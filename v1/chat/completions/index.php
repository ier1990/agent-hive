<?php

declare(strict_types=0);

// OpenAI-compatible shim: POST /v1/chat/completions
// Proxies to our existing /v1/chat/ handler and (when needed) wraps output.

// ----------
// CORS / preflight
// ----------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Vary: Origin');
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
	http_response_code(204);
	exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	http_response_code(405);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode([
		'error' => [
			'message' => 'method_not_allowed',
			'type' => 'invalid_request_error',
			'code' => 'method_not_allowed',
		],
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

function detect_origin(): string
{
	$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
	$scheme = $https ? 'https' : 'http';
	$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
	return $scheme . '://' . $host;
}

function get_header_value(string $name): string
{
	$needle = strtolower($name);
	if (function_exists('getallheaders')) {
		$hdrs = getallheaders();
		if (is_array($hdrs)) {
			foreach ($hdrs as $k => $v) {
				if (strtolower((string)$k) === $needle) return (string)$v;
			}
		}
	}
	$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
	return (string)($_SERVER[$key] ?? '');
}

function read_json_body(): array
{
	$raw = file_get_contents('php://input');
	$data = json_decode((string)$raw, true);
	return is_array($data) ? $data : [];
}

function openai_error(int $code, string $message, string $type = 'invalid_request_error', $internal = null): void
{
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	$err = [
		'error' => [
			'message' => $message,
			'type' => $type,
			'code' => null,
		],
	];
	if ($internal !== null) {
		$err['error']['internal'] = $internal;
	}
	echo json_encode($err, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

function extract_assistant_content($resp): string
{
	if (!is_array($resp)) return '';
	// OpenAI-style
	if (isset($resp['choices'][0]['message']['content']) && is_string($resp['choices'][0]['message']['content'])) {
		return (string)$resp['choices'][0]['message']['content'];
	}
	// Some local servers return {choices:[{text:"..."}]}
	if (isset($resp['choices'][0]['text']) && is_string($resp['choices'][0]['text'])) {
		return (string)$resp['choices'][0]['text'];
	}
	// Generic fallbacks
	if (isset($resp['content']) && is_string($resp['content'])) return (string)$resp['content'];
	if (isset($resp['text']) && is_string($resp['text'])) return (string)$resp['text'];
	if (isset($resp['answer']) && is_string($resp['answer'])) return (string)$resp['answer'];
	return '';
}

function sse_send($data): void
{
	echo 'data: ' . $data . "\n\n";
	@ob_flush();
	@flush();
}

$req = read_json_body();
if (!$req) {
	openai_error(400, 'Invalid or missing JSON body');
}

if (empty($req['messages']) || !is_array($req['messages'])) {
	openai_error(400, 'messages[] required');
}

$wantStream = !empty($req['stream']);

// Our internal /v1/chat/chat.php currently rejects stream=true; disable it for the proxy call.
$proxyReq = $req;
$proxyReq['stream'] = false;

$originBase = detect_origin();
$targetUrl = rtrim($originBase, '/') . '/v1/chat/?async=0&stream=0';

$headers = [];
$auth = trim(get_header_value('Authorization'));
if ($auth !== '') {
	$headers[] = 'Authorization: ' . $auth;
}
$apiKey = trim(get_header_value('X-API-Key'));
if ($apiKey !== '') {
	$headers[] = 'X-API-Key: ' . $apiKey;
}
$headers[] = 'Accept: application/json';
$headers[] = 'Content-Type: application/json';

$ch = curl_init($targetUrl);
if (!$ch) {
	openai_error(500, 'Failed to init curl');
}

curl_setopt_array($ch, [
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_POST => true,
	CURLOPT_POSTFIELDS => json_encode($proxyReq, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
	CURLOPT_HTTPHEADER => $headers,
	CURLOPT_CONNECTTIMEOUT => 2,
	CURLOPT_TIMEOUT => 900,
]);

$body = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErr) {
	openai_error(502, 'Upstream /v1/chat/ request failed', 'api_error', $curlErr);
}

$bodyStr = (string)$body;
$decoded = json_decode($bodyStr, true);

if ($httpCode >= 400) {
	// If upstream already returned OpenAI-style error, pass through.
	if (is_array($decoded) && isset($decoded['error'])) {
		http_response_code($httpCode);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}
	openai_error($httpCode ?: 502, 'Upstream error', 'api_error', substr($bodyStr, 0, 2000));
}

// If upstream is already an OpenAI ChatCompletion and streaming was not requested, just pass it through.
if (!$wantStream) {
	header('Content-Type: application/json; charset=utf-8');
	if (is_array($decoded) && isset($decoded['object']) && $decoded['object'] === 'chat.completion') {
		echo json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		exit;
	}
	// Wrap non-OpenAI-shaped upstream response.
	$model = (string)($req['model'] ?? ($decoded['model'] ?? 'unknown'));
	$id = 'chatcmpl_' . bin2hex(random_bytes(12));
	$out = [
		'id' => $id,
		'object' => 'chat.completion',
		'created' => time(),
		'model' => $model,
		'choices' => [
			[
				'index' => 0,
				'message' => [
					'role' => 'assistant',
					'content' => extract_assistant_content($decoded),
				],
				'finish_reason' => 'stop',
			],
		],
	];
	// Best-effort usage mapping
	if (is_array($decoded) && isset($decoded['usage']) && is_array($decoded['usage'])) {
		$out['usage'] = $decoded['usage'];
	}
	echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

// Streaming response (OpenAI SSE). This is a compatibility stream:
// we proxy upstream non-streaming, then emit SSE chunks.
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) { @ob_end_flush(); }

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$model = (string)($req['model'] ?? (is_array($decoded) ? ($decoded['model'] ?? 'unknown') : 'unknown'));
$id = 'chatcmpl_' . bin2hex(random_bytes(12));
$created = time();

// If upstream already gave us a ChatCompletion, extract content from it.
$content = is_array($decoded) ? extract_assistant_content($decoded) : '';

$chunk0 = [
	'id' => $id,
	'object' => 'chat.completion.chunk',
	'created' => $created,
	'model' => $model,
	'choices' => [[
		'index' => 0,
		'delta' => ['role' => 'assistant'],
		'finish_reason' => null,
	]],
];

$chunk1 = [
	'id' => $id,
	'object' => 'chat.completion.chunk',
	'created' => $created,
	'model' => $model,
	'choices' => [[
		'index' => 0,
		'delta' => ['content' => $content],
		'finish_reason' => null,
	]],
];

$chunk2 = [
	'id' => $id,
	'object' => 'chat.completion.chunk',
	'created' => $created,
	'model' => $model,
	'choices' => [[
		'index' => 0,
		'delta' => (object)[],
		'finish_reason' => 'stop',
	]],
];

sse_send(json_encode($chunk0, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
sse_send(json_encode($chunk1, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
sse_send(json_encode($chunk2, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "data: [DONE]\n\n";
@ob_flush();
@flush();
