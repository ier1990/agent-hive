# BH Blueprint

`bh` is still a real subsystem today.

It is not replaced by `/memory`, and it is not just an alias.

## Current Role

`bh/` stores the bash proposal and execution trail used by the Guardian shell:

- `bash_history.db`
  - SQLite source of truth for proposed, approved, rejected, auto-approved, and executed commands
- `events/YYYY-MM-DD.jsonl`
  - append-only mirror of proposal lifecycle events
- `blueprint.md`
  - purpose and structure notes for this directory

This data is used for:

- `/bh pending`
- `/bh approve`
- `/bh reject`
- `/bh recent`
- `/bh top`
- `/bh similar`
- exact-match auto-approval reuse
- prompt context such as recent approved and top-used commands

## Relationship To Memory

`/memory` and `bh/` serve different jobs:

- `/memory`
  - session memory, compose artifacts, summaries, and AI-facing recall
- `bh/`
  - command governance, approval history, execution audit, and tool learning

So `/memory` makes the AI recall prior work better, while `bh/` makes shell-tool use safer and smarter.

## Naming

`bh` is acceptable as the working directory name for now because the code and config already point to it:

- default DB path: `./bh/bash_history.db`
- default event mirror: `./bh/events`

If we ever rename the directory to `bash_history/`, it should be treated as a migration task, not a wording tweak.

## Future Direction

The safest future shape is:

1. Keep `bh/` as the command-audit subsystem.
2. Keep `/memory` as the session/artifact subsystem.
3. Let memory reference bash actions, but do not merge the stores yet.
4. If needed later, add a higher-level `/memory` summary that can cite `bh` facts.

## Rules

- SQLite remains the operational source of truth for command state.
- JSONL mirrors remain useful for append-only export, replay, or ingestion.
- Human-readable markdown or YAML belongs more naturally in `/memory` than in `bh/`.
- Sensitive runtime values still belong in `/web/private`, not in this directory.

## Intent

`bh/` should remain the durable command-history and approval engine behind Guardian's shell tools, even as `/memory` becomes richer.
