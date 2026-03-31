#!/usr/bin/env python3
from __future__ import annotations

import atexit
import argparse
from typing import Optional

from agent_common import DEFAULT_PRIVATE_ROOT, colorize, tty_supports_color
from agent_runtime import AliveAgent

try:
    import readline
except ImportError:
    readline = None


HISTORY_FILE = DEFAULT_PRIVATE_ROOT / "logs" / "agent_shell_history.log"
HISTORY_LENGTH = 500


def setup_readline() -> None:
    if readline is None:
        return
    try:
        readline.parse_and_bind("set editing-mode emacs")
    except Exception:
        pass
    try:
        readline.parse_and_bind("tab: complete")
    except Exception:
        pass
    try:
        readline.set_history_length(HISTORY_LENGTH)
    except Exception:
        pass
    if not HISTORY_FILE.parent.exists():
        return
    try:
        readline.read_history_file(str(HISTORY_FILE))
    except FileNotFoundError:
        pass
    except Exception:
        return

    def save_history() -> None:
        try:
            readline.set_history_length(HISTORY_LENGTH)
            readline.write_history_file(str(HISTORY_FILE))
        except Exception:
            return

    atexit.register(save_history)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Alive notes+code AI agent")
    parser.add_argument("--query", default="", help="Single-shot query. If omitted, runs interactive mode.")
    parser.add_argument("--list-models", action="store_true", help="List model IDs from base-url/models and exit.")
    parser.add_argument("--model", default=None, help="Model override. Defaults to the active PHP AI setup model.")
    parser.add_argument("--base-url", default=None, help="Base URL override. Defaults to the active PHP AI setup base URL.")
    parser.add_argument("--api-key", default=None, help="API key override. Defaults to the active PHP AI setup key.")
    parser.add_argument("--notes-db", default=None, help="Notes DB override. Defaults to agent.json or built-in default.")
    parser.add_argument("--code-root", default=None, help="Code root override. Defaults to agent.json or built-in default.")
    parser.add_argument("--boot-prompt-path", default=None, help="Boot prompt override. Defaults to agent.json or admin/AI/agent_boot.md.")
    parser.add_argument("--tool-settings-path", default=None, help="Tool settings override. Defaults to agent.json or /web/private/agent_tools.json.")
    parser.add_argument("--max-steps", type=int, default=None, help="Max steps override.")
    parser.add_argument("--temperature", type=float, default=None, help="Temperature override.")
    parser.add_argument("--debug", dest="debug", action="store_true", default=None, help="Enable debug logging.")
    parser.add_argument("--no-debug", dest="debug", action="store_false", help="Disable debug logging.")
    return parser


def banner_block(agent: AliveAgent, debug: bool) -> str:
    color = tty_supports_color()
    width = 76
    search_cfg = agent.tool_settings.get("search", {})
    search_url = ""
    search_state = "off"
    if isinstance(search_cfg, dict):
        search_url = str(search_cfg.get("searx_url", "") or "").strip()
        if bool(search_cfg.get("enabled", True)) and search_url != "":
            search_state = "ready"
        elif bool(search_cfg.get("enabled", True)):
            search_state = "unconfigured"

    def box_line(left: str, content: str, right: str, style: str) -> str:
        inner = " " + content[: width - 4].ljust(width - 4) + " "
        return colorize(left + inner + right, style, color)

    return "\n".join(
        [
            "",
            colorize("+" + "-" * (width - 2) + "+", "1;36", color),
            box_line("|", "AgentHive AI Shell", "|", "1;36"),
            box_line("|", "Backend: %s" % agent.backend_label(), "|", "36"),
            box_line("|", "Model: %s" % agent.model, "|", "36"),
            box_line("|", "Base URL: %s" % agent.base_url, "|", "36"),
            box_line("|", "Search: %s%s" % (search_state, (" | " + search_url) if search_url else ""), "|", "36"),
            box_line("|", "Debug: %s | Max steps: %s | Temp: %s" % (("on" if debug else "off"), agent.max_steps, agent.temperature), "|", "36"),
            box_line("|", "Commands: /help /status /debug /models /search /tools /clear /exit", "|", "33"),
            colorize("+" + "-" * (width - 2) + "+", "1;36", color),
        ]
    )


def status_block(agent: AliveAgent, debug: bool) -> str:
    search_cfg = agent.tool_settings.get("search", {})
    search_url = ""
    if isinstance(search_cfg, dict):
        search_url = str(search_cfg.get("searx_url", "") or "")
    startup_prompt = ""
    if hasattr(agent, "startup_greeting_prompt"):
        startup_prompt = str(getattr(agent, "startup_greeting_prompt", "") or "")
    startup_enabled = False
    if hasattr(agent, "startup_greeting_enabled"):
        startup_enabled = bool(getattr(agent, "startup_greeting_enabled", False))
    return "\n".join(
        [
            "Status",
            "------",
            "Backend    : %s" % agent.backend_label(),
            "Provider   : %s" % (agent.provider or "unknown"),
            "Model      : %s" % agent.model,
            "Base URL   : %s" % agent.base_url,
            "Search URL : %s" % search_url,
            "Temperature: %s" % agent.temperature,
            "Max steps  : %s" % agent.max_steps,
            "Code root  : %s" % agent.code_root,
            "Notes DB   : %s" % agent.notes_db,
            "Boot prompt: %s" % agent.boot_prompt_path,
            "Startup hi : %s" % ("on" if startup_enabled else "off"),
            "Hello text : %s" % startup_prompt,
            "Debug      : %s" % ("on" if debug else "off"),
        ]
    )


def help_block() -> str:
    return "\n".join(
        [
            "Slash commands",
            "--------------",
            "/help       Show this help",
            "/hello      Run the startup greeting bypass against the model",
            "/status     Show current backend, model, paths, and runtime settings",
            "/debug      Show whether debug mode is on",
            "/debug on   Enable debug logging to stderr",
            "/debug off  Disable debug logging to stderr",
            "/models     List models returned by the active backend",
            "/search     Show active search URL status from agent tools settings",
            "/memory     Show agent memory DB status",
            "/mem list   List recent durable memory entries",
            "/tools      Show DB-backed agent tools status",
            "/tools list List approved DB-backed tool names",
            "/clear      Redraw the banner",
            "/exit       Quit the shell",
        ]
    )


def handle_slash_command(agent: AliveAgent, command: str, debug: bool) -> Optional[bool]:
    normalized = command.strip()
    lower = normalized.lower()

    if lower == "/help":
        print("\n" + help_block())
        return debug
    if lower == "/hello":
        try:
            print("\n" + agent.startup_greeting())
        except Exception as e:
            print("\nStartup greeting failed: %s" % e)
        return debug
    if lower == "/status":
        print("\n" + status_block(agent, debug))
        return debug
    if lower == "/debug":
        print("\nDebug is %s." % ("on" if debug else "off"))
        return debug
    if lower == "/debug on":
        print("\nDebug enabled.")
        return True
    if lower == "/debug off":
        print("\nDebug disabled.")
        return False
    if lower == "/models":
        try:
            models = agent.available_models()
            if not models:
                print("\nNo models returned by %s/models" % agent.base_url.rstrip("/"))
            else:
                print("\nAvailable models:")
                for mid in models:
                    print("- %s" % mid)
        except Exception as e:
            print("\nFailed to list models: %s" % e)
        return debug
    if lower == "/search":
        cfg = agent.tool_settings.get("search", {})
        if not isinstance(cfg, dict):
            cfg = {}
        print("\nSearch status: enabled=%s url=%s" % ("yes" if bool(cfg.get("enabled", True)) else "no", str(cfg.get("searx_url", "") or "")))
        return debug
    if lower == "/memory":
        cfg = agent.tool_settings.get("memory", {})
        if not isinstance(cfg, dict):
            cfg = {}
        print(
            "\nAgent memory: enabled=%s db=%s autoload=%s limit=%s"
            % (
                "yes" if bool(cfg.get("enabled", True)) else "no",
                str(cfg.get("db_path", "") or ""),
                "yes" if bool(cfg.get("autoload_on_start", False)) else "no",
                str(cfg.get("autoload_limit", 10) or 10),
            )
        )
        return debug
    if lower in ("/mem list", "/memory list"):
        result = agent.list_memory_entries(10)
        if not result.get("ok"):
            print("\nFailed to list memory entries: %s" % str(result.get("error", "unknown_error")))
            if result.get("db_path"):
                print("DB path: %s" % str(result.get("db_path")))
            return debug
        items = result.get("items", [])
        if not isinstance(items, list) or not items:
            print("\nNo memory entries found.")
            return debug
        print("\nRecent memory:")
        for item in items:
            if not isinstance(item, dict):
                continue
            line = "- #%s" % str(item.get("id", "") or "")
            topic = str(item.get("topic", "") or "")
            if topic:
                line += " %s" % topic
            tags = str(item.get("tags", "") or "")
            if tags:
                line += " [%s]" % tags
            print(line)
            snippet = str(item.get("snippet", "") or "").strip()
            if snippet:
                print("  %s" % snippet[:160])
        return debug
    if lower == "/tools":
        cfg = agent.tool_settings.get("agent_tools", {})
        if not isinstance(cfg, dict):
            cfg = {}
        print(
            "\nAgent tools: enabled=%s db=%s"
            % ("yes" if bool(cfg.get("enabled", True)) else "no", str(cfg.get("db_path", "") or ""))
        )
        return debug
    if lower == "/tools list":
        result = agent.list_agent_tools(100)
        if not result.get("ok"):
            print("\nFailed to list approved tools: %s" % str(result.get("error", "unknown_error")))
            if result.get("path"):
                print("DB path: %s" % str(result.get("path")))
            return debug
        items = result.get("items", [])
        if not isinstance(items, list) or not items:
            print("\nNo approved tools found.")
            return debug
        print("\nApproved tools:")
        for item in items:
            if not isinstance(item, dict):
                continue
            name = str(item.get("name", "") or "")
            language = str(item.get("language", "") or "")
            description = str(item.get("description", "") or "")
            line = "- %s" % name
            if language:
                line += " [%s]" % language
            print(line)
            if description:
                print("  %s" % description)
        return debug
    if lower == "/clear":
        if tty_supports_color():
            print("\033[2J\033[H" + banner_block(agent, debug))
        else:
            print(banner_block(agent, debug))
        return debug
    if lower in ("/exit", "/quit"):
        return None

    print("\nUnknown command: %s" % normalized)
    print("Try /help")
    return debug


def interactive_loop(agent: AliveAgent, debug: bool, startup_greeting_enabled: bool = False, startup_greeting_prompt: str = "") -> int:
    debug_enabled = debug
    setup_readline()
    print(banner_block(agent, debug_enabled))
    if startup_greeting_enabled:
        try:
            greeting = agent.startup_greeting(startup_greeting_prompt)
            if greeting.strip() != "":
                print("\n" + greeting)
        except Exception as e:
            print("\nStartup greeting failed: %s" % e)
    while True:
        try:
            prompt = "\nagent"
            if debug_enabled:
                prompt += " [debug]"
            prompt += "> "
            user_q = input(prompt).strip()
        except (EOFError, KeyboardInterrupt):
            print("\nbye")
            return 0

        if not user_q:
            continue
        if user_q.lower() in ("exit", "quit"):
            print("bye")
            return 0
        if user_q.startswith("/"):
            cmd_result = handle_slash_command(agent, user_q, debug_enabled)
            if cmd_result is None:
                print("bye")
                return 0
            debug_enabled = cmd_result
            continue

        try:
            answer = agent.run(user_q, debug=debug_enabled)
            print("\n" + answer)
        except Exception as e:
            print("Agent error: %s" % e)
            print("Hint: run with --list-models or use /models to inspect models on the active backend.")
