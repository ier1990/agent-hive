# Bootstrap (lib/bootstrap.php)

This repo uses a single, general-purpose bootstrap file:

- `lib/bootstrap.php`

The intent is:

- predictable paths regardless of Apache docroot/layout
- safe, idempotent env loading
- consistent API auth/rate-limit helpers for `/v1/*`

## What bootstrap computes

Bootstrap derives all key paths from `__DIR__` (the directory of `bootstrap.php`) instead of the current working directory.

It defines (or ensures) these constants:

- `APP_ROOT`: filesystem path to the app root (one level above `lib/`)
- `APP_LIB`: filesystem path to `lib/`
- `ENTRY_URL`: URL base for the current entrypoint (directory of `SCRIPT_NAME`)
- `APP_URL`: URL base for the app root (best-effort relative to `DOCUMENT_ROOT`, otherwise `/`)
- `WEB_ROOT`: best-effort web root (uses `DOCUMENT_ROOT` if it matches, otherwise `APP_ROOT`)
- `PRIVATE_ROOT`: private filesystem root (prefers `/web/private`, otherwise adjacent fallbacks)
- `PRIVATE_SCRIPTS`: `${PRIVATE_ROOT}/scripts`

It also defines:

- `APP_SERVICE_NAME` (default `iernc-api`)
- `APP_ENV_FILE` (default `${PRIVATE_ROOT}/.env`)
- `API_KEYS_FILE` (default `${PRIVATE_ROOT}/api_keys.json`)

## APP_BOOTSTRAP_CONFIG

Bootstrap stores a summary in:

- `$GLOBALS['APP_BOOTSTRAP_CONFIG']`

This is useful for debug output and for keeping other parts of the app consistent.

Important: secrets should not be dumped into this structure.

Current behavior:

- safe values like `APP_VERSION`, `LLM_BASE_URL`, and booleans like `HAS_LLM_API_KEY` are reflected
- secret values like `LLM_API_KEY` / `APP_API_KEY` are intentionally not copied into the config array

## Env loading

Bootstrap loads env exactly once (idempotent):

- if `vlucas/phpdotenv` is available, it uses it
- otherwise it uses a small built-in `.env` parser

Source file:

- `${PRIVATE_ROOT}/.env` (via `APP_ENV_FILE`)

Helper:

- `env($key, $default = null)` reads from `$_ENV`, `$_SERVER`, or `getenv()`

Bootstrap also populates:

- `$GLOBALS['APP_BOOTSTRAP_ENV']`

Important: this can include secrets (because it mirrors loaded env). Don’t dump it in responses.

## API auth guard (for /v1/*)

`lib/bootstrap.php` provides API helpers used by `/v1/*` endpoints:

- `api_guard($endpoint, $needsTools = false)`
- `api_guard_once($endpoint, $needsTools = false)` (compat wrapper)

Behavior:

- reads client key from `X-API-Key` or `Authorization: Bearer ...`
- loads scopes from `${PRIVATE_ROOT}/api_keys.json`
- applies rate limits (via `lib/ratelimit.php`)

Rate limiting:

- IP limit emits `X-RateLimit-*` headers and then enforces 429
- Key limit (when a key is present) emits `X-RateLimit-Key-*` headers and can also enforce 429

Rate-limit storage:

- `lib/ratelimit.php` stores counters under `${PRIVATE_ROOT}/ratelimit` when possible (no hardcoded `/web/private`)

Security config knobs (via env; no per-server edits):

- `SECURITY_MODE` (`lan` or `public`; default `lan`)
- `ALLOW_IPS_WITHOUT_KEY` (comma-separated CIDRs/IPs; default empty)
- `REQUIRE_KEY_FOR_ALL` (`0` or `1`; only used when `SECURITY_MODE=lan`)

Effective behavior:

- `SECURITY_MODE=public` → keys required for all requests
- `SECURITY_MODE=lan` + empty `ALLOW_IPS_WITHOUT_KEY` → defaults to RFC1918 + loopback (`127.0.0.1/32`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`)

Proxy config:

- `$TRUSTED_PROXIES` (empty by default; only trust proxies you explicitly list)

## Admin authentication (non-bricking fresh install)

Admin pages use a separate, minimal auth layer:

- `lib/auth/auth.php`

Design goal:

- a fresh install must not lock you out

Flow:

- if no admin exists, bootstrap creates a one-time token at:
  - `${PRIVATE_ROOT}/bootstrap_admin_token.txt`
- `/admin/*` allows initial admin creation only if:
  - request comes from LAN, OR
  - `?bootstrap=<token>` matches the private token
- once admin exists:
  - `/admin/*` requires login (session)
  - credentials stored in `${PRIVATE_ROOT}/admin_auth.json` (password hash)

## Common debug snippet

```php
<?php
require_once __DIR__ . '/lib/bootstrap.php';
echo '<pre>' . htmlspecialchars(json_encode($GLOBALS['APP_BOOTSTRAP_CONFIG'], JSON_PRETTY_PRINT), ENT_QUOTES) . '</pre>';
```

## Notes

- Keep bootstrap compatible with PHP 7.3 (avoid PHP 8-only features before polyfills are defined).
- Prefer using `PRIVATE_ROOT`/`APP_ENV_FILE`/`API_KEYS_FILE` rather than hardcoding `/web/private`.
