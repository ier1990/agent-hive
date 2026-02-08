#!/bin/bash
set -euo pipefail

# Path: scripts/update_scripts.sh
# copied to /etc/cron.hourly/update_scripts
#
# This script creates wrapper scripts in the private directory that call
# the original scripts in the source directory.
# SRC is /web/html/src/scripts
# DST is /web/private/scripts (wrappers only)

SRC="/web/html/src/scripts"
DST="/web/private/scripts"
BOOTSTRAP_SRC="/web/html/lib/bootstrap.py"
BOOTSTRAP_DST_DIR="/web/private/lib"
BOOTSTRAP_DST="$BOOTSTRAP_DST_DIR/bootstrap.py"
OWNER="samekhi:www-data"

mkdir -p "$SRC" 
mkdir -p "$DST"

# Public-facing source trees should not have executable bits set.
# Keep dirs traversable (0755) but keep files non-executable (0644).
find "$SRC" -type d -exec chmod 0755 {} \;
find "$SRC" -type f -exec chmod 0644 {} \;

# Create wrappers for all script files in SRC
find "$SRC" -type f \
  ! -path "*/__pycache__/*" \
  ! -name "*.pyc" \
  ! -name ".DS_Store" \
  ! -name ".gitignore" | while read -r src_file; do
  
  # Get relative path from SRC
  rel_path="${src_file#$SRC/}"
  dst_file="$DST/$rel_path"
  dst_dir="$(dirname "$dst_file")"
  
  # Create destination directory if needed
  mkdir -p "$dst_dir"
  
  # Determine file type and create appropriate wrapper
  if [[ "$src_file" == *.py ]]; then
    # Python wrapper
    cat > "$dst_file" <<EOF
#!/usr/bin/env python3
import sys
import os

# Execute the original script
original_script = "$src_file"
with open(original_script, 'r') as f:
    code = compile(f.read(), original_script, 'exec')
    exec(code, {'__file__': original_script, '__name__': '__main__'})
EOF
  elif [[ "$src_file" == *.sh ]] || [[ "$src_file" == *.bash ]]; then
    # Bash wrapper
    cat > "$dst_file" <<EOF
#!/bin/bash
exec "$src_file" "\$@"
EOF
  else
    # Generic wrapper - try to execute directly
    cat > "$dst_file" <<EOF
#!/bin/bash
exec "$src_file" "\$@"
EOF
  fi
  
  chmod 0755 "$dst_file"
done

# Handle bootstrap.py
mkdir -p "$BOOTSTRAP_DST_DIR"
if [[ -f "$BOOTSTRAP_SRC" ]]; then
  install -m 0644 "$BOOTSTRAP_SRC" "$BOOTSTRAP_DST"
fi

# Set ownership
chown -R "$OWNER" "$DST" "$BOOTSTRAP_DST_DIR" 2>/dev/null || true

echo "Wrapper scripts created successfully (src → private wrappers)."