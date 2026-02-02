<?php
// /web/html/v1/health.php

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

// Optional: keep health open (recommended). If you want to restrict later:
// api_guard('health', false);

// Basic headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$resp = [
  'ok'       => true,
  'service'  => defined('IER_SERVICE_NAME') ? IER_SERVICE_NAME : 'iernc-api',
  'endpoint' => 'v1/health',
  'time'     => gmdate('c'),
  'host'     => gethostname(),
  'php'      => PHP_VERSION,
  'your_ip'  => get_client_ip_trusted(),
  'checks'   => [],
];
$resp['role'] = env('IER_ROLE', 'unknown');
$resp['node'] = env('IER_NODE', 'unknown');
$resp['version'] = env('IER_VERSION', 'dev');

// --------------------
// Check: model registry DB (read-only count)
// --------------------
$model_db = '/web/private/db/ai_models.db';
$resp['checks']['model_db'] = [
  'ok' => false,
  'path' => $model_db,
];

if (is_readable($model_db)) {
  try {
    $pdo = new PDO("sqlite:$model_db", null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("PRAGMA busy_timeout=5000;");
    // WAL is fine, but avoid changing journal mode on every request
    $row = $pdo->query("SELECT COUNT(*) AS cnt FROM ai_models")->fetch();
    $resp['checks']['model_db']['ok'] = true;
    $resp['checks']['model_db']['count'] = (int)($row['cnt'] ?? 0);
  } catch (Throwable $e) {
    $resp['checks']['model_db']['err'] = $e->getMessage();
  }
} else {
  $resp['checks']['model_db']['err'] = 'file not readable';
}

// --------------------
// Check: api.db writable + heartbeat
// Instead of infinite INSERT growth, keep a single-row heartbeat.
// --------------------
$api_db = '/web/private/db/api.db';
$resp['checks']['api_db'] = [
  'ok' => false,
  'path' => $api_db,
];

try {
  $pdo = new PDO("sqlite:$api_db", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec("PRAGMA busy_timeout=5000;");

  // single-row heartbeat table
  $pdo->exec("CREATE TABLE IF NOT EXISTS _health_one(
    id INTEGER PRIMARY KEY,
    ts TEXT
  )");

  // upsert row id=1
  $pdo->exec("INSERT INTO _health_one(id, ts) VALUES(1, datetime())
              ON CONFLICT(id) DO UPDATE SET ts=excluded.ts");

  $row = $pdo->query("SELECT ts AS last FROM _health_one WHERE id=1")->fetch();

  $resp['checks']['api_db']['ok'] = true;
  $resp['checks']['api_db']['last'] = $row['last'] ?? null;
} catch (Throwable $e) {
  $resp['checks']['api_db']['err'] = $e->getMessage();
}

// --------------------
// LLM status (don’t call LLM here by default)
// You already have hourly model DB updates; report that.
// --------------------
$resp['checks']['llm'] = [
  'ok' => true,
  'mode' => 'skipped',
  'detail' => 'using hourly updated model db',
];

// Overall ok?
foreach ($resp['checks'] as $k => $chk) {
  if (isset($chk['ok']) && $chk['ok'] === false) {
    $resp['ok'] = false;
  }
}

http_response_code($resp['ok'] ? 200 : 503);
echo json_encode($resp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
