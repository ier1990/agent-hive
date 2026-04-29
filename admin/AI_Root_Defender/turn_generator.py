#!/usr/bin/env python3
"""TurnGenerator — provider-agnostic AI turn loop.

Calls the active provider, builds conversation context, and parses
the JSON turn contract returned by the model.

The shell harness (agent_bash.py) owns state; this module owns only
the conversation history and the provider call.
"""
from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any, Optional

from providers import get_provider
from providers.base import BaseProvider

_BOOT_PROMPT_PATH = Path(__file__).resolve().parent / "agent_bash_boot.md"

def _load_system_prompt() -> str:
    if _BOOT_PROMPT_PATH.exists():
        return _BOOT_PROMPT_PATH.read_text(encoding="utf-8").strip()
    # Minimal fallback so the agent still works without the boot file.
    return (
        "You are Root Guardian, a read-only AI diagnostic assistant for Linux systems. "
        "You MUST reply ONLY with a valid JSON object matching the turn contract schema: "
        '{"state":"continue|needs_input|final","summary":"...","next_tool":"cmd or null",'
        '"reason":"...","ask_user":"question or null","final_answer":"text or null","confidence":0.9}. '
        "Never propose destructive commands. Propose one tool per turn."
    )


def _extract_json(text: str) -> dict[str, Any]:
    """Extract the first JSON object from a model response.

    Handles:
      - Bare JSON
      - JSON wrapped in markdown ```json ... ``` fences
      - JSON embedded in surrounding prose
    """
    text = text.strip()

    def _loads_relaxed(candidate: str) -> dict[str, Any]:
        try:
            result = json.loads(candidate)
            if isinstance(result, dict):
                return result
        except json.JSONDecodeError:
            repaired = _repair_common_json_escapes(candidate)
            if repaired != candidate:
                result = json.loads(repaired)
                if isinstance(result, dict):
                    return result
        raise ValueError("not valid json object")

    # 1. Bare JSON
    try:
        return _loads_relaxed(text)
    except Exception:
        pass

    # 2. Markdown code fence  ```json { ... } ```
    fence_match = re.search(r"```(?:json)?\s*(\{.*?\})\s*```", text, re.DOTALL)
    if fence_match:
        try:
            return _loads_relaxed(fence_match.group(1))
        except Exception:
            pass

    # 3. First { ... } block in surrounding prose
    brace_match = re.search(r"\{.*\}", text, re.DOTALL)
    if brace_match:
        try:
            return _loads_relaxed(brace_match.group(0))
        except Exception:
            pass

    raise ValueError(
        f"Could not extract a JSON turn contract from model response "
        f"(first 300 chars): {text[:300]!r}"
    )


def _repair_common_json_escapes(text: str) -> str:
    r"""Repair common invalid backslash escapes inside model-produced JSON strings.

    Example repaired sequences:
      \.  -> \\.
      \(  -> \\(
      \)  -> \\)
    """
    out: list[str] = []
    in_string = False
    escaped = False
    valid_escapes = set(['"', '\\', '/', 'b', 'f', 'n', 'r', 't', 'u'])

    for ch in text:
        if not in_string:
            out.append(ch)
            if ch == '"':
                in_string = True
                escaped = False
            continue

        if escaped:
            if ch not in valid_escapes:
                out.append('\\')
            out.append(ch)
            escaped = False
            continue

        if ch == '\\':
            out.append(ch)
            escaped = True
            continue

        out.append(ch)
        if ch == '"':
            in_string = False

    return ''.join(out)


class TurnGenerator:
    """Manages the conversation history and calls the provider each turn."""

    def __init__(
        self,
        provider_name: str,
        provider_config: dict[str, Any],
        runtime_access_context: str = "",
        extra_system_context: str = "",
        max_history_messages: int = 20,
    ) -> None:
        """
        Args:
            provider_name:   Slug from the registry, e.g. "local_openai_compat".
            provider_config: Full settings["provider"] dict (all providers' sub-configs).
        """
        self._provider: BaseProvider = get_provider(provider_name, provider_config)
        self._provider_cfg: dict[str, Any] = provider_config.get(provider_name, {}) if isinstance(provider_config, dict) else {}
        if not isinstance(self._provider_cfg, dict):
            self._provider_cfg = {}
        self._runtime_access_context: str = str(runtime_access_context or "").strip()
        self._extra_system_context: str = str(extra_system_context or "").strip()
        try:
            self._max_history_messages = max(1, int(max_history_messages))
        except Exception:
            self._max_history_messages = 20
        self._base_system_prompt: str = _load_system_prompt()
        self._system_prompt: str = ""
        self._rebuild_system_prompt()
        self._history: list[dict[str, Any]] = []

    def _rebuild_system_prompt(self) -> None:
        prompt = self._base_system_prompt
        if self._runtime_access_context:
            prompt += (
                "\n\n[Runtime access context]\n"
                + self._runtime_access_context
                + "\nUse this as factual access context for command safety and diagnostics."
            )
        if self._extra_system_context:
            prompt += (
                "\n\n[Tool context]\n"
                + self._extra_system_context
                + "\nUse this as current bash tool history and availability context."
            )
        self._system_prompt = prompt

    # ------------------------------------------------------------------
    # Properties
    # ------------------------------------------------------------------

    @property
    def provider_name(self) -> str:
        return self._provider.name

    @property
    def is_available(self) -> bool:
        return self._provider.is_available()

    @property
    def provider_model(self) -> str:
        model = self._provider_cfg.get("model")
        return str(model) if model else "unknown"

    @property
    def provider_endpoint(self) -> str:
        # local_openai_compat uses base_url; other providers may not expose one.
        endpoint = self._provider_cfg.get("base_url")
        return str(endpoint) if endpoint else "n/a"

    def switch_provider(self, slug: str, cfg_entry: dict[str, Any]) -> None:
        """Hot-swap the underlying provider without losing conversation history.

        Args:
            slug:      Provider type slug e.g. "local_openai_compat".
            cfg_entry: Flat config dict for this provider (as from providers list).
        """
        wrapped = {slug: cfg_entry}
        self._provider = get_provider(slug, wrapped)
        self._provider_cfg = cfg_entry if isinstance(cfg_entry, dict) else {}

    def set_extra_system_context(self, text: str) -> None:
        self._extra_system_context = str(text or "").strip()
        self._rebuild_system_prompt()

    def hello_check(self) -> tuple[bool, str]:
        """Run an explicit lightweight generation check.

        Returns:
            (ok, message) where message is human-readable status.
        """
        if not self.is_available:
            return (False, "provider endpoint not reachable")

        snapshot = list(self._history)
        try:
            contract = self.generate_contract("hello")
            state = str(contract.get("state", "")).strip().lower()
            if state not in {"continue", "needs_input", "final"}:
                return (False, "provider responded but contract state was invalid")
            return (True, "provider responded with valid turn contract")
        except Exception as exc:
            return (False, f"provider hello check failed: {exc}")
        finally:
            # Do not let hello probes pollute live conversation history.
            self._history = snapshot

    # ------------------------------------------------------------------
    # Conversation control
    # ------------------------------------------------------------------

    def reset(self) -> None:
        """Clear conversation history (start fresh turn sequence)."""
        self._history.clear()

    def inject_memory(self, text: str) -> None:
        """Prepend prior-session context as a synthetic user message.

        Call this after reset() if /memory recall should seed the context.
        """
        self._history.insert(0, {
            "role":    "user",
            "content": f"[Session memory recalled]\n{text}",
        })
        self._history.insert(1, {
            "role":    "assistant",
            "content": '{"state":"needs_input","summary":"Memory loaded. Ready.","next_tool":null,"reason":"","ask_user":null,"final_answer":null,"confidence":1.0}',
        })

    # ------------------------------------------------------------------
    # Core: generate a turn contract
    # ------------------------------------------------------------------

    def generate_contract(
        self,
        user_input: str,
        context: Optional[str] = None,
    ) -> dict[str, Any]:
        """Send user input to the provider and return a parsed turn contract.

        Args:
            user_input: Raw user message.
            context:    Optional extra context to prepend (e.g. /compose output).

        Returns:
            Parsed turn contract dict with keys:
            state, summary, next_tool, reason, ask_user, final_answer, confidence.

        Raises:
            RuntimeError: Provider unreachable or returned an unparseable response.
        """
        parts: list[str] = []
        if self._runtime_access_context:
            parts.append(f"[Runtime access]\n{self._runtime_access_context}")
        if context:
            parts.append(f"[Context]\n{context}")
        parts.append(f"[Request]\n{user_input}")
        content = "\n\n".join(parts)

        self._history.append({"role": "user", "content": content})

        # Sliding window to avoid blowing the context limit.
        window = self._history[-self._max_history_messages:]

        raw_text = self._provider.generate(
            messages=window,
            system=self._system_prompt,
        )

        self._history.append({"role": "assistant", "content": raw_text})

        return _extract_json(raw_text)

    def inject_tool_result(self, tool_command: str, result: dict[str, Any]) -> None:
        """Feed a tool execution result back into history as a user turn.

        Call this after a proposal is approved and executed so the model
        can reason about the output on the next user turn.

        Args:
            tool_command: The shell command that was run.
            result:       Dict with exit_code, stdout, stderr keys.
        """
        stdout = str(result.get("stdout", ""))[:3000]
        stderr = str(result.get("stderr", ""))[:500]
        exit_code = result.get("exit_code", "?")

        lines = [f"[Tool executed: {tool_command}]", f"exit_code={exit_code}"]
        if stdout:
            lines.append(f"stdout:\n{stdout}")
        if stderr:
            lines.append(f"stderr:\n{stderr}")

        self._history.append({"role": "user", "content": "\n".join(lines)})
