# AI Root Defender

AI Root Defender is a local-first, read-only diagnostic shell for Linux and web servers. It gives an AI model enough visibility to investigate problems, but keeps command execution behind a human approval gate.

## What It Does

- runs a deterministic AI turn loop with `continue`, `needs_input`, and `final` states
- validates every proposed shell command through a layered guard:
  - blocked-token, syntax, and path safety checks first
  - static allowlist and disallowed-command policy next
  - exact-match bash history reuse after policy checks pass
  - prompt context improves as approved command history grows
- gets more useful over time through command-history reuse and better prompt context
- records command proposals, approvals, rejections, and executions in `bh/`
- preserves session memory and compose artifacts in readable markdown memory files
- supports editor-driven context composition with `/compose`
- supports multiple provider profiles through config and `/provider`
- supports telemetry toggles through `/monitor-mode`
- supports config-driven telemetry thresholds and optional deeper diagnostics

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

Telemetry and higher-level diagnostics are configured in:

- `telemetry`
  - master on/off switch for the telemetry contract
- `telemetry_alerts`
  - controls whether threshold breaches emit `[ALERT]` lines
- `telemetry_config`
  - threshold and trip values such as load-per-core ratio, memory percent, swap percent, and root disk percent
- `telemetry_diagnostics`
  - opt-in deeper reporting such as MySQL process visibility, failed services, inode usage, and recent OOM signals

Provider slugs:

- `local_openai_compat`
  - Ollama, LM Studio, vLLM, and similar local `/v1` servers
- `remote_openai_sdk`
  - OpenAI-hosted models such as `gpt-5-mini`
- `claude_sdk`
  - Anthropic Claude via optional SDK

## Boot Contracts

The active default boot prompt is still [agent_bash_boot.md](/web/html/admin/AI_Root_Defender/agent_bash_boot.md:1).

Reusable boot-prompt variants now live in:

- [boot-contracts/default_bash_boot.md](/web/html/admin/AI_Root_Defender/boot-contracts/default_bash_boot.md:1)
- [boot-contracts/apache_bash_boot.md](/web/html/admin/AI_Root_Defender/boot-contracts/apache_bash_boot.md:1)

See [boot-contracts/blueprint.md](/web/html/admin/AI_Root_Defender/boot-contracts/blueprint.md:1).

## Interpreter Concept

The Interpreter concept lives under:

- [Interpreter/plan.md](/web/html/admin/AI_Root_Defender/Interpreter/plan.md:1)
  - the concrete build spec for a Python-first gatekeeper between AI intent and system action
- [Interpreter/ideals.md](/web/html/admin/AI_Root_Defender/Interpreter/ideals.md:1)
  - the longer-term module-system direction for turning one stable Interpreter into a reusable host for specialized AI roles

The intended direction is:

- a small Interpreter core that enforces policy
- modules selected by settings and boot prompts
- reusable templates plugged into modules instead of replacing them
- one AI system that can switch specialized roles without exposing the same capability surface everywhere

## Quick Start

```bash
cd /web/html/admin/AI_Root_Defender
./bin/install.sh
source ./activate.sh
python3 agent_bash.py
```

## Non-Interactive Mode

You can run one task without entering the shell:

```bash
python3 agent_bash.py --non-interactive \
  --provider 0 \
  --prompt "Check recent Apache and MySQL errors and summarize the likely issue" \
  --json
```

You can also pass extra context from a file:

```bash
python3 agent_bash.py --non-interactive \
  --prompt "Review this context and continue diagnosis" \
  --context-file /tmp/guardian_context.txt \
  --json
```

For cron-style file drop processing, place JSON task files in:

- `/web/private/guardian_tasks/`

and run:

```bash
python3 agent_bash.py --non-interactive --claim-task --json
```

Completed tasks are moved into `done/` and failed ones into `failed/` under that queue directory, with a `.result.json` file saved beside each processed task.

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
- `/monitor-mode`
- `/monitor-mode status`
- `/monitor-mode on`
- `/monitor-mode off`
- `/monitor-mode alerts-on`
- `/monitor-mode alerts-off`
- `/debug`
- `/compose`
- `/compose --bootstrap`
- `/notes`
- `/memory`
- `/bh pending`
- `/bh recent`
- `/bh top`
- `/contract <json>`

## Monitor Modes

`/monitor-mode` controls the basic telemetry harness state from inside the shell.

- `/monitor-mode status`
  - shows whether telemetry collection and telemetry alerts are currently on or off
- `/monitor-mode on`
  - enables telemetry collection
- `/monitor-mode off`
  - disables telemetry collection
- `/monitor-mode alerts-on`
  - keeps telemetry running and enables alert output
- `/monitor-mode alerts-off`
  - keeps telemetry available but suppresses alert output

This command writes live overrides into `/web/private/agent_tools.json`, so changes persist across restarts without editing the repo default file directly.

## Diagnostic Modes

The telemetry contract at [bash_contracts/telemetry.sh](/web/html/admin/AI_Root_Defender/bash_contracts/telemetry.sh:1) now separates simple threshold alerts from deeper self-diagnostics.

- Threshold alerts
  - driven by `telemetry_config` in [tools.default.json](/web/html/admin/AI_Root_Defender/config/tools.default.json:1)
  - currently covers load-per-core ratio, memory usage, swap usage, and root disk usage
- Deeper diagnostic reporting
  - driven by `telemetry_diagnostics` in [tools.default.json](/web/html/admin/AI_Root_Defender/config/tools.default.json:1)
  - currently supports `mysql_process`, `failed_services`, `disk_inodes`, and `oom_events`

This gives you a few useful operating modes:

- quiet suit
  - `telemetry=true`, `telemetry_alerts=false`
  - gather extra machine context without shouting
- loud suit
  - `telemetry=true`, `telemetry_alerts=true`
  - emit alerts when thresholds are breached
- deeper suit
  - `telemetry=true` plus selected `telemetry_diagnostics.*=true`
  - expose richer environment feedback for the agent

The intent is to keep the AI in a controlled shell harness while still letting it feel the “pressure” of the host through bounded, auditable reporting surfaces.

## Task File Shape

Minimal task JSON:

```json
{
  "prompt": "Check Apache and MySQL logs for problems affecting /v1/ clients.",
  "provider": "0",
  "max_turns": 6
}
```

Optional fields:

- `context`
- `context_file`
- `provider`
- `max_turns`

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
