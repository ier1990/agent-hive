<?php

declare(strict_types=0);

// Minimal admin auth with a "bootstrap token" to avoid lockouts on fresh installs.
// Intended usage (top of admin entrypoints):
//   require_once __DIR__ . '/../lib/bootstrap.php';
//   require_once APP_LIB . '/auth/auth.php';
//   auth_require_admin();

function auth_session_start(): void
{
	if (function_exists('session_status')) {
		if (session_status() === PHP_SESSION_ACTIVE) return;
	}
	if (!headers_sent()) {
		@session_start();
	}
}

function auth_client_ip(): string
{
	if (function_exists('get_client_ip_trusted')) {
		try {
			return (string)get_client_ip_trusted();
		} catch (Throwable $e) {
			// fall through
		}
	}
	return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function auth_is_lan_ip(string $ip): bool
{
	$ip = trim($ip);
	if ($ip === '') return false;
	if (strpos($ip, '10.') === 0) return true;
	if (strpos($ip, '192.168.') === 0) return true;
	// 172.16.0.0 – 172.31.255.255
	if (preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $ip)) return true;
	return false;
}

function auth_private_root(): string
{
	if (defined('PRIVATE_ROOT')) {
		$pr = (string)constant('PRIVATE_ROOT');
		if ($pr !== '') return $pr;
	}
	return '/web/private';
}

function auth_admin_file_path(): string
{
	return rtrim(auth_private_root(), "/\\") . '/admin_auth.json';
}

function auth_reset_super_admin_files(): void
{
	$root = auth_private_root();
	$adminAuthPath = rtrim($root, "/\\") . '/admin_auth.json';
	if (is_file($adminAuthPath)) {
		@chmod($adminAuthPath, 0660);
		@unlink($adminAuthPath);
	}

	$dbNew = rtrim($root, "/\\") . '/db/auth.db';
	$dbOld = rtrim($root, "/\\") . '/db/auth.sqlite';
	if (is_file($dbNew)) {
		@chmod($dbNew, 0660);
		@unlink($dbNew);
	}
	if (is_file($dbOld)) {
		@chmod($dbOld, 0660);
		@unlink($dbOld);
	}
}

function auth_bootstrap_token_path(): string
{
	return rtrim(auth_private_root(), "/\\") . '/bootstrap_admin_token.txt';
}

function auth_has_admin(): bool
{
	$path = auth_admin_file_path();
	if (!is_file($path)) return false;
	$raw = @file_get_contents($path);
	if (!is_string($raw) || trim($raw) === '') return false;
	$data = json_decode($raw, true);
	if (!is_array($data)) return false;
	$hash = (string)($data['password_hash'] ?? '');
	return $hash !== '';
}

function auth_read_admin(): ?array
{
	$path = auth_admin_file_path();
	if (!is_file($path)) return null;
	$raw = @file_get_contents($path);
	if (!is_string($raw) || trim($raw) === '') return null;
	$data = json_decode($raw, true);
	return is_array($data) ? $data : null;
}

function auth_write_admin(string $username, string $password): bool
{
	$root = auth_private_root();
	if (!is_dir($root)) {
		@mkdir($root, 0775, true);
	}
	if (!is_dir($root) || !is_writable($root)) {
		return false;
	}

	$path = auth_admin_file_path();
	$data = [
		'username' => $username,
		'password_hash' => password_hash($password, PASSWORD_DEFAULT),
		'created_at' => gmdate('c'),
	];
	$payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
	//make file writable temporarily
	@chmod($path, 0660); // Owner read/write, group read/write (for www-data)
	$ok = @file_put_contents($path, $payload);
	if ($ok === false) return false;
	@chmod($path, 0640); // Owner read/write, group read (for www-data)
	return true;
}

function auth_change_admin_password(string $currentPassword, string $newPassword): array
{
	$admin = auth_read_admin();
	if (!is_array($admin)) {
		return ['ok' => false, 'error' => 'no_admin_configured'];
	}
	$hash = (string)($admin['password_hash'] ?? '');
	if ($hash === '' || !password_verify($currentPassword, $hash)) {
		return ['ok' => false, 'error' => 'invalid_current_password'];
	}
	if (strlen($newPassword) < 10) {
		return ['ok' => false, 'error' => 'password_too_short'];
	}

	$username = (string)($admin['username'] ?? 'admin');
	$admin['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
	$admin['updated_at'] = gmdate('c');
	$payload = json_encode($admin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
	if (!is_string($payload) || $payload === '') {
		return ['ok' => false, 'error' => 'encode_failed'];
	}

	$path = auth_admin_file_path();
	@chmod($path, 0660);
	$ok = @file_put_contents($path, $payload);
	if ($ok === false) {
		return ['ok' => false, 'error' => 'write_failed'];
	}
	@chmod($path, 0640);
	return ['ok' => true, 'username' => $username];
}

function auth_bootstrap_token(bool $createIfMissing = true): ?string
{
	$path = auth_bootstrap_token_path();
	if (is_file($path)) {
		$tok = trim((string)@file_get_contents($path));
		return $tok !== '' ? $tok : null;
	}
	if (!$createIfMissing) return null;

	$root = auth_private_root();
	if (!is_dir($root)) {
		@mkdir($root, 0775, true);
	}
	if (!is_dir($root) || !is_writable($root)) {
		return null;
	}

	$tok = bin2hex(random_bytes(24));
	//make file writable temporarily
	@chmod($path, 0660); // Owner read/write, group read/write (for www-data)
	if (@file_put_contents($path, $tok . "\n") === false) {
		return null;
	}
	@chmod($path, 0640); // Owner read/write, group read (for www-data)
	return $tok;
}

function auth_allow_bootstrap(): bool
{
	if (auth_has_admin()) return false;

	$given = (string)($_GET['bootstrap'] ?? '');
	$given = trim($given);
	if ($given === '') return false;

	$tok = auth_bootstrap_token(true);
	if ($tok === null || $tok === '') return false;

	return hash_equals($tok, $given);
}

function auth_is_logged_in(): bool
{
	auth_session_start();
	return !empty($_SESSION['admin_authed']);
}

function auth_logout(): void
{
	auth_session_start();
	$_SESSION = [];
	if (function_exists('session_destroy')) {
		@session_destroy();
	}
}

function auth_is_https(): bool
{
	// Operator override (useful behind proxies / while on LAN without HTTPS).
	// AUTH_COOKIE_SECURE=1 forces Secure cookies.
	// AUTH_COOKIE_SECURE=0 forces non-Secure cookies.
	if (function_exists('env')) {
		$ov = (string)env('AUTH_COOKIE_SECURE', '');
		$ov = strtolower(trim($ov));
		if ($ov !== '') {
			if (in_array($ov, ['1', 'true', 'yes', 'on'], true)) return true;
			if (in_array($ov, ['0', 'false', 'no', 'off'], true)) return false;
		}
	}

	$https = (string)($_SERVER['HTTPS'] ?? '');
	if ($https !== '' && strtolower($https) !== 'off') return true;

	// Avoid trusting X-Forwarded-Proto by default (can cause Secure cookies on HTTP).
	if (function_exists('env')) {
		$trust = (string)env('AUTH_TRUST_X_FORWARDED_PROTO', '');
		$trust = strtolower(trim($trust));
		if (in_array($trust, ['1', 'true', 'yes', 'on'], true)) {
			$proto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
			if (strtolower($proto) === 'https') return true;
		}
	}

	$port = (string)($_SERVER['SERVER_PORT'] ?? '');
	return $port === '443';
}

function auth_device_cookie_name(): string
{
	return 'cw_device';
}

function auth_cookie_set(string $name, string $value, int $expires): void
{
	if (headers_sent()) return;
	$secure = auth_is_https();
	@setcookie($name, $value, [
		'expires' => $expires,
		'path' => '/',
		'samesite' => 'Lax',
		'httponly' => true,
		'secure' => $secure,
	]);
}

function auth_cookie_clear(string $name): void
{
	auth_cookie_set($name, '', time() - 3600);
}

function auth_db_path(): string
{
	$root = auth_private_root();
	return rtrim($root, "/\\") . '/db/auth.db';
}

function auth_db_open(): PDO
{
	$path = auth_db_path();
	// Backward-compat: older installs may have used auth.sqlite
	$oldPath = rtrim(auth_private_root(), "/\\") . '/db/auth.sqlite';
	if (!is_file($path) && is_file($oldPath)) {
		// Prefer migrating to the new filename.
		$dir = dirname($path);
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		if (@rename($oldPath, $path) === false) {
			// If rename fails (permissions), fall back to the existing file.
			$path = $oldPath;
		}
	}
	$dir = dirname($path);
	if (!is_dir($dir)) {
		@mkdir($dir, 0775, true);
	}

	$db = new PDO('sqlite:' . $path);
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$db->exec('PRAGMA journal_mode=WAL');
	$db->exec('PRAGMA synchronous=NORMAL');
	$db->exec('PRAGMA busy_timeout=5000');
	$db->exec('PRAGMA foreign_keys=ON');

	auth_db_init($db);
	return $db;
}

function auth_db_init(PDO $db): void
{
	$db->exec('CREATE TABLE IF NOT EXISTS users (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		username TEXT NOT NULL UNIQUE,
		password_hash TEXT NOT NULL DEFAULT "",
		roles TEXT NOT NULL DEFAULT "",
		disabled INTEGER NOT NULL DEFAULT 0,
		created_at INTEGER NOT NULL,
		updated_at INTEGER NOT NULL
	)');
	$db->exec('CREATE INDEX IF NOT EXISTS idx_users_username ON users(username)');

	// Backward-compat migration: older installs may not have password_hash.
	try {
		$cols = $db->query("PRAGMA table_info('users')")->fetchAll(PDO::FETCH_ASSOC);
		$hasPw = false;
		if (is_array($cols)) {
			foreach ($cols as $c) {
				if (is_array($c) && (string)($c['name'] ?? '') === 'password_hash') {
					$hasPw = true;
					break;
				}
			}
		}
		if (!$hasPw) {
			$db->exec('ALTER TABLE users ADD COLUMN password_hash TEXT NOT NULL DEFAULT ""');
		}
	} catch (Throwable $t) {
		// Ignore; schema will still work for device-token use.
	}

	$db->exec('CREATE TABLE IF NOT EXISTS devices (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		user_id INTEGER NOT NULL,
		token_hash TEXT NOT NULL UNIQUE,
		label TEXT NOT NULL DEFAULT "",
		device_info_json TEXT NOT NULL DEFAULT "{}",
		created_at INTEGER NOT NULL,
		last_seen_at INTEGER,
		last_seen_ip TEXT,
		last_seen_ua TEXT,
		revoked_at INTEGER,
		FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
	)');
	$db->exec('CREATE INDEX IF NOT EXISTS idx_devices_user ON devices(user_id)');
	$db->exec('CREATE INDEX IF NOT EXISTS idx_devices_last_seen ON devices(last_seen_at DESC)');
}

function auth_token_pepper(): string
{
	// Optional hardening via env. Keep backward compatible if unset.
	if (function_exists('env')) {
		$pep = (string)env('AUTH_TOKEN_PEPPER', '');
		if ($pep !== '') return $pep;
	}
	return '';
}

function auth_token_hash(string $token): string
{
	$pep = auth_token_pepper();
	return hash('sha256', $token . $pep);
}

function auth_roles_has_admin(string $roles): bool
{
	$roles = ',' . strtolower(trim($roles)) . ',';
	return (strpos($roles, ',admin,') !== false);
}

function auth_db_ensure_user(PDO $db, string $username, string $roles): array
{
	$username = trim($username);
	$roles = trim($roles);
	$now = time();

	$stmt = $db->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
	$stmt->execute([':u' => $username]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	if (is_array($row)) {
		// Ensure roles include requested roles
		$curRoles = (string)($row['roles'] ?? '');
		if ($roles !== '' && stripos(',' . $curRoles . ',', ',' . $roles . ',') === false) {
			$newRoles = trim($curRoles . ',' . $roles, ',');
			$upd = $db->prepare('UPDATE users SET roles=:r, updated_at=:t WHERE id=:id');
			$upd->execute([':r' => $newRoles, ':t' => $now, ':id' => (int)$row['id']]);
			$row['roles'] = $newRoles;
		}
		return $row;
	}

	$ins = $db->prepare('INSERT INTO users (username, roles, disabled, created_at, updated_at) VALUES (:u,:r,0,:c,:u2)');
	$ins->execute([':u' => $username, ':r' => $roles, ':c' => $now, ':u2' => $now]);
	$id = (int)$db->lastInsertId();
	return [
		'id' => $id,
		'username' => $username,
		'roles' => $roles,
		'disabled' => 0,
		'created_at' => $now,
		'updated_at' => $now,
	];
}

function auth_device_issue(PDO $db, int $userId, string $label, array $deviceInfo): string
{
	$token = bin2hex(random_bytes(32));
	$hash = auth_token_hash($token);
	$now = time();
	$ip = (string)auth_client_ip();
	$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

	$infoJson = json_encode($deviceInfo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if ($infoJson === false) $infoJson = '{}';

	$ins = $db->prepare('INSERT INTO devices (user_id, token_hash, label, device_info_json, created_at, last_seen_at, last_seen_ip, last_seen_ua) VALUES (:uid,:th,:l,:j,:c,:s,:ip,:ua)');
	$ins->execute([
		':uid' => $userId,
		':th' => $hash,
		':l' => $label,
		':j' => $infoJson,
		':c' => $now,
		':s' => $now,
		':ip' => $ip,
		':ua' => $ua,
	]);

	// 90 days
	auth_cookie_set(auth_device_cookie_name(), $token, $now + 90 * 86400);
	return $token;
}

function auth_device_try_login_from_cookie(): bool
{
	if (auth_is_logged_in()) return true;

	$cookieName = auth_device_cookie_name();
	$token = (string)($_COOKIE[$cookieName] ?? '');
	$token = trim($token);
	if ($token === '') return false;

	try {
		$db = auth_db_open();
	} catch (Throwable $t) {
		return false;
	}

	$hash = auth_token_hash($token);
	try {
		$stmt = $db->prepare('SELECT d.id AS device_id, d.user_id, d.revoked_at, d.label, u.username, u.roles, u.disabled FROM devices d JOIN users u ON u.id = d.user_id WHERE d.token_hash = :h LIMIT 1');
		$stmt->execute([':h' => $hash]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
	} catch (Throwable $t) {
		return false;
	}

	if (!is_array($row)) {
		// Unknown token (or DB mismatch) => clear so we don't keep retrying forever.
		auth_cookie_clear($cookieName);
		return false;
	}
	if (!empty($row['revoked_at']) || ((int)($row['disabled'] ?? 0) === 1) || !auth_roles_has_admin((string)($row['roles'] ?? ''))) {
		// Token is no longer valid for admin.
		auth_cookie_clear($cookieName);
		return false;
	}

	// Update last_seen
	try {
		$upd = $db->prepare('UPDATE devices SET last_seen_at=:t, last_seen_ip=:ip, last_seen_ua=:ua WHERE id=:id');
		$upd->execute([
			':t' => time(),
			':ip' => auth_client_ip(),
			':ua' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
			':id' => (int)($row['device_id'] ?? 0),
		]);
	} catch (Throwable $t) {
		// ignore
	}

	auth_session_start();
	$_SESSION['admin_authed'] = 1;
	$_SESSION['admin_user'] = (string)($row['username'] ?? 'admin');
	$_SESSION['admin_auth_via'] = 'device';
	$_SESSION['admin_device_id'] = (int)($row['device_id'] ?? 0);
	$_SESSION['admin_device_label'] = (string)($row['label'] ?? '');
	return true;
}

function auth_admin_device_label(): string
{
	auth_session_start();
	return (string)($_SESSION['admin_device_label'] ?? '');
}

function auth_is_debug_admin_device(): bool
{
	return auth_admin_device_label() === 'bootstrap_debug_admin';
}

function auth_require_admin(): void
{
	auth_session_start();

	// SSH kill-switch: if an admin exists but the bootstrap token file is missing,
	// reset auth state and generate a fresh bootstrap token.
	// This allows operators to delete bootstrap_admin_token.txt to force a full re-bootstrap.
	if (auth_has_admin()) {
		$tokenPath = auth_bootstrap_token_path();
		if (!is_file($tokenPath)) {
			auth_reset_super_admin_files();
			auth_bootstrap_token(true);
			auth_cookie_clear(auth_device_cookie_name());
			auth_logout();
		}
	}

	// Prefer device-token login if present.
	if (!auth_is_logged_in()) {
		auth_device_try_login_from_cookie();
	}

	// If no admin exists, allow bootstrap flow (LAN or token).
	if (!auth_has_admin()) {
		$tokenPath = auth_bootstrap_token_path();
		$token = auth_bootstrap_token(true);
		$givenBootstrap = trim((string)($_GET['bootstrap'] ?? ''));

		if (!auth_allow_bootstrap()) {
			http_response_code(403);
			header('Content-Type: text/html; charset=utf-8');
			echo '<h2>No admin set</h2>';
			echo '<p>Admin is not configured yet. Bootstrap requires a valid <code>?bootstrap=...</code> token.</p>';
			echo '<form method="get" style="margin: 12px 0; padding: 12px; border: 1px solid #ddd; max-width: 520px;">'
				. '<label>Bootstrap token<br><input name="bootstrap" value="" autocomplete="off" autocapitalize="off" spellcheck="false" style="width: 100%; padding: 6px;" /></label><br><br>'
				. '<button type="submit">Submit token</button>'
				. '</form>';
			echo '<p class="muted" style="max-width: 720px;">Tip: once accepted, you\'ll be redirected to the auth page and the token will be removed from the URL after admin creation.</p>';
			echo '<p>Bootstrap token file (server-side): <code>' . htmlspecialchars($tokenPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></p>';
			if ($token === null) {
				echo '<p><strong>Note:</strong> Unable to create/read bootstrap token (check PRIVATE_ROOT permissions).</p>';
			}
			exit;
		}

		// Canonicalize bootstrap flow to the auth admin page so users land
		// where they can also manage pairing/device tokens.
		$script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
		if ($script !== '' && strpos($script, '/admin/admin_AUTH.php') === false) {
			// Preserve the bootstrap token across the redirect.
			$qs = '';
			if ($givenBootstrap !== '') {
				$qs = '?bootstrap=' . rawurlencode($givenBootstrap);
			}
			header('Location: /admin/admin_AUTH.php' . $qs);
			exit;
		}

		// Handle create-admin POST.
		if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['action'] ?? '') === 'create_admin') {
			$username = trim((string)($_POST['username'] ?? 'admin'));
			$password = (string)($_POST['password'] ?? '');
			$password2 = (string)($_POST['password2'] ?? '');

			$errs = [];
			if ($username === '' || strlen($username) > 80) $errs[] = 'Invalid username';
			if (strlen($password) < 10) $errs[] = 'Password must be at least 10 characters';
			if ($password !== $password2) $errs[] = 'Passwords do not match';

			if (empty($errs)) {
				if (!auth_write_admin($username, $password)) {
					$errs[] = 'Failed to write admin auth file (check PRIVATE_ROOT permissions)';
				}
			}

			if (empty($errs)) {
				$_SESSION['admin_authed'] = 1;
				$_SESSION['admin_user'] = $username;
				$_SESSION['admin_auth_via'] = 'device';

				// Create admin user/device and issue device cookie.
				try {
					$db = auth_db_open();
					$urow = auth_db_ensure_user($db, $username, 'admin');
					auth_device_issue($db, (int)($urow['id'] ?? 0), 'bootstrap_password_admin', [
						'ua' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
						'ip' => auth_client_ip(),
						'ts' => gmdate('c'),
					]);
				} catch (Throwable $t) {
					// ignore
				}

				// Redirect to strip bootstrap token from URL (and keep the user on the auth page).
				header('Location: /admin/admin_AUTH.php');
				exit;
			}

			header('Content-Type: text/html; charset=utf-8');
			echo '<h2>Create admin</h2>';
			echo '<ul>';
			foreach ($errs as $m) {
				echo '<li>' . htmlspecialchars($m, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
			}
			echo '</ul>';
		}

		header('Content-Type: text/html; charset=utf-8');
		echo '<h2>No admin set. Create admin.</h2>';
		echo '<p>This is a fresh install. Bootstrap requires <code>?bootstrap=&lt;token&gt;</code>.</p>';
		echo '<p>Bootstrap token file (server-side): <code>' . htmlspecialchars($tokenPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></p>';
		echo '<form method="post">'
			. '<input type="hidden" name="action" value="create_admin">'
			. '<label>Username<br><input name="username" value="admin" /></label><br><br>'
			. '<label>Password<br><input type="password" name="password" /></label><br>'
			. '<label>Repeat Password<br><input type="password" name="password2" /></label><br><br>'
			. '<button type="submit">Create admin</button>'
			. '</form>';
		exit;
	}

	// Admin exists: require login.
	if (isset($_GET['logout'])) {
		auth_logout();
		auth_cookie_clear(auth_device_cookie_name());
		$base = strtok((string)($_SERVER['REQUEST_URI'] ?? '/admin/'), '?');
		header('Location: ' . $base);
		exit;
	}

	if (auth_is_logged_in()) {
		return;
	}

	$admin = auth_read_admin();
	$user = (string)($admin['username'] ?? 'admin');

	$err = '';
	if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['action'] ?? '') === 'login') {
		$pw = (string)($_POST['password'] ?? '');
		$hash = (string)($admin['password_hash'] ?? '');
		if ($hash !== '' && password_verify($pw, $hash)) {
			$_SESSION['admin_authed'] = 1;
			$_SESSION['admin_user'] = $user;

				// Password login is session-only. Device cookies are created via /v1/auth?action=register.
				$_SESSION['admin_auth_via'] = 'password';

			$base = strtok((string)($_SERVER['REQUEST_URI'] ?? '/admin/'), '?');
			header('Location: ' . $base);
			exit;
		}
		$err = 'Invalid password';
	}

	header('Content-Type: text/html; charset=utf-8');
	echo '<h2>Admin login</h2>';
	if ($err !== '') {
		echo '<p style="color:#b00020;">' . htmlspecialchars($err, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
	}
	echo '<p>User: <strong>' . htmlspecialchars($user, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>';
	echo '<form method="post">'
		. '<input type="hidden" name="action" value="login">'
		. '<label>Password<br><input type="password" name="password" /></label><br><br>'
		. '<button type="submit">Login</button>'
		. '</form>';
	echo '<p class="muted"><a href="?logout=1">Logout</a></p>';
	exit;
}
