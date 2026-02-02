# Notes cron scripts (quick reference)

This is the “keep track of everything” list for the Notes pipeline.

## URLs (host: 192.168.0.142)

- Search API (writes cache DB): http://192.168.0.142/v1/search?q=
- Search cache UI (reads cache DB): http://192.168.0.142/admin/notes/?view=search_cache
- SearXNG raw: http://192.168.0.142:3000/
- Jobs heartbeat UI: http://192.168.0.142/admin/notes/?view=jobs

## Databases (PRIVATE_ROOT=/web/private)

- Notes DB: `/web/private/db/memory/human_notes.db`
- Bash KB DB: `/web/private/db/memory/bash_history.db`
- AI metadata DB: `/web/private/db/memory/notes_ai_metadata.db`
- Search cache DB (written by Search API): `/web/private/db/memory/search_cache.db`

## Script pipeline

1) Ingest bash history → bash_history.db (+ heartbeat)
- `/web/html/admin/notes/scripts/ingest_bash_history_to_kb.py <user>`

2) Classify commands with Ollama → bash_history.db (+ heartbeat)
- `/web/html/admin/notes/scripts/classify_bash_commands.py`

3) Queue “known” commands to Search API (+ heartbeat)
- `/web/html/admin/notes/scripts/queue_bash_searches.py`

4) Summarize cached searches → search_cache.db + ai_generated notes (+ heartbeat)
- `/web/html/admin/notes/scripts/ai_search_summ.py`

5) Generate metadata for notes → notes_ai_metadata.db (+ heartbeat)
- `/web/html/admin/notes/scripts/ai_notes.py`

## Example crontab

These lines assume python is at `/usr/bin/python3` and logs go to `/web/private/logs/`.

```cron
# ingest bash history into bash_history.db
5 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/ingest_bash_history_to_kb.py samekhi >> /web/private/logs/ingest_bash_history_to_kb.log 2>&1
7 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/ingest_bash_history_to_kb.py root   >> /web/private/logs/ingest_bash_history_to_kb.log 2>&1

# classify commands
15 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/classify_bash_commands.py >> /web/private/logs/classify_bash_commands.log 2>&1

# queue searches
25 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/queue_bash_searches.py >> /web/private/logs/queue_bash_searches.log 2>&1

# summarize search cache
35 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/ai_search_summ.py >> /web/private/logs/ai_search_summ.log 2>&1

# ai metadata for notes
45 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/ai_notes.py >> /web/private/logs/ai_notes.cron.log 2>&1
```

## Notes

- The search cache + search summaries only advance on the host that runs `/v1/search` (because that’s where `search_cache.db` is written).
- `?view=jobs` reads `job_runs` from `human_notes.db` and shows the last start/ok/error + duration for each script.
