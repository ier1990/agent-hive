<?php
// /web/api.iernc.net/public_html/v1/index.php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once dirname(__DIR__) . '/lib/bootstrap.php';

// Path parsing
$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$path = ltrim(substr($uri, strlen($base)), '/');
$path = $path !== '' ? $path : 'health';

// Block lib/ direct hits
if (str_starts_with($path, 'lib/')) {
  http_response_code(404);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error'=>'not_found']);
  exit;
}

// Treat empty or "index" as the health endpoint
if ($path === '' || $path === 'index') {
  $path = 'health';
}

// Choose which endpoints skip auth/rate-limit
//$unguarded = ['health','ping','whatip'];
$unguarded = ['health','ping'];
if (!in_array($path, $unguarded, true)) {
  // Needs tools for these:
  $needsTools = in_array($path, ['chat','chat_async','worker'], true);
  api_guard($path, $needsTools);
}

// Dispatch
$file = __DIR__ . "/{$path}.php";
if (is_file($file)) { require $file; exit; }

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error'=>'not_found','endpoint'=>$path], JSON_UNESCAPED_SLASHES);
