# AI Root Defender - Quick Start

## Activation Command (Copy & Paste)

```bash
cd /web/html/admin/AI_Root_Defender && source .venv/bin/activate && python3 agent_bash.py
```

Or if already in `/web/html/admin/AI_Root_Defender`:
```bash
source .venv/bin/activate
python3 agent_bash.py
```

## What is Root Defender?

Root Defender is an AI-assisted shell harness that:
- ✓ Proposes bash commands via AI analysis
- ✓ Requires inline approval before execution (accept/reject/skip)
- ✓ Maintains bash history database with search & dedup
- ✓ Reuses exact prior approvals automatically when safe
- ✓ Logs session memory and compose artifacts for auditing and recall

## Essential Commands

### Bash History (`/bh`)

Show pending proposals:
```
/bh pending [N]           # List pending proposals
/bh recent [N]            # Show recent history (all statuses)
```

Search & discovery:
```
/bh similar <pattern>     # Find similar approved commands
/bh top [N]               # Show top N most-used executed commands (default: 20)
/bh check <command>       # Check for duplicates before proposing
```

Manage proposals:
```
/bh propose <command>     # Manually propose a command
/bh approve <id>          # Approve and execute a proposal
/bh reject <id> [reason]  # Reject a proposal
```

### Core Commands

```
/help                     # Show all available commands
/status                   # Show current session status
/provider                 # Show AI provider info
/hello                    # Run provider health check
/debug                    # Toggle debug mode
/contract <json>          # Submit a manual turn contract
```

### Composition & Notes

```
/compose                  # Open editor for context composition
/compose --bootstrap      # Open with boot prompt context
/notes                    # Create a new note
/memory [N] [full]        # Recall past session memory
```

## Common Workflows

### Approving AI Proposals

When the AI proposes a command, you'll see inline options:
- `y` / `accept` - Execute the command
- `n` / `reject` - Reject it
- `s` / `skip` - Skip (AI continues without result)

### Searching Before Proposing

Check if a command has already been approved:
```
/bh check cat /var/log/syslog
# Output: "✓ No duplicates" or "⚠ DUPLICATE FOUND: #15"
```

### Discovering Approved Commands

See your most frequently used approved commands:
```
/bh top 20
# Shows top 20 commands with execution count
```

### One-Shot JSON Run

```bash
python3 agent_bash.py --non-interactive \
  --prompt "Check Apache and MySQL logs for fresh errors and summarize them" \
  --json
```

### Cron Task Queue

Drop a task JSON file into `/web/private/guardian_tasks/`:

```json
{
  "prompt": "Check Apache access and error logs for /v1/ failures from LAN clients.",
  "provider": "0",
  "max_turns": 6
}
```

Then process one task:

```bash
python3 agent_bash.py --non-interactive --claim-task --json
```

## Environment

- Python: 3.8+
- Virtual env: `.venv/`
- Dependencies: `requirements.txt`
- Database: `bh/bash_history.db` (SQLite)
- Logs: `logs/session_*.md` (one per session)
- Notes: `notes/` directory
- Repo defaults: `config/settings.default.json`, `config/tools.default.json`
- Private overrides: `/web/private/agent_settings.json`, `/web/private/agent_tools.json`

## Activation Troubleshooting

**"bash: python3: command not found"**
- Check: `which python3` or `python --version`
- Fallback: `which python` and update `activate.sh`

**"ModuleNotFoundError: No module named 'agent_bash'"**
- Ensure you're in `/web/html/admin/AI_Root_Defender`: `cd /web/html/admin/AI_Root_Defender`
- Run from the repo root, not subdirectories

**Virtual env not activating**
- Manual: `source /web/html/admin/AI_Root_Defender/.venv/bin/activate`
- Check: `which python` should point to `.venv/bin/python`

## Next Steps

1. **Read the README** for full documentation
2. **Check the logs** (`logs/`) to see past sessions
3. **Use `/bh similar`** to explore approved commands
4. **Try `/bh check`** before proposing new commands
