#!/usr/bin/env python3
from __future__ import annotations

import json
import shlex
import sqlite3
import subprocess
from pathlib import Path
from typing import Any, Dict, List

from agent_common import AGENT_DB_PATH, APP_ROOT, DEFAULT_PRIVATE_ROOT, compact_json


def _bash_cfg(tool_settings: Dict[str, Any]) -> Dict[str, Any]:
    cfg = tool_settings.get("bash", {})
    return cfg if isinstance(cfg, dict) else {}


def bash_enabled(tool_settings: Dict[str, Any]) -> bool:
    cfg = _bash_cfg(tool_settings)
    return bool(cfg.get("enabled", True))


def bash_db_path(tool_settings: Dict[str, Any]) -> Path:
    cfg = _bash_cfg(tool_settings)
    raw = str(cfg.get("db_path", "") or "").strip()
    if raw == "":
        return AGENT_DB_PATH
    return Path(raw)


def bash_max_command_length(tool_settings: Dict[str, Any]) -> int:
    cfg = _bash_cfg(tool_settings)
    return max(80, min(int(cfg.get("max_command_length", 1200) or 1200), 8000))


def bash_read_only_enabled(tool_settings: Dict[str, Any]) -> bool:
    cfg = _bash_cfg(tool_settings)
    return bool(cfg.get("read_only_enabled", True))


def bash_timeout(tool_settings: Dict[str, Any]) -> int:
    cfg = _bash_cfg(tool_settings)
    return max(1, int(cfg.get("execution_timeout_seconds", 30) or 30))


def bash_max_output_bytes(tool_settings: Dict[str, Any]) -> int:
    cfg = _bash_cfg(tool_settings)
    return max(1000, min(int(cfg.get("max_output_bytes", 12000) or 12000), 200000))


def bash_allowed_commands(tool_settings: Dict[str, Any]) -> List[str]:
    cfg = _bash_cfg(tool_settings)
    raw = cfg.get("allowed_commands", [])
    if not isinstance(raw, list):
        raw = []
    out: List[str] = []
    for value in raw:
        text = str(value or "").strip().lower()
        if text != "":
            out.append(text)
    return out


def bash_blocked_tokens(tool_settings: Dict[str, Any]) -> List[str]:
    cfg = _bash_cfg(tool_settings)
    raw = cfg.get("blocked_tokens", [])
    if not isinstance(raw, list):
        raw = []
    out: List[str] = []
    for value in raw:
        text = str(value or "").strip()
        if text != "":
            out.append(text)
    return out


def bash_allowed_roots(tool_settings: Dict[str, Any]) -> List[str]:
    cfg = _bash_cfg(tool_settings)
    raw = cfg.get("allowed_roots", [])
    if not isinstance(raw, list):
        raw = []
    out: List[str] = []
    for value in raw:
        text = str(value or "").strip()
        if text == "":
            continue
        try:
            out.append(str(Path(text).resolve()))
        except Exception:
            continue
    return out


def ensure_bash_schema(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS bash_proposals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            command_text TEXT NOT NULL,
            cwd TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'proposed',
            risk_level TEXT NOT NULL DEFAULT 'medium',
            operator_summary TEXT NOT NULL DEFAULT '',
            tutorial_summary TEXT NOT NULL DEFAULT '{}',
            metadata_json TEXT NOT NULL DEFAULT '{}',
            proposed_by TEXT NOT NULL DEFAULT 'agent',
            proposed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_by TEXT NOT NULL DEFAULT '',
            approved_at TEXT,
            executed_by TEXT NOT NULL DEFAULT '',
            executed_at TEXT,
            exit_code INTEGER,
            stdout_preview TEXT NOT NULL DEFAULT '',
            stderr_preview TEXT NOT NULL DEFAULT '',
            result_json TEXT NOT NULL DEFAULT '{}',
            notes TEXT NOT NULL DEFAULT ''
        )
        """
    )
    conn.execute("CREATE INDEX IF NOT EXISTS idx_bash_proposals_status ON bash_proposals(status)")
    conn.execute("CREATE INDEX IF NOT EXISTS idx_bash_proposals_time ON bash_proposals(proposed_at)")
    conn.commit()


def root_allowed(tool_settings: Dict[str, Any], cwd: Path) -> bool:
    roots = bash_allowed_roots(tool_settings)
    if not roots:
        return False
    cwd_text = str(cwd)
    for root in roots:
        if cwd_text == root or cwd_text.startswith(root.rstrip("/") + "/"):
            return True
    return False


def path_token_allowed(tool_settings: Dict[str, Any], cwd: Path, token: str) -> bool:
    text = str(token or "").strip()
    if text == "":
        return True
    if text.startswith("-"):
        return True
    if "://" in text:
        return False
    if text.startswith("/"):
        target = Path(text)
    elif text.startswith("./") or text.startswith("../"):
        target = cwd / text
    else:
        return True
    try:
        resolved = target.resolve()
    except Exception:
        return False
    return root_allowed(tool_settings, resolved)


def command_token_docs(command_name: str, token: str) -> str:
    common_flags = {
        "-n": "show line numbers",
        "-l": "list results in a compact form",
        "-a": "include hidden or all entries depending on the command",
        "-h": "human-readable sizes or help depending on the command",
        "-R": "recurse into subdirectories",
        "-r": "recurse into subdirectories",
        "-i": "case-insensitive search or in-place edit depending on the command",
        "-c": "run the following string as a command",
    }
    command_docs = {
        "rg": "ripgrep, a fast recursive search tool",
        "grep": "search text for matching lines",
        "ls": "list directory contents",
        "find": "walk a directory tree and filter paths",
        "wc": "count lines, words, or bytes",
        "cat": "print file contents",
        "head": "show the first lines of a file",
        "tail": "show the last lines of a file",
        "sed": "stream editor for selecting or transforming text",
        "awk": "pattern-based text processor",
        "sort": "sort lines of text",
        "uniq": "collapse duplicate adjacent lines",
        "cut": "extract fields or character ranges",
        "pwd": "print the current working directory",
        "stat": "show file metadata",
        "file": "guess the file type",
        "git": "inspect or modify git state",
        "curl": "make an HTTP request",
        "wget": "download from a URL",
        "rm": "remove files or directories",
        "mv": "move or rename files",
        "cp": "copy files or directories",
        "chmod": "change file permissions",
        "chown": "change file ownership",
        "mkdir": "create a directory",
        "touch": "create a file or update timestamps",
    }
    if token == command_name:
        return command_docs.get(command_name, "shell command")
    if token in common_flags:
        return common_flags[token]
    if token.startswith("-"):
        return "command option"
    if token.startswith("/") or token.startswith("./") or token.startswith("../"):
        return "filesystem path argument"
    if "://" in token:
        return "URL argument"
    return "command argument"


def summarize_bash_command(tool_settings: Dict[str, Any], command: str, cwd: Path) -> Dict[str, Any]:
    raw = command.strip()
    tokens = shlex.split(raw)
    command_name = tokens[0] if tokens else ""
    lower_name = command_name.lower()
    lower_raw = raw.lower()

    read_only_commands = {"rg", "grep", "ls", "find", "wc", "cat", "head", "tail", "sort", "uniq", "cut", "pwd", "stat", "file"}
    write_commands = {"rm", "mv", "cp", "chmod", "chown", "mkdir", "rmdir", "touch"}
    network_commands = {"curl", "wget", "ssh", "scp", "rsync", "ping", "dig", "nc", "ncat"}
    risk = "low"
    writes: List[str] = []
    reads: List[str] = [str(cwd)]
    network = lower_name in network_commands
    sudo = lower_name == "sudo" or lower_raw.startswith("sudo ")
    destructive = lower_name in {"rm", "chmod", "chown"} or " rm " in lower_raw
    chained = any(op in raw for op in [";", "&&", "||"])
    piped = "|" in raw
    redirected = ">" in raw or ">>" in raw

    if lower_name in write_commands or redirected:
        writes.append(str(cwd))
    if network or sudo or destructive:
        risk = "high"
    elif chained or piped or redirected or lower_name not in read_only_commands:
        risk = "medium"

    purpose_map = {
        "rg": "Search recursively for text matches.",
        "grep": "Search text for matching lines.",
        "ls": "List files in a directory.",
        "find": "Find files or directories that match conditions.",
        "wc": "Count lines, words, or bytes.",
        "cat": "Print file contents.",
        "head": "Show the first part of a file.",
        "tail": "Show the last part of a file.",
        "git": "Inspect or change git state.",
        "curl": "Make a network request.",
        "rm": "Delete files or directories.",
        "mv": "Move or rename files.",
        "cp": "Copy files or directories.",
    }
    operator_summary = purpose_map.get(lower_name, "Run a shell command in the selected working directory.")
    if tokens:
        operator_summary = operator_summary.rstrip(".") + " Command: " + raw

    tutorial_tokens = []
    for token in tokens[:12]:
        tutorial_tokens.append(
            {
                "part": token,
                "meaning": command_token_docs(command_name, token),
            }
        )

    safer_alternative = ""
    if risk == "high":
        safer_alternative = "Narrow the target path or split the command into simpler read-only inspection steps first."
    elif lower_name in {"grep", "find"}:
        safer_alternative = "Use rg for faster recursive searches when available."

    return {
        "risk": risk,
        "reads": reads,
        "writes": writes,
        "network": network,
        "sudo": sudo,
        "operator_summary": operator_summary,
        "tutorial_summary": {
            "purpose": purpose_map.get(lower_name, "Run a shell command."),
            "tokens": tutorial_tokens,
            "why_this_command": "It is a direct shell expression for the requested task.",
            "expected_output": "Terminal output from the command, or no output if it succeeds silently.",
            "safer_alternative": safer_alternative,
        },
        "metadata": {
            "command_name": command_name,
            "token_count": len(tokens),
            "chained": chained,
            "piped": piped,
            "redirected": redirected,
            "cwd_allowed": root_allowed(tool_settings, cwd),
        },
    }


class AgentBashTools:
    def __init__(self, tool_settings: Dict[str, Any], code_root: Path) -> None:
        self.tool_settings = tool_settings if isinstance(tool_settings, dict) else {}
        self.code_root = code_root.resolve()

    def _find_reusable_proposal(self, conn: sqlite3.Connection, command: str, cwd: Path) -> sqlite3.Row:
        conn.row_factory = sqlite3.Row
        return conn.execute(
            """
            SELECT *
            FROM bash_proposals
            WHERE command_text = ?
              AND cwd = ?
              AND status IN ('proposed', 'approved')
            ORDER BY id DESC
            LIMIT 1
            """,
            (command, str(cwd)),
        ).fetchone()

    def _validate_read_only_command(self, command: str, cwd_path: Path) -> Dict[str, Any]:
        if not bash_enabled(self.tool_settings):
            return {"ok": False, "error": "bash_tool_disabled"}
        if not bash_read_only_enabled(self.tool_settings):
            return {"ok": False, "error": "bash_read_disabled"}
        if not root_allowed(self.tool_settings, cwd_path):
            return {"ok": False, "error": "cwd_not_allowed", "cwd": str(cwd_path), "allowed_roots": bash_allowed_roots(self.tool_settings)}

        raw = str(command or "").strip()
        if raw == "":
            return {"ok": False, "error": "empty_command"}
        if len(raw) > bash_max_command_length(self.tool_settings):
            return {"ok": False, "error": "command_too_long"}

        for token in bash_blocked_tokens(self.tool_settings):
            if token in ("sudo", "curl", "wget", "ssh", "scp", "rsync", "rm", "mv", "cp", "chmod", "chown", "mkdir", "rmdir", "touch"):
                if raw == token or raw.startswith(token + " "):
                    return {"ok": False, "error": "command_blocked", "blocked_token": token}
                continue
            if token in raw:
                return {"ok": False, "error": "command_blocked", "blocked_token": token}

        try:
            args = shlex.split(raw)
        except Exception as e:
            return {"ok": False, "error": "command_parse_failed", "detail": str(e)}
        if not args:
            return {"ok": False, "error": "empty_command"}

        command_name = str(args[0] or "").strip().lower()
        if command_name not in bash_allowed_commands(self.tool_settings):
            return {"ok": False, "error": "command_not_allowed", "command_name": command_name, "allowed_commands": bash_allowed_commands(self.tool_settings)}

        for arg in args[1:]:
            if not path_token_allowed(self.tool_settings, cwd_path, arg):
                return {"ok": False, "error": "path_not_allowed", "path": str(arg)}

        return {"ok": True, "args": args, "command_name": command_name}

    def read(self, command: str, cwd: str) -> Dict[str, Any]:
        target_cwd = str(cwd or "").strip()
        if target_cwd == "":
            target_cwd = str(self.code_root)
        try:
            cwd_path = Path(target_cwd).resolve()
        except Exception:
            return {"ok": False, "error": "invalid_cwd"}
        if not cwd_path.exists() or not cwd_path.is_dir():
            return {"ok": False, "error": "cwd_missing", "cwd": str(cwd_path)}

        checked = self._validate_read_only_command(command, cwd_path)
        if not checked.get("ok"):
            return checked

        args = checked.get("args", [])
        summary = summarize_bash_command(self.tool_settings, str(command or ""), cwd_path)
        timeout = bash_timeout(self.tool_settings)
        max_bytes = bash_max_output_bytes(self.tool_settings)

        try:
            proc = subprocess.run(
                args,
                cwd=str(cwd_path),
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                timeout=timeout,
                check=False,
            )
        except FileNotFoundError:
            return {"ok": False, "error": "command_not_found", "command_name": checked.get("command_name", "")}
        except subprocess.TimeoutExpired as e:
            stdout_text = e.stdout if isinstance(e.stdout, str) else ""
            stderr_text = e.stderr if isinstance(e.stderr, str) else ""
            return {
                "ok": False,
                "error": "bash_read_timeout",
                "command": str(command or ""),
                "cwd": str(cwd_path),
                "timeout_seconds": timeout,
                "stdout": stdout_text[:max_bytes],
                "stderr": stderr_text[:max_bytes],
            }

        stdout_text = proc.stdout or ""
        stderr_text = proc.stderr or ""
        truncated = False
        if len(stdout_text) > max_bytes:
            stdout_text = stdout_text[:max_bytes]
            truncated = True
        if len(stderr_text) > max_bytes:
            stderr_text = stderr_text[:max_bytes]
            truncated = True

        return {
            "ok": proc.returncode == 0,
            "command": str(command or ""),
            "cwd": str(cwd_path),
            "command_name": checked.get("command_name", ""),
            "exit_code": int(proc.returncode),
            "stdout": stdout_text,
            "stderr": stderr_text,
            "truncated": truncated,
            "risk": summary.get("risk", "low"),
            "operator_summary": summary.get("operator_summary", ""),
            "tutorial_summary": summary.get("tutorial_summary", {}),
            "reads": summary.get("reads", []),
            "writes": summary.get("writes", []),
            "network": bool(summary.get("network", False)),
            "sudo": bool(summary.get("sudo", False)),
        }

    def propose(self, command: str, cwd: str) -> Dict[str, Any]:
        if not bash_enabled(self.tool_settings):
            return {"ok": False, "error": "bash_tool_disabled"}

        command = str(command or "").strip()
        if command == "":
            return {"ok": False, "error": "empty_command"}
        if len(command) > bash_max_command_length(self.tool_settings):
            return {"ok": False, "error": "command_too_long"}

        target_cwd = str(cwd or "").strip()
        if target_cwd == "":
            target_cwd = str(self.code_root)
        try:
            cwd_path = Path(target_cwd).resolve()
        except Exception:
            return {"ok": False, "error": "invalid_cwd"}
        if not cwd_path.exists() or not cwd_path.is_dir():
            return {"ok": False, "error": "cwd_missing", "cwd": str(cwd_path)}
        if not root_allowed(self.tool_settings, cwd_path):
            return {
                "ok": False,
                "error": "cwd_not_allowed",
                "cwd": str(cwd_path),
                "allowed_roots": bash_allowed_roots(self.tool_settings),
            }

        try:
            shlex.split(command)
        except Exception as e:
            return {"ok": False, "error": "command_parse_failed", "detail": str(e)}

        summary = summarize_bash_command(self.tool_settings, command, cwd_path)
        db_path = bash_db_path(self.tool_settings)
        db_path.parent.mkdir(parents=True, exist_ok=True)
        conn = sqlite3.connect(str(db_path))
        try:
            ensure_bash_schema(conn)
            existing = self._find_reusable_proposal(conn, command, cwd_path)
            if existing is not None:
                existing_status = str(existing["status"] or "")
                approval_hint = "This command is still waiting for human approval."
                if existing_status == "approved":
                    approval_hint = "This command was already approved. It can be executed from the admin UI."
                return {
                    "ok": True,
                    "proposal_id": int(existing["id"]),
                    "status": existing_status,
                    "command": command,
                    "cwd": str(cwd_path),
                    "risk": str(existing["risk_level"] or summary.get("risk", "medium")),
                    "operator_summary": str(existing["operator_summary"] or summary.get("operator_summary", "")),
                    "tutorial_summary": summary.get("tutorial_summary", {}),
                    "reads": summary.get("reads", []),
                    "writes": summary.get("writes", []),
                    "network": bool(summary.get("network", False)),
                    "sudo": bool(summary.get("sudo", False)),
                    "review_url": "/admin/admin_AI_Bash.php",
                    "approval_hint": approval_hint,
                    "reused_existing": True,
                }
            cursor = conn.execute(
                """
                INSERT INTO bash_proposals (
                    command_text, cwd, status, risk_level, operator_summary,
                    tutorial_summary, metadata_json, proposed_by
                ) VALUES (?, ?, 'proposed', ?, ?, ?, ?, 'agent')
                """,
                (
                    command,
                    str(cwd_path),
                    str(summary.get("risk", "medium")),
                    str(summary.get("operator_summary", "")),
                    compact_json(summary.get("tutorial_summary", {})),
                    compact_json(
                        {
                            "reads": summary.get("reads", []),
                            "writes": summary.get("writes", []),
                            "network": bool(summary.get("network", False)),
                            "sudo": bool(summary.get("sudo", False)),
                            "metadata": summary.get("metadata", {}),
                        }
                    ),
                ),
            )
            conn.commit()
            proposal_id = int(cursor.lastrowid)
        finally:
            conn.close()

        return {
            "ok": True,
            "proposal_id": proposal_id,
            "status": "proposed",
            "command": command,
            "cwd": str(cwd_path),
            "risk": summary.get("risk", "medium"),
            "operator_summary": summary.get("operator_summary", ""),
            "tutorial_summary": summary.get("tutorial_summary", {}),
            "reads": summary.get("reads", []),
            "writes": summary.get("writes", []),
            "network": bool(summary.get("network", False)),
            "sudo": bool(summary.get("sudo", False)),
            "review_url": "/admin/admin_AI_Bash.php",
            "approval_hint": "This command was proposed only. It still needs human approval before execution.",
        }

    def proposal_status(self, proposal_id: int) -> Dict[str, Any]:
        if not bash_enabled(self.tool_settings):
            return {"ok": False, "error": "bash_tool_disabled"}
        if int(proposal_id) <= 0:
            return {"ok": False, "error": "invalid_proposal_id"}
        db_path = bash_db_path(self.tool_settings)
        if not db_path.exists():
            return {"ok": False, "error": "bash_db_missing", "path": str(db_path)}
        conn = sqlite3.connect(str(db_path))
        conn.row_factory = sqlite3.Row
        try:
            ensure_bash_schema(conn)
            row = conn.execute("SELECT * FROM bash_proposals WHERE id = ? LIMIT 1", (int(proposal_id),)).fetchone()
        finally:
            conn.close()
        if row is None:
            return {"ok": False, "error": "proposal_not_found", "proposal_id": int(proposal_id)}

        tutorial_summary: Dict[str, Any]
        metadata: Dict[str, Any]
        result_json: Dict[str, Any]
        try:
            tutorial_summary = json.loads(str(row["tutorial_summary"] or "{}"))
        except Exception:
            tutorial_summary = {}
        try:
            metadata = json.loads(str(row["metadata_json"] or "{}"))
        except Exception:
            metadata = {}
        try:
            result_json = json.loads(str(row["result_json"] or "{}"))
        except Exception:
            result_json = {}

        return {
            "ok": True,
            "proposal": {
                "id": int(row["id"]),
                "command": str(row["command_text"] or ""),
                "cwd": str(row["cwd"] or ""),
                "status": str(row["status"] or ""),
                "risk": str(row["risk_level"] or ""),
                "operator_summary": str(row["operator_summary"] or ""),
                "tutorial_summary": tutorial_summary,
                "metadata": metadata,
                "proposed_at": str(row["proposed_at"] or ""),
                "approved_by": str(row["approved_by"] or ""),
                "approved_at": str(row["approved_at"] or ""),
                "executed_by": str(row["executed_by"] or ""),
                "executed_at": str(row["executed_at"] or ""),
                "exit_code": row["exit_code"],
                "stdout_preview": str(row["stdout_preview"] or ""),
                "stderr_preview": str(row["stderr_preview"] or ""),
                "result": result_json,
            },
        }

    def proposal_list(self, limit: int, status: str) -> Dict[str, Any]:
        if not bash_enabled(self.tool_settings):
            return {"ok": False, "error": "bash_tool_disabled"}
        db_path = bash_db_path(self.tool_settings)
        if not db_path.exists():
            return {"ok": False, "error": "bash_db_missing", "path": str(db_path)}

        limit = max(1, min(int(limit), 100))
        status_text = str(status or "").strip().lower()
        allowed_statuses = {"proposed", "approved", "canceled", "executed", "failed"}
        conn = sqlite3.connect(str(db_path))
        conn.row_factory = sqlite3.Row
        try:
            ensure_bash_schema(conn)
            if status_text in allowed_statuses:
                rows = conn.execute(
                    """
                    SELECT id, command_text, cwd, status, risk_level, operator_summary,
                           proposed_at, approved_at, executed_at, exit_code
                    FROM bash_proposals
                    WHERE status = ?
                    ORDER BY id DESC
                    LIMIT ?
                    """,
                    (status_text, limit),
                ).fetchall()
            else:
                rows = conn.execute(
                    """
                    SELECT id, command_text, cwd, status, risk_level, operator_summary,
                           proposed_at, approved_at, executed_at, exit_code
                    FROM bash_proposals
                    ORDER BY id DESC
                    LIMIT ?
                    """,
                    (limit,),
                ).fetchall()
        finally:
            conn.close()

        items: List[Dict[str, Any]] = []
        for row in rows:
            items.append(
                {
                    "id": int(row["id"]),
                    "command": str(row["command_text"] or ""),
                    "cwd": str(row["cwd"] or ""),
                    "status": str(row["status"] or ""),
                    "risk": str(row["risk_level"] or ""),
                    "operator_summary": str(row["operator_summary"] or ""),
                    "proposed_at": str(row["proposed_at"] or ""),
                    "approved_at": str(row["approved_at"] or ""),
                    "executed_at": str(row["executed_at"] or ""),
                    "exit_code": row["exit_code"],
                }
            )
        return {
            "ok": True,
            "count": len(items),
            "items": items,
            "db_path": str(db_path),
            "status_filter": status_text if status_text in allowed_statuses else "",
        }
