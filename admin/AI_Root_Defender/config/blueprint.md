# Config Blueprint

This directory uses a two-layer config model:

1. Public repo defaults
- `settings.default.json`
- `tools.default.json`

2. Private live overrides
- `/web/private/agent_settings.json`
- `/web/private/agent_tools.json`

## Rules

- Repo-tracked `*.default.json` files are the public baseline.
- Private `/web/private/*.json` files override repo defaults at runtime.
- Do not rely on `config/settings.json` or `config/tools.json` going forward.
- Secrets, API keys, internal-only endpoints, and host-specific values belong in `/web/private`.
- Safe examples, policy defaults, allowlists, prompt-context defaults, and fallback provider examples belong in `*.default.json`.

## Load Order

1. Hardcoded Python fallback defaults
2. `config/*.default.json`
3. `/web/private/agent_*.json`

Later layers override earlier layers.

## File Responsibilities

### `settings.default.json`
- Provider list examples
- Active provider default
- Model/base URL defaults
- Other agent-wide runtime settings
- Shell defaults such as max turns and debug default
- Turn generator limits such as conversation history window
- Turn usage tracking defaults for prompt and response size estimates
- Task queue defaults for cron or file-drop execution
- Editor selection preferences
- Compose behavior defaults

This is the public app baseline.

### `tools.default.json`
- Bash tool policy
- Allowed/disallowed commands
- Blocked tokens
- Prompt-context defaults
- Memory/search feature toggles
- Telemetry defaults such as `telemetry` and `telemetry_alerts`
- Telemetry thresholds under `telemetry_config`
- Optional deeper diagnostic reporting flags under `telemetry_diagnostics`

This is the public tool and safety policy baseline.

## Intent

- Public, understandable defaults in git
- Private live values outside git
- Fewer hidden hardcoded settings in Python
- One clear place to learn how config works
