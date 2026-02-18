<?php
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once APP_LIB . '/ai_templates.php';
require_once APP_LIB . '/story_engine.php';

if (function_exists('api_guard_once')) {
  api_guard_once('story', true);
} else {
  api_guard('story', true);
}

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$db = story_db();

$input = [];
if ($method === 'POST') {
  $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
  if (strpos($ct, 'application/json') !== false) {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
  } else {
    $input = $_POST;
  }
}

$op = '';
if ($method === 'GET') {
  $op = isset($_GET['op']) ? (string)$_GET['op'] : 'list';
} else {
  $op = isset($input['op']) ? (string)$input['op'] : 'turn';
}

if ($method === 'GET' && $op === 'list') {
  echo json_encode(['ok' => true, 'stories' => story_list($db, 50)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

if ($method === 'POST' && $op === 'create') {
  $title = trim((string)($input['title'] ?? 'Untitled Story'));
  $templateName = trim((string)($input['template_name'] ?? ''));
  if ($templateName === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'template_name_required']);
    exit;
  }
  $created = story_create($db, $title, $templateName);
  $start = story_start($db, (string)$created['story_id'], 'api');
  echo json_encode(['ok' => true, 'story_id' => $created['story_id'], 'start' => $start], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

if ($method === 'POST' && $op === 'turn') {
  $storyId = trim((string)($input['story_id'] ?? ''));
  $inputMode = trim((string)($input['input_mode'] ?? 'choice'));
  $selectedKey = strtoupper(trim((string)($input['selected_key'] ?? 'A')));
  $note = trim((string)($input['note'] ?? ''));
  $playerId = trim((string)($input['player_id'] ?? 'api'));

  if ($storyId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'story_id_required']);
    exit;
  }

  if ($inputMode !== 'choice' && $inputMode !== 'note') {
    $inputMode = 'choice';
  }

  $result = story_take_turn($db, $storyId, $inputMode, $selectedKey, $note, $playerId);
  if (empty($result['ok'])) {
    http_response_code(422);
  }
  echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

if ($method === 'POST' && $op === 'relay') {
  $storyId = trim((string)($input['story_id'] ?? ''));
  $remoteUrl = trim((string)($input['remote_url'] ?? ''));
  $selectedKey = strtoupper(trim((string)($input['selected_key'] ?? 'A')));
  $note = trim((string)($input['note'] ?? ''));
  $playerId = trim((string)($input['player_id'] ?? 'api'));

  if ($storyId === '' || $remoteUrl === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'story_id_and_remote_url_required']);
    exit;
  }

  $result = story_relay_turn($db, $storyId, $remoteUrl, $selectedKey, $note, $playerId);
  if (empty($result['ok'])) {
    http_response_code(422);
  }
  echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

http_response_code(405);
echo json_encode([
  'ok' => false,
  'error' => 'method_or_op_not_allowed',
  'note' => 'Prefer dedicated routes: /v1/story/create, /v1/story/turn, /v1/story/list, /v1/story/relay',
  'usage' => [
    'GET /v1/story?op=list',
    'POST /v1/story {"op":"create","title":"...","template_name":"..."}',
    'POST /v1/story {"op":"turn","story_id":"...","input_mode":"choice|note","selected_key":"A|B|C|W","note":"..."}',
    'POST /v1/story {"op":"relay","story_id":"...","remote_url":"https://...","selected_key":"A"}'
  ]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
