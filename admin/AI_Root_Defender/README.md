# AI Root Defender

AI Root Defender is a local-first, read-only diagnostic shell for Linux and web servers. It gives an AI model enough visibility to investigate problems, but keeps command execution behind a human approval gate.

## What It Does

- runs a deterministic AI turn loop with `continue`, `needs_input`, and `final` states
- validates every proposed shell command against an allowlist and blocked-token policy
- records command proposals, approvals, rejections, and executions in `bh/`
- preserves session memory and compose artifacts in readable markdown memory files
- supports editor-driven context composition with `/compose`
- supports multiple provider profiles through config and `/provider`

## Current Shape

- [agent_bash.py](/web/html/admin/AI_Root_Defender/agent_bash.py:1)
  - interactive shell entrypoint
- [turn_generator.py](/web/html/admin/AI_Root_Defender/turn_generator.py:1)
  - provider call + turn contract parsing
- [session_logger.py](/web/html/admin/AI_Root_Defender/session_logger.py:1)
  - memory/session writer
- [proposal_store.py](/web/html/admin/AI_Root_Defender/proposal_store.py:1)
  - bash proposal SQLite store + JSONL event mirror
- [lib/bash_guard.py](/web/html/admin/AI_Root_Defender/lib/bash_guard.py:1)
  - shell validation, execution, and prompt-context helpers

## Config Model

Repo defaults live in:

- [config/settings.default.json](/web/html/admin/AI_Root_Defender/config/settings.default.json:1)
- [config/tools.default.json](/web/html/admin/AI_Root_Defender/config/tools.default.json:1)

Private live overrides belong in:

- `/web/private/agent_settings.json`
- `/web/private/agent_tools.json`

More detail is documented in [config/blueprint.md](/web/html/admin/AI_Root_Defender/config/blueprint.md:1).

## Boot Contracts

The active default boot prompt is still [agent_bash_boot.md](/web/html/admin/AI_Root_Defender/agent_bash_boot.md:1).

Reusable boot-prompt variants now live in:

- [boot-contracts/default_bash_boot.md](/web/html/admin/AI_Root_Defender/boot-contracts/default_bash_boot.md:1)
- [boot-contracts/apache_bash_boot.md](/web/html/admin/AI_Root_Defender/boot-contracts/apache_bash_boot.md:1)

See [boot-contracts/blueprint.md](/web/html/admin/AI_Root_Defender/boot-contracts/blueprint.md:1).

## Quick Start

```bash
cd /web/html/admin/AI_Root_Defender
./bin/install.sh
source ./activate.sh
python3 agent_bash.py
```

Manual setup also works:

```bash
cd /web/html/admin/AI_Root_Defender
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
python3 agent_bash.py
```

## Common Commands

- `/help`
- `/status`
- `/provider`
- `/debug`
- `/compose`
- `/compose --bootstrap`
- `/notes`
- `/memory`
- `/bh pending`
- `/bh recent`
- `/bh top`
- `/contract <json>`

## Storage Model

- `bh/`
  - bash command governance, approval history, and JSONL event trail
- `logs/`
  - session memory files
- `notes/`
  - user-authored note artifacts

The directory purposes are documented in:

- [bh/blueprint.md](/web/html/admin/AI_Root_Defender/bh/blueprint.md:1)
- [profiles/blueprint.md](/web/html/admin/AI_Root_Defender/profiles/blueprint.md:1)

## Safety Model

- read-only shell commands only
- one command per turn
- no pipes, redirects, chaining, or `sudo`
- human approval required for new commands
- exact prior approved commands can be auto-reused from bash history

## Project Status

This is no longer just a planning area. It is a working local harness with room to keep splitting into its own repo once the runtime loader, docs, and packaging settle a bit more.
