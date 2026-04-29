# Boot Contracts Blueprint

`boot-contracts/` is the library of reusable system prompts and operating contracts for Root Defender.

## Current Role

This directory stores markdown boot prompts that define how the AI should behave at startup.

Each boot contract can specialize the agent for a different operating style, such as:

- general interactive shell diagnostics
- Apache and web log investigation
- worker-style summarization
- review or triage focused operation

## Relationship To The Live Boot Prompt

Today, the active default boot prompt is still:

- `agent_bash_boot.md`

That file remains the live default used by the current runtime.

This directory is the cleaner long-term home for alternate and curated boot contracts.

## Recommended Layout

- `default_bash_boot.md`
  - the general-purpose Root Defender shell contract
- `apache_bash_boot.md`
  - Apache and API diagnostics focused contract
- other future task-specific boot prompts as needed

## Rules

- Keep contracts in Markdown.
- Keep each contract focused on behavior, scope, and response format.
- Do not place secrets or host-specific values in these files.
- If a contract needs live environment detail, inject it at runtime instead of hardcoding it here.
- Prefer specific filenames that describe the task or operating mode.

## Future Direction

If runtime support is expanded later, `/compose --bootstrap` and related flows should be able to choose a boot contract from this directory explicitly.

At that point:

1. `agent_bash_boot.md` can become a compatibility wrapper or be retired.
2. the selected boot contract can become a real runtime setting
3. task-specific variants can work like a sysadmin Swiss army knife

## Intent

`boot-contracts/` should become the clean home for reusable AI operating contracts, while the current single-file boot prompt remains the active default until the loader catches up.
