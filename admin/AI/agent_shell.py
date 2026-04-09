#!/usr/bin/env python3
from __future__ import annotations

import atexit
import argparse
from typing import Optional

from agent_common import DEFAULT_PRIVATE_ROOT, colorize, tty_supports_color
from agent_input import AgentSessionLogger, PASTE_EDIT_MIN_LINES, build_session_history_prompt, list_composer_archives, load_composer_archive, load_prompt_from_file, newest_composer_archive, open_in_editor, read_user_input
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
    parser.add_argument("--config-file", default="", help="Optional agent profile JSON to merge after /web/private/agent.json and before direct CLI overrides.")
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
    parser.add_argument("--output-mode", default=None, choices=["plain", "json"], help="Single-shot output format override.")
    parser.add_argument("--interactive", dest="interactive", action="store_true", default=None, help="Force interactive shell mode.")
    parser.add_argument("--no-interactive", dest="interactive", action="store_false", help="Disable interactive shell mode.")
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
            "/paste      Enter multiline paste mode, finish with /end or cancel with /cancel",
            "/compose    Open $EDITOR to compose the next prompt",
            "/compose-last  Reopen the newest composer archive in $EDITOR",
            "/compose-load PATH  Reopen a composer archive or local file in $EDITOR",
            "/composer-history  List recent composer archives",
            "/edit-paste on|off  Review large multiline pastes in $EDITOR before sending",
            "/read PATH  Load a local file into the next prompt",
            "/load PATH  Same as /read",
            "/session    Show the current session log file",
            "/sessions-history on|off  Include current and recent session history in prompts",
            "/status     Show current backend, model, paths, and runtime settings",            
            "/debug      on/off debug logging to stderr",            
            "/models     List models returned by the active backend",
            "/search     Show active search URL status from agent tools settings",
            "/memory     Show agent memory DB status",
            "/mem list   List recent durable memory entries",
            "/tools      Show DB-backed agent tools status",
            "/tools list List approved DB-backed tool names",
            "/clear      Redraw the banner",
            "/cancel     Cancel multiline paste mode",
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
    if lower == "/paste":
        print("\nPaste mode: enter multiple lines, then use /end to submit or /cancel to discard.")
        return debug
    if lower == "/cancel":
        print("\nNothing to cancel outside paste mode.")
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


def read_multiline_query(debug_enabled: bool) -> Optional[str]:
    print("\nPaste mode: finish with /end or discard with /cancel.")
    lines = []
    while True:
        try:
            line = input("... ").rstrip("\n")
        except EOFError:
            print("\nPaste cancelled.")
            return ""
        except KeyboardInterrupt:
            print("\nPaste cancelled.")
            return ""
        normalized = line.strip().lower()
        if normalized == "/cancel":
            print("Paste discarded.")
            return ""
        if normalized == "/end":
            break
        lines.append(line)
    text = "\n".join(lines).strip()
    if text == "":
        print("No pasted content submitted.")
        return ""
    return text


def interactive_loop(agent: AliveAgent, debug: bool, startup_greeting_enabled: bool = False, startup_greeting_prompt: str = "") -> int:
    debug_enabled = debug
    sessions_history_enabled = False
    edit_paste_enabled = bool(getattr(agent, "edit_paste_enabled", False))
    edit_paste_min_lines = int(getattr(agent, "edit_paste_min_lines", PASTE_EDIT_MIN_LINES) or PASTE_EDIT_MIN_LINES)
    if edit_paste_min_lines < 2:
        edit_paste_min_lines = PASTE_EDIT_MIN_LINES
    editor_command = str(getattr(agent, "editor_command", "") or "")
    editor_timeout_seconds = int(getattr(agent, "editor_timeout_seconds", 300) or 300)
    if editor_timeout_seconds < 1:
        editor_timeout_seconds = 300
    edit_paste_strip_comment_lines = bool(getattr(agent, "edit_paste_strip_comment_lines", True))
    session = AgentSessionLogger(
        getattr(agent, "profile_name", "") if hasattr(agent, "profile_name") else "",
        getattr(agent, "profile_mode", "") if hasattr(agent, "profile_mode") else "shell",
    )
    composer_metadata = {
        "profile_name": str(getattr(agent, "profile_name", "") or ""),
        "mode": str(getattr(agent, "profile_mode", "") or ""),
        "model": str(getattr(agent, "model", "") or ""),
        "session_id": session.session_id,
    }
    setup_readline()
    print(banner_block(agent, debug_enabled))
    print("\nSession log: %s" % str(session.path))
    if startup_greeting_enabled:
        try:
            greeting = agent.startup_greeting(startup_greeting_prompt)
            if greeting.strip() != "":
                print("\n" + greeting)
                session.log("assistant_startup_greeting", {"text": greeting})
        except Exception as e:
            print("\nStartup greeting failed: %s" % e)
            session.log("startup_greeting_error", {"error": str(e)})
    while True:
        try:
            prompt = "\nagent"
            if debug_enabled:
                prompt += " [debug]"
            prompt += "> "
            user_q = read_user_input(
                prompt,
                edit_multiline=edit_paste_enabled,
                edit_min_lines=edit_paste_min_lines,
                editor_command=editor_command,
                editor_timeout_seconds=editor_timeout_seconds,
                edit_strip_comment_lines=edit_paste_strip_comment_lines,
                archive_source="edit_paste",
                archive_metadata=composer_metadata,
            )
        except (EOFError, KeyboardInterrupt):
            print("\nbye")
            session.log("session_end", {"reason": "eof_or_interrupt"})
            return 0

        user_q = user_q.strip()
        if not user_q:
            continue
        if user_q.lower() in ("exit", "quit"):
            print("bye")
            session.log("session_end", {"reason": "quit"})
            return 0
        if user_q.lower() == "/paste":
            pasted = read_multiline_query(debug_enabled)
            if not pasted:
                session.log("paste_cancelled", {})
                continue
            session.log("user_input_paste", {"chars": len(pasted)})
            user_q = pasted
        elif user_q.lower() == "/session":
            print("\nSession log: %s" % str(session.path))
            session.log("session_path_requested", {"path": str(session.path)})
            continue
        elif user_q.lower() == "/compose":
            composed = open_in_editor(
                "",
                editor_command=editor_command,
                timeout_seconds=editor_timeout_seconds,
                strip_comment_lines=edit_paste_strip_comment_lines,
                archive_source="compose",
                archive_metadata=composer_metadata,
            )
            if not composed.get("ok"):
                print("\nCompose cancelled.")
                session.log("compose_cancelled", composed)
                continue
            user_q = str(composed.get("content", "") or "").strip()
            if user_q == "":
                print("\nCompose cancelled.")
                session.log("compose_cancelled", {"error": "empty_compose"})
                continue
            if composed.get("archived_path"):
                print("\nSaved compose copy: %s" % str(composed.get("archived_path")))
            session.log("compose_submitted", {"chars": len(user_q), "archived_path": str(composed.get("archived_path", "") or "")})
        elif user_q.lower() == "/composer-history":
            archives = list_composer_archives(10)
            if not archives.get("ok"):
                print("\nComposer history failed: %s" % str(archives.get("error", "unknown_error")))
                if archives.get("path"):
                    print("Path: %s" % str(archives.get("path")))
                session.log("composer_history_error", archives)
                continue
            items = archives.get("items", [])
            if not isinstance(items, list) or not items:
                print("\nNo composer archives found.")
                session.log("composer_history_listed", {"count": 0, "path": str(archives.get("path", "") or "")})
                continue
            print("\nComposer history:")
            for index, item in enumerate(items, 1):
                if not isinstance(item, dict):
                    continue
                line = "%d. %s" % (index, str(item.get("path", "") or ""))
                created_at = str(item.get("created_at", "") or "")
                source = str(item.get("source", "") or "")
                if created_at or source:
                    line += " [%s%s]" % (created_at, (" | " + source) if source else "")
                print(line)
                detail_parts = []
                for key in ("profile", "mode", "model"):
                    value = str(item.get(key, "") or "")
                    if value:
                        detail_parts.append("%s=%s" % (key, value))
                first_line = str(item.get("first_line", "") or "")
                if detail_parts:
                    print("  %s" % " | ".join(detail_parts))
                if first_line:
                    print("  %s" % first_line[:180])
            session.log("composer_history_listed", {"count": len(items), "path": str(archives.get("path", "") or "")})
            continue
        elif user_q.lower() == "/compose-last":
            loaded_archive = newest_composer_archive()
            if not loaded_archive.get("ok"):
                print("\nCompose-last failed: %s" % str(loaded_archive.get("error", "archive_not_found")))
                if loaded_archive.get("path"):
                    print("Path: %s" % str(loaded_archive.get("path")))
                session.log("compose_last_error", loaded_archive)
                continue
            composed = open_in_editor(
                str(loaded_archive.get("content", "") or ""),
                editor_command=editor_command,
                timeout_seconds=editor_timeout_seconds,
                strip_comment_lines=edit_paste_strip_comment_lines,
                archive_source="compose_reuse",
                archive_metadata=composer_metadata,
            )
            if not composed.get("ok"):
                print("\nCompose-last cancelled.")
                session.log("compose_last_cancelled", composed)
                continue
            user_q = str(composed.get("content", "") or "").strip()
            if user_q == "":
                print("\nCompose-last cancelled.")
                session.log("compose_last_cancelled", {"error": "empty_compose"})
                continue
            if composed.get("archived_path"):
                print("\nSaved compose copy: %s" % str(composed.get("archived_path")))
            session.log(
                "compose_last_submitted",
                {
                    "chars": len(user_q),
                    "from_path": str(loaded_archive.get("path", "") or ""),
                    "archived_path": str(composed.get("archived_path", "") or ""),
                },
            )
        elif user_q.lower().startswith("/compose-load "):
            parts = user_q.split(" ", 1)
            path_text = parts[1] if len(parts) > 1 else ""
            loaded_archive = load_composer_archive(path_text)
            if not loaded_archive.get("ok"):
                print("\nCompose-load failed: %s" % str(loaded_archive.get("error", "unknown_error")))
                if loaded_archive.get("path"):
                    print("Path: %s" % str(loaded_archive.get("path")))
                session.log("compose_load_error", loaded_archive)
                continue
            composed = open_in_editor(
                str(loaded_archive.get("content", "") or ""),
                editor_command=editor_command,
                timeout_seconds=editor_timeout_seconds,
                strip_comment_lines=edit_paste_strip_comment_lines,
                archive_source="compose_reuse",
                archive_metadata=composer_metadata,
            )
            if not composed.get("ok"):
                print("\nCompose-load cancelled.")
                session.log("compose_load_cancelled", composed)
                continue
            user_q = str(composed.get("content", "") or "").strip()
            if user_q == "":
                print("\nCompose-load cancelled.")
                session.log("compose_load_cancelled", {"error": "empty_compose"})
                continue
            if composed.get("archived_path"):
                print("\nSaved compose copy: %s" % str(composed.get("archived_path")))
            session.log(
                "compose_load_submitted",
                {
                    "chars": len(user_q),
                    "from_path": str(loaded_archive.get("path", "") or ""),
                    "archived_path": str(composed.get("archived_path", "") or ""),
                },
            )
        elif user_q.lower() == "/edit-paste":
            print("\nEdit-paste is %s." % ("on" if edit_paste_enabled else "off"))
            print("Large multiline pastes open $EDITOR when %d+ lines are detected." % edit_paste_min_lines)
            session.log("edit_paste_state_requested", {"enabled": edit_paste_enabled, "min_lines": edit_paste_min_lines, "editor_command": editor_command, "editor_timeout_seconds": editor_timeout_seconds})
            continue
        elif user_q.lower() in ("/edit-paste on", "/edit-paste off"):
            edit_paste_enabled = user_q.lower().endswith("on")
            print("\nEdit-paste %s." % ("enabled" if edit_paste_enabled else "disabled"))
            print("Large multiline pastes open $EDITOR when %d+ lines are detected." % edit_paste_min_lines)
            session.log("edit_paste_toggled", {"enabled": edit_paste_enabled, "min_lines": edit_paste_min_lines, "editor_command": editor_command, "editor_timeout_seconds": editor_timeout_seconds})
            continue
        elif user_q.lower() == "/sessions-history":
            print("\nSessions history is %s." % ("on" if sessions_history_enabled else "off"))
            print("Current session file: %s" % str(session.path))
            session.log("sessions_history_state_requested", {"enabled": sessions_history_enabled, "path": str(session.path)})
            continue
        elif user_q.lower() in ("/sessions-history on", "/sessions-history off"):
            sessions_history_enabled = user_q.lower().endswith("on")
            print("\nSessions history %s." % ("enabled" if sessions_history_enabled else "disabled"))
            print("Current session file: %s" % str(session.path))
            session.log("sessions_history_toggled", {"enabled": sessions_history_enabled, "path": str(session.path)})
            continue
        elif user_q.lower().startswith("/read ") or user_q.lower().startswith("/load "):
            parts = user_q.split(" ", 1)
            path_text = parts[1] if len(parts) > 1 else ""
            loaded = load_prompt_from_file(path_text)
            if not loaded.get("ok"):
                print("\nFile load failed: %s" % str(loaded.get("error", "unknown_error")))
                if loaded.get("path"):
                    print("Path: %s" % str(loaded.get("path")))
                session.log("file_load_error", loaded)
                continue
            print("\nLoaded file into prompt: %s" % str(loaded.get("path")))
            if loaded.get("truncated"):
                print("Warning: file was truncated for prompt size.")
            session.log(
                "file_loaded_into_prompt",
                {"path": str(loaded.get("path", "")), "truncated": bool(loaded.get("truncated", False))},
            )
            user_q = str(loaded.get("prompt", "") or "")
        if user_q.startswith("/"):
            session.log("slash_command", {"command": user_q})
            cmd_result = handle_slash_command(agent, user_q, debug_enabled)
            if cmd_result is None:
                print("bye")
                session.log("session_end", {"reason": "slash_exit"})
                return 0
            debug_enabled = cmd_result
            continue

        try:
            prompt_text = user_q
            if sessions_history_enabled:
                history_text = build_session_history_prompt(session.path, include_current=True, max_sessions=3)
                if history_text != "":
                    prompt_text = history_text + "\n\nCurrent request:\n" + user_q
            session.log("user_prompt", {"text": user_q[:4000], "chars": len(user_q), "sessions_history_enabled": sessions_history_enabled})
            answer = agent.run(prompt_text, debug=debug_enabled)
            print("\n" + answer)
            session.log("assistant_response", {"text": answer[:4000], "chars": len(answer)})
        except Exception as e:
            print("Agent error: %s" % e)
            print("Hint: run with --list-models or use /models to inspect models on the active backend.")
            session.log("agent_error", {"error": str(e), "prompt": user_q[:1000]})
