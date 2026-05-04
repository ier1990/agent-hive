#!/usr/bin/env python3
"""Hourly summarize cached searches into a dedicated AI search notes DB.

Reads cached search snapshots from:
- {PRIVATE_ROOT}/db/memory/search_cache.db (table: search_cache_history)

For each cached search row that does NOT yet have ai_notes:
- Summarize via Ollama
- Store summary back into search_cache_history.ai_notes
- Insert a new note into ai_search_notes.db (notes_type=ai_generated)

Also writes a Jobs heartbeat row into the same AI search notes DB.
"""

from __future__ import annotations

import argparse
import json
import os
import sqlite3
import re
import sys
import time
from dataclasses import dataclass
from typing import Any, List, Optional, Tuple

import requests

from notes_config import get_config, get_private_root
from ai_templates import compile_payload_by_name, payload_to_chat_parts

PRIVATE_ROOT = get_private_root(__file__)
_CFG = get_config()

SEARCH_DB_DEFAULT = os.path.join(PRIVATE_ROOT, "db/memory/search_cache.db")
SEARCH_NOTES_DB_DEFAULT = os.path.join(PRIVATE_ROOT, "db/memory/ai_search_notes.db")
CURSOR_FILE_DEFAULT = os.path.join(PRIVATE_ROOT, "db/memory/ai_search_cnt_id.txt")
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


def ensure_search_notes_schema(conn: sqlite3.Connection) -> None:
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


def read_cursor_file(path: str) -> int:
    if not path:
        return 0
    try:
        with open(path, "r", encoding="utf-8") as f:
            raw = f.read().strip()
    except FileNotFoundError:
        return 0
    except Exception:
        return 0
    try:
        return max(0, int(raw))
    except Exception:
        return 0


def write_cursor_file(path: str, value: int) -> None:
    if not path:
        return
    try:
        os.makedirs(os.path.dirname(path), exist_ok=True)
    except Exception:
        pass
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        f.write(str(max(0, int(value))) + "\n")
    os.replace(tmp, path)


def normalize_query_text(text: str) -> str:
    text = str(text or "").strip().lower()
    text = re.sub(r"[^a-z0-9]+", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text


def looks_too_short(query: str, min_len: int) -> bool:
    normalized = normalize_query_text(query)
    if normalized == "":
        return True
    return len(normalized) < max(1, int(min_len))


def find_prior_similar_summary(search_conn: sqlite3.Connection, row: SearchRow) -> Optional[Tuple[int, str]]:
    normalized = normalize_query_text(row.q)
    if normalized == "":
        return None

    search_conn.row_factory = sqlite3.Row
    rows = search_conn.execute(
        """
        SELECT id, COALESCE(q, '') AS q, COALESCE(ai_notes, '') AS ai_notes
        FROM search_cache_history
        WHERE id < ?
          AND ai_notes IS NOT NULL
          AND TRIM(ai_notes) <> ''
        ORDER BY id ASC
        LIMIT 500
        """,
        (row.id,),
    ).fetchall()

    for r in rows:
        other_query = normalize_query_text(str(r["q"] or ""))
        other_notes = str(r["ai_notes"] or "").strip()
        if other_query == "":
            continue
        if other_notes == "":
            continue
        if other_notes.startswith("("):
            continue
        if other_query == normalized:
            return int(r["id"]), other_notes

    return None


def stored_ai_notes_to_text(raw: str) -> str:
    payload = parse_summary_payload(raw)
    return summary_payload_to_text(payload)


def call_ollama_search_summary(ollama_url: str, model: str, row: SearchRow, timeout_s: int) -> str:
    default_system = (
        "You summarize cached web search results for an internal notes system.\n"
        "Return ONLY a single JSON object. No markdown fences and no extra text.\n"
        "Schema:\n"
        "{\n"
        '  "summary_text": "plain text summary with short overview and bullets",\n'
        '  "overview": "1-2 sentence overview",\n'
        '  "bullets": ["key finding 1", "key finding 2"],\n'
        '  "notable_urls": ["https://example.com"]\n'
        "}\n"
        "Rules:\n"
        "- summary_text must be plain text and readable by humans.\n"
        "- bullets must be a short array of useful findings.\n"
        "- notable_urls can be empty.\n"
        "- If content looks like an error page or empty response, say so clearly.\n"
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


def parse_summary_payload(raw: str) -> dict:
    text = str(raw or "").strip()
    if text == "":
        return {
            "summary_text": "",
            "overview": "",
            "bullets": [],
            "notable_urls": [],
        }

    try:
        payload = json.loads(text)
        if not isinstance(payload, dict):
            raise ValueError("top-level payload must be an object")
    except Exception:
        return {
            "summary_text": text,
            "overview": text,
            "bullets": [],
            "notable_urls": [],
        }

    summary_text = str(payload.get("summary_text") or "").strip()
    overview = str(payload.get("overview") or "").strip()
    bullets = payload.get("bullets") if isinstance(payload.get("bullets"), list) else []
    notable_urls = payload.get("notable_urls") if isinstance(payload.get("notable_urls"), list) else []

    clean_bullets = []
    for item in bullets:
        item = str(item).strip()
        if item != "":
            clean_bullets.append(item)

    clean_urls = []
    for item in notable_urls:
        item = str(item).strip()
        if item != "":
            clean_urls.append(item)

    if summary_text == "":
        parts = []
        if overview != "":
            parts.append(overview)
        for item in clean_bullets[:7]:
            parts.append("- " + item)
        summary_text = "\n".join(parts).strip()

    if overview == "":
        overview = summary_text

    return {
        "summary_text": summary_text,
        "overview": overview,
        "bullets": clean_bullets,
        "notable_urls": clean_urls,
    }


def summary_payload_to_text(payload: dict) -> str:
    summary_text = str(payload.get("summary_text") or "").strip()
    if summary_text != "":
        return summary_text

    parts = []
    overview = str(payload.get("overview") or "").strip()
    if overview != "":
        parts.append(overview)

    bullets = payload.get("bullets") if isinstance(payload.get("bullets"), list) else []
    for item in bullets:
        item = str(item).strip()
        if item != "":
            parts.append("- " + item)

    return "\n".join(parts).strip()


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
    ap.add_argument("--notes-db", default=SEARCH_NOTES_DB_DEFAULT)
    ap.add_argument("--human-db", default="", help="deprecated alias for --notes-db")
    ap.add_argument("--cursor-file", default=CURSOR_FILE_DEFAULT, help="path to file storing last handled search_cache_history id")
    ap.add_argument("--ollama-url", default=OLLAMA_URL_DEFAULT)
    ap.add_argument("--model", default=MODEL_DEFAULT)
    ap.add_argument("--limit", type=int, default=500, help="max cached searches to process per run")
    ap.add_argument("--timeout", type=int, default=180)
    ap.add_argument("--sleep", type=float, default=0.0, help="sleep between calls (seconds)")
    ap.add_argument("--since-id", type=int, default=0, help="only process search_cache_history rows with id > since-id")
    ap.add_argument("--min-query-len", type=int, default=8, help="skip AI summary for very short normalized queries")
    ap.add_argument("--dry-run", action="store_true", help="do not call Ollama or write summaries; just report pending count")
    args = ap.parse_args()

    started = time.time()
    lock_or_exit(LOCK_PATH)
    notes_db_path = str(args.human_db or args.notes_db or "").strip()
    if notes_db_path == "":
        notes_db_path = SEARCH_NOTES_DB_DEFAULT

    search_conn = sqlite3.connect(args.search_db)
    ensure_search_schema(search_conn)

    human_conn = sqlite3.connect(notes_db_path)
    ensure_search_notes_schema(human_conn)

    job_upsert_start(human_conn, JOB_NAME)

    try:
        cursor_id = read_cursor_file(str(args.cursor_file or "").strip())
        effective_since_id = max(int(args.since_id), int(cursor_id))
        pending = load_pending_searches(search_conn, limit=args.limit, since_id=effective_since_id)
        if args.dry_run:
            dur_ms = int((time.time() - started) * 1000)
            job_upsert_finish(human_conn, JOB_NAME, True, dur_ms, f"dry_run pending={len(pending)} since_id={effective_since_id}")
            print(f"[DONE] dry_run pending={len(pending)} since_id={effective_since_id}", file=sys.stderr)
            return 0

        processed = 0
        skipped = 0
        failed = 0
        next_cursor = int(cursor_id)
        cursor_blocked = False

        for r in pending:
            try:
                handled = False
                if human_already_has_search_note(human_conn, r.id):
                    if not r.ai_notes.strip():
                        search_ai_notes_set(search_conn, r.id, "(already summarized into ai_search_notes.db)")
                    skipped += 1
                    handled = True
                elif looks_too_short(r.q, int(args.min_query_len)):
                    search_ai_notes_set(search_conn, r.id, "(skipped short query)")
                    skipped += 1
                    handled = True
                else:
                    similar = find_prior_similar_summary(search_conn, r)
                    if similar is not None:
                        similar_id, similar_summary = similar
                        search_ai_notes_set(search_conn, r.id, similar_summary)
                        skipped += 1
                        handled = True
                    else:
                        summary_raw = call_ollama_search_summary(args.ollama_url, args.model, r, timeout_s=args.timeout)
                        summary_payload = parse_summary_payload(summary_raw)
                        summary_text = summary_payload_to_text(summary_payload)
                        if not summary_text:
                            summary_text = "(empty summary returned by model)"
                            summary_payload["summary_text"] = summary_text
                            summary_payload["overview"] = summary_text

                        note_text = (
                            f"search_cache_id: {r.id}\n"
                            f"cached_at: {r.cached_at}\n"
                            f"query: {r.q}\n\n"
                            "top_urls:\n"
                            + "\n".join([f"- {u}" for u in r.top_urls[:10]])
                            + "\n\n"
                            "summary:\n"
                            + summary_text.strip()
                            + "\n"
                        )

                        topic = f"search: {r.q}" if r.q.strip() else "search: (no query)"
                        insert_human_note(human_conn, notes_type="ai_generated", topic=topic, note=note_text)
                        search_ai_notes_set(
                            search_conn,
                            r.id,
                            json.dumps(summary_payload, ensure_ascii=False, separators=(",", ":")),
                        )
                        processed += 1
                        handled = True
                        if args.sleep > 0:
                            time.sleep(args.sleep)

                if handled and not cursor_blocked:
                    next_cursor = int(r.id)
            except Exception as e:
                failed += 1
                cursor_blocked = True
                print(f"[ERROR] search_cache_id={r.id}: {e}", file=sys.stderr)

        if next_cursor > int(cursor_id):
            write_cursor_file(str(args.cursor_file or "").strip(), next_cursor)

        dur_ms = int((time.time() - started) * 1000)
        msg = f"processed={processed} skipped={skipped} failed={failed} scanned={len(pending)} since_id={effective_since_id} cursor={next_cursor}"
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
