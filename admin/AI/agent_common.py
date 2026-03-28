#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import re
import sys
from pathlib import Path
from typing import Any, Dict, Optional


APP_ROOT = Path(__file__).resolve().parents[2]
AGENT_BOOT_PATH = Path(__file__).resolve().with_name("agent_boot.md")
DEFAULT_AGENT_CONFIG_PATH = Path(__file__).resolve().with_name("default_agent.json")
DEFAULT_PRIVATE_ROOT = Path(os.environ.get("APP_PRIVATE_ROOT", "/web/private"))
AI_SETTINGS_DB_PATH = DEFAULT_PRIVATE_ROOT / "db" / "codewalker_settings.db"
AGENT_DB_PATH = DEFAULT_PRIVATE_ROOT / "db" / "agent_tools.db"
AGENT_MEMORY_DB_PATH = DEFAULT_PRIVATE_ROOT / "db" / "memory" / "agent_ai_memory.db"
PRIVATE_AGENT_CONFIG_PATH = DEFAULT_PRIVATE_ROOT / "agent.json"
AGENT_TOOL_SETTINGS_PATH = DEFAULT_PRIVATE_ROOT / "agent_tools.json"
PRIVATE_ENV_PATH = DEFAULT_PRIVATE_ROOT / ".env"
DEFAULT_NOTES_DB = DEFAULT_PRIVATE_ROOT / "db" / "memory" / "human_notes.db"
DEFAULT_TMP_DIR = DEFAULT_PRIVATE_ROOT / "tmp"


def compact_json(data: Any) -> str:
    return json.dumps(data, ensure_ascii=False, separators=(",", ":"))


def parse_model_json(text: str) -> Optional[Dict[str, Any]]:
    text = text.strip()
    if not text:
        return None

    fenced = re.search(r"```(?:json)?\s*(\{[\s\S]*\})\s*```", text, re.IGNORECASE)
    if fenced:
        text = fenced.group(1).strip()

    if not text.startswith("{"):
        idx = text.find("{")
        if idx >= 0:
            text = text[idx:]

    try:
        parsed = json.loads(text)
    except Exception:
        return None

    return parsed if isinstance(parsed, dict) else None


def load_agent_boot_prompt(path: Optional[Path] = None) -> str:
    target = path or AGENT_BOOT_PATH
    try:
        return target.read_text(encoding="utf-8").strip()
    except Exception as e:
        raise RuntimeError("Failed to load agent boot prompt from %s: %s" % (target, e))


def colorize(text: str, code: str, enabled: bool) -> str:
    if not enabled:
        return text
    return "\033[" + code + "m" + text + "\033[0m"


def tty_supports_color() -> bool:
    if not sys.stdout.isatty():
        return False
    term = os.environ.get("TERM", "")
    if term.strip().lower() == "dumb":
        return False
    return True
