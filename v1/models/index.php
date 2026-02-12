<?php
/**
 * /v1/models - OpenAI-compatible models endpoint
 * Returns available models from Ollama server
 */

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/http.php';

header('Content-Type: application/json; charset=utf-8');

api_guard('models', false);

// Get Ollama server URL and API key from environment
$ollamaUrl = getenv('OLLAMA_URL') ?: 'http://localhost:11434';
$apiKey = getenv('LLM_API_KEY') ?: '';

// Fetch models from Ollama
$headers = $apiKey ? ['Authorization: Bearer ' . $apiKey] : [];
$response = http_get_json($ollamaUrl . '/v1/models', $headers, 1, 3);

// Validate response structure
if (!is_array($response) || $response['status'] !== 200) {
  http_json(502, ['error' => 'ollama_unreachable']);
  exit;
}

// Extract and validate models data
$body = $response['body'] ?? [];
if (!isset($body['data']) || !is_array($body['data'])) {
  http_json(502, ['error' => 'ollama_invalid_response']);
  exit;
}

// Transform models to OpenAI format
$models = array_map(function ($model) {
  return ['id' => $model['id'] ?? ''];
}, $body['data']);

// Filter out empty IDs
$models = array_filter($models, function ($model) {
  return $model['id'] !== '';
});

// Return OpenAI-compatible response
http_json(200, [
  'object' => 'list',
  'data' => array_values($models),
]);





?>
