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
  - Banner, slash commands, parser wiring, and status/help output.

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
- `/status`
- `/debug`
- `/debug on`
- `/debug off`
- `/models`
- `/search`
- `/memory`
- `/tools`
- `/clear`
- `/exit`

## Notes

- Keep new modules in this same directory unless there is a strong reason to move them.
- `agent_boot.md` is the place to tune model behavior before changing runtime code.
- Search is intended for non-local questions; local project knowledge should prefer notes/code tools first.
