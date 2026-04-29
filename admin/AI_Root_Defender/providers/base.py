#!/usr/bin/env python3
from __future__ import annotations

from abc import ABC, abstractmethod
from typing import Any


class BaseProvider(ABC):
    """Vendor-neutral interface all provider adapters must implement."""

    @property
    @abstractmethod
    def name(self) -> str:
        """Unique slug that matches the registry key (e.g. 'local_openai_compat')."""
        ...

    @abstractmethod
    def generate(self, messages: list[dict[str, Any]], system: str = "", **kwargs: Any) -> str:
        """Send a message list to the model and return the raw text response.

        Args:
            messages: Conversation history as [{"role": "user"|"assistant", "content": str}, ...]
            system:   System prompt string (empty string = no system prompt)
            **kwargs: Optional hints: max_tokens, temperature, etc.

        Returns:
            Raw model response text (may be JSON, prose, or mixed).
        """
        ...

    def is_available(self) -> bool:
        """Return True if the provider can accept requests right now.

        Default implementation always returns True. Override to do a
        lightweight health check (e.g. ping the local endpoint).
        """
        return True
