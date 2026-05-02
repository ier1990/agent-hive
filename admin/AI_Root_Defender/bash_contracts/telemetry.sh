#!/bin/bash

echo "[TELEMETRY_CPU]"
ps -eo pid,comm,%cpu,%mem --sort=-%cpu | head -n 6

echo "[TELEMETRY_LOAD]"
awk '{print $1, $2, $3}' /proc/loadavg

echo "[TELEMETRY_MEMORY]"
free -h

# /web/html/admin/AI_Root_Defender/bash_contracts/telemetry.sh
# /web/html/admin/AI_Root_Defender/config/tools.default.json
#
# * Telemetry Script for AI Root Defender
# * This script collects system performance metrics and checks against defined thresholds.
# * If telemetry is enabled and any metric exceeds its threshold, an alert is printed.
# * 
# * Metrics Collected:
# * - CPU Load: Compared against the configured load-per-core ratio threshold.
# * - Memory Usage: Alert if usage exceeds the configured threshold.
# * - Swap Usage: Alert if usage exceeds the configured threshold.
# * - Root Disk Usage: Alert if usage exceeds the configured threshold.
# * 
# * Configuration:
# * The script checks the "telemetry" setting in the configuration file. If telemetry is disabled, the script exits without performing any checks.
# * The script also checks the "telemetry_alerts" setting. If telemetry alerts are disabled, the script exits without performing any checks.
# * Thresholds and optional higher-level diagnostic flags live under "telemetry_config" and "telemetry_diagnostics".
# * 
# * Usage:
# * This script is intended to be run periodically (e.g., via cron) to monitor system performance and alert administrators of potential issues.
# Load configuration settings
CONFIG_FILE="/web/html/admin/AI_Root_Defender/config/tools.default.json"
PRIVATE_CONFIG_FILE="${APP_PRIVATE_ROOT:-/web/private}/agent_tools.json"

# Read a single JSON key from a specific file without crashing the whole
# contract when the file is absent or the key is missing.
load_json_flag() {
    key="$1"
    file="$2"
    if [ ! -f "$file" ]; then
        return 1
    fi
    jq -r "$key // empty" "$file" 2>/dev/null
}

# Resolve a setting using the same public-default -> private-override shape
# documented elsewhere in Root Defender. The private file wins when present,
# and the shell fallback keeps the contract deterministic even during partial
# rollouts or missing config.
load_setting() {
    key="$1"
    fallback="$2"
    value=$(load_json_flag "$key" "$PRIVATE_CONFIG_FILE")
    if [ -z "$value" ]; then
        value=$(load_json_flag "$key" "$CONFIG_FILE")
    fi
    if [ -z "$value" ]; then
        value="$fallback"
    fi
    printf '%s\n' "$value"
}

# The public config file is part of the contract definition. If it disappears,
# fail loudly instead of silently changing the contract's behavior.
if [ ! -f "$CONFIG_FILE" ]; then
    echo "[ERROR] Configuration file not found: $CONFIG_FILE"
    exit 1
fi

TELEMETRY_SETTING=$(load_setting '.telemetry' "false")
TELEMETRY_ALERTS_SETTING=$(load_setting '.telemetry_alerts' "false")
LOAD_PER_CORE_RATIO_WARN=$(load_setting '.telemetry_config.load_per_core_ratio_warn' "1.0")
MEMORY_PCT_WARN=$(load_setting '.telemetry_config.memory_pct_warn' "80")
SWAP_PCT_WARN=$(load_setting '.telemetry_config.swap_pct_warn' "20")
DISK_ROOT_PCT_WARN=$(load_setting '.telemetry_config.disk_root_pct_warn' "90")

# These flags control "deeper suit" reporting. They expose more bounded host
# signals without turning the contract into an unrestricted shell.
MYSQL_PROCESS_DIAGNOSTIC=$(load_setting '.telemetry_diagnostics.mysql_process' "false")
FAILED_SERVICES_DIAGNOSTIC=$(load_setting '.telemetry_diagnostics.failed_services' "false")
DISK_INODES_DIAGNOSTIC=$(load_setting '.telemetry_diagnostics.disk_inodes' "false")
OOM_EVENTS_DIAGNOSTIC=$(load_setting '.telemetry_diagnostics.oom_events' "false")

# If telemetry itself is off, exit before collecting any additional host
# signals. This keeps monitor-mode off truly quiet.
if [ "$TELEMETRY_SETTING" != "true" ]; then
    exit 0
fi

# Load is compared against "cores * configured ratio" rather than a hardcoded
# number so the same contract behaves sensibly on both tiny and large hosts.
CPU_CORES=$(nproc)
LOAD=$(awk '{print $1}' /proc/loadavg)
LOAD_TRIP=$(awk "BEGIN { printf \"%.2f\", $CPU_CORES * $LOAD_PER_CORE_RATIO_WARN }")
LOAD_FLAG=0
if awk "BEGIN { exit !($LOAD > $LOAD_TRIP) }"; then
    LOAD_FLAG=1
fi

# Memory and swap are rounded to whole percentages because the contract is
# intended for human/AI scanning more than for graph-grade precision.
MEMORY_USAGE=$(free | awk '/Mem/{printf("%.0f"), $3/$2 * 100}')
MEMORY_FLAG=0
if [ "$MEMORY_USAGE" -gt "$MEMORY_PCT_WARN" ]; then
    MEMORY_FLAG=1
fi

SWAP_USAGE=$(free | awk '/Swap/{ if ($2 > 0) { printf("%.0f", $3/$2 * 100) } else { printf("0") } }')
SWAP_FLAG=0
if [ "$SWAP_USAGE" -gt "$SWAP_PCT_WARN" ]; then
    SWAP_FLAG=1
fi

# Root disk pressure is checked separately because full disks cause very
# different failure modes than CPU or memory saturation.
DISK_USAGE=$(df / | tail -1 | awk '{print $5}' | sed 's/%//')
DISK_FLAG=0
if [ "$DISK_USAGE" -gt "$DISK_ROOT_PCT_WARN" ]; then
    DISK_FLAG=1
fi

# Deeper diagnostics still run even when alerts are muted. That lets the
# operator keep the "space suit HUD" on without forcing alert spam.
if [ "$MYSQL_PROCESS_DIAGNOSTIC" = "true" ]; then
    echo "[TELEMETRY_MYSQL_PROCESS]"
    ps -eo pid,comm,%cpu,%mem --sort=-%cpu | awk 'NR==1 || /mysql|mariadb/'
fi

if [ "$FAILED_SERVICES_DIAGNOSTIC" = "true" ]; then
    echo "[TELEMETRY_FAILED_SERVICES]"
    systemctl --failed --no-legend 2>/dev/null || true
fi

if [ "$DISK_INODES_DIAGNOSTIC" = "true" ]; then
    echo "[TELEMETRY_INODES]"
    df -i /
fi

if [ "$OOM_EVENTS_DIAGNOSTIC" = "true" ]; then
    echo "[TELEMETRY_OOM_EVENTS]"
    journalctl -k -n 20 --no-pager 2>/dev/null | grep -i "killed process\|out of memory" || true
fi

# Alerts are the noisy layer on top of telemetry. If alerts are off, we stop
# after reporting the optional diagnostic sections above.
if [ "$TELEMETRY_ALERTS_SETTING" != "true" ]; then
    exit 0
fi

# Emit a specific alert first, then a single coarse "degraded" line so humans
# and the AI both get an easy summary to react to.
if [ "$LOAD_FLAG" -eq 1 ]; then
    echo "[ALERT] Load is higher than threshold! load=${LOAD} trip=${LOAD_TRIP} cores=${CPU_CORES}"
fi
if [ "$MEMORY_FLAG" -eq 1 ]; then
    echo "[ALERT] Memory usage is higher than ${MEMORY_PCT_WARN}%!"
fi
if [ "$SWAP_FLAG" -eq 1 ]; then
    echo "[ALERT] Swap usage is higher than ${SWAP_PCT_WARN}%!"
fi
if [ "$DISK_FLAG" -eq 1 ]; then
    echo "[ALERT] Disk usage is higher than ${DISK_ROOT_PCT_WARN}%!"
fi
if [ "$LOAD_FLAG" -eq 1 ] || [ "$MEMORY_FLAG" -eq 1 ] || [ "$SWAP_FLAG" -eq 1 ] || [ "$DISK_FLAG" -eq 1 ]; then
    echo "[ALERT] System performance is degraded! Please check the metrics above."
fi
