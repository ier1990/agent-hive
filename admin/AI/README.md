# AgentHive AI Agent

This directory contains the split Python agent stack for the new admin AI shell.

## Files

- `agent.py`
  - Small entrypoint.
  - Resolves shared AI settings, loads tool settings, creates `AliveAgent`, and starts the shell or single-shot run.
- `agent_boot.md`
  - Boot prompt.
  - Defines tool usage and JSON response rules for the model.
- `agent_common.py`
  - Shared constants and helpers.
  - Includes path constants, compact JSON output, prompt loading, and TTY color support.
- `agent_config.py`
  - Reads `/web/private/.env`.
  - Resolves the same active AI settings PHP uses from `/web/private/db/codewalker_settings.db`.
  - Loads `default_agent.json`, optional `/web/private/agent.json`, and `/web/private/agent_tools.json`.
- `agent_runtime.py`
  - Contains the `AliveAgent` class.
  - Handles model calls, agent memory, notes/code/search tools, DB-backed approved agent tools, and the think -> tool -> think loop.
- `agent_shell.py`
  - Interactive shell UX.
  - Banner, slash commands, parser wiring, status/help output, and optional startup greeting warmup.

## Runtime config

- Shared AI backend:
  - Follows the active PHP AI setup when `/web/private/agent.json` is not present.
- Agent profile:
  - Versioned template: `admin/AI/default_agent.json`
  - Private runtime copy: `/web/private/agent.json`
  - If `/web/private/agent.json` exists, its non-empty values override the PHP AI settings and built-in defaults.
- Tool settings:
  - `/web/private/agent_tools.json`
- Agent memory:
  - `/web/private/db/memory/agent_ai_memory.db`
  - Supports `memory_search` and `memory_write`
  - Optional startup preload can inject recent memory into the initial context
- Admin-managed dynamic tools:
  - Read from `/web/private/db/agent_tools.db`
  - Only approved tools are exposed to the Python agent bridge
- Search:
  - Prefers `SEARX_URL` from `/web/private/.env`
  - JSON tool settings can override it when a non-empty `searx_url` is set

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

## Notes

- Keep new modules in this same directory unless there is a strong reason to move them.
- `agent_boot.md` is the place to tune model behavior before changing runtime code.
- Search is intended for non-local questions; local project knowledge should prefer notes/code tools first.
- `startup_greeting_enabled` in `agent.json` can send a short plain-text greeting request on shell startup to warm a local model before normal tool-loop runs.

## Questions

### When does `agent_boot.md` load?

`agent_boot.md` is loaded fresh each time the agent starts a new model run.

Request flow:

1. `agent.py` builds `AliveAgent` with the resolved profile and tool settings.
2. `agent_runtime.py` starts a run and calls `_system_prompt()`.
3. `_system_prompt()` reads the boot file through `load_agent_boot_prompt(...)`.
4. That content becomes the initial `"system"` message sent to the model.

What that means in practice:

- every `--query` run loads it fresh
- every new prompt entered in the interactive shell loads it fresh
- you do not need to restart the shell for boot prompt edits to take effect on the next user message

Boot prompt path precedence:

- CLI `--boot-prompt-path`, if set
- `/web/private/agent.json`, if it overrides `boot_prompt_path`
- default `admin/AI/agent_boot.md`

So yes: it is effectively loaded per run, not just once at shell startup.

### Does `/status` show the startup greeting setting?

Yes. `/status` now shows:

- whether the startup hello warmup is enabled
- the configured hello prompt text

That makes it easier to tell whether the shell should send the plain-text warmup request when it starts.
