#!/usr/bin/env python3
"""Shared config loader for Notes + cron scripts.

Resolution order (requested):
1) Notes DB app_settings (human_notes.db)
2) /web/private/notes_default.json
3) Hardcoded defaults

This module is intentionally dependency-light (stdlib only).
"""

from __future__ import annotations

import json
import os
import sqlite3
from pathlib import Path
import importlib.util
from typing import Any, Dict


DEFAULTS: Dict[str, str] = {
    "ai.ollama.url": "http://192.168.0.142:11434",
    "ai.ollama.model": "gpt-oss:latest",
    "search.api.base": "http://192.168.0.142/v1/search/?q=",
}


def _import_domain_memory_bootstrap(caller_path: str | None = None):
    """Import lib/bootstrap.py without assuming cwd or PYTHONPATH."""
    here = Path(caller_path or __file__).resolve()
    for parent in [here.parent] + list(here.parents):
        cand = parent / "lib" / "bootstrap.py"
        if cand.is_file():
            spec = importlib.util.spec_from_file_location("domain_memory_bootstrap", str(cand))
            if spec and spec.loader:
                mod = importlib.util.module_from_spec(spec)
                spec.loader.exec_module(mod)
                return mod
    return None


def get_paths(caller_path: str | None = None) -> Dict[str, str]:
    try:
        mod = _import_domain_memory_bootstrap(caller_path)
        if not mod:
            return {}
        paths = mod.get_paths(caller_path or __file__)
        return paths if isinstance(paths, dict) else {}
    except Exception:
        return {}


def get_private_root(caller_path: str | None = None) -> str:
    paths = get_paths(caller_path)
    pr = paths.get("PRIVATE_ROOT") if isinstance(paths, dict) else None
    if isinstance(pr, str) and pr.strip():
        return pr.strip()

    env_pr = os.getenv("PRIVATE_ROOT")
    if isinstance(env_pr, str) and env_pr.strip():
        return env_pr.strip()

    # Compatibility fallback; prefer bootstrap paths when available.
    return "/web/private"


def _load_json(path: str) -> Dict[str, Any]:
    if not path:
        return {}
    try:
        with open(path, "r", encoding="utf-8") as f:
            data = json.load(f)
        return data if isinstance(data, dict) else {}
    except FileNotFoundError:
        return {}
    except Exception:
        return {}


def _load_db_settings(db_path: str) -> Dict[str, str]:
    if not db_path:
        return {}
    try:
        conn = sqlite3.connect(db_path)
        try:
            rows = conn.execute(
                "SELECT key, value FROM app_settings WHERE key IN (?,?,?)",
                (
                    "ai.ollama.url",
                    "ai.ollama.model",
                    "search.api.base",
                ),
            ).fetchall()
        finally:
            conn.close()
        out: Dict[str, str] = {}
        for k, v in rows:
            if isinstance(k, str) and v is not None:
                out[k] = str(v)
        return out
    except Exception:
        return {}


def _json_decode_maybe(raw: Any) -> Any:
    if not isinstance(raw, str):
        return raw
    try:
        return json.loads(raw)
    except Exception:
        return raw


def _load_codewalker_settings(db_path: str) -> Dict[str, Any]:
    if not db_path:
        return {}
    try:
        conn = sqlite3.connect(db_path)
        try:
            rows = conn.execute(
                "SELECT key, value FROM settings WHERE key IN (?,?,?,?,?,?,?)",
                (
                    "base_url",
                    "model",
                    "search_api_base",
                    "use_active_ai",
                    "provider",
                    "ollama_base_url",
                    "llm_base_url",
                ),
            ).fetchall()
        finally:
            conn.close()

        out: Dict[str, Any] = {}
        for k, v in rows:
            if isinstance(k, str):
                out[k] = _json_decode_maybe(v)
        return out
    except Exception:
        return {}


def get_config(
    notes_db_path: str | None = None,
    default_json_path: str | None = None,
) -> Dict[str, str]:
    private_root = get_private_root(__file__)

    notes_db_path = notes_db_path or os.getenv("NOTES_DB") or os.path.join(private_root, "db/memory/human_notes.db")
    default_json_path = (
        default_json_path
        or os.getenv("NOTES_DEFAULT_JSON")
        or os.path.join(private_root, "notes_default.json")
    )

    cfg: Dict[str, str] = dict(DEFAULTS)

    file_cfg = _load_json(default_json_path)
    for k in DEFAULTS.keys():
        v = file_cfg.get(k)
        if isinstance(v, str) and v.strip():
            cfg[k] = v.strip()

    db_cfg = _load_db_settings(notes_db_path)
    for k, v in db_cfg.items():
        if isinstance(v, str) and v.strip():
            cfg[k] = v.strip()

    # Prefer CodeWalker settings DB for AI/search endpoints so scripts follow admin/codew_config.php.
    cw_db_path = os.getenv("CODEWALKER_SETTINGS_DB") or os.path.join(private_root, "db/codewalker_settings.db")
    cw_cfg = _load_codewalker_settings(cw_db_path)

    cw_base = cw_cfg.get("base_url")
    if not isinstance(cw_base, str) or not cw_base.strip():
        # Backward-compatible fallback if legacy key is present.
        alt_base = cw_cfg.get("ollama_base_url") or cw_cfg.get("llm_base_url")
        if isinstance(alt_base, str):
            cw_base = alt_base

    if isinstance(cw_base, str) and cw_base.strip():
        cfg["ai.ollama.url"] = cw_base.strip().rstrip("/")

    cw_model = cw_cfg.get("model")
    if isinstance(cw_model, str) and cw_model.strip():
        cfg["ai.ollama.model"] = cw_model.strip()

    cw_search = cw_cfg.get("search_api_base")
    if isinstance(cw_search, str) and cw_search.strip():
        cfg["search.api.base"] = cw_search.strip()

    return cfg
