# Interpreter Harness Plan

## Purpose

This document defines the intended design for the AI Root Defender Interpreter.

The Interpreter exists to sit between AI-generated intent and real system
actions.

Its job is not to "lecture" the model about what is forbidden.

Its job is to enforce a narrow execution surface that only permits approved,
structured actions.

The core idea is simple: capability shaping is safer than teaching a model a
long list of destructive commands to avoid.

## Problem

Raw AI-to-shell execution is too sharp.

If an AI writes Bash directly, the system has to trust string output that may
contain:

- destructive deletion
- obfuscated shell behavior
- chained commands
- unintended side effects
- prompt-injected or malformed actions

Root Defender already treats direct shell access as risky. 

The Interpreter
should go one step further by making Python the primary execution harness for
controlled actions instead of treating raw Bash as the main runtime surface.

- AI may propose Python code or structured intent
- Interpreter validates it before execution
- approved scripts/helpers may be saved with metadata for reuse
- execution output streams back into the turn flow
- no raw Bash exposure


## Chosen Architecture

Primary execution model:

`AI -> Interpreter -> Python runtime -> controlled system actions`

Roles:

- AI
  - proposes intent, structured requests, or implementation code
- Interpreter
  - parses the request
  - classifies intent
  - validates requested capabilities against policy
  - blocks unsafe actions
  - routes safe actions into approved Python helpers
  - logs every decision
- Python runtime
  - executes only approved helper functions or tightly controlled actions
- System
  - receives only actions that passed interpreter policy

Raw Bash may still exist elsewhere in Root Defender as a lower-level tool
surface, but it is not the primary execution substrate for the Interpreter.

## Core Safety Rules

- Allowlist-first execution
  - the Interpreter should only permit known action classes
- No default deletion capability
  - deletion is not a normal tool the AI gets to use by default
- Structured refusal over conversational correction
  - unsafe requests should be rejected with boring, machine-readable output
- Full logging
  - every request, classification, decision, and action should be recorded
- Python-first enforcement
  - safe actions should execute through approved Python helpers, not ad hoc
    shell strings
- Temporary writes only in approved workspace paths
  - if the Interpreter allows temporary file output, it should write only to a
    bounded workspace such as `/tmp/ai-scripts/`
  - temporary write locations should be declared in settings, not improvised by
    generated code

## Temporary Write Workspace

The Interpreter may need a limited write surface for temporary outputs, review
artifacts, or localhost fetch results.

The current direction is:

- permit temporary writes only when enabled by settings
- restrict those writes to an approved workspace such as `/tmp/ai-scripts/`
- treat the workspace as non-executable scratch space
- keep the workspace outside normal application code paths
- make the location visible to the AI through module or runtime context rather
  than leaving it to guess

This is a bounded exception to the otherwise read-mostly execution posture. It
exists to support inspection and controlled intermediate artifacts, not general
filesystem write access.

## Allowed Action Classes

The Interpreter should be designed around narrow action classes such as:

- read-only inspection
  - read files from approved paths
  - inspect service state
  - inspect logs
  - gather telemetry
- controlled service actions
  - restart approved services
  - reload approved services
  - run bounded health checks
- safe file handling
  - archive files into approved archive paths
  - quarantine suspicious files into approved quarantine paths
  - write temporary review artifacts into approved scratch paths such as
    `/tmp/ai-scripts/`
- human-gated destructive actions
  - allow only after explicit approval and only within defined policy

Each action class should map to explicit Python helpers rather than open-ended
command generation.

## Destructive Action Policy

Deletion is not a default capability.

If the AI requests a destructive action, the Interpreter should not argue with
the request and should not try to "teach" the model what not to do. It should
translate or block based on policy:

- Archive
  - use when the requested operation is functionally "remove from normal use"
    but the data should remain recoverable
- Quarantine
  - use when the target is suspicious, unsafe, or should be isolated for review
- Human approval
  - require for irreversible destruction or any destructive action that cannot
    be safely transformed into archive or quarantine

Examples of requests that should not execute by default:

- recursive deletion
- filesystem wipes
- database-destructive operations
- destructive cleanup outside approved safe paths

## Structured Refusal Format

When the Interpreter blocks a request, it should return a boring, structured
refusal.

Example:

```json
{
  "ok": false,
  "action": "blocked",
  "reason": "harmful_deletion_detected",
  "message": "Rejected: this request attempts destructive deletion outside the approved safe paths.",
  "evidence": [
    "rm -rf /",
    "os.remove('/web')",
    "shutil.rmtree(...)"
  ],
  "allowed_next_steps": [
    "Use quarantine_file(path)",
    "Use archive_file(path)",
    "Request human approval for deletion"
  ]
}
```

Important behavior:

- no system action is executed
- the refusal is machine-readable
- the response points to safe next steps
- the refusal does not become a long safety speech

## Logging And Audit Requirements

Every Interpreter decision should be logged.

Minimum audit record:

- timestamp
- AI request or proposed action
- parsed action class
- policy decision
  - allowed
  - blocked
  - transformed
  - pending human approval
- evidence used in the decision
- Python helper invoked, if any
- result status
- operator approval record, if applicable

This log should make it possible to answer:

- what the AI asked for
- how the Interpreter understood it
- why it was blocked, transformed, or allowed
- what actually ran

## Future Extensions

Likely next steps for the Interpreter design:

- structured intent schemas instead of free-form code requests
- service-specific helper libraries for Apache, MySQL, PHP-FPM, and system
  diagnostics
- approval workflows for destructive actions
- archive and quarantine helper primitives
- policy profiles for different operating modes
  - read-only
  - service-operator
  - human-gated maintenance
- rollback or restore helpers for safe recovery paths

## Bottom Line

The Interpreter should function as a narrow, auditable gatekeeper between AI
intent and system action.

Its default posture is:

- allow known-safe actions
- block unsafe actions
- transform deletion into archive or quarantine when policy allows
- require human approval for irreversible destruction
- log everything
