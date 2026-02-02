#!/bin/bash
set -euo pipefail

# This script syncs the canonical Notes pipeline scripts into /web/private/scripts
# so root cron can call stable paths that survive deploys.
#
# Canonical source: /web/html/admin/notes/scripts
# Destination:      /web/private/scripts
# Also copies /web/html/lib/bootstrap.py into /web/private/scripts/lib/bootstrap.py

SRC="/web/html/admin/notes/scripts"
SRC_BOOTSTRAP="/web/html/lib/bootstrap.py"
DST="/web/private/scripts"
STAGE="/web/private/scripts.new"
OWNER="samekhi:www-data"

MANIFEST=(
  "notes_config.py"
  "save_bash_history_threaded.py"
  "ingest_bash_history_to_kb.py"
  "classify_bash_commands.py"
  "queue_bash_searches.py"
  "ai_search_summ.py"
  "ai_notes.py"
)

mkdir -p "$STAGE" "$DST" "$STAGE/lib"

for f in "${MANIFEST[@]}"; do
  if [[ ! -f "$SRC/$f" ]]; then
    echo "ERROR: missing source file: $SRC/$f" >&2
    exit 2
  fi
done

if [[ ! -f "$SRC_BOOTSTRAP" ]]; then
  echo "ERROR: missing bootstrap: $SRC_BOOTSTRAP" >&2
  exit 2
fi

# Stage files (explicit copy; avoids accidental extra scripts).
for f in "${MANIFEST[@]}"; do
  install -m 0644 "$SRC/$f" "$STAGE/$f"
done

install -m 0644 "$SRC_BOOTSTRAP" "$STAGE/lib/bootstrap.py"

# Make scripts runnable (cron uses python3 explicitly, but this helps manual runs too).
chmod 0755 "$STAGE"/*.py || true

# Ownership
chown -R "$OWNER" "$STAGE"

# Atomic swap
rm -rf "$DST.old" || true
if [[ -d "$DST" ]]; then
  mv "$DST" "$DST.old" || true
fi
mv "$STAGE" "$DST"
rm -rf "$DST.old" || true

echo "Scripts updated successfully: $DST"

