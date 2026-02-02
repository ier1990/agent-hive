# Copilot Instructions

**Domain Memory** - A PHP-based self-hosted web application with admin tools, API endpoints, AI workers, and knowledge base management.

**Philosophy:** Keep changes operationally boring. Predictable paths, self-bootstrapping SQLite schemas, actionable error messages.

---

## 🎯 Golden Rules

- **Target PHP 7.3+** (treat 7.3 as the compatibility floor)
- **Small, local changes** over big refactors
- **No new UX** unless explicitly requested
- **Admin tools** use bootstrap token auth (`lib/auth/auth.php`)
- **API endpoints** use API key auth or LAN-only access

---

## 📁 Project Structure

### `/web/html/` - Web Root (Versioned)

```
admin/              # Admin console & tools
  index.php         # Main hub (iframe navigation)
  admin_sysinfo.php # Infrastructure monitoring
  admin_jobs.php    # Cron job status
  admin_logs.php    # Log viewer
  admin_codewalker.php # AI code analysis
  admin_notes.php   # Notes management
  admin_htaccess.php # Auth testing
  notes/            # Notes app (formerly /v1/notes)
  AI_System/        # System monitoring scripts
  lib/              # Admin-specific libraries

v1/                 # RESTful API endpoints
  ping/, health/, inbox/, chat/, search/, etc.

lib/                # Shared PHP libraries
  bootstrap.php     # Core bootstrap
  auth/auth.php     # Authentication system
  db.php, http.php, queue.php, ratelimit.php, etc.

src/                # Source scripts (deploy to /web/private)
  scripts/          # Python/Bash workers
  prompts/          # AI prompt templates

index.php          # Root handler
```

### `/web/private/` - Private Runtime (Writable, Never Web-Served)

```
db/
  memory/          # Knowledge base
    human_notes.db, notes_ai_metadata.db, bash_history.db
  inbox/           # Universal receiver (auto-created tables)
    sysinfo_new.db, *.db
  codewalker/
    codewalker.db

logs/              # Application logs
scripts/           # Deployed runtime scripts
prompts/           # Deployed prompts
cache/             # Temporary cache
locks/             # Script locks
ratelimit/         # Rate limit state

.env               # Environment vars (IER_API_KEY)
admin_auth.json    # Admin creds (640)
bootstrap_admin_token.txt # Setup token (640)
notes_default.json # Config
```

---

## 🔐 Authentication

### Admin Auth (`lib/auth/auth.php`)

**Bootstrap Flow (First-Time Setup):**
1. Visit `/admin/` from LAN → auto-allowed (192.168.x.x, 10.x.x.x)
2. OR from remote: `?bootstrap=TOKEN` (auto-created at `/web/private/bootstrap_admin_token.txt`)
3. Create admin account (min 10 chars)
4. Bootstrap token no longer needed

**File Permissions:** `0640` (owner RW, group R for www-data)

**Usage:**
```php
require_once APP_LIB . '/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();
```

### API Auth

- **Header:** `X-API-Key: xxx` or query param `?api_key=xxx`
- **Stored in:** `/web/private/.env` as `IER_API_KEY`
- **Rate limiting:** `lib/ratelimit.php`

---

## 🗄️ Databases (SQLite)

**Memory/Knowledge Base:**
- `/web/private/db/memory/human_notes.db` - Notes, files, job_runs
- `/web/private/db/memory/notes_ai_metadata.db` - AI metadata
- `/web/private/db/memory/bash_history.db` - Bash command history
- `/web/private/db/memory/search_cache.db` - Search cache

**Inbox (Universal Receiver):**
- `/web/private/db/inbox/sysinfo_new.db` - System monitoring data
- Other databases auto-created via `/v1/inbox` POST

**CodeWalker:**
- `/web/private/db/codewalker/codewalker.db` - Code analysis results

**Important Tables:**
- `notes`, `files`, `app_settings`, `history_state`
- `job_runs` - Cron job heartbeat tracking

---

## 🛠️ Admin Tools

**Main Console** (`admin/index.php`):
- Iframe-based navigation
- Auto-discovers `admin_*.php` files
- Modern UI with Tailwind CSS

**System Monitoring:**
- **admin_sysinfo.php** - Real-time host metrics (uptime, load, memory, disk, GPU)
- **admin_jobs.php** - Cron job status with auto-refresh
- **admin_logs.php** - Log viewer (tail 50/100/500 lines)

**Development:**
- **admin_codewalker.php** - AI code analysis, apply rewrites, queue files
- **admin_notes.php** - Notes management interface
- **admin_htaccess.php** - Auth & rewrite testing

**Other:**
- **admin_MC_Browser.php** - File browser
- **admin_AI_Chat.php** - AI chat
- **admin_installer.php** - Installation wizard

---

## 📡 API Endpoints (`/v1/`)

- `ping/`, `health/` - Status checks
- `inbox/` - **Universal data receiver** (auto-creates tables)
- `chat/`, `search/`, `expert/` - AI interactions
- `receiving/`, `request/`, `response/` - Data flow
- `models/` - Model info
- `whatip.php` - IP detection

**Inbox Auto-Table Creation:**
```bash
# POST with JSON creates table automatically
curl -X POST http://localhost/v1/inbox \
  -H "X-API-Key: xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "db": "sysinfo_new",
    "table": "daily_sysinfo",
    "host": "server1",
    "ts": "2026-01-08T12:00:00Z",
    "sysinfo": {"uptime": "5d", "load": "0.5"}
  }'
```

---

## 🤖 Worker Scripts

### Location Strategy

**Edit in:** `/web/html/src/scripts/` (version controlled)
**Deploy to:** `/web/private/scripts/` (runtime)
**Sync:** Hourly rsync via cron

### Current Workers

**Ingest:**
- `ingest_bash_history_to_kb.py` - Bash history → KB

**Enrich:**
- `classify_bash_commands.py` - Command classification
- `ai_notes.py` - AI note processing
- `notes_ai_metadata.py` - Metadata generation

**Queue:**
- `queue_bash_searches.py` - Push to `/v1/search`

**System:**
- `admin/AI_System/scripts/sysinfo.sh` - Collect & POST to `/v1/inbox`

### Worker Conventions

**All workers should:**
- Read config via `notes_config.py` (Python) or `notesResolveConfig()` (PHP)
- Write heartbeat to `job_runs` table
- Be idempotent (safe to run repeatedly)
- Log to `/web/private/logs/`
- Use locks in `/web/private/locks/`

**Heartbeat tracking:**
```python
# workers update job_runs table:
# last_start, last_ok, last_status, last_message, last_duration_ms
```

---

## ⚙️ Configuration

**File:** `/web/private/notes_default.json`

**Standard Keys:**
```json
{
  "app.private_root": "/web/private",
  "search.api.base": "http://127.0.0.1/v1/search",
  "ai.ollama.url": "http://127.0.0.1:11434",
  "ai.ollama.model": "qwen2.5-coder:32b",
  "security.mode": "lan",
  "scripts.batch_size": 20
}
```

**Access:**
- **PHP:** `notesResolveConfig()` in `admin/notes/notes_core.php`
- **Python:** `get_config()` in `admin/notes/scripts/notes_config.py`

**Priority:**
1. Notes DB `app_settings` table
2. `/web/private/notes_default.json`
3. Built-in defaults

---

## 🚀 Fresh Server Setup

```bash
# 1. Create directory structure
mkdir -p /web/private/{db/{memory,inbox,codewalker},logs,scripts,prompts,cache,locks,ratelimit}

# 2. Set ownership
chown -R samekhi:www-data /web/private
chmod -R 775 /web/private

# 3. Web root permissions
chown -R samekhi:www-data /web/html
chmod -R 755 /web/html

# 4. Sensitive file permissions (when created)
chmod 640 /web/private/admin_auth.json
chmod 640 /web/private/bootstrap_admin_token.txt
chmod 640 /web/private/.env
```

**First Admin Access:**
```bash
# From LAN: Just visit http://your-server/admin/
# From remote: Get token and use it
ssh user@server
cat /web/private/bootstrap_admin_token.txt
# Visit: http://your-server/admin/?bootstrap=TOKEN_HERE
```

---

## 📊 Monitoring & Debugging

**Job Status:**
- View at: `admin/admin_jobs.php`
- Auto-refresh: `?refresh=30` or `?refresh=60`
- Data source: `job_runs` table in notes DB

**System Info:**
- View at: `admin/admin_sysinfo.php`
- Data collected by: `admin/AI_System/scripts/sysinfo.sh`
- Storage: `/web/private/db/inbox/sysinfo_new.db`
- Features: Host filtering, time range, GPU/Docker/Ollama detection

**Logs:**
- View at: `admin/admin_logs.php`
- Location: `/web/private/logs/*.log`
- Actions: View full, tail 50/100/500

---

## 🔧 Development Workflow

### Making Changes

**Admin tools:**
```bash
# Create new tool
touch /web/html/admin/admin_newtool.php
# It auto-appears in admin console menu
```

**Worker scripts:**
```bash
# 1. Edit in source
vim /web/html/src/scripts/my_worker.py

# 2. Deploy (or wait for hourly sync)
rsync -av /web/html/src/scripts/ /web/private/scripts/

# 3. Run from runtime location
cd /web/private/scripts && python3 my_worker.py
```

**Database schema:**
```php
// In admin/notes/notes_core.php ensureDb():
$db->exec("CREATE TABLE IF NOT EXISTS my_table (...)");
```

### Git Workflow

```bash
cd /web/html
git add .
git commit -m "✨ Add feature"
git push origin main
```

**Ignored:**
- `music/`, `.b/`, `/web/private/*`
- `__pycache__/`, `*.pyc`, `.env`

---

## 📝 Template Conventions (Notes App)

**Views:** `admin/notes/views/*.php`

**Rendering:**
```php
// Controller (admin/notes/index.php)
$data = ['title' => 'My Page', 'items' => $items];
renderNotesView('my_view', $data);

// View (admin/notes/views/my_view.php)
extract($ctx); // Provides $title, $items
echo "<h1>$title</h1>";
foreach ($items as $item) { ... }
```

**Allowlist:** Views must be registered in `renderNotesView()` in `notes_core.php`

---

## 🎨 UI Standards

- **Admin console:** Tailwind CSS + Alpine.js
- **Dark theme:** Preferred for admin tools
- **Mobile responsive:** All new UIs
- **Error handling:** Show actionable messages, not crashes
- **File links:** Use workspace-relative paths in markdown

---

## 🐛 Common Issues

### Permission Problems
```bash
# Fix: Ensure www-data can read auth files
chmod 640 /web/private/bootstrap_admin_token.txt
chmod 640 /web/private/admin_auth.json
chown samekhi:www-data /web/private/*.json
chown samekhi:www-data /web/private/*.txt
```

### Bootstrap Token Not Working
```bash
# Regenerate token
rm /web/private/bootstrap_admin_token.txt
# Visit admin page - it will recreate automatically
```

### Database Locked
```bash
# Check for zombie processes
ps aux | grep python
kill <pid>

# Remove lock files
rm /web/private/locks/*.lock
```

---

## 🔮 Future Plans

- **Web fetch workers** - `web_fetch.py`, `curl_proxy.py`
- **Search query ingestion** - `ingest_search_queries.py`
- **Queue web fetch** - `queue_web_fetch.py`
- **HTTP cache** - `/web/private/cache/http/`
- **Systemd services** - Background workers
- **Advanced rate limiting** - Per-IP, per-key
- **Multi-model support** - Route to different LLMs

---

**Last Updated:** January 8, 2026 🚀
