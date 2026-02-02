#!/usr/bin/env python3
"""Tester: tail recent bash history and append into Notes as threaded logs.

Behavior:
- Reads the last N lines of a user's ~/.bash_history (default: 25)
- Creates a new parent note per day (YYYY-MM-DD) per host+user
- Appends a child note each run (threaded under the daily parent)

Examples:
- One-off:  python3 save_bash_history_threaded.py samekhi
- Limit:    python3 save_bash_history_threaded.py samekhi --limit 50

Notes UI:
- Jobs: http://<host>/admin/notes/?view=jobs
- Bash: http://<host>/admin/notes/?view=bash
"""

import argparse
import datetime
import fcntl
import logging
import os
import socket
import sqlite3
import sys
from typing import Dict, List, Optional

from notes_config import get_private_root

PRIVATE_ROOT = get_private_root(__file__)
DB_PATH = os.path.join(PRIVATE_ROOT, "db/memory/human_notes.db")

NOTES_TYPE = "logs"
TOPIC = "bash_history"


def parse_args(argv: List[str]) -> argparse.Namespace:
    ap = argparse.ArgumentParser(description="Tail bash history and append into Notes as threaded logs")
    ap.add_argument("username", help="Linux username (e.g. samekhi, root)")
    ap.add_argument("--limit", type=int, default=25, help="How many recent history lines to capture")
    ap.add_argument("--debug", action="store_true", help="Print debug/progress logs to stderr")
    ap.add_argument("--tutor", action="store_true", help="Print step-by-step narration of what the script is doing")
    ap.add_argument(
        "--clean-logs",
        nargs="?",
        const=7,
        default=None,
        type=int,
        metavar="DAYS",
        help="Delete old rows from notes where notes_type='logs' (keeps last DAYS; default 7 when flag is present)",
    )
    return ap.parse_args(argv)


def setup_logging(debug: bool) -> logging.Logger:
    logger = logging.getLogger("save_bash_history_threaded")
    if logger.handlers:
        return logger

    logger.setLevel(logging.DEBUG if debug else logging.INFO)
    logger.propagate = False

    handler = logging.StreamHandler(sys.stderr)
    handler.setLevel(logging.DEBUG if debug else logging.INFO)
    handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s", "%Y-%m-%d %H:%M:%S"))
    logger.addHandler(handler)
    return logger


class Tutor:
    def __init__(self, enabled: bool):
        self.enabled = bool(enabled)
        self._step = 0

    def say(self, message: str) -> None:
        if not self.enabled:
            return
        self._step += 1
        print(f"[{self._step}] {message}", file=sys.stderr)


def _cutoff_day_str(keep_days: int) -> str:
    if keep_days < 0:
        keep_days = 0
    cutoff = datetime.date.today() - datetime.timedelta(days=int(keep_days))
    return cutoff.strftime("%Y-%m-%d")


def cleanup_logs(db: sqlite3.Connection, keep_days: int) -> int:
    """Delete logs older than cutoff.

    We delete rows where notes_type='logs' and either:
    - ts is set (YYYY-MM-DD) and older than cutoff day
    - ts missing/blank and created_at date is older than cutoff day

    Returns number of deleted rows.
    """
    cutoff_day = _cutoff_day_str(int(keep_days))
    cur = db.execute(
        """
        DELETE FROM notes
        WHERE notes_type=?
          AND (
                (COALESCE(ts,'') != '' AND ts < ?)
             OR (COALESCE(ts,'') = '' AND COALESCE(created_at,'') != '' AND substr(created_at,1,10) < ?)
          )
        """,
        ("logs", cutoff_day, cutoff_day),
    )
    db.commit()
    try:
        return int(cur.rowcount or 0)
    except Exception:
        return 0


def lock_or_exit(lock_path: str) -> None:
    # Prevent concurrent runs (cron overlap)
    try:
        os.makedirs(os.path.dirname(lock_path), exist_ok=True)
    except Exception:
        pass
    fd = os.open(lock_path, os.O_RDWR | os.O_CREAT, 0o644)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        sys.exit(0)  # another instance is running
    # keep fd open for process lifetime


def now_sqlite() -> str:
    # ISO-like string, SQLite-friendly
    return datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def today_str() -> str:
    return datetime.date.today().strftime("%Y-%m-%d")


def ensure_notes_schema(db: sqlite3.Connection) -> None:
    """Best-effort schema creation/migration to match admin/notes PHP schema."""
    db.execute(
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
        """
    )

    existing = {}
    for row in db.execute("PRAGMA table_info(notes);").fetchall():
        try:
            existing[str(row[1]).lower()] = True
        except Exception:
            pass

    # Migrate older DBs
    for name, typ in (
        ("node", "TEXT"),
        ("path", "TEXT"),
        ("version", "TEXT"),
        ("ts", "TEXT"),
        ("topic", "TEXT"),
    ):
        if name not in existing:
            try:
                db.execute(f"ALTER TABLE notes ADD COLUMN {name} {typ}")
            except Exception:
                pass

    db.execute("CREATE INDEX IF NOT EXISTS idx_notes_parent ON notes(parent_id)")
    db.execute("CREATE INDEX IF NOT EXISTS idx_notes_created ON notes(created_at DESC)")
    db.commit()


def _tail_lines(path: str, limit: int) -> List[str]:
    if limit <= 0:
        return []
    with open(path, "r", errors="ignore") as f:
        lines = f.read().splitlines()
    # Keep last N non-empty lines (but preserve original order)
    out = [s for s in lines if s.strip()]
    return out[-limit:]


def find_or_create_parent(
    db: sqlite3.Connection,
    host: str,
    user: str,
    day: str,
    hist_path: str,
    parent_note: str,
) -> int:
    row = db.execute(
        """
        SELECT id
        FROM notes
        WHERE parent_id=0
          AND notes_type=?
          AND topic=?
          AND ts=?
          AND node=?
          AND path=?
        ORDER BY id DESC
        LIMIT 1
        """,
        (NOTES_TYPE, TOPIC, day, host, hist_path),
    ).fetchone()

    if row:
        return int(row[0])

    cur = db.execute(
        """
        INSERT INTO notes (notes_type, topic, node, path, ts, note, parent_id, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?)
        """,
        (NOTES_TYPE, TOPIC, host, hist_path, day, parent_note, 0, now_sqlite(), now_sqlite()),
    )
    db.commit()
    return int(cur.lastrowid)


def insert_child(db: sqlite3.Connection, parent_id: int, host: str, hist_path: str, day: str, child_note: str) -> None:
    db.execute(
        """
        INSERT INTO notes (notes_type, topic, node, path, ts, note, parent_id, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?)
        """,
        (NOTES_TYPE, TOPIC, host, hist_path, day, child_note, int(parent_id), now_sqlite(), now_sqlite()),
    )
    db.commit()


def main() -> int:
    args = parse_args(sys.argv[1:])

    tutor = Tutor(bool(args.tutor))
    logger = setup_logging(bool(args.debug))
    started = datetime.datetime.now()

    user = args.username
    host = socket.gethostname()
    day = today_str()

    tutor.say(f"Starting run for user='{user}' on host='{host}' for day='{day}'.")
    tutor.say(f"Resolved PRIVATE_ROOT='{PRIVATE_ROOT}'.")
    tutor.say(f"Notes DB path is '{DB_PATH}'.")

    hist_file = "/root/.bash_history" if user == "root" else f"/home/{user}/.bash_history"
    tutor.say(f"Target bash history file: '{hist_file}'.")
    if not os.path.isfile(hist_file):
        logger.info("noop no_history_file user=%s path=%s", user, hist_file)
        tutor.say("No-op: history file does not exist; nothing to write.")
        return 0

    lock_path = os.path.join(PRIVATE_ROOT, "locks", f"save_bash_history_{user}.lock")
    logger.debug("lock path=%s", lock_path)
    tutor.say(f"Acquiring lock at '{lock_path}' to prevent overlapping runs.")
    lock_or_exit(lock_path)
    tutor.say("Lock acquired.")

    # Connect early so --clean-logs runs even if history tailing becomes a no-op.
    db = sqlite3.connect(DB_PATH)
    try:
        tutor.say("Ensuring Notes schema exists (creating/migrating tables/indexes if needed).")
        ensure_notes_schema(db)

        if args.clean_logs is not None:
            keep_days = int(args.clean_logs)
            tutor.say(f"Cleaning old logs: deleting notes rows with notes_type='logs' older than {keep_days} days.")
            deleted = cleanup_logs(db, keep_days)
            logger.info("clean_logs deleted_rows=%s keep_days=%s cutoff_day=%s", deleted, keep_days, _cutoff_day_str(keep_days))
            tutor.say(f"Cleaned logs: deleted {deleted} rows.")

        if not os.path.isfile(hist_file):
            logger.info("noop no_history_file user=%s path=%s", user, hist_file)
            tutor.say("No-op: history file does not exist; nothing to write.")
            dur_ms = int((datetime.datetime.now() - started).total_seconds() * 1000)
            logger.info("done duration_ms=%s", dur_ms)
            tutor.say(f"Completed (no-op) in {dur_ms} ms.")
            return 0

        limit = int(args.limit)
        tutor.say(f"Reading bash history and taking the last {limit} non-empty lines.")
        lines = _tail_lines(hist_file, limit)
        if not lines:
            logger.info("noop no_lines user=%s path=%s limit=%s", user, hist_file, limit)
            tutor.say("No-op: history file had no non-empty lines to record.")
            dur_ms = int((datetime.datetime.now() - started).total_seconds() * 1000)
            logger.info("done duration_ms=%s", dur_ms)
            tutor.say(f"Completed (no-op) in {dur_ms} ms.")
            return 0

        tutor.say(f"Captured {len(lines)} lines from history.")

        logger.info(
            "start user=%s host=%s day=%s limit=%s private_root=%s db=%s",
            user,
            host,
            day,
            limit,
            PRIVATE_ROOT,
            DB_PATH,
        )

        parent_note = (
            f"### Bash History — {day}\n"
            f"**Host:** {host}  \n"
            f"**User:** {user}  \n"
            f"**Path:** {hist_file}  \n"
        )

        parent_id = find_or_create_parent(
            db=db,
            host=host,
            user=user,
            day=day,
            hist_path=hist_file,
            parent_note=parent_note,
        )

        logger.info("parent_ok id=%s topic=%s notes_type=%s", int(parent_id), TOPIC, NOTES_TYPE)
        tutor.say(f"Daily parent note OK: parent_id={int(parent_id)} (topic='{TOPIC}').")

        ts = now_sqlite()
        child_note = (
            f"**Tail {int(args.limit)} lines** at {ts}  \n"
            f"```bash\n" + "\n".join(lines) + "\n```\n"
        )
        insert_child(db, parent_id, host, hist_file, day, child_note)
        child_id = db.execute("SELECT last_insert_rowid()", ()).fetchone()[0]
        logger.info("child_ok id=%s parent_id=%s lines=%s", int(child_id), int(parent_id), int(len(lines)))
        tutor.say(f"Inserted child note: id={int(child_id)} under parent_id={int(parent_id)}.")
        dur_ms = int((datetime.datetime.now() - started).total_seconds() * 1000)
        logger.info("done duration_ms=%s", dur_ms)
        tutor.say(f"Completed successfully in {dur_ms} ms.")
        return 0
    finally:
        try:
            db.close()
        except Exception:
            pass


if __name__ == "__main__":
    raise SystemExit(main())
