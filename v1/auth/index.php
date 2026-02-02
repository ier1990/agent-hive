<?php
// /web/html/v1/auth/index.php
// Device-token auth endpoints (register/verify/logout/login)

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';

header('Cache-Control: no-store');
header('Pragma: no-cache');

function auth_http_json(int $code, array $data): void
{
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

function auth_read_json_body(): array
{
	$raw = file_get_contents('php://input');
	if (!is_string($raw) || trim($raw) === '') return [];
	$data = json_decode($raw, true);
	return is_array($data) ? $data : [];
}

$action = strtolower(trim((string)($_GET['action'] ?? 'verify')));
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'OPTIONS') {
	http_response_code(204);
	exit;
}

// Best-effort: refresh session from device cookie for callers that rely on session.
auth_session_start();
auth_device_try_login_from_cookie();

if ($action === 'verify') {
	if (auth_is_logged_in()) {
		auth_http_json(200, [
			'ok' => true,
			'authed' => true,
			'user' => $_SESSION['admin_user'] ?? null,
		]);
	}
	auth_http_json(401, ['ok' => false, 'authed' => false, 'error' => 'unauthorized']);
}

if ($action === 'logout') {
	// Optional: revoke the presented token.
	$cookieName = auth_device_cookie_name();
	$token = trim((string)($_COOKIE[$cookieName] ?? ''));
	$revoke = (string)($_GET['revoke'] ?? '1');
	$doRevoke = !in_array(strtolower($revoke), ['0', 'false', 'no'], true);

	if ($doRevoke && $token !== '') {
		try {
			$db = auth_db_open();
			$hash = auth_token_hash($token);
			$upd = $db->prepare('UPDATE devices SET revoked_at = :t WHERE token_hash = :h AND revoked_at IS NULL');
			$upd->execute([':t' => time(), ':h' => $hash]);
		} catch (Throwable $t) {
			// ignore
		}
	}

	auth_logout();
	auth_cookie_clear($cookieName);
	auth_http_json(200, ['ok' => true]);
}

if ($action === 'login') {
	if ($method !== 'POST') auth_http_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
	$body = auth_read_json_body();
	$pw = (string)($body['password'] ?? ($_POST['password'] ?? ''));

	$admin = auth_read_admin();
	if (!is_array($admin)) {
		auth_http_json(400, ['ok' => false, 'error' => 'no_admin_configured']);
	}
	$user = (string)($admin['username'] ?? 'admin');
	$hash = (string)($admin['password_hash'] ?? '');
	if ($hash === '' || !password_verify($pw, $hash)) {
		auth_http_json(401, ['ok' => false, 'error' => 'invalid_password']);
	}

	$_SESSION['admin_authed'] = 1;
	$_SESSION['admin_user'] = $user;
	$_SESSION['admin_auth_via'] = 'password';

	auth_http_json(200, ['ok' => true, 'user' => $user]);
}

if ($action === 'register') {
	if ($method !== 'POST') auth_http_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
	$body = auth_read_json_body();

	$label = trim((string)($body['label'] ?? ($_POST['label'] ?? 'registered_device')));
	if (strlen($label) > 120) {
		$label = substr($label, 0, 120);
	}
	$deviceInfo = is_array($body['device_info'] ?? null) ? (array)$body['device_info'] : [];

	$bootstrapToken = trim((string)($body['bootstrap_token'] ?? ($_POST['bootstrap_token'] ?? ($_GET['bootstrap'] ?? ''))));
	$bootstrapOk = false;
	if ($bootstrapToken !== '') {
		$tok = auth_bootstrap_token(true);
		if ($tok !== null && $tok !== '' && hash_equals($tok, $bootstrapToken)) {
			$bootstrapOk = true;
		}
	}

	// Allow if already logged in (password or device), OR if bootstrap token matches.
	// This endpoint mints a new long-lived device cookie.
	if (!auth_is_logged_in() && !$bootstrapOk) {
		auth_http_json(403, ['ok' => false, 'error' => 'forbidden', 'reason' => 'not_authed_or_bootstrap']);
	}

	// Determine username to attach: the configured admin username if present, else create "admin".
	$admin = auth_read_admin();
	$username = is_array($admin) ? (string)($admin['username'] ?? 'admin') : 'admin';

	try {
		$db = auth_db_open();
		$urow = auth_db_ensure_user($db, $username, 'admin');
		auth_device_issue($db, (int)($urow['id'] ?? 0), ($label !== '' ? $label : 'registered_device'), $deviceInfo);
	} catch (Throwable $t) {
		auth_http_json(500, ['ok' => false, 'error' => 'db_error', 'detail' => $t->getMessage()]);
	}

	// If we got here via bootstrap (no session), set session too.
	$_SESSION['admin_authed'] = 1;
	$_SESSION['admin_user'] = $username;
	$_SESSION['admin_auth_via'] = 'device';
	$_SESSION['admin_device_label'] = ($label !== '' ? $label : 'registered_device');

	auth_http_json(200, ['ok' => true, 'user' => $username]);
}

auth_http_json(400, ['ok' => false, 'error' => 'bad_action', 'action' => $action]);
