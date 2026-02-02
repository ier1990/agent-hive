<?php
// /web/html/v1/ping.php

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

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
  'ok' => true,
  'service' => 'iernc-api',
  'endpoint' => 'v1/ping',
  'time' => gmdate('c'),
  'host' => gethostname(),
  'server_ip' => $_SERVER['SERVER_ADDR'] ?? null,
  'your_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
  'method' => $method,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
