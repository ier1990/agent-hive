#!/usr/bin/env python3
from __future__ import annotations

import json
import hashlib
import os
import sqlite3
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any, Dict, List
from urllib.parse import parse_qsl, quote_plus, urlencode, urlsplit, urlunsplit

from agent_common import AGENT_MEMORY_DB_PATH, DEFAULT_TMP_DIR, compact_json, load_agent_boot_prompt, parse_model_json


def build_searx_search_url(base_url: str, query: str) -> str:
    raw = (base_url or "").strip()
    if not raw:
        return ""

    if "{query}" in raw:
        return raw.replace("{query}", quote_plus(query))

    parts = urlsplit(raw)
    path = parts.path or ""
    if not path.endswith("/search") and "/search" not in path:
        path = path.rstrip("/") + "/search"

    params = dict(parse_qsl(parts.query, keep_blank_values=True))
    params["q"] = query
    params["format"] = "json"
    query_string = urlencode(params, doseq=True)
    return urlunsplit((parts.scheme, parts.netloc, path, query_string, parts.fragment))


def summarize_searx_results(data: Any, limit: int) -> str:
    results = data.get("results", []) if isinstance(data, dict) else []
    lines = []
    if isinstance(results, list):
        for row in results[:limit]:
            if not isinstance(row, dict):
                continue
            title = str(row.get("title", "") or "").strip()
            url = str(row.get("url", "") or "").strip()
            if title:
                lines.append(title)
            if url:
                lines.append(url)
            if title or url:
                lines.append("")
    while lines and lines[-1] == "":
        lines.pop()
    return "\n".join(lines)


def format_final_response(response: Any) -> str:
    if isinstance(response, (dict, list)):
        return json.dumps(response, ensure_ascii=False, indent=2)
    if response is None:
        return ""
    return str(response).strip()


def tool_call_fingerprint(tool_name: str, args: Dict[str, Any]) -> str:
    return tool_name + ":" + compact_json(args)


class AliveAgent:
    def __init__(
        self,
        model: str,
        base_url: str,
        api_key: str,
        provider: str,
        notes_db: Path,
        code_root: Path,
        tool_settings: Dict[str, Any],
        boot_prompt_path: Path,
        max_steps: int,
        temperature: float,
    ) -> None:
        self.model = model
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key
        self.provider = (provider or "").strip().lower()
        self.notes_db = notes_db
        self.code_root = code_root.resolve()
        self.tool_settings = tool_settings if isinstance(tool_settings, dict) else {}
        self.boot_prompt_path = boot_prompt_path
        self.max_steps = max(1, min(max_steps, 12))
        self.temperature = max(0.0, min(temperature, 1.0))
        self._model_autofixed = False

    def backend_label(self) -> str:
        if self.provider == "ollama":
            return "Ollama"
        if self.provider in ("local", "lmstudio"):
            return "LM Studio"
        if self.provider == "openrouter":
            return "OpenRouter"
        if self.provider == "openai":
            return "OpenAI"
        if self.provider == "anthropic":
            return "Anthropic"
        if self.provider == "custom":
            return "Custom"
        base = self.base_url.lower()
        if "11434" in base or "ollama" in base:
            return "Ollama"
        if "1234" in base or "lmstudio" in base:
            return "LM Studio"
        if "openrouter.ai" in base:
            return "OpenRouter"
        if "api.openai.com" in base:
            return "OpenAI"
        if "anthropic.com" in base:
            return "Anthropic"
        return "OpenAI-compatible"

    def _api_headers(self) -> Dict[str, str]:
        headers = {"Content-Type": "application/json"}
        if self.api_key:
            headers["Authorization"] = "Bearer " + self.api_key
        return headers

    def _chat_completion_to_base(self, base_url: str, messages: List[Dict[str, str]]) -> str:
        payload = {
            "model": self.model,
            "messages": messages,
            "temperature": self.temperature,
            "stream": False,
        }
        body = json.dumps(payload).encode("utf-8")

        req = urllib.request.Request(
            base_url.rstrip("/") + "/chat/completions",
            data=body,
            method="POST",
            headers=self._api_headers(),
        )

        with urllib.request.urlopen(req, timeout=240) as resp:
            raw = resp.read().decode("utf-8", errors="replace")

        data = json.loads(raw)
        msg = data.get("choices", [{}])[0].get("message", {}) if isinstance(data, dict) else {}
        content = msg.get("content", "") if isinstance(msg, dict) else ""
        if isinstance(content, str) and content.strip() != "":
            return content

        if isinstance(data, dict):
            for key in ("content", "text", "answer"):
                value = data.get(key)
                if isinstance(value, str) and value.strip() != "":
                    return value

        return compact_json(data)

    def _list_models(self) -> List[str]:
        req = urllib.request.Request(
            self.base_url + "/models",
            method="GET",
            headers=self._api_headers(),
        )
        with urllib.request.urlopen(req, timeout=30) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
        data = json.loads(raw)
        rows = data.get("data", []) if isinstance(data, dict) else []
        out = []
        if isinstance(rows, list):
            for row in rows:
                if isinstance(row, dict):
                    mid = str(row.get("id", "")).strip()
                    if mid:
                        out.append(mid)
        return out

    def available_models(self) -> List[str]:
        return self._list_models()

    def _maybe_autofix_model(self, error_detail: str) -> bool:
        if self._model_autofixed:
            return False
        lower = error_detail.lower()
        if "model" not in lower or "not found" not in lower:
            return False
        try:
            models = self._list_models()
        except Exception:
            return False
        if not models:
            return False
        self.model = models[0]
        self._model_autofixed = True
        return True

    def _chat_completion(self, messages: List[Dict[str, str]]) -> str:
        try:
            return self._chat_completion_to_base(self.base_url, messages)
        except urllib.error.HTTPError as e:
            detail = e.read().decode("utf-8", errors="replace") if hasattr(e, "read") else str(e)
            if self._maybe_autofix_model(detail):
                return self._chat_completion(messages)
            raise RuntimeError("LLM HTTP error: %s" % detail)
        except Exception as e:
            raise RuntimeError("LLM request failed: %s" % e)

    def startup_greeting(self, prompt: str = "") -> str:
        greeting_prompt = str(prompt or "").strip()
        if greeting_prompt == "":
            greeting_prompt = "Say a short greeting and introduce yourself in 2 short sentences."

        messages = [
            {
                "role": "system",
                "content": (
                    "You are the AgentHive shell startup greeter. "
                    "Reply in plain text only. "
                    "Do not use JSON. "
                    "Do not call tools. "
                    "Keep it short and friendly."
                ),
            },
            {"role": "user", "content": greeting_prompt},
        ]
        return self._chat_completion(messages).strip()

    def _tool_notes_search(self, query: str, limit: int) -> Dict[str, Any]:
        if not self.notes_db.exists():
            return {"ok": False, "error": "notes_db_missing", "notes_db": str(self.notes_db)}

        limit = max(1, min(int(limit), 30))
        like_query = "%%%s%%" % query
        conn = sqlite3.connect(str(self.notes_db))
        conn.row_factory = sqlite3.Row
        try:
            rows = conn.execute(
                """
                SELECT id, COALESCE(topic,'') AS topic, COALESCE(notes_type,'') AS notes_type,
                       COALESCE(note,'') AS note, COALESCE(updated_at,'') AS updated_at
                FROM notes
                WHERE note LIKE ? OR topic LIKE ?
                ORDER BY id DESC
                LIMIT ?
                """,
                (like_query, like_query, limit),
            ).fetchall()
        finally:
            conn.close()

        items = []
        for row in rows:
            note_text = str(row["note"])
            items.append(
                {
                    "id": int(row["id"]),
                    "topic": str(row["topic"]),
                    "notes_type": str(row["notes_type"]),
                    "updated_at": str(row["updated_at"]),
                    "snippet": note_text[:500],
                }
            )
        return {"ok": True, "query": query, "count": len(items), "items": items}

    def _tool_code_search(self, query: str, limit: int) -> Dict[str, Any]:
        limit = max(1, min(int(limit), 80))
        cmd = [
            "rg",
            "--line-number",
            "--no-heading",
            "--color",
            "never",
            "--max-count",
            str(limit),
            query,
            str(self.code_root),
        ]
        try:
            proc = subprocess.run(
                cmd,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                timeout=20,
                check=False,
            )
        except FileNotFoundError:
            return {"ok": False, "error": "rg_not_found"}
        except subprocess.TimeoutExpired:
            return {"ok": False, "error": "code_search_timeout"}

        lines = [line for line in proc.stdout.splitlines() if line.strip()]
        return {
            "ok": proc.returncode in (0, 1),
            "query": query,
            "count": len(lines),
            "matches": lines[:limit],
            "stderr": proc.stderr[-500:] if proc.stderr else "",
        }

    def _memory_cfg(self) -> Dict[str, Any]:
        cfg = self.tool_settings.get("memory", {})
        return cfg if isinstance(cfg, dict) else {}

    def _memory_enabled(self) -> bool:
        cfg = self._memory_cfg()
        return bool(cfg.get("enabled", True))

    def _memory_db_path(self) -> Path:
        cfg = self._memory_cfg()
        raw = str(cfg.get("db_path", "") or "").strip()
        if raw == "":
            return AGENT_MEMORY_DB_PATH
        return Path(raw)

    def _memory_search_limit_default(self) -> int:
        cfg = self._memory_cfg()
        return max(1, min(int(cfg.get("default_search_limit", 8) or 8), 50))

    def _memory_write_length_max(self) -> int:
        cfg = self._memory_cfg()
        return max(100, min(int(cfg.get("max_write_length", 4000) or 4000), 20000))

    def _memory_autoload_enabled(self) -> bool:
        cfg = self._memory_cfg()
        return bool(cfg.get("autoload_on_start", False))

    def _memory_autoload_limit(self) -> int:
        cfg = self._memory_cfg()
        return max(1, min(int(cfg.get("autoload_limit", 10) or 10), 50))

    def _ensure_memory_schema(self) -> Path:
        db_path = self._memory_db_path()
        db_path.parent.mkdir(parents=True, exist_ok=True)
        conn = sqlite3.connect(str(db_path))
        try:
            conn.execute(
                """
                CREATE TABLE IF NOT EXISTS memory_entries (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    topic TEXT DEFAULT '',
                    content TEXT NOT NULL,
                    tags TEXT DEFAULT '',
                    source TEXT DEFAULT '',
                    pinned INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
                """
            )
            conn.execute("CREATE INDEX IF NOT EXISTS idx_memory_entries_created ON memory_entries(created_at DESC)")
            conn.execute("CREATE INDEX IF NOT EXISTS idx_memory_entries_pinned ON memory_entries(pinned DESC, id DESC)")
            conn.commit()
        finally:
            conn.close()
        return db_path

    def _tool_memory_search(self, query: str, limit: int) -> Dict[str, Any]:
        if not self._memory_enabled():
            return {"ok": False, "error": "memory_disabled"}

        db_path = self._ensure_memory_schema()
        limit = max(1, min(int(limit), 50))
        conn = sqlite3.connect(str(db_path))
        conn.row_factory = sqlite3.Row
        try:
            if query.strip() == "":
                rows = conn.execute(
                    """
                    SELECT id, COALESCE(topic,'') AS topic, COALESCE(content,'') AS content,
                           COALESCE(tags,'') AS tags, COALESCE(source,'') AS source,
                           COALESCE(pinned,0) AS pinned, COALESCE(created_at,'') AS created_at,
                           COALESCE(updated_at,'') AS updated_at
                    FROM memory_entries
                    ORDER BY pinned DESC, id DESC
                    LIMIT ?
                    """,
                    (limit,),
                ).fetchall()
            else:
                like_query = "%%%s%%" % query
                rows = conn.execute(
                    """
                    SELECT id, COALESCE(topic,'') AS topic, COALESCE(content,'') AS content,
                           COALESCE(tags,'') AS tags, COALESCE(source,'') AS source,
                           COALESCE(pinned,0) AS pinned, COALESCE(created_at,'') AS created_at,
                           COALESCE(updated_at,'') AS updated_at
                    FROM memory_entries
                    WHERE topic LIKE ? OR content LIKE ? OR tags LIKE ? OR source LIKE ?
                    ORDER BY pinned DESC, id DESC
                    LIMIT ?
                    """,
                    (like_query, like_query, like_query, like_query, limit),
                ).fetchall()
        finally:
            conn.close()

        items = []
        for row in rows:
            content = str(row["content"] or "")
            items.append(
                {
                    "id": int(row["id"]),
                    "topic": str(row["topic"] or ""),
                    "tags": str(row["tags"] or ""),
                    "source": str(row["source"] or ""),
                    "pinned": int(row["pinned"] or 0),
                    "created_at": str(row["created_at"] or ""),
                    "updated_at": str(row["updated_at"] or ""),
                    "snippet": content[:500],
                }
            )
        return {"ok": True, "query": query, "count": len(items), "items": items, "db_path": str(db_path)}

    def _tool_memory_write(self, content: str, topic: str, tags: str, source: str, pinned: int) -> Dict[str, Any]:
        if not self._memory_enabled():
            return {"ok": False, "error": "memory_disabled"}

        content = str(content or "").strip()
        if content == "":
            return {"ok": False, "error": "memory_content_required"}
        max_len = self._memory_write_length_max()
        if len(content) > max_len:
            content = content[:max_len]

        db_path = self._ensure_memory_schema()
        conn = sqlite3.connect(str(db_path))
        try:
            cur = conn.execute(
                """
                INSERT INTO memory_entries (topic, content, tags, source, pinned, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                """,
                (str(topic or "").strip(), content, str(tags or "").strip(), str(source or "").strip(), 1 if int(pinned or 0) else 0),
            )
            conn.commit()
            entry_id = int(cur.lastrowid or 0)
        finally:
            conn.close()

        return {
            "ok": True,
            "id": entry_id,
            "topic": str(topic or "").strip(),
            "tags": str(tags or "").strip(),
            "source": str(source or "").strip(),
            "content_preview": content[:200],
            "db_path": str(db_path),
        }

    def list_memory_entries(self, limit: int = 10) -> Dict[str, Any]:
        return self._tool_memory_search("", limit)

    def _tool_search(self, query: str, limit: int) -> Dict[str, Any]:
        cfg = self.tool_settings.get("search", {})
        if not isinstance(cfg, dict):
            cfg = {}

        if not bool(cfg.get("enabled", True)):
            return {"ok": False, "error": "search_disabled"}

        base_url = str(cfg.get("searx_url", "") or "").strip()
        if base_url == "":
            return {"ok": False, "error": "search_not_configured", "hint": "Set SEARX_URL or /web/private/agent_tools.json"}

        timeout = int(cfg.get("timeout_seconds", 20) or 20)
        limit = max(1, min(int(limit), 10))
        default_limit = int(cfg.get("result_limit", limit) or limit)
        limit = max(1, min(limit or default_limit, 10))
        url = build_searx_search_url(base_url, query)

        req = urllib.request.Request(
            url,
            method="GET",
            headers={"Accept": "application/json", "User-Agent": "AgentHive-Agent/1.0"},
        )

        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                raw = resp.read().decode("utf-8", errors="replace")
        except urllib.error.HTTPError as e:
            detail = e.read().decode("utf-8", errors="replace") if hasattr(e, "read") else str(e)
            return {"ok": False, "error": "search_http_error", "status": getattr(e, "code", 0), "detail": detail[:500], "url": url}
        except Exception as e:
            return {"ok": False, "error": "search_request_failed", "detail": str(e), "url": url}

        try:
            data = json.loads(raw)
        except Exception:
            return {"ok": False, "error": "search_invalid_json", "body": raw[:500], "url": url}

        text_summary = summarize_searx_results(data, limit)
        rows = []
        results = data.get("results", []) if isinstance(data, dict) else []
        if isinstance(results, list):
            for row in results[:limit]:
                if not isinstance(row, dict):
                    continue
                rows.append(
                    {
                        "title": str(row.get("title", "") or ""),
                        "url": str(row.get("url", "") or ""),
                        "content": str(row.get("content", "") or ""),
                        "engine": str(row.get("engine", "") or ""),
                    }
                )

        return {
            "ok": True,
            "query": query,
            "count": len(rows),
            "response": text_summary if text_summary else "No results found.",
            "results": rows,
            "url": url,
        }

    def _tool_read_code(self, rel_path: str, start_line: int, end_line: int) -> Dict[str, Any]:
        target = (self.code_root / rel_path).resolve()
        if not str(target).startswith(str(self.code_root)):
            return {"ok": False, "error": "path_not_allowed"}
        if not target.exists() or not target.is_file():
            return {"ok": False, "error": "file_not_found", "path": str(target)}

        start_line = max(1, int(start_line))
        end_line = max(start_line, int(end_line))
        if end_line - start_line > 400:
            end_line = start_line + 400

        with target.open("r", encoding="utf-8", errors="replace") as f:
            content = f.readlines()

        total = len(content)
        start_idx = min(start_line - 1, total)
        end_idx = min(end_line, total)

        out_lines = []
        line_no = start_idx + 1
        for line in content[start_idx:end_idx]:
            out_lines.append("%d: %s" % (line_no, line.rstrip("\n")))
            line_no += 1

        return {
            "ok": True,
            "path": str(target),
            "start_line": start_line,
            "end_line": end_line,
            "total_lines": total,
            "content": "\n".join(out_lines),
        }

    def _agent_tools_cfg(self) -> Dict[str, Any]:
        cfg = self.tool_settings.get("agent_tools", {})
        return cfg if isinstance(cfg, dict) else {}

    def _agent_tools_db_path(self) -> Path:
        cfg = self._agent_tools_cfg()
        return Path(str(cfg.get("db_path", "") or ""))

    def _agent_tools_timeout(self) -> int:
        cfg = self._agent_tools_cfg()
        return max(1, int(cfg.get("execution_timeout_seconds", 30) or 30))

    def _agent_tools_enabled(self) -> bool:
        cfg = self._agent_tools_cfg()
        return bool(cfg.get("enabled", True))

    def _agent_tools_approval_where(self) -> str:
        return "(status = 'approved' OR (COALESCE(TRIM(status), '') = '' AND is_approved = 1))"

    def _load_approved_agent_tool(self, name: str) -> Dict[str, Any]:
        if not self._agent_tools_enabled():
            return {"ok": False, "error": "agent_tools_disabled"}

        db_path = self._agent_tools_db_path()
        if not str(db_path).strip():
            return {"ok": False, "error": "agent_tools_db_not_configured"}
        if not db_path.exists():
            return {"ok": False, "error": "agent_tools_db_missing", "path": str(db_path)}

        conn = sqlite3.connect(str(db_path))
        conn.row_factory = sqlite3.Row
        try:
            row = conn.execute(
                "SELECT id, name, description, keywords, parameters_schema, code, language, status, is_approved "
                "FROM tools WHERE name = ? AND " + self._agent_tools_approval_where() + " LIMIT 1",
                (name,),
            ).fetchone()
        finally:
            conn.close()

        if row is None:
            return {"ok": False, "error": "tool_not_found_or_not_approved", "name": name}
        return {"ok": True, "tool": dict(row), "db_path": str(db_path)}

    def _record_agent_tool_run(self, tool: Dict[str, Any], params: Dict[str, Any], result: Any, ok: bool, duration_ms: int) -> None:
        db_path = self._agent_tools_db_path()
        if not db_path.exists():
            return

        input_preview = compact_json(params)[:500]
        output_preview = compact_json(result)[:500] if isinstance(result, (dict, list, tuple, bool, int, float)) or result is None else str(result)[:500]
        input_hash = hashlib.md5(compact_json(params).encode("utf-8")).hexdigest()

        try:
            conn = sqlite3.connect(str(db_path))
            try:
                conn.execute(
                    "UPDATE tools SET run_count = run_count + 1, last_run_at = CURRENT_TIMESTAMP WHERE id = ?",
                    (int(tool.get("id", 0) or 0),),
                )
                conn.execute(
                    "INSERT INTO tool_runs (tool_id, tool_name, input_hash, input_preview, output_preview, success, duration_ms, client_ip) "
                    "VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    (
                        int(tool.get("id", 0) or 0),
                        str(tool.get("name", "") or ""),
                        input_hash,
                        input_preview,
                        output_preview,
                        1 if ok else 0,
                        duration_ms,
                        "cli",
                    ),
                )
                conn.commit()
            finally:
                conn.close()
        except Exception:
            return

    def _execute_python_agent_tool(self, code: str, params: Dict[str, Any]) -> Any:
        DEFAULT_TMP_DIR.mkdir(parents=True, exist_ok=True)
        with tempfile.NamedTemporaryFile("w", suffix=".py", dir=str(DEFAULT_TMP_DIR), delete=False, encoding="utf-8") as fh:
            fh.write("import json, sys\n")
            fh.write("params = json.loads(sys.stdin.read() or '{}')\n\n")
            fh.write(code)
            fh.write("\n")
            tmp_path = fh.name

        try:
            proc = subprocess.run(
                ["python3", tmp_path],
                input=compact_json(params),
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                timeout=self._agent_tools_timeout(),
                check=False,
            )
        finally:
            try:
                Path(tmp_path).unlink()
            except Exception:
                pass

        if proc.returncode != 0:
            raise RuntimeError("Python execution failed: %s" % (proc.stderr.strip() or proc.stdout.strip()))
        output = proc.stdout.strip()
        if output == "":
            return ""
        try:
            return json.loads(output)
        except Exception:
            return output

    def _execute_bash_agent_tool(self, code: str, params: Dict[str, Any]) -> Any:
        env = dict()
        for key, value in params.items():
            if isinstance(value, (str, int, float, bool)):
                env["PARAM_" + str(key).upper().replace("-", "_")] = str(value)

        proc = subprocess.run(
            ["bash", "-lc", code],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            timeout=self._agent_tools_timeout(),
            check=False,
            env={**os.environ, **env},
        )
        if proc.returncode != 0:
            raise RuntimeError("Bash execution failed (exit %s): %s" % (proc.returncode, proc.stderr.strip() or proc.stdout.strip()))
        return proc.stdout.strip()

    def _execute_php_agent_tool(self, code: str, params: Dict[str, Any]) -> Any:
        DEFAULT_TMP_DIR.mkdir(parents=True, exist_ok=True)
        wrapper = """<?php
$params = json_decode(stream_get_contents(STDIN), true);
if (!is_array($params)) { $params = []; }
try {
    $result = (function($params) {
%s
    })($params);
    if (is_array($result) || is_object($result)) {
        echo json_encode($result);
    } elseif (is_bool($result)) {
        echo $result ? "true" : "false";
    } elseif ($result === null) {
        echo "null";
    } else {
        echo (string)$result;
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
""" % code
        with tempfile.NamedTemporaryFile("w", suffix=".php", dir=str(DEFAULT_TMP_DIR), delete=False, encoding="utf-8") as fh:
            fh.write(wrapper)
            tmp_path = fh.name

        try:
            proc = subprocess.run(
                ["php", tmp_path],
                input=compact_json(params),
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                timeout=self._agent_tools_timeout(),
                check=False,
            )
        finally:
            try:
                Path(tmp_path).unlink()
            except Exception:
                pass

        if proc.returncode != 0:
            raise RuntimeError("PHP execution failed: %s" % (proc.stderr.strip() or proc.stdout.strip()))
        output = proc.stdout.strip()
        if output == "":
            return ""
        try:
            return json.loads(output)
        except Exception:
            if output == "true":
                return True
            if output == "false":
                return False
            if output == "null":
                return None
            return output

    def _tool_agent_tool_list(self, limit: int) -> Dict[str, Any]:
        if not self._agent_tools_enabled():
            return {"ok": False, "error": "agent_tools_disabled"}

        db_path = self._agent_tools_db_path()
        if not str(db_path).strip():
            return {"ok": False, "error": "agent_tools_db_not_configured"}
        if not db_path.exists():
            return {"ok": False, "error": "agent_tools_db_missing", "path": str(db_path)}

        cfg = self._agent_tools_cfg()
        max_items = max(1, min(int(cfg.get("max_list_items", 50) or 50), 200))
        limit = max(1, min(int(limit), max_items))

        conn = sqlite3.connect(str(db_path))
        conn.row_factory = sqlite3.Row
        try:
            rows = conn.execute(
                "SELECT name, description, keywords, language, parameters_schema "
                "FROM tools WHERE " + self._agent_tools_approval_where() + " ORDER BY name ASC LIMIT ?",
                (limit,),
            ).fetchall()
        finally:
            conn.close()

        items = []
        for row in rows:
            params_schema = str(row["parameters_schema"] or "")
            try:
                parsed_schema = json.loads(params_schema) if params_schema else {}
            except Exception:
                parsed_schema = params_schema
            items.append(
                {
                    "name": str(row["name"] or ""),
                    "description": str(row["description"] or ""),
                    "keywords": str(row["keywords"] or ""),
                    "language": str(row["language"] or ""),
                    "parameters_schema": parsed_schema,
                }
            )
        return {"ok": True, "count": len(items), "items": items, "db_path": str(db_path)}

    def list_agent_tools(self, limit: int = 25) -> Dict[str, Any]:
        return self._tool_agent_tool_list(limit)

    def _tool_agent_tool_run(self, name: str, params: Dict[str, Any]) -> Dict[str, Any]:
        loaded = self._load_approved_agent_tool(name)
        if not loaded.get("ok"):
            return loaded

        tool = loaded.get("tool", {})
        if not isinstance(tool, dict):
            return {"ok": False, "error": "tool_load_failed", "name": name}

        language = str(tool.get("language", "") or "").strip().lower()
        code = str(tool.get("code", "") or "")
        if code == "":
            return {"ok": False, "error": "tool_has_no_code", "name": name}

        started = time.time()
        try:
            if language == "python":
                result = self._execute_python_agent_tool(code, params)
            elif language == "bash":
                result = self._execute_bash_agent_tool(code, params)
            elif language == "php":
                result = self._execute_php_agent_tool(code, params)
            else:
                return {"ok": False, "error": "unsupported_tool_language", "language": language, "name": name}
            duration_ms = int((time.time() - started) * 1000)
            self._record_agent_tool_run(tool, params, result, True, duration_ms)
            return {
                "ok": True,
                "tool_name": str(tool.get("name", "") or name),
                "language": language,
                "result": result,
                "duration_ms": duration_ms,
            }
        except Exception as e:
            duration_ms = int((time.time() - started) * 1000)
            self._record_agent_tool_run(tool, params, str(e), False, duration_ms)
            return {
                "ok": False,
                "error": "tool_execution_failed",
                "tool_name": str(tool.get("name", "") or name),
                "language": language,
                "detail": str(e),
                "duration_ms": duration_ms,
            }

    def _run_tool(self, tool_name: str, args: Dict[str, Any]) -> Dict[str, Any]:
        if tool_name == "memory_search":
            return self._tool_memory_search(str(args.get("query", "")), int(args.get("limit", self._memory_search_limit_default())))
        if tool_name == "memory_write":
            return self._tool_memory_write(
                str(args.get("content", "")),
                str(args.get("topic", "")),
                str(args.get("tags", "")),
                str(args.get("source", "")),
                int(args.get("pinned", 0)),
            )
        if tool_name == "notes_search":
            return self._tool_notes_search(str(args.get("query", "")), int(args.get("limit", 8)))
        if tool_name == "code_search":
            return self._tool_code_search(str(args.get("query", "")), int(args.get("limit", 40)))
        if tool_name == "search":
            return self._tool_search(str(args.get("query", "")), int(args.get("limit", 5)))
        if tool_name == "agent_tool_list":
            return self._tool_agent_tool_list(int(args.get("limit", 25)))
        if tool_name == "agent_tool_run":
            raw_params = args.get("params", {})
            if not isinstance(raw_params, dict):
                raw_params = {}
            return self._tool_agent_tool_run(str(args.get("name", "")), raw_params)
        if tool_name == "read_code":
            return self._tool_read_code(str(args.get("path", "")), int(args.get("start_line", 1)), int(args.get("end_line", 120)))
        return {"ok": False, "error": "unknown_tool", "tool": tool_name}

    def _system_prompt(self) -> str:
        return load_agent_boot_prompt(self.boot_prompt_path)

    def _auto_context(self, user_request: str) -> Dict[str, Any]:
        context = {
            "notes_preview": self._tool_notes_search(user_request, 4),
            "code_preview": self._tool_code_search(user_request, 20),
        }
        if self._memory_autoload_enabled():
            context["memory_preview"] = self._tool_memory_search("", self._memory_autoload_limit())
        return context

    def run(self, user_request: str, debug: bool = False) -> str:
        messages: List[Dict[str, str]] = [{"role": "system", "content": self._system_prompt()}]
        preload = self._auto_context(user_request)
        seen_tool_calls: Dict[str, int] = {}
        messages.append({"role": "user", "content": "USER_REQUEST:\n" + user_request + "\n\nPRELOADED_CONTEXT:\n" + compact_json(preload)})

        for step in range(1, self.max_steps + 1):
            model_out = self._chat_completion(messages)
            parsed = parse_model_json(model_out)

            if debug:
                print("\n[step %d model] %s" % (step, model_out), file=sys.stderr)

            if not parsed:
                return model_out.strip()

            action = str(parsed.get("action", "")).strip().lower()
            if debug:
                print("[step %d parsed action] %s" % (step, action or "(missing)"), file=sys.stderr)
            if action == "final":
                response = parsed.get("response", "")
                if debug:
                    print("[step %d final] %s" % (step, compact_json(response)), file=sys.stderr)
                return format_final_response(response)
            if action != "tool":
                return "Agent error: invalid action from model."

            tool_name = str(parsed.get("tool", "")).strip()
            args = parsed.get("args", {})
            if not isinstance(args, dict):
                args = {}

            if debug:
                print("[step %d tool request] %s %s" % (step, tool_name, compact_json(args)), file=sys.stderr)

            fingerprint = tool_call_fingerprint(tool_name, args)
            seen_tool_calls[fingerprint] = seen_tool_calls.get(fingerprint, 0) + 1
            if seen_tool_calls[fingerprint] > 2:
                if debug:
                    print(
                        "[step %d loop-detected] repeated tool call %s count=%d"
                        % (step, fingerprint, seen_tool_calls[fingerprint]),
                        file=sys.stderr,
                    )
                return "Agent stopped: repeated tool call loop detected for %s." % tool_name

            tool_result = self._run_tool(tool_name, args)
            if debug:
                print("[step %d tool %s] %s" % (step, tool_name, compact_json(tool_result)), file=sys.stderr)

            messages.append({"role": "assistant", "content": compact_json(parsed)})
            messages.append({"role": "user", "content": "TOOL_RESULT:\n" + compact_json({"tool": tool_name, "result": tool_result})})

        return "Agent stopped: max steps reached before final answer."
