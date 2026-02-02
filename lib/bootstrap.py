#!/usr/bin/env python3
"""Domain Memory Python bootstrap.

Goal: give Python scripts the same deterministic path resolution as PHP bootstrap,
without depending on environment variables.

Primary outputs:
- APP_ROOT, APP_LIB
- PRIVATE_ROOT, PRIVATE_SCRIPTS

Strategy:
- Find APP_ROOT by walking upward until we find `lib/bootstrap.php`.
- Compute PRIVATE_ROOT by checking common + adjacent locations.
- Optionally read `${PRIVATE_ROOT}/bootstrap_paths.json` if it exists (written by PHP bootstrap).

This module is intentionally dependency-free.
"""

from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Dict, Optional, Union


PathLike = Union[str, os.PathLike]


def _norm(p: Path) -> str:
    return str(p.resolve())


def _find_private_root_with_paths_file(start: Path) -> Optional[Path]:
    """If we're running from within PRIVATE_ROOT (e.g. /web/private/scripts),
    locate the nearest parent containing bootstrap_paths.json.

    This allows private-runtime scripts to resolve APP_ROOT/PRIVATE_ROOT without
    needing the repo checkout to be a parent directory.
    """
    cur = start if start.is_dir() else start.parent
    for parent in [cur] + list(cur.parents):
        try:
            if (parent / "bootstrap_paths.json").is_file():
                return parent
        except OSError:
            continue
    return None


def find_app_root(start: Optional[PathLike] = None) -> Path:
    """Find repo/app root by locating `lib/bootstrap.php` in parent chain."""
    if start is None:
        start_path = Path(__file__).resolve()
    else:
        start_path = Path(start).resolve()

    # If start is a file, begin from its parent.
    cur = start_path if start_path.is_dir() else start_path.parent

    for parent in [cur] + list(cur.parents):
        if (parent / "lib" / "bootstrap.php").is_file():
            return parent

    raise RuntimeError(
        f"Could not find app root from start={start_path}; expected to find lib/bootstrap.php in a parent directory."
    )


def compute_private_root(app_root: Path) -> Path:
    """Compute PRIVATE_ROOT similarly to PHP bootstrap, but with safe fallbacks."""
    # Prefer explicit env var if someone *really* wants it, but not required.
    env_private = os.environ.get("PRIVATE_ROOT")
    if env_private:
        p = Path(env_private).expanduser()
        if p.is_dir():
            return p

    candidates = [
        Path("/web/private"),
        Path("/var/www/private"),
        app_root.parent / "private",
        app_root / "private",
    ]

    for cand in candidates:
        try:
            if cand.is_dir():
                return cand
        except OSError:
            continue

    # Final fallback: create a private dir alongside temp. (Better than hard failing for cron scripts.)
    return Path(os.getenv("TMPDIR", "/tmp")) / "domain-memory-private"


def read_paths_file(private_root: Path) -> Optional[Dict[str, str]]:
    """Read `${PRIVATE_ROOT}/bootstrap_paths.json` if it exists."""
    f = private_root / "bootstrap_paths.json"
    if not f.is_file():
        return None
    try:
        data = json.loads(f.read_text(encoding="utf-8"))
        if isinstance(data, dict):
            # Only accept string values.
            out = {k: v for k, v in data.items() if isinstance(k, str) and isinstance(v, str)}
            return out
    except Exception:
        return None
    return None


def get_paths(start: Optional[PathLike] = None) -> Dict[str, str]:
    """Return resolved bootstrap paths for scripts.

    Resolution order:
    1) If we can locate a nearby PRIVATE_ROOT containing bootstrap_paths.json, use it.
       (Supports running from /web/private/scripts.)
    2) Otherwise, discover APP_ROOT by finding lib/bootstrap.php in a parent directory.
       Then compute PRIVATE_ROOT and optionally read bootstrap_paths.json there.
    """
    start_path = Path(__file__).resolve() if start is None else Path(start).resolve()

    # Fast-path for private-runtime scripts.
    pr = _find_private_root_with_paths_file(start_path)
    if pr is not None:
        file_paths = read_paths_file(pr)
        if file_paths and file_paths.get("APP_ROOT") and file_paths.get("APP_LIB"):
            return file_paths

    app_root = find_app_root(start_path)
    app_lib = app_root / "lib"
    private_root = compute_private_root(app_root)

    # If PHP has written an authoritative paths file, prefer it.
    file_paths = read_paths_file(private_root)
    if file_paths and file_paths.get("APP_ROOT") and file_paths.get("APP_LIB"):
        return file_paths

    private_scripts = private_root / "scripts"

    return {
        "APP_ROOT": _norm(app_root),
        "APP_LIB": _norm(app_lib),
        "PRIVATE_ROOT": _norm(private_root),
        "PRIVATE_SCRIPTS": _norm(private_scripts),
    }
