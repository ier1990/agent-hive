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
- `/web/private` (not in git, never web-served): SQLite DBs, logs, keys, caches, rate limit state

## Security / secrets

- **MUST:** `APP_PRIVATE_ROOT` (default `/web/private`) must be outside the web document root.
- **MUST:** Web server configuration must deny all requests to any `private/` path (defense-in-depth).
- **MUST NOT:** Commit secrets, DBs, logs, caches, or uploads from `APP_PRIVATE_ROOT`.
- **SHOULD:** Add a startup self-check that refuses to boot if private root is inside docroot.
- Runtime defense-in-depth: `lib/bootstrap.php` auto-creates `APP_PRIVATE_ROOT/.htaccess` with deny rules when missing.
- Auth patterns:
  - Admin pages use `lib/auth/auth.php`.
  - Admin entrypoints should call `auth_require_admin()`.
  - API routes use API keys / scopes and rate limiting (see `lib/bootstrap.php`), and should call `api_guard()`.

## “Operationally boring” change policy

- Prefer **small, local edits** over refactors.
- Keep paths deterministic (`/web/html`, `/web/private`) and avoid host-specific assumptions.
- Keep endpoints backward compatible under the `/v1/*` contract unless explicitly changing the API.

## Routing guardrails (/v1)

- Treat `/v1/*` as API routes and avoid global slash-canonicalization rules that can fight Apache `DirectorySlash`.
- For directory-backed endpoints, trailing-slash canonicalization (`/v1/foo` -> `/v1/foo/`) is acceptable.
- Route rules should accept both forms (`/v1/foo` and `/v1/foo/`) when practical.
- When adding routes, prefer explicit rewrites to current `*/index.php` layout (or explicit route files) over legacy flat `*.php` rewrites.
- Do not reintroduce legacy `/v1/*.php` public route rewrites unless the file actually exists and is intentionally public.

## Smoke checks (manual)

Common quick checks after changes:

- `GET /v1/health` returns JSON
- `GET /v1/ping` returns a simple success response
- Notes UI loads: `GET /v1/notes/?view=human`

## Release artifacts

Generated release outputs live under `v1/releases/` and are ignored by git (`v1/releases/*`).
