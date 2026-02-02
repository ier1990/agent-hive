#!/usr/bin/env python3
# /web/html/admin/cron.hourly/classify_bash_commands.py



"""
Classify bash commands in the knowledge base using a local AI model.
Updates the command_ai table with AI-generated metadata.

Schema changes required in bash_history.db:
/web/html/admin/cron.hourly/ingest_bash_history_to_kb.py
AI example:
/web/html/admin/scripts/ai_notes.py
/web/html/admin/cron.hourly/ai_notes_summary.sh


Cron it hourly
15 * * * * /usr/bin/python3 /web/html/admin/cron.hourly/classify_bash_commands.py

human:
search is http://192.168.0.142/v1/search?q= 
saved in db, hourly ai summaries into notes.db
hourly cron creates ai metadata from new notes.db
/web/html/admin/cron.hourly/ingest_bash_history_to_kb.py is creating a unique per command to be futher processed. from the looks they need more refinement before search, lets send each command to local ai & get back a json of the word/full commnd , summary OR unknown 

AI:
Cool — the missing piece is a local-AI “classifier/normalizer” step between ingest → search. That’s exactly how you keep /v1/search from getting spammed with junk.

What we’ll do

ingest_bash_history_to_kb.py keeps inserting unique full_cmd + base_cmd.

A new hourly worker reads new/unprocessed commands and sends them to your local AI.

AI returns a strict JSON object:

base_cmd (normalized)

intent (what this command does)

keywords (for search)

known true/false

search_query (or null if unknown)

We store the AI result in bash_history.db and only then queue /v1/search.

Below is a robust schema + worker script that fits your pipeline.

1) Extend bash_history.db schema for AI classification

Add two tables:

CREATE TABLE IF NOT EXISTS command_ai (
  cmd_id INTEGER PRIMARY KEY,          -- FK-ish to commands.id (1:1)
  status TEXT DEFAULT 'pending',       -- pending|working|done|error
  model TEXT,
  prompt_version TEXT,
  result_json TEXT,                    -- AI JSON output (string)
  summary TEXT,
  search_query TEXT,
  known INTEGER DEFAULT 0,             -- 1/0
  updated_at TEXT DEFAULT (datetime('now')),
  last_error TEXT
);

CREATE INDEX IF NOT EXISTS idx_command_ai_status ON command_ai(status, updated_at);


And optionally, a queue table if you like explicit queues (not required if you use command_ai.status).

When inserting new commands

In your ingest script, after inserting/upserting a commands row, ensure there’s a command_ai row:

"""




import os, sys, json, sqlite3, datetime, fcntl, logging, urllib.request, re, time
from logging.handlers import RotatingFileHandler
from typing import Dict, Any, List, Tuple

from notes_config import get_config, get_private_root

PRIVATE_ROOT = get_private_root(__file__)
KB_DB = os.path.join(PRIVATE_ROOT, "db/memory/bash_history.db")
SEARCH_QUEUE_DB = os.path.join(PRIVATE_ROOT, "db/memory/search_cache.db")  # if you want to queue there later (optional)
LOCK = os.path.join(PRIVATE_ROOT, "locks", "classify_bash_commands.lock")
LOG_PATH = os.path.join(PRIVATE_ROOT, "logs/classify_bash_commands.log")

PROMPT_VERSION = "bash_cmd_v1"
BATCH = int(os.getenv("BASH_AI_BATCH", "20"))

HUMAN_DB_DEFAULT = os.path.join(PRIVATE_ROOT, "db/memory/human_notes.db")
AI_DB_DEFAULT = os.path.join(PRIVATE_ROOT, "db/memory/notes_ai_metadata.db")
_CFG = get_config()
OLLAMA_URL_DEFAULT = _CFG.get("ai.ollama.url", "http://192.168.0.142:11434")
MODEL = _CFG.get("ai.ollama.model", "gpt-oss:latest")

JOB_NAME = "classify_bash_commands"


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


def setup_logging() -> logging.Logger:
    logger = logging.getLogger("classify_bash_commands")
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



def now():
    return datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")

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

def ensure_schema(db: sqlite3.Connection):
    db.executescript("""
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
    """)
    db.commit()

def fetch_pending(db: sqlite3.Connection, limit: int) -> List[Tuple[int, str, str]]:
    # ensure every command has a command_ai row
    db.execute("""
      INSERT OR IGNORE INTO command_ai(cmd_id, status, updated_at)
      SELECT id, 'pending', datetime('now') FROM commands
    """)
    db.commit()

    rows = db.execute("""
      SELECT c.id, c.full_cmd, c.base_cmd
      FROM commands c
      JOIN command_ai a ON a.cmd_id = c.id
      WHERE a.status IN ('pending','error')
      ORDER BY a.updated_at ASC, c.id ASC
      LIMIT ?
    """, (limit,)).fetchall()
    return [(int(r[0]), r[1], r[2]) for r in rows]

def mark_working(db: sqlite3.Connection, cmd_id: int):
    db.execute("""
      UPDATE command_ai
      SET status='working', updated_at=?, last_error=NULL
      WHERE cmd_id=?
    """, (now(), cmd_id))
    db.commit()

def mark_done(db: sqlite3.Connection, cmd_id: int, payload: Dict[str, Any]):
    summary = payload.get("intent", "") or ""
    search_query = payload.get("search_query", None)
    known = 1 if payload.get("known") else 0

    db.execute("""
      UPDATE command_ai
      SET status='done',
          model=?,
          prompt_version=?,
          result_json=?,
          summary=?,
          search_query=?,
          known=?,
          updated_at=?,
          last_error=NULL
      WHERE cmd_id=?
    """, (
        MODEL,
        PROMPT_VERSION,
        json.dumps(payload, ensure_ascii=False),
        summary,
        search_query if isinstance(search_query, str) else None,
        known,
        now(),
        cmd_id
    ))
    db.commit()

def mark_error(db: sqlite3.Connection, cmd_id: int, err: str):
    db.execute("""
      UPDATE command_ai
      SET status='error', updated_at=?, last_error=?
      WHERE cmd_id=?
    """, (now(), err[:500], cmd_id))
    db.commit()

def validate_payload(full_cmd: str, base_cmd: str, payload: Dict[str, Any]) -> Dict[str, Any]:
    # Force required keys and types
    out = {
        "full_cmd": full_cmd,
        "base_cmd": (payload.get("base_cmd") or base_cmd or "").strip(),
        "known": bool(payload.get("known", False)),
        "intent": (payload.get("intent") or "unknown").strip(),
        "keywords": payload.get("keywords") if isinstance(payload.get("keywords"), list) else [],
        "search_query": payload.get("search_query") if isinstance(payload.get("search_query"), str) else None,
        "notes": (payload.get("notes") or "").strip(),
    }
    # If unknown, never search
    if not out["known"]:
        out["search_query"] = None
        out["keywords"] = []
    # If base_cmd ended up empty, fallback
    if not out["base_cmd"]:
        out["base_cmd"] = base_cmd or full_cmd.split()[0]
    return out

def local_ai_classify(full_cmd: str, base_cmd: str) -> Dict[str, Any]:
    """
    Default implementation uses Ollama CLI (local).
    If you use an HTTP endpoint, swap this function.
    """
    prompt = f"""You are a bash command classifier.

Return ONLY valid JSON (no markdown, no extra text).
Schema:
{{
  "base_cmd": string,
  "known": boolean,
  "intent": string,
  "keywords": [string,...],
  "search_query": string|null,
  "notes": string
}}

Rules:
- base_cmd should be the first real command (skip leading 'sudo' and env assignments).
- If you are not confident, set known=false and search_query=null.
- search_query should be a good web query to learn what the command does.

Command:
full_cmd: {full_cmd}
base_cmd_guess: {base_cmd}
"""

    ollama_url = os.getenv("OLLAMA_URL", OLLAMA_URL_DEFAULT).rstrip("/")
    api_url = f"{ollama_url}/api/generate"

    req_body = {
        "model": MODEL,
        "prompt": prompt,
        "stream": False,
        "options": {
            "temperature": 0,
        },
    }

    try:
        req = urllib.request.Request(
            api_url,
            data=json.dumps(req_body).encode("utf-8"),
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        with urllib.request.urlopen(req, timeout=60) as resp:
            raw = resp.read().decode("utf-8", errors="ignore")
    except Exception as e:
        raise RuntimeError(f"ollama_http_failed url={api_url} err={e}")

    # Ollama /api/generate returns JSON with a 'response' field.
    try:
        data = json.loads(raw)
    except json.JSONDecodeError as e:
        raise RuntimeError(f"ollama_http_bad_json url={api_url} err={e} body={_truncate(raw, 800)}")

    txt = (data.get("response") or "").strip()
    if not txt:
        raise RuntimeError(f"ollama_empty_response url={api_url} body={_truncate(raw, 800)}")

    # Parse the model output as JSON, with a small repair pass for common bad escapes.
    return _parse_model_json(txt)


def _truncate(s: str, n: int = 2000) -> str:
    s = s or ""
    if len(s) <= n:
        return s
    return s[:n] + "...<truncated>"


def _extract_json_object(text: str) -> str:
    if not text:
        return ""
    start = text.find("{")
    end = text.rfind("}")
    if start == -1 or end == -1 or end <= start:
        return ""
    return text[start : end + 1]


def _repair_invalid_json_escapes(text: str) -> str:
    """Repair common invalid JSON escapes like \\_ by doubling the backslash.

    JSON allows escapes: \\" \\\\ \\/ \\b \\f \\n \\r \\t \\uXXXX
    """
    if not text:
        return text

    text = re.sub(r'\\(?!["\\/bfnrt]|u[0-9a-fA-F]{4})', r'\\\\', text)
    text = re.sub(r'\\$', r'\\\\', text)
    return text


def _parse_model_json(txt: str) -> Dict[str, Any]:
    last_err = None
    txt = (txt or "").strip()

    candidates = []
    if txt:
        candidates.append(txt)

    extracted = _extract_json_object(txt)
    if extracted and extracted != txt:
        candidates.append(extracted)

    # Try repair pass on candidates.
    for c in list(candidates):
        rc = _repair_invalid_json_escapes(c)
        if rc != c:
            candidates.append(rc)

    # De-dup while preserving order.
    seen = set()
    uniq = []
    for c in candidates:
        if c in seen:
            continue
        seen.add(c)
        uniq.append(c)

    for c in uniq:
        try:
            val = json.loads(c)
            if not isinstance(val, dict):
                raise json.JSONDecodeError("top_level_not_object", c, 0)
            return val
        except json.JSONDecodeError as e:
            last_err = e

    if isinstance(last_err, json.JSONDecodeError):
        raise last_err
    raise json.JSONDecodeError("invalid_json", txt, 0)

def main():
    logger = setup_logging()

    lock_or_exit(LOCK)

    hb = sqlite3.connect(HUMAN_DB_DEFAULT)
    try:
        ensure_job_runs_schema(hb)
        job_upsert_start(hb, JOB_NAME)
        t0 = time.time()

        db = sqlite3.connect(KB_DB)
        ensure_schema(db)

        pending = fetch_pending(db, BATCH)
        if not pending:
            logger.info("noop pending=0")
            dur_ms = int((time.time() - t0) * 1000)
            job_upsert_finish(hb, JOB_NAME, True, dur_ms, "noop pending=0")
            return

        logger.info("start pending=%s batch=%s model=%s", int(len(pending)), int(BATCH), MODEL)

        processed = 0
        done = 0
        errors = 0

        for cmd_id, full_cmd, base_cmd in pending:
            processed += 1
            try:
                mark_working(db, cmd_id)
                raw = local_ai_classify(full_cmd, base_cmd)
                payload = validate_payload(full_cmd, base_cmd, raw)
                mark_done(db, cmd_id, payload)
                done += 1

                logger.info(
                    "done cmd_id=%s known=%s base_cmd=%s",
                    int(cmd_id),
                    1 if payload.get("known") else 0,
                    payload.get("base_cmd", ""),
                )

            except Exception as e:
                errors += 1
                err_text = str(e)

                if isinstance(e, json.JSONDecodeError):
                    try:
                        prompt_debug = f"full_cmd={full_cmd} base_cmd_guess={base_cmd}"
                        err_text = f"json_decode_error: {e} ({prompt_debug})"
                    except Exception:
                        pass

                mark_error(db, cmd_id, err_text)
                logger.exception(
                    "error cmd_id=%s base_cmd=%s full_cmd=%s err=%s",
                    int(cmd_id),
                    _truncate(base_cmd, 200),
                    _truncate(full_cmd, 500),
                    _truncate(err_text, 500),
                )

        logger.info("finish processed=%s done=%s errors=%s", int(processed), int(done), int(errors))
        dur_ms = int((time.time() - t0) * 1000)
        job_upsert_finish(hb, JOB_NAME, errors == 0, dur_ms, f"processed={processed} done={done} errors={errors}")
    except Exception as e:
        try:
            job_upsert_finish(hb, JOB_NAME, False, 0, f"fatal: {str(e)}")
        except Exception:
            pass
        raise
    finally:
        try:
            hb.close()
        except Exception:
            pass

if __name__ == "__main__":
    main()
