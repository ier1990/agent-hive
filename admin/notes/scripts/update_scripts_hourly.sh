#!/bin/bash
set -euo pipefail

# Path: scripts/update_scripts.sh
# copied to /etc/cron.hourly/update_scripts
#
# This script updates the private scripts directory from the source scripts.
# It uses rsync to copy files, excludes unnecessary files, and performs an
# atomic swap to minimize downtime.
# PRIVATE_ROOT is assumed to be /web/private
# SRC1 is /web/html/admin/notes/scripts
# SRC2 is /web/html/src/scripts
# DST is /web/private/scripts

SRC1="/web/html/admin/notes/scripts"
SRC2="/web/html/src/scripts"
DST="/web/private/scripts"
BOOTSTRAP_SRC="/web/html/lib/bootstrap.py"
BOOTSTRAP_DST_DIR="/web/private/lib"
BOOTSTRAP_DST="$BOOTSTRAP_DST_DIR/bootstrap.py"
OWNER="samekhi:www-data"

mkdir -p "$SRC1"
mkdir -p "$SRC2" 
mkdir -p "$DST"

# Sync from v1 → src (do NOT delete from SRC2; it may contain additional scripts tracked in git)
rsync -av --checksum \
  --exclude '__pycache__/' \
  --exclude '*.pyc' \
  --exclude '.DS_Store' \
  --exclude '.git/' \
  --exclude '.gitignore' \
  "$SRC1/" "$SRC2/"

# Public-facing source trees should not have executable bits set.
# Keep dirs traversable (0755) but keep files non-executable (0644).
find "$SRC1" -type d -exec chmod 0755 {} \;
find "$SRC2" -type d -exec chmod 0755 {} \;
find "$SRC1" -type f -exec chmod 0644 {} \;
find "$SRC2" -type f -exec chmod 0644 {} \;



# Sync to runtime scripts (do NOT delete from DST; other software may rely on extra files there)
rsync -av --checksum \
  --exclude '__pycache__/' \
  --exclude '*.pyc' \
  --exclude '.DS_Store' \
  --exclude '.git/' \
  --exclude '.gitignore' \
  "$SRC2/" "$DST/"

mkdir -p "$BOOTSTRAP_DST_DIR"
if [[ -f "$BOOTSTRAP_SRC" ]]; then
  install -m 0644 "$BOOTSTRAP_SRC" "$BOOTSTRAP_DST"
fi

# Ownership + runtime permissions (do NOT chmod sources).
chown -R "$OWNER" "$DST" "$BOOTSTRAP_DST_DIR" 2>/dev/null || true

# Per ops requirement: everything we sync into /web/private/scripts is executable.
# Skip runtime caches that may be root-owned.
find "$DST" -type f \
  ! -path "*/__pycache__/*" \
  ! -name "*.pyc" \
  -exec chmod 0755 {} \;

echo "Scripts updated successfully (v1 → src → private)."
