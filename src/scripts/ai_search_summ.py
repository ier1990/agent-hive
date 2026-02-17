#!/usr/bin/env python3
"""Hourly summarize cached searches into Notes.

Reads cached search snapshots from:
- {PRIVATE_ROOT}/db/memory/search_cache.db (table: search_cache_history)

For each cached search row that does NOT yet have ai_notes:
- Summarize via Ollama
- Store summary back into search_cache_history.ai_notes
- Insert a new note into human_notes.db (notes_type=ai_generated)

Also writes a Jobs heartbeat row into human_notes.db table job_runs.
"""

from __future__ import annotations

import argparse
import json
import os
import sqlite3
import sys
import time
from dataclasses import dataclass
from typing import Any, List

import requests

from notes_config import get_config, get_private_root
from ai_templates import compile_payload_by_name, payload_to_chat_parts

PRIVATE_ROOT = get_private_root(__file__)
_CFG = get_config()

SEARCH_DB_DEFAULT = os.path.join(PRIVATE_ROOT, "db/memory/search_cache.db")
HUMAN_DB_DEFAULT = os.path.join(PRIVATE_ROOT, "db/memory/human_notes.db")
OLLAMA_URL_DEFAULT = _CFG.get("ai.ollama.url", "http://127.0.0.1:11434")
MODEL_DEFAULT = _CFG.get("ai.ollama.model", "gpt-oss:latest")

LOCK_PATH = os.path.join(PRIVATE_ROOT, "locks", "ai_search_summ.lock")
JOB_NAME = "ai_search_summ"


@dataclass
class SearchRow:
    id: int
    q: str
    body: str
    top_urls: List[str]
    cached_at: str
    ai_notes: str


def ensure_search_schema(conn: sqlite3.Connection) -> None:
    conn.execute("PRAGMA journal_mode=WAL;")
    conn.executescript(
        """
        CREATE TABLE IF NOT EXISTS search_cache_history (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          key_hash CHAR(64) NOT NULL,
          q TEXT,
          body MEDIUMTEXT NOT NULL,
          top_urls TEXT,
          ai_notes TEXT,
          cached_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_search_cache_history_key_time
          ON search_cache_history(key_hash, cached_at);
        """
    )

    cols = {str(r[1]).lower() for r in conn.execute("PRAGMA table_info(search_cache_history);").fetchall()}
    if "ai_notes" not in cols:
        conn.execute("ALTER TABLE search_cache_history ADD COLUMN ai_notes TEXT;")
    if "top_urls" not in cols:
        conn.execute("ALTER TABLE search_cache_history ADD COLUMN top_urls TEXT;")

    conn.commit()


def ensure_human_schema(conn: sqlite3.Connection) -> None:
    conn.execute("PRAGMA journal_mode=WAL;")
    conn.executescript(
        """
        CREATE TABLE IF NOT EXISTS notes (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          notes_type TEXT NOT NULL,
          topic TEXT,
          node TEXT,
          path TEXT,
          version TEXT,
          ts TEXT,
          note TEXT NOT NULL,
          parent_id INTEGER DEFAULT 0,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_notes_parent ON notes(parent_id);
        CREATE INDEX IF NOT EXISTS idx_notes_created ON notes(created_at DESC);
        CREATE INDEX IF NOT EXISTS idx_notes_search ON notes(note);

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


def lock_or_exit(path: str) -> None:
    try:
        os.makedirs(os.path.dirname(path), exist_ok=True)
    except Exception:
        pass

    fd = os.open(path, os.O_RDWR | os.O_CREAT, 0o644)
    try:
        import fcntl

        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        sys.exit(0)


def job_upsert_start(db: sqlite3.Connection, job: str) -> None:
    db.execute(
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
    db.commit()


def job_upsert_finish(db: sqlite3.Connection, job: str, ok: bool, duration_ms: int, message: str) -> None:
    msg = (message or "")[:900]
    if ok:
        db.execute(
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
        db.execute(
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
    db.commit()


def load_pending_searches(search_conn: sqlite3.Connection, limit: int, since_id: int = 0) -> List[SearchRow]:
    search_conn.row_factory = sqlite3.Row
    rows = search_conn.execute(
        """
        SELECT
          id,
          COALESCE(q,'') AS q,
          COALESCE(body,'') AS body,
          COALESCE(top_urls,'[]') AS top_urls,
          COALESCE(ai_notes,'') AS ai_notes,
          COALESCE(cached_at,'') AS cached_at
        FROM search_cache_history
        WHERE id > ?
          AND (ai_notes IS NULL OR TRIM(ai_notes) = '')
        ORDER BY id ASC
        LIMIT ?;
        """,
        (since_id, limit),
    ).fetchall()

    out: List[SearchRow] = []
    for r in rows:
        raw_top = str(r["top_urls"] or "[]")
        try:
            top = json.loads(raw_top)
            if not isinstance(top, list):
                top = []
        except Exception:
            top = []
        top_urls = [str(u) for u in top if isinstance(u, str) and u.strip()]

        out.append(
            SearchRow(
                id=int(r["id"]),
                q=str(r["q"] or ""),
                body=str(r["body"] or ""),
                top_urls=top_urls,
                cached_at=str(r["cached_at"] or ""),
                ai_notes=str(r["ai_notes"] or ""),
            )
        )

    return out


def call_ollama_search_summary(ollama_url: str, model: str, row: SearchRow, timeout_s: int) -> str:
    default_system = (
        "You summarize cached web search results for an internal notes system.\n"
        "Be concise and actionable. Output PLAIN TEXT only.\n"
        "Include: 1-2 sentence overview, then 3-7 bullet points of key findings.\n"
        "If content looks like a backend error page or empty response, say so clearly.\n"
    )

    top = "\n".join([f"- {u}" for u in row.top_urls[:15]])
    default_user = (
        f"search_cache_id: {row.id}\n"
        f"cached_at: {row.cached_at}\n"
        f"query: {row.q}\n\n"
        "TOP_URLS:\n"
        f"{top}\n\n"
        "RAW_SEARCH_JSON:\n"
        f"{row.body}\n"
    )

    template_name = os.getenv("AI_TEMPLATE_SEARCH_SUMMARY", "Search Summary")
    compiled = compile_payload_by_name(
        template_name,
        {
            "row": {
                "id": row.id,
                "cached_at": row.cached_at,
                "q": row.q,
                "body": row.body,
                "top_urls_formatted": top,
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

    r = requests.post(f"{ollama_url.rstrip('/')}/api/chat", json=payload, timeout=timeout_s)
    r.raise_for_status()
    data = r.json()

    content = (data.get("message") or {}).get("content") or ""
    return str(content).strip()


def search_ai_notes_set(search_conn: sqlite3.Connection, search_id: int, ai_notes: str) -> None:
    search_conn.execute("UPDATE search_cache_history SET ai_notes=? WHERE id=?;", (ai_notes, search_id))
    search_conn.commit()


def human_already_has_search_note(human_conn: sqlite3.Connection, search_id: int) -> bool:
    marker = f"search_cache_id: {search_id}"
    row = human_conn.execute("SELECT 1 FROM notes WHERE note LIKE ? LIMIT 1;", (f"%{marker}%",)).fetchone()
    return row is not None


def insert_human_note(human_conn: sqlite3.Connection, notes_type: str, topic: str, note: str) -> int:
    cur = human_conn.execute(
        "INSERT INTO notes (notes_type, topic, note, parent_id) VALUES (?, ?, ?, 0);",
        (notes_type, topic, note),
    )
    human_conn.commit()
    return int(cur.lastrowid)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--search-db", default=SEARCH_DB_DEFAULT)
    ap.add_argument("--human-db", default=HUMAN_DB_DEFAULT)
    ap.add_argument("--ollama-url", default=OLLAMA_URL_DEFAULT)
    ap.add_argument("--model", default=MODEL_DEFAULT)
    ap.add_argument("--limit", type=int, default=500, help="max cached searches to process per run")
    ap.add_argument("--timeout", type=int, default=180)
    ap.add_argument("--sleep", type=float, default=0.0, help="sleep between calls (seconds)")
    ap.add_argument("--since-id", type=int, default=0, help="only process search_cache_history rows with id > since-id")
    ap.add_argument("--dry-run", action="store_true", help="do not call Ollama or write summaries; just report pending count")
    args = ap.parse_args()

    started = time.time()
    lock_or_exit(LOCK_PATH)

    search_conn = sqlite3.connect(args.search_db)
    ensure_search_schema(search_conn)

    human_conn = sqlite3.connect(args.human_db)
    ensure_human_schema(human_conn)

    job_upsert_start(human_conn, JOB_NAME)

    try:
        pending = load_pending_searches(search_conn, limit=args.limit, since_id=args.since_id)
        if args.dry_run:
            dur_ms = int((time.time() - started) * 1000)
            job_upsert_finish(human_conn, JOB_NAME, True, dur_ms, f"dry_run pending={len(pending)}")
            print(f"[DONE] dry_run pending={len(pending)}", file=sys.stderr)
            return 0

        processed = 0
        skipped = 0
        failed = 0

        for r in pending:
            try:
                if human_already_has_search_note(human_conn, r.id):
                    if not r.ai_notes.strip():
                        search_ai_notes_set(search_conn, r.id, "(already summarized into human_notes.db)")
                    skipped += 1
                    continue

                summary = call_ollama_search_summary(args.ollama_url, args.model, r, timeout_s=args.timeout)
                if not summary:
                    summary = "(empty summary returned by model)"

                note_text = (
                    f"search_cache_id: {r.id}\n"
                    f"cached_at: {r.cached_at}\n"
                    f"query: {r.q}\n\n"
                    "top_urls:\n"
                    + "\n".join([f"- {u}" for u in r.top_urls[:10]])
                    + "\n\n"
                    "summary:\n"
                    + summary.strip()
                    + "\n"
                )

                topic = f"search: {r.q}" if r.q.strip() else "search: (no query)"
                insert_human_note(human_conn, notes_type="ai_generated", topic=topic, note=note_text)
                search_ai_notes_set(search_conn, r.id, summary.strip())

                processed += 1
                if args.sleep > 0:
                    time.sleep(args.sleep)
            except Exception as e:
                failed += 1
                print(f"[ERROR] search_cache_id={r.id}: {e}", file=sys.stderr)

        dur_ms = int((time.time() - started) * 1000)
        msg = f"processed={processed} skipped={skipped} failed={failed} scanned={len(pending)}"
        job_upsert_finish(human_conn, JOB_NAME, failed == 0, dur_ms, msg)
        print(f"[DONE] {msg}", file=sys.stderr)
        return 0

    except Exception as e:
        dur_ms = int((time.time() - started) * 1000)
        job_upsert_finish(human_conn, JOB_NAME, False, dur_ms, f"fatal: {str(e)}")
        raise
    finally:
        try:
            search_conn.close()
        except Exception:
            pass
        try:
            human_conn.close()
        except Exception:
            pass


if __name__ == "__main__":
    raise SystemExit(main())
