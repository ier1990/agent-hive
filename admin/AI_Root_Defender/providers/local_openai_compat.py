#!/usr/bin/env python3
"""Local OpenAI-compatible provider.

Works with any server that speaks the OpenAI /v1/chat/completions API:
  - Ollama        (http://localhost:11434/v1)
  - LM Studio     (http://localhost:1234/v1)
  - llama.cpp     (http://localhost:8080/v1)
  - vLLM, Aphrodite, TabbyAPI, etc.
  - OpenRouter or any cloud proxy (set base_url + api_key in config)

Zero external dependencies — uses Python stdlib urllib only.
This is the DEFAULT provider. The core agent runs on this alone.
"""
from __future__ import annotations

import json
import urllib.error
import urllib.request
from typing import Any

from providers.base import BaseProvider


class LocalOpenAICompatProvider(BaseProvider):
    def __init__(self, config: dict[str, Any]) -> None:
        self._base_url = str(config.get("base_url", "http://localhost:11434/v1")).rstrip("/")
        self._model    = str(config.get("model", "llama3.2"))
        self._api_key  = str(config.get("api_key", "") or "")
        self._timeout  = int(config.get("timeout", 60) or 60)

    @property
    def name(self) -> str:
        return "local_openai_compat"

    # ------------------------------------------------------------------
    # Public interface
    # ------------------------------------------------------------------

    def generate(self, messages: list[dict[str, Any]], system: str = "", **kwargs: Any) -> str:
        all_messages: list[dict[str, Any]] = []
        if system:
            all_messages.append({"role": "system", "content": system})
        all_messages.extend(messages)

        payload: dict[str, Any] = {
            "model":       self._model,
            "messages":    all_messages,
            "stream":      False,
            "temperature": float(kwargs.get("temperature", 0.3)),
        }
        if "max_tokens" in kwargs:
            payload["max_tokens"] = int(kwargs["max_tokens"])

        raw = self._post("/chat/completions", payload)

        try:
            return str(raw["choices"][0]["message"]["content"])
        except (KeyError, IndexError, TypeError) as exc:
            raise RuntimeError(f"Unexpected response shape from provider: {raw}") from exc

    def is_available(self) -> bool:
        """Ping /v1/models — fast health check, no model load required."""
        try:
            req = self._build_request("/models", method="GET")
            with urllib.request.urlopen(req, timeout=5):
                return True
        except Exception:
            return False

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------

    def _build_request(self, path: str, method: str = "POST", body: bytes | None = None) -> urllib.request.Request:
        url = self._base_url + path
        req = urllib.request.Request(url, data=body, method=method)
        req.add_header("Content-Type", "application/json")
        if self._api_key:
            req.add_header("Authorization", f"Bearer {self._api_key}")
        return req

    def _post(self, path: str, payload: dict[str, Any]) -> dict[str, Any]:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        req  = self._build_request(path, method="POST", body=body)
        try:
            with urllib.request.urlopen(req, timeout=self._timeout) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as exc:
            detail = exc.read().decode("utf-8", errors="replace")
            raise RuntimeError(f"HTTP {exc.code} from {self._base_url}{path}: {detail}") from exc
        except urllib.error.URLError as exc:
            raise RuntimeError(
                f"Cannot reach local provider at {self._base_url}{path}: {exc.reason}\n"
                "Is Ollama / LM Studio running? Check your base_url in config."
            ) from exc
