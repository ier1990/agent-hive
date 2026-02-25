# Notes Scripts: Bash History Ingest

This directory contains the hourly ingestion script used by the Notes system:

- `ingest_bash_history_to_kb.py`

## Purpose

Ingests new lines from a user’s `~/.bash_history` into a SQLite knowledge base and queues enrichment work.

## What It Does (Verified From Code)

For each run (per user):

1. Reads the user history file:
	- `root` → `/root/.bash_history`
	- other users → `/home/<user>/.bash_history`
2. Maintains ingest progress in `human_notes.db.history_state` using `(host, path)` as the key.
3. For each new line since the last run:
	- Parses a `base_cmd` (first token; strips leading `VAR=...`; handles `sudo`; splits on `&&`/`;` and uses the first segment).
	- Upserts into `bash_history.db.commands` (unique on `full_cmd`).
	- Upserts into `bash_history.db.base_commands` (unique on `base_cmd`).
	- Ensures a seed row exists in `bash_history.db.command_ai` for each command id (`INSERT OR IGNORE`).
	- Enqueues enrichment work into `bash_history.db.enrich_queue` (`kind='base'`, `ref=<base_cmd>`, unique on `(kind, ref)`).
4. Updates `history_state` (inode + last_line) at the end.

Concurrency is prevented by a per-user lock file:

- `/tmp/ingest_bash_kb_<user>.lock`

## Databases & Logs

- KB DB: `/web/private/db/memory/bash_history.db`
- State DB: `/web/private/db/memory/human_notes.db` (table: `history_state`)
- Log file: `/web/private/logs/ingest_bash_history_to_kb.log`

## Schema Source Of Truth

The schema is defined in the script:

- `ensure_kb_schema()`
- `ensure_state_schema()`

It is also captured in the Notes AI sheet:

- `../AI_SHEET.md`

## Cron (Hourly)

Example crontab entries:

```cron
5 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/ingest_bash_history_to_kb.py samekhi >> /web/private/logs/ingest_bash_history_to_kb.log 2>&1
7 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/ingest_bash_history_to_kb.py root   >> /web/private/logs/ingest_bash_history_to_kb.log 2>&1
```

## Import Modes

- Default: `--import new` (uses saved state; only ingests new lines)
- Backfill: `--import all` or `--all` (re-imports from line 1)

## Verify It Worked

### 1) Syntax/compile check

If this directory is not writable, Python may fail trying to create `__pycache__`. This avoids that:

```bash
PYTHONDONTWRITEBYTECODE=1 python3 -m py_compile /web/html/admin/notes/scripts/ingest_bash_history_to_kb.py
```

### 2) Run once manually

```bash
/usr/bin/python3 /web/html/admin/notes/scripts/ingest_bash_history_to_kb.py samekhi
```

### 3) Check logs

```bash
tail -n 200 /web/private/logs/ingest_bash_history_to_kb.log
```

Look for:

- `start user=...`
- `state host=... start_line=...`
- `done user=... processed_lines=... parsed_commands=... queued_enrich=...`

### 4) Verify state progressed

```bash
sqlite3 /web/private/db/memory/human_notes.db "SELECT host, path, inode, last_line, updated_at FROM history_state ORDER BY updated_at DESC LIMIT 5;"
```

### 5) Verify KB tables exist + counts

```bash
sqlite3 /web/private/db/memory/bash_history.db "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name;"
sqlite3 /web/private/db/memory/bash_history.db "SELECT COUNT(*) AS commands FROM commands;"
sqlite3 /web/private/db/memory/bash_history.db "SELECT COUNT(*) AS base_commands FROM base_commands;"
sqlite3 /web/private/db/memory/bash_history.db "SELECT status, COUNT(*) FROM enrich_queue GROUP BY status ORDER BY COUNT(*) DESC;"
```

### 6) Verify `command_ai` seed rows exist

```bash
sqlite3 /web/private/db/memory/bash_history.db "SELECT COUNT(*) AS ai_rows FROM command_ai;"
sqlite3 /web/private/db/memory/bash_history.db "SELECT status, COUNT(*) FROM command_ai GROUP BY status ORDER BY COUNT(*) DESC;"
```

## Notes UI

In the Notes DB Browser:

- `.../admin/notes/?view=dbs`

You can browse both `bash_history.db` and `human_notes.db` directly.


### What it does, end-to-end
1) Finds PRIVATE_ROOT + DB locations

Imports lib/bootstrap.py by walking up directories until it finds it (_import_domain_memory_bootstrap()).

Calls _BOOT.get_paths(__file__) to resolve paths.

Sets:

STATE_DB = PRIVATE_ROOT/db/memory/human_notes.db

KB_DB = PRIVATE_ROOT/db/memory/bash_history.db

log file: PRIVATE_ROOT/logs/ingest_bash_history_to_kb.log

So it’s anchored on PRIVATE_ROOT (env var or default /web/private).

2) Writes a “job heartbeat” row for the UI

In human_notes.db, it ensures a table job_runs exists, then:

sets the job to running (_job_upsert_start)

later sets it to ok or error with duration + message (_job_upsert_finish)

This is what your /admin/notes/?view=jobs page is reading.

3) Prevents overlapping runs (per user) using a lock file

Creates a lock file:

PRIVATE_ROOT/locks/ingest_bash_kb_<user>.lock

Uses fcntl.flock(... LOCK_EX | LOCK_NB):

if another instance is running, it logs lock_busy and exits cleanly (status ok, message lock_busy)

So you can safely run it hourly; it won’t overlap itself.

4) Reads the user’s .bash_history

root → /root/.bash_history

otherwise → /home/<user>/.bash_history

If file missing: it exits “ok” with message no_history_file.

5) Tracks “what it already ingested” so it only reads new lines

In human_notes.db, table history_state stores:

host

path

inode

last_line

Logic:

If --import all (or --all) → start at line 1

Else:

if inode matches and history length >= last_line → start at last_line+1

otherwise → start at line 1 (handles rotated/truncated history)

Then it saves updated last_line = total_lines after processing.

6) Parses commands into “base command” + full command

For each new line:

ignores blank lines and comments (#)

chops off after first && or ;

removes leading VAR=... env assignments

strips sudo

base command becomes the first actual command token

Examples:

sudo apt-get update → base = apt-get

FOO=1 BAR=2 make test → base = make

cd /web && ls → base = cd (because it stops at &&)

7) Upserts into the KB DB (bash_history.db)

Ensures schema in bash_history.db:

commands table: unique full_cmd, tracks counts + seen times

base_commands table: unique base command, tracks counts + seen times

command_ai table: per command id AI enrichment status (pending, etc.)

enrich_queue table: queue of enrichment items with UNIQUE(kind, ref)

For each parsed command:

upserts commands and increments seen_count

ensures a row in command_ai (pending)

upserts base_commands

enqueues a base-command enrichment job in enrich_queue(kind="base", ref=<base_cmd>, priority=50) if not already present

So the output of this script is:

a growing command KB

a set of enrichment queue items for later AI/search

8) Writes a summary message and exits

It logs and sets job_runs status “ok” with counts:

processed_lines

parsed_commands

queued_enrich

If anything crashes, it:

logs exception

marks job_runs “error”

exits 1

What it does NOT do

It does not call a model.

It does not perform the enrichment itself.

It does not use AI templates yet.
It just captures commands + queues future work.

Why it’s a great “first job” for Mother Queue

This script is already:

idempotent-ish (state tracking)

lock-protected

has a heartbeat row

safe to rerun

So in Mother Queue terms:

job name: ingest_bash_history_to_kb

payload: { "username": "samekhi", "import_mode": "new" }

The worker just runs the script and lets it manage its own state/locks.
