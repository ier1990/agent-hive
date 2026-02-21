#!/usr/bin/env python3
"""Root-only wrapper for process_bash_history.py.

Use this as the single cron-dispatcher task when root access is required.

# CRON: 5 * * * *
"""

from __future__ import annotations

import os
import subprocess
import sys


def main() -> int:
    if hasattr(os, "geteuid") and os.geteuid() != 0:
        print("root_process_bash_history.py must run as root", file=sys.stderr)
        return 1

    # Ensure bash history files are readable by root
    perm_fixes = [
        "/root/.bash_history",
        "/root/.bash_history.db",
    ]
    for path in perm_fixes:
        if os.path.exists(path):
            try:
                os.chmod(path, 0o600)
            except Exception as e:
                print("warning: could not chmod %s: %s" % (path, e), file=sys.stderr)

    target = os.path.join(os.path.dirname(__file__), "process_bash_history.py")
    cmd = [sys.executable, target] + sys.argv[1:]
    return int(subprocess.call(cmd))


if __name__ == "__main__":
    raise SystemExit(main())
