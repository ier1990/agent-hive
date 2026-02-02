<?php
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/queue.php';

api_guard_once('chat', !empty(($GLOBALS['REQ_JSON'] ?? [])['run_tools'])); // your auth/limits

$body = $GLOBALS['REQ_JSON'] ?? (json_decode(file_get_contents('php://input'), true) ?: []);
$messages = $body['messages'] ?? null;
$model    = $body['model'] ?? 'gpt-oss-20b';
$backend  = $body['backend'] ?? 'lmstudio';

if (!$messages || !is_array($messages)) {
  http_response_code(400);
  echo json_encode(['error'=>'messages[] required']); exit;
}

$jobId = q_enqueue('chat', ['backend'=>$backend,'model'=>$model,'messages'=>$messages], /*prio*/0, /*delay*/0, /*max*/3);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'job_id'=>$jobId]);
