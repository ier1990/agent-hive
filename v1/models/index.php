<?php
/*
LM Studio (OpenAI-compatible) → /v1/models
*/
// returns json models list in lmstudio format
// from local ollama server
//lsdeclare(strict_types=1);

//show all errors for debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);


//get root bootstrap in /lib/bootstrap.php

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/http.php';

header('Content-Type: application/json; charset=utf-8');

//$lmstudio_ollamaUrl = 'http://localhost:1234';
$ollamaUrl = 'http://localhost:11434';
$LLM_API_KEY = getenv('LLM_API_KEY') ?: '';
// get models list from ollama api
$modelsResp = http_get_json(
  $ollamaUrl . '/v1/models',
  $LLM_API_KEY ? ['Authorization: Bearer ' . $LLM_API_KEY] : [],
  1, 3
);
// is it array or string or error?
if (!is_array($modelsResp)) {
  http_json(502, ['error' => 'ollama_unreachable', 'details' => $modelsResp]);
  exit;
}

/*
<b>Warning</b>:  Undefined array key "code" in <b>/web/html/v1/models.php</b> on line <b>36</b><br />
{"error":"ollama_unreachable","details":{"status":200,"body":{"object":"list","data":[{"id":"mistral-nemo:latest","object":"model","created":1766551435,"owned_by":"library"},{"id":"nemotron-cascade-14b-q4km:latest","object":"model","created":1766289274,"owned_by":"library"},{"id":"hf.co/bartowski/nvidia_Nemotron-Cascade-14B-Thinking-GGUF:Q4_K_M","object":"model","created":1766288100,"owned_by":"bartowski"},{"id":"second_constantine/gpt-oss-u:20b","object":"model","created":1766092856,"owned_by":"second_constantine"},{"id":"gurubot/gpt-oss-derestricted:20b","object":"model","created":1766080012,"owned_by":"gurubot"},{"id":"nemotron-3-nano:30b","object":"model","created":1766074420,"owned_by":"library"},{"id":"gpt-oss:latest","object":"model","created":1766030540,"owned_by":"library"}]}}}
*/

if ($modelsResp['status'] !== 200) {
  http_json(502, ['error' => 'ollama_unreachable', 'details' => $modelsResp]);
  exit;
}

//is model a json array with data?
if (!isset($modelsResp['body']['data']) || !is_array($modelsResp['body']['data'])) {
  http_json(502, ['error' => 'ollama_invalid_response', 'details' => $modelsResp]);
  exit;
}

// ncaught TypeError: json_decode(): Argument #1 ($json) must be of type string, array given in /web/html/v1/models.php:51
$avail = $modelsResp['body'] ?? [];
if (!is_array($avail)) {
  http_json(502, ['error' => 'ollama_invalid_response', 'details' => $modelsResp]);
  exit;
}
// ncaught TypeError: json_decode(): Argument #1

// Local Ollama on this server
/*
should be in this json style
,
    {
      "id": "google/gemma-3-12b",
      "object": "model",
      "owned_by": "organization_owner"
    },
    */
//$ids = array_map(function ($m) { return $m['id'] ?? ''; }, $avail['data'] ?? []);
//http_json(200, ['data' => array_map(function ($id) { return ['id' => $id]; }, $ids)]);

$ids = [];
foreach ($avail['data'] as $m) {
  if (isset($m['id'])) {
    $ids[] = $m['id'];
    }
}
$data = [];
foreach ($ids as $name) {
  $data[] = ['id' => $name];
}
// OpenAI-ish response shape
http_json(200, [
  'object' => 'list',
  'data' => $data,
]);









/*
$ollama = 'http://localhost:11434';

$tagsResp = http_get_json($ollama . '/api/tags', [], 1, 3);
if (($tagsResp['code'] ?? 0) >= 400 || empty($tagsResp['body'])) {
  http_json(502, [
    'ok' => false,
    'error' => 'upstream_failed',
    'upstream' => 'ollama',
    'code' => $tagsResp['code'] ?? 0,
    'resp' => $tagsResp['body'] ?? '',
  ]);
}


$tags = json_decode($tagsResp['body'], true);
$models = $tags['models'] ?? [];

$data = [];
foreach ($models as $m) {
  $name = $m['name'] ?? '';
  if ($name !== '') $data[] = ['id' => $name];
}

// OpenAI-ish response shape
http_json(200, [
  'object' => 'list',
  'data' => $data,
]);
*/

?>