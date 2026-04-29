#!/usr/bin/env python3
"""Notes handler for Root Guardian.

Manages /notes command for user-owned reference files.
Coordinates with AI for metadata generation (or uses templates).
"""
from __future__ import annotations

import json
import re
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, Optional


class NotesHandler:
    """Manages notes directory and file operations."""
    
    def __init__(self, notes_dir: Path):
        """Initialize notes handler.
        
        Args:
            notes_dir: Directory to store note files (will be created if needed)
        """
        self.notes_dir = Path(notes_dir)
        self.notes_dir.mkdir(parents=True, exist_ok=True)
    
    def save_note(
        self,
        content: str,
        title: str = "",
        tags: Optional[list[str]] = None,
        session_ref: str = "",
    ) -> Path:
        """Save a note with generated metadata.
        
        Args:
            content: Note content (user-written)
            title: AI-generated title
            tags: AI-generated tags
            session_ref: Reference to session that created this note
        
        Returns:
            Path to created note file
        """
        if not content.strip():
            raise ValueError("Note content cannot be empty")
        
        if not title:
            title = "Untitled Note"
        
        if not tags:
            tags = []
        
        # Generate filename from title slug
        filename = self._title_to_filename(title)
        file_path = self.notes_dir / filename
        
        # Ensure unique filename
        counter = 1
        base_path = file_path
        while file_path.exists():
            stem = base_path.stem
            file_path = self.notes_dir / f"{stem}_{counter}.md"
            counter += 1
        
        # Create frontmatter
        now = datetime.now()
        frontmatter = {
            "title": title,
            "tags": tags,
            "date": now.strftime("%Y-%m-%d"),
            "session_ref": session_ref,
        }
        
        # Write note file
        lines = ["---"]
        for key, value in frontmatter.items():
            if isinstance(value, list):
                lines.append(f"{key}: {json.dumps(value)}")
            else:
                lines.append(f"{key}: {value}")
        lines.append("---")
        lines.append("")
        lines.append(content.strip())
        lines.append("")
        
        file_content = "\n".join(lines)
        file_path.write_text(file_content, encoding="utf-8")
        
        return file_path
    
    def list_notes(self, limit: int = 50) -> list[Path]:
        """List all notes, newest first.
        
        Args:
            limit: Maximum number to return
        
        Returns:
            List of note file paths
        """
        notes = sorted(self.notes_dir.glob("*.md"), key=lambda p: p.stat().st_mtime, reverse=True)
        return notes[:limit]
    
    def search_notes(self, query: str) -> list[Path]:
        """Search notes by content or filename.
        
        Args:
            query: Search term (case-insensitive)
        
        Returns:
            List of matching note paths
        """
        results = []
        query_lower = query.lower()
        
        for note_file in self.notes_dir.glob("*.md"):
            # Check filename
            if query_lower in note_file.name.lower():
                results.append(note_file)
                continue
            
            # Check content
            try:
                content = note_file.read_text(encoding="utf-8", errors="ignore")
                if query_lower in content.lower():
                    results.append(note_file)
            except Exception:
                pass
        
        return sorted(results, key=lambda p: p.stat().st_mtime, reverse=True)
    
    def load_note(self, note_file: Path) -> Dict[str, Any]:
        """Load and parse a note file.
        
        Returns dict with keys: metadata, content
        """
        if not note_file.exists():
            raise FileNotFoundError(f"Note not found: {note_file}")
        
        text = note_file.read_text(encoding="utf-8")
        result: Dict[str, Any] = {
            "metadata": {},
            "content": "",
        }
        
        # Parse frontmatter
        if text.startswith("---"):
            end_marker = text.find("\n---\n", 4)
            if end_marker > 0:
                yaml_block = text[4:end_marker]
                for line in yaml_block.split("\n"):
                    if ":" in line:
                        key, val = line.split(":", 1)
                        val = val.strip()
                        # Try to parse JSON arrays
                        if val.startswith("[") and val.endswith("]"):
                            try:
                                result["metadata"][key.strip()] = json.loads(val)
                            except json.JSONDecodeError:
                                result["metadata"][key.strip()] = val
                        else:
                            result["metadata"][key.strip()] = val
                text = text[end_marker + 5:]
        
        result["content"] = text.strip()
        return result
    
    @staticmethod
    def _title_to_filename(title: str) -> str:
        """Convert title to filename slug.
        
        Example: "Apache Vhost Mirror" -> "2026-04-28-apache-vhost-mirror.md"
        """
        # Lowercase and replace spaces/non-alphanumeric with dashes
        slug = title.lower()
        slug = re.sub(r"[^a-z0-9]+", "-", slug)
        slug = slug.strip("-")
        
        # Add date prefix
        now = datetime.now()
        date_prefix = now.strftime("%Y-%m-%d")
        
        return f"{date_prefix}-{slug}.md"
    
    def mkdir(self, folder_name: str) -> Path:
        """Create a subfolder under notes directory.
        
        Args:
            folder_name: Subfolder name
        
        Returns:
            Path to created folder
        """
        folder = self.notes_dir / folder_name
        folder.mkdir(parents=True, exist_ok=True)
        return folder
