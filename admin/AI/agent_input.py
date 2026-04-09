#!/usr/bin/env python3
from __future__ import annotations

import json
import fcntl
import os
import select
import shlex
import subprocess
import sys
import tempfile
import time
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, Optional

from agent_common import APP_ROOT, DEFAULT_PRIVATE_ROOT


SESSION_LOG_DIR = DEFAULT_PRIVATE_ROOT / "logs" / "agent_sessions"
COMPOSER_LOG_DIR = DEFAULT_PRIVATE_ROOT / "logs" / "agent_composer"
MAX_FILE_PROMPT_CHARS = 48000
MAX_SESSION_CONTEXT_CHARS = 12000
PASTE_GRACE_SECONDS = 0.12
PASTE_MAX_WAIT_SECONDS = 2.0
PASTE_EDIT_MIN_LINES = 5
EDITOR_TIMEOUT_SECONDS = 300


def composer_metadata_header(content: str, source: str = "editor", metadata: Optional[Dict[str, Any]] = None) -> str:
    meta = dict(metadata or {})
    source_text = str(source or meta.get("source", "editor") or "editor").strip() or "editor"
    created_at = now_iso()
    first_line = ""
    for line in str(content or "").splitlines():
        stripped = line.strip()
        if stripped:
            first_line = stripped[:160]
            break

    fields = [
        ("composer_meta_version", "1"),
        ("created_at", created_at),
        ("source", source_text),
        ("profile", str(meta.get("profile_name", "") or "")),
        ("mode", str(meta.get("mode", "") or "")),
        ("model", str(meta.get("model", "") or "")),
        ("session_id", str(meta.get("session_id", "") or "")),
        ("first_line", first_line),
    ]

    lines = []
    for key, value in fields:
        text = str(value or "").replace("\r", " ").replace("\n", " ").strip()
        lines.append("# %s: %s" % (key, text))
    return "\n".join(lines) + "\n\n"


def save_composer_snapshot(content: str, source: str = "editor", metadata: Optional[Dict[str, Any]] = None) -> str:
    COMPOSER_LOG_DIR.mkdir(parents=True, exist_ok=True)
    stamp = datetime.utcnow().strftime("%Y%m%dT%H%M%S%fZ")
    safe_source = str(source or "editor").strip().lower().replace(" ", "_").replace("/", "_")
    if safe_source == "":
        safe_source = "editor"
    path = COMPOSER_LOG_DIR / ("%s_%s.md" % (stamp, safe_source))
    try:
        body = str(content or "")
        path.write_text(composer_metadata_header(body, source, metadata) + body, encoding="utf-8")
    except Exception:
        return ""
    return str(path)


def parse_composer_archive(path: Path) -> Dict[str, Any]:
    try:
        raw = path.read_text(encoding="utf-8", errors="replace")
    except Exception as e:
        return {"ok": False, "error": "file_read_failed", "detail": str(e), "path": str(path)}

    metadata = {}
    body_lines = []
    in_header = True
    for line in raw.splitlines():
        if in_header and line.startswith("# "):
            key_value = line[2:].split(":", 1)
            if len(key_value) == 2:
                key = key_value[0].strip()
                value = key_value[1].strip()
                if key != "":
                    metadata[key] = value
            continue
        if in_header and line.strip() == "":
            in_header = False
            continue
        in_header = False
        body_lines.append(line)

    body = "\n".join(body_lines).strip()
    return {"ok": True, "path": str(path), "metadata": metadata, "content": body}


def list_composer_archives(limit: int = 10) -> Dict[str, Any]:
    COMPOSER_LOG_DIR.mkdir(parents=True, exist_ok=True)
    try:
        paths = sorted(COMPOSER_LOG_DIR.glob("*.md"), key=lambda p: p.stat().st_mtime, reverse=True)
    except Exception as e:
        return {"ok": False, "error": "archive_list_failed", "detail": str(e), "path": str(COMPOSER_LOG_DIR)}

    items = []
    for path in paths[: max(1, int(limit or 10))]:
        parsed = parse_composer_archive(path)
        if not parsed.get("ok"):
            continue
        metadata = parsed.get("metadata", {})
        if not isinstance(metadata, dict):
            metadata = {}
        items.append(
            {
                "path": str(path),
                "created_at": str(metadata.get("created_at", "") or ""),
                "source": str(metadata.get("source", "") or ""),
                "profile": str(metadata.get("profile", "") or ""),
                "mode": str(metadata.get("mode", "") or ""),
                "model": str(metadata.get("model", "") or ""),
                "session_id": str(metadata.get("session_id", "") or ""),
                "first_line": str(metadata.get("first_line", "") or ""),
            }
        )
    return {"ok": True, "path": str(COMPOSER_LOG_DIR), "items": items}


def newest_composer_archive() -> Dict[str, Any]:
    listing = list_composer_archives(1)
    if not listing.get("ok"):
        return listing
    items = listing.get("items", [])
    if not isinstance(items, list) or not items:
        return {"ok": False, "error": "archive_not_found", "path": str(COMPOSER_LOG_DIR)}
    path = str(items[0].get("path", "") or "")
    if path == "":
        return {"ok": False, "error": "archive_not_found", "path": str(COMPOSER_LOG_DIR)}
    return load_composer_archive(path)


def load_composer_archive(path_text: str) -> Dict[str, Any]:
    try:
        path = resolve_allowed_path(path_text)
    except ValueError as e:
        return {"ok": False, "error": str(e)}

    if not path.exists() or not path.is_file():
        return {"ok": False, "error": "file_not_found", "path": str(path)}

    return parse_composer_archive(path)


def open_in_editor(
    initial_content: str,
    suffix: str = ".md",
    editor_command: str = "",
    timeout_seconds: int = EDITOR_TIMEOUT_SECONDS,
    strip_comment_lines: bool = True,
    archive_source: str = "editor",
    archive_metadata: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    editor = str(editor_command or os.environ.get("EDITOR") or os.environ.get("VISUAL") or "nano").strip() or "nano"
    uses_placeholder = ("{file_path}" in editor)
    fd, temp_path = tempfile.mkstemp(prefix="agent_input_", suffix=suffix, dir=tempfile.gettempdir())
    path = Path(temp_path)
    header = "# Edit the prompt below. Save and quit to send. Delete all non-comment content to cancel.\n\n"
    try:
        with os.fdopen(fd, "w", encoding="utf-8", errors="replace") as fh:
            fh.write(header)
            fh.write(initial_content)

        resolved_editor = editor.replace("{file_path}", str(path)) if uses_placeholder else editor
        try:
            editor_cmd = shlex.split(resolved_editor)
        except Exception:
            editor_cmd = [resolved_editor]
        if not editor_cmd:
            editor_cmd = ["nano"]

        print("\n[opening %s in %s]" % (str(path), editor_cmd[0]))
        original = path.read_text(encoding="utf-8", errors="replace")
        run_timeout = int(timeout_seconds or EDITOR_TIMEOUT_SECONDS)
        if run_timeout < 1:
            run_timeout = EDITOR_TIMEOUT_SECONDS
        cmd = list(editor_cmd)
        if not uses_placeholder:
            cmd.append(str(path))
        result = subprocess.run(cmd, timeout=run_timeout)
        if result.returncode != 0:
            return {"ok": False, "cancelled": True, "error": "editor_exit_nonzero", "editor": editor, "path": str(path)}

        content = path.read_text(encoding="utf-8", errors="replace")
        if content.strip() == original.strip():
            return {"ok": False, "cancelled": True, "error": "editor_unchanged", "editor": editor, "path": str(path)}
        cleaned_lines = []
        for line in content.splitlines():
            if strip_comment_lines and line.startswith("#"):
                continue
            cleaned_lines.append(line)
        cleaned = "\n".join(cleaned_lines).strip()
        if cleaned == "":
            return {"ok": False, "cancelled": True, "error": "editor_empty_result", "editor": editor, "path": str(path)}
        archived_path = save_composer_snapshot(cleaned, archive_source, archive_metadata)
        return {"ok": True, "content": cleaned, "editor": editor, "path": str(path), "archived_path": archived_path}
    except subprocess.TimeoutExpired:
        return {"ok": False, "cancelled": True, "error": "editor_timeout", "editor": editor, "path": str(path)}
    except Exception as e:
        return {"ok": False, "cancelled": True, "error": "editor_failed", "detail": str(e), "editor": editor, "path": str(path)}
    finally:
        try:
            path.unlink()
        except Exception:
            pass


def read_user_input(
    prompt_text: str = "agent> ",
    edit_multiline: bool = False,
    edit_min_lines: int = PASTE_EDIT_MIN_LINES,
    editor_command: str = "",
    editor_timeout_seconds: int = EDITOR_TIMEOUT_SECONDS,
    edit_strip_comment_lines: bool = True,
    archive_source: str = "edit_paste",
    archive_metadata: Optional[Dict[str, Any]] = None,
) -> str:
    """Read one logical user prompt, including rapid multiline paste bursts."""
    try:
        first = input(prompt_text)
    except EOFError:
        return ""

    lines = [first.rstrip("\r")]

    fd = sys.stdin.fileno()
    orig_flags = fcntl.fcntl(fd, fcntl.F_GETFL)
    fcntl.fcntl(fd, fcntl.F_SETFL, orig_flags | os.O_NONBLOCK)

    try:
        started = time.monotonic()
        deadline = started + PASTE_GRACE_SECONDS
        while True:
            now = time.monotonic()
            # Hard ceiling — never wait longer than this total
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
            deadline = time.monotonic() + PASTE_GRACE_SECONDS  # extend per chunk

    finally:
        fcntl.fcntl(fd, fcntl.F_SETFL, orig_flags)

    result = "\n".join(lines).replace("\r\n", "\n").replace("\r", "\n")
    if edit_multiline and len(lines) >= max(2, int(edit_min_lines or PASTE_EDIT_MIN_LINES)):
        print("\n[%d lines detected - opening editor for review]" % len(lines))
        edited = open_in_editor(
            result,
            editor_command=editor_command,
            timeout_seconds=editor_timeout_seconds,
            strip_comment_lines=edit_strip_comment_lines,
            archive_source=archive_source,
            archive_metadata=archive_metadata,
        )
        if edited.get("ok"):
            return str(edited.get("content", "") or "").strip()
        print("[editor %s - using original pasted content]" % str(edited.get("error", "cancelled")))
    return result


def now_iso() -> str:
    return datetime.utcnow().replace(microsecond=0).isoformat() + "Z"


def _allowed_roots() -> list[Path]:
    return [APP_ROOT.resolve(), DEFAULT_PRIVATE_ROOT.resolve()]


def resolve_allowed_path(path_text: str) -> Path:
    raw = str(path_text or "").strip()
    if raw == "":
        raise ValueError("path_required")
    candidate = Path(raw)
    if not candidate.is_absolute():
        candidate = (APP_ROOT / candidate).resolve()
    else:
        candidate = candidate.resolve()
    for root in _allowed_roots():
        if str(candidate).startswith(str(root)):
            return candidate
    raise ValueError("path_not_allowed")


def load_prompt_from_file(path_text: str) -> Dict[str, Any]:
    try:
        path = resolve_allowed_path(path_text)
    except ValueError as e:
        return {"ok": False, "error": str(e)}

    if not path.exists() or not path.is_file():
        return {"ok": False, "error": "file_not_found", "path": str(path)}

    try:
        content = path.read_text(encoding="utf-8", errors="replace")
    except Exception as e:
        return {"ok": False, "error": "file_read_failed", "detail": str(e), "path": str(path)}

    truncated = False
    if len(content) > MAX_FILE_PROMPT_CHARS:
        content = content[:MAX_FILE_PROMPT_CHARS]
        truncated = True

    prompt = "Please use this file as context.\n\nLoaded file: %s\n\nFile contents:\n%s" % (str(path), content)
    if truncated:
        prompt += "\n\n[Truncated after %d characters]" % MAX_FILE_PROMPT_CHARS

    return {
        "ok": True,
        "path": str(path),
        "prompt": prompt,
        "truncated": truncated,
    }


class AgentSessionLogger:
    def __init__(self, profile_name: str = "", mode: str = "") -> None:
        SESSION_LOG_DIR.mkdir(parents=True, exist_ok=True)
        stamp = datetime.utcnow().strftime("%Y%m%dT%H%M%SZ")
        suffix = ("%s_%s" % (profile_name or "session", mode or "shell")).replace(" ", "_").replace("/", "_")
        self.session_id = stamp + "_" + suffix
        self.path = SESSION_LOG_DIR / (self.session_id + ".jsonl")
        self.log("session_start", {"profile_name": profile_name, "mode": mode})

    def log(self, event: str, payload: Optional[Dict[str, Any]] = None) -> None:
        row = {
            "ts": now_iso(),
            "event": event,
            "session_id": self.session_id,
            "payload": payload or {},
        }
        try:
            with self.path.open("a", encoding="utf-8") as fh:
                fh.write(json.dumps(row, ensure_ascii=False) + "\n")
        except Exception:
            return


def build_session_history_prompt(current_session_path: Path, include_current: bool = True, max_sessions: int = 3) -> str:
    SESSION_LOG_DIR.mkdir(parents=True, exist_ok=True)
    candidates = []
    if include_current and current_session_path.exists():
        candidates.append(current_session_path)

    try:
        other_paths = sorted(
            [p for p in SESSION_LOG_DIR.glob("*.jsonl") if p != current_session_path],
            key=lambda p: p.stat().st_mtime,
            reverse=True,
        )
    except Exception:
        other_paths = []

    for path in other_paths:
        if len(candidates) >= max_sessions:
            break
        candidates.append(path)

    sections = []
    total_chars = 0
    for path in candidates:
        try:
            rows = path.read_text(encoding="utf-8", errors="replace").splitlines()
        except Exception:
            continue
        entries = []
        for raw in rows:
            try:
                row = json.loads(raw)
            except Exception:
                continue
            event = str(row.get("event", "") or "")
            payload = row.get("payload", {})
            if not isinstance(payload, dict):
                payload = {}
            if event == "user_prompt":
                text = str(payload.get("text", "") or "").strip()
                if text:
                    entries.append("User: " + text[:500])
            elif event == "assistant_response":
                text = str(payload.get("text", "") or "").strip()
                if text:
                    entries.append("Assistant: " + text[:500])
        if not entries:
            continue
        section = "Session file: %s\n%s" % (str(path), "\n".join(entries[-12:]))
        if total_chars + len(section) > MAX_SESSION_CONTEXT_CHARS:
            break
        sections.append(section)
        total_chars += len(section)

    if not sections:
        return ""
    return "Session history context:\n\n" + "\n\n".join(sections)
