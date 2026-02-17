#!/usr/bin/env python3
"""
_metadata. now ai_notes.py
Summary or  metadata pass: human_notes.db -> notes_ai_metadata.db using Ollama.

- Incremental: only processes notes that changed (source_hash).
- Writes strict JSON metadata per note to ai_note_meta table.
- Writes summary, tags, doc_kind, entities, commands, sensitivity, etc.
- Configurable via command-line args.
- Uses Ollama LLM via local HTTP API.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import sqlite3
import sys
import time
from dataclasses import dataclass
from typing import Any, Dict, List, Optional

import requests

from notes_config import get_config, get_private_root
from ai_templates import compile_payload_by_name, payload_to_chat_parts

PRIVATE_ROOT = get_private_root(__file__)
HUMAN_DB_DEFAULT = os.path.join(PRIVATE_ROOT, "db/memory/human_notes.db")
AI_DB_DEFAULT = os.path.join(PRIVATE_ROOT, "db/memory/notes_ai_metadata.db")
OLLAMA_URL_DEFAULT = "http://192.168.0.142:11434"
MODEL_DEFAULT = "gpt-oss:latest"

_CFG = get_config()
OLLAMA_URL_DEFAULT = _CFG.get("ai.ollama.url", OLLAMA_URL_DEFAULT)
MODEL_DEFAULT = _CFG.get("ai.ollama.model", MODEL_DEFAULT)


@dataclass
class NoteRow:
    id: int
    parent_id: int
    notes_type: str
    topic: str
    note: str
    created_at: str
    updated_at: str


def sha256_hex(s: str) -> str:
    return hashlib.sha256(s.encode("utf-8", errors="replace")).hexdigest()


def ensure_ai_schema(conn: sqlite3.Connection) -> None:
    conn.execute("PRAGMA journal_mode=WAL;")
    conn.executescript(
        """
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
        """
    )
    conn.commit()


def ensure_job_runs_schema(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS job_runs (
          job TEXT PRIMARY KEY,
          last_start TEXT,
          last_ok TEXT,
          last_status TEXT,
          last_message TEXT,
          last_duration_ms INTEGER
        );
        """
    )
    conn.commit()


def job_upsert_start(conn: sqlite3.Connection, job: str) -> None:
    conn.execute(
        """
        INSERT INTO job_runs(job, last_start, last_status, last_message, last_duration_ms)
        VALUES(?, datetime('now'), 'running', '', NULL)
        ON CONFLICT(job) DO UPDATE SET
          last_start=datetime('now'),
          last_status='running',
          last_message='',
          last_duration_ms=NULL;
        """,
        (job,),
    )
    conn.commit()


def job_upsert_finish(conn: sqlite3.Connection, job: str, ok: bool, duration_ms: int, message: str) -> None:
    msg = (message or "")[:900]
    if ok:
        conn.execute(
            """
            INSERT INTO job_runs(job, last_ok, last_status, last_message, last_duration_ms)
            VALUES(?, datetime('now'), 'ok', ?, ?)
            ON CONFLICT(job) DO UPDATE SET
              last_ok=datetime('now'),
              last_status='ok',
              last_message=excluded.last_message,
              last_duration_ms=excluded.last_duration_ms;
            """,
            (job, msg, int(duration_ms)),
        )
    else:
        conn.execute(
            """
            INSERT INTO job_runs(job, last_status, last_message, last_duration_ms)
            VALUES(?, 'error', ?, ?)
            ON CONFLICT(job) DO UPDATE SET
              last_status='error',
              last_message=excluded.last_message,
              last_duration_ms=excluded.last_duration_ms;
            """,
            (job, msg, int(duration_ms)),
        )
    conn.commit()


def get_max_note_id(human_db: str) -> int:
    conn = sqlite3.connect(human_db)
    try:
        row = conn.execute("SELECT COALESCE(MAX(id), 0) FROM notes;").fetchone()
        return int(row[0] or 0)
    finally:
        conn.close()


def load_notes(human_db: str, limit: int, since_id: int = 0) -> List[NoteRow]:
    conn = sqlite3.connect(human_db)
    conn.row_factory = sqlite3.Row

    # Important: we fetch newest-first so a small LIMIT still reaches recent notes.
    # We'll reverse before returning so processing stays oldest->newest.
    rows = conn.execute(
        """
        SELECT id, parent_id, notes_type, COALESCE(topic,'') AS topic, note,
               COALESCE(created_at,'') AS created_at,
               COALESCE(updated_at,'') AS updated_at
        FROM notes
        WHERE id > ?
        ORDER BY id DESC
        LIMIT ?;
        """,
        (since_id, limit),
    ).fetchall()

    conn.close()

    out: List[NoteRow] = []
    for r in reversed(rows):
        out.append(
            NoteRow(
                id=int(r["id"]),
                parent_id=int(r["parent_id"] or 0),
                notes_type=str(r["notes_type"] or ""),
                topic=str(r["topic"] or ""),
                note=str(r["note"] or ""),
                created_at=str(r["created_at"] or ""),
                updated_at=str(r["updated_at"] or ""),
            )
        )
    return out


def already_done(ai_conn: sqlite3.Connection, note_id: int, source_hash: str) -> bool:
    row = ai_conn.execute(
        "SELECT 1 FROM ai_note_meta WHERE note_id=? AND source_hash=? LIMIT 1;",
        (note_id, source_hash),
    ).fetchone()
    return row is not None


def get_last_processed_note_id(ai_conn: sqlite3.Connection) -> int:
    row = ai_conn.execute("SELECT COALESCE(MAX(note_id), 0) FROM ai_note_meta;").fetchone()
    return int(row[0] or 0)


def call_ollama_metadata(
    ollama_url: str,
    model: str,
    note: NoteRow,
    timeout_s: int,
) -> Dict[str, Any]:
    """
    Calls Ollama /api/chat and forces strict JSON output.
    """
    default_system = (
        "You generate metadata for an internal LAN-only notes system.\n"
        "Return ONLY a single JSON object. No markdown, no code fences, no extra text.\n"
        "Schema:\n"
        "{\n"
        '  "doc_kind": "bash_history|sysinfo|manual_pdf|bios_pdf|general_note|code|reminder|passwords|links|images|files|tags|other",\n'
        '  "summary": "1-2 sentence summary",\n'
        '  "tags": ["tag1","tag2"],\n'
        '  "entities": ["asus","x570","tpm","secure boot"],\n'
        '  "commands": ["systemctl restart ollama","apt-get install ..."],\n'
        '  "cmd_families": ["systemctl","apt","docker","ufw","journalctl"],\n'
        '  "sensitivity": "normal|sensitive"\n'
        "}\n"
        "Rules:\n"
        "- tags/entities/commands/cmd_families must be arrays (can be empty).\n"
        "- If note looks like bash history or logs, extract commands.\n"
        "- If note looks like a manual/pdf, set doc_kind accordingly.\n"
        "- If note_type is 'passwords', set sensitivity='sensitive' and keep summary minimal.\n"
    )

    default_user = (
        f"note_id: {note.id}\n"
        f"parent_id: {note.parent_id}\n"
        f"notes_type: {note.notes_type}\n"
        f"topic: {note.topic}\n"
        f"created_at: {note.created_at}\n"
        f"updated_at: {note.updated_at}\n\n"
        "NOTE CONTENT:\n"
        f"{note.note}\n"
    )

    template_name = os.getenv("AI_TEMPLATE_NOTES_METADATA", "Notes Metadata")
    compiled = compile_payload_by_name(
        template_name,
        {
            "note": {
                "id": note.id,
                "parent_id": note.parent_id,
                "notes_type": note.notes_type,
                "topic": note.topic,
                "created_at": note.created_at,
                "updated_at": note.updated_at,
                "note": note.note,
            }
        },
        template_type="payload",
    )
    payload_tpl = compiled.get("payload") if isinstance(compiled, dict) else {}
    system, user, options, stream = payload_to_chat_parts(payload_tpl, default_system, default_user)
    if not isinstance(options, dict):
        options = {}
    if "temperature" not in options:
        options["temperature"] = 0.2

    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": system},
            {"role": "user", "content": user},
        ],
        "stream": bool(stream),
        "options": options,
    }

    r = requests.post(f"{ollama_url}/api/chat", json=payload, timeout=timeout_s)
    r.raise_for_status()
    data = r.json()

    content = (data.get("message") or {}).get("content") or ""
    content = content.strip()

    # Hard-parse JSON only
    meta = json.loads(content)

    # Normalize required keys
    meta.setdefault("doc_kind", "other")
    meta.setdefault("summary", "")
    meta.setdefault("tags", [])
    meta.setdefault("entities", [])
    meta.setdefault("commands", [])
    meta.setdefault("cmd_families", [])
    meta.setdefault("sensitivity", "normal")

    # Type safety
    for k in ("tags", "entities", "commands", "cmd_families"):
        if not isinstance(meta.get(k), list):
            meta[k] = []

    if not isinstance(meta.get("summary"), str):
        meta["summary"] = str(meta.get("summary", ""))

    if meta.get("sensitivity") not in ("normal", "sensitive"):
        meta["sensitivity"] = "normal"

    return meta


def upsert_meta(
    ai_conn: sqlite3.Connection,
    note: NoteRow,
    source_hash: str,
    model: str,
    meta: Dict[str, Any],
) -> None:
    summary = meta.get("summary", "")
    tags = meta.get("tags", [])
    tags_csv = ",".join([t.strip() for t in tags if isinstance(t, str) and t.strip()])

    ai_conn.execute(
        """
        INSERT INTO ai_note_meta
          (note_id, parent_id, notes_type, topic, source_hash, model_name, meta_json, summary, tags_csv, created_at, updated_at)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ON CONFLICT(note_id, source_hash) DO UPDATE SET
          parent_id=excluded.parent_id,
          notes_type=excluded.notes_type,
          topic=excluded.topic,
          model_name=excluded.model_name,
          meta_json=excluded.meta_json,
          summary=excluded.summary,
          tags_csv=excluded.tags_csv,
          updated_at=CURRENT_TIMESTAMP;
        """,
        (
            note.id,
            note.parent_id,
            note.notes_type,
            note.topic,
            source_hash,
            model,
            json.dumps(meta, ensure_ascii=False),
            summary,
            tags_csv,
        ),
    )
    ai_conn.commit()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--human-db", default=HUMAN_DB_DEFAULT)
    ap.add_argument("--ai-db", default=AI_DB_DEFAULT)
    ap.add_argument("--ollama-url", default=OLLAMA_URL_DEFAULT)
    ap.add_argument("--model", default=MODEL_DEFAULT)
    ap.add_argument("--limit", type=int, default=500, help="max notes to scan per run")
    ap.add_argument("--timeout", type=int, default=180)
    ap.add_argument("--sleep", type=float, default=0.0, help="sleep between calls (seconds)")
    ap.add_argument("--since-id", type=int, default=0, help="force starting note id")
    ap.add_argument("--backtrack", type=int, default=200, help="scan backwards this many note IDs to catch recent edits")
    ap.add_argument("--dry-run", action="store_true", help="do not call Ollama or write ai_db; just report what would be processed")
    args = ap.parse_args()

    ai_conn = sqlite3.connect(args.ai_db)
    ensure_ai_schema(ai_conn)

    # Heartbeat goes to the human notes DB.
    job_name = "ai_notes"
    hb = sqlite3.connect(args.human_db)
    ensure_job_runs_schema(hb)
    job_upsert_start(hb, job_name)
    t0 = time.time()

    last_processed = get_last_processed_note_id(ai_conn)
    backtrack = max(0, int(args.backtrack))
    start_from = args.since_id if args.since_id > 0 else max(0, last_processed - backtrack)
    max_note_id = get_max_note_id(args.human_db)

    print(
        "[INFO] scan_config "
        f"human_db={args.human_db} ai_db={args.ai_db} "
        f"max_note_id={max_note_id} last_processed_note_id={last_processed} "
        f"start_from={start_from} limit={args.limit} backtrack={backtrack} dry_run={bool(args.dry_run)} "
        f"ollama_url={args.ollama_url} model={args.model}",
        file=sys.stderr,
    )

    notes = load_notes(args.human_db, limit=args.limit, since_id=start_from)

    processed = 0
    skipped = 0
    failed = 0
    would_process = 0

    try:
        for n in notes:
            # Build a stable source_hash so edits trigger reprocessing
            material = f"{n.notes_type}\n{n.topic}\n{n.updated_at}\n{n.note}"
            source_hash = sha256_hex(material)

            if already_done(ai_conn, n.id, source_hash):
                skipped += 1
                continue

            try:
                if args.dry_run:
                    would_process += 1
                else:
                    meta = call_ollama_metadata(args.ollama_url, args.model, n, timeout_s=args.timeout)
                    upsert_meta(ai_conn, n, source_hash, args.model, meta)
                    processed += 1
                if args.sleep > 0:
                    time.sleep(args.sleep)
            except Exception as e:
                failed += 1
                # Keep going; log to stderr
                print(f"[ERROR] note_id={n.id}: {e}", file=sys.stderr)

        dur_ms = int((time.time() - t0) * 1000)
        msg = f"processed={processed} would_process={would_process} skipped={skipped} failed={failed} scanned={len(notes)} start_from={start_from} max_note_id={max_note_id}"
        job_upsert_finish(hb, job_name, failed == 0, dur_ms, msg)

        print(f"[DONE] {msg}", file=sys.stderr)
        return 0
    except Exception as e:
        dur_ms = int((time.time() - t0) * 1000)
        try:
            job_upsert_finish(hb, job_name, False, dur_ms, f"fatal: {str(e)}")
        except Exception:
            pass
        raise
    finally:
        try:
            hb.close()
        except Exception:
            pass


if __name__ == "__main__":
    raise SystemExit(main())
