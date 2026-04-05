# AgentHive Concept

AgentHive is moving toward a layered AI workbench, not a single giant always-on agent prompt.

The key idea is:

- keep the default agent lean
- unlock specialized capability packs on demand
- use small workers for narrow jobs
- store reports and drafts in the database
- require human approval for execution-sensitive changes

## Core design

The base agent should stay small enough for local and low-context models.

Default context should focus on:

- boot rules
- current built-in tools
- memory, notes, code search, and `read_code`
- a compact summary of available admin-managed tools
- the rule that richer capability packs can be requested when needed

The base agent should not carry large tool source blobs all the time.

## Capability packs

AgentHive fits well as a set of capability packs.

### Core pack

- memory
- notes search
- code search
- file reading
- web search
- approved tool execution

### Toolsmith pack

- tool metadata inspection
- tool source inspection
- tool drafting
- tool revision drafting
- test/example generation
- approval workflow awareness

### Planner pack

- stored plans
- plan review
- dependencies and handoffs
- approval checkpoints

### Expert pack

- escalation to a stronger model when the small model is not enough
- summarized context instead of raw prompt bloat

### Worker pack

- cron-safe narrow jobs
- monitoring
- log review
- anomaly summaries
- queued report generation

## Tool Work mode

Tool Work should be lazy-loaded, not permanent context baggage.

Base mode should know:

- tool counts
- high-level tool capabilities
- the status lifecycle
- that source is available on request

Tool Work mode should unlock:

- `/admin/admin_Agent_Tools.php` context
- tool schema summary
- tool lifecycle rules
- source inspection
- drafting and review conventions

That preserves small-model usefulness and keeps context efficient.

## Tool lifecycle contract

The tool contract now centers on a status model instead of a simple approval flag.

Tool statuses:

- `draft`
- `registered`
- `approved`
- `disabled`
- `deprecated`
- `rejected`
- `superseded`

Supporting fields:

- `approved_by`
- `approved_at`
- `review_notes`
- `replaces_tool_id`
- `source_type`
- `lineage_key`

Operational rule:

- only `approved` tools execute in normal runtime paths

Creation defaults:

- AI-created tools should default to `draft`
- human-created tools should default to `registered`
- imported tools should default to `registered` unless explicitly promoted by an admin workflow

This keeps the Toolsmith workflow reviewable and safe.

## Small workers

Small models should be workers, not full coding agents.

Good worker jobs:

- summarize recent Apache errors
- inspect recent auth failures
- classify log anomalies
- probe a URL and summarize changes
- read the newest report row and summarize risk
- monitor a queue or file and write back a report

These jobs need:

- a tiny prompt
- one clear objective
- strict output shape
- DB writeback

They do not need:

- large source dumps
- broad planning context
- tool-authoring context

## Supervisor pattern

A strong AgentHive structure is:

1. worker layer
2. reviewer layer
3. human approval layer

Worker layer:

- cheap, frequent, narrow tasks

Reviewer layer:

- reads worker reports
- groups patterns
- suggests actions
- drafts plans or tool changes

Human approval layer:

- approves plans
- approves tools
- approves risky changes

That is stronger than trying to make every worker “smart enough to do everything.”

## Cron-friendly direction

This concept maps well to cron.

Small cron workers can:

- read a narrow input
- produce a report
- write the report to DB
- exit cleanly

Then a later AgentHive review pass can decide whether to:

- ignore it
- escalate it
- draft a plan
- draft a tool
- ask a human

That is a better use of small models than making them full-time orchestration agents.

## Step budgets

Not every mode should use the same step budget.

Reasonable guidance:

- small worker jobs: `4-6` steps
- ordinary interactive help: `6` steps
- Tool Work / planning / review flows: `8-12` steps

The answer is not “raise everything.”
The answer is “different modes get different budgets.”

## Product identity

The cleanest plain-English description is:

AgentHive is a distributed AI workbench where small agents do narrow jobs, store results, and a supervisor agent drafts next steps for human approval.

That is the core concept worth preserving while the implementation evolves.
