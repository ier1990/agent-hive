# queue_bash_searches.py

Queues/executes web searches for bash commands that have already been classified as **known** by `classify_bash_commands.py`.

It calls your existing search endpoint (`/v1/search`) and marks each command as `sent` once the search returns usable URLs.

This is designed to follow the same pipeline used by the Notes app:

1. `ingest_bash_history_to_kb.py` → fills/updates `bash_history.db.commands`
2. `classify_bash_commands.py` → fills `bash_history.db.command_ai` (`known`, `search_query`)
3. `queue_bash_searches.py` → calls `/v1/search?q=...` for AI-known commands and tracks progress in `bash_history.db.command_search`

## What it does

- Ensures a tracking table exists in `/web/private/db/memory/bash_history.db`:
  - `command_search` (`pending|sent|error`, timestamps, last_error)
- Seeds `command_search` rows for any command that is:
  - `command_ai.status='done'`
  - `command_ai.known=1`
  - `command_ai.search_query IS NOT NULL`
- Pulls `pending`/`error` rows in small batches and calls the Search API.
- If the Search API returns `ok=true` and includes `meta.top_urls`, it marks the row `sent`.
- If the Search API returns `ok=false` with `error=no_results`, it keeps the row `pending` (retry later).

## Files / paths

- KB DB: `/web/private/db/memory/bash_history.db`
- Lock file: `/tmp/queue_bash_searches.lock`
- Log file: `/web/private/logs/queue_bash_searches.log` (rotating)

## Configuration

Environment variables:

- `IER_SEARCH_API`
  - Default: `http://192.168.0.142/v1/search?q=`
  - Must include the `?q=` suffix because the script appends the URL-encoded query.
- `BASH_SEARCH_BATCH`
  - Default: `5`
- `BASH_SEARCH_SLEEP`
  - Default: `1`
  - Seconds to sleep between requests (helps avoid spamming the search backend)

## Run it

Manual:

```bash
/usr/bin/python3 /web/html/admin/notes/scripts/queue_bash_searches.py
```

Cron (example):

```cron
25 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/queue_bash_searches.py >> /web/private/logs/queue_bash_searches.log 2>&1
```

Override the Search API target:

```bash
IER_SEARCH_API="http://192.168.0.142/v1/search?q=" /usr/bin/python3 /web/html/admin/notes/scripts/queue_bash_searches.py
```

## Table: command_search

Schema created automatically:

- `cmd_id` (PK; matches `commands.id` / `command_ai.cmd_id`)
- `status` (`pending|sent|error`)
- `last_at` (timestamp)
- `last_error` (last error string)

## Troubleshooting

- **No work**
  - Logs will show: `noop pending=0 eligible_ai_done_known=...`
  - That usually means classification hasn’t produced `known=1` rows with `search_query`.

- **Lock contention**
  - Another instance is running: `/tmp/queue_bash_searches.lock`

- **Search API errors**
  - Verify `IER_SEARCH_API` points to a reachable server that returns JSON.
  - The script expects the response shape:
    - `ok` boolean
    - `meta.top_urls` list (non-empty)
  - If the backend returns `error=no_results`, the script will retry later.
