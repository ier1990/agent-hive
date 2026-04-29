#!/usr/bin/env python3
"""Claude SDK provider — OPTIONAL.

Requires:  pip install anthropic

This module is never imported by the core agent unless the user explicitly
sets provider.name = "claude_sdk" in their config. The anthropic package is
NOT listed in any requirements file; it must be installed manually.

Privacy note: Using this provider sends your conversation to Anthropic's
servers. If you work with sensitive data (passwords, SSH keys, API keys),
use the default local_openai_compat provider instead.

Prompt caching is enabled automatically for the system prompt and long
conversation tails to reduce latency and cost.
"""
from __future__ import annotations

from typing import Any

from providers.base import BaseProvider

# Deferred import — SDK is checked at __init__ time, not module load time.
# This means `from providers import list_providers` never fails even when
# anthropic is not installed.
_anthropic_mod: Any = None


def _require_anthropic() -> Any:
    global _anthropic_mod
    if _anthropic_mod is None:
        try:
            import anthropic
            _anthropic_mod = anthropic
        except ImportError as exc:
            raise ImportError(
                "The 'anthropic' package is required for the claude_sdk provider.\n"
                "Install it with:  pip install anthropic\n"
                "Or switch to the default local provider (no cloud, no install needed)."
            ) from exc
    return _anthropic_mod


class ClaudeSdkProvider(BaseProvider):
    def __init__(self, config: dict[str, Any]) -> None:
        ant = _require_anthropic()  # raises ImportError if not installed

        self._model      = str(config.get("model", "claude-sonnet-4-6"))
        self._max_tokens = int(config.get("max_tokens", 1024) or 1024)
        api_key          = str(config.get("api_key", "") or "")

        # api_key=None → SDK reads ANTHROPIC_API_KEY from environment
        self._client = ant.Anthropic(api_key=api_key or None)

    @property
    def name(self) -> str:
        return "claude_sdk"

    # ------------------------------------------------------------------
    # Public interface
    # ------------------------------------------------------------------

    def generate(self, messages: list[dict[str, Any]], system: str = "", **kwargs: Any) -> str:
        max_tokens = int(kwargs.get("max_tokens", self._max_tokens))

        # Build system with prompt caching so repeated boot prompts are cheap.
        system_param: list[dict[str, Any]] | str = []
        if system:
            system_param = [
                {
                    "type": "text",
                    "text": system,
                    "cache_control": {"type": "ephemeral"},
                }
            ]

        # Cache the latest user turn when history is long enough to benefit.
        anthropic_messages = self._prepare_messages(messages)

        api_kwargs: dict[str, Any] = {
            "model":      self._model,
            "max_tokens": max_tokens,
            "messages":   anthropic_messages,
        }
        if system_param:
            api_kwargs["system"] = system_param

        response = self._client.messages.create(**api_kwargs)
        return str(response.content[0].text)

    def is_available(self) -> bool:
        try:
            _require_anthropic()
            return True
        except ImportError:
            return False

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------

    def _prepare_messages(self, messages: list[dict[str, Any]]) -> list[dict[str, Any]]:
        """Copy messages and add cache_control to the last user turn if history is deep."""
        result: list[dict[str, Any]] = list(messages)

        # Only cache if there are enough prior turns to make it worthwhile.
        if len(result) < 4:
            return result

        # Find the last user message and wrap its content for caching.
        for i in range(len(result) - 1, -1, -1):
            if result[i].get("role") == "user":
                msg = dict(result[i])
                content = msg["content"]
                if isinstance(content, str):
                    msg["content"] = [
                        {
                            "type":          "text",
                            "text":          content,
                            "cache_control": {"type": "ephemeral"},
                        }
                    ]
                result[i] = msg
                break

        return result
