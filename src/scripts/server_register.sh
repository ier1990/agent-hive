#!/usr/bin/env bash
# /web/html/src/scripts/server_register.sh
# Self-register this server with AgentHive and send periodic heartbeats.
#
# Usage:
#   AGENTHIVE_URL=http://192.168.1.10 AGENTHIVE_API_KEY=srv-lan-01-xxxx bash server_register.sh
#
# Run on startup via cron (@reboot) or systemd, e.g.:
#   @reboot AGENTHIVE_URL=http://hive AGENTHIVE_API_KEY=mykey /web/private/scripts/server_register.sh
#
# Environment variables:
#   AGENTHIVE_URL        - Base URL of the AgentHive instance (required)
#   AGENTHIVE_API_KEY    - API key with 'server' scope (required)
#   HEARTBEAT_INTERVAL   - Seconds between heartbeats (default: 60)
#   SERVER_ID_FILE       - Path to persist server_id (default: /web/private/server_id)
#   SERVER_LOCATION      - 'lan' or 'cloud' (default: lan)

set -euo pipefail

AGENTHIVE_URL="${AGENTHIVE_URL:-}"
AGENTHIVE_API_KEY="${AGENTHIVE_API_KEY:-}"
HEARTBEAT_INTERVAL="${HEARTBEAT_INTERVAL:-60}"
SERVER_ID_FILE="${SERVER_ID_FILE:-/web/private/server_id}"
SERVER_LOCATION="${SERVER_LOCATION:-lan}"

if [ -z "$AGENTHIVE_URL" ] || [ -z "$AGENTHIVE_API_KEY" ]; then
    echo "ERROR: AGENTHIVE_URL and AGENTHIVE_API_KEY must be set." >&2
    exit 1
fi

# Strip trailing slash from URL
AGENTHIVE_URL="${AGENTHIVE_URL%/}"

# ---------- Collect system info ----------

collect_hostname() {
    hostname -f 2>/dev/null || hostname 2>/dev/null || echo "unknown"
}

collect_lan_ip() {
    # Prefer the default-route interface IP
    ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}' | head -1
}

collect_public_ip() {
    curl -s --max-time 5 https://ifconfig.me 2>/dev/null || echo ""
}

collect_load() {
    # Returns: load_1m load_5m load_15m
    if [ -f /proc/loadavg ]; then
        awk '{print $1, $2, $3}' /proc/loadavg
    else
        uptime | awk -F'load average:' '{print $2}' | tr ',' ' ' | awk '{print $1, $2, $3}'
    fi
}

collect_memory() {
    # Returns: total_mb used_mb
    if command -v free >/dev/null 2>&1; then
        free -m | awk '/^Mem:/ {print $2, $3}'
    else
        echo "0 0"
    fi
}

collect_disk() {
    # Returns: total_gb used_gb (root filesystem)
    df -BG / 2>/dev/null | awk 'NR==2 {gsub("G",""); print $2, $3}' || echo "0 0"
}

detect_gpu() {
    if command -v nvidia-smi >/dev/null 2>&1; then
        echo "true"
    else
        echo "false"
    fi
}

# ---------- Build registration payload ----------

build_register_payload() {
    local hn ip_lan ip_pub load_vals mem_vals disk_vals gpu_flag
    local load_1 load_5 load_15 mem_total mem_used disk_total disk_used
    local existing_id

    hn="$(collect_hostname)"
    ip_lan="$(collect_lan_ip)"
    ip_pub="$(collect_public_ip)"

    read -r load_1 load_5 load_15 <<< "$(collect_load)"
    read -r mem_total mem_used <<< "$(collect_memory)"
    read -r disk_total disk_used <<< "$(collect_disk)"
    gpu_flag="$(detect_gpu)"

    # If we already have a server_id, include it for upsert
    existing_id=""
    if [ -f "$SERVER_ID_FILE" ]; then
        existing_id="$(cat "$SERVER_ID_FILE" 2>/dev/null | tr -d '[:space:]')"
    fi

    local id_field=""
    if [ -n "$existing_id" ]; then
        id_field="\"server_id\": \"$existing_id\","
    fi

    cat <<EOJSON
{
    ${id_field}
    "hostname": "$hn",
    "ip_lan": "$ip_lan",
    "ip_public": "$ip_pub",
    "location": "$SERVER_LOCATION",
    "capabilities": {"gpu": $gpu_flag, "storage": true},
    "load_1m": ${load_1:-0},
    "load_5m": ${load_5:-0},
    "load_15m": ${load_15:-0},
    "mem_total_mb": ${mem_total:-0},
    "mem_used_mb": ${mem_used:-0},
    "disk_total_gb": ${disk_total:-0},
    "disk_used_gb": ${disk_used:-0},
    "status": "online",
    "version": "$(uname -r 2>/dev/null || echo '')"
}
EOJSON
}

# ---------- Build heartbeat payload ----------

build_heartbeat_payload() {
    local load_1 load_5 load_15 mem_total mem_used disk_total disk_used server_id

    server_id="$(cat "$SERVER_ID_FILE" 2>/dev/null | tr -d '[:space:]')"
    read -r load_1 load_5 load_15 <<< "$(collect_load)"
    read -r mem_total mem_used <<< "$(collect_memory)"
    read -r disk_total disk_used <<< "$(collect_disk)"

    cat <<EOJSON
{
    "server_id": "$server_id",
    "load_1m": ${load_1:-0},
    "load_5m": ${load_5:-0},
    "load_15m": ${load_15:-0},
    "mem_total_mb": ${mem_total:-0},
    "mem_used_mb": ${mem_used:-0},
    "disk_total_gb": ${disk_total:-0},
    "disk_used_gb": ${disk_used:-0},
    "status": "online"
}
EOJSON
}

# ---------- Register ----------

echo "[$(date -Iseconds)] Registering with $AGENTHIVE_URL/v1/servers/register ..."

payload="$(build_register_payload)"
response="$(curl -s -X POST \
    "${AGENTHIVE_URL}/v1/servers/register" \
    -H "X-API-Key: $AGENTHIVE_API_KEY" \
    -H "Content-Type: application/json" \
    -d "$payload" \
    --max-time 15)"

# Extract server_id from response
ok="$(echo "$response" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('ok',''))" 2>/dev/null || echo "")"

if [ "$ok" != "True" ] && [ "$ok" != "true" ]; then
    echo "ERROR: Registration failed. Response: $response" >&2
    exit 1
fi

server_id="$(echo "$response" | python3 -c "import sys,json; print(json.load(sys.stdin).get('server_id',''))" 2>/dev/null || echo "")"

if [ -z "$server_id" ]; then
    echo "ERROR: No server_id in response. Response: $response" >&2
    exit 1
fi

# Persist server_id
id_dir="$(dirname "$SERVER_ID_FILE")"
if [ ! -d "$id_dir" ]; then
    mkdir -p "$id_dir"
fi
echo "$server_id" > "$SERVER_ID_FILE"
echo "[$(date -Iseconds)] Registered as server_id=$server_id"

# ---------- Heartbeat loop ----------

echo "[$(date -Iseconds)] Starting heartbeat loop (interval=${HEARTBEAT_INTERVAL}s) ..."

while true; do
    sleep "$HEARTBEAT_INTERVAL"

    hb_payload="$(build_heartbeat_payload)"
    hb_response="$(curl -s -X POST \
        "${AGENTHIVE_URL}/v1/servers/heartbeat" \
        -H "X-API-Key: $AGENTHIVE_API_KEY" \
        -H "Content-Type: application/json" \
        -d "$hb_payload" \
        --max-time 10 2>/dev/null || echo '{"ok":false}')"

    hb_ok="$(echo "$hb_response" | python3 -c "import sys,json; print(json.load(sys.stdin).get('ok',''))" 2>/dev/null || echo "")"

    if [ "$hb_ok" = "True" ] || [ "$hb_ok" = "true" ]; then
        echo "[$(date -Iseconds)] Heartbeat OK"
    else
        echo "[$(date -Iseconds)] Heartbeat failed: $hb_response" >&2
    fi
done
