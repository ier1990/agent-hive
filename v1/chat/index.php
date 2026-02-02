<?php

declare(strict_types=0);

// Autoselector for /v1/chat/
// - Default: synchronous handler (chat.php)
// - Opt-in async handler (chat_async.php) via:
//     ?async=1, ?stream=1, Accept: text/event-stream, or X-Chat-Mode: async|stream

$wantAsync = false;

// Keep legacy ping behavior (chat.php contains a no-auth ping path).
if (isset($_GET['ping'])) {
	$wantAsync = false;
} else {
	$accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
	if ($accept !== '' && strpos($accept, 'text/event-stream') !== false) {
		$wantAsync = true;
	}

	$xMode = strtolower(trim((string)($_SERVER['HTTP_X_CHAT_MODE'] ?? '')));
	if ($xMode === 'async' || $xMode === 'stream') {
		$wantAsync = true;
	}

	$asyncQ = strtolower(trim((string)($_GET['async'] ?? '')));
	if ($asyncQ !== '' && !in_array($asyncQ, ['0', 'false', 'no', 'off'], true)) {
		$wantAsync = true;
	}

	$streamQ = strtolower(trim((string)($_GET['stream'] ?? '')));
	if ($streamQ !== '' && !in_array($streamQ, ['0', 'false', 'no', 'off'], true)) {
		$wantAsync = true;
	}
}

$target = __DIR__ . '/' . ($wantAsync ? 'chat_async.php' : 'chat.php');
header('X-Chat-Selector: ' . basename($target));
if (!is_file($target)) {
	http_response_code(500);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['error' => 'chat_handler_missing', 'handler' => basename($target)], JSON_UNESCAPED_SLASHES);
	exit;
}

require $target;

