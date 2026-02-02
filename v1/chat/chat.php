<?php
// --- BEGIN SELFTEST HEADER ---
header('X-Auto-Prepend: ' . (ini_get('auto_prepend_file') ?: 'none'));
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json; charset=utf-8');
header('X-Chat-Handler: chat.php');
header('X-Chat-Build: 2025-09-30T20:50Z');

// Turn fatals into JSON so we SEE why it’s 500
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'fatal', 'detail' => $e['message']]);
  }
});

// GET /v1/chat?ping=1  (does not require auth)
if (isset($_GET['ping'])) {
  echo json_encode([
    'ok' => true,
    'who' => 'chat.php',
    'cwd' => getcwd(),
    '__DIR__' => __DIR__,
    'php' => PHP_VERSION,
  ]);
  exit;
}
// --- END SELFTEST HEADER ---

// ===== Bootstrap =====
// chat.php
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

// Single-call guard (rate limits, auth, logging should be inside bootstrap.php)
api_guard_once('chat', /* needsTools? */ false);

// ---- Helpers ----
if (!function_exists('http_get_json')) {
  function http_get_json(string $url, array $headers = [], int $ct=2, int $t=10): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER     => $headers ?: ['Content-Type: application/json'],
      CURLOPT_CONNECTTIMEOUT => $ct,
      CURLOPT_TIMEOUT        => $t,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code'=>(int)$code,'body'=>(string)$body,'err'=>(string)$err];
  }
}

if (!function_exists('http_post_json')) {
  function http_post_json(string $url, array $payload, array $headers = [], int $connectTimeout = 2, int $timeout = 900): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
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
}

if (!function_exists('out_or_error')) {
  function out_or_error(array $resp): void {
    $code  = (int)($resp['code'] ?? 0);
    $err   = (string)($resp['err']  ?? '');
    $body  = (string)($resp['body'] ?? '');
    if ($err || $code >= 400 || $body === '') {
      respond_error(502, 'lmstudio_failed', ['code' => $code, 'detail' => $err ?: substr($body, 0, 500)]);
    }
    echo $body; exit;
  }
}

function respond_error(int $code, string $msg, array $extra = []) {
  http_response_code($code);
  echo json_encode(['error' => $msg] + $extra);
  exit;
}

function read_json_body(): array {
  // Prefer a bootstrap helper if it exists
  if (function_exists('request_json')) {
    $b = request_json();
    return is_array($b) ? $b : [];
  }
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function normalize_messages($messages): array {
  // Accept:
  // - OpenAI-style: [{role, content}, ...]
  // - Simple list of strings: ["hi", "how are you?"] -> each as user
  if (!is_array($messages)) return [];
  $out = [];
  foreach ($messages as $m) {
    if (is_string($m)) {
      $out[] = ['role' => 'user', 'content' => $m];
    } elseif (is_array($m)) {
      $role = $m['role'] ?? 'user';
      // Allow {content: "..."} without role
      $content = $m['content'] ?? '';
      if (!is_string($content)) {
        $content = json_encode($content);
      }
      $out[] = ['role' => $role, 'content' => $content];
    }
  }
  return $out;
}


// ---- Read input ----
$body = read_json_body();

$backend  = strtolower($body['backend'] ?? 'auto'); // openai|ollama|lmstudio|auto
$model    = $body['model']   ?? null;               // e.g. gpt-4o-mini, llama3.1:8b, etc.
$runTools = !empty($body['run_tools']);             // optional single-pass tool exec
$tools    = $body['tools']   ?? [];                 // optional tool defs (names/args)
$stream   = (bool)($body['stream'] ?? false);       // streaming not implemented here
$temperature = $body['temperature'] ?? 0.2;

// Normalize messages
$messages = normalize_messages($body['messages'] ?? null);
if (!$messages) respond_error(400, 'messages[] required');

// ---- Config ----
// Source of truth: CodeWalker settings DB (admin AI Config).
$cw = function_exists('codewalker_llm_settings') ? codewalker_llm_settings() : [];
$cwBackend = strtolower((string)($cw['backend'] ?? 'lmstudio'));
$cwBaseUrl = (string)($cw['base_url'] ?? '');
$cwApiKey  = (string)($cw['api_key'] ?? '');
$cwModel   = (string)($cw['model'] ?? '');
$cwTimeout = (int)($cw['model_timeout_seconds'] ?? 0);
if ($cwTimeout < 1) $cwTimeout = 900;

$OPENAI_API_KEY = ($cwBackend === 'openai') ? $cwApiKey : '';
$OLLAMA_HOST    = ($cwBackend === 'ollama') ? ($cwBaseUrl ?: 'http://127.0.0.1:11434') : 'http://127.0.0.1:11434';
$LLM_BASE_URL   = ($cwBackend === 'lmstudio' || $cwBackend === 'openai_compat' || $cwBackend === 'openai-compat') ? $cwBaseUrl : '';
$LLM_API_KEY    = ($cwBackend === 'lmstudio' || $cwBackend === 'openai_compat' || $cwBackend === 'openai-compat') ? $cwApiKey : '';

// ---- Choose backend ----
if ($backend === 'auto') {
  // Use CodeWalker backend directly.
  if ($cwBackend === 'openai_compat' || $cwBackend === 'openai-compat') $backend = 'lmstudio';
  else $backend = $cwBackend ?: 'lmstudio';
}

// Reject streaming for now (implement server-sent events later)
if ($stream) {
  respond_error(400, 'stream_not_supported', ['hint' => 'Set "stream": false']);
}

// ---- LM STUDIO (OpenAI-compatible local server) ----
if ($backend === 'lmstudio') {
  if (!$LLM_BASE_URL) respond_error(500, 'LLM_BASE_URL missing');

  $base = rtrim($LLM_BASE_URL, '/');

  if (!preg_match('~/v1$~', $base)) { 
    $base .= '/v1'; 
  }
  $endpoint = $base . '/chat/completions';



$modelsResp = http_get_json($base . '/models', ['Authorization: Bearer ' . ($LLM_API_KEY ?: 'lm-studio')], 1, 3);
$avail = json_decode($modelsResp['body'] ?? '[]', true);
$ids = array_map(function ($m) {
  return $m['id'] ?? '';
}, $avail['data'] ?? []);

if (empty($ids)) {
  respond_error(502, 'lmstudio_no_models_loaded', [
    'hint' => 'Run: lms load "<model_key>" --identifier "google/gemma-3-4b" --gpu auto',
  ]);
}

if (($model === null || $model === '') && $cwModel !== '') {
  $model = $cwModel;
}

if ($model && !in_array($model, $ids, true)) {
  respond_error(404, 'model_not_found', [
    'requested' => $model,
    'available' => $ids,
    'hint' => 'Use an alias (e.g., gemma3:4b) or load the model with that identifier.',
  ]);
}








  if ($model === null || $model === '') $model = ($cwModel !== '' ? $cwModel : 'default');

  $payload = [
    'model'       => $model,
    'messages'    => $messages,
    'temperature' => $temperature,
  ];
  if (!empty($tools)) $payload['tools'] = $tools;

  $headers = [
    'Authorization: Bearer ' . ($LLM_API_KEY ?: 'lm-studio'),
    'Content-Type: application/json',
  ];

  // If bootstrap doesn’t define these, add the simple fallbacks I posted earlier
  $resp = http_post_json($endpoint, $payload, $headers, 2, $cwTimeout);
  out_or_error($resp);
}


// ---- OPENAI ----
if ($backend === 'openai') {
  if (!$OPENAI_API_KEY) respond_error(500, 'OPENAI_API_KEY missing');

  $endpoint = 'https://api.openai.com/v1/chat/completions';
  if ($model === null || $model === '') $model = ($cwModel !== '' ? $cwModel : 'gpt-4o-mini');

  $payload = [
    'model'       => $model,
    'messages'    => $messages,
    'temperature' => $temperature,
  ];
  if (!empty($tools)) $payload['tools'] = $tools;

  $ch = curl_init($endpoint);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $OPENAI_API_KEY,
      'Content-Type: application/json',
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => $cwTimeout,
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($err || $code >= 400) {
    respond_error(502, 'openai_failed', ['code' => $code, 'detail' => $err, 'resp' => $resp]);
  }

  $data = json_decode($resp, true);

  // Optional: single-pass tool execution bridge
  if ($runTools && !empty($data['choices'][0]['message']['tool_calls'])) {
    $toolCalls = $data['choices'][0]['message']['tool_calls'];
    $toolResults = [];
    foreach ($toolCalls as $tc) {
      $name = $tc['function']['name'] ?? '';
      $args = json_decode($tc['function']['arguments'] ?? '{}', true);
      $toolResults[] = [
        'tool_call_id' => $tc['id'] ?? null,
        'name' => $name,
        'result' => run_tool($name, is_array($args) ? $args : []),
      ];
    }
    echo json_encode([
      'ok' => true,
      'backend' => 'openai',
      'tool_results' => $toolResults,
      'first_reply' => $data,
    ]);
    exit;
  }

  echo $resp; exit;
}

// ---- OLLAMA ----
if ($backend === 'ollama') {
  $endpoint = rtrim($OLLAMA_HOST, '/') . '/api/chat';
  // Prefer a small default CPU model if not provided
  $model = $model ?: ($cwModel !== '' ? $cwModel : 'gemma2:2b');

  // Convert OpenAI-style -> Ollama format
  $msgs = [];
  foreach ($messages as $m) {
    $msgs[] = ['role' => $m['role'], 'content' => $m['content']];
  }

  $payload = [
    'model'    => $model,
    'messages' => $msgs,
    'stream'   => false,
    'options'  => ['temperature' => $temperature],
  ];

  $ch = curl_init($endpoint);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => $cwTimeout,
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($err || $code >= 400) {
    respond_error(502, 'ollama_failed', ['code' => $code, 'detail' => $err, 'resp' => $resp]);
  }

  echo $resp; exit;
}

// ---- Fallback ----
respond_error(400, 'unknown backend');

// --------- simple tool runner (server-side) ----------
function run_tool(string $name, array $args) {
  try {
    switch ($name) {
      case 'ping':
        return ['pong' => true, 'time' => gmdate('c')];
      case 'units_search':
        // placeholder; wire to PDO later
        $q = trim($args['q'] ?? '');
        return ['q' => $q, 'results' => []];
      default:
        return ['error' => 'unknown_tool', 'name' => $name];
    }
  } catch (Throwable $e) {
    return ['error' => 'tool_exception', 'msg' => $e->getMessage()];
  }
}