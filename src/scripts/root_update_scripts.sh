#!/bin/bash
set -euo pipefail

# Path: scripts/root_update_scripts.sh
# copied to /etc/cron.hourly/root_update_scripts
# Run by root at install time and via cron_dispatcher.php (root_ prefix = root-only task)
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
    # Python wrapper (forwards CLI args via runpy)
    cat > "$dst_file" <<EOF
#!/usr/bin/env python3
import os
import runpy
import sys

# Execute the original script as __main__ (keeps sys.argv)
src_file = "$src_file"
src_dir = os.path.dirname(src_file)

# Ensure imports resolve to the source tree, not the wrapper directory.
wrapper_dir = os.path.dirname(__file__)
if wrapper_dir in sys.path:
  sys.path.remove(wrapper_dir)
if src_dir not in sys.path:
  sys.path.insert(0, src_dir)
sys.argv[0] = src_file
runpy.run_path(src_file, run_name="__main__")
EOF
  elif [[ "$src_file" == *.php ]]; then
    # PHP wrapper (require_once the source - keep PHP environment intact, accept args)
    cat > "$dst_file" <<EOF
<?php
\$src = "$src_file";
if (!is_file(\$src)) {
  fwrite(STDERR, "Missing source script: \$src\n");
  exit(2);
}
\$owner = posix_getpwuid(fileowner(\$src))['name'] . ':' . posix_getgrgid(filegroup(\$src))['name'];
\$perms = substr(sprintf('%o', fileperms(\$src)), -3);
if (\$owner !== 'samekhi:www-data') {
  fwrite(STDERR, "Bad owner for \$src: \$owner\n");
  exit(3);
}
// Reject if "others" has write bit set
if ((((int)\$perms % 10) & 2) !== 0) {
  fwrite(STDERR, "Refusing world-writable script: \$src perms=\$perms\n");
  exit(4);
}
// Set argv[0] to source script path, keep any CLI args
if (isset(\$GLOBALS['argv'])) {
  \$GLOBALS['argv'][0] = \$src;
  \$GLOBALS['argc'] = count(\$GLOBALS['argv']);
}
require_once \$src;
EOF
  elif [[ "$src_file" == *.sh ]] || [[ "$src_file" == *.bash ]]; then
    # Bash wrapper (with source validation + ownership/perms check)
    cat > "$dst_file" <<EOF
#!/bin/bash
set -euo pipefail
SRC="$src_file"
[[ -f "\$SRC" ]] || { echo "Missing source script: \$SRC" >&2; exit 2; }
owner="\$(stat -c '%U:%G' "\$SRC")"
perms="\$(stat -c '%a' "\$SRC")"
[[ "\$owner" == "samekhi:www-data" ]] || { echo "Bad owner for \$SRC: \$owner" >&2; exit 3; }
# Reject if "others" has write bit set
if (( (perms % 10) & 2 )); then
  echo "Refusing world-writable script: \$SRC perms=\$perms" >&2
  exit 4
fi
exec /bin/bash "\$SRC" "\$@"
EOF
  else
    # Generic/executable wrapper (with source validation + ownership/perms check)
    cat > "$dst_file" <<EOF
#!/bin/bash
set -euo pipefail
SRC="$src_file"
[[ -f "\$SRC" ]] || { echo "Missing source script: \$SRC" >&2; exit 2; }
owner="\$(stat -c '%U:%G' "\$SRC")"
perms="\$(stat -c '%a' "\$SRC")"
[[ "\$owner" == "samekhi:www-data" ]] || { echo "Bad owner for \$SRC: \$owner" >&2; exit 3; }
# Reject if "others" has write bit set
if (( (perms % 10) & 2 )); then
  echo "Refusing world-writable script: \$SRC perms=\$perms" >&2
  exit 4
fi
exec /bin/bash "\$SRC" "\$@"
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