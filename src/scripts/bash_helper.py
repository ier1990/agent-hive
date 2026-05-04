#!/usr/bin/env python3
"""Shared helpers for the bash-history pipeline."""

from __future__ import annotations

import os
from typing import List

from notes_config import get_config, get_private_root


def script_path(name: str) -> str:
    return os.path.join(os.path.dirname(__file__), name)


def private_root() -> str:
    return get_private_root(__file__)


def human_db_path() -> str:
    return os.path.join(private_root(), "db/memory/human_notes.db")


def ai_metadata_db_path() -> str:
    return os.path.join(private_root(), "db/memory/notes_ai_metadata.db")


def bash_kb_db_path() -> str:
    return os.path.join(private_root(), "db/memory/bash_history.db")


def default_users_csv() -> str:
    cfg = get_config()
    users = str(cfg.get("bash.history.users", "samekhi,root") or "").strip()
    return users if users != "" else "samekhi,root"


def parse_users_csv(raw: str) -> List[str]:
    users = [u.strip() for u in str(raw or "").split(",") if u.strip()]
    if users:
        return users
    return ["samekhi", "root"]


def _is_truthy(raw: str) -> bool:
    value = str(raw or "").strip().lower()
    return value in ("1", "true", "yes", "on")


def external_search_enabled() -> bool:
    cfg = get_config()
    value = cfg.get("bash.history.enable_search", "0")
    return _is_truthy(str(value))


def assert_ai_db_path(ai_db: str, human_db: str) -> None:
    ai_real = os.path.realpath(str(ai_db or ""))
    human_real = os.path.realpath(str(human_db or ""))
    if ai_real == "" or human_real == "":
        raise RuntimeError("AI DB and human DB paths must both be set")
    if ai_real == human_real:
        raise RuntimeError("Refusing to use human_notes.db as the AI metadata DB")
    if os.path.basename(ai_real) == "human_notes.db":
        raise RuntimeError("AI metadata DB path must not point at human_notes.db")
