<?php
// Lightweight front for LM Studio /responses with optional tools
exit; // disable for now


// --- CORS and meta headers ---
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json; charset=utf-8');
header('X-Chat-Handler: responses.php');
if (!defined('JSON_FLAGS')) {
  define('JSON_FLAGS', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$dbPath    = getenv('IERNC_SEARCH_DB_PATH') ?: '';

// Return JSON on fatals so errors are visible
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'fatal', 'detail' => $e['message']], JSON_FLAGS);
  }
});

// Preflight
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// Quick self-test: GET /v1/responses?ping=1
if (isset($_GET['ping'])) {
  echo json_encode([
    'ok' => true,
    'who' => 'responses.php',
    'php' => PHP_VERSION,
    'cwd' => getcwd(),
  ], JSON_FLAGS);
  exit;
}

// ---- Bootstrap (auth/logging/rate limits inside) ----
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
api_guard_once('responses', /* needsTools? */ false);

// ---- Small helpers ----
function respond_error(int $code, string $msg, array $extra = []): void {
  http_response_code($code);
  echo json_encode(['error' => $msg] + $extra, JSON_FLAGS);
  exit;
}

function http_post_json(string $url, array $payload, array $headers = [], int $connectTimeout = 2, int $timeout = 900): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_FLAGS),
    CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    CURLOPT_CONNECTTIMEOUT => $connectTimeout,
    CURLOPT_TIMEOUT => $timeout,
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  return ['code' => (int)$code, 'body' => (string)$body, 'err' => (string)$err];
}

function is_admin_ip(): bool {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  // Allow env-admin list like "192.168.0.210,127.0.0.1"
  $env = getenv('ADMIN_IPS') ?: '';
  $list = array_filter(array_map('trim', explode(',', $env)));
  if ($list && in_array($ip, $list, true)) return true;
  // Fallback: treat local/private ranges as admin for debug convenience
  if ($ip === '127.0.0.1' || $ip === '::1') return true;
  if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip)) return true;
  return false;
}

function out_or_error(array $resp): void {
  $code  = (int)($resp['code'] ?? 0);
  $err   = (string)($resp['err']  ?? '');
  $body  = (string)($resp['body'] ?? '');
  if ($err || $code >= 400 || $body === '') {
    $extra = ['code' => $code, 'detail' => $err ?: substr($body, 0, 800)];
    if (is_admin_ip()) {
      // Include raw backend body for admin to aid debugging
      $extra['raw'] = $body;
    }
    respond_error(502, 'lmstudio_failed', $extra);
  }
  echo $body;
  exit;
}

function read_json_body(): array {
  if (function_exists('request_json')) {
    $b = request_json();
    return is_array($b) ? $b : [];
  }
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function normalize_messages($messages): array {
  if (!is_array($messages)) return [];
  $out = [];
  foreach ($messages as $m) {
    if (is_string($m)) {
      $out[] = ['role' => 'user', 'content' => $m];
    } elseif (is_array($m)) {
      $role = $m['role'] ?? 'user';
      $content = $m['content'] ?? '';
      if (!is_string($content)) $content = json_encode($content);
      $out[] = ['role' => $role, 'content' => $content];
    }
  }
  return $out;
}

// ---- Read and validate input ----
$body = read_json_body();

// Source of truth: CodeWalker settings DB (admin AI Config).
$cw = function_exists('codewalker_llm_settings') ? codewalker_llm_settings() : [];
$cwBackend = strtolower((string)($cw['backend'] ?? 'lmstudio'));
$cwBaseUrl = (string)($cw['base_url'] ?? '');
$cwApiKey  = (string)($cw['api_key'] ?? '');
$cwModel   = (string)($cw['model'] ?? '');
$cwTimeout = (int)($cw['model_timeout_seconds'] ?? 0);
if ($cwTimeout < 1) $cwTimeout = 900;

$backend  = strtolower($body['backend'] ?? 'lmstudio');
$model    = is_string($body['model'] ?? null) ? $body['model'] : '';
$stream   = (bool)($body['stream'] ?? false);
$temperature = (float)($body['temperature'] ?? 0.2);
$runTools = (bool)($body['run_tools'] ?? true);
$tools    = $body['tools'] ?? [];
// Extended knobs
$max_tokens_in        = $body['max_tokens'] ?? null;
$max_output_tokens_in = $body['max_output_tokens'] ?? null;
$tool_choice          = $body['tool_choice'] ?? null;              // e.g., 'auto'|'none'|{function:...}
$parallel_tool_calls  = $body['parallel_tool_calls'] ?? null;      // bool
$stop_in              = $body['stop'] ?? null;                     // string|array|null
$top_p                = $body['top_p'] ?? null;                    // float
$presence_penalty     = $body['presence_penalty'] ?? null;         // float
$frequency_penalty    = $body['frequency_penalty'] ?? null;        // float

$LLM_BASE_URL = $cwBaseUrl;
$LLM_API_KEY  = $cwApiKey;
$MCP_SERVER   = getenv('MCP_SERVER')   ?: '';

if ($backend !== 'lmstudio') {
  // This endpoint only supports LM Studio right now.
  $backend = 'lmstudio';
}

if (!$LLM_BASE_URL) {
  respond_error(503, 'lmstudio_unavailable', ['hint' => 'Configure base_url in Admin → AI Config (codewalker settings DB).']);
}

// Convenience: cron mode can just send q (or make/model/part)
// If neither input nor messages provided, but we have a search query, derive a default instruction
$q = $body['q'] ?? ($_GET['q'] ?? null);
$make  = $body['make']  ?? ($_GET['make']  ?? null);
$modelN= $body['model_n'] ?? $body['part'] ?? ($_GET['model'] ?? null);
if (!$q) {
  $parts = array_filter([$make, $modelN], function ($v) {
    return is_string($v) && trim($v) !== '';
  });
  if ($parts) $q = trim(implode(' ', $parts));
}

// Allow either `input` or `messages` from caller
$messages = normalize_messages($body['messages'] ?? null);
$input = $body['input'] ?? null;
if ($input === null && $messages) {
  // Derive a single string input from the chat turns
  $lines = array_map(function ($m) {
    return ($m['role'] ?? 'user') . ': ' . $m['content'];
  }, $messages);
  $input = implode("\n", $lines);
}
if ($input === null && !$messages && $q) {
  // Opinionated default instruction for summarizing a part number via web_search
  $input = "Use the web_search tool to research this query, then produce a concise, neutral summary with key specs and variants.\nQuery: \"" . $q . "\"";
  $runTools = true;
  if ($tool_choice === null) $tool_choice = 'auto';
  if ($max_output_tokens_in === null && $max_tokens_in === null) $max_output_tokens_in = 500;
}

if (!$model) {
  // Reasonable default that ships with LM Studio
  $model = $cwModel !== '' ? $cwModel : 'openai/gpt-oss-20b';
}

if ($input === null && !$messages) {
  respond_error(400, 'input_or_messages_required');
}

// Normalize tools: allow simple string list or full definitions
if ($tools && is_array($tools)) {
  $tools = array_map(function ($t) {
    if (is_string($t)) {
      $tool = ['type' => 'mcp', 'name' => $t];
      return $tool;
    }
    return $t;
  }, $tools);
} else {
  // Provide a handy default for local retrieval/web utils if caller sent none
  $def = ['type' => 'mcp', 'name' => 'web_search'];
  if ($MCP_SERVER) $def['server'] = $MCP_SERVER; // optional hint if LM Studio uses server name
  $tools = [$def];
}

// Build LM Studio payload for /responses (supports tools + retrieval)
$payload = [
  'model' => $model,
  'temperature' => $temperature,
  'tools' => $tools,
  'run_tools' => $runTools,
  'stream' => $stream,
];
if (!isset($payload['tool_choice']) && $tool_choice === null) {
  $payload['tool_choice'] = 'auto';
}
// Map extended knobs (prefer max_output_tokens over max_tokens if both)
if ($max_output_tokens_in !== null) {
  $payload['max_output_tokens'] = (int)$max_output_tokens_in;
} elseif ($max_tokens_in !== null) {
  $payload['max_output_tokens'] = (int)$max_tokens_in; // alias
}
if ($tool_choice !== null)        $payload['tool_choice'] = $tool_choice;
if ($parallel_tool_calls !== null) $payload['parallel_tool_calls'] = (bool)$parallel_tool_calls;
if ($top_p !== null)               $payload['top_p'] = (float)$top_p;
if ($presence_penalty !== null)    $payload['presence_penalty'] = (float)$presence_penalty;
if ($frequency_penalty !== null)   $payload['frequency_penalty'] = (float)$frequency_penalty;
if ($stop_in !== null) {
  $payload['stop'] = is_array($stop_in) ? array_values($stop_in) : [(string)$stop_in];
}
if ($input !== null) $payload['input'] = $input;
if ($messages) $payload['messages'] = $messages; // included for compatibility

$base = rtrim($LLM_BASE_URL, '/');
if (!preg_match('~/v1$~', $base)) $base .= '/v1';
$url = $base . '/responses';
$headers = [];
if ($LLM_API_KEY) $headers[] = 'Authorization: Bearer ' . $LLM_API_KEY;

// Quick debug preview: only for admin and when debug/dry_run is requested
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip == '24.167.157.209') {$debug_requested = true;} else {$debug_requested = false;}
 	
//$debug_requested = isset($_GET['debug']) || !empty($body['debug']) || isset($_GET['dry_run']) || !empty($body['dry_run']);
if ($debug_requested) {
  // Redact sensitive headers
  $dbgHeaders = $headers;
  foreach ($dbgHeaders as &$h) {
    if (stripos($h, 'authorization:') === 0) {
      $h = 'Authorization: Bearer ***redacted***';
    }
  }
  $preview = [
    'url' => $url,
    'headers' => $dbgHeaders,
    'payload' => $payload,
    'note' => 'dry_run preview only (no request performed). Remove debug/dry_run to send.'
  ];
  error_log("LM Studio /responses preview: " . json_encode($preview, JSON_FLAGS));
  echo json_encode($preview, JSON_FLAGS);
  exit;
}

$resp = http_post_json($url, $payload, $headers, 2, $cwTimeout);
out_or_error($resp);
// no return; out_or_error exits
