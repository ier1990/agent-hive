#!/usr/bin/env python3
from __future__ import annotations

import json
from pathlib import Path
from typing import Any, Dict, List, Tuple

from agent_common import (
    DEFAULT_SETTINGS_CONFIG_PATH,
    DEFAULT_TOOLS_CONFIG_PATH,
    EXTERNAL_SETTINGS_CONFIG_PATH,
    EXTERNAL_TOOLS_CONFIG_PATH,
)


DEFAULT_TOOL_SETTINGS: Dict[str, Any] = {
    "bash": {
        "enabled": True,
        "read_only_enabled": True,
        "db_path": "./bh/bash_history.db",
        "execution_timeout_seconds": 30,
        "max_command_length": 1200,
        "max_output_bytes": 12000,
        "proposal_limit": 100,
        "allowed_commands": [
            "pwd", "ls", "find", "rg", "grep", "cat", "sed", "head", "tail", "wc", "stat", "file"
        ],
        "blocked_tokens": [
            "|", ";", "&&", "||", ">", ">>", "<", "sudo", "curl", "wget", "ssh", "scp", "rsync",
            "rm", "mv", "cp", "chmod", "chown", "mkdir", "rmdir", "touch"
        ],
        "disallowed_commands": [],
        "disallowed_tokens": [],
        "allowed_roots": ["/web", "/var/log", "/etc/apache2", "/etc/nginx", "/etc/mysql", "/proc", "/tmp"],
        "proposal_store_backend": "sqlite",
        "proposal_jsonl_mirror": True,
        "proposal_jsonl_dir": "./bh/events",
        "prompt_context": {
            "enabled": True,
            "include_allowed_commands": True,
            "include_blocked_tokens": True,
            "include_single_command_examples": True,
            "recent_commands_count": 5,
            "top_commands_count": 5,
        },
    },
    "memory": {"enabled": True},
    "search": {"enabled": False},
}


# ------------------------------------------------------------------ #
# Provider plugin system                                             #
# ------------------------------------------------------------------ #
# providers list: index 0 = primary. Add more entries for bigger or  #
# smaller models. Switch at runtime: /provider <idx|label>           #
# Supported slugs: local_openai_compat, remote_openai_sdk, claude_sdk #
# Also accepts legacy format: {name: "slug", slug: {config}}         #
# ------------------------------------------------------------------ #
DEFAULT_AGENT_SETTINGS: Dict[str, Any] = {
    "shell": {
        "debug_default": False,
        "max_turns_per_session": 11,
        "blocked_tool_retry_limit": 2,
    },
    "editor": {
        "command": "",
        "candidates": ["nano", "vim", "vi", "emacs"],
    },
    "compose": {
        "auto_run": True,
        "preview_chars": 160,
    },
    "turn_generator": {
        "max_history_messages": 20,
        "usage_tracking": {
            "enabled": True,
            "show_after_turn": True,
            "chars_per_token": 4.0,
        },
    },
    "task_queue": {
        "enabled": True,
        "dir": "",
        "claim_glob": "*.json",
    },
    "provider": {
        # "active" can be an integer index or a label/slug string.
        "active": 0,
        "providers": [
            {
                # Provider 0 — primary (local/Ollama/vLLM/etc.)
                "name":     "local_openai_compat",
                "label":    "primary",
                "base_url": "http://localhost:11434/v1",
                "model":    "llama3.2",
                "api_key":  "",   # leave blank for local servers
                "timeout":  60,
            },
            # Example: add more providers here for larger/smaller models
            # {
            #     "name":     "remote_openai_sdk",
            #     "label":    "openai",
            #     "base_url": "https://api.openai.com/v1",
            #     "model":    "gpt-5-mini",
            #     "api_key":  "",
            #     "timeout":  90,
            #     "reasoning_effort": "medium",
            # },
            # {
            #     "name":     "local_openai_compat",
            #     "label":    "big",
            #     "base_url": "http://localhost:11434/v1",
            #     "model":    "llama3.3:70b",
            #     "api_key":  "",
            #     "timeout":  120,
            # },
            # {
            #     "name":    "claude_sdk",
            #     "label":   "claude",
            #     "model":   "claude-sonnet-4-6",
            #     "max_tokens": 4096,
            #     "api_key": "",   # or set ANTHROPIC_API_KEY env var
            # },
        ],
        # Legacy per-slug blocks — still honoured if "providers" list is absent.
        "local_openai_compat": {
            "base_url": "http://localhost:11434/v1",
            "model":    "llama3.2",
            "api_key":  "",
            "timeout":  60,
        },
        "claude_sdk": {
            "model":      "claude-sonnet-4-6",
            "max_tokens": 1024,
            "api_key":    "",
        },
        "remote_openai_sdk": {
            "base_url": "https://api.openai.com/v1",
            "model": "gpt-5-mini",
            "api_key": "",
            "timeout": 90,
            "reasoning_effort": "medium",
        },
    },
}


def _load_json(path: Path) -> Dict[str, Any]:
    if not path.exists():
        return {}
    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return {}
    return raw if isinstance(raw, dict) else {}


def _deep_merge(base: Dict[str, Any], override: Dict[str, Any]) -> Dict[str, Any]:
    out: Dict[str, Any] = dict(base)
    for key, value in override.items():
        if isinstance(value, dict) and isinstance(out.get(key), dict):
            out[key] = _deep_merge(out[key], value)
        else:
            out[key] = value
    return out


def load_agent_settings(
    local_path: Path | None = None,
    external_path: Path | None = None,
) -> Dict[str, Any]:
    default_cfg = _load_json(local_path or DEFAULT_SETTINGS_CONFIG_PATH)
    external_cfg = _load_json(external_path or EXTERNAL_SETTINGS_CONFIG_PATH)

    # Compatibility order:
    # hardcoded fallback <- repo default file <- private external override
    merged = _deep_merge(DEFAULT_AGENT_SETTINGS, default_cfg)
    merged = _deep_merge(merged, external_cfg)
    return merged


def load_tool_settings(local_path: Path | None = None, external_path: Path | None = None) -> Dict[str, Any]:
    default_tools_cfg = _load_json(local_path or DEFAULT_TOOLS_CONFIG_PATH)
    external_tools_cfg = _load_json(external_path or EXTERNAL_TOOLS_CONFIG_PATH)

    # Merge provider/agent settings from dedicated settings files.
    merged_agent_settings = load_agent_settings()

    # Compatibility order:
    # hardcoded fallback <- repo default file <- private external override
    merged = _deep_merge(DEFAULT_TOOL_SETTINGS, default_tools_cfg)
    merged = _deep_merge(merged, external_tools_cfg)
    merged = _deep_merge(merged, merged_agent_settings)
    return merged


def detect_external_agent_hive(external_path: Path | None = None) -> bool:
    return (external_path or EXTERNAL_TOOLS_CONFIG_PATH).exists()


def detect_external_agent_settings(external_path: Path | None = None) -> bool:
    return (external_path or EXTERNAL_SETTINGS_CONFIG_PATH).exists()


def get_active_provider_entry(
    settings: Dict[str, Any],
) -> Tuple[str, Dict[str, Any], str, int, List[Dict[str, Any]]]:
    """Resolve the active provider entry from the merged settings dict.

    Supports both new list format and legacy slug-keyed format.

    Returns:
        (slug, entry_cfg, label, index, providers_list)

        slug           — provider type slug e.g. "local_openai_compat"
        entry_cfg      — flat config dict passed to the provider class
        label          — human label (from "label" field or slug)
        index          — 0-based index in providers_list
        providers_list — full list of provider dicts (for enumeration)
    """
    prov = settings.get("provider", {})
    if not isinstance(prov, dict):
        prov = {}

    providers = prov.get("providers")
    if isinstance(providers, list) and providers:
        # New list format.
        active = prov.get("active", 0)
        idx = 0
        if isinstance(active, int):
            idx = max(0, min(active, len(providers) - 1))
        elif isinstance(active, str):
            for i, entry in enumerate(providers):
                if not isinstance(entry, dict):
                    continue
                if str(entry.get("label", "")) == active or str(entry.get("name", "")) == active:
                    idx = i
                    break
        entry = providers[idx]
        entry = dict(entry) if isinstance(entry, dict) else {}
        slug = str(entry.get("name", "local_openai_compat"))
        label = str(entry.get("label", slug))
        return slug, entry, label, idx, [dict(p) if isinstance(p, dict) else {} for p in providers]

    # Legacy format: {"name": "slug", "slug": {config_dict}}
    slug = str(prov.get("name", "local_openai_compat"))
    entry = dict(prov.get(slug, {}))
    entry["name"] = slug
    label = slug
    return slug, entry, label, 0, [entry]


def resolve_provider_by_ref(
    providers_list: List[Dict[str, Any]],
    ref: str,
) -> Tuple[int, Dict[str, Any]]:
    """Find a provider entry by integer index, label, or slug name.

    Returns:
        (index, entry_dict)

    Raises:
        ValueError if not found.
    """
    # Try integer index.
    try:
        idx = int(ref)
        if 0 <= idx < len(providers_list):
            return idx, dict(providers_list[idx])
        raise ValueError(f"Provider index {idx} out of range (0-{len(providers_list) - 1})")
    except ValueError as exc:
        if "out of range" in str(exc):
            raise

    # Try label or name match.
    ref_lower = ref.lower()
    for i, entry in enumerate(providers_list):
        if not isinstance(entry, dict):
            continue
        if str(entry.get("label", "")).lower() == ref_lower or str(entry.get("name", "")).lower() == ref_lower:
            return i, dict(entry)

    labels = [f'{i}:{e.get("label", e.get("name", "?"))}' for i, e in enumerate(providers_list) if isinstance(e, dict)]
    raise ValueError(f"Provider {ref!r} not found. Available: {', '.join(labels)}")
