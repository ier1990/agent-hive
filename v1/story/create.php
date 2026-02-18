<?php
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once APP_LIB . '/ai_templates.php';
require_once APP_LIB . '/story_engine.php';

if (function_exists('api_guard_once')) {
  api_guard_once('story/create', true);
} else {
  api_guard('story/create', true);
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

$title = trim((string)($input['title'] ?? 'Untitled Story'));
$templateName = trim((string)($input['template_name'] ?? ''));
if ($templateName === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'template_name_required']);
  exit;
}

$db = story_db();
$created = story_create($db, $title, $templateName);
$start = story_start($db, (string)$created['story_id'], 'api');
echo json_encode(['ok' => true, 'story_id' => $created['story_id'], 'start' => $start], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
