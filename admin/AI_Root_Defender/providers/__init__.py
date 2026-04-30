#!/usr/bin/env python3
"""Provider plugin registry.

Adding a new provider:
  1. Drop a file in providers/<slug>.py that subclasses BaseProvider.
  2. Add an entry to _REGISTRY below: "slug" -> "providers.<slug>.<ClassName>"
  3. Add its default config block in agent_config.py under the "provider" key.

No other files need to change.
"""
from __future__ import annotations

import importlib
from typing import Any

from providers.base import BaseProvider

# Maps provider slug -> "module.path.ClassName"
_REGISTRY: dict[str, str] = {
    "local_openai_compat": "providers.local_openai_compat.LocalOpenAICompatProvider",
    "remote_openai_sdk":   "providers.remote_openai_sdk.RemoteOpenAiSdkProvider",
    "claude_sdk":          "providers.claude_sdk.ClaudeSdkProvider",
}


def list_providers() -> list[str]:
    """Return all registered provider slugs (not necessarily installed/available)."""
    return list(_REGISTRY.keys())


def get_provider(name: str, config: dict[str, Any]) -> BaseProvider:
    """Instantiate and return the named provider.

    Args:
        name:   Provider slug (e.g. "local_openai_compat", "claude_sdk").
        config: Full provider config dict from settings["provider"].
                The provider receives only its own sub-dict: config[name].

    Raises:
        ValueError:    Unknown provider slug.
        ImportError:   Optional SDK not installed (e.g. anthropic package).
        RuntimeError:  Provider-specific init failure.
    """
    if name not in _REGISTRY:
        available = ", ".join(f'"{k}"' for k in _REGISTRY)
        raise ValueError(f"Unknown provider: {name!r}. Available: {available}")

    dotted = _REGISTRY[name]
    module_path, class_name = dotted.rsplit(".", 1)

    mod = importlib.import_module(module_path)
    cls = getattr(mod, class_name)

    provider_cfg: dict[str, Any] = config.get(name, {})
    if not isinstance(provider_cfg, dict):
        provider_cfg = {}

    return cls(provider_cfg)
