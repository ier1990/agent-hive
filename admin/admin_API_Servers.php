<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

header('Content-Type: text/html; charset=utf-8');

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function infer_base_url() {
    $https = isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'localhost';
    return $scheme . '://' . $host;
}

function build_servers_list_url($baseUrl, $statusFilter, $locationFilter) {
    $baseUrl = rtrim((string)$baseUrl, '/');
    $params = [];
    if ($statusFilter !== '') $params['status'] = $statusFilter;
    if ($locationFilter !== '') $params['location'] = $locationFilter;
    $qs = $params ? ('?' . http_build_query($params)) : '';
    return $baseUrl . '/v1/servers/list' . $qs;
}

function fetch_servers_list($baseUrl, $apiKey, $statusFilter, $locationFilter) {
    $url = build_servers_list_url($baseUrl, $statusFilter, $locationFilter);
    $headers = ['Accept: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'X-API-Key: ' . $apiKey;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string)curl_error($ch);
    curl_close($ch);

    $decoded = null;
    if (is_string($body) && $body !== '') {
        $tmp = json_decode($body, true);
        if (is_array($tmp)) $decoded = $tmp;
    }

    return [
        'url' => $url,
        'http_code' => $code,
        'curl_error' => $err,
        'raw' => is_string($body) ? $body : '',
        'json' => $decoded,
    ];
}

function auth_csrf_token() {
    if (function_exists('auth_session_start')) {
        auth_session_start();
    } elseif (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_ai_servers'])) {
        $_SESSION['csrf_ai_servers'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf_ai_servers'];
}

function auth_csrf_ok($token) {
    if (function_exists('auth_session_start')) {
        auth_session_start();
    } elseif (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return isset($_SESSION['csrf_ai_servers']) && is_string($token) && hash_equals((string)$_SESSION['csrf_ai_servers'], $token);
}

function cluster_config_path() {
    return rtrim((string)PRIVATE_ROOT, '/\\') . '/config/cluster_receiver.json';
}

function read_cluster_config($path) {
    if (!is_readable($path)) return [];
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return [];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function write_cluster_config($path, array $cfg) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) return false;
    return file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function cluster_db_path() {
    return rtrim((string)PRIVATE_ROOT, '/\\') . '/db/cluster.db';
}

function cluster_db_open($path) {
    if (!is_readable($path)) return null;
    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout=5000');
        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

function cluster_db_table_exists(PDO $pdo, $tableName) {
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:name LIMIT 1");
    $stmt->bindValue(':name', (string)$tableName, PDO::PARAM_STR);
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
}

function cluster_db_summary_stats(PDO $pdo, $staleSeconds) {
    $staleSeconds = (int)$staleSeconds;
    if ($staleSeconds < 1) $staleSeconds = 300;

    $stats = [
        'total_servers' => 0,
        'online' => 0,
        'offline' => 0,
        'degraded' => 0,
        'maintenance' => 0,
        'stale' => 0,
        'fresh' => 0,
        'heartbeats_5m' => 0,
        'heartbeats_1h' => 0,
        'avg_load_1m' => 0,
        'avg_load_5m' => 0,
        'avg_load_15m' => 0,
        'last_seen_max' => '',
        'registered_min' => '',
    ];

    $row = $pdo->query("
        SELECT
            COUNT(*) AS total_servers,
            SUM(CASE WHEN status='online' THEN 1 ELSE 0 END) AS online,
            SUM(CASE WHEN status='offline' THEN 1 ELSE 0 END) AS offline,
            SUM(CASE WHEN status='degraded' THEN 1 ELSE 0 END) AS degraded,
            SUM(CASE WHEN status='maintenance' THEN 1 ELSE 0 END) AS maintenance,
            SUM(CASE WHEN (strftime('%s','now') - strftime('%s', last_seen)) > " . $staleSeconds . " THEN 1 ELSE 0 END) AS stale,
            SUM(CASE WHEN (strftime('%s','now') - strftime('%s', last_seen)) <= " . $staleSeconds . " THEN 1 ELSE 0 END) AS fresh,
            SUM(CASE WHEN (strftime('%s','now') - strftime('%s', last_seen)) <= 300 THEN 1 ELSE 0 END) AS heartbeats_5m,
            SUM(CASE WHEN (strftime('%s','now') - strftime('%s', last_seen)) <= 3600 THEN 1 ELSE 0 END) AS heartbeats_1h,
            ROUND(AVG(load_1m), 3) AS avg_load_1m,
            ROUND(AVG(load_5m), 3) AS avg_load_5m,
            ROUND(AVG(load_15m), 3) AS avg_load_15m,
            MAX(last_seen) AS last_seen_max,
            MIN(registered_at) AS registered_min
        FROM servers
    ")->fetch(PDO::FETCH_ASSOC);

    if (is_array($row)) {
        foreach ($stats as $k => $v) {
            if (array_key_exists($k, $row) && $row[$k] !== null) {
                $stats[$k] = $row[$k];
            }
        }
    }
    return $stats;
}

function cluster_db_recent_rows(PDO $pdo, $limit) {
    $limit = (int)$limit;
    if ($limit < 1) $limit = 25;
    if ($limit > 500) $limit = 500;

    $sql = "
        SELECT
            server_id, hostname, location, status,
            ip_lan, ip_public,
            load_1m, load_5m, load_15m,
            mem_used_mb, mem_total_mb,
            disk_used_gb, disk_total_gb,
            registered_at, last_seen,
            (strftime('%s','now') - strftime('%s', last_seen)) AS seconds_since_heartbeat
        FROM servers
        ORDER BY datetime(last_seen) DESC, hostname ASC
        LIMIT " . $limit;

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function cluster_db_heartbeat_history_stats(PDO $pdo) {
    $stats = [
        'total_samples' => 0,
        'samples_24h' => 0,
        'samples_7d' => 0,
        'samples_30d' => 0,
        'first_sample_at' => '',
        'last_sample_at' => '',
    ];
    $row = $pdo->query("
        SELECT
            COUNT(*) AS total_samples,
            SUM(CASE WHEN sampled_at >= datetime('now', '-1 day') THEN 1 ELSE 0 END) AS samples_24h,
            SUM(CASE WHEN sampled_at >= datetime('now', '-7 days') THEN 1 ELSE 0 END) AS samples_7d,
            SUM(CASE WHEN sampled_at >= datetime('now', '-30 days') THEN 1 ELSE 0 END) AS samples_30d,
            MIN(sampled_at) AS first_sample_at,
            MAX(sampled_at) AS last_sample_at
        FROM heartbeat_samples
    ")->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        foreach ($stats as $k => $v) {
            if (array_key_exists($k, $row) && $row[$k] !== null) {
                $stats[$k] = $row[$k];
            }
        }
    }
    return $stats;
}

function cluster_db_heartbeat_series(PDO $pdo, $range) {
    $range = (string)$range;
    $tz = new DateTimeZone('UTC');
    $now = new DateTime('now', $tz);
    $cfg = [
        'range' => $range,
        'title' => 'Custom',
        'steps' => 24,
        'interval' => new DateInterval('PT1H'),
        'bucket_fmt' => 'Y-m-d H:00:00',
        'label_fmt' => 'H:i',
        'sql_bucket' => "%Y-%m-%d %H:00:00",
    ];

    if ($range === 'weekly') {
        $cfg['title'] = 'Weekly (last 7 days)';
        $cfg['steps'] = 7;
        $cfg['interval'] = new DateInterval('P1D');
        $cfg['bucket_fmt'] = 'Y-m-d';
        $cfg['label_fmt'] = 'm-d';
        $cfg['sql_bucket'] = "%Y-%m-%d";
        $start = clone $now;
        $start->setTime(0, 0, 0);
        $start->modify('-6 days');
    } elseif ($range === 'monthly') {
        $cfg['title'] = 'Monthly (last 30 days)';
        $cfg['steps'] = 30;
        $cfg['interval'] = new DateInterval('P1D');
        $cfg['bucket_fmt'] = 'Y-m-d';
        $cfg['label_fmt'] = 'm-d';
        $cfg['sql_bucket'] = "%Y-%m-%d";
        $start = clone $now;
        $start->setTime(0, 0, 0);
        $start->modify('-29 days');
    } else {
        $cfg['title'] = 'Daily (last 24 hours)';
        $start = clone $now;
        $start->setTime((int)$now->format('H'), 0, 0);
        $start->modify('-23 hours');
    }

    $end = clone $start;
    for ($i = 0; $i < $cfg['steps']; $i++) {
        $end->add($cfg['interval']);
    }

    $sql = "
        SELECT
            strftime('" . $cfg['sql_bucket'] . "', sampled_at) AS bucket,
            COUNT(*) AS sample_count,
            COUNT(DISTINCT server_id) AS active_servers,
            ROUND(AVG(load_1m), 3) AS avg_load_1m,
            ROUND(AVG(CASE WHEN mem_total_mb > 0 THEN (mem_used_mb * 100.0 / mem_total_mb) END), 2) AS avg_mem_pct
        FROM heartbeat_samples
        WHERE sampled_at >= :start_at AND sampled_at < :end_at
        GROUP BY bucket
        ORDER BY bucket ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':start_at', $start->format('Y-m-d H:i:s'), PDO::PARAM_STR);
    $stmt->bindValue(':end_at', $end->format('Y-m-d H:i:s'), PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $byBucket = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $b = (string)($row['bucket'] ?? '');
            if ($b !== '') $byBucket[$b] = $row;
        }
    }

    $labels = [];
    $samples = [];
    $active = [];
    $load1m = [];
    $memPct = [];
    $cursor = clone $start;
    for ($i = 0; $i < $cfg['steps']; $i++) {
        $bucketKey = $cursor->format($cfg['bucket_fmt']);
        $labels[] = $cursor->format($cfg['label_fmt']);
        $row = isset($byBucket[$bucketKey]) ? $byBucket[$bucketKey] : null;
        $samples[] = $row ? (int)($row['sample_count'] ?? 0) : 0;
        $active[] = $row ? (int)($row['active_servers'] ?? 0) : 0;
        $load1m[] = $row && $row['avg_load_1m'] !== null ? (float)$row['avg_load_1m'] : 0.0;
        $memPct[] = $row && $row['avg_mem_pct'] !== null ? (float)$row['avg_mem_pct'] : 0.0;
        $cursor->add($cfg['interval']);
    }

    return [
        'range' => $cfg['range'],
        'title' => $cfg['title'],
        'start_at' => $start->format('Y-m-d H:i:s'),
        'end_at' => $end->format('Y-m-d H:i:s'),
        'labels' => $labels,
        'samples' => $samples,
        'active' => $active,
        'load1m' => $load1m,
        'mem_pct' => $memPct,
        'total_samples' => array_sum($samples),
        'max_active_servers' => $active ? max($active) : 0,
    ];
}

function cluster_svg_line_chart(array $values, $stroke, $width = 780, $height = 170) {
    $width = (int)$width;
    $height = (int)$height;
    if ($width < 80) $width = 80;
    if ($height < 60) $height = 60;

    $vals = [];
    foreach ($values as $v) $vals[] = (float)$v;
    if (count($vals) === 0) {
        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" class="chart-svg" xmlns="http://www.w3.org/2000/svg"></svg>';
    }

    $padL = 34;
    $padR = 10;
    $padT = 10;
    $padB = 22;
    $plotW = $width - $padL - $padR;
    $plotH = $height - $padT - $padB;
    if ($plotW < 10) $plotW = 10;
    if ($plotH < 10) $plotH = 10;

    $max = max($vals);
    $min = min($vals);
    if ($max <= $min) {
        $min = 0.0;
        $max = $max <= 0 ? 1.0 : $max;
    }
    $n = count($vals);
    $stepX = ($n > 1) ? ($plotW / ($n - 1)) : 0;
    $points = [];
    for ($i = 0; $i < $n; $i++) {
        $x = $padL + ($stepX * $i);
        $norm = ($vals[$i] - $min) / ($max - $min);
        $y = $padT + ($plotH * (1 - $norm));
        $points[] = round($x, 2) . ',' . round($y, 2);
    }
    $poly = implode(' ', $points);
    $baseY = $padT + $plotH;

    return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" class="chart-svg" xmlns="http://www.w3.org/2000/svg">'
        . '<line x1="' . $padL . '" y1="' . $baseY . '" x2="' . ($padL + $plotW) . '" y2="' . $baseY . '" stroke="#2b3a5b" stroke-width="1"/>'
        . '<polyline fill="none" stroke="' . h($stroke) . '" stroke-width="2" points="' . h($poly) . '"/>'
        . '</svg>';
}

$saveMsg = '';
$saveErr = '';
$cfgPath = cluster_config_path();
$clusterDbPath = cluster_db_path();
$clusterDbErr = '';
$clusterDbStats = null;
$clusterDbRows = [];
$clusterDbStaleSeconds = 300;
$clusterHbStats = null;
$clusterHbCharts = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if (!auth_csrf_ok($csrf)) {
        $saveErr = 'Invalid CSRF token.';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        if ($action === 'save_cluster_receiver') {
            $receiverUrl = trim((string)($_POST['receiver_url'] ?? ''));
            $receiverKey = trim((string)($_POST['receiver_api_key'] ?? ''));
            $serverLocation = trim((string)($_POST['server_location'] ?? 'lan'));
            $heartbeat = (int)($_POST['heartbeat_interval'] ?? 60);
            if ($heartbeat < 10) $heartbeat = 10;
            if (!in_array($serverLocation, ['lan', 'cloud'], true)) $serverLocation = 'lan';
            if ($receiverUrl === '' || $receiverKey === '') {
                $saveErr = 'Receiver URL and API key are required.';
            } else {
                $cfg = [
                    'receiver_url' => rtrim($receiverUrl, '/'),
                    'api_key' => $receiverKey,
                    'server_location' => $serverLocation,
                    'heartbeat_interval' => $heartbeat,
                    'updated_at' => gmdate('c'),
                ];
                if (write_cluster_config($cfgPath, $cfg)) {
                    $saveMsg = 'Cluster receiver config saved to ' . $cfgPath;
                } else {
                    $saveErr = 'Failed writing ' . $cfgPath . ' (check permissions).';
                }
            }
        } elseif ($action === 'clear_cluster_receiver') {
            if (is_file($cfgPath) && !@unlink($cfgPath)) {
                $saveErr = 'Failed to remove ' . $cfgPath;
            } else {
                $saveMsg = 'Cluster receiver config cleared.';
            }
        }
    }
}

$clusterCfg = read_cluster_config($cfgPath);
$cfgReceiverUrl = trim((string)($clusterCfg['receiver_url'] ?? ''));
$cfgReceiverKey = trim((string)($clusterCfg['api_key'] ?? ''));
$cfgServerLocation = trim((string)($clusterCfg['server_location'] ?? 'lan'));
$cfgHeartbeatInterval = (int)($clusterCfg['heartbeat_interval'] ?? 60);
if ($cfgHeartbeatInterval < 10) $cfgHeartbeatInterval = 60;

$baseUrl = trim((string)($_GET['base_url'] ?? ($cfgReceiverUrl !== '' ? $cfgReceiverUrl : infer_base_url())));
$apiKey = trim((string)($_GET['api_key'] ?? $cfgReceiverKey));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$locationFilter = trim((string)($_GET['location'] ?? ''));
$run = isset($_GET['run']) && (string)$_GET['run'] === '1';

$result = null;
$servers = [];
$error = '';

if ($run) {
    if ($baseUrl === '') {
        $error = 'Base URL is required.';
    } else {
        $result = fetch_servers_list($baseUrl, $apiKey, $statusFilter, $locationFilter);
        if ($result['curl_error'] !== '') {
            $error = 'cURL error: ' . $result['curl_error'];
        } elseif (!is_array($result['json'])) {
            $error = 'Endpoint did not return JSON.';
        } else {
            if (!empty($result['json']['ok'])) {
                $servers = is_array($result['json']['servers'] ?? null) ? $result['json']['servers'] : [];
            } else {
                $error = (string)($result['json']['error'] ?? 'request_failed');
                $reason = (string)($result['json']['reason'] ?? '');
                if ($reason !== '') $error .= ' (' . $reason . ')';
            }
        }
    }
}

$clusterPdo = cluster_db_open($clusterDbPath);
if ($clusterPdo === null) {
    if (is_file($clusterDbPath)) {
        $clusterDbErr = 'Could not open cluster DB at ' . $clusterDbPath;
    }
} else {
    try {
        if (!cluster_db_table_exists($clusterPdo, 'servers')) {
            $clusterDbErr = 'DB opened, but table "servers" does not exist yet.';
        } else {
            $clusterDbStats = cluster_db_summary_stats($clusterPdo, $clusterDbStaleSeconds);
            $clusterDbRows = cluster_db_recent_rows($clusterPdo, 100);
            if (cluster_db_table_exists($clusterPdo, 'heartbeat_samples')) {
                $clusterHbStats = cluster_db_heartbeat_history_stats($clusterPdo);
                $clusterHbCharts['daily'] = cluster_db_heartbeat_series($clusterPdo, 'daily');
                $clusterHbCharts['weekly'] = cluster_db_heartbeat_series($clusterPdo, 'weekly');
                $clusterHbCharts['monthly'] = cluster_db_heartbeat_series($clusterPdo, 'monthly');
            }
        }
    } catch (Throwable $e) {
        $clusterDbErr = 'DB query error: ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API Servers</title>
    <style>
        :root {
            --bg: #0b1220;
            --panel: #131d31;
            --panel2: #182643;
            --ink: #e5ecff;
            --muted: #9aa8c7;
            --ok: #29c36a;
            --warn: #f2a93b;
            --bad: #ec5a5a;
            --line: #2b3a5b;
            --accent: #5aa3ff;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: radial-gradient(circle at 20% 0%, #1a2c50 0%, #0b1220 55%);
            color: var(--ink);
            font: 14px/1.45 "Segoe UI", Tahoma, sans-serif;
            padding: 16px;
        }
        h1, h2, h3 { margin: 0 0 10px; }
        .wrap { max-width: 1200px; margin: 0 auto; }
        .panel {
            background: linear-gradient(180deg, var(--panel) 0%, var(--panel2) 100%);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 12px;
        }
        .muted { color: var(--muted); }
        .grid {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(4, minmax(140px, 1fr));
        }
        label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 4px; }
        input, select, button, textarea {
            width: 100%;
            border: 1px solid var(--line);
            background: #0d1628;
            color: var(--ink);
            border-radius: 8px;
            padding: 9px 10px;
            font: inherit;
        }
        button {
            background: var(--accent);
            color: #081425;
            font-weight: 700;
            cursor: pointer;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            border-bottom: 1px solid var(--line);
            text-align: left;
            padding: 8px;
            vertical-align: top;
        }
        th { color: var(--muted); font-weight: 600; }
        .badge {
            display: inline-block;
            border-radius: 999px;
            padding: 2px 8px;
            font-size: 12px;
            border: 1px solid var(--line);
            background: #10203a;
        }
        .ok { color: var(--ok); }
        .warn { color: var(--warn); }
        .bad { color: var(--bad); }
        code, pre {
            background: #0b1629;
            border: 1px solid var(--line);
            border-radius: 8px;
        }
        code { padding: 2px 5px; }
        pre {
            margin: 0;
            padding: 10px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .row { margin: 8px 0 0; }
        .chart-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: 1fr;
            margin-top: 10px;
        }
        .chart-card {
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #0f1a2f;
            padding: 10px;
        }
        .chart-title {
            font-weight: 600;
            margin-bottom: 6px;
        }
        .chart-subgrid {
            display: grid;
            gap: 8px;
            grid-template-columns: repeat(3, minmax(160px, 1fr));
            margin-bottom: 8px;
        }
        .chart-svg {
            width: 100%;
            height: auto;
            display: block;
            background: #0b1629;
            border: 1px solid var(--line);
            border-radius: 8px;
        }
        .legend {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 12px;
            color: var(--muted);
            margin-top: 8px;
        }
        .legend-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
            vertical-align: middle;
        }
        @media (max-width: 900px) {
            .grid { grid-template-columns: 1fr; }
            .chart-subgrid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="panel">
        <h1>API Servers</h1>
        <div class="muted">Cluster endpoint is <code>/v1/servers/*</code>. There is no separate <code>/v1/register</code> route in this repo.</div>
    </div>

    <div class="panel">
        <h2>Cluster Receiver Config (for server_register.sh)</h2>
        <div class="muted">Saved at <code><?php echo h($cfgPath); ?></code>. Joining nodes can run <code>bash /web/html/src/scripts/server_register.sh</code> without passing env vars.</div>
        <?php if ($saveMsg !== ''): ?>
            <div class="row ok"><strong>Saved:</strong> <?php echo h($saveMsg); ?></div>
        <?php endif; ?>
        <?php if ($saveErr !== ''): ?>
            <div class="row bad"><strong>Error:</strong> <?php echo h($saveErr); ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo h(auth_csrf_token()); ?>">
            <input type="hidden" name="action" value="save_cluster_receiver">
            <div class="grid">
                <div>
                    <label>Receiver URL</label>
                    <input type="text" name="receiver_url" value="<?php echo h($cfgReceiverUrl); ?>" placeholder="http://192.168.0.142" required>
                </div>
                <div>
                    <label>Receiver API Key (<code>server</code> scope)</label>
                    <input type="text" name="receiver_api_key" value="<?php echo h($cfgReceiverKey); ?>" placeholder="srv-cluster-1" required>
                </div>
                <div>
                    <label>Server Location</label>
                    <select name="server_location">
                        <option value="lan"<?php echo $cfgServerLocation === 'lan' ? ' selected' : ''; ?>>lan</option>
                        <option value="cloud"<?php echo $cfgServerLocation === 'cloud' ? ' selected' : ''; ?>>cloud</option>
                    </select>
                </div>
                <div>
                    <label>Heartbeat Interval (seconds)</label>
                    <input type="number" name="heartbeat_interval" min="10" max="3600" value="<?php echo h((string)$cfgHeartbeatInterval); ?>">
                </div>
            </div>
            <div class="row"><button type="submit">Save Cluster Config</button></div>
        </form>
        <form method="post" class="row">
            <input type="hidden" name="csrf_token" value="<?php echo h(auth_csrf_token()); ?>">
            <input type="hidden" name="action" value="clear_cluster_receiver">
            <button type="submit">Clear Saved Config</button>
        </form>
    </div>

    <div class="panel">
        <h2>Cluster Viewer</h2>
        <form method="get">
            <input type="hidden" name="run" value="1">
            <div class="grid">
                <div>
                    <label>Base URL</label>
                    <input type="text" name="base_url" value="<?php echo h($baseUrl); ?>" placeholder="http://192.168.1.10">
                </div>
                <div>
                    <label>API Key (must include <code>server</code> scope)</label>
                    <input type="text" name="api_key" value="<?php echo h($apiKey); ?>" placeholder="srv-cluster-1">
                </div>
                <div>
                    <label>Status Filter</label>
                    <select name="status">
                        <?php
                        $statusOptions = ['', 'online', 'offline', 'degraded', 'maintenance'];
                        foreach ($statusOptions as $opt) {
                            $sel = ($statusFilter === $opt) ? ' selected' : '';
                            $label = ($opt === '') ? '(all)' : $opt;
                            echo '<option value="' . h($opt) . '"' . $sel . '>' . h($label) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label>Location Filter</label>
                    <select name="location">
                        <?php
                        $locationOptions = ['', 'lan', 'cloud'];
                        foreach ($locationOptions as $opt) {
                            $sel = ($locationFilter === $opt) ? ' selected' : '';
                            $label = ($opt === '') ? '(all)' : $opt;
                            echo '<option value="' . h($opt) . '"' . $sel . '>' . h($label) . '</option>';
                        }
                        ?>
                    </select>
                </div>
            </div>
            <div class="row"><button type="submit">Fetch /v1/servers/list</button></div>
        </form>

        <?php if ($run): ?>
            <div class="row muted">Request URL: <code><?php echo h($result ? $result['url'] : ''); ?></code></div>
            <?php if ($result): ?>
                <div class="row muted">HTTP: <code><?php echo h((string)$result['http_code']); ?></code></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="row bad"><strong>Error:</strong> <?php echo h($error); ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Server List</h2>
        <?php if (!$run): ?>
            <div class="muted">Run a query above to load data.</div>
        <?php elseif ($error !== ''): ?>
            <div class="muted">No rows due to error. See details above.</div>
        <?php elseif (empty($servers)): ?>
            <div class="muted">No servers returned.</div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Hostname</th>
                    <th>Status</th>
                    <th>Last Seen</th>
                    <th>LAN / Public IP</th>
                    <th>Load</th>
                    <th>Memory</th>
                    <th>Disk</th>
                    <th>Server ID</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($servers as $s): ?>
                    <?php
                    $status = (string)($s['status'] ?? '');
                    $isStale = !empty($s['stale']);
                    $statusClass = 'ok';
                    if ($isStale || $status === 'offline') $statusClass = 'bad';
                    elseif ($status === 'degraded' || $status === 'maintenance') $statusClass = 'warn';
                    ?>
                    <tr>
                        <td><?php echo h((string)($s['hostname'] ?? '')); ?></td>
                        <td>
                            <span class="badge <?php echo h($statusClass); ?>"><?php echo h($status !== '' ? $status : 'unknown'); ?></span>
                            <?php if ($isStale): ?><span class="badge bad">stale</span><?php endif; ?>
                        </td>
                        <td><?php echo h((string)($s['last_seen'] ?? '')); ?></td>
                        <td><?php echo h((string)($s['ip_lan'] ?? '')); ?><br><span class="muted"><?php echo h((string)($s['ip_public'] ?? '')); ?></span></td>
                        <td><?php echo h((string)($s['load_1m'] ?? 0)); ?> / <?php echo h((string)($s['load_5m'] ?? 0)); ?> / <?php echo h((string)($s['load_15m'] ?? 0)); ?></td>
                        <td><?php echo h((string)($s['mem_used_mb'] ?? 0)); ?> / <?php echo h((string)($s['mem_total_mb'] ?? 0)); ?> MB</td>
                        <td><?php echo h((string)($s['disk_used_gb'] ?? 0)); ?> / <?php echo h((string)($s['disk_total_gb'] ?? 0)); ?> GB</td>
                        <td><code><?php echo h((string)($s['server_id'] ?? '')); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Local Cluster DB Stats</h2>
        <div class="muted">Source: <code><?php echo h($clusterDbPath); ?></code>. Recency uses <code>servers.last_seen</code>; history graphs below use <code>heartbeat_samples</code>.</div>
        <?php if (!is_file($clusterDbPath)): ?>
            <div class="row muted">Cluster DB file does not exist yet. It will be created after first register/heartbeat to <code>/v1/servers/*</code>.</div>
        <?php elseif ($clusterDbErr !== ''): ?>
            <div class="row bad"><strong>Error:</strong> <?php echo h($clusterDbErr); ?></div>
        <?php elseif (!is_array($clusterDbStats)): ?>
            <div class="row muted">No stats available.</div>
        <?php else: ?>
            <div class="grid">
                <div><label>Total Servers</label><div><strong><?php echo h((string)$clusterDbStats['total_servers']); ?></strong></div></div>
                <div><label>Fresh (<= <?php echo h((string)$clusterDbStaleSeconds); ?>s)</label><div><strong class="ok"><?php echo h((string)$clusterDbStats['fresh']); ?></strong></div></div>
                <div><label>Stale (&gt; <?php echo h((string)$clusterDbStaleSeconds); ?>s)</label><div><strong class="bad"><?php echo h((string)$clusterDbStats['stale']); ?></strong></div></div>
                <div><label>Heartbeats in 5m</label><div><strong><?php echo h((string)$clusterDbStats['heartbeats_5m']); ?></strong></div></div>
                <div><label>Heartbeats in 1h</label><div><strong><?php echo h((string)$clusterDbStats['heartbeats_1h']); ?></strong></div></div>
                <div><label>Status: online / degraded</label><div><strong><?php echo h((string)$clusterDbStats['online']); ?></strong> / <strong><?php echo h((string)$clusterDbStats['degraded']); ?></strong></div></div>
                <div><label>Status: maintenance / offline</label><div><strong><?php echo h((string)$clusterDbStats['maintenance']); ?></strong> / <strong><?php echo h((string)$clusterDbStats['offline']); ?></strong></div></div>
                <div><label>Avg Load (1m/5m/15m)</label><div><strong><?php echo h((string)$clusterDbStats['avg_load_1m']); ?></strong> / <strong><?php echo h((string)$clusterDbStats['avg_load_5m']); ?></strong> / <strong><?php echo h((string)$clusterDbStats['avg_load_15m']); ?></strong></div></div>
            </div>
            <div class="row muted">First registered: <code><?php echo h((string)$clusterDbStats['registered_min']); ?></code> | Last heartbeat seen: <code><?php echo h((string)$clusterDbStats['last_seen_max']); ?></code></div>

            <?php if (empty($clusterDbRows)): ?>
                <div class="row muted">No server rows in DB.</div>
            <?php else: ?>
                <div class="row"><strong>Recent Heartbeat Rows</strong></div>
                <table>
                    <thead>
                    <tr>
                        <th>Hostname</th>
                        <th>Status</th>
                        <th>Heartbeat Age (s)</th>
                        <th>Last Seen</th>
                        <th>LAN / Public IP</th>
                        <th>Load</th>
                        <th>Memory</th>
                        <th>Disk</th>
                        <th>Server ID</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($clusterDbRows as $r): ?>
                        <?php
                        $heartbeatAge = isset($r['seconds_since_heartbeat']) ? (int)$r['seconds_since_heartbeat'] : -1;
                        $rowClass = ($heartbeatAge >= 0 && $heartbeatAge > $clusterDbStaleSeconds) ? 'bad' : 'ok';
                        ?>
                        <tr>
                            <td><?php echo h((string)($r['hostname'] ?? '')); ?><br><span class="muted"><?php echo h((string)($r['location'] ?? '')); ?></span></td>
                            <td><span class="badge"><?php echo h((string)($r['status'] ?? 'unknown')); ?></span></td>
                            <td class="<?php echo h($rowClass); ?>"><?php echo h((string)$heartbeatAge); ?></td>
                            <td><?php echo h((string)($r['last_seen'] ?? '')); ?></td>
                            <td><?php echo h((string)($r['ip_lan'] ?? '')); ?><br><span class="muted"><?php echo h((string)($r['ip_public'] ?? '')); ?></span></td>
                            <td><?php echo h((string)($r['load_1m'] ?? 0)); ?> / <?php echo h((string)($r['load_5m'] ?? 0)); ?> / <?php echo h((string)($r['load_15m'] ?? 0)); ?></td>
                            <td><?php echo h((string)($r['mem_used_mb'] ?? 0)); ?> / <?php echo h((string)($r['mem_total_mb'] ?? 0)); ?> MB</td>
                            <td><?php echo h((string)($r['disk_used_gb'] ?? 0)); ?> / <?php echo h((string)($r['disk_total_gb'] ?? 0)); ?> GB</td>
                            <td><code><?php echo h((string)($r['server_id'] ?? '')); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Heartbeat History Graphs</h2>
        <?php if (!is_file($clusterDbPath)): ?>
            <div class="muted">Cluster DB file does not exist yet.</div>
        <?php elseif ($clusterDbErr !== ''): ?>
            <div class="muted">DB error above prevented graph rendering.</div>
        <?php elseif (!is_array($clusterHbStats)): ?>
            <div class="muted">No heartbeat history table yet. New records will be appended after register/heartbeat calls.</div>
        <?php else: ?>
            <div class="chart-subgrid">
                <div><label>Total Samples</label><div><strong><?php echo h((string)$clusterHbStats['total_samples']); ?></strong></div></div>
                <div><label>Samples (24h / 7d / 30d)</label><div><strong><?php echo h((string)$clusterHbStats['samples_24h']); ?></strong> / <strong><?php echo h((string)$clusterHbStats['samples_7d']); ?></strong> / <strong><?php echo h((string)$clusterHbStats['samples_30d']); ?></strong></div></div>
                <div><label>First / Last Sample</label><div><code><?php echo h((string)$clusterHbStats['first_sample_at']); ?></code> → <code><?php echo h((string)$clusterHbStats['last_sample_at']); ?></code></div></div>
            </div>

            <div class="chart-grid">
                <?php foreach ($clusterHbCharts as $chart): ?>
                    <div class="chart-card">
                        <div class="chart-title"><?php echo h((string)($chart['title'] ?? 'History')); ?></div>
                        <div class="chart-subgrid">
                            <div><label>Total Samples</label><div><strong><?php echo h((string)($chart['total_samples'] ?? 0)); ?></strong></div></div>
                            <div><label>Max Active Servers</label><div><strong><?php echo h((string)($chart['max_active_servers'] ?? 0)); ?></strong></div></div>
                            <div><label>Window</label><div><code><?php echo h((string)($chart['start_at'] ?? '')); ?></code> → <code><?php echo h((string)($chart['end_at'] ?? '')); ?></code></div></div>
                        </div>

                        <div class="row muted">Samples per bucket</div>
                        <?php echo cluster_svg_line_chart((array)($chart['samples'] ?? []), '#5aa3ff'); ?>

                        <div class="row muted">Avg 1m load per bucket</div>
                        <?php echo cluster_svg_line_chart((array)($chart['load1m'] ?? []), '#f2a93b'); ?>

                        <div class="row muted">Avg memory usage % per bucket</div>
                        <?php echo cluster_svg_line_chart((array)($chart['mem_pct'] ?? []), '#29c36a'); ?>

                        <div class="legend">
                            <span><span class="legend-dot" style="background:#5aa3ff"></span>samples</span>
                            <span><span class="legend-dot" style="background:#f2a93b"></span>avg load 1m</span>
                            <span><span class="legend-dot" style="background:#29c36a"></span>avg mem %</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>How To Connect 3 Local Servers</h2>
        <ol>
            <li>Choose one node as your cluster receiver (for example <code>http://192.168.1.10</code>).</li>
            <li>Use /admin/admin_API.php to create an API key with <code>server</code> scope, stored at <code>/web/private/api_keys.json</code>.</li>
            <li>On each joining node, save the receiver URL + key above in this page.</li>
            <li>On each joining node, run <code>/web/html/src/scripts/server_register.sh</code> (it will auto-load <code><?php echo h($cfgPath); ?></code>).</li>
            <li>Keep that script running by cron <code>@reboot</code> or systemd so heartbeats continue.</li>
            <li>Verify from any admin node by calling <code>/v1/servers/list</code> with the same key.</li>
        </ol>

<pre>{
  "srv-cluster-1": {"active": true, "scopes": ["server","health"], "name": "Cluster Server Key"}
}</pre>

<pre>bash /web/html/src/scripts/server_register.sh</pre>

<pre># Optional one-time override (takes precedence over saved config)
AGENTHIVE_URL=http://192.168.1.10 AGENTHIVE_API_KEY=srv-cluster-1 \
bash /web/html/src/scripts/server_register.sh</pre>

<pre>curl -s -H "X-API-Key: srv-cluster-1" \
http://192.168.1.10/v1/servers/list</pre>

        <div class="row muted">
            If you get <code>missing_server_scope</code>, your API key is valid but missing the <code>server</code> scope.
        </div>
    </div>

    <?php if ($run && $result && $result['raw'] !== ''): ?>
    <div class="panel">
        <h3>Raw Response</h3>
        <pre><?php echo h($result['raw']); ?></pre>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
