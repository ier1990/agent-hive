## AI Header

This folder is the start of an “AI Header” app.

**AI Header** is the neutral name for the whole instruction envelope you send to an LLM: system + persona + prompt + RAG/context + controls + execution metadata.

The goal is to end the endless “system vs prompt vs persona” confusion by making the envelope explicit, versioned, and repeatable.

---

## Terminology

- **AI Header**: the complete instruction envelope for one run.
- **AI Header Template** (`.tpl`): the editable source that compiles into an AI Header.
- **Compiled Header**: the resolved output after template variables are substituted.
- **Pre-processing**: optional steps that generate/inject context (RAG pulls, file excerpts, memory snippets) before dispatch.
- **Dispatcher**: the execution adapter (Ollama/OpenAI/etc) that turns a compiled header into an API call.

---

## What this app manages

The app is a template editor + registry:

- Create / edit / delete templates (`.tpl` with `{{ variables }}`)
- Store authoritative records in SQLite
- Write portable JSON backups
- Produce a “compiled header” payload suitable for dispatch to a model

Some templates can be sent directly to a model; others require pre-processing (for example, populating `{{ attachments }}` from a RAG query).





---

## AI Header model (v1)

The header is organized into layers so each piece has a clear job.

### 1) Identity layer (who is speaking)

- `system`
- `persona`

### 2) Intent layer (why this run exists)

- `objective`
- `task_type` (e.g. `classify`, `generate`, `extract`, `repair`, `summarize`)
- `success_criteria`
- `failure_modes`

### 3) Context layer (what the AI knows)

- `pre_prompt`
- `attachments` (RAG output, file excerpts, DB pulls)
- `memory_refs`

### 4) Instruction layer (what to do now)

- `prompt`

### 5) Control layer (how the model behaves)

- `constraints` (tone, verbosity, allowed assumptions, forbidden topics)
- `format` (output type + optional schema/examples)

### 6) Execution layer (how it runs)

- `engine` (provider/model + sampling settings)

### 7) Safety & guardrails

- `safety` (tools/network/file-access toggles + redaction rules)

### 8) Provenance & versioning

- `meta` (header/template IDs, version, timestamps, checksum)

---

## Template format (`.tpl`)

Templates use a simple `{{ variable }}` substitution syntax.

AI Headers
Tell your router:
- which model to use
- which API key
- which session
- which client
- any routing metadata
AI Payload
Tells the model:
- who it is
- who the user is
- what the context is
- what the task is
This separation gives you:
- modularity
- reusable templates
- clean debugging
- model‑agnostic routing
- future‑proofing for tools, memory, agents
Exactly the kind of operational hygiene you like.





Example template:

```yaml

```

Notes:

- The template itself is just a text document; it can be YAML-ish or JSON-ish as long as compilation is deterministic.
- Variables should be treated as plain text substitutions (no code execution).

---

## Lifecycle

1) **Editor**: user edits an AI Header Template (`.tpl`)
2) **Compiler**: resolves `{{ variables }}` into a **Compiled Header**
3) **Pre-processor (optional)**: populates variables like `attachments` via RAG, file excerpts, memory injection
4) **Dispatcher**: sends the compiled header to a model backend (Ollama, OpenAI, etc)
5) **Logger (optional but recommended)**: store inputs/outputs + header checksum for replay/debug

---

## Storage + backup rules

The intent is:

- **SQLite is authoritative** (templates + metadata live there)
- **JSON is portable backup** (easy to diff, copy, restore)

Recommended to store both:

- `template.tpl` (source)
- `compiled_header.json` (last compiled output, or per-run compiled outputs)

Benefits:

- replay/debugging
- auditing (what exactly was sent)
- regression testing

---

## Rules (v1) to enforce in the app

These are the “boring” constraints that make this usable at scale.

### Naming + identity

- Templates have a stable `template_id` (UUID or monotonic integer) and a human `name`.
- Templates are versioned (increment on edit); old versions remain retrievable.

### Types

Templates have a `type` used for grouping and dispatch:

- `header`: routing/execution metadata (provider/model/session/client/etc)
- `payload`: the model instruction envelope (system/persona/context/prompt/constraints)
- `text`: plain text templating (usable as a normal template compiler for webpages or other text)


### Compilation

- Compilation is deterministic: same template + same inputs ⇒ same compiled header.
- Missing variables are ignored by design: if `{{ var }}` is unbound, it renders as an empty string.
- Bindings inputs must be associative arrays (key/value). Non-associative arrays, strings, and nulls are ignored (or logged).
- The compiled header stores a checksum (e.g. SHA-256) of the compiled content.

### Safety / scope


### Separation of concerns

- `system/persona` define identity and rules.
- `prompt` is per-run instruction, kept short and specific.
- RAG output goes in `attachments` (not mixed into `prompt` by default).

### Portability

- A compiled header is backend-neutral; engine/provider settings are metadata, not “baked into” the prompt.
- The same template should be runnable on different providers with minimal input changes.



## Why this matters

AI Header is infrastructure:

- repeatable runs
- easier debugging (“what did we send?”)
- backend swaps without rewriting everything
- clean separation between identity, context, instructions, and controls