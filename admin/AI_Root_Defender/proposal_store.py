#!/usr/bin/env python3
from __future__ import annotations

import difflib
import json
import sqlite3
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Tuple

from agent_common import DEFAULT_DB_PATH, ensure_parent


SCHEMA_SQL = """
CREATE TABLE IF NOT EXISTS bash_proposals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    command_text TEXT NOT NULL,
    cwd TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'proposed',
    risk_level TEXT NOT NULL DEFAULT 'medium',
    operator_summary TEXT NOT NULL DEFAULT '',
    metadata_json TEXT NOT NULL DEFAULT '{}',
    proposed_by TEXT NOT NULL DEFAULT 'agent',
    proposed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by TEXT NOT NULL DEFAULT '',
    approved_at TEXT,
    executed_by TEXT NOT NULL DEFAULT '',
    executed_at TEXT,
    exit_code INTEGER,
    stdout_preview TEXT NOT NULL DEFAULT '',
    stderr_preview TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_bash_proposals_status ON bash_proposals(status);
CREATE INDEX IF NOT EXISTS idx_bash_proposals_time ON bash_proposals(proposed_at);
"""


def _utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


class ProposalStore:
    def __init__(self, db_path: Path | None = None, jsonl_mirror: bool = True, jsonl_dir: Path | None = None) -> None:
        self.db_path = db_path or DEFAULT_DB_PATH
        self.jsonl_mirror = bool(jsonl_mirror)
        self.jsonl_dir = jsonl_dir or (self.db_path.parent / "bash_proposals")
        ensure_parent(self.db_path)
        self._ensure_schema()

    def _conn(self) -> sqlite3.Connection:
        conn = sqlite3.connect(str(self.db_path))
        conn.row_factory = sqlite3.Row
        return conn

    def _ensure_schema(self) -> None:
        with self._conn() as conn:
            conn.executescript(SCHEMA_SQL)
            conn.commit()

    def _mirror_event(self, event: Dict[str, Any]) -> None:
        if not self.jsonl_mirror:
            return
        day = datetime.now(timezone.utc).strftime("%Y-%m-%d")
        path = self.jsonl_dir / f"{day}.jsonl"
        ensure_parent(path)
        line = json.dumps(event, ensure_ascii=True)
        path.write_text((path.read_text(encoding="utf-8") if path.exists() else "") + line + "\n", encoding="utf-8")

    def create_proposal(
        self,
        command_text: str,
        cwd: str,
        risk_level: str = "medium",
        operator_summary: str = "",
        metadata: Dict[str, Any] | None = None,
        proposed_by: str = "agent",
    ) -> int:
        metadata_json = json.dumps(metadata or {}, ensure_ascii=True)
        with self._conn() as conn:
            cur = conn.execute(
                """
                INSERT INTO bash_proposals
                (command_text, cwd, status, risk_level, operator_summary, metadata_json, proposed_by)
                VALUES (?, ?, 'proposed', ?, ?, ?, ?)
                """,
                (command_text.strip(), cwd.strip(), risk_level, operator_summary.strip(), metadata_json, proposed_by),
            )
            conn.commit()
            proposal_id = int(cur.lastrowid)

        self._mirror_event({
            "event": "proposed",
            "proposal_id": proposal_id,
            "command_text": command_text.strip(),
            "cwd": cwd.strip(),
            "risk_level": risk_level,
            "operator_summary": operator_summary,
            "metadata": metadata or {},
            "at": _utc_now(),
        })
        return proposal_id

    def create_auto_approved_proposal(
        self,
        command_text: str,
        cwd: str,
        risk_level: str = "medium",
        operator_summary: str = "",
        metadata: Dict[str, Any] | None = None,
        proposed_by: str = "agent",
        approved_by: str = "history-auto",
        notes: str = "",
    ) -> int:
        metadata_json = json.dumps(metadata or {}, ensure_ascii=True)
        now = _utc_now()
        with self._conn() as conn:
            cur = conn.execute(
                """
                INSERT INTO bash_proposals
                (command_text, cwd, status, risk_level, operator_summary, metadata_json, proposed_by, approved_by, approved_at, notes)
                VALUES (?, ?, 'approved', ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    command_text.strip(),
                    cwd.strip(),
                    risk_level,
                    operator_summary.strip(),
                    metadata_json,
                    proposed_by,
                    approved_by,
                    now,
                    (notes or "").strip(),
                ),
            )
            conn.commit()
            proposal_id = int(cur.lastrowid)

        self._mirror_event({
            "event": "auto_approved",
            "proposal_id": proposal_id,
            "command_text": command_text.strip(),
            "cwd": cwd.strip(),
            "risk_level": risk_level,
            "operator_summary": operator_summary,
            "metadata": metadata or {},
            "approved_by": approved_by,
            "notes": (notes or "").strip(),
            "at": now,
        })
        return proposal_id

    def list_pending(self, limit: int = 25) -> List[Dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                """
                SELECT id, command_text, cwd, risk_level, operator_summary, proposed_at
                FROM bash_proposals
                WHERE status='proposed'
                ORDER BY id DESC
                LIMIT ?
                """,
                (max(1, min(int(limit), 200)),),
            ).fetchall()
        return [dict(row) for row in rows]

    def list_recent(self, limit: int = 25) -> List[Dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                """
                SELECT id, status, command_text, risk_level, operator_summary, proposed_at, approved_at, executed_at, exit_code
                FROM bash_proposals
                ORDER BY id DESC
                LIMIT ?
                """,
                (max(1, min(int(limit), 200)),),
            ).fetchall()
        return [dict(row) for row in rows]

    def get_proposal(self, proposal_id: int) -> Dict[str, Any] | None:
        with self._conn() as conn:
            row = conn.execute(
                """
                SELECT id, status, command_text, cwd, risk_level, operator_summary,
                       proposed_at, approved_at, executed_at, exit_code, notes
                FROM bash_proposals
                WHERE id=?
                LIMIT 1
                """,
                (int(proposal_id),),
            ).fetchone()
        return dict(row) if row else None

    def approve(self, proposal_id: int, approved_by: str = "operator") -> bool:
        with self._conn() as conn:
            cur = conn.execute(
                """
                UPDATE bash_proposals
                SET status='approved', approved_by=?, approved_at=?
                WHERE id=? AND status='proposed'
                """,
                (approved_by, _utc_now(), int(proposal_id)),
            )
            conn.commit()
            ok = cur.rowcount > 0
        if ok:
            self._mirror_event({"event": "approved", "proposal_id": int(proposal_id), "approved_by": approved_by, "at": _utc_now()})
        return ok

    def reject(self, proposal_id: int, reason: str = "", rejected_by: str = "operator") -> bool:
        note = (reason or "").strip()
        with self._conn() as conn:
            cur = conn.execute(
                """
                UPDATE bash_proposals
                SET status='rejected', notes=?
                WHERE id=? AND status='proposed'
                """,
                (note, int(proposal_id)),
            )
            conn.commit()
            ok = cur.rowcount > 0
        if ok:
            self._mirror_event({"event": "rejected", "proposal_id": int(proposal_id), "rejected_by": rejected_by, "reason": note, "at": _utc_now()})
        return ok

    def mark_executed(self, proposal_id: int, exit_code: int, stdout_preview: str, stderr_preview: str, executed_by: str = "operator") -> bool:
        with self._conn() as conn:
            cur = conn.execute(
                """
                UPDATE bash_proposals
                SET status='executed', executed_by=?, executed_at=?, exit_code=?, stdout_preview=?, stderr_preview=?
                WHERE id=? AND status IN ('approved', 'proposed')
                """,
                (executed_by, _utc_now(), int(exit_code), stdout_preview[:2000], stderr_preview[:2000], int(proposal_id)),
            )
            conn.commit()
            ok = cur.rowcount > 0
        if ok:
            self._mirror_event({
                "event": "executed",
                "proposal_id": int(proposal_id),
                "executed_by": executed_by,
                "exit_code": int(exit_code),
                "stdout_preview": stdout_preview[:500],
                "stderr_preview": stderr_preview[:500],
                "at": _utc_now(),
            })
        return ok

    def find_exact_duplicates(self, command_text: str) -> List[Dict[str, Any]]:
        """Find all approved/executed commands matching exact command text."""
        query = command_text.strip().lower()
        with self._conn() as conn:
            rows = conn.execute(
                """
                SELECT id, status, command_text, executed_at, exit_code
                FROM bash_proposals
                WHERE LOWER(command_text) = ? AND status IN ('approved', 'executed')
                ORDER BY id DESC
                """,
                (query,),
            ).fetchall()
        return [dict(row) for row in rows]

    def find_similar(self, command_text: str, limit: int = 20, min_ratio: float = 0.5) -> List[Tuple[Dict[str, Any], float]]:
        """Find similar approved/executed commands using string similarity.
        
        Returns list of (row_dict, similarity_ratio) sorted by similarity descending.
        """
        query = command_text.strip()
        if not query:
            return []
        
        with self._conn() as conn:
            rows = conn.execute(
                """
                SELECT id, status, command_text, executed_at, exit_code
                FROM bash_proposals
                WHERE status IN ('approved', 'executed')
                ORDER BY id DESC
                LIMIT 500
                """,
            ).fetchall()
        
        # Calculate similarity for each historical command
        scored: List[Tuple[Dict[str, Any], float]] = []
        for row in rows:
            cmd = str(row["command_text"]).strip()
            ratio = difflib.SequenceMatcher(None, query.lower(), cmd.lower()).ratio()
            if ratio >= min_ratio:
                scored.append((dict(row), ratio))
        
        # Sort by similarity descending
        scored.sort(key=lambda x: x[1], reverse=True)
        return scored[:limit]

    def get_top_executed(self, limit: int = 20) -> List[Dict[str, Any]]:
        """Get most frequently executed commands (by execution count)."""
        with self._conn() as conn:
            # Group by lower-cased command_text and count executions
            rows = conn.execute(
                """
                SELECT 
                    LOWER(command_text) AS cmd_normalized,
                    command_text AS cmd_original,
                    COUNT(*) AS exec_count,
                    MAX(id) AS latest_id,
                    MAX(executed_at) AS latest_exec
                FROM bash_proposals
                WHERE status = 'executed'
                GROUP BY cmd_normalized
                ORDER BY exec_count DESC, latest_id DESC
                LIMIT ?
                """,
                (max(1, min(int(limit), 200)),),
            ).fetchall()
        
        result = []
        for row in rows:
            result.append({
                "command": row["cmd_original"],
                "exec_count": row["exec_count"],
                "latest_id": row["latest_id"],
                "latest_exec": row["latest_exec"],
            })
        return result

    def get_recent_approved_or_executed(self, limit: int = 10) -> List[Dict[str, Any]]:
        with self._conn() as conn:
            rows = conn.execute(
                """
                SELECT id, status, command_text, approved_at, executed_at, exit_code
                FROM bash_proposals
                WHERE status IN ('approved', 'executed')
                ORDER BY id DESC
                LIMIT ?
                """,
                (max(1, min(int(limit), 200)),),
            ).fetchall()
        return [dict(row) for row in rows]

    def search_by_prefix(self, prefix: str, limit: int = 20) -> List[Dict[str, Any]]:
        """Find commands starting with prefix (case-insensitive)."""
        query = prefix.strip().lower()
        if not query:
            return []
        
        with self._conn() as conn:
            rows = conn.execute(
                """
                SELECT DISTINCT 
                    command_text,
                    COUNT(*) AS count,
                    MAX(id) AS latest_id
                FROM bash_proposals
                WHERE LOWER(command_text) LIKE ? AND status IN ('approved', 'executed')
                GROUP BY LOWER(command_text)
                ORDER BY count DESC, latest_id DESC
                LIMIT ?
                """,
                (query + "%", max(1, min(int(limit), 200))),
            ).fetchall()
        
        return [dict(row) for row in rows]
