#!/usr/bin/env python3
"""Consolidated bash-history processing pipeline.

Default stages (in order):
1) ingest_bash_history_to_kb.py for each user in --users
2) classify_bash_commands.py
3) queue_bash_searches.py
4) ai_search_summ.py
5) ai_notes.py

This allows a single cron entry while preserving individual scripts for manual debugging.
"""

from __future__ import annotations

import argparse
import datetime
import fcntl
import os
import sqlite3
import subprocess
import sys
import time
from typing import List, Tuple

from notes_config import get_private_root

PRIVATE_ROOT = get_private_root(__file__)
HUMAN_DB = os.path.join(PRIVATE_ROOT, "db/memory/human_notes.db")
LOCK_PATH = os.path.join(PRIVATE_ROOT, "locks", "process_bash_history.lock")
JOB_NAME = "process_bash_history"


def now() -> str:
    return datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def ensure_job_runs_schema(db: sqlite3.Connection) -> None:
    db.execute(
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
    db.commit()


def job_upsert_start(db: sqlite3.Connection, job: str, message: str) -> None:
    db.execute(
        """
        INSERT INTO job_runs(job, last_start, last_status, last_message, last_duration_ms)
        VALUES(?, ?, 'running', ?, NULL)
        ON CONFLICT(job) DO UPDATE SET
          last_start=excluded.last_start,
          last_status='running',
          last_message=excluded.last_message,
          last_duration_ms=NULL;
        """,
        (job, now(), (message or "")[:900]),
    )
    db.commit()


def job_upsert_finish(db: sqlite3.Connection, job: str, ok: bool, duration_ms: int, message: str) -> None:
    status = "ok" if ok else "error"
    msg = (message or "")[:900]
    if ok:
        db.execute(
            """
            INSERT INTO job_runs(job, last_ok, last_status, last_message, last_duration_ms)
            VALUES(?, ?, 'ok', ?, ?)
            ON CONFLICT(job) DO UPDATE SET
              last_ok=excluded.last_ok,
              last_status='ok',
              last_message=excluded.last_message,
              last_duration_ms=excluded.last_duration_ms;
            """,
            (job, now(), msg, int(duration_ms)),
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


def lock_or_exit(path: str) -> None:
    parent = os.path.dirname(path)
    if parent:
        os.makedirs(parent, exist_ok=True)

    fd = os.open(path, os.O_RDWR | os.O_CREAT, 0o644)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        print("[process_bash_history] lock busy; exiting", file=sys.stderr)
        raise SystemExit(0)


def parse_args(argv: List[str]) -> argparse.Namespace:
    ap = argparse.ArgumentParser(description="Run the full bash-history processing pipeline")
    ap.add_argument(
        "--users",
        default="samekhi,root",
        help="Comma-separated users for ingest stage (default: samekhi,root)",
    )
    ap.add_argument(
        "--skip-ai-notes",
        action="store_true",
        help="Skip ai_notes.py stage",
    )
    ap.add_argument(
        "--skip-ai-search-summ",
        action="store_true",
        help="Skip ai_search_summ.py stage",
    )
    ap.add_argument(
        "--dry-run",
        action="store_true",
        help="Print planned commands only",
    )
    ap.add_argument(
        "--keep-going",
        action="store_true",
        help="Continue remaining stages even if one fails",
    )
    return ap.parse_args(argv)


def _script_path(name: str) -> str:
    return os.path.join(os.path.dirname(__file__), name)


def build_plan(args: argparse.Namespace) -> List[Tuple[str, List[str]]]:
    users = [u.strip() for u in str(args.users or "").split(",") if u.strip()]
    if not users:
        users = ["samekhi", "root"]

    plan: List[Tuple[str, List[str]]] = []
    for u in users:
        plan.append(("ingest:" + u, [sys.executable, _script_path("ingest_bash_history_to_kb.py"), u]))

    plan.append(("classify", [sys.executable, _script_path("classify_bash_commands.py")]))
    plan.append(("queue_search", [sys.executable, _script_path("queue_bash_searches.py")]))

    if not args.skip_ai_search_summ:
        plan.append(("ai_search_summ", [sys.executable, _script_path("ai_search_summ.py")]))

    if not args.skip_ai_notes:
        plan.append(("ai_notes", [sys.executable, _script_path("ai_notes.py")]))

    return plan


def run_stage(stage_name: str, cmd: List[str], dry_run: bool) -> int:
    print("[process_bash_history] stage=%s cmd=%s" % (stage_name, " ".join(cmd)), file=sys.stderr)
    if dry_run:
        return 0

    completed = subprocess.run(cmd)
    return int(completed.returncode)


def main() -> int:
    args = parse_args(sys.argv[1:])
    lock_or_exit(LOCK_PATH)

    os.makedirs(os.path.dirname(HUMAN_DB), exist_ok=True)
    hb = sqlite3.connect(HUMAN_DB)
    ensure_job_runs_schema(hb)

    plan = build_plan(args)
    start = time.time()
    job_upsert_start(hb, JOB_NAME, "stages=%s dry_run=%s" % (len(plan), int(bool(args.dry_run))))

    failed = []

    try:
        for stage_name, cmd in plan:
            rc = run_stage(stage_name, cmd, bool(args.dry_run))
            if rc != 0:
                failed.append("%s:rc=%s" % (stage_name, rc))
                if not args.keep_going:
                    break

        duration_ms = int((time.time() - start) * 1000)
        if failed:
            message = "failed=%s" % (", ".join(failed))
            job_upsert_finish(hb, JOB_NAME, False, duration_ms, message)
            print("[process_bash_history] ERROR %s" % message, file=sys.stderr)
            return 1

        message = "ok stages=%s" % (len(plan),)
        job_upsert_finish(hb, JOB_NAME, True, duration_ms, message)
        print("[process_bash_history] DONE %s" % message, file=sys.stderr)
        return 0
    finally:
        try:
            hb.close()
        except Exception:
            pass


if __name__ == "__main__":
    raise SystemExit(main())
