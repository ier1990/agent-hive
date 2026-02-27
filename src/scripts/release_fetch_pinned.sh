#!/usr/bin/env bash
set -euo pipefail

# Fetch a pinned GitHub commit tarball and convert it to local release format
# expected by admin/admin_Update.php:
#   /web/private/releases/<app>/<app>-<host>-<timestamp>-<sha12>.tar.gz
#   /web/private/releases/<app>/<filename>.sha256
#   /web/private/releases/<app>/latest.json
#
# Usage:
#   ./release_fetch_pinned.sh --sha <commit_sha>
#   ./release_fetch_pinned.sh --sha <commit_sha> --app html --repo ier1990/agent-hive

APP="html"
REPO="ier1990/agent-hive"
SHA=""
RELEASE_ROOT="/web/private/releases"
KEEP_MIN=10
MAX_AGE_DAYS=90

usage() {
  cat <<'EOF'
Usage:
  release_fetch_pinned.sh --sha <commit_sha> [options]

Required:
  --sha <commit_sha>          Commit SHA to pin (7-40 hex chars)

Options:
  --app <name>                App key for release directory (default: html)
  --repo <owner/repo>         GitHub repo path (default: ier1990/agent-hive)
  --release-root <path>       Root release dir (default: /web/private/releases)
  --keep-min <n>              Keep at least N newest tarballs (default: 10)
  --max-age-days <n>          Delete older tarballs beyond N days (default: 90)
  -h, --help                  Show this help

Example:
  ./release_fetch_pinned.sh --sha 38d53d7 --repo ier1990/agent-hive
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --sha) SHA="${2:-}"; shift 2 ;;
    --app) APP="${2:-}"; shift 2 ;;
    --repo) REPO="${2:-}"; shift 2 ;;
    --release-root) RELEASE_ROOT="${2:-}"; shift 2 ;;
    --keep-min) KEEP_MIN="${2:-}"; shift 2 ;;
    --max-age-days) MAX_AGE_DAYS="${2:-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *)
      echo "Unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ -z "$SHA" ]]; then
  echo "ERROR: --sha is required." >&2
  usage >&2
  exit 2
fi

if ! [[ "$SHA" =~ ^[A-Fa-f0-9]{7,40}$ ]]; then
  echo "ERROR: --sha must be 7-40 hex chars." >&2
  exit 2
fi

if ! [[ "$KEEP_MIN" =~ ^[0-9]+$ ]] || ! [[ "$MAX_AGE_DAYS" =~ ^[0-9]+$ ]]; then
  echo "ERROR: --keep-min and --max-age-days must be integers." >&2
  exit 2
fi

if ! command -v curl >/dev/null 2>&1; then
  echo "ERROR: curl is required." >&2
  exit 2
fi
if ! command -v tar >/dev/null 2>&1; then
  echo "ERROR: tar is required." >&2
  exit 2
fi
if ! command -v sha256sum >/dev/null 2>&1; then
  echo "ERROR: sha256sum is required." >&2
  exit 2
fi

HOST="$(hostname -s 2>/dev/null || hostname || echo host)"
STAMP="$(date -u +%Y-%m-%d_%H%M%S)"
SHA12="$(printf '%s' "$SHA" | cut -c1-12)"
APP_DIR="${RELEASE_ROOT}/${APP}"

FILENAME="${APP}-${HOST}-${STAMP}-${SHA12}.tar.gz"
DEST_TAR="${APP_DIR}/${FILENAME}"
DEST_SHA="${DEST_TAR}.sha256"
LATEST_JSON="${APP_DIR}/latest.json"

TMP="$(mktemp -d)"
cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

RAW_TAR="${TMP}/raw.tar.gz"
RAW_EXTRACT="${TMP}/raw_extract"
STAGE="${TMP}/stage"

mkdir -p "$APP_DIR" "$RAW_EXTRACT" "$STAGE/${APP}"

URL="https://codeload.github.com/${REPO}/tar.gz/${SHA}"
echo "Downloading pinned source: ${URL}"
curl -fsSL --connect-timeout 15 --max-time 600 "$URL" -o "$RAW_TAR"

echo "Extracting raw archive..."
tar -xzf "$RAW_TAR" -C "$RAW_EXTRACT"

TOP_DIR="$(find "$RAW_EXTRACT" -mindepth 1 -maxdepth 1 -type d | head -n1 || true)"
if [[ -z "$TOP_DIR" || ! -d "$TOP_DIR" ]]; then
  echo "ERROR: Could not find extracted source root." >&2
  exit 1
fi

echo "Staging as '${APP}/' top-level folder..."
cp -a "${TOP_DIR}/." "${STAGE}/${APP}/"

echo "Creating release tarball: ${DEST_TAR}"
tar -czf "$DEST_TAR" -C "$STAGE" "$APP"

BYTES="$(stat -c '%s' "$DEST_TAR")"
SHA256="$(sha256sum "$DEST_TAR" | awk '{print $1}')"
printf '%s  %s\n' "$SHA256" "$FILENAME" > "$DEST_SHA"

CREATED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
cat > "$LATEST_JSON" <<EOF
{
  "app": "${APP}",
  "version": "${HOST}-${STAMP}-${SHA12}",
  "filename": "${FILENAME}",
  "sha256": "${SHA256}",
  "bytes": ${BYTES},
  "created_at": "${CREATED_AT}",
  "commit": "${SHA}",
  "source": "github"
}
EOF

echo "Applying retention policy (keep >= ${KEEP_MIN}, prune older than ${MAX_AGE_DAYS} days)..."
NOW_EPOCH="$(date +%s)"
CUTOFF_EPOCH="$(( NOW_EPOCH - (MAX_AGE_DAYS * 86400) ))"

mapfile -t RELEASE_ROWS < <(find "$APP_DIR" -maxdepth 1 -type f -name "${APP}-*.tar.gz" -printf '%T@ %p\n' | sort -nr)
IDX=0
for row in "${RELEASE_ROWS[@]}"; do
  IDX=$((IDX + 1))
  MTIME_RAW="${row%% *}"
  FILE_PATH="${row#* }"
  [[ -f "$FILE_PATH" ]] || continue

  # Keep at least KEEP_MIN newest tarballs no matter their age.
  if [[ "$IDX" -le "$KEEP_MIN" ]]; then
    continue
  fi

  MTIME_EPOCH="${MTIME_RAW%.*}"
  if [[ "$MTIME_EPOCH" -lt "$CUTOFF_EPOCH" ]]; then
    rm -f -- "$FILE_PATH" "${FILE_PATH}.sha256"
    echo "Pruned old release: $(basename "$FILE_PATH")"
  fi
done

echo "Release ready:"
echo "  app_dir:   ${APP_DIR}"
echo "  tarball:   ${DEST_TAR}"
echo "  sha256:    ${SHA256}"
echo "  latest:    ${LATEST_JSON}"

