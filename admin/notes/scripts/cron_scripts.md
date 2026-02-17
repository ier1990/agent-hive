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

## Script pipeline (single orchestrator)

Primary cron target:
- `/web/private/scripts/root_process_bash_history.py`

It runs these scripts in order:
1) `/web/private/scripts/ingest_bash_history_to_kb.py samekhi`
2) `/web/private/scripts/ingest_bash_history_to_kb.py root`
3) `/web/private/scripts/classify_bash_commands.py`
4) `/web/private/scripts/queue_bash_searches.py`
5) `/web/private/scripts/ai_search_summ.py`
6) `/web/private/scripts/ai_notes.py`

All individual scripts remain callable for manual debugging.

## Example cron / dispatcher setup

Single root-only schedule:

```cron
5 * * * * /usr/bin/python3 /web/private/scripts/root_process_bash_history.py >> /web/private/logs/process_bash_history.log 2>&1
```

Optional: run source directly (same behavior):

```cron
5 * * * * /usr/bin/python3 /web/html/src/scripts/root_process_bash_history.py >> /web/private/logs/process_bash_history.log 2>&1
```

## Notes

- The search cache + search summaries only advance on the host that runs `/v1/search` (because that’s where `search_cache.db` is written).
- `?view=jobs` reads `job_runs` from `human_notes.db` and shows the last start/ok/error + duration for each script.
- `process_bash_history` also writes its own `job_runs` heartbeat row for top-level pipeline visibility.
