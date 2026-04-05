#!/usr/bin/env python3
"""
Alive Agent for AgentHive (notes + code aware).
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

from agent_common import AGENT_BOOT_PATH, AGENT_TOOL_SETTINGS_PATH, APP_ROOT, DEFAULT_NOTES_DB
from agent_config import load_agent_profile, load_tool_settings_from_path
from agent_runtime import AliveAgent
from agent_shell import build_parser, interactive_loop


def main() -> int:
    args = build_parser().parse_args()
    profile = load_agent_profile(args.config_file)
    tool_settings_path = args.tool_settings_path or str(profile.get("tool_settings_path", "") or AGENT_TOOL_SETTINGS_PATH)
    tool_settings = load_tool_settings_from_path(tool_settings_path)
    memory_cfg = tool_settings.get("memory", {})
    if not isinstance(memory_cfg, dict):
        memory_cfg = {}
    memory_cfg["enabled"] = bool(profile.get("memory_enabled", True))
    tool_settings["memory"] = memory_cfg
    debug_enabled = args.debug if args.debug is not None else bool(profile.get("debug", False))
    interactive_enabled = args.interactive if args.interactive is not None else bool(profile.get("interactive", True))
    output_mode = str(args.output_mode or str(profile.get("output_mode", "") or "plain")).strip().lower()
    if output_mode not in ("plain", "json"):
        output_mode = "plain"

    agent = AliveAgent(
        model=args.model or str(profile.get("model", "") or "openai/gpt-oss-20b"),
        base_url=args.base_url or str(profile.get("base_url", "") or "http://127.0.0.1:1234/v1"),
        api_key=args.api_key if args.api_key is not None else str(profile.get("api_key", "") or ""),
        provider=str(profile.get("provider", "") or ""),
        notes_db=Path(args.notes_db or str(profile.get("notes_db", "") or DEFAULT_NOTES_DB)),
        code_root=Path(args.code_root or str(profile.get("code_root", "") or APP_ROOT)),
        tool_settings=tool_settings,
        boot_prompt_path=Path(args.boot_prompt_path or str(profile.get("boot_prompt_path", "") or AGENT_BOOT_PATH)),
        max_steps=int(args.max_steps if args.max_steps is not None else int(profile.get("max_steps", profile.get("step_budget", 6)) or profile.get("step_budget", 6) or 6)),
        temperature=float(args.temperature if args.temperature is not None else float(profile.get("temperature", 0.2) or 0.2)),
    )
    agent.startup_greeting_enabled = bool(profile.get("startup_greeting_enabled", False))
    agent.startup_greeting_prompt = str(profile.get("startup_greeting_prompt", "") or "")
    agent.profile_name = str(profile.get("profile_name", "") or "")
    agent.task_name = str(profile.get("task_name", "") or "")
    agent.profile_mode = str(profile.get("mode", "") or "")
    agent.profile_description = str(profile.get("description", "") or "")

    def build_query_text(raw_query: str) -> str:
        base_query = str(raw_query or "").strip()
        if base_query == "":
            base_query = str(profile.get("default_query", "") or "").strip()
        task_prompt = str(profile.get("task_prompt", "") or "").strip()
        if task_prompt != "" and base_query != "":
            return task_prompt + "\n\n" + base_query
        if task_prompt != "":
            return task_prompt
        return base_query

    def maybe_write_report(payload_text: str, payload_data: object) -> None:
        if not bool(profile.get("write_report", False)):
            return
        report_target = str(profile.get("report_target", "") or "").strip()
        if report_target == "":
            return
        target = Path(report_target)
        target.parent.mkdir(parents=True, exist_ok=True)
        report_type = str(profile.get("report_type", "") or output_mode).strip().lower()
        if report_type == "json":
            target.write_text(json.dumps(payload_data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            return
        target.write_text(payload_text + ("\n" if payload_text and not payload_text.endswith("\n") else ""), encoding="utf-8")

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

    query_text = build_query_text(args.query)

    if query_text:
        try:
            answer = agent.run(query_text, debug=debug_enabled)
            if output_mode == "json":
                payload = {
                    "ok": True,
                    "profile_name": str(profile.get("profile_name", "") or ""),
                    "task_name": str(profile.get("task_name", "") or ""),
                    "mode": str(profile.get("mode", "") or ""),
                    "query": query_text,
                    "response": answer,
                }
                output_text = json.dumps(payload, ensure_ascii=False, indent=2)
                print(output_text)
                maybe_write_report(output_text, payload)
            else:
                print(answer)
                maybe_write_report(answer, {"ok": True, "response": answer})
            return 0
        except Exception as e:
            if output_mode == "json":
                payload = {
                    "ok": False,
                    "profile_name": str(profile.get("profile_name", "") or ""),
                    "task_name": str(profile.get("task_name", "") or ""),
                    "mode": str(profile.get("mode", "") or ""),
                    "query": query_text,
                    "error": str(e),
                }
                output_text = json.dumps(payload, ensure_ascii=False, indent=2)
                print(output_text, file=sys.stderr)
                maybe_write_report(output_text, payload)
            print("Agent run failed: %s" % e, file=sys.stderr)
            print("Hint: run --list-models and choose a loaded --model.", file=sys.stderr)
            return 1

    if not interactive_enabled:
        print("No query provided and interactive mode is disabled.", file=sys.stderr)
        return 1

    return interactive_loop(
        agent,
        debug=debug_enabled,
        startup_greeting_enabled=agent.startup_greeting_enabled,
        startup_greeting_prompt=agent.startup_greeting_prompt,
    )


if __name__ == "__main__":
    raise SystemExit(main())
