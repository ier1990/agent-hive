# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

AgentHive is a self-hosted, LAN-first PHP + SQLite application — a machine memory layer with AI enrichment under a stable `/v1/*` API. It runs on Apache with document root at `/web/html` and private runtime data at `/web/private` (never in git, never web-served).

## PHP Compatibility (Critical)

**Target PHP 7.3** as the compatibility floor:
- No arrow functions, typed properties, `??=`, array unpacking (7.4+)
- No named arguments, `match`, `?->`, union types, `str_contains`, `str_starts_with`, `str_ends_with` (8.0+)
- Use `isset()`, `array_key_exists()`, `strpos(...) !== false` instead
- If a newer-PHP helper is needed, add a small polyfill in `lib/bootstrap.php`

## Smoke Checks After Changes

```bash
curl -s http://localhost/v1/health | jq    # Should return JSON (no auth needed)
curl -s http://localhost/v1/ping | jq      # Simple alive check
```

## Architecture

### Two-Directory Separation

- `/web/html/` — version-controlled code (this repo)
- `/web/private/` — runtime data: SQLite DBs, `.env`, API keys, logs, locks, caches, rate limit state

### Request Flow

Apache `.htaccess` rewrites route URLs to PHP files. API endpoints live under `v1/` using directory-per-route (`v1/<route>/index.php`). Each endpoint calls `api_guard()` from `lib/bootstrap.php` for auth/rate-limiting, then returns JSON.

### Key Directories

- `lib/` — shared PHP libraries: bootstrap (paths, env, API guard), auth, db (PDO singleton + ULID), http (cURL), queue, rate limiting, schema builder
- `v1/` — API endpoints: health, ping, inbox, chat, search, status, models, etc.
- `admin/` — protected admin tools. `admin/index.php` auto-discovers `admin_*.php` files and renders them in an iframe nav. Also loads imported modules from `/web/private/admin_modules/`
- `admin/notes/` — notes app with views in `admin/notes/views/` (registered via allowlist in `notes_core.php`)
- `src/scripts/` — worker scripts (Python/Bash/PHP), version-controlled here, deployed to `/web/private/scripts/` via `/web/html/src/scripts/root_update_scripts.sh`

### Authentication

- **API routes**: `api_guard($endpoint, $requireKey)` in `lib/bootstrap.php` — checks `X-API-Key` or `Authorization: Bearer`, validates scopes from `/web/private/api_keys.json`, applies rate limits. LAN IPs (RFC1918) get keyless access when `SECURITY_MODE=lan`.
- **Admin pages**: `auth_require_admin()` from `lib/auth/auth.php` — session-based with bootstrap token flow for fresh installs. LAN auto-allowed.

### Database Patterns

SQLite everywhere with self-bootstrapping schemas (`CREATE TABLE IF NOT EXISTS`). No migration files — schema changes are idempotent additions via `ensureTableAndColumns()`. All DBs use WAL mode + `busy_timeout=5000`.

Key databases in `/web/private/db/`:
- `memory/human_notes.db` — notes, files, job_runs, app_settings
- `memory/notes_ai_metadata.db`, `memory/bash_history.db`, `memory/search_cache.db`
- `inbox/*.db` — auto-created per POST to `/v1/inbox` (table schema inferred from JSON)
- `codewalker_settings.db` — key-value settings + prompt templates
- `inbox/codewalker.db` — analysis results

### Config Priority

1. Server env vars / `.env` file at `/web/private/.env`
2. `app_settings` table in notes DB
3. `/web/private/notes_default.json`
4. Built-in defaults

Python scripts use `notes_config.py:get_config()`, PHP uses `notesResolveConfig()`.

## Adding New Components

**New API endpoint**: Create `v1/myroute/index.php`, include `lib/bootstrap.php`, call `api_guard()`, return JSON. Add rewrite rule to `v1/.htaccess` if needed.

**New admin tool**: Create `admin/admin_mytool.php` with `auth_require_admin()` at top. It auto-appears in the admin console menu.

**New worker script**: Create in `src/scripts/`, use heartbeat writes to `job_runs` table, log to `/web/private/logs/`, use locks in `/web/private/locks/`. deployed to `/web/private/scripts/` via auto wrapper creator `/web/html/src/scripts/root_update_scripts.sh`

**Schema changes**: Add `CREATE TABLE IF NOT EXISTS` + `ensureTableAndColumns()` calls in the relevant PHP file. No migration files needed.

## Change Policy

- Prefer small, local edits over refactors
- Keep paths deterministic (`/web/html`, `/web/private`)
- Keep `/v1/*` endpoints backward compatible unless explicitly changing the API
- Never commit anything from `/web/private`
