#!/usr/bin/env bash
set -euo pipefail

REPO="ier1990/agent-hive"
REL_DIR="/web/private/releases/html"
TMP_DIR="/web/private/releases/tmp"
KEEP_LAST=10
MAX_AGE_DAYS=90

usage() {
  cat <<EOF
Usage:
  $0 [--sha <commit_sha>] [--channel main]

Behavior:
  - Downloads pinned tarball from GitHub: https://codeload.github.com/<repo>/tar.gz/<sha>
  - Stores it in ${REL_DIR}
  - Computes sha256
  - Writes ${REL_DIR}/latest.json
  - Retention: keep last ${KEEP_LAST} artifacts, delete others older than ${MAX_AGE_DAYS} days

Examples:
  $0 --sha 0123abcd...         # pin to specific commit
  $0                           # auto-detect latest main commit SHA (public GitHub API)
EOF
}

CHANNEL="main"
SHA=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --sha) SHA="${2:-}"; shift 2;;
    --channel) CHANNEL="${2:-main}"; shift 2;;
    -h|--help) usage; exit 0;;
    *) echo "Unknown arg: $1"; usage; exit 2;;
  esac
done

mkdir -p "$REL_DIR" "$TMP_DIR"

# Auto-detect SHA if not provided
if [[ -z "$SHA" ]]; then
  # Public GitHub API call (rate-limited but fine for weekly)
  SHA="$(curl -fsSL "https://api.github.com/repos/${REPO}/commits/${CHANNEL}" \
    | awk -F'"' '/"sha":/ {print $4; exit}')"
fi

if [[ -z "$SHA" || "${#SHA}" -lt 12 ]]; then
  echo "ERROR: invalid SHA: '$SHA'"
  exit 1
fi

SHA12="${SHA:0:12}"
VERSION="$(date -u +%F)_${SHA12}"
FNAME="agent-hive-${VERSION}.tar.gz"
TARBALL="${REL_DIR}/${FNAME}"
SUMFILE="${TARBALL}.sha256"
LATEST_JSON="${REL_DIR}/latest.json"

# If already cached, just refresh latest.json (idempotent)
if [[ ! -f "$TARBALL" ]]; then
  echo "Downloading pinned tarball for ${REPO}@${SHA12}..."
  curl -fL "https://codeload.github.com/${REPO}/tar.gz/${SHA}" -o "${TMP_DIR}/${FNAME}"
  mv "${TMP_DIR}/${FNAME}" "$TARBALL"
else
  echo "Tarball already exists: ${FNAME}"
fi

# Compute sha256
sha256sum "$TARBALL" | tee "$SUMFILE" >/dev/null
SHA256="$(awk '{print $1}' "$SUMFILE")"

# Write latest.json
cat > "$LATEST_JSON" <<EOF
{
  "schema": "release_latest",
  "repo": "${REPO}",
  "channel": "${CHANNEL}",
  "version": "${VERSION}",
  "commit": "${SHA}",
  "file": "${FNAME}",
  "sha256": "${SHA256}",
  "created_at_utc": "$(date -u +%FT%TZ)"
}
EOF

echo "Wrote ${LATEST_JSON}"
echo "Latest: ${VERSION} (${SHA12})"

# -----------------------
# Retention policy
# - Keep last N artifacts regardless of age
# - Remove others older than MAX_AGE_DAYS
# -----------------------
echo "Applying retention: keep last ${KEEP_LAST}, delete older than ${MAX_AGE_DAYS} days (except kept)..."

# List artifacts newest-first by mtime
mapfile -t artifacts < <(ls -1t "${REL_DIR}"/agent-hive-*.tar.gz 2>/dev/null || true)

# Build a "keep set" of newest KEEP_LAST files
declare -A keep
for ((i=0; i<${#artifacts[@]} && i<KEEP_LAST; i++)); do
  keep["${artifacts[$i]}"]=1
done

# Delete artifacts older than MAX_AGE_DAYS if not in keep-set
now_epoch="$(date +%s)"
max_age_seconds="$((MAX_AGE_DAYS*24*60*60))"

for f in "${artifacts[@]}"; do
  [[ -f "$f" ]] || continue
  if [[ -n "${keep[$f]:-}" ]]; then
    continue
  fi
  mtime="$(stat -c %Y "$f" 2>/dev/null || echo 0)"
  age="$((now_epoch - mtime))"
  if (( age > max_age_seconds )); then
    echo "Deleting old artifact: $(basename "$f")"
    rm -f "$f" "${f}.sha256" 2>/dev/null || true
  fi
done

echo "Done."