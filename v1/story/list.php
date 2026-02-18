<?php
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once APP_LIB . '/story_engine.php';

if (function_exists('api_guard_once')) {
  api_guard_once('story/list', false);
} else {
  api_guard('story/list', false);
}

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
  exit;
}

$db = story_db();
echo json_encode(['ok' => true, 'stories' => story_list($db, 50)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
