#!/usr/bin/env python3
'''
How to wire it to your system (quick)

Put the script in /web/private/mcp/scripts/

Add a job name like molt_shell in your worker dispatch 
(or your generic script runner) and pass payload {goal: "..."}

Implement call_model() using your OpenAI API key (Python) or 
call your existing PHP OpenAI endpoint.

'''

import os, sys, json, re, subprocess, time
from pathlib import Path

# ---- CONFIG ----
READ_ROOTS = ["/var/log", "/web/private/logs", str(Path.home() / ".clawdbot" / "inbox" / "logs")]
WRITE_ROOTS = ["/web/private/logs/ai", "/web/private/mcp/out"]
MAX_TOOL_OUTPUT_CHARS = 30000
MAX_STEPS = 20

def is_under(path: str, roots: list[str]) -> bool:
    p = Path(path).resolve()
    for r in roots:
        rr = Path(r).resolve()
        try:
            p.relative_to(rr)
            return True
        except Exception:
            pass
    return False

def cap(s: str) -> str:
    return s if len(s) <= MAX_TOOL_OUTPUT_CHARS else (s[:MAX_TOOL_OUTPUT_CHARS] + "\n...[truncated]...\n")

def cmd_list(args):
    path = args[0]
    if not is_under(path, READ_ROOTS):
        return f"ERR not allowed: LIST {path}"
    p = Path(path)
    if not p.exists():
        return f"ERR missing: {path}"
    if p.is_dir():
        items = sorted([x.name for x in p.iterdir()])[:200]
        return "\n".join(items)
    return f"ERR not a dir: {path}"

def cmd_read(args):
    path = args[0]
    lines = int(args[1]) if len(args) > 1 else 200
    lines = max(1, min(lines, 500))
    if not is_under(path, READ_ROOTS):
        return f"ERR not allowed: READ {path}"
    try:
        out = subprocess.check_output(["tail", "-n", str(lines), path], text=True, errors="ignore")
        return out
    except Exception as e:
        return f"ERR read failed: {e}"

def cmd_grep(args):
    path, pattern = args[0], args[1]
    maxn = int(args[2]) if len(args) > 2 else 200
    maxn = max(1, min(maxn, 500))
    if not is_under(path, READ_ROOTS):
        return f"ERR not allowed: GREP {path}"
    try:
        out = subprocess.check_output(["grep", "-nE", pattern, path], text=True, errors="ignore")
        lines = out.splitlines()[-maxn:]
        return "\n".join(lines)
    except subprocess.CalledProcessError:
        return ""  # no matches
    except Exception as e:
        return f"ERR grep failed: {e}"

def cmd_write(path, content, append=False):
    if not is_under(path, WRITE_ROOTS):
        return f"ERR not allowed: WRITE {path}"
    Path(path).parent.mkdir(parents=True, exist_ok=True)
    mode = "a" if append else "w"
    with open(path, mode, encoding="utf-8", errors="ignore") as f:
        f.write(content)
    return f"OK wrote {len(content)} bytes to {path}"

MCP_BLOCK_RE = re.compile(r"```mcp\s*(.*?)```", re.S)

def parse_mcp(text: str) -> list[tuple[str, str, str]]:
    m = MCP_BLOCK_RE.search(text)
    if not m:
        return [("ERR", "", "No ```mcp``` block found.")]
    block = m.group(1).strip()
    cmds = []
    buf = block.splitlines()
    i = 0
    while i < len(buf):
        line = buf[i].strip()
        i += 1
        if not line or line.startswith("#"):
            continue
        if line.startswith("WRITE ") or line.startswith("APPEND "):
            append = line.startswith("APPEND ")
            parts = line.split(" ", 2)
            path = parts[1]
            # expect <<EOF
            if "<<EOF" not in line:
                cmds.append(("ERR", "", f"Bad heredoc: {line}"))
                continue
            content_lines = []
            while i < len(buf) and buf[i].strip() != "EOF":
                content_lines.append(buf[i])
                i += 1
            if i < len(buf) and buf[i].strip() == "EOF":
                i += 1
            cmds.append(("APPEND" if append else "WRITE", path, "\n".join(content_lines) + "\n"))
            continue
        if line.startswith("DONE"):
            cmds.append(("DONE", "", line))
            continue
        # LIST/READ/GREP
        parts = line.split(" ", 2)
        op = parts[0].upper()
        rest = parts[1:] if len(parts) > 1 else []
        cmds.append((op, "", " ".join(rest)))
    return cmds

def call_model(prompt: str) -> str:
    """
    Replace this stub with your OpenAI call.
    For now we just raise so you wire in the provider you want.
    """
    raise RuntimeError("Wire call_model() to OpenAI (or your local model)")

def main():
    goal = " ".join(sys.argv[1:]).strip() or "Summarize today's logs."
    state = f"""You are a terminal agent. Respond ONLY with one ```mcp``` block.
Allowed commands:
- LIST <path>
- READ <path> <lines>
- GREP <path> <regex> <max>
- WRITE <path> <<EOF ... EOF
- APPEND <path> <<EOF ... EOF
- DONE "<short human summary>"

Read roots: {READ_ROOTS}
Write roots: {WRITE_ROOTS}

Goal: {goal}
"""
    tool_feedback = ""
    for step in range(MAX_STEPS):
        prompt = state + "\n" + (f"Last tool output:\n{tool_feedback}\n" if tool_feedback else "")
        reply = call_model(prompt)

        cmds = parse_mcp(reply)
        if cmds and cmds[0][0] == "ERR":
            tool_feedback = cap(cmds[0][2])
            continue

        outputs = []
        done = None
        for op, a, b in cmds:
            if op == "LIST":
                outputs.append(cmd_list(b.split()))
            elif op == "READ":
                parts = b.split()
                outputs.append(cmd_read(parts))
            elif op == "GREP":
                parts = b.split(" ", 2)
                if len(parts) < 2:
                    outputs.append("ERR bad GREP")
                else:
                    path = parts[0]
                    pattern = parts[1]
                    maxn = parts[2] if len(parts) > 2 else "200"
                    outputs.append(cmd_grep([path, pattern, maxn]))
            elif op == "WRITE":
                outputs.append(cmd_write(a, b, append=False))
            elif op == "APPEND":
                outputs.append(cmd_write(a, b, append=True))
            elif op == "DONE":
                done = b
            elif op == "ERR":
                outputs.append(b)
            else:
                outputs.append(f"ERR unknown op: {op}")

        tool_feedback = cap("\n\n".join(outputs))
        if done:
            print(done)
            return 0

    print("DONE \"Stopped: too many steps.\"")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
