# classify_bash_commands.py

Classifies ingested bash commands using a local Ollama model, and stores AI metadata back into `bash_history.db`.

This is meant to sit between:

1. `ingest_bash_history_to_kb.py` (writes/updates the `commands` table)
2. `classify_bash_commands.py` (fills/updates the `command_ai` table)
3. (Optional) a downstream search/queue step once commands are “known”

## What it does

- Ensures the required schema exists in `/web/private/db/memory/bash_history.db`:
  - `commands` (unique `full_cmd`, normalized `base_cmd`, seen counters)
  - `command_ai` (1:1 row per command, with status + JSON payload)
- Finds commands that are `pending` (or `error`) and sends them to Ollama’s `/api/generate` endpoint.
- Validates the model output into a strict JSON shape and writes it to `command_ai`.

## Output JSON format

The model is prompted to return ONLY JSON, shaped like:

```json
{
  "base_cmd": "string",
  "known": true,
  "intent": "string",
  "keywords": ["string"],
  "search_query": "string or null",
  "notes": "string"
}
```

Rules enforced by the script:

- If `known` is false, `search_query` is forced to `null` and `keywords` is forced to `[]`.
- If `base_cmd` comes back empty, it falls back to the ingested `base_cmd` or first token of `full_cmd`.

## Files / paths

- KB DB: `/web/private/db/memory/bash_history.db`
- Lock file: `/tmp/classify_bash_commands.lock`
- Log file: `/web/private/logs/classify_bash_commands.log` (rotating)

## Configuration

Environment variables:

- `OLLAMA_URL`
  - Default: `http://192.168.0.142:11434`
  - Used for Ollama HTTP API calls (`/api/generate`)
- `BASH_AI_BATCH`
  - Default: `20`
  - Max commands processed per run

Constants in the script:

- `MODEL = "gpt-oss:latest"`
- `PROMPT_VERSION = "bash_cmd_v1"`

## Run it

Manual run:

```bash
/usr/bin/python3 /web/html/admin/notes/scripts/classify_bash_commands.py
```

Cron (hourly example):

```cron
15 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/classify_bash_commands.py >> /web/private/logs/classify_bash_commands.log 2>&1
```

If you want to use a different Ollama host:

```bash
OLLAMA_URL="http://192.168.0.142:11434" /usr/bin/python3 /web/html/admin/notes/scripts/classify_bash_commands.py
```

## DB schema notes

The script will create/upgrade tables automatically via `ensure_schema()`.

Key table:

- `command_ai.status` is one of: `pending | working | done | error`

Typical flow:

- ingest writes `commands`
- classifier ensures a `command_ai` row exists per `commands.id`
- classifier marks rows `working` → `done` (or `error`)

## Troubleshooting

- **Nothing happens**
  - Check the log: `/web/private/logs/classify_bash_commands.log`
  - If it logs `noop pending=0`, there’s nothing pending.

- **It exits immediately**
  - Another instance is running (lock file): `/tmp/classify_bash_commands.lock`

- **Ollama errors**
  - Confirm Ollama is reachable at `OLLAMA_URL` and supports `/api/generate`.
  - The error will look like: `ollama_http_failed url=...`

- **JSON decode errors**
  - The script does a small “repair” pass for common invalid JSON escapes, but if the model emits non-JSON text it will still fail.
  - Errors are recorded into `command_ai.last_error`.

## Next step (optional)

Once `command_ai.known=1` and `command_ai.search_query` is not null, you can automatically queue web searches by inserting into your existing search system (e.g. `search_cache.db`) or by adding a `search_queue` table.
