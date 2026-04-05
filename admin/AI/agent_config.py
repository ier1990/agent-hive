#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import sqlite3
from pathlib import Path
from typing import Any, Dict, Optional

from agent_common import AGENT_BOOT_PATH, AGENT_DB_PATH, AGENT_MEMORY_DB_PATH, AGENT_TOOL_SETTINGS_PATH, AI_SETTINGS_DB_PATH, APP_ROOT, DEFAULT_AGENT_CONFIG_PATH, DEFAULT_NOTES_DB, PRIVATE_AGENT_CONFIG_PATH, PRIVATE_ENV_PATH


def ai_base_ensure_v1(base_url: str) -> str:
    base = (base_url or "").strip().rstrip("/")
    if not base:
        return ""
    if not base.endswith("/v1"):
        base += "/v1"
    return base


def load_private_env() -> Dict[str, str]:
    out = {}
    if not PRIVATE_ENV_PATH.exists():
        return out

    try:
        lines = PRIVATE_ENV_PATH.read_text(encoding="utf-8", errors="replace").splitlines()
    except Exception:
        return out

    for raw_line in lines:
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if not key:
            continue
        if len(value) >= 2 and ((value[0] == '"' and value[-1] == '"') or (value[0] == "'" and value[-1] == "'")):
            value = value[1:-1]
        out[key] = value
    return out


def env_value(key: str, env_file: Dict[str, str], default: str = "") -> str:
    value = os.environ.get(key)
    if value is not None and value != "":
        return value
    value = env_file.get(key)
    if value is not None and value != "":
        return value
    return default


def bool_value(value: Any, default: bool = False) -> bool:
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return bool(value)
    text = str(value or "").strip().lower()
    if text in ("1", "true", "yes", "on"):
        return True
    if text in ("0", "false", "no", "off", ""):
        return False
    return default


def load_ai_settings_raw() -> Dict[str, Any]:
    defaults = {
        "backend": "lmstudio",
        "provider": "local",
        "base_url": "http://127.0.0.1:1234",
        "api_key": "",
        "model": "openai/gpt-oss-20b",
        "model_timeout_seconds": 900,
        "openai_base_url": "",
        "llm_base_url": "",
        "ollama_base_url": "",
    }

    if not AI_SETTINGS_DB_PATH.exists():
        return defaults

    out = dict(defaults)
    try:
        conn = sqlite3.connect(str(AI_SETTINGS_DB_PATH))
        try:
            rows = conn.execute("SELECT key, value FROM settings").fetchall()
        finally:
            conn.close()
    except Exception:
        return out

    for key, raw_value in rows:
        key = str(key or "").strip()
        if not key:
            continue
        text = raw_value if isinstance(raw_value, str) else str(raw_value)
        try:
            out[key] = json.loads(text)
        except Exception:
            out[key] = text
    return out


def resolve_shared_ai_settings() -> Dict[str, Any]:
    raw = load_ai_settings_raw()
    env_file = load_private_env()

    backend = str(raw.get("backend", "") or "").strip().lower()
    provider = str(raw.get("provider", "") or "").strip().lower()
    model = str(raw.get("model", "") or "")
    timeout = int(raw.get("model_timeout_seconds", 0) or 0)
    if timeout < 1:
        timeout = 120

    if provider == "":
        if backend == "openai":
            provider = "openai"
        elif backend == "ollama":
            provider = "ollama"
        else:
            provider = "local"

    env_openai_base = env_value("OPENAI_BASE_URL", env_file, "")
    env_openai_key = env_value("OPENAI_API_KEY", env_file, "")
    env_openai_model = env_value("OPENAI_MODEL", env_file, "")
    env_llm_base = env_value("LLM_BASE_URL", env_file, "")
    env_llm_key = env_value("LLM_API_KEY", env_file, "")
    env_ollama_base = env_value("OLLAMA_BASE_URL", env_file, "")
    if env_ollama_base == "":
        env_ollama_base = env_value("OLLAMA_HOST", env_file, "")

    base = str(raw.get("base_url", "") or "")
    openai_base = str(raw.get("openai_base_url", "") or "")
    llm_base = str(raw.get("llm_base_url", "") or "")
    ollama_base = str(raw.get("ollama_base_url", "") or "")
    api_key = str(raw.get("api_key", "") or "")

    if provider == "openai":
        base_resolved = openai_base or env_openai_base or "https://api.openai.com"
        key_resolved = api_key or env_openai_key
        model_resolved = model or env_openai_model or "gpt-4o-mini"
    elif provider == "ollama":
        base_resolved = ollama_base or llm_base or base or env_ollama_base or env_llm_base or "http://127.0.0.1:11434"
        key_resolved = api_key or env_llm_key
        model_resolved = model or "llama3"
    elif provider in ("local", "lmstudio"):
        base_resolved = llm_base or base or env_llm_base or "http://127.0.0.1:1234"
        key_resolved = api_key or env_llm_key
        model_resolved = model or "openai/gpt-oss-20b"
    elif provider == "anthropic":
        base_resolved = base or env_openai_base or "https://api.anthropic.com"
        key_resolved = api_key or env_openai_key
        model_resolved = model or "claude-3-5-sonnet-20241022"
    elif provider == "openrouter":
        base_resolved = base or "https://openrouter.ai/api/v1"
        key_resolved = api_key or env_openai_key
        model_resolved = model or "openai/gpt-4-turbo"
    elif provider == "custom":
        base_resolved = base or llm_base or "http://127.0.0.1:1234"
        key_resolved = api_key
        model_resolved = model or ""
    else:
        base_resolved = llm_base or base or env_llm_base or "http://127.0.0.1:1234"
        key_resolved = api_key or env_llm_key
        model_resolved = model or "openai/gpt-oss-20b"

    return {
        "provider": provider,
        "backend": backend,
        "base_url": ai_base_ensure_v1(base_resolved),
        "api_key": key_resolved,
        "model": model_resolved,
        "timeout_seconds": timeout,
    }


def default_tool_settings() -> Dict[str, Any]:
    env_file = load_private_env()
    return {
        "search": {
            "enabled": True,
            "searx_url": env_value("SEARX_URL", env_file, ""),
            "timeout_seconds": 20,
            "result_limit": 5,
        },
        "agent_tools": {
            "enabled": True,
            "db_path": str(AGENT_DB_PATH),
            "execution_timeout_seconds": 30,
            "max_list_items": 50,
        },
        "memory": {
            "enabled": True,
            "db_path": str(AGENT_MEMORY_DB_PATH),
            "autoload_on_start": False,
            "autoload_limit": 10,
            "default_search_limit": 8,
            "max_write_length": 4000,
        }
    }


def load_tool_settings() -> Dict[str, Any]:
    defaults = default_tool_settings()

    if not AGENT_TOOL_SETTINGS_PATH.exists():
        try:
            AGENT_TOOL_SETTINGS_PATH.parent.mkdir(parents=True, exist_ok=True)
            AGENT_TOOL_SETTINGS_PATH.write_text(json.dumps(defaults, indent=2) + "\n", encoding="utf-8")
        except Exception:
            return defaults
        return defaults

    try:
        raw = json.loads(AGENT_TOOL_SETTINGS_PATH.read_text(encoding="utf-8"))
    except Exception:
        return defaults

    if not isinstance(raw, dict):
        return defaults

    out = dict(defaults)
    for key, value in raw.items():
        if isinstance(value, dict) and isinstance(out.get(key), dict):
            merged = dict(out[key])
            for subkey, subvalue in value.items():
                if isinstance(subvalue, str) and subvalue.strip() == "":
                    continue
                merged[subkey] = subvalue
            out[key] = merged
        else:
            out[key] = value
    return out


def default_agent_config() -> Dict[str, Any]:
    return {
        "name": "AgentHive Default Agent",
        "profile_name": "default",
        "task_name": "",
        "description": "",
        "mode": "shell",
        "provider": "",
        "backend": "",
        "base_url": "",
        "api_key": "",
        "api_key_env": "",
        "model": "",
        "timeout_seconds": 120,
        "notes_db": str(DEFAULT_NOTES_DB),
        "code_root": str(APP_ROOT),
        "boot_prompt_path": str(AGENT_BOOT_PATH),
        "tool_settings_path": str(AGENT_TOOL_SETTINGS_PATH),
        "max_steps": 6,
        "step_budget": 6,
        "temperature": 0.2,
        "debug": False,
        "interactive": True,
        "output_mode": "plain",
        "plain_output": True,
        "write_report": False,
        "report_type": "plain",
        "report_target": "",
        "memory_enabled": True,
        "allowed_tools": [],
        "default_query": "",
        "task_prompt": "",
    }


def load_json_file(path: Path) -> Dict[str, Any]:
    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return {}
    return raw if isinstance(raw, dict) else {}


def merge_agent_config(base: Dict[str, Any], override: Dict[str, Any]) -> Dict[str, Any]:
    out = dict(base)
    for key, value in override.items():
        if isinstance(value, str) and value.strip() == "":
            continue
        out[key] = value
    return out


def load_default_agent_template() -> Dict[str, Any]:
    defaults = default_agent_config()
    if not DEFAULT_AGENT_CONFIG_PATH.exists():
        return defaults
    return merge_agent_config(defaults, load_json_file(DEFAULT_AGENT_CONFIG_PATH))


def load_private_agent_config() -> Dict[str, Any]:
    if not PRIVATE_AGENT_CONFIG_PATH.exists():
        return {}
    return load_json_file(PRIVATE_AGENT_CONFIG_PATH)

def load_agent_profile(config_path_text: str = "") -> Dict[str, Any]:
    defaults = load_default_agent_template()
    private = load_private_agent_config()
    php = resolve_shared_ai_settings()
    env_file = load_private_env()

    out = dict(defaults)
    out["provider"] = str(php.get("provider", "") or out.get("provider", ""))
    out["backend"] = str(php.get("backend", "") or out.get("backend", ""))
    out["base_url"] = str(php.get("base_url", "") or out.get("base_url", ""))
    out["api_key"] = str(php.get("api_key", "") or out.get("api_key", ""))
    out["model"] = str(php.get("model", "") or out.get("model", ""))
    out["timeout_seconds"] = int(php.get("timeout_seconds", out.get("timeout_seconds", 120)) or out.get("timeout_seconds", 120))

    if private:
        out = merge_agent_config(out, private)

    if str(config_path_text or "").strip() != "":
        out = merge_agent_config(out, load_json_file(Path(str(config_path_text))))

    api_key_env = str(out.get("api_key_env", "") or "").strip()
    if api_key_env != "":
        resolved_api_key = env_value(api_key_env, env_file, "")
        if resolved_api_key != "":
            out["api_key"] = resolved_api_key

    out["notes_db"] = str(out.get("notes_db", "") or DEFAULT_NOTES_DB)
    out["code_root"] = str(out.get("code_root", "") or APP_ROOT)
    out["boot_prompt_path"] = str(out.get("boot_prompt_path", "") or AGENT_BOOT_PATH)
    out["tool_settings_path"] = str(out.get("tool_settings_path", "") or AGENT_TOOL_SETTINGS_PATH)
    out["profile_name"] = str(out.get("profile_name", "") or "default")
    out["task_name"] = str(out.get("task_name", "") or "")
    out["description"] = str(out.get("description", "") or "")
    out["mode"] = str(out.get("mode", "") or "shell")
    out["api_key_env"] = api_key_env
    step_budget = int(out.get("step_budget", out.get("max_steps", 6)) or out.get("max_steps", 6) or 6)
    out["step_budget"] = step_budget
    out["max_steps"] = int(out.get("max_steps", step_budget) or step_budget)
    out["temperature"] = float(out.get("temperature", 0.2) or 0.2)
    out["debug"] = bool_value(out.get("debug", False), False)
    out["interactive"] = bool_value(out.get("interactive", True), True)
    out["startup_greeting_enabled"] = bool_value(out.get("startup_greeting_enabled", False), False)
    out["plain_output"] = bool_value(out.get("plain_output", True), True)
    out["write_report"] = bool_value(out.get("write_report", False), False)
    out["memory_enabled"] = bool_value(out.get("memory_enabled", True), True)
    out["output_mode"] = str(out.get("output_mode", "") or ("plain" if out["plain_output"] else "json")).strip().lower()
    if out["output_mode"] not in ("plain", "json"):
        out["output_mode"] = "plain"
    out["report_type"] = str(out.get("report_type", "") or out["output_mode"]).strip().lower()
    if out["report_type"] not in ("plain", "json"):
        out["report_type"] = out["output_mode"]
    out["report_target"] = str(out.get("report_target", "") or "")
    out["default_query"] = str(out.get("default_query", "") or "")
    out["task_prompt"] = str(out.get("task_prompt", "") or "")
    timeout_seconds = int(out.get("timeout_seconds", 120) or 120)
    if timeout_seconds < 1:
        timeout_seconds = 120
    out["timeout_seconds"] = timeout_seconds
    allowed_tools = out.get("allowed_tools", [])
    out["allowed_tools"] = allowed_tools if isinstance(allowed_tools, list) else []
    return out


def load_tool_settings_from_path(path_text: str) -> Dict[str, Any]:
    defaults = default_tool_settings()
    path = Path(path_text or str(AGENT_TOOL_SETTINGS_PATH))

    if not path.exists():
        try:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(json.dumps(defaults, indent=2) + "\n", encoding="utf-8")
        except Exception:
            return defaults
        return defaults

    raw = load_json_file(path)
    if not raw:
        return defaults

    out = dict(defaults)
    for key, value in raw.items():
        if isinstance(value, dict) and isinstance(out.get(key), dict):
            merged = dict(out[key])
            for subkey, subvalue in value.items():
                if isinstance(subvalue, str) and subvalue.strip() == "":
                    continue
                merged[subkey] = subvalue
            out[key] = merged
        else:
            out[key] = value
    return out
