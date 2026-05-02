# Bash Contracts Blueprint

## Purpose

This directory contains trusted, pre-approved Bash contracts for AI Root Defender.

These are not arbitrary scripts. They are:

- controlled execution surfaces
- auditable entry points
- safe wrappers around system capabilities

Think of this directory as the AI-safe shell API layer.

## Contract Rules

Every contract in this directory should be:

- deterministic
- non-interactive
- scoped to a narrow task
- safe by default
- readable by both humans and AI
- fast enough for repeated execution
- cron-safe when practical

Allowed categories include:

- telemetry
- monitoring and health checks
- controlled system queries
- safe automation helpers

## Contract Inputs

Contracts may be config-driven rather than fully hardcoded.

Bounded inputs may come from:

- `/web/html/admin/AI_Root_Defender/config/tools.default.json`
- `/web/private/agent_tools.json`

Good examples of config-driven inputs:

- threshold trip values
- alert enable or disable switches
- reporting depth flags
- service-specific diagnostic toggles

Config-driven behavior is encouraged when it keeps the contract predictable and easier to operate without editing code.

## Contract Outputs

Outputs should be stable and easy to parse.

Preferred output traits:

- labeled sections such as `[TELEMETRY_LOAD]`
- concise summaries before noisy details
- explicit alert lines such as `[ALERT] ...`
- no interactive prompts
- no ambiguous control flow hidden in output formatting

If a contract becomes hard to scan, it is too open-ended for this directory.

## Alias Metadata

Each contract may define simple AI-facing metadata near the top of the file.

Example:

```sh
# @alias: telemetry
# @desc: returns cpu, load, memory, and alert state
```

Aliases should be:

- short
- unique
- descriptive

The agent should prefer aliases over raw file paths when possible.

## Telemetry Contracts

Telemetry contracts are a first-class pattern in this directory.

They should usually separate:

- the decision to run
- the decision to alert
- the threshold values
- the deeper diagnostic modes

Example config model:

- `telemetry`
  - master on or off switch
- `telemetry_alerts`
  - controls whether threshold breaches emit `[ALERT]` lines
- `telemetry_config.*`
  - numeric trip values such as load ratio, memory percent, swap percent, and disk percent
- `telemetry_diagnostics.*`
  - optional deeper visibility such as failed services, MySQL process presence, inode pressure, or OOM signals

This lets the AI feel host pressure through bounded reporting rather than unrestricted shell access.

## Diagnostic Modes

Deeper diagnostic modes are allowed when they remain narrow and safe.

Good diagnostic modes are:

- bounded
- read-only
- useful under system stress
- quick enough to run repeatedly
- explicit about what they expose

Examples:

- quiet
  - report telemetry but suppress alerts
- alerts
  - report telemetry and emit threshold alerts
- deep
  - include extra host signals such as failed services, OOM events, inode pressure, or service-specific process visibility
- service-specific
  - expose narrow signals for Apache, MySQL, PHP-FPM, Redis, or similar components

## Pre-Approved Tool Surface

Scripts in this directory are considered trusted inputs and may be:

- injected into prompts
- chained after tool calls
- exposed as callable tools
- run without additional approval if policy allows

That trust level means the scripts must stay boring, explicit, and reviewable.

## Calling Other Languages

Contracts may call local Python or PHP helpers if all of the following are true:

- the called script is local and controlled
- the path is explicit
- input and output are sanitized
- execution stays non-interactive

Allowed examples:

- `php /web/AI/bin/safe_query.php`
- `python3 /web/AI/bin/check_state.py`

Not allowed:

- calling random scripts
- dynamic execution such as `eval`
- remote download and execute patterns

## File Rules

Primary contract files should be:

- `*.sh`

Supporting helpers may live elsewhere, but the contract entrypoint in this directory should remain a shell script.

## Execution Context

Contracts should:

- run as a non-root, restricted user whenever possible
- avoid requiring elevated privileges
- document any privileged dependency clearly and isolate it

## Disallowed Patterns

Contracts in this directory should not contain:

- interactive prompts
- infinite loops
- background daemons
- uncontrolled network calls
- destructive operations without explicit safeguards
- open-ended execution paths that behave like a general shell

## Design Principle

Contracts should reduce uncertainty, not increase power.

Good contracts behave like a narrow shell API:

- stable outputs
- explicit config knobs
- no hidden privilege escalation
- no broad execution surface
- enough host feedback for the AI to reason
- not enough power for the AI to roam

## Future Extensions

Possible future additions:

- `@output: json` metadata
- `@alerts: true` capability flags
- auto-registration into the tools database
- named diagnostic profiles
- service-specific bundles of diagnostic flags

## Bottom Line

This directory is where shell access becomes policy-shaped, auditable, and intentionally small.
