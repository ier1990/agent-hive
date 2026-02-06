# CodeWalker Architecture

## Overview
CodeWalker is a LAN‑first AI code analysis system that scans files, runs a selected action (summarize, rewrite, audit, test, docs, refactor), and stores results in SQLite. It is implemented in PHP (admin UI + runner) and Python (CLI/cron runner). The admin UI reads the same results database and provides a review/apply workflow for rewrites.

Key characteristics:
- PHP 7.3 compatibility (no 7.4+ features).
- SQLite for both settings and results.
- Multiple AI backends (local LM Studio/Ollama and OpenAI‑compatible HTTP).
- Deterministic file safety: writes constrained to a configured `write_root`.

## Major Components

### Admin UI (PHP)
- [admin/codewalker.php](admin/codewalker.php) is the primary dashboard and reviewer.
- [admin/admin_codewalker.php](admin/admin_codewalker.php) is a thin router that loads the main dashboard.
- Auth is enforced via [lib/auth/auth.php](lib/auth/auth.php).

Responsibilities:
- Lists actions, files, queue items, and applied rewrites.
- Renders stored results (JSON or Markdown) with fallback raw display.
- Applies rewrites to files with backup + hash safety.
- Exposes a small “queue” for targeted file runs.

### PHP Runner
- [admin/lib/codewalker_runner.php](admin/lib/codewalker_runner.php) executes actions and persists results.
- [admin/lib/codewalker_settings.php](admin/lib/codewalker_settings.php) loads/merges settings from the settings DB.
- [admin/lib/codewalker_helpers.php](admin/lib/codewalker_helpers.php) provides hashing, file selection, and utility helpers.

Responsibilities:
- Initializes results DB schema if missing.
- Scans files, deduplicates by content hash, selects action by configured distribution.
- Sends prompt to the configured AI backend.
- Writes results to action‑specific tables.

### Python CLI / Cron Runner
- [src/scripts/codewalker.py](src/scripts/codewalker.py)

Responsibilities:
- Bootstraps settings DB (if missing) and reads config.
- Initializes results DB schema using its embedded DDL.
- Scans files and stores results in SQLite.

## Runtime Data Stores

### Settings DB
- Path: /web/private/db/codewalker_settings.db
- Used by both PHP and Python.
- Contains:
  - Settings rows (key/value)
  - Prompt templates
  - Action distribution percentages
  - Backend configuration

Primary loaders:
- `cw_settings_get_all()` in [admin/lib/codewalker_settings.php](admin/lib/codewalker_settings.php)
- `db_get_or_create_settings()` in [src/scripts/codewalker.py](src/scripts/codewalker.py)

### Results DB
- Path (default): /web/private/db/inbox/codewalker.db
- Used by PHP admin UI and both runners.

#### Core tables (PHP runner schema)
Created in `cw_cwdb_init()` in [admin/lib/codewalker_runner.php](admin/lib/codewalker_runner.php):
- `files` — tracked files with hashes
- `runs` — run metadata
- `actions` — action records (summarize, rewrite, audit, test, docs, refactor)
- `summaries`
- `rewrites`
- `audits`
- `tests`
- `docs`
- `refactors`
- `queued_files`
- `vw_last_actions` (view)

#### Admin‑only tables
Created in [admin/codewalker.php](admin/codewalker.php) for the UI workflow:
- `applied_rewrites` — audit trail for “Apply rewrite” actions

#### Python DDL scope (current)
The embedded DDL in [src/scripts/codewalker.py](src/scripts/codewalker.py) currently defines:
- `files`, `runs`, `actions`, `summaries`, `rewrites`, `queued_files`, `vw_last_actions`

It does **not** create `audits`, `tests`, `docs`, or `refactors` tables. This is a known mismatch with the PHP runner schema. If Python is used for these action types, add the missing tables or adjust storage logic.

## Data Flow

1. **Config load**
   - PHP: `cw_settings_get_all()` loads settings from the settings DB.
   - Python: `db_get_or_create_settings()` seeds/reads settings.

2. **Scan + select action**
   - Candidate files gathered and filtered (extension, size, excludes).
   - File hash used for deduplication.
   - `cw_pick_random_action()` (PHP) or `pick_random_action()` (Python) selects the action using configured percentages.

3. **AI call**
   - Prompt is built from templates + file content.
   - Backend selected (LM Studio, Ollama, or OpenAI‑compatible).

4. **Persist results**
   - Record in `actions`.
   - Result stored in action‑specific table (PHP) or `summaries`/`rewrites` (Python, current behavior).

5. **Review/apply**
   - Admin UI loads results and provides “apply rewrite” flow.
   - Rewrites are backed up and applied to files under `write_root` only.

## Key Functions and APIs

PHP runner:
- `cw_cwdb_init()` — initializes results DB schema.
- `cw_run_on_file()` — executes a single action on a file.
- `cw_pick_random_action()` — weighted action selection.
- `cw_is_file_already_processed()` — dedup guard.

Admin UI helpers:
- `cw_summary_to_markdown()` — JSON summary → Markdown.
- `sha256_file_s()` — hash safety for apply flow.

Python runner:
- `db_connect()` — initializes schema + connects to results DB.
- `db_insert_action()` — inserts the base action record.
- `pick_random_action()` — weighted action selection.
- `extract_first_codeblock()` — rewrite extraction.

## File Dependency Graph (high‑level)

- [admin/admin_codewalker.php](admin/admin_codewalker.php)
  - loads [admin/codewalker.php](admin/codewalker.php)

- [admin/codewalker.php](admin/codewalker.php)
  - depends on [lib/bootstrap.php](lib/bootstrap.php)
  - depends on [lib/auth/auth.php](lib/auth/auth.php)
  - depends on [admin/lib/codewalker_helpers.php](admin/lib/codewalker_helpers.php)
  - depends on [admin/lib/codewalker_settings.php](admin/lib/codewalker_settings.php)
  - depends on [admin/lib/codewalker_runner.php](admin/lib/codewalker_runner.php)

- [admin/lib/codewalker_runner.php](admin/lib/codewalker_runner.php)
  - depends on [admin/lib/codewalker_helpers.php](admin/lib/codewalker_helpers.php)
  - depends on [admin/lib/codewalker_settings.php](admin/lib/codewalker_settings.php)

- [src/scripts/codewalker.py](src/scripts/codewalker.py)
  - reads settings DB at /web/private/db/codewalker_settings.db
  - writes results DB at /web/private/db/inbox/codewalker.db

## Configuration Flow

1. **Defaults** live in [admin/lib/codewalker_settings.php](admin/lib/codewalker_settings.php) and [src/scripts/codewalker.py](src/scripts/codewalker.py).
2. **Settings DB** values override defaults.
3. **Runtime overrides** (environment or CLI flags) may further override values (Python).
4. **Active AI profile** integration (PHP) is mediated via [lib/ai_bootstrap.php](lib/ai_bootstrap.php) when enabled.

## Gotchas / Operational Notes

- **Schema creation**: the admin UI only creates `applied_rewrites` and `queued_files`. The main schema is created by the runners. If the results DB is deleted, run the PHP runner or Python CLI once to initialize it.
- **PHP 7.3 compatibility**: avoid PHP 7.4+ features in all CodeWalker PHP files.
- **Python multi‑action mismatch**: the Python DDL does not include `audits`, `tests`, `docs`, or `refactors`. The PHP runner writes to those tables. If Python is used for these actions, extend its DDL and insert logic.
- **Write safety**: rewrite application is restricted to `write_root` from settings, and compares `file_hash` unless force‑applied.
- **Empty responses**: both runners mark empty AI responses as errors; admin UI will show stored content fallback if Markdown rendering fails.
- **Auth required**: admin routes require `auth_require_admin()`; ensure admin session is valid when loading CodeWalker.
