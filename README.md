# AgentHive

AgentHive is a self-hosted ops memory + AI backend: job queues, admin tools, notes/search, and API endpoints.
Designed to be the first platform installed on every server — private by default, with optional public APIs.

## What you can do with it
- Scan and modernize legacy codebases (e.g., older PHP projects)
- Run queued AI jobs (workers, logs, retries)
- Manage shared AI connections (OpenAI / Ollama / LM Studio)
- Store and search operational notes and history
- Expose safe endpoints for automation and agent-to-agent workflows

## Quick start
> (Add your install steps here)

---

![PHP](https://img.shields.io/badge/PHP-7.3-blue)
![SQLite](https://img.shields.io/badge/DB-SQLite-lightgrey)
![LAN First](https://img.shields.io/badge/Network-LAN--First-green)
![License](https://img.shields.io/badge/License-Apache--2.0-blue)

AgentHive is for small teams who want AI assistance without sending their data to the cloud.

AgentHive is not “just notes” or “just an API”.

It’s a **machine memory layer** with **time-aware search history** feeding **AI enrichment**, under a stable `/v1/*` API contract.

This project captures:

- Bash history
- Search queries & results (with ranking snapshots)
- AI-generated summaries & metadata
- Operational notes and human logs

…and continuously enriches them using local or external AI models via a `/v1/*`-style API.

## The plan (three pillars)

### 1️⃣ Ops Memory System

Capture operational reality in a way that’s cheap to store, easy to audit, and safe to keep local.

- Logs
- History
- Jobs
- Notes
- Incidents
- Decisions

### 2️⃣ AI Assistant Backend

Turn that memory into an assistant via a boring, controllable backend.

- Queues (Mother Queue)
- Workers (tool runner)
- Agents (optional task-level behaviors)
- Models (local-first, external optional)
- Routing (stable `/v1/*` endpoints, including OpenAI-compat shims)

### 3️⃣ Knowledge Vault

Make the captured data usable over time.

- Searchable
- Persistent
- Local
- Private

## Design principles

- **LAN-first by default** (safe on fresh installs)
- **Stateless APIs, stateful memory**
- **SQLite everywhere** (self-bootstrapping schemas)
- **Deterministic paths** (no `/var/www/html` vs `/web/html` drift)
- **Operationally boring** (cron-safe, idempotent, debuggable)

This system replaces:

> “I used to Google this command…”

with:

> “I already *know* this machine.”

---

## What’s included today

- **/v1 API** endpoints (JSON, key + scope auth, rate limited)
- **Notes** web app at `/admin/notes/` (SQLite-backed, includes a small “Jobs” health view)
- **Chat routing** at `/v1/chat/` (autoselector) and `/v1/chat/completions` (OpenAI-compatible shim)
- **Admin tools** under `/admin/` (protected with a “bootstrap token” flow to avoid fresh-install lockouts)

It’s designed to run on a normal Linux box with Apache + PHP and a writable private data directory (default: `/web/private`).

---

## Quick start (existing server)

1) Point your webserver docroot at this folder (example: `/web/html`).

2) Create your private directory:

```bash
sudo mkdir -p /web/private
sudo chown -R www-data:www-data /web/private
sudo chmod 0750 /web/private
```

3) Create `/web/private/.env`:

```bash
cat >/web/private/.env <<'EOF'
# --- Service identity ---
APP_VERSION=dev

# --- Security ---
# lan (default) allows RFC1918+loopback keyless access; public requires keys for all requests
SECURITY_MODE=lan

# Optional: explicit allowlist for keyless access (comma-separated CIDRs/IPs)
# ALLOW_IPS_WITHOUT_KEY=192.168.0.0/24,127.0.0.1/32

# Only used when SECURITY_MODE=lan
# REQUIRE_KEY_FOR_ALL=0

# --- API keys file ---
# API_KEYS_FILE=/web/private/api_keys.json

# --- Optional: override where private data lives (bootstrap will auto-detect if not set) ---
# PRIVATE_ROOT=/web/private
EOF
```

4) Create your API keys file (scopes are app-defined; common ones: `chat`, `tools`, `health`):

```bash
cat >/web/private/api_keys.json <<'EOF'
{
	"change-me": {"active": true, "scopes": ["chat","tools","health"]}
}
EOF
sudo chown www-data:www-data /web/private/api_keys.json
sudo chmod 0640 /web/private/api_keys.json
```

5) Hit health:

- `GET /v1/health` (should return JSON)
- `GET /admin/notes/?view=human` (Notes UI)

---

## Security model (important)

### API guard (`lib/bootstrap.php`)

API routes call `api_guard()` / `api_guard_once()` which:

- extracts key from `X-API-Key` or `Authorization: Bearer ...`
- loads scopes from `api_keys.json`
- enforces per-IP and per-key rate limits

### Security knobs (no server-specific edits)

Configured via `.env`:

- `SECURITY_MODE=lan|public`
	- `lan` (default): allows keyless access from RFC1918 + loopback unless you provide `ALLOW_IPS_WITHOUT_KEY`
	- `public`: keys required for all requests
- `ALLOW_IPS_WITHOUT_KEY` (comma-separated CIDRs/IPs)
- `REQUIRE_KEY_FOR_ALL=0|1` (only applies when `SECURITY_MODE=lan`)

### Admin auth (non-bricking fresh installs)

Admin pages use `lib/auth/auth.php`.

Design goal: a fresh install should not lock you out.

- On first run, a one-time bootstrap token lives at `${PRIVATE_ROOT}/bootstrap_admin_token.txt`
- You can “claim” admin from LAN or with `?bootstrap=<token>`
- After an admin exists, normal session login applies

---

## Data + storage layout

This repo intentionally separates **code** from **private data**.

- Code: this repo (example: `/web/html`)
- Private data: `${PRIVATE_ROOT}` (default: `/web/private`)
	- `.env`
	- `api_keys.json`
	- SQLite DBs
	- rate-limit state: `${PRIVATE_ROOT}/ratelimit`

The git ignore policy is set up so you don’t accidentally commit private data.

---

## Repo structure (high level)

- `lib/`
	- `bootstrap.php` (paths, env loader, API guard)
	- `ratelimit.php` (file+flock sliding-window limiter; stores under `${PRIVATE_ROOT}/ratelimit`)
	- `auth/auth.php` (admin bootstrap-token auth)
- `v1/`
	- API endpoints and apps, generally using the “directory route” form: `v1/<route>/index.php`
	
- `admin/`
	- Admin tools (protected)

---

## Troubleshooting

### “500 Internal Server Error”

Check Apache error logs (Ubuntu/Debian):

```bash
tail -n 200 /var/log/apache2/error.log
```

Common causes in this repo:

- missing / incorrect `${PRIVATE_ROOT}` permissions for the web user
- missing includes after route refactors (e.g. old `__DIR__/lib/...` paths)

### API returns 401 / unauthorized

- Ensure `${PRIVATE_ROOT}/api_keys.json` exists and is readable by the web user.
- If running `SECURITY_MODE=public`, all non-unguarded endpoints require a key.

---

## Docs

- `bootstrap.md` — deeper notes on bootstrap behavior and config
- `copilot-instructions.md` — repo conventions and operational guidance

---

## Roadmap (aspirational)

The TurnKey AI appliance is a roadmap direction; this repository currently provides the core LAN-first service layer that also runs on Ubuntu and nginx-php-fastcgi.

### Core idea (normalized)

TurnKey Linux → **TurnKey AI appliance**: a self-hosted, private, LAN-aware AI system for small businesses that installs like an OS, configures itself, and keeps data local by default.

### High-level architecture

AI stack components (plan):

- AI Header → request envelope (model, limits, rules)
- AI Templates → editable prompt blueprints
- AI Compiler → renders templates
- AI Engine → calls model
- Mother Queue → schedules jobs


1) **Base system**

- Debian stable
- hardened defaults
- no cloud dependencies by default

2) **Install-time console config**

On first boot, guide setup (LAN-friendly defaults):

- show local IP + hostname
- show available models + storage status
- collect business name + primary role
- optional: enable LAN mesh
- optional: allow external AI

Generate:

- API keys
- local TLS
- private memory DBs

3) **Preinstalled structure**

Example layout:

```
/var/www/html      -> UI / Admin
/web               -> Working domain
/web/api           -> Internal + external API
/web/notes         -> Human + AI notes
/web/codewalker    -> Self-analysis & refactor
/web/files         -> File browser
/web/memory        -> AgentHive DBs
```

4) **Private domain memory**

- SQLite + (optional) vector DB
- human notes + AI notes
- code history + decision memory
- sync only when explicitly enabled

5) **LAN mesh (optional)**

- discovery via mDNS / Avahi
- signed keys + opt-in trust
- shared-but-permissioned memory across nodes

6) **External AI (optional)**

- local-only mode (no external dependency)
- hybrid mode (use external AI for heavy summarization, occasional refactors)

## License

Apache License 2.0. See [LICENSE](LICENSE).