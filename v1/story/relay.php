<?php
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once APP_LIB . '/story_engine.php';

if (function_exists('api_guard_once')) {
  api_guard_once('story/relay', true);
} else {
  api_guard('story/relay', true);
}

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
  exit;
}

$ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
$input = [];
if (strpos($ct, 'application/json') !== false) {
  $input = json_decode((string)file_get_contents('php://input'), true);
  if (!is_array($input)) $input = [];
} else {
  $input = $_POST;
}

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

$db = story_db();
$result = story_relay_turn($db, $storyId, $remoteUrl, $selectedKey, $note, $playerId);
if (empty($result['ok'])) {
  http_response_code(422);
}
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
