# Profiles Blueprint

`profiles/` is deprecated.

## Current Status

This directory is no longer the preferred home for future Root Defender templates.

The more useful idea turned out to be boot-prompt and operating-contract templates, not full JSON runtime profile objects.

## Replacement

Use:

- `boot-contracts/`

instead of adding new files here.

## Why

- the current runtime does not load profile JSON files
- the old examples pointed at stale paths and an older runner concept
- the real reusable asset is the boot contract that shapes AI behavior

## Migration Direction

- keep `agent_bash_boot.md` as the active default for now
- store reusable prompt variants in `boot-contracts/`
- avoid creating new profile example files here

## Intent

This directory should be considered retired in favor of `boot-contracts/`.
