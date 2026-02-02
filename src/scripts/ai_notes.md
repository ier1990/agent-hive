
# Notes Scripts: AI Notes Metadata (`ai_notes.py`)

This script generates **AI metadata** for your human notes and stores it in a SQLite table (`ai_note_meta`) that the Notes UI can display under **AI Metadata**.

It is designed to be:

- **Incremental**: only reprocesses notes when their `source_hash` changes.
- **Safe to rerun**: uses `UNIQUE(note_id, source_hash)` and an upsert.
- **LAN-only friendly**: talks to Ollama over HTTP (`/api/chat`).

## What it reads / writes

- Reads **Human Notes DB** (source): `/web/private/db/memory/human_notes.db`
	- Table: `notes`
- Writes **AI Metadata DB** (destination):
	- Recommended (matches Notes UI `AI_DB_PATH`): `/web/private/db/memory/notes_ai_metadata.db`
	- Script default (if you don’t override): `/web/private/db/memory/notes_ai_metadata.db`

The output table is:

- `ai_note_meta` (created automatically by the script)

## Requirements

- Python 3
- Python package: `requests`
- Ollama reachable via HTTP (example: `http://127.0.0.1:11434`)

Quick dependency check:

```bash
python3 -c "import requests; print('requests OK')"
```

If needed:

```bash
python3 -m pip install --user requests
```

## Ollama expectations

This script calls:

- `POST {OLLAMA_URL}/api/chat`

It asks the model to return **ONLY strict JSON** (no Markdown/code fences).

Quick connectivity check:

```bash
curl -sS http://127.0.0.1:11434/api/tags | head
```

## Run it (recommended)

Use the same AI DB that the Notes UI reads:

```bash
/usr/bin/python3 /web/html/admin/notes/scripts/ai_notes.py \
	--human-db /web/private/db/memory/human_notes.db \
	--ai-db /web/private/db/memory/notes_ai_metadata.db \
	--ollama-url http://127.0.0.1:11434 \
	--model gpt-oss:latest \
	--limit 200
```

Useful flags:

- `--limit N`: max notes scanned per run
- `--timeout SECONDS`: per-request timeout
- `--sleep SECONDS`: pause between calls (helps reduce load)
- `--since-id ID`: force starting point (for backfills)
- `--backtrack N`: how far to back up from last processed note id (default: 200)
- `--dry-run`: don’t call Ollama or write `ai_db`; just report what would be processed

Incremental behavior details:

- The script computes a `source_hash` from: `notes_type`, `topic`, `updated_at`, and note text.
- It normally starts from the most recently processed note id, backing up `--backtrack` notes so recent edits get picked up.
- It fetches notes **newest-first** (then processes oldest→newest) so a small `--limit` still reaches recent notes.

On startup, it prints a helpful line like:

```text
[INFO] scan_config human_db=... ai_db=... max_note_id=... last_processed_note_id=... start_from=... limit=... backtrack=... dry_run=... ollama_url=... model=...
```

At the end it prints:

```text
[DONE] processed=... would_process=... skipped=... failed=... scanned=...
```

- `processed`: rows actually sent to Ollama + written
- `would_process`: rows that would be processed in `--dry-run`
- `skipped`: already had `(note_id, source_hash)` in `ai_note_meta`

## Schema (created by the script)

Destination DB table:

```sql
CREATE TABLE IF NOT EXISTS ai_note_meta (
	id INTEGER PRIMARY KEY AUTOINCREMENT,
	note_id INTEGER NOT NULL,
	parent_id INTEGER DEFAULT 0,
	notes_type TEXT,
	topic TEXT,
	source_hash TEXT NOT NULL,
	model_name TEXT NOT NULL,
	meta_json TEXT NOT NULL,
	summary TEXT,
	tags_csv TEXT,
	created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
	UNIQUE(note_id, source_hash)
);
CREATE INDEX IF NOT EXISTS idx_ai_note_id ON ai_note_meta(note_id);
CREATE INDEX IF NOT EXISTS idx_ai_topic ON ai_note_meta(topic);
CREATE INDEX IF NOT EXISTS idx_ai_notes_type ON ai_note_meta(notes_type);
CREATE INDEX IF NOT EXISTS idx_ai_updated ON ai_note_meta(updated_at);
```

## Cron example

Run every 15 minutes (tune to taste):

```cron
*/15 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/ai_notes.py --human-db /web/private/db/memory/human_notes.db --ai-db /web/private/db/memory/notes_ai_metadata.db --ollama-url http://127.0.0.1:11434 --model gpt-oss:latest --limit 200 >> /web/private/logs/ai_notes.log 2>&1
```

Make sure logs directory exists and is writable:

```bash
sudo mkdir -p /web/private/logs
sudo chown -R www-data:www-data /web/private/logs
sudo chmod 775 /web/private/logs
```

## Verify it worked

1) Confirm the table exists and has rows:

```bash
sqlite3 /web/private/db/memory/notes_ai_metadata.db "SELECT COUNT(*) FROM ai_note_meta;"
```

2) Inspect recent AI rows:

```bash
sqlite3 -cmd ".mode column" -cmd ".headers on" /web/private/db/memory/notes_ai_metadata.db \
	"SELECT id, note_id, notes_type, topic, substr(summary,1,80) AS summary, updated_at FROM ai_note_meta ORDER BY id DESC LIMIT 10;"
```

3) In the Notes UI, open:

- `?view=ai` (AI Metadata)

## Troubleshooting

- **Ollama not reachable**: check it’s listening on the host/port you pass via `--ollama-url`.
- **Model not found**: update `--model` to a model that exists on that Ollama server.
- **Wrong DB showing in UI**: ensure you write to `/web/private/db/memory/notes_ai_metadata.db` (or whatever `AI_DB_PATH` is set to in Notes).
- **It "doesn’t process new notes" with a small limit**: run with `--dry-run` and check the `scan_config` line. If `last_processed_note_id` is close to `max_note_id`, there may simply be nothing new to process. If you expect edits, increase `--backtrack`.

