#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PRIVATE_ROOT="${APP_PRIVATE_ROOT:-/web/private}"

cd "$ROOT_DIR"

mkdir -p \
  "$ROOT_DIR/bh/events" \
  "$ROOT_DIR/config" \
  "$ROOT_DIR/data" \
  "$ROOT_DIR/logs" \
  "$ROOT_DIR/memory" \
  "$ROOT_DIR/notes"

echo "Using repo defaults:"
echo "  - config/tools.default.json"
echo "  - config/settings.default.json"
echo "Runtime private root:"
echo "  - $PRIVATE_ROOT"

if [[ -f "$PRIVATE_ROOT/agent_tools.json" ]]; then
  echo "Detected external AgentHive tools config: $PRIVATE_ROOT/agent_tools.json"
  echo "Private tools config will override repo defaults."
else
  echo "No external AgentHive config found; repo defaults will be used."
fi

if [[ -f "$PRIVATE_ROOT/agent_settings.json" ]]; then
  echo "Detected external AgentHive settings config: $PRIVATE_ROOT/agent_settings.json"
  echo "Private settings config will override repo defaults."
else
  echo "No external AgentHive settings config found; repo default provider settings will be used."
fi

echo "Install/bootstrap complete."
