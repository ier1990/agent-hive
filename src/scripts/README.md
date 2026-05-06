# src/scripts

This directory is the canonical, version-controlled source for scripts that get wrapped into `/web/private/scripts/` by `root_update_scripts.sh`.

If a script is no longer part of the active deployment set, move it out of this folder. In this repo, retired copies are archived under `/web/html/src/scripts/.b/`.

## Active scripts

### Bash history pipeline

Simple plan:

1. Read bash history for each configured user
2. Save unique commands into the local SQLite knowledge base
3. Use local AI to classify or summarize commands
4. Keep the bash pipeline local-only by default

For most installs, there is only one script you should schedule:

- `root_process_bash_history.py`:
  root-only entrypoint for the full bash-history pipeline

What it runs internally:

- `process_bash_history.py`:
  orchestrates the stages below
- `ingest_bash_history_to_kb.py`:
  imports bash history into `bash_history.db`
- `ai_bash_enrich.py`:
  merged local AI stage that classifies commands and writes AI bash-note metadata

Write boundaries for `ai_bash_enrich.py`:

- reads bash-history note rows from `human_notes.db`
- writes only heartbeat rows to `human_notes.db.job_runs`
- writes command classification to `bash_history.db`
- writes AI note metadata to `notes_ai_metadata.db`

Optional or maintenance-only:

- `root_ingest_bash_history.py`:
  root-only ingest wrapper for manual troubleshooting
- `save_bash_history_threaded.py`:
  optional script that writes bash history into `human_notes.db` as threaded `logs` notes
- `classify_bash_commands.py` and `ai_notes.py`:
  older standalone AI stages kept for compatibility and manual debugging
- `queue_bash_searches.py`:
  older search-related helper kept for manual or legacy workflows
- `ai_search_summ.py`:
  legacy search-summary helper for `search_cache.db` and `ai_search_notes.db`; not part of the current default bash-history or notes pipeline and should not be enabled by default on new installs

### Bash history users

The pipeline now defaults to `BASH_HISTORY_USERS` from `/web/private/.env`.

Example:

```env
BASH_HISTORY_USERS=samekhi,root
```

If that value is missing, it falls back to `samekhi,root`.

### Safety notes

- Local AI processing is the preferred default:
  bash history stays local while still getting classification or summaries
- External search is intentionally not part of the default bash-history concept:
  raw shell commands can include usernames, hostnames, paths, secrets, private repo names, or sensitive arguments
- If search-style enrichment is ever brought back, it should use sanitized command families or reduced summaries instead of the full original command line

### Shared helpers

- `notes_config.py`:
  shared config and private-root helpers for the notes/bash pipeline
- `ai_templates.py`:
  payload/template helper used by AI script stages

### Deployment and maintenance

- `root_update_scripts.sh`:
  creates wrappers in `/web/private/scripts/`
- `root_dirperm.sh` and `dirperm.sh`:
  filesystem permission helpers
- `cron_dispatcher.php`:
  cron runner used by the app
- `release_fetch_pinned.sh`:
  pulls a pinned GitHub release into the local release cache

### System and node helpers

- `server_register.sh`
- `sysinfo.sh`
- `sysinfo_local.sh`
- `root_sysinfo_local.sh`

### Codewalker

- `codewalker.py`
- `codewalker_cli.php`

## Notes

- `admin/notes/scripts/` and `admin/AI_MotherQue/scripts/` still contain older feature-local copies of some scripts.
- Prefer adding new deployable scripts here in `src/scripts/`.
- Before removing a script from this folder, confirm nothing in cron, installer docs, or admin UI still points at its wrapper under `/web/private/scripts/`.
