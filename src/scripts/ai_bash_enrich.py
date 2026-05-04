#!/usr/bin/env python3
"""Merged local AI enrichment stage for bash history.

This stage combines:
- command classification into bash_history.db.command_ai
- AI metadata generation for bash-history note rows into notes_ai_metadata.db.ai_note_meta

Write boundaries:
- reads bash-history note rows from human_notes.db
- writes heartbeat rows to human_notes.db.job_runs
- writes command classification to bash_history.db.command_ai
- writes AI note metadata only to notes_ai_metadata.db.ai_note_meta
"""

from __future__ import annotations

import argparse
import logging
from logging.handlers import RotatingFileHandler
import json
import fcntl
import os
import sqlite3
import sys
import time

import ai_notes as notes_stage
import classify_bash_commands as classify_stage
from bash_helper import ai_metadata_db_path, assert_ai_db_path, bash_kb_db_path, human_db_path
from notes_config import get_private_root

PRIVATE_ROOT = get_private_root(__file__)
HUMAN_DB_DEFAULT = human_db_path()
AI_DB_DEFAULT = ai_metadata_db_path()
KB_DB_DEFAULT = bash_kb_db_path()
LOCK_PATH = os.path.join(PRIVATE_ROOT, "locks", "ai_bash_enrich.lock")
LOG_PATH = os.path.join(PRIVATE_ROOT, "logs", "ai_bash_enrich.log")
JOB_NAME = "ai_bash_enrich"
_LOCK_FDS = []


def ensure_job_runs_schema(conn):
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


def job_upsert_start(conn, job):
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


def job_upsert_finish(conn, job, ok, duration_ms, message):
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


def setup_logging():
    logger = logging.getLogger("ai_bash_enrich")
    if logger.handlers:
        return logger

    logger.setLevel(logging.INFO)
    logger.propagate = False

    try:
        os.makedirs(os.path.dirname(LOG_PATH), exist_ok=True)
    except Exception:
        pass

    formatter = logging.Formatter(
        fmt="%(asctime)s %(levelname)s pid=%(process)d %(message)s",
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


def lock_or_exit(path):
    try:
        os.makedirs(os.path.dirname(path), exist_ok=True)
    except Exception:
        pass
    fd = os.open(path, os.O_RDWR | os.O_CREAT, 0o644)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        sys.exit(0)
    _LOCK_FDS.append(fd)


def parse_args(argv):
    ap = argparse.ArgumentParser(description="Run merged local AI enrichment for bash history")
    ap.add_argument("--human-db", default=HUMAN_DB_DEFAULT)
    ap.add_argument("--ai-db", default=AI_DB_DEFAULT)
    ap.add_argument("--kb-db", default=KB_DB_DEFAULT)
    ap.add_argument("--ollama-url", default=notes_stage.OLLAMA_URL_DEFAULT)
    ap.add_argument("--model", default=notes_stage.MODEL_DEFAULT)
    ap.add_argument("--classify-batch", type=int, default=classify_stage.BATCH)
    ap.add_argument("--notes-limit", type=int, default=500)
    ap.add_argument("--timeout", type=int, default=180)
    ap.add_argument("--sleep", type=float, default=0.0)
    ap.add_argument("--since-id", type=int, default=0)
    ap.add_argument("--backtrack", type=int, default=200)
    ap.add_argument("--source-notes-type", default="logs")
    ap.add_argument("--source-topic", default="bash_history")
    ap.add_argument("--skip-classify", action="store_true")
    ap.add_argument("--skip-notes", action="store_true")
    ap.add_argument("--dry-run", action="store_true")
    return ap.parse_args(argv)


def run_classify(args, logger):
    if args.skip_classify:
        return {"processed": 0, "done": 0, "errors": 0, "skipped": True}

    os.environ["OLLAMA_URL"] = str(args.ollama_url)
    classify_stage.MODEL = str(args.model)

    db = sqlite3.connect(args.kb_db)
    try:
        classify_stage.ensure_schema(db)
        pending = classify_stage.fetch_pending(db, int(args.classify_batch))
        if not pending:
            logger.info("classify noop pending=0")
            return {"processed": 0, "done": 0, "errors": 0, "skipped": False}

        processed = 0
        done = 0
        errors = 0

        for cmd_id, full_cmd, base_cmd in pending:
            processed += 1
            try:
                if args.dry_run:
                    done += 1
                    continue
                classify_stage.mark_working(db, cmd_id)
                raw = classify_stage.local_ai_classify(full_cmd, base_cmd)
                payload = classify_stage.validate_payload(full_cmd, base_cmd, raw)
                classify_stage.mark_done(db, cmd_id, payload)
                done += 1
            except Exception as e:
                errors += 1
                err_text = str(e)
                if isinstance(e, json.JSONDecodeError):
                    err_text = "json_decode_error: %s" % str(e)
                classify_stage.mark_error(db, cmd_id, err_text)
                logger.exception("classify error cmd_id=%s base_cmd=%s err=%s", int(cmd_id), base_cmd, err_text)

        logger.info("classify finish processed=%s done=%s errors=%s", int(processed), int(done), int(errors))
        return {"processed": processed, "done": done, "errors": errors, "skipped": False}
    finally:
        try:
            db.close()
        except Exception:
            pass


def run_notes(args, logger):
    if args.skip_notes:
        return {"processed": 0, "would_process": 0, "skipped": 0, "failed": 0, "scanned": 0, "skipped_stage": True}

    try:
        os.makedirs(os.path.dirname(args.ai_db), exist_ok=True)
    except Exception:
        pass

    ai_conn = sqlite3.connect(args.ai_db)
    notes_stage.ensure_ai_schema(ai_conn)
    try:
        last_processed = notes_stage.get_last_processed_note_id(ai_conn)
        backtrack = max(0, int(args.backtrack))
        start_from = args.since_id if args.since_id > 0 else max(0, last_processed - backtrack)
        notes = notes_stage.load_notes(
            args.human_db,
            limit=int(args.notes_limit),
            since_id=start_from,
            notes_type=str(args.source_notes_type or "").strip(),
            topic=str(args.source_topic or "").strip(),
        )

        processed = 0
        skipped = 0
        failed = 0
        would_process = 0

        for note in notes:
            material = "%s\n%s\n%s\n%s" % (note.notes_type, note.topic, note.updated_at, note.note)
            source_hash = notes_stage.sha256_hex(material)
            if notes_stage.already_done(ai_conn, note.id, source_hash):
                skipped += 1
                continue

            try:
                if args.dry_run:
                    would_process += 1
                else:
                    meta = notes_stage.call_ollama_metadata(args.ollama_url, args.model, note, timeout_s=int(args.timeout))
                    notes_stage.upsert_meta(ai_conn, note, source_hash, args.model, meta)
                    processed += 1
                if float(args.sleep) > 0:
                    time.sleep(float(args.sleep))
            except Exception as e:
                failed += 1
                logger.exception("notes error note_id=%s err=%s", int(note.id), str(e))

        logger.info(
            "notes finish processed=%s would_process=%s skipped=%s failed=%s scanned=%s",
            int(processed),
            int(would_process),
            int(skipped),
            int(failed),
            int(len(notes)),
        )
        return {
            "processed": processed,
            "would_process": would_process,
            "skipped": skipped,
            "failed": failed,
            "scanned": len(notes),
            "skipped_stage": False,
        }
    finally:
        try:
            ai_conn.close()
        except Exception:
            pass


def main():
    args = parse_args(sys.argv[1:])
    logger = setup_logging()
    lock_or_exit(LOCK_PATH)
    assert_ai_db_path(args.ai_db, args.human_db)

    os.makedirs(os.path.dirname(args.human_db), exist_ok=True)
    hb = sqlite3.connect(args.human_db)
    ensure_job_runs_schema(hb)
    job_upsert_start(hb, JOB_NAME)
    started = time.time()

    try:
        classify_stats = run_classify(args, logger)
        notes_stats = run_notes(args, logger)
        duration_ms = int((time.time() - started) * 1000)
        ok = int(classify_stats.get("errors", 0)) == 0 and int(notes_stats.get("failed", 0)) == 0
        msg = (
            "classify_processed=%s classify_done=%s classify_errors=%s "
            "notes_processed=%s notes_skipped=%s notes_failed=%s notes_scanned=%s"
        ) % (
            int(classify_stats.get("processed", 0)),
            int(classify_stats.get("done", 0)),
            int(classify_stats.get("errors", 0)),
            int(notes_stats.get("processed", 0)),
            int(notes_stats.get("skipped", 0)),
            int(notes_stats.get("failed", 0)),
            int(notes_stats.get("scanned", 0)),
        )
        job_upsert_finish(hb, JOB_NAME, ok, duration_ms, msg)
        logger.info("finish %s", msg)
        return 0 if ok else 1
    except Exception as e:
        duration_ms = int((time.time() - started) * 1000)
        try:
            job_upsert_finish(hb, JOB_NAME, False, duration_ms, "fatal: %s" % str(e))
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
