#!/usr/bin/env python3
import os, sys, time, json, sqlite3, datetime, fcntl, urllib.parse, logging
import urllib.request
from logging.handlers import RotatingFileHandler

from notes_config import get_config, get_private_root

PRIVATE_ROOT = get_private_root(__file__)
KB_DB = os.path.join(PRIVATE_ROOT, "db/memory/bash_history.db")
LOCK = os.path.join(PRIVATE_ROOT, "locks", "queue_bash_searches.lock")
LOG_PATH = os.path.join(PRIVATE_ROOT, "logs/queue_bash_searches.log")
HUMAN_DB = os.path.join(PRIVATE_ROOT, "db/memory/human_notes.db")

JOB_NAME = "queue_bash_searches"

_CFG = get_config()
SEARCH_API = _CFG.get("search.api.base", "http://192.168.0.142/v1/search/?q=")
BATCH = int(os.getenv("BASH_SEARCH_BATCH", "5"))
SLEEP_SEC = float(os.getenv("BASH_SEARCH_SLEEP", "1"))

def now():
    return datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")

_LOCK_FDS = []


def setup_logging() -> logging.Logger:
    logger = logging.getLogger("queue_bash_searches")
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


def _truncate(s: str, n: int = 800) -> str:
    s = s or ""
    if len(s) <= n:
        return s
    return s[:n] + "...<truncated>"

def lock_or_exit(path: str):
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

def ensure_schema(db: sqlite3.Connection):
    db.executescript("""
    CREATE TABLE IF NOT EXISTS command_search (
      cmd_id INTEGER PRIMARY KEY,
      status TEXT DEFAULT 'pending',
      last_at TEXT,
      last_error TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_command_search_status ON command_search(status, last_at);
    """)
    db.commit()


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
    status = "ok" if ok else "error"
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

def seed_rows(db: sqlite3.Connection):
    # ensure any AI-done + known commands are represented
    db.execute("""
      INSERT OR IGNORE INTO command_search(cmd_id, status, last_at)
      SELECT a.cmd_id, 'pending', NULL
      FROM command_ai a
      WHERE a.status='done' AND a.known=1 AND a.search_query IS NOT NULL
    """)
    db.commit()

def seed_count(db: sqlite3.Connection) -> int:
    row = db.execute(
        "SELECT COUNT(1) FROM command_ai WHERE status='done' AND known=1 AND search_query IS NOT NULL"
    ).fetchone()
    return int(row[0]) if row else 0

def fetch_pending(db: sqlite3.Connection, limit: int):
    return db.execute("""
      SELECT c.id, c.base_cmd, c.full_cmd, a.search_query
      FROM command_search s
      JOIN commands c ON c.id = s.cmd_id
      JOIN command_ai a ON a.cmd_id = c.id
      WHERE s.status IN ('pending','error')
        AND a.status='done'
        AND a.known=1
        AND a.search_query IS NOT NULL
      ORDER BY COALESCE(s.last_at,'') ASC, c.id ASC
      LIMIT ?
    """, (limit,)).fetchall()

def mark(db: sqlite3.Connection, cmd_id: int, status: str, err: str = None):
    db.execute("""
      UPDATE command_search
      SET status=?, last_at=?, last_error=?
      WHERE cmd_id=?
    """, (status, now(), err[:500] if err else None, cmd_id))
    db.commit()

def call_search(q: str) -> dict:
    url = SEARCH_API + urllib.parse.quote(q, safe="")
    req = urllib.request.Request(url, headers={"Accept": "application/json"})
    with urllib.request.urlopen(req, timeout=30) as resp:
        body = resp.read().decode("utf-8", errors="ignore")
        return json.loads(body)

def main():
    logger = setup_logging()
    started = time.time()

    lock_or_exit(LOCK)

    hb = sqlite3.connect(HUMAN_DB)
    try:
        ensure_job_runs_schema(hb)
        job_upsert_start(hb, JOB_NAME)
    except Exception:
        try:
            hb.close()
        except Exception:
            pass
        hb = None

    db = sqlite3.connect(KB_DB)
    ensure_schema(db)

    logger.info(
        "start db=%s api=%s batch=%s sleep=%s",
        KB_DB,
        SEARCH_API,
        int(BATCH),
        float(SLEEP_SEC),
    )

    try:
        eligible = seed_count(db)
    except Exception:
        eligible = -1

    seed_rows(db)

    rows = fetch_pending(db, BATCH)
    if not rows:
        logger.info("noop pending=0 eligible_ai_done_known=%s", int(eligible))
        if hb is not None:
            dur_ms = int((time.time() - started) * 1000)
            job_upsert_finish(hb, JOB_NAME, True, dur_ms, f"noop pending=0 eligible={int(eligible)}")
            hb.close()
        return

    logger.info("pending=%s eligible_ai_done_known=%s", int(len(rows)), int(eligible))

    ok = 0
    err = 0
    processed = 0

    for (cmd_id, base_cmd, full_cmd, search_query) in rows:
        processed += 1
        try:
            # optional: deprioritize noisy base commands
            # if base_cmd in ('cd', 'ls', 'clear'): continue

            out = call_search(search_query)
            if not isinstance(out, dict):
                raise RuntimeError(f"search_api_bad_response: {str(out)[:200]}")

            # Retry-later path: backend returned no results (ok=false) or no usable URLs.
            if not out.get("ok", False):
                err_code = str(out.get("error") or "")
                msg = str(out.get("message") or "")
                if err_code == "no_results":
                    mark(db, cmd_id, "pending", f"no_results: {msg}".strip())
                    logger.info(
                        "SKIP(no_results) cmd_id=%s base=%s q=%s",
                        int(cmd_id),
                        _truncate(str(base_cmd), 120),
                        _truncate(str(search_query), 300),
                    )
                    continue
                raise RuntimeError(f"search_api_bad_response: {str(out)[:200]}")

            urls = out.get("meta", {}).get("top_urls")
            if not isinstance(urls, list) or len(urls) == 0:
                mark(db, cmd_id, "pending", "no_urls")
                logger.info(
                    "SKIP(no_urls) cmd_id=%s base=%s q=%s",
                    int(cmd_id),
                    _truncate(str(base_cmd), 120),
                    _truncate(str(search_query), 300),
                )
                continue

            mark(db, cmd_id, "sent", None)
            ok += 1
            logger.info("OK cmd_id=%s base=%s q=%s", int(cmd_id), _truncate(str(base_cmd), 120), _truncate(str(search_query), 300))
            time.sleep(SLEEP_SEC)

        except Exception as e:
            mark(db, cmd_id, "error", str(e))
            err += 1
            logger.exception(
                "ERR cmd_id=%s base=%s q=%s full=%s err=%s",
                int(cmd_id),
                _truncate(str(base_cmd), 120),
                _truncate(str(search_query), 300),
                _truncate(str(full_cmd), 300),
                _truncate(str(e), 300),
            )

    dur_ms = int((time.time() - started) * 1000)
    logger.info("finish processed=%s ok=%s err=%s duration_ms=%s", int(processed), int(ok), int(err), int(dur_ms))
    if hb is not None:
        job_upsert_finish(hb, JOB_NAME, err == 0, dur_ms, f"processed={processed} ok={ok} err={err} eligible={int(eligible)}")
        hb.close()

if __name__ == "__main__":
    main()
