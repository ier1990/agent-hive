#!/usr/bin/env python3
# /web/html/admin/cron.daily/save_bash_history_threaded.py
"""
Save bash history into human_notes.db as "threaded" notes:
- One parent note per day per host+user
- Child note per run with only newly appended commands
Robust: uses parameterized SQL, correct lastrowid, locking, and inode tracking.


Cron usage

Replace your cron line with:

1 * * * * /usr/bin/python3 /web/html/admin/cron.daily/save_bash_history_threaded.py samekhi


…and similarly for root if you want.

"""

import os
import sys
import socket
import sqlite3
import datetime
import pathlib
import fcntl
from typing import Optional, Tuple


DB_PATH = "/web/private/db/memory/human_notes.db"
TOPIC = "bash_history"
NOTES_TYPE = "logs"


def usage() -> None:
    print("Usage: save_bash_history_threaded.py <username>")
    sys.exit(1)


def lock_or_exit(lock_path: str) -> None:
    # Prevent concurrent runs (cron overlap)
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


def get_inode(path: str) -> str:
    try:
        return str(os.stat(path).st_ino)
    except Exception:
        return ""


def read_lines(path: str) -> list:
    with open(path, "r", errors="ignore") as f:
        return f.read().splitlines()


def ensure_schema(db: sqlite3.Connection) -> None:
    db.executescript(
        """
        CREATE TABLE IF NOT EXISTS notes (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          notes_type TEXT NOT NULL,
          topic TEXT NOT NULL,
          note TEXT NOT NULL,
          parent_id INTEGER DEFAULT 0,
          created_at TEXT,
          updated_at TEXT
        );

        CREATE TABLE IF NOT EXISTS history_state (
          host TEXT NOT NULL,
          path TEXT NOT NULL,
          inode TEXT,
          last_line INTEGER DEFAULT 0,
          updated_at TEXT,
          PRIMARY KEY (host, path)
        );

        CREATE INDEX IF NOT EXISTS idx_notes_parent
          ON notes(parent_id);

        CREATE INDEX IF NOT EXISTS idx_notes_type_topic_created
          ON notes(notes_type, topic, created_at);
        """
    )
    db.commit()


def load_state(db: sqlite3.Connection, host: str, path: str) -> Tuple[str, int]:
    row = db.execute(
        "SELECT COALESCE(inode,''), COALESCE(last_line,0) FROM history_state WHERE host=? AND path=? LIMIT 1",
        (host, path),
    ).fetchone()
    if not row:
        return ("", 0)
    return (row[0] or "", int(row[1] or 0))


def save_state(db: sqlite3.Connection, host: str, path: str, inode: str, last_line: int) -> None:
    db.execute(
        """
        INSERT INTO history_state(host, path, inode, last_line, updated_at)
        VALUES(?,?,?,?,?)
        ON CONFLICT(host, path) DO UPDATE SET
          inode=excluded.inode,
          last_line=excluded.last_line,
          updated_at=excluded.updated_at
        """,
        (host, path, inode, int(last_line), now_sqlite()),
    )
    db.commit()


def find_or_create_parent(
    db: sqlite3.Connection, host: str, user: str, parent_title: str, parent_note: str
) -> int:
    # Find latest parent note for today+host+user
    row = db.execute(
        """
        SELECT id FROM notes
        WHERE parent_id=0
          AND notes_type=?
          AND topic=?
          AND note LIKE ?
          AND note LIKE ?
          AND note LIKE ?
        ORDER BY id DESC
        LIMIT 1
        """,
        (
            NOTES_TYPE,
            TOPIC,
            f"%{parent_title}%",
            f"%Host:** {host}%",
            f"%User:** {user}%",
        ),
    ).fetchone()

    if row:
        return int(row[0])

    cur = db.execute(
        """
        INSERT INTO notes (notes_type, topic, note, parent_id, created_at, updated_at)
        VALUES (?,?,?,?,?,?)
        """,
        (NOTES_TYPE, TOPIC, parent_note, 0, now_sqlite(), now_sqlite()),
    )
    db.commit()
    return int(cur.lastrowid)


def insert_child(db: sqlite3.Connection, parent_id: int, child_note: str) -> None:
    db.execute(
        """
        INSERT INTO notes (notes_type, topic, note, parent_id, created_at, updated_at)
        VALUES (?,?,?,?,?,?)
        """,
        (NOTES_TYPE, TOPIC, child_note, int(parent_id), now_sqlite(), now_sqlite()),
    )
    db.commit()


def main() -> None:
    user = sys.argv[1] if len(sys.argv) > 1 else ""
    if not user:
        usage()

    lock_or_exit(f"/tmp/save_bash_history_{user}.lock")

    host = socket.gethostname()
    today = today_str()

    if user == "root":
        hist_file = "/root/.bash_history"
    else:
        hist_file = f"/home/{user}/.bash_history"

    if not os.path.isfile(hist_file):
        sys.exit(0)

    lines = read_lines(hist_file)
    line_count = len(lines)
    inode = get_inode(hist_file)

    db = sqlite3.connect(DB_PATH)
    ensure_schema(db)

    old_inode, last_line = load_state(db, host, hist_file)

    if old_inode and old_inode == inode and line_count >= last_line:
        start_line = last_line + 1
    else:
        start_line = 1  # rotated/cleared/new inode

    if start_line > line_count:
        save_state(db, host, hist_file, inode, line_count)
        sys.exit(0)

    new_lines = lines[start_line - 1 :]  # 1-based to 0-based
    # Ignore if all whitespace
    if not any(s.strip() for s in new_lines):
        save_state(db, host, hist_file, inode, line_count)
        sys.exit(0)

    parent_title = f"Bash History — {today}"
    parent_note = (
        f"### {parent_title}\n"
        f"**Host:** {host}  \n"
        f"**User:** {user}  \n"
    )

    parent_id = find_or_create_parent(db, host, user, parent_title, parent_note)

    # Keep your original markdown format
    child_note = (
        f"**New commands appended:** lines {start_line}–{line_count}  \n"
        f"```bash\n" + "\n".join(new_lines) + "\n```\n"
    )

    insert_child(db, parent_id, child_note)
    save_state(db, host, hist_file, inode, line_count)


if __name__ == "__main__":
    main()
