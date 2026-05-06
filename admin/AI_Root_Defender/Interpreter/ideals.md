# Interpreter Ideals

## Purpose

This document captures the higher-level direction for the Interpreter after the
core harness in [plan.md](/web/html/admin/AI_Root_Defender/Interpreter/plan.md:1)
is in place.

`plan.md` explains how the Interpreter should work.

`ideals.md` explains what the Interpreter should grow into.

## Ideal End State

The long-term goal is not just a safer shell harness. The goal is a reusable
module system for AI behaviors inside the existing Root Defender and broader
agent environment.

The ideal shape is:

`AI system -> Interpreter core -> selected module -> approved tools/runtime`

In that model:

- the Interpreter core stays small and policy-focused
- modules provide specialized behavior
- prompts, tools, capabilities, and settings are loaded by module
- the same AI runtime can switch roles without rewriting the whole system

## Why Modules

The current system already has several reusable pieces:

- provider switching
- boot prompts
- tool policies
- telemetry and diagnostics
- memory and notes
- template-driven prompting

A module system would let those pieces be recombined cleanly instead of growing
as one giant agent personality.

This matters because different jobs want different behavior:

- Root Defender
  - safe diagnostics and controlled operations
- Code or admin assistant
  - repo and system help with different tool visibility
- Manufacturing or part research
  - domain-specific tools, searches, and templates
- Future narrow agents
  - one-purpose modules with minimal surface area

## Module Model

Each module should be a thin package of configuration and behavior layered on
top of the Interpreter core.

An ideal module would define:

- identity
  - module name
  - description
  - role or operating posture
- boot behavior
  - selected boot prompt or system prompt
  - optional prompt fragments
- tool surface
  - allowed tool groups
  - denied tool groups
  - action-class policy
- runtime settings
  - provider preferences
  - model preferences
  - timeout and budget limits
  - approval behavior
- memory behavior
  - what context stores are visible
  - whether notes, code search, or ops history are included
- output rules
  - structured response format
  - refusal behavior
  - logging expectations

The Interpreter core should load a module from JSON settings first, then apply
the stable policy engine beneath it.

## Recommended Architecture

The recommended shape is:

1. Interpreter Core
   - parses model output
   - enforces schemas
   - classifies intent
   - validates action classes
   - logs decisions
2. Planner Layer
   - turns user requests into bounded steps
   - decides when to use tools versus code helpers
3. Execution Layer
   - Python-first approved helper runtime
   - optional adapters for existing PHP, MCP, or local tools
4. Module Loader
   - loads selected module config
   - merges boot prompt, tool policy, runtime settings, and output rules
5. Memory Layer
   - exposes only the context a module is allowed to see

This keeps the Interpreter itself stable while modules stay easy to add or
replace.

## Settings-First Design

Modules should be easy to add mostly through settings, not hardcoded branches.

A good direction is a module definition file that can point to:

- boot prompt file
- template file or prompt fragment
- tool policy block
- provider defaults
- memory visibility flags
- approval and refusal policy

That means a future module can often be created by:

1. writing a boot prompt or template
2. defining a JSON settings block
3. registering the module name
4. reusing existing Interpreter policies and helpers

## Relationship To AI Templates

Twig-style or template-driven prompt systems should not compete with modules.
They should plug into them.

A useful mental model is:

- Interpreter core
  - the law and gatekeeper
- module
  - the operating role
- template
  - the phrasing or task frame used by that role

That means a module may select one or more templates, but the module remains
the higher-level unit of behavior and capability.

## Module Examples

Possible modules include:

- `root_defender`
  - diagnostics, telemetry, controlled service actions
- `code_assistant`
  - code search, repo understanding, implementation helpers
- `template_runner`
  - focused execution of reusable AI templates
- `manufacturing_research`
  - part lookup, vendor research, process notes, structured sourcing outputs

It is also acceptable if the Interpreter ends up with only one active module at
first. The point is to make the shape reusable before the module count grows.

## Naming Direction

The clearest naming is still the simplest:

- Interpreter
  - the policy and execution harness
- Module
  - the behavior package loaded into the Interpreter

Names like `Agent_Conductor` or `Agent_Fabric` can still be used as branding,
but the engineering structure should stay plain and obvious.

## Q&A Direction

When presenting this concept to humans, the clean Q&A should be:

- What is the Interpreter?
  - the gatekeeper between AI intent and real actions
- What is a module?
  - a reusable behavior package made of prompts, policies, tool access, and
    settings
- Why do this?
  - to reuse one stable AI system across specialized roles without giving every
    role the same power
- Why settings-first?
  - so new roles can be added quickly and safely
- Why Python-first?
  - because the Interpreter can inspect and constrain structured actions more
    safely than raw shell strings

## Bottom Line

The Interpreter should begin as a safe Python-first harness and evolve into a
module host.

If done well, Root Defender does not become one giant agent. It becomes:

- one stable Interpreter core
- many reusable modules
- selectable boot prompts and templates
- policy-shaped capability sets
- one AI system that can change roles without losing control
