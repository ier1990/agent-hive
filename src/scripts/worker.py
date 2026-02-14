#!/usr/bin/env python3
# /web/html/admin/AI/scripts/worker.py
import json, os, sys, time, traceback
from mq import MotherQueue

DB = os.environ.get("MOTHER_QUEUE_DB", "/web/private/db/memory/mother_queue.db")
PIDFILE = "/tmp/mq_worker_{queue}.pid"
AUTO_EXIT_SECONDS = 300  # Exit after 5 minutes

def handle_job(job, payload):
    """
    v1: stub handlers.
    Add real handlers incrementally.
    """
    name = job["name"]

    if name == "noop":
        # for testing
        return

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

    # Example future hook:
    # if name == "ai_search_summ":
    #     from your_script import run_ai_search_summ
    #     run_ai_search_summ(payload)
    #     return

    raise RuntimeError(f"Unknown job name: {name}")

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
