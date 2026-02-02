#!/usr/bin/env bash
set -euo pipefail

API="${API:-http://192.168.0.142/v1/inbox/}"
DB="${DB:-sysinfo_new}"
SERVICE="${SERVICE:-daily_sysinfo}"

# Optional auth (recommended): export IER_API_KEY="xxxx"
API_KEY_HEADER=()
[[ -n "${IER_API_KEY:-}" ]] && API_KEY_HEADER=( -H "X-API-Key: $IER_API_KEY" )

# Optional: auto-install a static jq if missing (set ALLOW_AUTO_INSTALL_JQ=1)
maybe_install_jq() {
  command -v jq >/dev/null 2>&1 && return 0
  [[ "${ALLOW_AUTO_INSTALL_JQ:-0}" = "1" ]] || return 1
  tmp="/usr/local/bin/jq"
  curl -fsSL --connect-timeout 10 --max-time 30 \
    https://github.com/jqlang/jq/releases/latest/download/jq-linux-amd64 -o "$tmp"
  chmod +x "$tmp"
}

# Robust primary IPv4 (global-scope)
PRIMARY_IP="$(ip -4 -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | head -n1 || true)"
[[ -z "$PRIMARY_IP" ]] && PRIMARY_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"

HOSTNAME_FQDN="$(hostname -f 2>/dev/null || hostname)"
MACHINE_ID="$(cat /etc/machine-id 2>/dev/null || echo unknown)"
HOST_ID="${MACHINE_ID}:${HOSTNAME_FQDN}:${PRIMARY_IP}"

TS="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

# Collect system info
UPTIME_HUMAN="$(uptime -p || true)"
UPTIME_SECS="$(cut -d' ' -f1 /proc/uptime 2>/dev/null | cut -d. -f1 || echo 0)"
LOAD="$(cut -d ' ' -f1-3 < /proc/loadavg)"
# Memory with "available" (older distros may lack this field)
if free -m | awk 'NR==2 && $7 ~ /^[0-9]+$/' >/dev/null 2>&1; then
  MEM="$(free -m | awk 'NR==2 {printf "%sMB used / %sMB total (avail %sMB)", $3, $2, $7}')"
else
  MEM="$(free -m | awk '/Mem:/ {printf "%sMB used / %sMB total", $3, $2}')"
fi
DISK_ROOT="$(df -h / | awk 'NR==2 {print $3 " used / " $2 " total (" $5 " used)"}')"
CPU_MODEL="$(grep -m1 'model name' /proc/cpuinfo | cut -d: -f2- | xargs || true)"
CPU_COUNT="$(nproc || echo 1)"
OS="$(grep -m1 PRETTY_NAME /etc/os-release | cut -d= -f2- | tr -d '"' || echo unknown)"
KERNEL="$(uname -r)"
PHP_VERSION="$( { php -v | head -n1 | awk '{print $2}'; } 2>/dev/null || echo "not installed")"
PUBLIC_IP="$(curl -fsS --connect-timeout 5 --max-time 10 https://api.ipify.org 2>/dev/null || echo "unknown")"

# ---- Build each optional JSON chunk properly ----
DOCKER_JSON=""
if command -v docker >/dev/null 2>&1; then
  running="$(docker ps -q 2>/dev/null | wc -l || echo 0)"
  total="$(docker ps -aq 2>/dev/null | wc -l || echo 0)"
  DOCKER_JSON="$(jq -nc --argjson r "$running" --argjson t "$total" \
    '{docker:{containers_running:$r,containers_total:$t}}')"
fi

NVIDIA_JSON=""
if command -v nvidia-smi >/dev/null 2>&1; then
  mapfile -t _g <<<"$(nvidia-smi \
    --query-gpu=name,driver_version,memory.total,temperature.gpu,power.draw,utilization.gpu \
    --format=csv,noheader,nounits 2>/dev/null || true)"
  if ((${#_g[@]})); then
    IFS=',' read -r g_name g_drv g_vram g_temp g_power g_util <<<"${_g[0]}"
    # trim & keep only digits for numeric fields
    g_name="$(echo "$g_name" | xargs)"
    g_drv="$(echo "$g_drv" | xargs)"
    g_vram="$(echo "$g_vram" | tr -dc '0-9')"
    g_temp="$(echo "$g_temp" | tr -dc '0-9')"
    g_power="$(echo "$g_power" | tr -dc '0-9.')"   # watts, may be empty
    g_util="$(echo "$g_util" | tr -dc '0-9')"      # percent, may be empty
    NVIDIA_JSON="$(jq -nc \
      --arg name "$g_name" \
      --arg drv  "$g_drv" \
      --arg vram "$g_vram" \
      --arg temp "$g_temp" \
      --arg power "$g_power" \
      --arg util  "$g_util" \
      '{gpu:{
          name:$name,
          driver:$drv,
          vram_mb:($vram|tonumber? // 0),
          temp_c:($temp|tonumber? // null),
          power_w:($power|tonumber? // null),
          util_pct:($util|tonumber? // null)
      }}')"
  fi
fi


OLLAMA_JSON=""
if command -v ollama >/dev/null 2>&1; then
  ov="$(ollama --version 2>/dev/null | awk '{print $NF}' || true)"
  [[ -n "$ov" ]] && OLLAMA_JSON="$(jq -nc --arg v "$ov" '{ollama:{version:$v}}')"
fi



# Ensure jq (or graceful failure)
if ! command -v jq >/dev/null 2>&1; then
  maybe_install_jq || { echo "ERROR: jq not found. Set ALLOW_AUTO_INSTALL_JQ=1 to auto-install, or install manually."; exit 1; }
fi

# Build JSON and send
jq -nc \
  --arg service "$SERVICE" \
  --arg db "$DB" \
  --arg host "$HOST_ID" \
  --arg ts "$TS" \
  --arg hostname "$HOSTNAME_FQDN" \
  --arg primary_ip "$PRIMARY_IP" \
  --arg uptime "$UPTIME_HUMAN" \
  --arg uptime_s "$UPTIME_SECS" \
  --arg load "$LOAD" \
  --arg mem "$MEM" \
  --arg disk "$DISK_ROOT" \
  --arg cpu "$CPU_MODEL" \
  --arg cpu_count "$CPU_COUNT" \
  --arg os "$OS" \
  --arg kernel "$KERNEL" \
  --arg php "$PHP_VERSION" \
  --arg pubip "$PUBLIC_IP" \
  --arg docker "$DOCKER_JSON" \
  --arg gpu "$NVIDIA_JSON" \
  --arg ollama "$OLLAMA_JSON" '
  ($docker|fromjson? // {}) as $d |
  ($gpu|fromjson? // {})    as $g |
  ($ollama|fromjson? // {}) as $o |
  {
    service:$service,
    db:$db,
    host:$host,
    ts:$ts,
    identity:{ hostname:$hostname, primary_ip:$primary_ip, machine:"'"$MACHINE_ID"'" },
    sysinfo:{
      uptime:$uptime,
      uptime_seconds: ($uptime_s|tonumber? // 0),
      load:$load,
      memory:$mem,
      disk:$disk,
      cpu:$cpu,
      cpu_count: ($cpu_count|tonumber? // 1),
      os:$os,
      kernel:$kernel,
      php_version:$php,
      public_ip:$pubip
    }
  } + $d + $g + $o
' | curl -fsS -X POST "$API" \
     -H 'Content-Type: application/json' \
     "${API_KEY_HEADER[@]}" \
     --connect-timeout 5 --max-time 20 \
     --retry 3 --retry-all-errors \
     --data-binary @- \
  || echo "Failed to send sysinfo" >&2

