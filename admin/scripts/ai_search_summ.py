#!/usr/bin/env python3
"""Compatibility wrapper.

Historically cron called /web/html/admin/scripts/ai_search_summ.py.
The maintained version lives in /web/html/admin/notes/scripts/ai_search_summ.py.
"""

import runpy

if __name__ == "__main__":
    runpy.run_path("/web/html/admin/notes/scripts/ai_search_summ.py", run_name="__main__")
