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
  - Provides banner/status/help output, slash commands, readline history, and optional startup greeting warmup.
- `default_agent.json`
  - Versioned default profile template for the Python agent.
  - Includes the startup greeting defaults used unless private config overrides them.
- `mc.md`
  - Draft notes for a future `/mc` file browser.
  - Design document only; not wired into the current shell.

## Config precedence

The resolved runtime profile is assembled in this order:

1. built-in defaults from `agent_config.py`
2. versioned defaults from `admin/AI/default_agent.json`
3. shared PHP AI settings from `/web/private/db/codewalker_settings.db`
4. optional private overrides from `/web/private/agent.json`
5. CLI overrides like `--model`, `--base-url`, or `--boot-prompt-path`

Rules worth knowing:

- Empty strings in `/web/private/agent.json` do not override existing values.
- Shared PHP settings are normalized to an OpenAI-compatible `/v1` base URL.
- `/web/private/.env` is used for provider-specific values such as `OPENAI_*`, `LLM_*`, `OLLAMA_*`, and `SEARX_URL`.

## Runtime files

- Agent profile template: `admin/AI/default_agent.json`
- Private agent override: `/web/private/agent.json`
- Tool settings: `/web/private/agent_tools.json`
- Shared AI settings DB: `/web/private/db/codewalker_settings.db`
- Approved admin tools DB: `/web/private/db/agent_tools.db`
- Agent memory DB: `/web/private/db/memory/agent_ai_memory.db`
- Default notes DB: `/web/private/db/memory/human_notes.db`
- Shell history file: `/web/private/logs/agent_shell_history.log`
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

## Preloaded context

Every normal agent run starts with a preloaded context block containing:

- `notes_preview` from `notes_search`
- `code_preview` from `code_search`
- optional `memory_preview` when `memory.autoload_on_start` is enabled

This means the model gets a first-pass notes/code snapshot before it decides whether to call more tools.

## Shell commands

- `/help`
- `/hello`
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
- `--debug`
- `--no-debug`

## Notes

- Keep new agent modules in this same directory unless there is a strong reason to move them.
- `agent_boot.md` is the first place to tune model behavior before changing runtime code.
- For local project questions, the prompt contract prefers memory, notes, code search, and `read_code` before web search.
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
