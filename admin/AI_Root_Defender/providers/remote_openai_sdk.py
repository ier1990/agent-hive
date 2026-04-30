#!/usr/bin/env python3
"""Remote OpenAI-hosted provider.

Uses OpenAI's hosted API over HTTPS with the Python standard library only.
This provider is intended for OpenAI-hosted models where request semantics
may differ from local OpenAI-compatible servers.
"""
from __future__ import annotations

import json
import urllib.error
import urllib.request
from typing import Any

from providers.base import BaseProvider


class RemoteOpenAiSdkProvider(BaseProvider):
    def __init__(self, config: dict[str, Any]) -> None:
        self._base_url = str(config.get("base_url", "https://api.openai.com/v1")).rstrip("/")
        self._model = str(config.get("model", "gpt-5-mini") or "gpt-5-mini")
        self._api_key = str(config.get("api_key", "") or "")
        self._timeout = int(config.get("timeout", 60) or 60)
        self._max_tokens = int(config.get("max_tokens", 0) or 0)
        self._reasoning_effort = str(config.get("reasoning_effort", "") or "").strip()

    @property
    def name(self) -> str:
        return "remote_openai_sdk"

    def generate(self, messages: list[dict[str, Any]], system: str = "", **kwargs: Any) -> str:
        all_messages: list[dict[str, Any]] = []
        if system:
            all_messages.append({"role": "system", "content": system})
        all_messages.extend(messages)

        payload: dict[str, Any] = {
            "model": self._model,
            "messages": all_messages,
            "stream": False,
        }

        max_tokens = int(kwargs.get("max_tokens", self._max_tokens) or 0)
        if max_tokens > 0:
            payload["max_tokens"] = max_tokens

        reasoning_effort = str(kwargs.get("reasoning_effort", self._reasoning_effort) or "").strip()
        if reasoning_effort:
            payload["reasoning_effort"] = reasoning_effort

        raw = self._post("/chat/completions", payload)

        try:
            return str(raw["choices"][0]["message"]["content"])
        except (KeyError, IndexError, TypeError) as exc:
            raise RuntimeError(f"Unexpected response shape from OpenAI provider: {raw}") from exc

    def is_available(self) -> bool:
        if not self._api_key:
            return False
        try:
            req = self._build_request("/models", method="GET")
            with urllib.request.urlopen(req, timeout=5):
                return True
        except Exception:
            return False

    def _build_request(self, path: str, method: str = "POST", body: bytes | None = None) -> urllib.request.Request:
        url = self._base_url + path
        req = urllib.request.Request(url, data=body, method=method)
        req.add_header("Content-Type", "application/json")
        if not self._api_key:
            raise RuntimeError("OpenAI API key is required for remote_openai_sdk")
        req.add_header("Authorization", "Bearer " + self._api_key)
        return req

    def _post(self, path: str, payload: dict[str, Any]) -> dict[str, Any]:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        req = self._build_request(path, method="POST", body=body)
        try:
            with urllib.request.urlopen(req, timeout=self._timeout) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as exc:
            detail = exc.read().decode("utf-8", errors="replace")
            raise RuntimeError(f"HTTP {exc.code} from {self._base_url}{path}: {detail}") from exc
        except urllib.error.URLError as exc:
            raise RuntimeError(
                f"Cannot reach OpenAI provider at {self._base_url}{path}: {exc.reason}"
            ) from exc
