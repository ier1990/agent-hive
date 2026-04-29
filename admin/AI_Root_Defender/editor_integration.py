#!/usr/bin/env python3
"""Editor integration for Root Guardian.

Provides /compose command to open nano/vim for user context editing.
Supports bootstrap mode for including system prompt in editing.
"""
from __future__ import annotations

import subprocess
import tempfile
from pathlib import Path
from typing import List, Optional


def open_editor(
    initial_content: str = "",
    editor_cmd: str = "nano",
    bootstrap_text: Optional[str] = None,
) -> Optional[str]:
    """Open an editor for user to compose/edit content.
    
    Args:
        initial_content: Content to pre-populate editor
        editor_cmd: Editor command (nano, vim, vi, etc.)
        bootstrap_text: Optional bootstrap/system prompt to include
    
    Returns:
        Edited content if user saved and exited, None if canceled.
    """
    content = initial_content
    
    # Prepend bootstrap if provided
    if bootstrap_text:
        content = f"{bootstrap_text}\n\n{content}"
    
    # Create temporary file
    with tempfile.NamedTemporaryFile(
        mode="w",
        suffix=".md",
        delete=False,
        encoding="utf-8",
    ) as f:
        temp_file = Path(f.name)
        f.write(content)
    
    try:
        # Open editor
        result = subprocess.run(
            [editor_cmd, str(temp_file)],
            check=False,
        )
        
        if result.returncode != 0:
            # Editor exited with error or user canceled
            return None
        
        # Read back edited content
        edited = temp_file.read_text(encoding="utf-8")
        
        # Remove bootstrap text if it was added
        if bootstrap_text and edited.startswith(bootstrap_text):
            edited = edited[len(bootstrap_text):].lstrip("\n")
        
        return edited if edited.strip() else None
    
    finally:
        # Clean up temp file
        try:
            temp_file.unlink()
        except Exception:
            pass


def get_available_editor(preferred: str = "", candidates: Optional[List[str]] = None) -> str:
    """Detect available editor command.
    
    Tries preferred first, then configured candidates.
    
    Returns:
        Available editor command
    """
    options: List[str] = []
    pref = str(preferred or "").strip()
    if pref:
        options.append(pref)
    if isinstance(candidates, list):
        for editor in candidates:
            name = str(editor or "").strip()
            if name and name not in options:
                options.append(name)
    if not options:
        options = ["nano", "vim", "vi", "emacs"]

    for editor in options:
        try:
            subprocess.run(
                ["which", editor],
                check=True,
                capture_output=True,
            )
            return editor
        except subprocess.CalledProcessError:
            continue
    
    # Fallback
    return pref or (options[0] if options else "nano")
