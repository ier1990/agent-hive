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
