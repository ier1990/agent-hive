#!/web/.venv/bin/python3
"""
vllm_ctl.py - start/stop/status for vLLM OpenAI server.

Examples:
  ./vllm_ctl.py start --model DeepHat/DeepHat-V1-7B --host 0.0.0.0 --port 8000
  ./vllm_ctl.py status
  ./vllm_ctl.py stop
"""

from __future__ import annotations

import argparse
import json
import os
import signal
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

DEFAULT_MODEL = "DeepHat/DeepHat-V1-7B"
DEFAULT_HOST = "0.0.0.0"
DEFAULT_PORT = 8000

# Put these wherever you like
STATE_DIR = Path("/tmp/vllm_ctl")
PID_FILE = STATE_DIR / "vllm.pid"
LOG_FILE = STATE_DIR / "vllm.log"


def _ensure_state_dir() -> None:
    STATE_DIR.mkdir(parents=True, exist_ok=True)


def _read_pid() -> int | None:
    try:
        pid = int(PID_FILE.read_text().strip())
        return pid
    except Exception:
        return None


def _pid_alive(pid: int) -> bool:
    try:
        os.kill(pid, 0)
        return True
    except ProcessLookupError:
        return False
    except PermissionError:
        # Exists but not ours; treat as alive to be safe
        return True


def _http_get_json(url: str, timeout: float = 1.5) -> dict:
    req = urllib.request.Request(url, headers={"Accept": "application/json"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        data = resp.read().decode("utf-8", errors="replace")
    return json.loads(data)


def is_ready(host: str, port: int) -> bool:
    try:
        j = _http_get_json(f"http://{host}:{port}/v1/models", timeout=1.5)
        return isinstance(j, dict) and "data" in j
    except Exception:
        return False


def start(args: argparse.Namespace) -> None:
    _ensure_state_dir()

    pid = _read_pid()
    if pid and _pid_alive(pid):
        print(f"[INFO] vLLM already running (pid={pid}).")
        if is_ready(args.host_check, args.port):
            print("[OK] Server is responding on /v1/models.")
        else:
            print("[WARN] PID exists but server not responding yet.")
        return

    # Build command (vLLM CLI)
    cmd = [
        sys.executable, "-m", "vllm.entrypoints.openai.api_server",
        "--model", args.model,
        "--host", args.host,
        "--port", str(args.port),
    ]

    # Optional knobs (only append if provided)
    if args.dtype:
        cmd += ["--dtype", args.dtype]
    if args.max_model_len:
        cmd += ["--max-model-len", str(args.max_model_len)]
    if args.gpu_memory_utilization:
        cmd += ["--gpu-memory-utilization", str(args.gpu_memory_utilization)]
    if args.tensor_parallel_size:
        cmd += ["--tensor-parallel-size", str(args.tensor_parallel_size)]
    if args.download_dir:
        cmd += ["--download-dir", args.download_dir]

    # vLLM will inherit env; you can also set HF_HOME, TRANSFORMERS_CACHE, etc.
    env = os.environ.copy()
    env['HF_HOME'] = '/web/ai/vllm/huggingface'
    env['HF_HUB_CACHE'] = '/web/ai/vllm/huggingface/hub'

    # Start detached so it stays running after you close the shell
    with open(LOG_FILE, "ab", buffering=0) as log:
        proc = subprocess.Popen(
            cmd,
            stdout=log,
            stderr=log,
            env=env,
            start_new_session=True,  # own process group (for clean stop)
        )

    PID_FILE.write_text(str(proc.pid))
    print(f"[OK] Started vLLM (pid={proc.pid})")
    print(f"[INFO] Log: {LOG_FILE}")

    # Wait for readiness (short, bounded)
    deadline = time.time() + args.wait_seconds
    while time.time() < deadline:
        if is_ready(args.host_check, args.port):
            print("[OK] Server is ready: /v1/models responds.")
            return
        time.sleep(0.5)

    print("[WARN] Started, but not ready yet. Check logs:")
    print(f"  tail -n 80 {LOG_FILE}")


def stop(args: argparse.Namespace) -> None:
    pid = _read_pid()
    if not pid:
        print("[INFO] No PID file found. Nothing to stop.")
        return

    if not _pid_alive(pid):
        print(f"[INFO] PID file exists but pid={pid} is not running. Cleaning up.")
        try:
            PID_FILE.unlink(missing_ok=True)
        except Exception:
            pass
        return

    print(f"[INFO] Stopping vLLM pid={pid} ...")
    try:
        # Send SIGTERM to the process group we created (start_new_session=True)
        os.killpg(pid, signal.SIGTERM)
    except ProcessLookupError:
        pass

    deadline = time.time() + args.grace_seconds
    while time.time() < deadline:
        if not _pid_alive(pid):
            break
        time.sleep(0.3)

    if _pid_alive(pid):
        print("[WARN] Still running; sending SIGKILL.")
        try:
            os.killpg(pid, signal.SIGKILL)
        except ProcessLookupError:
            pass

    try:
        PID_FILE.unlink(missing_ok=True)
    except Exception:
        pass

    print("[OK] Stopped.")


def status(args: argparse.Namespace) -> None:
    pid = _read_pid()
    if not pid:
        print("[INFO] Not running (no PID file).")
        return

    alive = _pid_alive(pid)
    print(f"[INFO] PID: {pid} ({'alive' if alive else 'dead'})")
    if alive:
        if is_ready(args.host_check, args.port):
            print("[OK] /v1/models responds.")
        else:
            print("[WARN] Not responding yet (or host/port mismatch).")
        print(f"[INFO] Log: {LOG_FILE}")


def main() -> int:
    p = argparse.ArgumentParser(add_help=False)
    sub = p.add_subparsers(dest="cmd", required=False)

    # Shared options
    def add_common(sp: argparse.ArgumentParser) -> None:
        sp.add_argument("--model", default=DEFAULT_MODEL)
        sp.add_argument("--host", default=DEFAULT_HOST, help="bind host")
        sp.add_argument("--port", type=int, default=DEFAULT_PORT)
        sp.add_argument("--host-check", default="127.0.0.1",
                        help="host used for readiness checks (often 127.0.0.1)")

    sp_start = sub.add_parser("start")
    add_common(sp_start)
    sp_start.add_argument("--dtype", default=None, help="e.g. float16, bfloat16, auto")
    sp_start.add_argument("--max-model-len", type=int, default=None)
    sp_start.add_argument("--gpu-memory-utilization", type=float, default=None)
    sp_start.add_argument("--tensor-parallel-size", type=int, default=None)
    sp_start.add_argument("--download-dir", default=None)
    sp_start.add_argument("--wait-seconds", type=float, default=30.0)

    sp_stop = sub.add_parser("stop")
    add_common(sp_stop)
    sp_stop.add_argument("--grace-seconds", type=float, default=20.0)

    sp_status = sub.add_parser("status")
    add_common(sp_status)

    p.add_argument('-h', '--help', action='store_true', help='show this help message and exit')

    args = p.parse_args()

    if args.help or args.cmd is None:
        if args.cmd is None and not args.help:
            p.print_usage()
            return 1
        p.print_help()
        print("\nSubcommand options:")
        for name, subp in sub.choices.items():
            print(f"\n{name}:")
            subp.print_help()
        return 0

    if args.cmd == "start":
        start(args)
    elif args.cmd == "stop":
        stop(args)
    elif args.cmd == "status":
        status(args)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
