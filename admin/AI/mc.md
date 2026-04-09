# `/mc` File Browser Draft

## Goal

Add a lightweight Midnight Commander style file browser to the AI shell so we get:

`agent shell -> browse files -> act on selected file -> return to agent`

The browser should make local file work fast, while keeping AI actions explicit and safe.

## Core idea

`/mc` opens a file browser rooted at a safe base path such as:

- current project root
- configured `code_root`
- current working directory inside the project
- current /web/private directory outside the project

From there, each key press should dispatch one of two action types:

- local command
- agent action

That split matters:

- local commands should stay fast and predictable
- agent actions should be intentional and inspectable

## First version scope

Keep v1 small and useful:

- `F1` summarize selected file
- `F3` view selected file
- `F4` edit selected file with default editor
- `F10` exit browser and return to `agent>`

That is enough to make `/mc` feel valuable without building a full TUI platform all at once.

## Suggested key map

### Local commands

- `F3` view
- `F4` edit
- `F10` exit

### Agent actions

- `F1` summarize selected file
- `F2` audit selected file
- `F5` explain selected function or class
- `F6` suggest refactor
- `F7` propose tests
- `F8` generate docs notes

For v1, only implement `F1`, `F3`, `F4`, and `F10`.

## Action model

Do not hardcode every key in Python logic if we can avoid it.

Use a small config-driven action registry. Example shape:

```json
[
  {
    "key": "F1",
    "label": "Summarize",
    "type": "prompt",
    "prompt_template": "Task: summarize_file\nFilename: {{file}}\nLanguage: {{language}}\n\nFile contents:\n{{contents}}"
  },
  {
    "key": "F3",
    "label": "View",
    "type": "command",
    "command": "less {{file}}"
  },
  {
    "key": "F4",
    "label": "Edit",
    "type": "command",
    "command": "nano {{file}}"
  }
]
```

That gives us:

- easier customization later
- room for admin-editable actions
- less code churn when we want new browser actions

## AI action flow

For agent-backed keys like `F1`, the browser should:

1. resolve the selected file path
2. inspect file type and size
3. decide whether to inline contents or just pass metadata
4. send a structured prompt into the existing agent loop
5. show the result
6. return to the browser or shell

Recommended prompt shape:

```text
Task: summarize_file
Filename: /web/html/admin/AI/agent_runtime.py
Language: python

You may use local file-reading tools if needed.
If file contents are included below, prefer that copy first.

File contents:
...
```

That is better than a loose "summarize this file" prompt because it helps the model stay anchored.

## Large file strategy

Do not always dump the full file into the prompt.

Better behavior:

- if file is small, inline contents
- if file is medium, inline a capped amount and mention truncation
- if file is large, send metadata and let the agent call file-reading tools

Suggested thresholds:

- under `32 KB`: inline
- `32 KB - 128 KB`: inline truncated preview
- over `128 KB`: metadata only, use tools on demand

## Safety rules

We need guardrails before this becomes comfortable to use.

### Skip or block by default

- binary files
- secrets
- `.env`
- key material
- DB files
- log archives
- anything outside allowed roots

### Warn or confirm

- very large files
- generated vendor trees
- protected paths
- write actions on sensitive files

### Allowed root idea

The browser should stay inside configured roots such as:

- `/web/html`
- maybe selected safe private subpaths if explicitly enabled

Do not let `/mc` wander the whole filesystem by default.

## UX flow

Recommended shell flow:

1. user types `/mc`
2. browser opens at `code_root`
3. user moves selection
4. user presses a key
5. browser dispatches:
   - local command dispatcher
   - agent prompt dispatcher
6. result is shown
7. user returns to browser or back to `agent>`

## Config idea

Possible future config file:

- `admin/AI/default_mc_actions.json`
- runtime copy: `/web/private/mc_actions.json`

That would match the pattern already used by:

- `default_agent.json`
- `/web/private/agent.json`
- `/web/private/agent_tools.json`

## Reuse from CodeWalker

There is a good opportunity to reuse the existing CodeWalker admin and settings patterns instead of inventing a second totally different system.

Relevant existing pieces:

- [admin/codewalker.php](/web/html/admin/codewalker.php)
  - main admin app pattern
  - useful as a reference for admin flow and safe path handling
- [admin/codew_config.php](/web/html/admin/codew_config.php)
  - good model for a curated settings editor
- [admin/codew_prompts.php](/web/html/admin/codew_prompts.php)
  - already solves prompt template editing well
- [admin/lib/codewalker_settings.php](/web/html/admin/lib/codewalker_settings.php)
  - strongest reuse candidate
  - provides a default config template, SQLite-backed settings, and prompt-template storage
- [admin/lib/codewalker_helpers.php](/web/html/admin/lib/codewalker_helpers.php)
  - reusable helpers for escaping, CSRF, timestamps, and simple path checks

### What to reuse

- settings DB pattern
- prompt template storage pattern
- admin page structure
- protected admin auth flow
- safe absolute-path guardrails

### What not to reuse directly

- CodeWalker queue and rewrite-apply workflow
- scan-job oriented UI
- action history as the primary `/mc` runtime model

`/mc` is better thought of as:

- interactive shell feature
- browser + dispatcher
- optional admin-managed config

not as a scan queue app.

## Storage options

### Option A: reuse `codewalker_settings.db`

Use the existing DB at:

- `/web/private/db/codewalker_settings.db`

and store `/mc` settings under clearly prefixed keys such as:

- `mc_enabled`
- `mc_root`
- `mc_viewer_command`
- `mc_editor_command`
- `mc_actions`
- `mc_prompt_summarize_template`
- `mc_prompt_audit_template`
- `mc_prompt_refactor_template`

Pros:

- fastest to build
- reuses the existing admin/settings code almost directly
- fewer moving parts

Cons:

- mixes `/mc` concerns into CodeWalker storage
- can get messy over time if both systems keep growing

### Option B: create `mc_settings.db`

Create a sibling DB such as:

- `/web/private/db/mc_settings.db`

with the same design pattern as CodeWalker.

Pros:

- cleaner separation
- easier to reason about long term
- easier to export or reset independently

Cons:

- more setup work now
- duplicates some settings plumbing

## Recommended path

Use a hybrid approach:

- keep `/mc` runtime inside the Python shell
- reuse the CodeWalker settings architecture and prompt-template approach
- start by sharing `codewalker_settings.db` with `mc_`-prefixed keys
- if `/mc` grows into its own subsystem later, split it into `mc_settings.db`

That gives us the fastest path to a working browser without locking us into a bad long-term structure.

## Proposed `/mc` config schema

Suggested initial settings shape:

```json
{
  "mc_enabled": true,
  "mc_root": "/web/html",
  "mc_allow_private_paths": false,
  "mc_allowed_private_roots": [],
  "mc_viewer_command": "less {{file}}",
  "mc_editor_command": "nano {{file}}",
  "mc_inline_small_file_bytes": 32768,
  "mc_inline_medium_file_bytes": 131072,
  "mc_block_patterns": [
    ".env",
    ".env.*",
    "*.db",
    "*.sqlite",
    "*.sqlite3",
    "*.pem",
    "*.key",
    "*.p12",
    "*.log.gz"
  ],
  "mc_actions": [
    {
      "key": "F1",
      "label": "Summarize",
      "type": "prompt",
      "prompt_template": "Task: summarize_file\nFilename: {{file}}\nLanguage: {{language}}\n\nFile contents:\n{{contents}}"
    },
    {
      "key": "F3",
      "label": "View",
      "type": "command",
      "command": "less {{file}}"
    },
    {
      "key": "F4",
      "label": "Edit",
      "type": "command",
      "command": "nano {{file}}"
    }
  ],
  "mc_prompt_summarize_template": "mc_summarize",
  "mc_prompt_audit_template": "mc_audit",
  "mc_prompt_explain_template": "mc_explain",
  "mc_prompt_refactor_template": "mc_refactor"
}
```

## Admin UI idea

If we build a PHP admin page for `/mc`, it can follow the same general structure as CodeWalker:

- `/admin/admin_AI_MC.php` or `/admin/mc_config.php`
- settings editor at top
- action key map editor
- prompt-template editor or link to prompt templates
- optional preview/test area

Reasonable first admin controls:

- enable or disable `/mc`
- root path
- viewer and editor command
- file size thresholds
- block patterns
- action list
- prompt template bindings

## Snapshot idea

CodeWalker already uses a runtime snapshot idea with:

- `/web/private/codewalker.json`

`/mc` could follow the same approach:

- versioned reference in repo
- runtime snapshot in `/web/private/mc.json`

That would let Python load a simple JSON file quickly at startup while PHP remains the editor of record.

## Architecture recommendation

Best current fit:

1. PHP admin manages `/mc` settings and prompt templates.
2. PHP writes SQLite settings and a JSON snapshot.
3. Python shell loads the snapshot on startup.
4. `/mc` runs locally in the shell with those settings.

That preserves the strengths of both sides:

- PHP for admin management
- Python for interactive shell behavior

## Implementation plan

### Phase 1

- add `/mc` slash command
- start browser rooted at `agent.code_root`
- support file navigation
- support:
  - `F1` summarize
  - `F3` view
  - `F4` edit
  - `F10` exit

### Phase 2

- move key bindings into config
- add more AI actions
- add large-file handling
- add binary and secret filters
- add breadcrumbs and current path status

### Phase 3

- add directory actions
- add multi-select
- add diff and compare helpers
- optionally add admin page to manage `/mc` actions

## Open questions

- Should AI actions open results inline, in a pager, or back in the shell?
- Should edit use `nano`, `$EDITOR`, or a configurable command?
- Should summarize return plain text or JSON plus rendered text?
- Should `/mc` be read-only by default except for the explicit edit action?
- Should private memory or logs ever be visible in the browser, or stay excluded?

## Recommendation

Build the smallest good version first:

- `/mc`
- `F1` summarize
- `F3` view
- `F4` edit
- `F10` exit

If that feels good, then move the key map into config and expand from there.
