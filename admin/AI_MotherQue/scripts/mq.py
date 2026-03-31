# /web/private/lib/mother_queue/mq.py
import json, os, socket, sqlite3, time, uuid
from datetime import datetime, timedelta, timezone
from typing import Any, Dict, Optional, Tuple

UTC = timezone.utc

"""
-- mother_queue.sql
PRAGMA journal_mode=WAL;
PRAGMA synchronous=NORMAL;
PRAGMA busy_timeout=5000;

CREATE TABLE IF NOT EXISTS jobs (
  id           TEXT PRIMARY KEY,
  queue        TEXT NOT NULL,
  name         TEXT NOT NULL,
  payload_json TEXT NOT NULL,

  status       TEXT NOT NULL DEFAULT 'queued',  -- queued|running|done|failed|dead
  priority     INTEGER NOT NULL DEFAULT 100,
  run_after    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),

  attempts     INTEGER NOT NULL DEFAULT 0,
  max_attempts INTEGER NOT NULL DEFAULT 5,

  locked_by    TEXT,
  locked_until TEXT,

  created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),

  last_error   TEXT
);

CREATE INDEX IF NOT EXISTS idx_jobs_pick
  ON jobs(queue, status, run_after, priority);

CREATE INDEX IF NOT EXISTS idx_jobs_locked
  ON jobs(status, locked_until);

CREATE INDEX IF NOT EXISTS idx_jobs_updated
  ON jobs(updated_at);
"""



def now_iso() -> str:
    return datetime.now(UTC).isoformat(timespec="milliseconds").replace("+00:00", "Z")

def iso_after(seconds: int) -> str:
    return (datetime.now(UTC) + timedelta(seconds=seconds)).isoformat(timespec="milliseconds").replace("+00:00", "Z")

def new_id() -> str:
    return uuid.uuid4().hex

class MotherQueue:
    def __init__(self, db_path: str):
        self.db_path = db_path
        os.makedirs(os.path.dirname(db_path), exist_ok=True)
        self._init_db()

    def _conn(self) -> sqlite3.Connection:
        con = sqlite3.connect(self.db_path, timeout=5)
        con.row_factory = sqlite3.Row
        con.execute("PRAGMA journal_mode=WAL")
        con.execute("PRAGMA synchronous=NORMAL")
        con.execute("PRAGMA busy_timeout=5000")
        return con

    def _init_db(self) -> None:
        """Create tables if they don't exist."""
        with self._conn() as con:
            con.execute("""
                CREATE TABLE IF NOT EXISTS jobs (
                  id           TEXT PRIMARY KEY,
                  queue        TEXT NOT NULL,
                  name         TEXT NOT NULL,
                  payload_json TEXT NOT NULL,

                  status       TEXT NOT NULL DEFAULT 'queued',
                  priority     INTEGER NOT NULL DEFAULT 100,
                  run_after    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),

                  attempts     INTEGER NOT NULL DEFAULT 0,
                  max_attempts INTEGER NOT NULL DEFAULT 5,

                  locked_by    TEXT,
                  locked_until TEXT,

                  created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
                  updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),

                  last_error   TEXT
                )
            """)
            con.execute("""
                CREATE INDEX IF NOT EXISTS idx_jobs_pick
                  ON jobs(queue, status, run_after, priority)
            """)
            con.execute("""
                CREATE INDEX IF NOT EXISTS idx_jobs_locked
                  ON jobs(status, locked_until)
            """)
            con.execute("""
                CREATE INDEX IF NOT EXISTS idx_jobs_updated
                  ON jobs(updated_at)
            """)
            con.commit()

    def enqueue(self, queue: str, name: str, payload: Dict[str, Any],
                priority: int = 100, run_after: Optional[str] = None,
                max_attempts: int = 5, job_id: Optional[str] = None) -> str:
        job_id = job_id or new_id()
        run_after = run_after or now_iso()
        payload_json = json.dumps(payload, ensure_ascii=False)
        ts = now_iso()

        with self._conn() as con:
            con.execute(
                """INSERT INTO jobs
                   (id, queue, name, payload_json, status, priority, run_after, max_attempts, created_at, updated_at)
                   VALUES (?, ?, ?, ?, 'queued', ?, ?, ?, ?, ?)""",
                (job_id, queue, name, payload_json, priority, run_after, max_attempts, ts, ts)
            )
        return job_id

    def lease_one(self, queue: str, lease_seconds: int = 120) -> Optional[Tuple[Dict[str, Any], Dict[str, Any]]]:
        worker = f"{socket.gethostname()}:{os.getpid()}"
        lock_until = iso_after(lease_seconds)
        ts = now_iso()

        with self._conn() as con:
            con.execute("BEGIN IMMEDIATE")

            row = con.execute(
                """SELECT * FROM jobs
                   WHERE queue = ?
                     AND status = 'queued'
                     AND run_after <= ?
                   ORDER BY priority ASC, created_at ASC
                   LIMIT 1""",
                (queue, ts)
            ).fetchone()

            if not row:
                con.execute("COMMIT")
                return None

            con.execute(
                """UPDATE jobs
                   SET status='running',
                       locked_by=?,
                       locked_until=?,
                       attempts=attempts+1,
                       updated_at=?
                   WHERE id=?""",
                (worker, lock_until, ts, row["id"])
            )
            con.execute("COMMIT")

            job = dict(row)
            payload = json.loads(job["payload_json"])
            return job, payload

    def ack(self, job_id: str) -> None:
        ts = now_iso()
        with self._conn() as con:
            con.execute(
                """UPDATE jobs
                   SET status='done', locked_by=NULL, locked_until=NULL, last_error=NULL, updated_at=?
                   WHERE id=?""",
                (ts, job_id)
            )

    def fail(self, job_id: str, error: str, retry_delay_seconds: int = 60) -> None:
        ts = now_iso()
        with self._conn() as con:
            row = con.execute("SELECT attempts, max_attempts FROM jobs WHERE id=?", (job_id,)).fetchone()
            if not row:
                return
            attempts = int(row["attempts"])
            max_attempts = int(row["max_attempts"])

            if attempts >= max_attempts:
                con.execute(
                    """UPDATE jobs
                       SET status='dead', locked_by=NULL, locked_until=NULL, last_error=?, updated_at=?
                       WHERE id=?""",
                    (error[:4000], ts, job_id)
                )
            else:
                con.execute(
                    """UPDATE jobs
                       SET status='queued',
                           locked_by=NULL,
                           locked_until=NULL,
                           last_error=?,
                           run_after=?,
                           updated_at=?
                       WHERE id=?""",
                    (error[:4000], iso_after(retry_delay_seconds), ts, job_id)
                )
