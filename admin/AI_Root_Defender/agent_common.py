#!/usr/bin/env python3
from __future__ import annotations

import os
from pathlib import Path

APP_ROOT = Path(__file__).resolve().parent
DEFAULT_PRIVATE_ROOT = Path(os.environ.get("APP_PRIVATE_ROOT", "/web/private"))
DEFAULT_DATA_ROOT = APP_ROOT / "data"
DEFAULT_DB_PATH = APP_ROOT / "bh" / "bash_history.db"
DEFAULT_TOOLS_CONFIG_PATH = APP_ROOT / "config" / "tools.default.json"
DEFAULT_SETTINGS_CONFIG_PATH = APP_ROOT / "config" / "settings.default.json"
EXTERNAL_TOOLS_CONFIG_PATH = DEFAULT_PRIVATE_ROOT / "agent_tools.json"
EXTERNAL_SETTINGS_CONFIG_PATH = DEFAULT_PRIVATE_ROOT / "agent_settings.json"


def ensure_parent(path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
