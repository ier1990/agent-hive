# AgentHive AI Agent

This directory contains the split Python agent stack behind the admin AI shell.

## Files

- `agent.py`
  - Small entrypoint.
  - Loads the resolved agent profile and tool settings, constructs `AliveAgent`, and runs either interactive shell mode or a single `--query`.
- `agent_boot.md`
  - System prompt for the model.
  - Defines the strict JSON response contract and the allowed built-in tool names.
- `agent_common.py`
  - Shared constants and helpers.
  - Defines runtime paths, compact JSON helpers, boot prompt loading, and TTY color support.
- `agent_config.py`
  - Resolves configuration from built-in defaults, the shared PHP AI settings DB, `/web/private/.env`, `default_agent.json`, and optional private overrides.
  - Creates `/web/private/agent_tools.json` with defaults when missing.
- `agent_runtime.py`
  - Contains the `AliveAgent` class.
  - Handles model calls, tool execution, memory storage, DB-backed approved tools, preloaded context, and the think -> tool -> think loop.
- `agent_shell.py`
  - Interactive shell UX.
  - Provides banner/status/help output, slash commands, readline history, optional startup greeting warmup, and optional editor review for large pasted prompts.
- `default_agent.json`
  - Versioned default profile template for the Python agent.
  - Includes the startup greeting defaults used unless private config overrides them.
- `profiles/`
  - Example role/profile JSON files for shell, worker, reviewer, and toolsmith use.
  - Safe to copy and adapt into private runtime config files.
- `mc.md`
  - Draft notes for a future `/mc` file browser.
  - Design document only; not wired into the current shell.

## Config precedence

The resolved runtime profile is assembled in this order:

1. built-in defaults from `agent_config.py`
2. versioned defaults from `admin/AI/default_agent.json`
3. shared PHP AI settings from `/web/private/db/codewalker_settings.db`
4. optional private overrides from `/web/private/agent.json`
5. optional profile override from `--config-file`
6. direct CLI overrides like `--model`, `--base-url`, or `--boot-prompt-path`

Rules worth knowing:

- Empty strings in `/web/private/agent.json` do not override existing values.
- Shared PHP settings are normalized to an OpenAI-compatible `/v1` base URL.
- `/web/private/.env` is used for provider-specific values such as `OPENAI_*`, `LLM_*`, `OLLAMA_*`, and `SEARX_URL`.

## Runtime files

- Agent profile template: `admin/AI/default_agent.json`
- Private agent override: `/web/private/agent.json`
- Optional per-role profile file: any JSON passed through `--config-file`
- Example profile directory: `admin/AI/profiles/`
- Tool settings: `/web/private/agent_tools.json`
- Shared AI settings DB: `/web/private/db/codewalker_settings.db`
- Approved admin tools DB: `/web/private/db/agent_tools.db`
- Agent memory DB: `/web/private/db/memory/agent_ai_memory.db`
- Default notes DB: `/web/private/db/memory/human_notes.db`
- Shell history file: `/web/private/logs/agent_shell_history.log`
- Composer/editor prompt archive: `/web/private/logs/agent_composer/`
- Temp execution directory: `/web/private/tmp`

## Built-in tools

`agent_boot.md` currently allows these built-in tool names:

- `memory_search`
- `memory_write`
- `notes_search`
- `code_search`
- `search`
- `agent_tool_list`
- `agent_tool_run`
- `read_code`

Important behavior:

- Admin-managed tools are never called directly by name; they must go through `agent_tool_run`.
- `read_code` is restricted to paths under the configured `code_root`.
- `search` uses the configured Searx endpoint and returns structured results plus a text summary.
- Memory schema is auto-created on first use if the memory DB does not exist yet.

## Admin-managed tools contract

Admin-managed tools in `/web/private/db/agent_tools.db` now follow a status-based lifecycle.

Tool statuses:

- `draft`
- `registered`
- `approved`
- `disabled`
- `deprecated`
- `rejected`
- `superseded`

Supporting workflow fields:

- `approved_by`
- `approved_at`
- `review_notes`
- `replaces_tool_id`
- `source_type`
- `lineage_key`

Execution rule:

- only tools with `status = approved` are executable through the normal runtime

Creation defaults:

- AI-created tools should default to `draft`
- human-created tools should default to `registered`
- imported tools should default to `registered` unless explicitly promoted by admin workflow

Compatibility note:

- legacy `is_approved` may still exist in the DB for migration compatibility
- current runtime behavior should treat `status` as the source of truth and keep `is_approved` synchronized from it

## Preloaded context

Every normal agent run starts with a preloaded context block containing:

- `notes_preview` from `notes_search`
- `code_preview` from `code_search`
- optional `memory_preview` when `memory.autoload_on_start` is enabled

This means the model gets a first-pass notes/code snapshot before it decides whether to call more tools.

## Shell commands

- `/help`
- `/hello`
- `/paste`
- `/compose`
- `/edit-paste`
- `/edit-paste on`
- `/edit-paste off`
- `/read PATH`
- `/load PATH`
- `/session`
- `/sessions-history`
- `/sessions-history on`
- `/sessions-history off`
- `/status`
- `/debug`
- `/debug on`
- `/debug off`
- `/models`
- `/search`
- `/memory`
- `/mem list`
- `/memory list`
- `/tools`
- `/tools list`
- `/clear`
- `/exit`
- `/quit`

Typing `exit` or `quit` without a slash also leaves the shell.

Input helpers worth knowing:

- normal rapid multiline paste is merged into one logical prompt
- `/paste` enters explicit multiline mode and finishes with `/end` or `/cancel`
- `/compose` opens `$EDITOR` immediately so you can draft, paste, and clean up a prompt before sending
- `/read PATH` and `/load PATH` load a local file into the next prompt
- `/session` shows the current session log path
- `/sessions-history on` prepends recent session history into the next request context
- `/edit-paste on` opens large pasted blocks in `$EDITOR` for review before sending
- editor-reviewed prompts are archived under `/web/private/logs/agent_composer/` for replaying or testing with smaller models later

## Startup greeting

The shell supports an optional plain-text warmup call on startup:

- `startup_greeting_enabled`
- `startup_greeting_prompt`

Those defaults currently live in `admin/AI/default_agent.json` and can be overridden in `/web/private/agent.json`.

The greeting bypasses the JSON tool loop on purpose:

- plain text only
- no tool calls
- intended to warm the active backend before normal shell use

`/hello` triggers the same greeting path on demand.

## CLI options

`agent.py` currently supports:

- `--config-file`
- `--query`
- `--list-models`
- `--model`
- `--base-url`
- `--api-key`
- `--notes-db`
- `--code-root`
- `--boot-prompt-path`
- `--tool-settings-path`
- `--max-steps`
- `--temperature`
- `--output-mode`
- `--interactive`
- `--no-interactive`
- `--debug`
- `--no-debug`

## Profile fields

Profile JSON now supports role-oriented fields for cron and worker-style use:

- `profile_name`
- `task_name`
- `description`
- `mode`
- `model`
- `base_url`
- `api_key`
- `api_key_env`
- `max_steps`
- `step_budget`
- `temperature`
- `startup_greeting_enabled`
- `interactive`
- `output_mode`
- `write_report`
- `report_type`
- `report_target`
- `notes_db`
- `code_root`
- `boot_prompt_path`
- `tool_settings_path`
- `memory_enabled`
- `allowed_tools`
- `default_query`
- `task_prompt`
- `timeout_seconds`
- `edit_paste_enabled`
- `edit_paste_min_lines`
- `editor_command`
- `editor_timeout_seconds`
- `edit_paste_strip_comment_lines`

Useful behavior:

- `api_key_env` stores the env var name, not the secret itself
- the real secret is resolved from process environment first, then `/web/private/.env`
- `default_query` and `task_prompt` let a profile define a default job prompt for non-interactive runs
- `interactive: false` makes cron-style profiles cleaner
- `write_report` plus `report_target` can persist single-shot output to a file
- `memory_enabled` can disable agent memory without needing a separate tool settings file
- `edit_paste_enabled: true` makes the shell open large multiline pastes in your editor before they are sent
- `edit_paste_min_lines` controls how many pasted lines trigger editor review
- `editor_command` can be a multi-word command such as `code --wait`
- `editor_command` can include `{file_path}` when the editor requires the path in a specific position, such as `nano -w -l {file_path}`
- `editor_timeout_seconds` limits how long the shell waits for the editor to close
- `edit_paste_strip_comment_lines` removes `#` helper lines from the reviewed temp file before sending

Example profile files:

- `admin/AI/profiles/interactive_shell.example.json`
- `admin/AI/profiles/apache_log_worker.example.json`
- `admin/AI/profiles/reviewer_agent.example.json`
- `admin/AI/profiles/toolsmith_agent.example.json`
- `admin/AI/profiles/openai_hosted.example.json`
- `admin/AI/profiles/lmstudio_local.example.json`

Provider hint:

- use `api_key_env: OPENAI_API_KEY` for hosted OpenAI-style profiles
- use `api_key_env: LLM_API_KEY` for local OpenAI-compatible backends like LM Studio when you want to keep the key name abstracted in env or `.env`

## Local docs

Useful docs in this directory:

- `admin/AI/README.md`
- `admin/AI/AI_tools.md`
- `admin/AI/AI_Hive_concept.md`
- `admin/AI/agent_boot.md`
- `admin/AI/mc.md`

## Notes

- Keep new agent modules in this same directory unless there is a strong reason to move them.
- `agent_boot.md` is the first place to tune model behavior before changing runtime code.
- For local project questions, the prompt contract prefers memory, notes, code search, and `read_code` before web search.
- AI may draft tools, but should never self-approve them.
- Repeated identical tool calls are loop-guarded; the runtime stops after the same tool+args fingerprint repeats more than twice.

## Questions

### When does `agent_boot.md` load?

`agent_boot.md` is loaded fresh each time the agent starts a new model run.

Request flow:

1. `agent.py` builds `AliveAgent` with the resolved profile and tool settings.
2. `agent_runtime.py` starts a run and calls `_system_prompt()`.
3. `_system_prompt()` reads the boot file through `load_agent_boot_prompt(...)`.
4. That content becomes the initial `"system"` message sent to the model.

In practice:

- every `--query` run loads it fresh
- every new prompt entered in the interactive shell loads it fresh
- you do not need to restart the shell for boot prompt edits to take effect on the next user message

Boot prompt path precedence:

- CLI `--boot-prompt-path`, if set
- `/web/private/agent.json`, if it overrides `boot_prompt_path`
- default `admin/AI/agent_boot.md`

### Does `/status` show the startup greeting setting?

Yes. `/status` shows:

- whether startup greeting warmup is enabled
- the configured hello prompt text

That makes it easy to confirm whether shell startup should send the plain-text warmup request.
