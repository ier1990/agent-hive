#!/usr/bin/env python3
# /web/html/admin/AI/scripts/agent_shell.py

'''
Recommendation: implement this in one script first

Create:

/web/private/mcp/scripts/agent_shell.py

It calls OpenAI

It parses ```mcp ```

Executes commands

Feeds results back

Stops on DONE

Then your worker can run it as a tool like anything else.
'''
import json, os, sys
import subprocess