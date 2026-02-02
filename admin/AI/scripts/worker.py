#!/usr/bin/env python3
# /web/html/admin/AI/scripts/worker.py
import json, os, sys, time, traceback
from mq import MotherQueue

DB = os.environ.get("MOTHER_QUEUE_DB", "/web/private/db/memory/mother_queue.db")
PIDFILE = "/tmp/mq_worker_{queue}.pid"
AUTO_EXIT_SECONDS = 300  # Exit after 5 minutes
SCRIPTS_DIR = os.environ.get("MCP_SCRIPTS_DIR", "/web/private/mcp/scripts")
ALLOWED_EXTS = (".py", ".sh")


# Helper to handle jobs and their payloads.
def find_script_for_job(name: str) -> str | None:
    # Prefer .py then .sh
    for ext in ALLOWED_EXTS:
        p = os.path.join(SCRIPTS_DIR, f"{name}{ext}")
        if os.path.isfile(p) and os.access(p, os.R_OK):
            return p
    return None

def run_job_script(script_path: str, payload: dict):
    import subprocess

    if script_path.endswith(".py"):
        # Pass payload as JSON arg
        subprocess.check_call([sys.executable, script_path, json.dumps(payload)])
        return

    if script_path.endswith(".sh"):
        # Pass payload via env + stdin (either is fine; env is easiest)
        env = os.environ.copy()
        env["MCP_PAYLOAD_JSON"] = json.dumps(payload)
        subprocess.check_call([script_path], env=env)
        return

    raise RuntimeError(f"Unsupported script type: {script_path}")


def handle_job(job, payload):
    name = job["name"]

    if name == "noop":
        return

    # Optional: keep legacy hardcoded ones during transition
    if name == "ingest_bash_history":
        user = payload.get("user")
        if user not in ("samekhi", "root"):
            raise RuntimeError(f"Bad user: {user}")

        import subprocess
        subprocess.check_call([
            sys.executable,
            "/web/private/scripts/ingest_bash_history_to_kb.py",
            user
        ])
        return

    script = find_script_for_job(name)
    if not script:
        raise RuntimeError(f"tool/skill not found: {name} (no script in {SCRIPTS_DIR})")

    run_job_script(script, payload)

def acquire_lock(pidfile):
    """Try to acquire a PID lock. Returns True if successful, False if another worker is running."""
    if os.path.exists(pidfile):
        try:
            with open(pidfile, 'r') as f:
                old_pid = int(f.read().strip())
            # Check if process is still running
            os.kill(old_pid, 0)
            # Process exists, lock is held
            return False
        except (OSError, ValueError):
            # Process doesn't exist or invalid PID, claim the lock
            pass
    
    # Write our PID
    with open(pidfile, 'w') as f:
        f.write(str(os.getpid()))
    return True

def release_lock(pidfile):
    """Release the PID lock."""
    try:
        os.unlink(pidfile)
    except OSError:
        pass

def main():
    if len(sys.argv) < 2:
        print("Usage: worker.py <queue> [sleep_seconds]")
        sys.exit(2)

    queue = sys.argv[1]
    sleep_s = int(sys.argv[2]) if len(sys.argv) >= 3 else 2
    
    pidfile = PIDFILE.format(queue=queue)
    
    # Try to acquire lock
    if not acquire_lock(pidfile):
        print(f"[mq] Another worker for queue '{queue}' is already running. Exiting.")
        sys.exit(0)

    mq = MotherQueue(DB)
    print(f"[mq] worker up. db={DB} queue={queue} pid={os.getpid()} auto_exit={AUTO_EXIT_SECONDS}s")
    
    start_time = time.time()

    try:
        while True:
            # Check if we should auto-exit
            if time.time() - start_time > AUTO_EXIT_SECONDS:
                print(f"[mq] Auto-exit after {AUTO_EXIT_SECONDS}s")
                break
            
            leased = mq.lease_one(queue, lease_seconds=120)
            if not leased:
                time.sleep(sleep_s)
                continue

            job, payload = leased
            try:
                handle_job(job, payload)
                mq.ack(job["id"])
            except Exception as e:
                err = f"{e}\n{traceback.format_exc()}"
                mq.fail(job["id"], err, retry_delay_seconds=60)
    finally:
        release_lock(pidfile)

if __name__ == "__main__":
    main()
