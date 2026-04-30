#!/usr/bin/env python3
from __future__ import annotations

import atexit
import argparse
import fcntl
import json
import os
import select
import shutil
import subprocess
import sys
import time
from pathlib import Path
from typing import Any, Dict, List, Optional

from agent_config import load_tool_settings, get_active_provider_entry, resolve_provider_by_ref
from editor_integration import get_available_editor, open_editor
from lib.bash_guard import bash_cfg as _bash_cfg
from lib.bash_guard import build_prompt_context as _build_prompt_context
from lib.bash_guard import execute as _execute
from lib.bash_guard import risk_level as _risk_level
from lib.bash_guard import store_from_settings as _store_from_settings
from lib.bash_guard import validate_command as _validate_command
from memory_loader import MemoryLoader
from notes_handler import NotesHandler
from proposal_store import ProposalStore
from session_logger import SessionLogger
from turn_generator import TurnGenerator

try:
    import readline
except ImportError:
    readline = None

try:
    from wcwidth import wcswidth
except ImportError:
    wcswidth = None

try:
    from prompt_toolkit import PromptSession
    from prompt_toolkit.completion import WordCompleter
except ImportError:
    PromptSession = None
    WordCompleter = None


HISTORY_LENGTH = 500
PASTE_GRACE_SECONDS = 0.12
PASTE_MAX_WAIT_SECONDS = 1.0


def _collect_runtime_access_details() -> Dict[str, str]:
    """Collect runtime privilege and host context for AI safety decisions."""
    try:
        whoami = subprocess.run(["whoami"], capture_output=True, text=True, timeout=3, check=False)
        user_text = (whoami.stdout or "").strip() or "unknown"
    except Exception:
        user_text = "unknown"

    try:
        ident = subprocess.run(["id"], capture_output=True, text=True, timeout=3, check=False)
        id_text = (ident.stdout or "").strip() or "unknown"
    except Exception:
        id_text = "unknown"

    try:
        host = subprocess.run(["hostname"], capture_output=True, text=True, timeout=3, check=False)
        hostname = (host.stdout or "").strip() or os.uname()[1]
    except Exception:
        hostname = os.uname()[1]

    try:
        uname_proc = subprocess.run(["uname", "-a"], capture_output=True, text=True, timeout=3, check=False)
        uname_text = (uname_proc.stdout or "").strip() or "unknown"
    except Exception:
        uname_text = "unknown"

    access = f"whoami={user_text}; id={id_text}"
    context = f"hostname={hostname}; uname={uname_text}; {access}"
    return {
        "whoami": user_text,
        "id": id_text,
        "hostname": hostname,
        "uname": uname_text,
        "access": access,
        "context": context,
    }


def _run_cmd(args: List[str], timeout: int = 3) -> str:
    """Run a command and return stripped stdout, or empty string on any failure."""
    try:
        r = subprocess.run(args, capture_output=True, text=True, timeout=timeout, check=False)
        return (r.stdout or "").strip()
    except Exception:
        return ""


def _collect_sysinfo() -> Dict[str, Any]:
    """Collect rich system info mirroring the data gathered in bin/sysinfo.sh."""
    info: Dict[str, Any] = {}

    # Uptime & load
    try:
        with open("/proc/uptime") as f:
            uptime_secs = int(float(f.read().split()[0]))
        h, rem = divmod(uptime_secs, 3600)
        m = rem // 60
        info["uptime"] = f"{h}h {m}m"
        info["uptime_seconds"] = uptime_secs
    except Exception:
        info["uptime"] = "unknown"

    try:
        with open("/proc/loadavg") as f:
            parts = f.read().split()
            info["load"] = f"{parts[0]} {parts[1]} {parts[2]}"
    except Exception:
        info["load"] = "unknown"

    # Memory
    try:
        mem_out = _run_cmd(["free", "-m"])
        for line in mem_out.splitlines():
            if line.startswith("Mem:"):
                cols = line.split()
                total, used = cols[1], cols[2]
                avail = cols[6] if len(cols) > 6 else None
                if avail:
                    info["memory"] = f"{used}MB used / {total}MB total (avail {avail}MB)"
                else:
                    info["memory"] = f"{used}MB used / {total}MB total"
                break
    except Exception:
        info["memory"] = "unknown"

    # Disk
    try:
        disk_out = _run_cmd(["df", "-h", "/"])
        lines = disk_out.splitlines()
        if len(lines) >= 2:
            cols = lines[1].split()
            info["disk_root"] = f"{cols[2]} used / {cols[1]} total ({cols[4]} used)"
    except Exception:
        info["disk_root"] = "unknown"

    # CPU
    try:
        with open("/proc/cpuinfo") as f:
            for line in f:
                if line.startswith("model name"):
                    info["cpu"] = line.split(":", 1)[1].strip()
                    break
        info["cpu_count"] = int(_run_cmd(["nproc"]) or "1")
    except Exception:
        info["cpu"] = "unknown"
        info["cpu_count"] = 1

    # OS & kernel
    try:
        with open("/etc/os-release") as f:
            for line in f:
                if line.startswith("PRETTY_NAME="):
                    info["os"] = line.split("=", 1)[1].strip().strip('"')
                    break
    except Exception:
        info["os"] = "unknown"

    info["kernel"] = _run_cmd(["uname", "-r"]) or "unknown"

    # PHP
    php_out = _run_cmd(["php", "-v"])
    if php_out:
        info["php_version"] = php_out.splitlines()[0].split()[1] if php_out.split() else "present"
    else:
        info["php_version"] = "not installed"

    # Primary IP
    ip_out = _run_cmd(["ip", "-4", "-o", "addr", "show", "scope", "global"])
    if ip_out:
        first_line = ip_out.splitlines()[0]
        addr_part = first_line.split()[3] if len(first_line.split()) > 3 else ""
        info["primary_ip"] = addr_part.split("/")[0]
    else:
        info["primary_ip"] = _run_cmd(["hostname", "-I"]).split()[0] if _run_cmd(["hostname", "-I"]) else "unknown"

    # Docker
    if shutil.which("docker"):
        running = _run_cmd(["docker", "ps", "-q"])
        total = _run_cmd(["docker", "ps", "-aq"])
        info["docker"] = {
            "containers_running": len(running.splitlines()) if running else 0,
            "containers_total": len(total.splitlines()) if total else 0,
        }

    # Ollama
    if shutil.which("ollama"):
        ov = _run_cmd(["ollama", "--version"])
        if ov:
            ver = ov.split()[-1] if ov.split() else ov
            info["ollama_version"] = ver

    # Nvidia GPU
    if shutil.which("nvidia-smi"):
        gpu_out = _run_cmd([
            "nvidia-smi",
            "--query-gpu=name,driver_version,memory.total,temperature.gpu,utilization.gpu",
            "--format=csv,noheader,nounits",
        ])
        if gpu_out:
            parts = [p.strip() for p in gpu_out.splitlines()[0].split(",")]
            if len(parts) >= 2:
                info["gpu"] = {
                    "name": parts[0] if len(parts) > 0 else "unknown",
                    "driver": parts[1] if len(parts) > 1 else "unknown",
                    "vram_mb": int(parts[2]) if len(parts) > 2 and parts[2].isdigit() else None,
                    "temp_c": int(parts[3]) if len(parts) > 3 and parts[3].isdigit() else None,
                    "util_pct": int(parts[4]) if len(parts) > 4 and parts[4].isdigit() else None,
                }

    return info


def _print_status(
    cwd: Path,
    debug_enabled: bool,
    turn_count: int,
    max_turns: int,
    runtime_access: str,
    runtime_details: Dict[str, str],
    turn_gen: Optional["TurnGenerator"],
    provider_name: str,
    active_idx: int,
    providers_list: List[Dict[str, Any]],
) -> None:
    """Print the /status output with rich system info."""
    cyan = "\033[96m"
    green = "\033[92m"
    yellow = "\033[93m"
    reset = "\033[0m"

    def _lbl(label: str, value: str, color: str = reset) -> None:
        print(f"  {cyan}{label:<18}{reset} {color}{value}{reset}")

    print(f"\n{cyan}── Session ──────────────────────────────────────────────────{reset}")
    _lbl("cwd", str(cwd))
    _lbl("debug", "on" if debug_enabled else "off", yellow if debug_enabled else green)
    _lbl("turns", f"{turn_count}/{max_turns}")
    _lbl("access", runtime_access)

    print(f"\n{cyan}── Provider ─────────────────────────────────────────────────{reset}")
    if turn_gen:
        status = "online" if turn_gen.is_available else "offline"
        status_color = green if status == "online" else yellow
        _lbl("name", f"[{active_idx}] {turn_gen.provider_name}", cyan)
        _lbl("model", turn_gen.provider_model)
        if turn_gen.provider_endpoint != "n/a":
            _lbl("endpoint", turn_gen.provider_endpoint)
        _lbl("status", status, status_color)
        _lbl("providers", str(len(providers_list)) + " configured")
    else:
        _lbl("status", "disabled", yellow)

    print(f"\n{cyan}── System ───────────────────────────────────────────────────{reset}")
    _lbl("hostname", runtime_details["hostname"])
    _lbl("kernel", runtime_details.get("uname", "unknown").split()[2] if runtime_details.get("uname") else "unknown")
    sysinfo = _collect_sysinfo()
    _lbl("os", sysinfo.get("os", "unknown"))
    _lbl("uptime", sysinfo.get("uptime", "unknown"))
    _lbl("load", sysinfo.get("load", "unknown"))
    _lbl("memory", sysinfo.get("memory", "unknown"))
    _lbl("disk /", sysinfo.get("disk_root", "unknown"))
    cpu = sysinfo.get("cpu", "unknown")
    cpuc = sysinfo.get("cpu_count", 1)
    _lbl("cpu", f"{cpuc}x {cpu}")
    _lbl("ip", sysinfo.get("primary_ip", "unknown"))
    if sysinfo.get("php_version", "not installed") != "not installed":
        _lbl("php", sysinfo["php_version"])
    if "docker" in sysinfo:
        d = sysinfo["docker"]
        _lbl("docker", f"{d['containers_running']} running / {d['containers_total']} total")
    if "ollama_version" in sysinfo:
        _lbl("ollama", sysinfo["ollama_version"])
    if "gpu" in sysinfo:
        g = sysinfo["gpu"]
        gpu_parts = [g.get("name", "?")]
        if g.get("vram_mb"):
            gpu_parts.append(f"{g['vram_mb']}MB VRAM")
        if g.get("temp_c") is not None:
            gpu_parts.append(f"{g['temp_c']}°C")
        if g.get("util_pct") is not None:
            gpu_parts.append(f"{g['util_pct']}% util")
        _lbl("gpu", "  ".join(gpu_parts))
    print()


def _normalize_contract(raw: Dict[str, Any], turn_count: int, max_turns: int) -> Dict[str, Any]:
    state = str(raw.get("state", "needs_input") or "needs_input").strip().lower()
    if state not in {"continue", "needs_input", "final"}:
        state = "needs_input"

    summary = str(raw.get("summary", "") or "").strip()
    next_tool = raw.get("next_tool")
    next_tool = None if next_tool in (None, "", "null") else str(next_tool).strip()
    reason = str(raw.get("reason", "") or "").strip()
    ask_user = raw.get("ask_user")
    ask_user = None if ask_user in (None, "", "null") else str(ask_user).strip()
    final_answer = raw.get("final_answer")
    final_answer = None if final_answer in (None, "", "null") else str(final_answer).strip()

    try:
        confidence = float(raw.get("confidence", 1.0))
    except Exception:
        confidence = 1.0
    confidence = max(0.0, min(confidence, 1.0))

    if confidence < 0.7 and summary:
        summary = "WARNING: Guardian is unsure about this step. " + summary

    if turn_count >= max_turns and state != "final":
        state = "final"
        final_answer = (
            final_answer
            or "Turn limit reached. Current progress has been captured. Resume with /memory 1 in a later session."
        )
        confidence = 1.0

    return {
        "state": state,
        "summary": summary,
        "next_tool": next_tool,
        "reason": reason,
        "ask_user": ask_user,
        "final_answer": final_answer,
        "confidence": confidence,
    }


def _render_contract(contract: Dict[str, Any], debug_enabled: bool) -> None:
    if debug_enabled:
        print(json.dumps(contract, ensure_ascii=True, indent=2))
        return
    summary = str(contract.get("summary", "") or "").strip()
    ask_user = contract.get("ask_user")
    final_answer = contract.get("final_answer")
    if summary:
        print(f"[Guardian]: {summary}")
    if ask_user:
        print(str(ask_user))
    if contract.get("state") == "final" and final_answer:
        print(str(final_answer))


def _load_contract_json(text: str) -> Dict[str, Any]:
    raw = json.loads(text)
    if not isinstance(raw, dict):
        raise ValueError("contract must be a JSON object")
    return raw


def _process_contract(
    raw_contract: Dict[str, Any],
    turn_count: int,
    max_turns: int,
    debug_enabled: bool,
    cwd: Path,
    cfg: Dict[str, Any],
    repo_root: Path,
    store: ProposalStore,
    render_output: bool = True,
) -> Dict[str, Any]:
    contract = _normalize_contract(raw_contract, turn_count=turn_count, max_turns=max_turns)
    proposal_id: Optional[int] = None
    proposed_command: Optional[str] = None
    auto_execute = False
    auto_execute_reason: Optional[str] = None
    blocked_error: Optional[str] = None

    if contract["state"] == "continue" and contract["next_tool"]:
        check = _validate_command(str(contract["next_tool"]), cwd, cfg, repo_root)
        if not check.get("ok"):
            blocked_error = str(check.get("error") or "tool blocked")
            contract["state"] = "needs_input"
            contract["ask_user"] = f"Tool blocked: {blocked_error}"
            contract["next_tool"] = None
        else:
            proposed_command = str(contract["next_tool"])
            exact = store.find_exact_duplicates(proposed_command)
            history_match = exact[0] if exact else None
            risk_level = _risk_level(proposed_command)
            operator_summary = contract["reason"] or contract["summary"] or "AI proposed next step"
            metadata = {"source": "turn_contract", "confidence": contract["confidence"]}

            if history_match is not None:
                metadata["history_match_id"] = int(history_match["id"])
                metadata["history_match_status"] = str(history_match["status"])
                proposal_id = store.create_auto_approved_proposal(
                    command_text=proposed_command,
                    cwd=str(cwd),
                    risk_level=risk_level,
                    operator_summary=operator_summary,
                    metadata=metadata,
                    approved_by="history-auto",
                    notes="Auto-approved from exact approved/executed bash history match",
                )
                auto_execute = True
                auto_execute_reason = "exact bash history match already approved"
                contract["summary"] = (contract["summary"] + " " if contract["summary"] else "") + f"[auto-approved #{proposal_id} from #{history_match['id']}]"
            else:
                proposal_id = store.create_proposal(
                    command_text=proposed_command,
                    cwd=str(cwd),
                    risk_level=risk_level,
                    operator_summary=operator_summary,
                    metadata=metadata,
                )
                contract["summary"] = (contract["summary"] + " " if contract["summary"] else "") + f"[proposed #{proposal_id}]"

    if render_output:
        _render_contract(contract, debug_enabled=debug_enabled)
    return {
        "is_final": contract["state"] == "final",
        "contract": contract,
        "proposal_id": proposal_id,
        "proposed_command": proposed_command,
        "auto_execute": auto_execute,
        "auto_execute_reason": auto_execute_reason,
        "blocked_error": blocked_error,
    }


def _show_help() -> None:
    print("Commands:")
    print("  /help                                Show this help")
    print("  /status                              Show current status")
    print("  /provider                            Show active provider/model configuration")
    print("  /provider list                       List all configured providers")
    print("  /provider <idx|label>                Switch active provider (e.g. /provider 1 or /provider big)")
    print("  /hello                               Run provider hello check")
    print("  /paste                               Enter multiline paste mode (/end to submit)")
    print("  /debug                               Toggle debug mode (full JSON visibility)")
    print("  /contract <json>                     Process a turn contract (JSON object)")
    print("  /bh list [N]                         List N pending bash history entries (default: 25)")
    print("  /bh history [N]                      List N recent bash history entries (all statuses)")
    print("  /bh search <pattern>                 Search for similar approved commands")
    print("  /bh top [N]                          Show top N most-used executed commands (default: 20)")
    print("  /bh check <command>                  Check for duplicates and similar commands")
    print("  /bh propose <command>                Create a new bash history entry")
    print("  /bh approve <id>                     Approve and execute bash history entry")
    print("  /bh reject <id> [reason]             Reject bash history entry")
    print("  /tools ...                           Alias for /bh (backward compatibility)")
    print("  /compose [--bootstrap]               Open editor for context composition")
    print("  /notes                               Create a new note")
    print("  /memory [N]                          Recall recent session (default: 1, summaries only)")
    print("  /memory full [N]                     Recall full session content")
    print("  <plain text>                         Send message to AI and get a turn response")
    print("  /exit                                Exit the shell")


def _print_provider_banner(turn_gen: Optional[TurnGenerator], provider_name: str) -> None:
    if turn_gen is None:
        print(f"Provider: {provider_name} (disabled)")
        print("Provider status: unavailable")
        return
    print(f"Provider: {turn_gen.provider_name}")
    print(f"Model: {turn_gen.provider_model}")
    if turn_gen.provider_endpoint != "n/a":
        print(f"Endpoint: {turn_gen.provider_endpoint}")
    status = "online" if turn_gen.is_available else "offline"
    print(f"Provider status: {status}")


def _visual_len(s: str) -> int:
    """Calculate display width of string, accounting for emoji widths."""
    if wcswidth is not None:
        w = wcswidth(s)
        return w if w >= 0 else len(s)  # fallback if contains unprintable chars
    # Fallback: count most emojis as 2 columns
    return len(s) + sum(1 for c in s if ord(c) > 0xFFFF)


def _truncate_to_width(text: str, max_width: int) -> str:
    """Safely truncate text to fit within max_width display columns."""
    result = ""
    for ch in text:
        if _visual_len(result + ch) > max_width:
            break
        result += ch
    return result


def _box_line(content: str, width: int, border_color: str, text_color: str, reset: str) -> str:
    """Format a box line with proper emoji width handling."""
    content_width = width - 4  # borders + spaces
    
    # Safely truncate content to fit
    visible = _truncate_to_width(content, content_width)
    
    # Calculate actual display width and pad accordingly
    visible_len = _visual_len(visible)
    padding = max(0, content_width - visible_len)
    
    padded = " " + visible + " " * padding + " "
    return border_color + "║" + reset + text_color + padded + reset + border_color + "║" + reset


def _runtime_access_color(runtime_access: str) -> str:
    if "whoami=root" in runtime_access or "uid=0(" in runtime_access:
        return "\033[91m"
    if "(sudo)" in runtime_access or ",27(sudo)" in runtime_access:
        return "\033[93m"
    return "\033[92m"


def _print_welcome_banner(
    hostname: str,
    uname_text: str,
    runtime_access: str,
    provider_name: str,
    model_name: str,
    provider_endpoint: str,
    provider_status: str,
    hello_status: str,
) -> None:
    # ANSI colors are widely supported in modern terminals.
    reset = "\033[0m"
    cyan = "\033[96m"
    green = "\033[92m"
    yellow = "\033[93m"
    blue = "\033[94m"
    magenta = "\033[95m"
    white = "\033[97m"
    width = 72
    access_color = _runtime_access_color(runtime_access)

    print(cyan + "╔" + "═" * (width - 3) + "╗" + reset)
    print(_box_line("🩺  Root Guardian Clinic  •  happy little diagnostics bay", width, cyan, white, reset))
    print(_box_line(f"🛡️  read-only care  •  tools-only  •  human-approved actions", width, cyan, yellow, reset))
    print(_box_line(f"🤖 provider: {provider_name}  •  model: {model_name}", width, cyan, green, reset))
    print(_box_line(f"🌐 endpoint: {provider_endpoint}", width, cyan, blue, reset))
    print(_box_line(f"🧪 provider: {provider_status}  •  hello: {hello_status}", width, cyan, magenta, reset))
    print(_box_line(f"🧑 hostname: {hostname}", width, cyan, white, reset))
    print(_box_line(f"💻 uname: {uname_text}", width, cyan, white, reset))
    print(_box_line(f"🔐 access: {runtime_access}", width, cyan, access_color, reset))
    print(cyan + "╚" + "═" * (width - 3) + "╝" + reset)


def _setup_readline(history_file: Path) -> None:
    if readline is None:
        return
    try:
        readline.parse_and_bind("set editing-mode emacs")
    except Exception:
        pass
    try:
        readline.parse_and_bind("tab: complete")
    except Exception:
        pass
    try:
        readline.set_history_length(HISTORY_LENGTH)
    except Exception:
        pass

    history_file.parent.mkdir(parents=True, exist_ok=True)
    try:
        readline.read_history_file(str(history_file))
    except FileNotFoundError:
        pass
    except Exception:
        return

    def _save_history() -> None:
        try:
            readline.set_history_length(HISTORY_LENGTH)
            readline.write_history_file(str(history_file))
        except Exception:
            return

    atexit.register(_save_history)


def _setup_prompt_session() -> Optional[PromptSession]:
    """Create a PromptSession with command autocomplete."""
    if not sys.stdin.isatty() or not sys.stdout.isatty():
        return None
    if PromptSession is None or WordCompleter is None:
        return None
    
    commands = [
        "/help",
        "/status",
        "/provider",
        "/provider list",
        "/hello",
        "/paste",
        "/debug",
        "/contract",
        "/bh list",
        "/bh history",
        "/bh search",
        "/bh top",
        "/bh check",
        "/bh propose",
        "/bh approve",
        "/bh reject",
        "/tools list",
        "/tools history",
        "/tools search",
        "/tools top",
        "/tools check",
        "/tools propose",
        "/tools approve",
        "/tools reject",
        "/compose",
        "/compose --bootstrap",
        "/notes",
        "/memory",
        "/memory full",
        "/exit",
        "/quit",
    ]
    
    completer = WordCompleter(commands, ignore_case=True, sentence=True)
    
    return PromptSession(
        completer=completer,
        complete_while_typing=True,
    )


def _read_user_input(prompt_text: str = "guardian 🛡️ > ", session: Optional[PromptSession] = None) -> str:
    """Read one logical user prompt, including rapid multiline paste bursts."""
    
    # Try to use prompt_toolkit if available, fall back to input()
    if session is not None:
        try:
            first = session.prompt(prompt_text).strip()
        except (EOFError, KeyboardInterrupt):
            return "/exit"
    else:
        try:
            first = input(prompt_text).strip()
        except (EOFError, KeyboardInterrupt):
            return "/exit"

    if not sys.stdin.isatty():
        return first

    lines = [first.rstrip("\r")]

    try:
        fd = sys.stdin.fileno()
        orig_flags = fcntl.fcntl(fd, fcntl.F_GETFL)
        fcntl.fcntl(fd, fcntl.F_SETFL, orig_flags | os.O_NONBLOCK)
    except Exception:
        return "\n".join(lines)

    try:
        started = time.monotonic()
        deadline = started + PASTE_GRACE_SECONDS
        while True:
            now = time.monotonic()
            if now - started > PASTE_MAX_WAIT_SECONDS:
                break
            remaining = deadline - now
            if remaining <= 0:
                break

            ready, _, _ = select.select([sys.stdin], [], [], remaining)
            if not ready:
                break

            try:
                extra = sys.stdin.readline()
            except BlockingIOError:
                break

            if not extra:
                break

            lines.append(extra.rstrip("\r\n"))
            deadline = time.monotonic() + PASTE_GRACE_SECONDS
    finally:
        try:
            fcntl.fcntl(fd, fcntl.F_SETFL, orig_flags)
        except Exception:
            pass

    return "\n".join(lines).replace("\r\n", "\n").replace("\r", "\n")


def _read_multiline_query() -> str:
    print("Paste mode: finish with /end or discard with /cancel.")
    lines: List[str] = []
    while True:
        try:
            line = input("... ").rstrip("\n")
        except (EOFError, KeyboardInterrupt):
            print("Paste cancelled.")
            return ""
        normalized = line.strip().lower()
        if normalized == "/cancel":
            print("Paste discarded.")
            return ""
        if normalized == "/end":
            break
        lines.append(line)
    text = "\n".join(lines).strip()
    if text == "":
        print("No pasted content submitted.")
        return ""
    return text


def _handle_compose(
    line: str,
    initial_context: str = "",
    bootstrap_text: Optional[str] = None,
    editor_cmd: str = "nano",
) -> Optional[str]:
    """Handle /compose command to open editor for context editing.
    
    Returns edited context if user saved, None if canceled.
    """
    include_bootstrap = "--bootstrap" in line
    context_to_edit = initial_context
    if include_bootstrap and bootstrap_text:
        context_to_edit = bootstrap_text + "\n\n" + initial_context
    
    result = open_editor(
        initial_content=context_to_edit,
        editor_cmd=editor_cmd,
        bootstrap_text=None,
    )
    
    if result:
        print(f"Context updated ({len(result)} bytes)")
        return result
    else:
        print("Composition canceled")
        return None


def _handle_notes(notes_handler: NotesHandler, session_file: Path, editor_cmd: str) -> Optional[Path]:
    """Handle /notes command to create a user note.
    
    Returns path to created note if saved, None if canceled.
    """
    result = open_editor(
        initial_content="",
        editor_cmd=editor_cmd,
    )
    
    if not result or not result.strip():
        print("Note creation canceled")
        return None
    
    # For now, use simple title generation from content
    title = result.split("\n")[0][:50] if result else "Untitled"
    tags = ["user-note"]
    
    try:
        note_file = notes_handler.save_note(
            content=result,
            title=title,
            tags=tags,
            session_ref=session_file.name,
        )
        print(f"Note saved: {note_file.relative_to(notes_handler.notes_dir)}")
        return note_file
    except Exception as exc:
        print(f"error: failed to save note: {exc}")
        return None


def _handle_memory(line: str, memory_loader: MemoryLoader) -> None:
    """Handle /memory command to recall previous sessions."""
    parts = line.split()
    
    if len(parts) == 1:
        # /memory - show summaries of recent sessions
        print("Recent sessions (summaries):")
        summaries = memory_loader.get_session_summaries(limit=5)
        if not summaries:
            print("  (no sessions found)")
            return
        for i, summary in enumerate(summaries, 1):
            metadata = summary.get("metadata", {})
            print(f"  {i}. {metadata.get('started_at', '?')} | {metadata.get('turns', '?')} turns | {metadata.get('tags', [])}")
        return
    
    if len(parts) == 2 and parts[1] == "full":
        # /memory full - show full content of most recent
        session_data = memory_loader.get_session_full(1)
        if not session_data:
            print("No session found")
            return
        print(memory_loader.format_session_display(session_data, full=True))
        return
    
    if len(parts) == 2:
        # /memory N - load specific session summary
        try:
            session_num = int(parts[1])
        except ValueError:
            print("usage: /memory [N] [full]")
            return
        session_data = memory_loader.get_session_full(session_num)
        if not session_data:
            print(f"Session #{session_num} not found")
            return
        print(memory_loader.format_session_display(session_data, full=False))
        return
    
    if len(parts) == 3 and parts[2] == "full":
        # /memory N full - load specific session full
        try:
            session_num = int(parts[1])
        except ValueError:
            print("usage: /memory N [full]")
            return
        session_data = memory_loader.get_session_full(session_num)
        if not session_data:
            print(f"Session #{session_num} not found")
            return
        print(memory_loader.format_session_display(session_data, full=True))
        return
    
    print("usage: /memory [N] [full]")



def _handle_tools(
    line: str,
    cwd: Path,
    cfg: Dict[str, Any],
    repo_root: Path,
    store: ProposalStore,
    quiet: bool = False,
) -> Optional[Dict[str, Any]]:
    """Handle /bh subcommands: list, propose, approve, reject.

    Returns a dict {"command": str, "result": dict} when a proposal is
    approved and executed — so the caller can inject it into the AI history.
    Returns None for all other subcommands.
    """
    parts = line.split(maxsplit=2)
    if len(parts) < 2:
        if not quiet:
            print("usage: /bh <subcommand> [args]")
            print("       /bh list [N]")
            print("       /bh history [N]")
            print("       /bh propose <command>")
            print("       /bh approve <id>")
            print("       /bh reject <id> [reason]")
        return

    subcommand = parts[1].lower()

    if subcommand == "list":
        limit = 25
        if len(parts) >= 3:
            try:
                limit = max(1, min(int(parts[2]), 200))
            except Exception:
                limit = 25
        rows = store.list_pending(limit=limit)
        if not rows:
            if not quiet:
                print("No pending proposals.")
            return
        for row in rows:
            if not quiet:
                print(f"#{row['id']} [{row['risk_level']}] {row['command_text']} :: {row['operator_summary']}")
        return

    if subcommand == "history":
        limit = 25
        if len(parts) >= 3:
            try:
                limit = max(1, min(int(parts[2]), 200))
            except Exception:
                limit = 25
        rows = store.list_recent(limit=limit)
        if not rows:
            if not quiet:
                print("No proposal history yet.")
            return
        for row in rows:
            exit_code = row.get("exit_code")
            exit_text = "" if exit_code is None else f" exit={exit_code}"
            if not quiet:
                print(f"#{row['id']} [{row['status']}] [{row['risk_level']}] {row['command_text']}{exit_text}")
        return

    if subcommand == "propose":
        if len(parts) < 3:
            if not quiet:
                print("usage: /bh propose <command>")
            return
        command = parts[2]
        check = _validate_command(command, cwd, cfg, repo_root)
        if not check.get("ok"):
            if not quiet:
                print(f"blocked: {check.get('error')}")
            return
        proposal_id = store.create_proposal(
            command_text=command,
            cwd=str(cwd),
            risk_level=_risk_level(command),
            operator_summary="manual proposal from shell",
            metadata={"source": "agent_shell"},
        )
        if not quiet:
            print(f"[proposed #{proposal_id}] {command}")
        return {"event": "proposed", "id": proposal_id, "command": command}

    if subcommand == "approve":
        if len(parts) < 3:
            if not quiet:
                print("usage: /bh approve <id>")
            return
        try:
            proposal_id = int(parts[2])
        except Exception:
            if not quiet:
                print("invalid id")
            return

        match = store.get_proposal(proposal_id)
        if not match:
            if not quiet:
                print("proposal not found")
            return

        status = str(match.get("status", "")).strip().lower()
        if status not in {"proposed", "approved"}:
            if not quiet:
                print(f"proposal #{proposal_id} is not executable (status={status or 'unknown'})")
            return

        command = str(match["command_text"])
        check = _validate_command(command, cwd, cfg, repo_root)
        if not check.get("ok"):
            if not quiet:
                print(f"blocked: {check.get('error')}")
            store.reject(proposal_id, reason=str(check.get("error")), rejected_by="guard")
            return

        if status == "proposed" and not store.approve(proposal_id, approved_by="operator"):
            if not quiet:
                print("unable to approve proposal")
            return

        exec_result = _execute(check["tokens"], cwd, cfg)
        store.mark_executed(
            proposal_id,
            exit_code=int(exec_result["exit_code"]),
            stdout_preview=str(exec_result["stdout"]),
            stderr_preview=str(exec_result["stderr"]),
            executed_by="operator",
        )
        if not quiet:
            print(f"Executed #{proposal_id} exit={exec_result['exit_code']}")
            if exec_result["stdout"]:
                print(exec_result["stdout"].rstrip())
            if exec_result["stderr"]:
                print(exec_result["stderr"].rstrip())
        return {"event": "executed", "id": proposal_id, "command": command, "result": exec_result}

    if subcommand == "reject":
        # Parse: /bh reject <id> [reason]
        # parts[2] may be "3" or "3 too risky" after split(maxsplit=2)
        if len(parts) < 3:
            if not quiet:
                print("usage: /bh reject <id> [reason]")
            return
        # Re-split the arguments part to handle optional reason
        args = parts[2].split(maxsplit=1)
        if not args:
            if not quiet:
                print("usage: /bh reject <id> [reason]")
            return
        try:
            proposal_id = int(args[0])
        except Exception:
            if not quiet:
                print("invalid id")
            return
        reason = args[1] if len(args) >= 2 else ""
        if store.reject(proposal_id, reason=reason, rejected_by="operator"):
            if not quiet:
                print(f"Rejected proposal #{proposal_id}")
            return {"event": "rejected", "id": proposal_id, "reason": reason}
        else:
            if not quiet:
                print("proposal not found or not pending")
        return

    if subcommand == "top":
        limit = 20
        if len(parts) >= 3:
            try:
                limit = max(1, min(int(parts[2]), 100))
            except Exception:
                limit = 20
        rows = store.get_top_executed(limit=limit)
        if not rows:
            if not quiet:
                print("No executed commands yet.")
            return
        if not quiet:
            print(f"Top {len(rows)} most-used executed commands:")
            for i, row in enumerate(rows, 1):
                print(f"  {i:2}. [{row['exec_count']:3}x] {row['command']}")
        return

    if subcommand == "search":
        if len(parts) < 3:
            if not quiet:
                print("usage: /bh search <pattern>")
            return
        pattern = parts[2]
        # Try exact match first
        exact = store.find_exact_duplicates(pattern)
        if exact:
            if not quiet:
                print(f"Exact duplicates of: {pattern}")
                for row in exact:
                    print(f"  #{row['id']} [{row['status']}] {row['command_text']}")
                print()
        
        # Then show similar commands
        similar = store.find_similar(pattern, limit=20, min_ratio=0.4)
        if similar:
            if not quiet:
                print(f"Similar approved/executed commands:")
                for row, ratio in similar:
                    pct = int(ratio * 100)
                    print(f"  {pct:3}% #{row['id']} [{row['status']}] {row['command_text']}")
        elif not exact:
            if not quiet:
                print("No matching or similar commands found.")
        return

    if subcommand == "check":
        if len(parts) < 3:
            if not quiet:
                print("usage: /bh check <command>")
            return
        command = parts[2]
        
        # Check for exact duplicates
        exact = store.find_exact_duplicates(command)
        if exact:
            if not quiet:
                print(f"⚠ DUPLICATE FOUND: This command was already executed/approved:")
                for row in exact:
                    print(f"    #{row['id']} [{row['status']}] exit={row['exit_code']}")
            return
        
        # Check for similar commands
        similar = store.find_similar(command, limit=5, min_ratio=0.6)
        if similar:
            if not quiet:
                print(f"Similar commands that were already approved/executed:")
                for row, ratio in similar:
                    pct = int(ratio * 100)
                    print(f"    {pct:3}% #{row['id']} [{row['status']}] {row['command_text']}")
        else:
            if not quiet:
                print("✓ No duplicates or very similar commands found.")
        return

    if not quiet:
        print(f"unknown /bh subcommand: {subcommand}")


def _default_private_root() -> Path:
    return Path(os.environ.get("APP_PRIVATE_ROOT", "/web/private"))


def _resolve_task_queue_dir(settings: Dict[str, Any]) -> Path:
    task_cfg = settings.get("task_queue", {}) if isinstance(settings.get("task_queue", {}), dict) else {}
    configured = str(task_cfg.get("dir", "") or "").strip()
    if configured:
        return Path(configured)
    return _default_private_root() / "guardian_tasks"


def _claim_next_task_file(task_dir: Path, claim_glob: str = "*.json") -> Optional[Path]:
    task_dir.mkdir(parents=True, exist_ok=True)
    processing_dir = task_dir / "processing"
    processing_dir.mkdir(parents=True, exist_ok=True)
    for candidate in sorted(task_dir.glob(claim_glob)):
        if not candidate.is_file():
            continue
        if candidate.parent != task_dir:
            continue
        claimed = processing_dir / (candidate.name + ".working")
        try:
            candidate.replace(claimed)
            return claimed
        except Exception:
            continue
    return None


def _load_task_request(task_file: Path) -> Dict[str, Any]:
    raw = json.loads(task_file.read_text(encoding="utf-8"))
    if not isinstance(raw, dict):
        raise ValueError("task file must contain a JSON object")
    return raw


def _finalize_task_file(task_file: Path, result: Dict[str, Any], success: bool) -> None:
    processing_dir = task_file.parent
    queue_dir = processing_dir.parent if processing_dir.name == "processing" else processing_dir
    final_dir = queue_dir / ("done" if success else "failed")
    final_dir.mkdir(parents=True, exist_ok=True)
    base_name = task_file.name[:-8] if task_file.name.endswith(".working") else task_file.name
    target_task = final_dir / base_name
    target_result = final_dir / (base_name + ".result.json")
    task_file.replace(target_task)
    target_result.write_text(json.dumps(result, ensure_ascii=True, indent=2) + "\n", encoding="utf-8")


def _init_runtime(
    repo_root: Path,
    provider_override: str = "",
) -> Dict[str, Any]:
    settings = load_tool_settings()
    cfg = _bash_cfg(settings)
    shell_cfg = settings.get("shell", {}) if isinstance(settings.get("shell", {}), dict) else {}
    editor_cfg = settings.get("editor", {}) if isinstance(settings.get("editor", {}), dict) else {}
    compose_cfg = settings.get("compose", {}) if isinstance(settings.get("compose", {}), dict) else {}
    turn_cfg = settings.get("turn_generator", {}) if isinstance(settings.get("turn_generator", {}), dict) else {}
    store = _store_from_settings(settings, repo_root)

    logs_dir = repo_root / "logs"
    logs_dir.mkdir(parents=True, exist_ok=True)
    logger = SessionLogger(logs_dir, host=os.uname()[1])
    session_file = logger.start_session()

    notes_dir = repo_root / "notes"
    notes_handler = NotesHandler(notes_dir)
    memory_loader = MemoryLoader(logs_dir)
    runtime_details = _collect_runtime_access_details()
    runtime_access = runtime_details["access"]

    slug, entry_cfg, provider_label, active_idx, providers_list = get_active_provider_entry(settings)
    cli_provider = str(provider_override or "").strip() or os.environ.get("ROOT_GUARDIAN_PROVIDER", "").strip()
    if cli_provider:
        active_idx, entry_cfg = resolve_provider_by_ref(providers_list, cli_provider)
        slug = str(entry_cfg.get("name", slug))
        provider_label = str(entry_cfg.get("label", slug))
    provider_name = slug
    provider_cfg = {slug: entry_cfg}
    try:
        max_history_messages = int(turn_cfg.get("max_history_messages", 20) or 20)
    except Exception:
        max_history_messages = 20
    max_history_messages = max(1, max_history_messages)
    usage_cfg = turn_cfg.get("usage_tracking", {}) if isinstance(turn_cfg.get("usage_tracking", {}), dict) else {}
    try:
        chars_per_token = float(usage_cfg.get("chars_per_token", 4.0) or 4.0)
    except Exception:
        chars_per_token = 4.0
    if chars_per_token <= 0:
        chars_per_token = 4.0

    turn_gen: Optional[TurnGenerator] = None
    provider_init_error = ""
    try:
        turn_gen = TurnGenerator(
            provider_name,
            provider_cfg,
            runtime_access_context=runtime_details["context"],
            extra_system_context=_build_prompt_context(cfg, store),
            max_history_messages=max_history_messages,
            chars_per_token=chars_per_token,
        )
    except Exception as exc:
        provider_init_error = str(exc)

    provider_status = "disabled"
    model_name = "unknown"
    provider_endpoint = "n/a"
    hello_status = "[FAIL] provider unavailable"
    if turn_gen is not None:
        provider_status = "online" if turn_gen.is_available else "offline"
        model_name = turn_gen.provider_model
        provider_endpoint = turn_gen.provider_endpoint
        ok, detail = turn_gen.hello_check()
        prefix = "OK" if ok else "FAIL"
        hello_status = f"[{prefix}] {detail}"

    debug_default = bool(shell_cfg.get("debug_default", False))
    debug_enabled = os.environ.get("ROOT_GUARDIAN_DEBUG", "1" if debug_default else "0") == "1"
    try:
        max_turns_default = int(shell_cfg.get("max_turns_per_session", 11) or 11)
        max_turns = max(1, int(os.environ.get("ROOT_GUARDIAN_MAX_TURNS", str(max_turns_default))))
    except Exception:
        max_turns = 11
    preferred_editor = str(editor_cfg.get("command", "") or "")
    editor_candidates = editor_cfg.get("candidates", [])
    active_editor = get_available_editor(preferred=preferred_editor, candidates=editor_candidates if isinstance(editor_candidates, list) else None)
    try:
        blocked_tool_retry_limit = int(shell_cfg.get("blocked_tool_retry_limit", 2) or 2)
    except Exception:
        blocked_tool_retry_limit = 2
    blocked_tool_retry_limit = max(0, blocked_tool_retry_limit)

    return {
        "settings": settings,
        "cfg": cfg,
        "shell_cfg": shell_cfg,
        "compose_cfg": compose_cfg,
        "store": store,
        "logger": logger,
        "session_file": session_file,
        "notes_handler": notes_handler,
        "memory_loader": memory_loader,
        "runtime_details": runtime_details,
        "runtime_access": runtime_access,
        "provider_name": provider_name,
        "provider_label": provider_label,
        "provider_cfg": provider_cfg,
        "active_idx": active_idx,
        "providers_list": providers_list,
        "max_history_messages": max_history_messages,
        "usage_chars_per_token": chars_per_token,
        "turn_gen": turn_gen,
        "provider_status": provider_status,
        "model_name": model_name,
        "provider_endpoint": provider_endpoint,
        "hello_status": hello_status,
        "provider_init_error": provider_init_error,
        "debug_enabled": debug_enabled,
        "max_turns": max_turns,
        "active_editor": active_editor,
        "blocked_tool_retry_limit": blocked_tool_retry_limit,
    }


def _run_non_interactive(
    prompt: str,
    context: Optional[str] = None,
    provider_override: str = "",
    output_json: bool = False,
    task_file: Optional[Path] = None,
    max_turns_override: Optional[int] = None,
) -> Any:
    repo_root = Path(__file__).resolve().parent
    runtime = _init_runtime(repo_root, provider_override=provider_override)
    logger = runtime["logger"]
    turn_gen = runtime["turn_gen"]
    store = runtime["store"]
    cfg = runtime["cfg"]
    max_turns = int(runtime["max_turns"])
    if max_turns_override is not None:
        try:
            max_turns = max(1, int(max_turns_override))
        except Exception:
            max_turns = int(runtime["max_turns"])
    debug_enabled = bool(runtime["debug_enabled"])
    blocked_tool_retry_limit = int(runtime["blocked_tool_retry_limit"])
    cwd = repo_root

    result: Dict[str, Any] = {
        "mode": "non_interactive",
        "status": "error",
        "prompt": prompt,
        "context_bytes": len(context or ""),
        "session_file": str(runtime["session_file"]),
        "provider": {
            "name": runtime["provider_name"],
            "label": runtime["provider_label"],
            "model": runtime["model_name"],
            "endpoint": runtime["provider_endpoint"],
            "status": runtime["provider_status"],
            "hello": runtime["hello_status"],
        },
        "turn_count": 0,
        "executed_tools": [],
        "final_answer": None,
        "ask_user": None,
        "summary": "",
        "confidence": None,
        "usage": {},
    }
    if task_file is not None:
        result["task_file"] = str(task_file)

    try:
        if turn_gen is None:
            result["error"] = "provider unavailable"
            if runtime["provider_init_error"]:
                result["provider_error"] = runtime["provider_init_error"]
            if output_json:
                print(json.dumps(result, ensure_ascii=True, indent=2))
            else:
                print("Provider unavailable")
                if runtime["provider_init_error"]:
                    print(runtime["provider_init_error"])
            return 1, result

        turn_count = 0
        current_prompt = prompt
        current_context = context

        while True:
            try:
                raw_contract = turn_gen.generate_contract(current_prompt, context=current_context)
            except RuntimeError as exc:
                result["error"] = str(exc)
                if output_json:
                    print(json.dumps(result, ensure_ascii=True, indent=2))
                else:
                    print(f"[Provider error]: {exc}")
                return 1, result
            except ValueError as exc:
                result["error"] = str(exc)
                if output_json:
                    print(json.dumps(result, ensure_ascii=True, indent=2))
                else:
                    print(f"[Parse error]: {exc}")
                return 1, result

            current_context = None
            turn_count += 1
            outcome = _process_contract(
                raw_contract,
                turn_count=turn_count,
                max_turns=max_turns,
                debug_enabled=debug_enabled,
                cwd=cwd,
                cfg=cfg,
                repo_root=repo_root,
                store=store,
                render_output=not output_json,
            )
            logger.add_turn(
                turn_count=turn_count,
                contract=raw_contract,
                tags=["ai", "non-interactive"],
            )

            blocked_retries = 0
            while (
                outcome.get("blocked_error")
                and not outcome.get("proposal_id")
                and blocked_retries < blocked_tool_retry_limit
            ):
                blocked_retries += 1
                blocked_error = str(outcome.get("blocked_error") or "tool blocked")
                blocked_command = str((outcome.get("contract") or {}).get("next_tool") or "")
                try:
                    raw_contract = turn_gen.generate_contract(
                        _blocked_tool_retry_prompt(blocked_command, blocked_error)
                    )
                except RuntimeError as exc:
                    result["error"] = str(exc)
                    if output_json:
                        print(json.dumps(result, ensure_ascii=True, indent=2))
                    else:
                        print(f"[Provider error]: {exc}")
                    return 1, result
                except ValueError as exc:
                    result["error"] = str(exc)
                    if output_json:
                        print(json.dumps(result, ensure_ascii=True, indent=2))
                    else:
                        print(f"[Parse error]: {exc}")
                    return 1, result

                outcome = _process_contract(
                    raw_contract,
                    turn_count=turn_count,
                    max_turns=max_turns,
                    debug_enabled=debug_enabled,
                    cwd=cwd,
                    cfg=cfg,
                    repo_root=repo_root,
                    store=store,
                    render_output=not output_json,
                )
                logger.add_turn(
                    turn_count=turn_count,
                    contract=raw_contract,
                    tags=["ai", "retry", "non-interactive"],
                )

            while outcome.get("proposal_id"):
                proposal_id = int(outcome["proposal_id"])
                tool_result = _handle_tools(f"/bh approve {proposal_id}", cwd, cfg, repo_root, store, quiet=output_json)
                if not tool_result or tool_result.get("event") != "executed":
                    result["error"] = "failed to auto-execute proposal"
                    if output_json:
                        print(json.dumps(result, ensure_ascii=True, indent=2))
                    else:
                        print("[Auto-execute error] failed to execute proposal")
                    return 1, result

                _record_tool_event(logger, tool_result)
                turn_gen.set_extra_system_context(_build_prompt_context(cfg, store))
                turn_gen.inject_tool_result(tool_result["command"], tool_result["result"])
                exec_payload = {
                    "proposal_id": proposal_id,
                    "command": str(tool_result["command"]),
                    "exit_code": tool_result["result"].get("exit_code"),
                    "stdout": str(tool_result["result"].get("stdout", "")),
                    "stderr": str(tool_result["result"].get("stderr", "")),
                    "auto_history_reuse": bool(outcome.get("auto_execute")),
                }
                result["executed_tools"].append(exec_payload)

                try:
                    raw_contract = turn_gen.generate_contract(
                        "Tool executed automatically in non-interactive mode. Continue analysis using that result."
                    )
                except RuntimeError as exc:
                    result["error"] = str(exc)
                    if output_json:
                        print(json.dumps(result, ensure_ascii=True, indent=2))
                    else:
                        print(f"[Provider error]: {exc}")
                    return 1, result
                except ValueError as exc:
                    result["error"] = str(exc)
                    if output_json:
                        print(json.dumps(result, ensure_ascii=True, indent=2))
                    else:
                        print(f"[Parse error]: {exc}")
                    return 1, result

                turn_count += 1
                outcome = _process_contract(
                    raw_contract,
                    turn_count=turn_count,
                    max_turns=max_turns,
                    debug_enabled=debug_enabled,
                    cwd=cwd,
                    cfg=cfg,
                    repo_root=repo_root,
                    store=store,
                    render_output=not output_json,
                )
                logger.add_turn(
                    turn_count=turn_count,
                    contract=raw_contract,
                    tags=["ai", "tool-loop", "non-interactive"],
                )

            contract = outcome.get("contract", {}) if isinstance(outcome.get("contract"), dict) else {}
            result["turn_count"] = turn_count
            result["summary"] = str(contract.get("summary", "") or "")
            result["confidence"] = contract.get("confidence")
            result["ask_user"] = contract.get("ask_user")
            result["final_answer"] = contract.get("final_answer")
            result["state"] = contract.get("state")
            result["usage"] = turn_gen.last_usage if turn_gen is not None else {}

            if outcome.get("is_final"):
                result["status"] = "final"
                break
            if contract.get("state") == "needs_input":
                result["status"] = "needs_input"
                break
            current_prompt = "Continue."

        if output_json:
            print(json.dumps(result, ensure_ascii=True, indent=2))
        else:
            if result.get("final_answer"):
                print(str(result["final_answer"]))
            elif result.get("ask_user"):
                print(str(result["ask_user"]))
        return (0 if result["status"] == "final" else 2), result
    finally:
        try:
            logger.end_session("Non-interactive session ended.")
        except Exception:
            pass


def _record_tool_event(logger: SessionLogger, tool_result: Dict[str, Any]) -> None:
    event = str(tool_result.get("event", "")).strip().lower()
    if event == "proposed":
        pid = tool_result.get("id")
        cmd = str(tool_result.get("command", ""))
        logger.add_event(
            f"/bh proposed #{pid}: {cmd}",
            action=f"Proposed #{pid}: {cmd}",
            tags=["tools"],
        )
    elif event == "rejected":
        pid = tool_result.get("id")
        reason = str(tool_result.get("reason", "")).strip() or "no reason"
        logger.add_event(
            f"/bh rejected #{pid}: {reason}",
            action=f"Rejected #{pid}",
            tags=["tools"],
        )
    elif event == "executed":
        pid = tool_result.get("id")
        cmd = str(tool_result.get("command", ""))
        result = tool_result.get("result", {}) if isinstance(tool_result.get("result"), dict) else {}
        exit_code = result.get("exit_code")
        logger.add_event(
            f"/bh approved+executed #{pid}: {cmd} (exit={exit_code})",
            action=f"Executed #{pid}: {cmd}",
            tags=["tools", "executed"],
        )


def _print_usage_summary(usage: Dict[str, Any], show: bool = True) -> None:
    if not show or not usage:
        return
    prompt_tokens = int(usage.get("estimated_prompt_tokens", 0) or 0)
    output_tokens = int(usage.get("estimated_output_tokens", 0) or 0)
    total_tokens = int(usage.get("estimated_total_tokens", 0) or 0)
    history_messages = int(usage.get("history_messages", 0) or 0)
    max_history_messages = int(usage.get("max_history_messages", 0) or 0)
    input_chars = int(usage.get("input_chars", 0) or 0)
    output_chars = int(usage.get("output_chars", 0) or 0)
    system_chars = int(usage.get("system_chars", 0) or 0)
    print(
        "[Usage] "
        + f"prompt~{prompt_tokens} tok, output~{output_tokens} tok, total~{total_tokens} tok"
        + f" | history {history_messages}/{max_history_messages}"
        + f" | chars in={input_chars} sys={system_chars} out={output_chars}"
    )


def _reset_after_max_turns(logger: SessionLogger, turn_gen: Optional[TurnGenerator]) -> int:
    logger.add_event(
        "Conversation reached max turns; resetting active AI conversation and keeping shell open.",
        action="Reset conversation after max turns",
        tags=["turn-limit"],
    )
    if turn_gen is not None:
        turn_gen.reset()
    print("conversation reached max turns; AI context was reset and the shell will stay open")
    print("start a fresh request, or use /memory 1 if you want to resume from the saved session memory")
    return 0


def _blocked_tool_retry_prompt(command: str, error: str) -> str:
    command_text = str(command or "").strip()
    error_text = str(error or "tool blocked").strip()
    return (
        "Your previous proposed tool was not allowed by the shell guard.\n"
        + "Blocked command: "
        + (command_text or "(none)")
        + "\n"
        + "Reason: "
        + error_text
        + "\n"
        + "Please try again with exactly one different allowed shell command."
        + " No pipes, redirects, chaining, or sudo."
        + " If no safe single command fits, set state=needs_input and ask a concise question."
    )


def _ask_tool_decision(proposal_id: int, command: str, session: Optional[PromptSession]) -> str:
    """Ask operator to accept/reject/skip an AI-proposed tool call."""
    print(f"\n[Tool proposal #{proposal_id}] {command}")
    print("Decision: (a)ccept, (r)eject, (s)kip")
    if not sys.stdin.isatty():
        print("Non-interactive mode: leaving proposal pending.")
        return "skip"

    prompt = "approve? [a/r/s]: "
    while True:
        if session is not None:
            try:
                raw = session.prompt(prompt)
            except (EOFError, KeyboardInterrupt):
                return "skip"
        else:
            try:
                raw = input(prompt)
            except (EOFError, KeyboardInterrupt):
                return "skip"

        choice = str(raw or "").strip().lower()
        if choice in {"a", "accept", "y", "yes"}:
            return "accept"
        if choice in {"r", "reject", "n", "no"}:
            return "reject"
        if choice in {"s", "skip", "", "later"}:
            return "skip"
        print("Please enter a, r, or s.")




def run_shell() -> int:
    repo_root = Path(__file__).resolve().parent
    runtime = _init_runtime(repo_root)
    settings = runtime["settings"]
    cfg = runtime["cfg"]
    compose_cfg = runtime["compose_cfg"]
    turn_cfg = settings.get("turn_generator", {}) if isinstance(settings.get("turn_generator", {}), dict) else {}
    store = runtime["store"]
    logger = runtime["logger"]
    session_file = runtime["session_file"]
    print(f"Session log (written on activity): {session_file}")
    notes_handler = runtime["notes_handler"]
    memory_loader = runtime["memory_loader"]
    runtime_details = runtime["runtime_details"]
    runtime_access = runtime["runtime_access"]
    provider_name = runtime["provider_name"]
    provider_label = runtime["provider_label"]
    active_idx = runtime["active_idx"]
    providers_list = runtime["providers_list"]
    max_history_messages = runtime["max_history_messages"]
    turn_gen = runtime["turn_gen"]
    if turn_gen is None and runtime["provider_init_error"]:
        print(f"warning: provider init failed ({runtime['provider_init_error']}). Natural language disabled.")
    cwd = repo_root
    debug_enabled = runtime["debug_enabled"]
    turn_count = 0
    composed_context: Optional[str] = None  # set by /compose, consumed on next AI turn
    max_turns = runtime["max_turns"]
    active_editor = runtime["active_editor"]
    blocked_tool_retry_limit = runtime["blocked_tool_retry_limit"]
    usage_cfg = turn_cfg.get("usage_tracking", {}) if isinstance(turn_cfg.get("usage_tracking", {}), dict) else {}
    show_usage_after_turn = bool(usage_cfg.get("enabled", True)) and bool(usage_cfg.get("show_after_turn", True))
    _print_welcome_banner(
        runtime_details["hostname"],
        runtime_details["uname"],
        runtime_access,
        provider_name,
        runtime["model_name"],
        runtime["provider_endpoint"],
        runtime["provider_status"],
        runtime["hello_status"],
    )
    print("Root Guardian shell active. Type /help.")
    print("Type plain text to chat with AI, or /help for commands.")
    _setup_readline(session_file.parent / "guardian_shell_history.log")
    
    # Set up prompt session with autocomplete (optional, falls back to input() if unavailable)
    prompt_session = _setup_prompt_session()
    
    try:
        while True:
            try:
                line = _read_user_input("guardian 🛡️ > ", session=prompt_session).strip()
            except (EOFError, KeyboardInterrupt):
                print()
                logger.end_session("Session ended by user.")
                return 0

            if not line:
                continue

            from_paste_mode = False
            if line.lower() == "/paste":
                pasted = _read_multiline_query()
                if not pasted:
                    continue
                line = pasted
                from_paste_mode = True

            is_multiline_input = "\n" in line
            treat_as_command = (not from_paste_mode and not is_multiline_input)

            # Backward compatibility alias: /tools -> /bh
            if treat_as_command and line.startswith("/tools"):
                line = "/bh" + line[len("/tools"):]

            if treat_as_command and line == "/help":
                _show_help()
                continue
            if treat_as_command and line == "/status":
                _print_status(
                    cwd=cwd,
                    debug_enabled=debug_enabled,
                    turn_count=turn_count,
                    max_turns=max_turns,
                    runtime_access=runtime_access,
                    runtime_details=runtime_details,
                    turn_gen=turn_gen,
                    provider_name=provider_name,
                    active_idx=active_idx,
                    providers_list=providers_list,
                )
                continue
            if treat_as_command and (line == "/provider" or line.startswith("/provider ")):
                parts = line.split(maxsplit=1)
                sub = parts[1].strip() if len(parts) > 1 else ""
                if sub == "" or sub == "show":
                    _print_provider_banner(turn_gen, provider_name)
                    print(f"Active index: {active_idx} / {len(providers_list)} providers")
                elif sub == "list":
                    for i, p in enumerate(providers_list):
                        marker = "*" if i == active_idx else " "
                        p_slug = str(p.get("name", "?"))
                        p_label = str(p.get("label", p_slug))
                        p_model = str(p.get("model", "?"))
                        p_url = str(p.get("base_url", ""))
                        url_part = f"  {p_url}" if p_url else ""
                        print(f"  [{marker}{i}] {p_label} ({p_slug}) model={p_model}{url_part}")
                else:
                    # Switch provider by index or label
                    try:
                        new_idx, new_entry = resolve_provider_by_ref(providers_list, sub)
                        new_slug = str(new_entry.get("name", slug))
                        new_label = str(new_entry.get("label", new_slug))
                        if turn_gen is not None:
                            turn_gen.switch_provider(new_slug, new_entry)
                            active_idx = new_idx
                            provider_name = new_slug
                            provider_label = new_label
                            model_name = turn_gen.provider_model
                            provider_endpoint = turn_gen.provider_endpoint
                            print(f"Switched to provider [{new_idx}] {new_label} model={model_name}")
                        else:
                            # Provider was disabled; try to init fresh
                            try:
                                turn_gen = TurnGenerator(
                                    new_slug,
                                    {new_slug: new_entry},
                                    runtime_access_context=runtime_details["context"],
                                    extra_system_context=_build_prompt_context(cfg, store),
                                    max_history_messages=max_history_messages,
                                    chars_per_token=runtime["usage_chars_per_token"],
                                )
                                active_idx = new_idx
                                provider_name = new_slug
                                provider_label = new_label
                                model_name = turn_gen.provider_model
                                provider_endpoint = turn_gen.provider_endpoint
                                print(f"Activated provider [{new_idx}] {new_label} model={model_name}")
                            except Exception as exc:
                                print(f"warning: failed to init provider {new_label!r}: {exc}")
                    except ValueError as exc:
                        print(f"error: {exc}")
                continue
            if treat_as_command and line == "/hello":
                if turn_gen is None:
                    print("Provider disabled. Check /provider and config/settings.default.json or /web/private/agent_settings.json.")
                    continue
                ok, detail = turn_gen.hello_check()
                prefix = "OK" if ok else "FAIL"
                print(f"[hello {prefix}] {detail}")
                continue
            if treat_as_command and line == "/debug":
                debug_enabled = not debug_enabled
                print(f"debug mode: {'on' if debug_enabled else 'off'}")
                continue
            if treat_as_command and line in {"/exit", "/quit"}:
                logger.end_session("Session ended by user.")
                return 0

            if treat_as_command and line.startswith("/contract "):
                if composed_context:
                    print(f"[Compose note] {len(composed_context)} bytes are queued for the next AI-generated turn; /contract does not consume compose context.")
                payload = line[len("/contract "):].strip()
                if not payload:
                    print("usage: /contract <json-object>")
                    continue
                try:
                    raw_contract = _load_contract_json(payload)
                except Exception as exc:
                    print(f"invalid contract json: {exc}")
                    continue
                turn_count += 1
                outcome = _process_contract(
                    raw_contract,
                    turn_count=turn_count,
                    max_turns=max_turns,
                    debug_enabled=debug_enabled,
                    cwd=cwd,
                    cfg=cfg,
                    repo_root=repo_root,
                    store=store,
                )
                # Log the turn
                logger.add_turn(
                    turn_count=turn_count,
                    contract=raw_contract,
                    tags=["interactive"],
                )
                is_final = bool(outcome.get("is_final"))
                if is_final:
                    if turn_count >= max_turns:
                        turn_count = _reset_after_max_turns(logger, turn_gen)
                        composed_context = None
                        continue
                    print("turn complete. ready for next request.")
                continue

            if treat_as_command and line.startswith("/bh"):
                tool_result = _handle_tools(line, cwd, cfg, repo_root, store)
                if tool_result:
                    _record_tool_event(logger, tool_result)
                # If a proposal was approved+executed, feed result back to AI history.
                if tool_result and tool_result.get("event") == "executed" and turn_gen:
                    turn_gen.set_extra_system_context(_build_prompt_context(cfg, store))
                    turn_gen.inject_tool_result(
                        tool_result["command"],
                        tool_result["result"],
                    )
                continue

            if treat_as_command and line.startswith("/compose"):
                boot_text: Optional[str] = None
                if "--bootstrap" in line:
                    boot_path = repo_root / "agent_bash_boot.md"
                    if boot_path.exists():
                        try:
                            boot_text = boot_path.read_text(encoding="utf-8")
                        except Exception:
                            boot_text = None
                edited = _handle_compose(
                    line,
                    initial_context=composed_context or "",
                    bootstrap_text=boot_text,
                    editor_cmd=active_editor,
                )
                if edited:
                    composed_context = edited
                    preview = edited.strip().replace("\n", " ")
                    preview_chars = int(compose_cfg.get("preview_chars", 160) or 160)
                    if preview_chars > 0 and len(preview) > preview_chars:
                        preview = preview[:preview_chars].rstrip() + "..."
                    logger.add_event(
                        message=f"/compose saved ({len(edited)} bytes)",
                        action="Compose context captured",
                        tags=["compose"],
                    )
                    logger.add_event(
                        message="compose_preview: " + preview,
                        tags=["compose"],
                    )
                    logger.add_artifact(
                        name="compose_input",
                        content=edited,
                        kind="compose",
                        metadata={
                            "command": line,
                            "bootstrap_included": "--bootstrap" in line,
                        },
                        tags=["compose", "memory"],
                    )
                    if turn_gen is None:
                        print(f"[Context ready — queued for the next AI-generated turn ({len(edited)} bytes)]")
                        continue
                    if bool(compose_cfg.get("auto_run", True)):
                        print(f"[Context ready — processing now ({len(edited)} bytes)]")
                        line = "Process the composed context and respond to it."
                        treat_as_command = False
                    else:
                        print(f"[Context ready — queued for the next AI-generated turn ({len(edited)} bytes)]")
                        continue
                else:
                    continue

            if treat_as_command and line == "/notes":
                _handle_notes(notes_handler, session_file, active_editor)
                continue

            if treat_as_command and line.startswith("/memory"):
                _handle_memory(line, memory_loader)
                continue

            if treat_as_command and line.startswith("/"):
                print("unknown command. type /help")
                continue

            # --- Natural language input → AI turn ---
            if turn_gen is None:
                print("No AI provider configured. Use /contract <json> to submit turns manually.")
                print("Check config/settings.default.json or /web/private/agent_settings.json.")
                continue

            ctx = composed_context
            composed_context = None  # consume once

            try:
                raw_contract = turn_gen.generate_contract(line, context=ctx)
            except RuntimeError as exc:
                print(f"[Provider error]: {exc}")
                continue
            except ValueError as exc:
                print(f"[Parse error]: {exc}")
                continue

            turn_count += 1
            outcome = _process_contract(
                raw_contract,
                turn_count=turn_count,
                max_turns=max_turns,
                debug_enabled=debug_enabled,
                cwd=cwd,
                cfg=cfg,
                repo_root=repo_root,
                store=store,
            )
            logger.add_turn(
                turn_count=turn_count,
                contract=raw_contract,
                tags=["ai", "compose"] if ctx else ["ai"],
            )
            if turn_gen is not None:
                _print_usage_summary(turn_gen.last_usage, show=show_usage_after_turn)
            is_final = bool(outcome.get("is_final"))

            blocked_retries = 0
            while (
                turn_gen is not None
                and outcome.get("blocked_error")
                and not outcome.get("proposal_id")
                and blocked_retries < blocked_tool_retry_limit
            ):
                blocked_retries += 1
                blocked_error = str(outcome.get("blocked_error") or "tool blocked")
                blocked_command = str((outcome.get("contract") or {}).get("next_tool") or "")
                print(f"[Guard]: Proposed tool not allowed: {blocked_error}. Asking Guardian to try again.")
                try:
                    raw_contract = turn_gen.generate_contract(
                        _blocked_tool_retry_prompt(blocked_command, blocked_error)
                    )
                except RuntimeError as exc:
                    print(f"[Provider error]: {exc}")
                    break
                except ValueError as exc:
                    print(f"[Parse error]: {exc}")
                    break

                outcome = _process_contract(
                    raw_contract,
                    turn_count=turn_count,
                    max_turns=max_turns,
                    debug_enabled=debug_enabled,
                    cwd=cwd,
                    cfg=cfg,
                    repo_root=repo_root,
                    store=store,
                )
                logger.add_turn(
                    turn_count=turn_count,
                    contract=raw_contract,
                    tags=["ai", "retry", "compose"] if ctx else ["ai", "retry"],
                )
                if turn_gen is not None:
                    _print_usage_summary(turn_gen.last_usage, show=show_usage_after_turn)
                is_final = bool(outcome.get("is_final"))

            # Inline approval loop for AI-proposed tools, with automatic continuation.
            while turn_gen is not None and outcome.get("proposal_id"):
                proposal_id = int(outcome["proposal_id"])
                proposed_command = str(outcome.get("proposed_command") or "")

                if outcome.get("auto_execute"):
                    tool_result = _handle_tools(f"/bh approve {proposal_id}", cwd, cfg, repo_root, store)
                    if tool_result:
                        _record_tool_event(logger, tool_result)
                        if tool_result.get("event") == "executed":
                            turn_gen.set_extra_system_context(_build_prompt_context(cfg, store))
                            turn_gen.inject_tool_result(tool_result["command"], tool_result["result"])
                            reason_text = str(outcome.get("auto_execute_reason") or "exact prior approval")
                            print(f"[auto-executed #{proposal_id}] {reason_text}")
                            followup_prompt = "Tool was auto-executed from exact approved history. Continue analysis using that result."
                        else:
                            break
                    else:
                        print(f"[auto-execute skipped #{proposal_id}] approval flow returned no result")
                        break
                else:
                    decision = _ask_tool_decision(proposal_id, proposed_command, prompt_session)

                    if decision == "accept":
                        tool_result = _handle_tools(f"/bh approve {proposal_id}", cwd, cfg, repo_root, store)
                        if tool_result:
                            _record_tool_event(logger, tool_result)
                            if tool_result.get("event") == "executed":
                                turn_gen.set_extra_system_context(_build_prompt_context(cfg, store))
                                turn_gen.inject_tool_result(tool_result["command"], tool_result["result"])
                        followup_prompt = "Tool executed by operator. Continue analysis using that result."
                    elif decision == "reject":
                        tool_result = _handle_tools(f"/bh reject {proposal_id} rejected by operator", cwd, cfg, repo_root, store)
                        if tool_result:
                            _record_tool_event(logger, tool_result)
                        turn_gen.inject_tool_result(
                            proposed_command,
                            {
                                "exit_code": "rejected",
                                "stdout": "",
                                "stderr": "operator rejected this proposed tool call",
                            },
                        )
                        followup_prompt = "Operator rejected the proposed tool. Continue without it and propose a safer alternative if needed."
                    else:
                        # Keep pending if skipped and return to prompt.
                        break

                try:
                    raw_contract = turn_gen.generate_contract(followup_prompt)
                except RuntimeError as exc:
                    print(f"[Provider error]: {exc}")
                    break
                except ValueError as exc:
                    print(f"[Parse error]: {exc}")
                    break

                turn_count += 1
                outcome = _process_contract(
                    raw_contract,
                    turn_count=turn_count,
                    max_turns=max_turns,
                    debug_enabled=debug_enabled,
                    cwd=cwd,
                    cfg=cfg,
                    repo_root=repo_root,
                    store=store,
                )
                logger.add_turn(
                    turn_count=turn_count,
                    contract=raw_contract,
                    tags=["ai", "tool-loop"],
                )
                if turn_gen is not None:
                    _print_usage_summary(turn_gen.last_usage, show=show_usage_after_turn)
                is_final = bool(outcome.get("is_final"))
                if is_final:
                    break

            if is_final:
                if turn_count >= max_turns:
                    turn_count = _reset_after_max_turns(logger, turn_gen)
                    composed_context = None
                    continue
                print("turn complete. ready for next request.")
    finally:
        # Ensure session log is written on any exit
        try:
            logger.end_session("Session ended (finally block).")
        except Exception as exc:
            print(f"warning: failed to finalize session log: {exc}")



def main() -> int:
    parser = argparse.ArgumentParser(description="Root Guardian local shell")
    parser.add_argument("--non-interactive", action="store_true", help="Run one prompt or one queued task without interactive shell")
    parser.add_argument("--debug", action="store_true", help="Start shell in debug mode")
    parser.add_argument("--max-turns", type=int, default=None, help="Turn limit for contract processing")
    parser.add_argument("--provider", default="", help="Select provider by index or label (e.g. 0, big, claude)")
    parser.add_argument("--prompt", default="", help="Prompt to run in non-interactive mode")
    parser.add_argument("--context-file", default="", help="Optional file whose contents are passed as extra context in non-interactive mode")
    parser.add_argument("--json", action="store_true", help="Emit machine-readable JSON in non-interactive mode")
    parser.add_argument("--task-file", default="", help="Process one task JSON file in non-interactive mode")
    parser.add_argument("--claim-task", action="store_true", help="Claim and process the next task JSON from the configured task queue")
    args = parser.parse_args()
    # Use environment variables to keep the run_shell signature stable for now.
    if args.debug:
        os.environ["ROOT_GUARDIAN_DEBUG"] = "1"
    if args.max_turns is not None:
        os.environ["ROOT_GUARDIAN_MAX_TURNS"] = str(max(1, args.max_turns))
    if args.provider:
        os.environ["ROOT_GUARDIAN_PROVIDER"] = args.provider
    if args.non_interactive:
        repo_root = Path(__file__).resolve().parent
        settings = load_tool_settings()
        task_file: Optional[Path] = None
        task_payload: Dict[str, Any] = {}
        if args.task_file:
            task_file = Path(args.task_file)
        elif args.claim_task:
            task_cfg = settings.get("task_queue", {}) if isinstance(settings.get("task_queue", {}), dict) else {}
            task_dir = _resolve_task_queue_dir(settings)
            claim_glob = str(task_cfg.get("claim_glob", "*.json") or "*.json")
            task_file = _claim_next_task_file(task_dir, claim_glob=claim_glob)
            if task_file is None:
                result = {
                    "mode": "non_interactive",
                    "status": "no_task",
                    "task_dir": str(task_dir),
                }
                if args.json:
                    print(json.dumps(result, ensure_ascii=True, indent=2))
                else:
                    print(f"No pending task files in {task_dir}")
                return 0
        if task_file is not None:
            try:
                task_payload = _load_task_request(task_file)
            except Exception as exc:
                failure = {"mode": "non_interactive", "status": "error", "error": str(exc), "task_file": str(task_file)}
                try:
                    _finalize_task_file(task_file, failure, success=False)
                except Exception:
                    pass
                if args.json:
                    print(json.dumps(failure, ensure_ascii=True, indent=2))
                else:
                    print(f"Task load error: {exc}")
                return 1

        prompt = str(task_payload.get("prompt", "") or args.prompt or "").strip()
        if not prompt:
            print("non-interactive mode requires --prompt, --task-file, or --claim-task")
            return 1
        context = None
        if args.context_file:
            context = Path(args.context_file).read_text(encoding="utf-8")
        elif isinstance(task_payload.get("context"), str):
            context = str(task_payload.get("context"))
        elif isinstance(task_payload.get("context_file"), str) and str(task_payload.get("context_file")).strip():
            context = Path(str(task_payload.get("context_file"))).read_text(encoding="utf-8")
        provider_override = str(task_payload.get("provider", "") or args.provider or "").strip()
        task_max_turns = task_payload.get("max_turns")
        exit_code, result_payload = _run_non_interactive(
            prompt=prompt,
            context=context,
            provider_override=provider_override,
            output_json=bool(args.json),
            task_file=task_file,
            max_turns_override=task_max_turns,
        )
        if task_file is not None:
            result_payload["task_status"] = "completed" if exit_code == 0 else ("needs_input" if exit_code == 2 else "error")
            try:
                _finalize_task_file(task_file, result_payload, success=(exit_code == 0))
            except Exception:
                pass
        return exit_code
    return run_shell()


if __name__ == "__main__":
    raise SystemExit(main())
