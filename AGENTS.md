# AGENTS.md

This repo is a self-hosted, LAN-first PHP + SQLite application that runs under an Apache-style docroot (commonly `/web/html`) with a writable private runtime directory (commonly `/web/private`).

## Runtime targets

- **PHP: 7.3+** (treat 7.3 as the compatibility floor)
- **Webserver:** Apache (or compatible), with URL rewriting enabled
- **DB:** SQLite (file-backed, auto-bootstrapped schemas)

## Compatibility rules (important)

When editing PHP, assume **PHP 7.3**:

- Avoid PHP 7.4+ features (arrow functions, typed properties, null coalescing assignment `??=`, array unpacking, etc.)
- Avoid PHP 8+ features (named arguments, `match`, nullsafe `?->`, union types, `str_contains`, `str_starts_with`, `str_ends_with`, `array_is_list`, etc.)
- Prefer boring, explicit code: `isset(...)`, `array_key_exists(...)`, `strpos(...) !== false`, etc.
- If you need a helper that exists only in newer PHP, add a **small polyfill** in `lib/bootstrap.php` (there are already 7.3 polyfills there).

## Repo layout (what goes where)

- `lib/`: shared PHP libraries (bootstrap, auth, db, http, rate limiting)
- `v1/`: API endpoints and apps (generally `v1/<route>/index.php`)
- `admin/`: admin tools (protected pages)
- `src/`: version-controlled scripts and notes that get deployed to private runtime
- `/web/private` (not in git): SQLite DBs, logs, keys, caches, rate limit state

## Security / secrets

- Never commit anything from `/web/private` (DBs, `.env`, keys, logs, caches).
- Auth patterns:
  - Admin pages use `lib/auth/auth.php`.
  - API routes use API keys / scopes and rate limiting (see `lib/bootstrap.php`).

## “Operationally boring” change policy

- Prefer **small, local edits** over refactors.
- Keep paths deterministic (`/web/html`, `/web/private`) and avoid host-specific assumptions.
- Keep endpoints backward compatible under the `/v1/*` contract unless explicitly changing the API.

## Smoke checks (manual)

Common quick checks after changes:

- `GET /v1/health` returns JSON
- `GET /v1/ping` returns a simple success response
- Notes UI loads: `GET /v1/notes/?view=human`

## Release artifacts

Generated release outputs live under `v1/releases/` and are ignored by git (`v1/releases/*`).
