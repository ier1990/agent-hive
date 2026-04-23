<?php
// /web/html/v1/ping.php
declare(strict_types=1);

// ----------
// CORS (simple + future-safe)
// ----------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Vary: Origin');
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Access-Control-Max-Age: 86400');

// No caching for health checks
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// Only allow GET/POST (POST useful for clients that can’t GET)
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
  http_response_code(405);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok' => false,
    'error' => 'method_not_allowed',
    'allowed' => ['GET', 'POST', 'OPTIONS']
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

// ----------
// Bootstrap (optional)
// ----------
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
api_guard('ping', false); // enable later when you want auth gating

function ping_client_ip(): string {
  if (function_exists('get_client_ip_trusted')) {
    return (string)get_client_ip_trusted();
  }
  return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function ping_verbose_allowed(string $clientIp): bool {
  global $ALLOW_IPS_WITHOUT_KEY;
  $allow = is_array($ALLOW_IPS_WITHOUT_KEY) ? $ALLOW_IPS_WITHOUT_KEY : [];
  if ($clientIp === '') {
    return false;
  }
  if (function_exists('ip_in_list') && ip_in_list($clientIp, $allow)) {
    return true;
  }
  return $clientIp === '127.0.0.1' || $clientIp === '::1';
}

header('Content-Type: application/json; charset=utf-8');

$clientIp = ping_client_ip();
$wantVerbose = isset($_GET['verbose']) && $_GET['verbose'] === '1';
$canSeeVerbose = ping_verbose_allowed($clientIp);

$resp = [
  'ok' => true,
  'service' => defined('APP_SERVICE_NAME') ? (string)APP_SERVICE_NAME : 'iernc-api',
  'your_ip' => $clientIp,
];

if ($wantVerbose && $canSeeVerbose) {
  $resp['endpoint'] = 'v1/ping';
  $resp['time'] = gmdate('c');
  $resp['host'] = gethostname();
  $resp['server_ip'] = $_SERVER['SERVER_ADDR'] ?? null;
  $resp['method'] = $method;
  $resp['verbose'] = true;
}

echo json_encode($resp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
