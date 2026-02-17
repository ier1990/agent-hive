#!/usr/bin/env python3
"""Shared AI template loader/compiler for Python scripts.

Loads templates from PRIVATE_ROOT/db/memory/ai_header.db and compiles
{{ variables }} with dot-notation support into a YAML-ish payload dict.
"""

from __future__ import annotations

import json
import os
import re
import sqlite3
from typing import Any, Dict, List, Optional, Tuple

from notes_config import get_private_root


def _db_path() -> str:
    private_root = get_private_root(__file__)
    return os.path.join(private_root, "db/memory/ai_header.db")


def _lookup(bindings: Dict[str, Any], key: str) -> Any:
    cur: Any = bindings
    for part in key.split("."):
        if isinstance(cur, dict) and part in cur:
            cur = cur[part]
            continue
        return ""
    return cur


def _to_text(v: Any) -> str:
    if v is None:
        return ""
    if isinstance(v, str):
        return v
    if isinstance(v, (int, float, bool)):
        return str(v)
    try:
        return json.dumps(v, ensure_ascii=False)
    except Exception:
        return str(v)


def render_template(template_text: str, bindings: Dict[str, Any]) -> str:
    rx = re.compile(r"\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}")

    def repl(m: re.Match) -> str:
        val = _lookup(bindings, m.group(1))
        return _to_text(val)

    return rx.sub(repl, template_text)


def _parse_scalar(s: str) -> Any:
    t = (s or "").strip()
    if t == "":
        return ""

    lo = t.lower()
    if lo == "true":
        return True
    if lo == "false":
        return False
    if lo in ("null", "none"):
        return None

    if re.match(r"^-?\d+$", t):
        try:
            return int(t)
        except Exception:
            pass
    if re.match(r"^-?\d+\.\d+$", t):
        try:
            return float(t)
        except Exception:
            pass

    if (t.startswith("{") and t.endswith("}")) or (t.startswith("[") and t.endswith("]")):
        try:
            return json.loads(t)
        except Exception:
            return t

    return t


def _leading_spaces(line: str) -> int:
    n = 0
    for ch in line:
        if ch == " ":
            n += 1
        else:
            break
    return n


def _parse_map(lines: List[str], start: int, base_indent: int) -> Tuple[Dict[str, Any], int]:
    out: Dict[str, Any] = {}
    i = start
    n = len(lines)

    while i < n:
        raw = lines[i]
        if raw.strip() == "":
            i += 1
            continue

        indent = _leading_spaces(raw)
        if indent < base_indent:
            break
        if indent > base_indent:
            i += 1
            continue

        line = raw[indent:]
        m = re.match(r"^([A-Za-z0-9_.-]+)\s*:\s*(.*)$", line)
        if not m:
            i += 1
            continue

        key = m.group(1)
        rest = m.group(2)

        if rest == "|":
            block: List[str] = []
            i += 1
            while i < n:
                nxt = lines[i]
                if nxt.strip() == "":
                    block.append("")
                    i += 1
                    continue
                nxt_indent = _leading_spaces(nxt)
                if nxt_indent <= indent:
                    break
                min_indent = indent + 2
                trim_from = min_indent if nxt_indent >= min_indent else nxt_indent
                block.append(nxt[trim_from:])
                i += 1
            out[key] = "\n".join(block).strip("\n")
            continue

        if rest == "":
            nested, ni = _parse_map(lines, i + 1, indent + 2)
            out[key] = nested
            i = ni
            continue

        out[key] = _parse_scalar(rest)
        i += 1

    return out, i


def parse_payload_text(rendered: str) -> Dict[str, Any]:
    txt = (rendered or "").strip()
    if txt == "":
        return {}

    if txt[0] in "[{":
        try:
            parsed = json.loads(txt)
            return parsed if isinstance(parsed, dict) else {}
        except Exception:
            pass

    lines = txt.replace("\r\n", "\n").replace("\r", "\n").split("\n")
    parsed, _ = _parse_map(lines, 0, 0)
    return parsed


def get_template_text(name: str, template_type: str = "payload") -> str:
    if not isinstance(name, str) or name.strip() == "":
        return ""

    path = _db_path()
    if not os.path.isfile(path):
        return ""

    try:
        conn = sqlite3.connect(path)
        try:
            if template_type:
                row = conn.execute(
                    "SELECT template_text FROM ai_header_templates WHERE name=? AND type=? LIMIT 1",
                    (name.strip(), template_type),
                ).fetchone()
                if row and isinstance(row[0], str) and row[0].strip() != "":
                    return row[0]

            row = conn.execute(
                "SELECT template_text FROM ai_header_templates WHERE name=? LIMIT 1",
                (name.strip(),),
            ).fetchone()
            if row and isinstance(row[0], str):
                return row[0]
            return ""
        finally:
            conn.close()
    except Exception:
        return ""


def compile_payload_by_name(name: str, bindings: Dict[str, Any], template_type: str = "payload") -> Dict[str, Any]:
    tpl = get_template_text(name, template_type=template_type)
    if tpl.strip() == "":
        return {"found": False, "template_name": name, "payload": {}}

    rendered = render_template(tpl, bindings if isinstance(bindings, dict) else {})
    payload = parse_payload_text(rendered)
    if not isinstance(payload, dict):
        payload = {}

    return {
        "found": True,
        "template_name": name,
        "payload": payload,
        "payload_text": rendered,
    }


def payload_to_chat_parts(payload: Dict[str, Any], fallback_system: str, fallback_user: str) -> Tuple[str, str, Dict[str, Any], bool]:
    if not isinstance(payload, dict):
        payload = {}

    def v(name: str) -> str:
        return _to_text(payload.get(name, "")).strip()

    system_parts: List[str] = []
    for key in ("system", "persona", "policy", "tools", "tool_list", "format", "formatting", "constraints"):
        value = v(key)
        if value:
            system_parts.append(value)

    system_text = "\n\n".join(system_parts).strip() or (fallback_system or "").strip()
    user_text = v("user") or v("prompt") or (fallback_user or "").strip()

    options = payload.get("options")
    if not isinstance(options, dict):
        options = {}

    stream_val = payload.get("stream", False)
    stream = bool(stream_val)

    return system_text, user_text, options, stream
