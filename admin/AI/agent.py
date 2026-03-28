#!/usr/bin/env python3
"""
Alive Agent for AgentHive (notes + code aware).
"""

from __future__ import annotations

import sys
from pathlib import Path

from agent_common import AGENT_BOOT_PATH, AGENT_TOOL_SETTINGS_PATH, APP_ROOT, DEFAULT_NOTES_DB
from agent_config import load_agent_profile, load_tool_settings_from_path
from agent_runtime import AliveAgent
from agent_shell import build_parser, interactive_loop


def main() -> int:
    args = build_parser().parse_args()
    profile = load_agent_profile()
    tool_settings_path = args.tool_settings_path or str(profile.get("tool_settings_path", "") or AGENT_TOOL_SETTINGS_PATH)
    tool_settings = load_tool_settings_from_path(tool_settings_path)

    agent = AliveAgent(
        model=args.model or str(profile.get("model", "") or "openai/gpt-oss-20b"),
        base_url=args.base_url or str(profile.get("base_url", "") or "http://127.0.0.1:1234/v1"),
        api_key=args.api_key if args.api_key is not None else str(profile.get("api_key", "") or ""),
        provider=str(profile.get("provider", "") or ""),
        notes_db=Path(args.notes_db or str(profile.get("notes_db", "") or DEFAULT_NOTES_DB)),
        code_root=Path(args.code_root or str(profile.get("code_root", "") or APP_ROOT)),
        tool_settings=tool_settings,
        boot_prompt_path=Path(args.boot_prompt_path or str(profile.get("boot_prompt_path", "") or AGENT_BOOT_PATH)),
        max_steps=int(args.max_steps if args.max_steps is not None else int(profile.get("max_steps", 6) or 6)),
        temperature=float(args.temperature if args.temperature is not None else float(profile.get("temperature", 0.2) or 0.2)),
    )

    if args.list_models:
        try:
            models = agent.available_models()
            if not models:
                print("No models returned by %s/models" % agent.base_url.rstrip("/"))
                return 1
            for mid in models:
                print(mid)
            return 0
        except Exception as e:
            print("Failed to list models: %s" % e, file=sys.stderr)
            return 1

    if args.query:
        try:
            print(agent.run(args.query, debug=(args.debug if args.debug is not None else bool(profile.get("debug", False)))))
            return 0
        except Exception as e:
            print("Agent run failed: %s" % e, file=sys.stderr)
            print("Hint: run --list-models and choose a loaded --model.", file=sys.stderr)
            return 1

    return interactive_loop(agent, debug=(args.debug if args.debug is not None else bool(profile.get("debug", False))))


if __name__ == "__main__":
    raise SystemExit(main())
