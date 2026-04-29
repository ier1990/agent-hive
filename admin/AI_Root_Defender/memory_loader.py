#!/usr/bin/env python3
"""Memory loader for Root Guardian.

Enables /memory command to recall previous sessions.
Supports both full session loading and summary-only mode.
"""
from __future__ import annotations

from pathlib import Path
from typing import Any, Dict, List, Optional

from session_logger import SessionLogger


class MemoryLoader:
    """Manages session memory recall."""
    
    def __init__(self, logs_dir: Path):
        """Initialize memory loader.
        
        Args:
            logs_dir: Directory containing session logs
        """
        self.logs_dir = Path(logs_dir)
    
    def get_session_summaries(self, limit: int = 10) -> List[Dict[str, Any]]:
        """Get summaries of recent sessions.
        
        Args:
            limit: Number of sessions to return (newest first)
        
        Returns:
            List of session summaries with metadata and bullet points only
        """
        sessions = SessionLogger.list_sessions(self.logs_dir, limit=limit)
        summaries = []
        
        for session_file in sessions:
            try:
                data = SessionLogger.load_session(session_file)
                summaries.append({
                    "file": session_file.name,
                    "metadata": data["metadata"],
                    "summary": data["summary"],
                })
            except Exception as exc:
                print(f"warning: failed to load session {session_file.name}: {exc}")
        
        return summaries
    
    def get_session_full(self, session_index: int = 1) -> Optional[Dict[str, Any]]:
        """Get full content of a specific session.
        
        Args:
            session_index: Index of session (1 = most recent, 2 = second recent, etc.)
        
        Returns:
            Full session data or None if not found
        """
        sessions = SessionLogger.list_sessions(self.logs_dir, limit=session_index)
        
        if session_index > len(sessions):
            return None
        
        # Index is 1-based (1 = most recent = index 0)
        session_file = sessions[session_index - 1]
        
        try:
            return SessionLogger.load_session(session_file)
        except Exception as exc:
            print(f"warning: failed to load session {session_file.name}: {exc}")
            return None
    
    def list_available_sessions(self, limit: int = 10) -> List[str]:
        """List available session filenames.
        
        Args:
            limit: Number of sessions to list
        
        Returns:
            List of session filenames (newest first)
        """
        sessions = SessionLogger.list_sessions(self.logs_dir, limit=limit)
        return [s.name for s in sessions]
    
    def format_session_display(self, session_data: Dict[str, Any], full: bool = True) -> str:
        """Format session data for terminal display.
        
        Args:
            session_data: Session data from SessionLogger.load_session()
            full: If True, show full log; if False, show summary only
        
        Returns:
            Formatted string for display
        """
        lines = []
        
        # Header
        metadata = session_data.get("metadata", {})
        lines.append(f"Session: {metadata.get('host', 'unknown')} @ {metadata.get('started_at', 'unknown')}")
        lines.append(f"Turns: {metadata.get('turns', '?')} | Tags: {metadata.get('tags', '[]')}")
        lines.append("")
        
        # Summary
        summary = session_data.get("summary", [])
        if summary:
            lines.append("## Summary")
            for bullet in summary:
                lines.append(f"  - {bullet}")
            lines.append("")
        
        # Actions
        actions = session_data.get("actions", [])
        if actions:
            lines.append("## Actions Suggested")
            for action in actions:
                lines.append(f"  - {action}")
            lines.append("")
        
        # Full memory if requested
        if full:
            memory = session_data.get("memory", []) or session_data.get("raw_log", [])
            if memory:
                lines.append("## Memory")
                lines.append(memory[0] if isinstance(memory, list) and memory else "(empty)")
                lines.append("")

            artifacts = session_data.get("artifacts", [])
            if artifacts:
                lines.append("## Artifacts")
                lines.append(artifacts[0] if isinstance(artifacts, list) and artifacts else "(empty)")
                lines.append("")
        
        return "\n".join(lines)
