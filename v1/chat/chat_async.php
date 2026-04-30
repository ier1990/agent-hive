<?php
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/queue.php';

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];

$runTools = !empty($body['run_tools']);
api_guard_once('chat', $runTools);

$scopes = isset($GLOBALS['APP_SCOPES']) && is_array($GLOBALS['APP_SCOPES']) ? $GLOBALS['APP_SCOPES'] : [];
$clientKey = isset($GLOBALS['APP_CLIENT_KEY']) ? (string)$GLOBALS['APP_CLIENT_KEY'] : '';
if ($clientKey !== '' && !in_array('chat', $scopes, true) && !in_array('tools', $scopes, true)) {
  http_response_code(403);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'forbidden', 'reason' => 'missing_chat_scope']);
  exit;
}

$messages = $body['messages'] ?? null;
$resolved = function_exists('ai_settings_resolve_request_profile')
  ? ai_settings_resolve_request_profile($body)
  : ['ok' => false, 'error' => 'ai_settings_unavailable'];
if (empty($resolved['ok'])) {
  http_response_code(400);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'error' => (string)($resolved['error'] ?? 'invalid_provider_profile'),
    'selector' => (string)($resolved['selector'] ?? ''),
  ]);
  exit;
}

$settings = isset($resolved['settings']) && is_array($resolved['settings']) ? $resolved['settings'] : [];
$provider = strtolower((string)($settings['provider'] ?? 'local'));
$model = (string)($body['model'] ?? ($settings['model'] ?? 'gpt-oss-20b'));
$backend = (string)($body['backend'] ?? 'auto');
if ($backend === 'auto' || $backend === '') {
  if ($provider === 'openai') $backend = 'openai';
  elseif ($provider === 'ollama') $backend = 'ollama';
  else $backend = 'lmstudio';
}

if (!$messages || !is_array($messages)) {
  http_response_code(400);
  echo json_encode(['error'=>'messages[] required']); exit;
}

$payload = [
  'backend' => $backend,
  'provider' => $provider,
  'model' => $model,
  'messages' => $messages,
];
if (!empty($resolved['selected_profile_hash'])) {
  $payload['provider_hash'] = (string)$resolved['selected_profile_hash'];
}

$jobId = q_enqueue('chat', $payload, /*prio*/0, /*delay*/0, /*max*/3);
header('Content-Type: application/json; charset=utf-8');
header('X-AI-Provider: ' . $provider);
if (!empty($resolved['selected_profile_hash'])) {
  header('X-AI-Provider-Hash: ' . (string)$resolved['selected_profile_hash']);
}
echo json_encode(['ok'=>true,'job_id'=>$jobId]);
