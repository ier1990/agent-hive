<?php
/*
Purpose: status overview for agent-hive
Includes: security mode, API keys, AI settings, DB health, model registry,
          and optional live probe to the configured AI base URL.
*/

// --- headers / CORS ---
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

// --- bootstrap ---
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

api_guard_once('status', false);

// Globals from bootstrap
global $SECURITY_MODE, $requireKeyForAll, $ALLOW_IPS_WITHOUT_KEY;

// Version
$versionFile = dirname(__DIR__, 2) . '/VERSION';
$version = is_readable($versionFile) ? trim((string)file_get_contents($versionFile)) : env('IER_VERSION', 'dev');

// AI settings
$ai_settings = function_exists('ai_settings_get') ? ai_settings_get() : [];
$ai_provider = (string)($ai_settings['provider'] ?? $ai_settings['backend'] ?? 'local');
$ai_base_url = (string)($ai_settings['base_url'] ?? '');
$ai_model = (string)($ai_settings['model'] ?? '');
$ai_key = (string)($ai_settings['api_key'] ?? '');
if ($ai_key === '') {
  $ai_key = (string)env('OPENAI_API_KEY', '');
  if ($ai_key === '') $ai_key = (string)env('LLM_API_KEY', '');
}
$ai_key_set = ($ai_key !== '');
$ai_settings_db = function_exists('ai_settings_db_path') ? ai_settings_db_path() : '/web/private/db/codewalker_settings.db';

$resp = [
  'ok'       => true,
  'service'  => defined('IER_SERVICE_NAME') ? IER_SERVICE_NAME : 'agent-hive',
  'endpoint' => 'v1/status',
  'time'     => gmdate('c'),
  'host'     => gethostname(),
  'php'      => PHP_VERSION,
  'version'  => $version,
  'your_ip'  => get_client_ip_trusted(),
  'security' => [
    'mode' => $SECURITY_MODE ?? env('SECURITY_MODE', 'lan'),
    'require_key_for_all' => (bool)($requireKeyForAll ?? false),
    'allow_ips_without_key' => is_array($ALLOW_IPS_WITHOUT_KEY ?? null) ? $ALLOW_IPS_WITHOUT_KEY : [],
  ],
  'api_keys' => [
    'file' => defined('API_KEYS_FILE') ? API_KEYS_FILE : '/web/private/api_keys.json',
    'readable' => false,
    'count' => 0,
    'active' => 0,
  ],
  'ai' => [
    'provider' => $ai_provider,
    'base_url' => $ai_base_url,
    'model' => $ai_model,
    'api_key_set' => $ai_key_set,
    'settings_db' => $ai_settings_db,
    'settings_db_ok' => is_readable($ai_settings_db),
  ],
  'checks' => [
    'model_db' => [
      'path' => '/web/private/db/ai_models.db',
      'ok' => false,
      'count' => 0,
    ],
    'api_sqlite' => [
      'path' => '/web/private/db/api.sqlite',
      'ok' => false,
      'jobs_table' => false,
      'jobs' => [],
    ],
    'inbox_db_dir' => [
      'path' => '/web/private/db/inbox',
      'ok' => false,
      'count' => 0,
    ],
    'logs_dir' => [
      'path' => '/web/private/logs',
      'ok' => is_dir('/web/private/logs'),
      'writable' => is_writable('/web/private/logs'),
    ],
  ],
  'models' => [],
];

// API keys stats
$keysFile = $resp['api_keys']['file'];
if (is_readable($keysFile)) {
  $resp['api_keys']['readable'] = true;
  $map = json_decode((string)file_get_contents($keysFile), true);
  if (is_array($map)) {
    $resp['api_keys']['count'] = count($map);
    $active = 0;
    foreach ($map as $k => $v) {
      if (is_array($v) && array_key_exists('active', $v)) {
        if ($v['active']) $active++;
      } else {
        $active++;
      }
    }
    $resp['api_keys']['active'] = $active;
  }
}

// Model registry DB status + sample models
$model_db = $resp['checks']['model_db']['path'];
if (is_readable($model_db)) {
  try {
    $pdo = new PDO("sqlite:$model_db", null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("PRAGMA busy_timeout=3000;");
    $row = $pdo->query("SELECT COUNT(*) AS cnt FROM ai_models")->fetch();
    $resp['checks']['model_db']['ok'] = true;
    $resp['checks']['model_db']['count'] = (int)($row['cnt'] ?? 0);

    $rows = $pdo->query("SELECT model_name, source, endpoint, param_size, quantization, family, notes FROM ai_models ORDER BY model_name ASC LIMIT 20")->fetchAll();
    foreach ($rows as $r) {
      $model = [
        'name' => $r['model_name'] ?? '',
        'backend' => $r['source'] ?? '',
      ];
      if (!empty($r['endpoint'])) $model['endpoint'] = $r['endpoint'];
      if (!empty($r['param_size'])) $model['param_size'] = $r['param_size'];
      if (!empty($r['quantization'])) $model['quantization'] = $r['quantization'];
      if (!empty($r['family'])) $model['family'] = $r['family'];
      if (!empty($r['notes'])) {
        if (preg_match('/GPU:\s*([^;]+)/i', $r['notes'], $m)) { $model['gpu'] = trim($m[1]); }
        if (preg_match('/Load:\s*([\d.]+)/i', $r['notes'], $m)) { $model['load'] = (float)$m[1]; }
      }
      $resp['models'][] = $model;
    }
  } catch (Throwable $e) {
    $resp['checks']['model_db']['err'] = $e->getMessage();
  }
} else {
  $resp['checks']['model_db']['err'] = 'file not readable';
}

// API queue DB status (api.sqlite)
$api_db = $resp['checks']['api_sqlite']['path'];
if (is_readable($api_db)) {
  try {
    $pdo = new PDO("sqlite:$api_db", null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("PRAGMA busy_timeout=3000;");
    $resp['checks']['api_sqlite']['ok'] = true;
    $row = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='jobs'")->fetch();
    if ($row) {
      $resp['checks']['api_sqlite']['jobs_table'] = true;
      $rows = $pdo->query("SELECT status, COUNT(*) AS cnt FROM jobs GROUP BY status")->fetchAll();
      foreach ($rows as $r) {
        $resp['checks']['api_sqlite']['jobs'][$r['status']] = (int)$r['cnt'];
      }
    }
  } catch (Throwable $e) {
    $resp['checks']['api_sqlite']['err'] = $e->getMessage();
  }
} else {
  $resp['checks']['api_sqlite']['err'] = 'file not readable';
}

// Inbox DB directory (ingest storage)
$inboxDir = $resp['checks']['inbox_db_dir']['path'];
if (is_dir($inboxDir)) {
  $resp['checks']['inbox_db_dir']['ok'] = true;
  $files = glob($inboxDir . '/*');
  $resp['checks']['inbox_db_dir']['count'] = is_array($files) ? count($files) : 0;
} else {
  $resp['checks']['inbox_db_dir']['err'] = 'dir not found';
}

// Optional: live probe to AI base URL
if (!empty($_GET['probe'])) {
  $probe = [
    'ok' => false,
    'url' => '',
    'ms' => null,
    'code' => null,
  ];
  if ($ai_base_url !== '') {
    $probeUrl = rtrim($ai_base_url, '/') . '/models';
    $probe['url'] = $probeUrl;
    if (!function_exists('curl_init')) {
      $probe['error'] = 'curl extension not installed';
    } else {
      $ch = curl_init($probeUrl);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_CONNECTTIMEOUT => 2,
      ]);
      $t0 = microtime(true);
      $body = curl_exec($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err = curl_error($ch);
      curl_close($ch);
      $probe['ms'] = (int)round((microtime(true) - $t0) * 1000);
      $probe['code'] = $code;
      $probe['ok'] = ($body !== false && $code >= 200 && $code < 500);
      if ($err) $probe['error'] = $err;
    }
  } else {
    $probe['error'] = 'ai_base_url not configured';
  }
  $resp['ai_probe'] = $probe;
}

// Overall ok flag
foreach ($resp['checks'] as $chk) {
  if (isset($chk['ok']) && $chk['ok'] === false) {
    $resp['ok'] = false;
  }
}

http_response_code($resp['ok'] ? 200 : 503);
echo json_encode($resp, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n";