<?php
// /web/html/v1/health/index.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function health_read_json_file(string $path): ?array {
    if (!is_file($path) || !is_readable($path)) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return null;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function health_sqlite_exists(string $path): bool {
    return is_file($path) && is_readable($path);
}

function health_sqlite_fetch_one(string $path, string $sql): ?array {
    if (!health_sqlite_exists($path)) return null;
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout=2000');
        $row = $pdo->query($sql)->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

$privateRoot = defined('PRIVATE_ROOT') ? (string)PRIVATE_ROOT : '/web/private';
$apiKeysPath = defined('API_KEYS_FILE') ? (string)API_KEYS_FILE : (rtrim($privateRoot, '/\\') . '/api_keys.json');

$resp = [
    'ok' => true,
    'service' => defined('APP_SERVICE_NAME') ? APP_SERVICE_NAME : 'iernc-api',
    'endpoint' => 'v1/health',
    'time' => gmdate('c'),
    'host' => gethostname(),
    'php' => PHP_VERSION,
    'your_ip' => function_exists('get_client_ip_trusted') ? get_client_ip_trusted() : ($_SERVER['REMOTE_ADDR'] ?? null),
    'version' => (string)env('APP_VERSION', 'dev'),
    'checks' => [],
];

// Core filesystem/runtime checks
$resp['checks']['private_root'] = [
    'ok' => is_dir($privateRoot) && is_writable($privateRoot),
    'path' => $privateRoot,
    'exists' => is_dir($privateRoot),
    'writable' => is_writable($privateRoot),
];

$logsDir = rtrim($privateRoot, '/\\') . '/logs';
$locksDir = rtrim($privateRoot, '/\\') . '/locks';
$resp['checks']['runtime_dirs'] = [
    'ok' => is_dir($logsDir) && is_writable($logsDir) && is_dir($locksDir) && is_writable($locksDir),
    'logs_dir' => ['path' => $logsDir, 'exists' => is_dir($logsDir), 'writable' => is_writable($logsDir)],
    'locks_dir' => ['path' => $locksDir, 'exists' => is_dir($locksDir), 'writable' => is_writable($locksDir)],
];

// API key map
$apiKeys = health_read_json_file($apiKeysPath);
$activeKeyCount = 0;
if (is_array($apiKeys)) {
    foreach ($apiKeys as $meta) {
        if (!is_array($meta) || !array_key_exists('active', $meta) || $meta['active'] !== false) $activeKeyCount++;
    }
}
$resp['checks']['api_keys'] = [
    'ok' => is_array($apiKeys),
    'path' => $apiKeysPath,
    'active_keys' => $activeKeyCount,
];
if (!is_array($apiKeys)) {
    $resp['checks']['api_keys']['err'] = 'missing_or_invalid_json';
}

// Search cache health
$searchDb = rtrim($privateRoot, '/\\') . '/db/memory/search_cache.db';
$searchRow = health_sqlite_fetch_one($searchDb, "SELECT COUNT(*) AS rows, MAX(cached_at) AS last_cached_at FROM search_cache_history");
$resp['checks']['search_cache'] = [
    'ok' => health_sqlite_exists($searchDb),
    'path' => $searchDb,
];
if (is_array($searchRow)) {
    $resp['checks']['search_cache']['rows'] = (int)($searchRow['rows'] ?? 0);
    $resp['checks']['search_cache']['last_cached_at'] = (string)($searchRow['last_cached_at'] ?? '');
}

// Cluster health (if enabled/used)
$clusterDb = rtrim($privateRoot, '/\\') . '/db/cluster.db';
$clusterRow = health_sqlite_fetch_one($clusterDb, "SELECT COUNT(*) AS servers, MAX(last_seen) AS last_seen FROM servers");
$samplesRow = health_sqlite_fetch_one($clusterDb, "SELECT COUNT(*) AS samples_24h FROM heartbeat_samples WHERE sampled_at >= datetime('now','-1 day')");
$resp['checks']['cluster'] = [
    'ok' => health_sqlite_exists($clusterDb),
    'path' => $clusterDb,
];
if (is_array($clusterRow)) {
    $resp['checks']['cluster']['servers'] = (int)($clusterRow['servers'] ?? 0);
    $resp['checks']['cluster']['last_seen'] = (string)($clusterRow['last_seen'] ?? '');
}
if (is_array($samplesRow)) {
    $resp['checks']['cluster']['samples_24h'] = (int)($samplesRow['samples_24h'] ?? 0);
}

// Cron dispatcher health
$cronDb = rtrim($privateRoot, '/\\') . '/db/memory/cron_dispatcher.db';
$cronRow = health_sqlite_fetch_one($cronDb, "SELECT COUNT(*) AS enabled_tasks, MAX(last_run_at) AS last_run_at FROM cron_tasks WHERE enabled = 1");
$resp['checks']['cron_dispatcher'] = [
    'ok' => health_sqlite_exists($cronDb),
    'path' => $cronDb,
];
if (is_array($cronRow)) {
    $resp['checks']['cron_dispatcher']['enabled_tasks'] = (int)($cronRow['enabled_tasks'] ?? 0);
    $resp['checks']['cron_dispatcher']['last_run_at_unix'] = isset($cronRow['last_run_at']) ? (int)$cronRow['last_run_at'] : 0;
}

foreach ($resp['checks'] as $chk) {
    if (is_array($chk) && isset($chk['ok']) && $chk['ok'] === false) {
        $resp['ok'] = false;
    }
}

http_response_code($resp['ok'] ? 200 : 503);
echo json_encode($resp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

