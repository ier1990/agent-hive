#!/usr/bin/env python3
# /web/html/admin/cron.hourly/ingest_bash_history_to_kb.py

"""Ingest bash history into a knowledge base.

Core behavior:
- Reads ~/.bash_history for a target username
- Tracks inode + last line read in Notes DB table history_state
- Upserts commands into bash_history.db and queues enrich_queue items
- Writes a cron heartbeat row into Notes DB table job_runs (running/ok/error + duration)

Cron example:
5 * * * * /usr/bin/python3 /web/html/admin/cron.hourly/ingest_bash_history_to_kb.py samekhi >> ${PRIVATE_ROOT:-/web/private}/logs/ingest_bash_history_to_kb.log 2>&1
7 * * * * /usr/bin/python3 /web/html/admin/cron.hourly/ingest_bash_history_to_kb.py root  >> ${PRIVATE_ROOT:-/web/private}/logs/ingest_bash_history_to_kb.log 2>&1

Jobs UI:
http://<host>/admin/notes/?view=jobs
"""

import argparse
import datetime
import fcntl
import logging
import os
import re
import socket
import sqlite3
import sys
import time
from logging.handlers import RotatingFileHandler
from typing import Tuple


def _import_domain_memory_bootstrap():
    """Import /lib/bootstrap.py without assuming cwd or PYTHONPATH."""
    from pathlib import Path
    import importlib.util

    here = Path(__file__).resolve()
    for parent in [here.parent] + list(here.parents):
        cand = parent / "lib" / "bootstrap.py"
        if cand.is_file():
            spec = importlib.util.spec_from_file_location("domain_memory_bootstrap", str(cand))
            if spec and spec.loader:
                mod = importlib.util.module_from_spec(spec)
                spec.loader.exec_module(mod)
                return mod

    for parent in [here.parent] + list(here.parents):
        if (parent / "lib" / "bootstrap.php").is_file():
            cand = parent / "lib" / "bootstrap.py"
            if cand.is_file():
                spec = importlib.util.spec_from_file_location("domain_memory_bootstrap", str(cand))
                if spec and spec.loader:
                    mod = importlib.util.module_from_spec(spec)
                    spec.loader.exec_module(mod)
                    return mod

    raise RuntimeError("Cannot locate Domain Memory bootstrap.py (expected lib/bootstrap.py in a parent directory)")


_BOOT = _import_domain_memory_bootstrap()
PATHS = _BOOT.get_paths(__file__)

PRIVATE_ROOT = (PATHS.get("PRIVATE_ROOT") if isinstance(PATHS, dict) else None) or os.getenv("PRIVATE_ROOT") or "/web/private"
KB_DB = os.path.join(PRIVATE_ROOT, "db/memory/bash_history.db")
STATE_DB = os.path.join(PRIVATE_ROOT, "db/memory/human_notes.db")
TOPIC_STATE_HOST = socket.gethostname()
LOG_PATH = os.path.join(PRIVATE_ROOT, "logs/ingest_bash_history_to_kb.log")

_LOCK_FDS = []


def setup_logging() -> logging.Logger:
    logger = logging.getLogger("ingest_bash_history_to_kb")
    if logger.handlers:
        return logger

    logger.setLevel(logging.INFO)
    logger.propagate = False

    try:
        os.makedirs(os.path.dirname(LOG_PATH), exist_ok=True)
    except Exception:
        pass

    formatter = logging.Formatter(
        fmt="%(asctime)s %(levelname)s host=%(host)s pid=%(process)d %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )

    try:
        fh = RotatingFileHandler(LOG_PATH, maxBytes=2 * 1024 * 1024, backupCount=5)
        fh.setFormatter(formatter)
        logger.addHandler(fh)
    except Exception:
        sh = logging.StreamHandler(sys.stderr)
        sh.setFormatter(formatter)
        logger.addHandler(sh)

    if sys.stdout.isatty():
        sh = logging.StreamHandler(sys.stdout)
        sh.setFormatter(formatter)
        logger.addHandler(sh)

    return logger


class _HostAdapter(logging.LoggerAdapter):
    def process(self, msg, kwargs):
        extra = kwargs.get("extra") or {}
        extra.setdefault("host", TOPIC_STATE_HOST)
        kwargs["extra"] = extra
        return msg, kwargs


def now() -> str:
    return datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def lock_or_exit(path: str, logger: logging.LoggerAdapter) -> bool:
    try:
        parent = os.path.dirname(path)
        if parent:
            os.makedirs(parent, exist_ok=True)
    except Exception:
        pass

    fd = os.open(path, os.O_RDWR | os.O_CREAT, 0o644)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        logger.info("lock_busy lock=%s", path)
        try:
            os.close(fd)
        except Exception:
            pass
        return False

    _LOCK_FDS.append(fd)
    return True


def ensure_kb_schema(db: sqlite3.Connection) -> None:
    db.executescript(
        """
        CREATE TABLE IF NOT EXISTS commands (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          full_cmd TEXT NOT NULL UNIQUE,
          base_cmd TEXT NOT NULL,
          first_seen TEXT DEFAULT (datetime('now')),
          last_seen  TEXT DEFAULT (datetime('now')),
          seen_count INTEGER DEFAULT 1
        );
        CREATE INDEX IF NOT EXISTS idx_commands_base_cmd ON commands(base_cmd);

        CREATE TABLE IF NOT EXISTS command_ai (
          cmd_id INTEGER PRIMARY KEY,
          status TEXT DEFAULT 'pending',
          model TEXT,
          prompt_version TEXT,
          result_json TEXT,
          summary TEXT,
          search_query TEXT,
          known INTEGER DEFAULT 0,
          updated_at TEXT DEFAULT (datetime('now')),
          last_error TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_command_ai_status ON command_ai(status, updated_at);

        CREATE TABLE IF NOT EXISTS base_commands (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          base_cmd TEXT NOT NULL UNIQUE,
          first_seen TEXT DEFAULT (datetime('now')),
          last_seen  TEXT DEFAULT (datetime('now')),
          seen_count INTEGER DEFAULT 1
        );

        CREATE TABLE IF NOT EXISTS enrich_queue (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          kind TEXT NOT NULL,
          ref TEXT NOT NULL,
          status TEXT DEFAULT 'pending',
          priority INTEGER DEFAULT 100,
          attempts INTEGER DEFAULT 0,
          last_error TEXT,
          created_at TEXT DEFAULT (datetime('now')),
          updated_at TEXT DEFAULT (datetime('now')),
          UNIQUE(kind, ref)
        );
        CREATE INDEX IF NOT EXISTS idx_queue_status_priority ON enrich_queue(status, priority, created_at);
        """
    )
    db.commit()


def ensure_state_schema(db: sqlite3.Connection) -> None:
    db.executescript(
        """
        CREATE TABLE IF NOT EXISTS history_state (
          host TEXT NOT NULL,
          path TEXT NOT NULL,
          inode TEXT,
          last_line INTEGER DEFAULT 0,
          updated_at TEXT,
          PRIMARY KEY (host, path)
        );

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
    db.commit()


def _job_upsert_start(db: sqlite3.Connection, job: str, message: str = "") -> None:
    db.execute(
        """
        INSERT INTO job_runs(job, last_start, last_status, last_message, last_duration_ms)
        VALUES(?, ?, 'running', ?, NULL)
        ON CONFLICT(job) DO UPDATE SET
          last_start=excluded.last_start,
          last_status='running',
          last_message=excluded.last_message,
          last_duration_ms=NULL
        """,
        (job, now(), message or ""),
    )
    db.commit()


def _job_upsert_finish(db: sqlite3.Connection, job: str, status: str, duration_ms: int, message: str = "") -> None:
    status = status if status in ("ok", "error") else "error"
    if status == "ok":
        db.execute(
            """
            INSERT INTO job_runs(job, last_ok, last_status, last_message, last_duration_ms)
            VALUES(?, ?, 'ok', ?, ?)
            ON CONFLICT(job) DO UPDATE SET
              last_ok=excluded.last_ok,
              last_status='ok',
              last_message=excluded.last_message,
              last_duration_ms=excluded.last_duration_ms
            """,
            (job, now(), message or "", int(duration_ms)),
        )
    else:
        db.execute(
            """
            INSERT INTO job_runs(job, last_status, last_message, last_duration_ms)
            VALUES(?, 'error', ?, ?)
            ON CONFLICT(job) DO UPDATE SET
              last_status='error',
              last_message=excluded.last_message,
              last_duration_ms=excluded.last_duration_ms
            """,
            (job, message or "", int(duration_ms)),
        )
    db.commit()


def inode_of(path: str) -> str:
    try:
        return str(os.stat(path).st_ino)
    except Exception:
        return ""


def load_state(db: sqlite3.Connection, host: str, path: str) -> Tuple[str, int]:
    row = db.execute(
        "SELECT COALESCE(inode,''), COALESCE(last_line,0) FROM history_state WHERE host=? AND path=? LIMIT 1",
        (host, path),
    ).fetchone()
    return (row[0], int(row[1])) if row else ("", 0)


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
        (host, path, inode, int(last_line), now()),
    )
    db.commit()


_env_assign_re = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*=.*$")


def base_command(full_cmd: str) -> str:
    s = full_cmd.strip()
    if not s or s.startswith("#"):
        return ""

    seg = re.split(r"\s*(?:&&|;)\s*", s, maxsplit=1)[0].strip()
    if not seg:
        return ""

    toks = seg.split()
    if not toks:
        return ""

    i = 0
    while i < len(toks) and _env_assign_re.match(toks[i]):
        i += 1
    if i >= len(toks):
        return ""

    if toks[i] == "sudo" and i + 1 < len(toks):
        i += 1

    return toks[i]


def upsert_command(kb: sqlite3.Connection, full_cmd: str, base_cmd: str) -> int:
    kb.execute(
        """
        INSERT INTO commands(full_cmd, base_cmd, first_seen, last_seen, seen_count)
        VALUES(?,?,?,?,1)
        ON CONFLICT(full_cmd) DO UPDATE SET
          last_seen=excluded.last_seen,
          seen_count=commands.seen_count+1
        """,
        (full_cmd, base_cmd, now(), now()),
    )

    row = kb.execute("SELECT id FROM commands WHERE full_cmd=? LIMIT 1", (full_cmd,)).fetchone()
    cmd_id = int(row[0]) if row else 0
    if cmd_id:
        kb.execute(
            "INSERT OR IGNORE INTO command_ai(cmd_id, status, updated_at) VALUES(?, 'pending', datetime('now'))",
            (cmd_id,),
        )

    kb.execute(
        """
        INSERT INTO base_commands(base_cmd, first_seen, last_seen, seen_count)
        VALUES(?,?,?,1)
        ON CONFLICT(base_cmd) DO UPDATE SET
          last_seen=excluded.last_seen,
          seen_count=base_commands.seen_count+1
        """,
        (base_cmd, now(), now()),
    )

    return cmd_id


def queue_enrich(kb: sqlite3.Connection, kind: str, ref: str, priority: int = 100) -> bool:
    cur = kb.execute(
        """
        INSERT INTO enrich_queue(kind, ref, status, priority, attempts, created_at, updated_at)
        VALUES(?,?, 'pending', ?, 0, datetime('now'), datetime('now'))
        ON CONFLICT(kind, ref) DO NOTHING
        """,
        (kind, ref, int(priority)),
    )
    return cur.rowcount == 1


def parse_args(argv) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Ingest bash history into a sqlite knowledge base")
    parser.add_argument("username", help="Linux username (e.g. samekhi, root)")
    parser.add_argument(
        "--import",
        dest="import_mode",
        choices=["new", "all"],
        default="new",
        help="Import mode: 'new' uses saved state (default); 'all' re-imports full history",
    )
    parser.add_argument("--all", action="store_true", help="Alias for --import all")
    return parser.parse_args(argv)


def main() -> int:
    args = parse_args(sys.argv[1:])
    if getattr(args, "all", False):
        args.import_mode = "all"

    base_logger = setup_logging()
    logger = _HostAdapter(base_logger, {})

    user = args.username
    job_name = f"ingest_bash_history_to_kb:{user}"
    t0 = time.time()

    logger.info("start user=%s import_mode=%s", user, args.import_mode)

    state_db = None
    kb = None
    try:
        state_db = sqlite3.connect(STATE_DB)
        ensure_state_schema(state_db)
        _job_upsert_start(state_db, job_name, f"host={TOPIC_STATE_HOST} import_mode={args.import_mode}")

        lock_path = os.path.join(PRIVATE_ROOT, "locks", f"ingest_bash_kb_{user}.lock")
        if not lock_or_exit(lock_path, logger):
            _job_upsert_finish(state_db, job_name, "ok", int((time.time() - t0) * 1000), "lock_busy")
            return 0

        hist = "/root/.bash_history" if user == "root" else f"/home/{user}/.bash_history"
        if not os.path.isfile(hist):
            msg = f"no_history_file path={hist}"
            logger.info(msg)
            _job_upsert_finish(state_db, job_name, "ok", int((time.time() - t0) * 1000), msg)
            return 0

        with open(hist, "r", errors="ignore") as f:
            lines = f.read().splitlines()

        inode = inode_of(hist)
        line_count = len(lines)
        old_inode, last_line = load_state(state_db, TOPIC_STATE_HOST, hist)

        if args.import_mode == "all":
            start_line = 1
        else:
            start_line = last_line + 1 if (old_inode and old_inode == inode and line_count >= last_line) else 1

        logger.info(
            "state host=%s path=%s inode=%s old_inode=%s last_line=%s start_line=%s total_lines=%s",
            TOPIC_STATE_HOST,
            hist,
            inode,
            old_inode,
            int(last_line),
            int(start_line),
            int(line_count),
        )

        if start_line > line_count:
            save_state(state_db, TOPIC_STATE_HOST, hist, inode, line_count)
            msg = "noop start_line_past_eof"
            logger.info(msg)
            _job_upsert_finish(state_db, job_name, "ok", int((time.time() - t0) * 1000), msg)
            return 0

        new_lines = lines[start_line - 1 :]
        new_lines = [s for s in new_lines if s.strip() and not s.strip().startswith("#")]
        if not new_lines:
            save_state(state_db, TOPIC_STATE_HOST, hist, inode, line_count)
            msg = "noop no_new_lines"
            logger.info(msg)
            _job_upsert_finish(state_db, job_name, "ok", int((time.time() - t0) * 1000), msg)
            return 0

        kb = sqlite3.connect(KB_DB)
        ensure_kb_schema(kb)

        processed_lines = 0
        parsed_commands = 0
        queued_enrich = 0

        for full in new_lines:
            processed_lines += 1
            b = base_command(full)
            if not b:
                continue
            parsed_commands += 1
            upsert_command(kb, full, b)
            if queue_enrich(kb, "base", b, priority=50):
                queued_enrich += 1

        kb.commit()
        save_state(state_db, TOPIC_STATE_HOST, hist, inode, line_count)

        msg = f"done processed_lines={int(processed_lines)} parsed_commands={int(parsed_commands)} queued_enrich={int(queued_enrich)}"
        logger.info(
            "done user=%s processed_lines=%s parsed_commands=%s queued_enrich=%s",
            user,
            int(processed_lines),
            int(parsed_commands),
            int(queued_enrich),
        )
        _job_upsert_finish(state_db, job_name, "ok", int((time.time() - t0) * 1000), msg)
        return 0

    except Exception as e:
        logger.exception("ingest_failed")
        if state_db is not None:
            try:
                _job_upsert_finish(state_db, job_name, "error", int((time.time() - t0) * 1000), str(e))
            except Exception:
                pass
        return 1

    finally:
        if kb is not None:
            try:
                kb.close()
            except Exception:
                pass
        if state_db is not None:
            try:
                state_db.close()
            except Exception:
                pass


if __name__ == "__main__":
    raise SystemExit(main())
