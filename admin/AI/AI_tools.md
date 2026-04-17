# AI Tools Contract

This file describes the intended Toolsmith contract for AgentHive.

The short version:

- AI may draft tools.
- AI may inspect tool source when Tool Work mode is active.
- AI may never self-approve tools.
- Only tools with `status = approved` are executable by the normal agent runtime.

## Current reality

The current system already supports:

- DB-backed tool storage in `/web/private/db/agent_tools.db`
- tool execution for approved tools
- tool run auditing in `tool_runs`
- admin editing through `/admin/db_loader.php`
- Python agent runtime discovery and execution through `agent_tool_list` and `agent_tool_run`

The newer contract replaces the old binary approval mindset with a status lifecycle.

## Tool lifecycle

Tools now move through these statuses:

- `draft`
- `registered`
- `approved`
- `disabled`
- `deprecated`
- `rejected`
- `superseded`

Status meaning:

- `draft`: early AI- or human-created work that is not ready for general use
- `registered`: a stored candidate that is known to the system but still not executable
- `approved`: executable by normal agent and API runtime paths
- `disabled`: intentionally blocked from execution without deleting history
- `deprecated`: still present for reference, but should not be chosen for new work
- `rejected`: reviewed and declined
- `superseded`: replaced by a newer tool version or lineage successor

Only `approved` tools are executable.

## Supporting fields

The `tools` table should carry these workflow fields:

- `status`
- `approved_by`
- `approved_at`
- `review_notes`
- `replaces_tool_id`
- `source_type`
- `lineage_key`

Supporting field meaning:

- `approved_by`: who approved the tool for execution
- `approved_at`: when approval happened
- `review_notes`: approval, rejection, or review rationale
- `replaces_tool_id`: optional backward link to the tool being replaced
- `source_type`: one of `human`, `ai`, or `imported`
- `lineage_key`: optional grouping key for drafts, revisions, and successor tools

## Default creation rules

New tools must not default to approved.

Recommended defaults:

- new AI-created tools: `draft`
- new human-created tools: `registered`
- imported tools: `registered` unless an explicit admin import policy promotes them

Compatibility note:

- legacy `is_approved` may still exist for migration compatibility
- runtime approval should be derived from `status = approved`
- `is_approved` should be synchronized from `status`, not treated as the source of truth

## Tool Work mode

Tool source and tool-authoring workflow should be considered a capability pack, not always-on prompt baggage.

Base mode should know:

- tool counts
- high-level capability summary
- lifecycle rules
- that source is available on request

Tool Work mode should unlock:

- tool metadata inspection
- tool source inspection
- tool draft creation
- tool draft revision
- test/example drafting
- review note generation

That keeps smaller models useful and avoids wasting context on tool internals when they are not needed.

## Toolsmith built-ins

If Tool Work becomes a first-class runtime mode, the built-in tool ideas should look more like this:

- `tool_list`
- `tool_read_source`
- `tool_read_meta`
- `tool_propose_write`
- `tool_propose_revision`
- `tool_test_preview`

Suggested responsibilities:

- `tool_list`: list tool records with status, source type, lineage, and summary metadata
- `tool_read_source`: inspect the actual code of a tool draft or executable tool
- `tool_read_meta`: inspect workflow fields, run stats, and review information
- `tool_propose_write`: create a new tool draft
- `tool_propose_revision`: create a successor draft linked to an existing tool
- `tool_test_preview`: generate examples or test vectors for review

## Bash proposal tool

One especially useful built-in is a guarded bash proposal tool.

The idea:

- agent does not execute shell directly
- agent proposes a bash command string
- system runs a deterministic safety pass over it
- system produces two summaries:
  - `operator_summary`
  - `tutorial_summary`
- human chooses `approve` or `cancel`
- only then does a separate executor run the command

That keeps shell access useful without making the runtime reckless.

Current contract:

- `bash_propose`
- `bash_proposal_list`
- `bash_proposal_status`

Current responsibilities:

- `bash_propose`: create a reviewable proposal row with summaries, risk, cwd, and metadata
- `bash_proposal_list`: list recent proposals so the agent can discover IDs and statuses
- `bash_proposal_status`: inspect one proposal in detail after approval or execution

Current human review surface:

- `/admin/admin_AI_Bash.php`

Current admin actions:

- `approve`
- `cancel`
- `delete`
- `execute`

Important implementation note:

- the agent currently proposes and inspects bash commands
- the human approves and executes them through the admin UI
- execution is intentionally not exposed as a direct built-in tool yet

## Bash proposal shape

Recommended proposal payload:

```json
{
  "tool": "bash",
  "command": "rg -n \"api_guard\" /web/html",
  "cwd": "/web/html",
  "operator_summary": "Search recursively for api_guard usages under /web/html with line numbers.",
  "tutorial_summary": {
    "purpose": "Find where api_guard is used in the codebase.",
    "tokens": [
      {"part": "rg", "meaning": "ripgrep, a fast recursive search tool"},
      {"part": "-n", "meaning": "show matching line numbers"},
      {"part": "\"api_guard\"", "meaning": "the text pattern to search for"},
      {"part": "/web/html", "meaning": "directory to search"}
    ],
    "why_this_command": "Faster and cleaner than grep -R for code search.",
    "expected_output": "Matching file paths with line numbers.",
    "safer_alternative": "Search a narrower directory if you want less output."
  },
  "risk": "low",
  "needs_approval": true,
  "writes": [],
  "reads": ["/web/html"],
  "network": false,
  "sudo": false
}
```

This is useful both as approval UI data and as a growing bash tutorial library.

Live implementation notes:

- proposals are stored in `bash_proposals` inside `/web/private/db/agent_tools.db`
- each proposal carries operator summary, tutorial summary, risk, and metadata JSON
- execution stores exit code, stdout preview, stderr preview, and result JSON back onto the same row

## Bash approval workflow

Recommended flow:

1. agent decides shell access would help
2. agent proposes one command, not a whole hidden script
3. deterministic guard parses and classifies the command
4. UI shows:
   - exact command
   - operator summary
   - tutorial summary
   - risk level
   - likely reads/writes/network use
5. human selects `approve`, `cancel`, or `delete`
6. if approved, human may later choose `execute`
7. result is stored and can be read back through `bash_proposal_status`

This should feel like a reviewable action card, not an invisible side effect.

Practical agent loop:

1. agent uses `bash_propose`
2. human reviews proposal in `/admin/admin_AI_Bash.php`
3. if the human approves and executes it, the agent can later call:
   - `bash_proposal_list`
   - `bash_proposal_status`
4. agent then interprets the result

## Bash tutorial value

The tutorial side is not just nice-to-have.

It helps users learn recurring command patterns from real work:

- `rg -n`
- `find`
- `sed -n`
- `tail -f`
- `wc -l`
- `sort`
- `uniq -c`
- `xargs`

That means the bash tool is doing two jobs:

- safe human-approved execution
- practical shell teaching in context

Recommended summary split:

- `operator_summary`: one or two short sentences about what the command will do
- `tutorial_summary`: a token-by-token explanation with expected output and rationale

The UI can show operator summary by default and keep tutorial details collapsible.

## Bash safety boundary

The summary generator must not be the safety boundary.

Safety should come from deterministic checks first.

Examples of things to classify explicitly:

- `rm`
- `mv`
- `chmod`
- `chown`
- `sudo`
- shell redirection like `>` and `>>`
- command chaining with `;`, `&&`, `||`
- pipes
- wildcard-heavy writes
- network fetches
- writes outside allowed roots

AI can explain a command.
AI should not be the only component deciding whether it is safe.

## Bash v1 limits

Current v1 limits:

- require approval for every command
- proposals are created by the agent, but execution is human-triggered
- restrict working directories to configured allowed roots
- log the exact proposed command and exact execution result
- do not allow execution of a different command from the one approved
- keep deterministic risk classification ahead of any AI explanation

Possible allowed early patterns:

- search
- listing
- file inspection
- line counting
- process inspection
- simple git read-only inspection

Then later, if the workflow earns trust, expand carefully into write-capable operations or direct post-approval execution hooks.

## Safety rules

- AI drafts tools for human approval.
- AI must not silently overwrite an approved tool.
- AI should prefer drafting a successor or replacement candidate over mutating a live approved tool in place.
- AI should include purpose, parameters, expected outputs, and safety notes when proposing a tool.
- AI should inspect related tools before drafting a near-duplicate.
- AI should not assume imported or registered tools are safe to run.

## Review workflow

Recommended review flow:

1. agent detects a missing or weak capability
2. agent inspects existing related tools
3. agent drafts a new tool or revision
4. system stores it as `draft` or `registered`
5. human reviews code, notes, and examples
6. human sets status to `approved`, `rejected`, `disabled`, or `superseded`

That framing keeps the system powerful without turning it reckless.

## Runtime contract

Normal execution paths should behave like this:

- list only `approved` tools for default runtime execution
- refuse `draft`, `registered`, `disabled`, `deprecated`, `rejected`, and `superseded` tools
- keep run logging and audit history even when tools later become disabled or superseded

The point is simple:

AgentHive should be able to evolve its capabilities, but only through a reviewable workflow.
