#!/usr/bin/env python3
from __future__ import annotations

import os
import shlex
import shutil
import sqlite3
import subprocess
from pathlib import Path
from typing import Any, Dict, List

from proposal_store import ProposalStore


def bash_cfg(settings: Dict[str, Any]) -> Dict[str, Any]:
    raw = settings.get("bash", {})
    return raw if isinstance(raw, dict) else {}


def resolve_path(base_dir: Path, value: str) -> Path:
    p = Path(value)
    if p.is_absolute():
        return p.resolve()
    return (base_dir / p).resolve()


def allowed_roots(cfg: Dict[str, Any], base_dir: Path) -> List[Path]:
    raw = cfg.get("allowed_roots", [])
    if not isinstance(raw, list):
        return []
    roots: List[Path] = []
    for item in raw:
        text = str(item or "").strip()
        if not text:
            continue
        roots.append(resolve_path(base_dir, text))
    return roots


def is_under_roots(path: Path, roots: List[Path]) -> bool:
    for root in roots:
        if path == root:
            return True
        if str(path).startswith(str(root).rstrip("/") + os.sep):
            return True
    return False


def validate_command(command: str, cwd: Path, cfg: Dict[str, Any], repo_root: Path) -> Dict[str, Any]:
    raw = (command or "").strip()
    if not raw:
        return {"ok": False, "error": "empty command"}

    max_len = int(cfg.get("max_command_length", 1200) or 1200)
    if len(raw) > max_len:
        return {"ok": False, "error": f"command too long ({len(raw)} > {max_len})"}

    blocked = cfg.get("blocked_tokens", [])
    disallowed_tokens = cfg.get("disallowed_tokens", [])
    merged_blocked: List[str] = []
    if isinstance(blocked, list):
        merged_blocked.extend([str(x) for x in blocked])
    if isinstance(disallowed_tokens, list):
        merged_blocked.extend([str(x) for x in disallowed_tokens])
    for token in merged_blocked:
        token_text = str(token or "").strip()
        if token_text and token_text in raw:
            return {"ok": False, "error": f"blocked token detected: {token_text}"}

    try:
        tokens = shlex.split(raw)
    except Exception as exc:
        return {"ok": False, "error": f"invalid shell syntax: {exc}"}

    if not tokens:
        return {"ok": False, "error": "no command tokens"}

    cmd_name = tokens[0].lower()
    allowed_commands = cfg.get("allowed_commands", [])
    if not isinstance(allowed_commands, list):
        allowed_commands = []
    allowed_set = {str(x).strip().lower() for x in allowed_commands if str(x).strip()}
    if cmd_name not in allowed_set:
        return {"ok": False, "error": f"command not allowlisted: {cmd_name}"}

    disallowed_commands = cfg.get("disallowed_commands", [])
    disallowed_set = {str(x).strip().lower() for x in disallowed_commands if str(x).strip()} if isinstance(disallowed_commands, list) else set()
    if cmd_name in disallowed_set:
        return {"ok": False, "error": f"command disallowed by policy: {cmd_name}"}

    root_list = allowed_roots(cfg, repo_root)
    if not is_under_roots(cwd, root_list):
        return {"ok": False, "error": f"cwd outside allowed roots: {cwd}"}

    for token in tokens[1:]:
        t = token.strip()
        if not t:
            continue
        if t.startswith("-"):
            continue
        if "*" in t or "?" in t:
            return {"ok": False, "error": f"wildcards are not expanded in this harness: {t}"}
        if "://" in t:
            return {"ok": False, "error": f"url-like token blocked: {t}"}

        is_path = t.startswith("/") or t.startswith("./") or t.startswith("../")
        if not is_path:
            continue

        resolved = resolve_path(cwd, t)
        if not is_under_roots(resolved, root_list):
            return {"ok": False, "error": f"path outside allowed roots: {resolved}"}

    return {"ok": True, "tokens": tokens}


def risk_level(command: str) -> str:
    text = command.lower()
    if "journalctl" in text or "systemctl" in text:
        return "medium"
    if "sqlite3" in text:
        return "medium"
    return "low"


def execute(tokens: List[str], cwd: Path, cfg: Dict[str, Any]) -> Dict[str, Any]:
    timeout = int(cfg.get("execution_timeout_seconds", 90) or 30)
    max_out = int(cfg.get("max_output_bytes", 12000) or 12000)
    try:
        proc = subprocess.run(
            tokens,
            cwd=str(cwd),
            capture_output=True,
            text=True,
            timeout=timeout,
            shell=False,
            check=False,
        )
        out = (proc.stdout or "")
        err = (proc.stderr or "")
        return {
            "exit_code": int(proc.returncode),
            "stdout": out[:max_out],
            "stderr": err[:max_out],
        }
    except subprocess.TimeoutExpired:
        return {"exit_code": 124, "stdout": "", "stderr": "execution timed out"}
    except Exception as exc:
        return {"exit_code": 1, "stdout": "", "stderr": str(exc)}


def store_from_settings(settings: Dict[str, Any], repo_root: Path) -> ProposalStore:
    def proposal_count(path: Path) -> int:
        if not path.exists():
            return -1
        try:
            conn = sqlite3.connect(str(path))
            try:
                row = conn.execute("SELECT count(*) FROM bash_proposals").fetchone()
                return int(row[0]) if row else 0
            finally:
                conn.close()
        except Exception:
            return -1

    cfg = bash_cfg(settings)
    db_path = resolve_path(repo_root, str(cfg.get("db_path", "./bh/bash_history.db")))
    legacy_db_path = resolve_path(repo_root, "./data/agent_tools.db")
    if legacy_db_path.exists() and legacy_db_path != db_path:
        current_count = proposal_count(db_path)
        legacy_count = proposal_count(legacy_db_path)
        should_seed = (not db_path.exists()) or (current_count == 0 and legacy_count > 0)
        if should_seed:
            try:
                db_path.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(str(legacy_db_path), str(db_path))
                print(f"Migrated bash history DB to {db_path}")
            except Exception as exc:
                print(f"warning: could not migrate legacy DB: {exc}")
    jsonl_mirror = bool(cfg.get("proposal_jsonl_mirror", True))
    jsonl_dir = resolve_path(repo_root, str(cfg.get("proposal_jsonl_dir", "./bh/events")))
    return ProposalStore(db_path=db_path, jsonl_mirror=jsonl_mirror, jsonl_dir=jsonl_dir)


def build_prompt_context(cfg: Dict[str, Any], store: ProposalStore) -> str:
    prompt_cfg = cfg.get("prompt_context", {})
    if not isinstance(prompt_cfg, dict):
        prompt_cfg = {}
    if not bool(prompt_cfg.get("enabled", True)):
        return ""

    lines: List[str] = []

    if bool(prompt_cfg.get("include_allowed_commands", True)):
        allowed = cfg.get("allowed_commands", [])
        if isinstance(allowed, list):
            cleaned = [str(x).strip() for x in allowed if str(x).strip()]
            if cleaned:
                lines.append("Allowed shell commands: " + ", ".join(cleaned))

    if bool(prompt_cfg.get("include_blocked_tokens", True)):
        blocked = cfg.get("blocked_tokens", [])
        if isinstance(blocked, list):
            cleaned_blocked = [str(x).strip() for x in blocked if str(x).strip()]
            if cleaned_blocked:
                lines.append("Blocked shell tokens/operators: " + ", ".join(cleaned_blocked))
                lines.append("You must propose exactly one shell command with no pipes, redirects, chaining, or sudo.")

    if bool(prompt_cfg.get("include_single_command_examples", True)):
        lines.append("Single-command examples for common diagnostics:")
        lines.append("- Apache /v1/ log search by IP suffix: rg -n '([.]191|[.]152).*/v1/' /var/log/apache2")
        lines.append("- Apache error scan for API routes: rg -n -e '/v1/' -e 'error' -e 'AH[0-9]+' /var/log/apache2")
        lines.append("- Recent API hits in one file: rg -n '/v1/' /var/log/apache2/access.log")
        lines.append("- Auth failures without globs: rg -n 'Failed password' /var/log")
        lines.append("- Do not use auth.log* or access.log* wildcards; this harness does not expand shell globs")

    recent_count = int(prompt_cfg.get("recent_commands_count", 5) or 0)
    if recent_count > 0:
        recent_rows = store.get_recent_approved_or_executed(limit=recent_count)
        if recent_rows:
            lines.append("Recent approved/executed commands:")
            for row in recent_rows:
                cmd = str(row.get("command_text", "")).strip()
                status = str(row.get("status", "")).strip()
                if cmd:
                    lines.append("- [" + status + "] " + cmd)

    top_count = int(prompt_cfg.get("top_commands_count", 5) or 0)
    if top_count > 0:
        top_rows = store.get_top_executed(limit=top_count)
        if top_rows:
            lines.append("Most-used executed commands:")
            for row in top_rows:
                cmd = str(row.get("command", "")).strip()
                count = int(row.get("exec_count", 0) or 0)
                if cmd:
                    lines.append("- [" + str(count) + "x] " + cmd)

    return "\n".join(lines).strip()
