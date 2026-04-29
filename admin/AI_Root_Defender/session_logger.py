#!/usr/bin/env python3
"""Session logger for Root Guardian.

Manages session memory files in logs/session_YYYYMMDDHHMM.md format with:
- YAML frontmatter (metadata)
- Summary section (bullets)
- Memory section (YAML-ish contracts + plaintext events)
- Artifacts section (preserved source text for later ingestion)
- Actions suggested section (next steps)
"""
from __future__ import annotations

import json
import os
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, List, Optional


class SessionLogger:
    def __init__(self, log_dir: Path, host: str = "localhost"):
        """Initialize session logger.
        
        Args:
            log_dir: Directory to store session files (will be created if needed)
            host: Hostname for session metadata
        """
        self.log_dir = Path(log_dir)
        self.log_dir.mkdir(parents=True, exist_ok=True)
        
        self.host = host
        self.session_file: Optional[Path] = None
        self.started_at: Optional[datetime] = None
        self.tags: List[str] = []
        self.summary_bullets: List[str] = []
        self.memory_entries: List[str] = []
        self.artifact_entries: List[str] = []
        self.actions_suggested: List[str] = []
        self.turn_count: int = 0
        self.has_activity: bool = False
    
    def start_session(self) -> Path:
        """Initialize a new session file and return its path.
        
        Reserves logs/session_YYYYMMDDHHMM.md path. File is only written
        when activity occurs (turns/events), so empty sessions are not saved.
        """
        self.started_at = datetime.now()
        timestamp = self.started_at.strftime("%Y%m%d%H%M")
        self.session_file = self.log_dir / f"session_{timestamp}.md"
        return self.session_file
    
    def add_turn(
        self,
        turn_count: int,
        contract: Dict[str, Any],
        tags: Optional[List[str]] = None,
    ) -> None:
        """Record a turn contract and update session file.
        
        Args:
            turn_count: Current turn number
            contract: Full turn contract JSON
            tags: Optional tags to add/update for this session
        """
        if not self.session_file:
            raise RuntimeError("Session not started. Call start_session() first.")
        
        self.turn_count = turn_count
        self.has_activity = True
        
        self.memory_entries.append(f"**Turn {turn_count}:**")
        self.memory_entries.append("```yaml")
        self.memory_entries.extend(_yaml_lines(contract))
        self.memory_entries.append("```")
        
        # Extract summary/reason for bullets if present
        summary = str(contract.get("summary", "")).strip()
        reason = str(contract.get("reason", "")).strip()
        next_tool = contract.get("next_tool")
        
        if summary and summary not in self.summary_bullets:
            self.summary_bullets.append(summary)
        if reason and reason not in self.summary_bullets:
            self.summary_bullets.append(reason)
        if next_tool:
            action = f"Proposed: {next_tool}"
            if action not in self.actions_suggested:
                self.actions_suggested.append(action)
        
        # Update tags
        if tags:
            for tag in tags:
                tag_clean = str(tag).strip().lower()
                if tag_clean and tag_clean not in self.tags:
                    self.tags.append(tag_clean)
        
        # Prune summary to reasonable size (keep most recent 8 bullets)
        if len(self.summary_bullets) > 8:
            self.summary_bullets = self.summary_bullets[-8:]
        
        # Write updated file
        self._write_file()

    def add_event(self, message: str, action: Optional[str] = None, tags: Optional[List[str]] = None) -> None:
        """Record non-turn shell activity (e.g., /tools actions) in session log."""
        if not self.session_file:
            raise RuntimeError("Session not started. Call start_session() first.")

        text = str(message or "").strip()
        if not text:
            return

        self.has_activity = True
        self.memory_entries.append(f"- {text}")

        if action:
            action_text = str(action).strip()
            if action_text and action_text not in self.actions_suggested:
                self.actions_suggested.append(action_text)

        if tags:
            for tag in tags:
                tag_clean = str(tag).strip().lower()
                if tag_clean and tag_clean not in self.tags:
                    self.tags.append(tag_clean)

        self._write_file()

    def add_artifact(
        self,
        name: str,
        content: str,
        kind: str = "artifact",
        metadata: Optional[Dict[str, Any]] = None,
        tags: Optional[List[str]] = None,
    ) -> None:
        """Persist a user/source artifact verbatim for later recall or ingestion."""
        if not self.session_file:
            raise RuntimeError("Session not started. Call start_session() first.")

        artifact_name = str(name or "").strip() or "artifact"
        artifact_kind = str(kind or "").strip() or "artifact"
        text = str(content or "")
        meta = dict(metadata or {})
        meta["kind"] = artifact_kind
        meta["bytes"] = len(text)

        self.has_activity = True
        self.artifact_entries.append(f"### {artifact_name}")
        self.artifact_entries.append("```yaml")
        self.artifact_entries.extend(_yaml_lines(meta))
        self.artifact_entries.append("```")
        self.artifact_entries.append("```text")
        self.artifact_entries.append(text.rstrip("\n"))
        self.artifact_entries.append("```")

        if tags:
            for tag in tags:
                tag_clean = str(tag).strip().lower()
                if tag_clean and tag_clean not in self.tags:
                    self.tags.append(tag_clean)

        self._write_file()
    
    def end_session(self, final_summary: Optional[str] = None) -> Path:
        """Finalize session file on completion.
        
        Args:
            final_summary: Optional final summary to append
        
        Returns:
            Path to completed session file
        """
        if not self.session_file:
            raise RuntimeError("Session not started. Call start_session() first.")

        # Do not persist no-op sessions (no turns/events and no actions).
        if not self.has_activity and self.turn_count == 0 and not self.memory_entries and not self.artifact_entries and not self.actions_suggested:
            return self.session_file
        
        if final_summary:
            self.summary_bullets.append(final_summary)
        
        self._write_file()
        return self.session_file
    
    def _write_file(self) -> None:
        """Write current session state to disk."""
        if not self.session_file:
            return
        
        now = datetime.now()
        ended_at = now.isoformat() if self.started_at else None
        
        lines: List[str] = []
        
        # YAML frontmatter
        lines.append("---")
        lines.append(f"host: {self.host}")
        lines.append(f"started_at: {self.started_at.isoformat() if self.started_at else 'unknown'}")
        lines.append(f"ended_at: {ended_at}")
        lines.append(f"turns: {self.turn_count}")
        lines.append("tags:")
        if self.tags:
            for tag in self.tags:
                lines.append(f"  - {tag}")
        else:
            lines.append("  - none")
        lines.append("---")
        lines.append("")
        
        # Summary section
        lines.append("## Summary")
        if self.summary_bullets:
            for bullet in self.summary_bullets:
                lines.append(f"- {bullet}")
        else:
            lines.append("- Session started")
        lines.append("")
        
        # Memory section
        lines.append("## Memory")
        if self.memory_entries:
            for entry in self.memory_entries:
                lines.append(entry)
        else:
            lines.append("(no memory recorded yet)")
        lines.append("")

        # Artifacts section
        lines.append("## Artifacts")
        if self.artifact_entries:
            for entry in self.artifact_entries:
                lines.append(entry)
        else:
            lines.append("(none)")
        lines.append("")
        
        # Actions suggested section
        lines.append("## Actions Suggested")
        if self.actions_suggested:
            for action in self.actions_suggested:
                lines.append(f"- {action}")
        else:
            lines.append("(none)")
        lines.append("")
        
        # Write atomically
        content = "\n".join(lines)
        try:
            self.session_file.write_text(content, encoding="utf-8")
        except Exception as exc:
            print(f"warning: failed to write session log: {exc}")
    
    def get_session_file(self) -> Optional[Path]:
        """Get the current session file path."""
        return self.session_file
    
    @staticmethod
    def load_session(session_file: Path) -> Dict[str, Any]:
        """Load and parse an existing session file.
        
        Returns dict with keys: metadata, summary, raw_log, actions
        """
        if not session_file.exists():
            raise FileNotFoundError(f"Session file not found: {session_file}")
        
        content = session_file.read_text(encoding="utf-8")
        result: Dict[str, Any] = {
            "metadata": {},
            "summary": [],
            "memory": [],
            "raw_log": [],
            "artifacts": [],
            "actions": [],
        }
        
        # Parse YAML frontmatter
        if content.startswith("---"):
            end_marker = content.find("\n---\n", 4)
            if end_marker > 0:
                yaml_block = content[4:end_marker]
                current_list_key: Optional[str] = None
                for line in yaml_block.split("\n"):
                    if not line.strip():
                        continue
                    if line.startswith("  - ") and current_list_key:
                        result["metadata"].setdefault(current_list_key, [])
                        result["metadata"][current_list_key].append(line[4:].strip())
                        continue
                    current_list_key = None
                    if ":" in line:
                        key, val = line.split(":", 1)
                        key = key.strip()
                        val = val.strip()
                        if val == "":
                            current_list_key = key
                            result["metadata"][key] = []
                        else:
                            result["metadata"][key] = val
                content = content[end_marker + 5:]
        
        # Parse markdown sections
        sections = content.split("## ")
        for section in sections:
            if not section.strip():
                continue
            lines = section.split("\n", 1)
            if len(lines) < 2:
                continue
            section_title = lines[0].strip()
            section_content = lines[1]
            
            if section_title.lower() == "summary":
                for line in section_content.split("\n"):
                    line = line.strip()
                    if line.startswith("- "):
                        result["summary"].append(line[2:])
            elif section_title.lower() == "memory":
                result["memory"].append(section_content.strip())
                result["raw_log"].append(section_content.strip())
            elif section_title.lower() == "raw log":
                result["memory"].append(section_content.strip())
                result["raw_log"].append(section_content.strip())
            elif section_title.lower() == "artifacts":
                result["artifacts"].append(section_content.strip())
            elif section_title.lower() == "actions suggested":
                for line in section_content.split("\n"):
                    line = line.strip()
                    if line.startswith("- "):
                        result["actions"].append(line[2:])
        
        return result
    
    @staticmethod
    def list_sessions(log_dir: Path, limit: int = 10) -> List[Path]:
        """List recent session files, newest first.
        
        Args:
            log_dir: Directory containing session files
            limit: Maximum number of sessions to return
        
        Returns:
            List of Path objects, sorted by timestamp (newest first)
        """
        log_dir = Path(log_dir)
        if not log_dir.exists():
            return []
        
        sessions = sorted(log_dir.glob("session_*.md"), reverse=True)
        return sessions[:limit]


def _yaml_scalar(value: Any) -> str:
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, (int, float)):
        return str(value)
    text = str(value)
    if text == "":
        return '""'
    if any(ch in text for ch in [":", "#", "{", "}", "[", "]"]) or "\n" in text or text.strip() != text:
        return json.dumps(text, ensure_ascii=False)
    return text


def _yaml_lines(value: Any, indent: int = 0) -> List[str]:
    prefix = " " * indent
    lines: List[str] = []
    if isinstance(value, dict):
        for key, item in value.items():
            key_text = str(key)
            if isinstance(item, (dict, list)):
                lines.append(f"{prefix}{key_text}:")
                lines.extend(_yaml_lines(item, indent + 2))
            else:
                lines.append(f"{prefix}{key_text}: {_yaml_scalar(item)}")
        return lines
    if isinstance(value, list):
        if not value:
            lines.append(f"{prefix}[]")
            return lines
        for item in value:
            if isinstance(item, (dict, list)):
                lines.append(f"{prefix}-")
                lines.extend(_yaml_lines(item, indent + 2))
            else:
                lines.append(f"{prefix}- {_yaml_scalar(item)}")
        return lines
    lines.append(f"{prefix}{_yaml_scalar(value)}")
    return lines
