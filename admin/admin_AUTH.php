<?php

require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';

// Safer testing escape hatch: to bypass auth on this page, create this file via SSH:
//   /web/private/allow_admin_auth_noauth.txt
// Remove the file to re-enable auth.
$noAuthFlag = rtrim((string)PRIVATE_ROOT, "/\\") . '/allow_admin_auth_noauth.txt';
if (!is_file($noAuthFlag)) {
	auth_require_admin();
}

header('Content-Type: text/html; charset=utf-8');


/*
PSEUDOCODE / NOTES (new auth)

Goal:
	- “Register” a browser/device once, then treat it as trusted.
	- Store server-side identity + device metadata.
	- Use a cookie for future logins (avoid typing passwords on LAN).
	- Assume public-facing access (do NOT rely on localhost-only).

Important note:
	- Browsers cannot expose MAC addresses to JavaScript.
	- For device identity, use a server-issued device token cookie or WebAuthn/passkeys.

Relevant paths (current / legacy):
	/web/private/bootstrap_admin_token.txt
	/web/private/admin_auth.json
	/web/html/lib/auth/auth.php

Defense in depth:
	- /admin should be protected by PHP auth gate (the contract).
	- /admin should also be protected by webserver rules (.htaccess / nginx config).
	- Treat webserver restrictions as an extra layer, not the only layer.

Bootstrap / pairing token:
	- Use /web/private/bootstrap_admin_token.txt as a “password-like” pairing secret.
	- Primary use: create the very first admin device/user.
	- After first admin exists, consider rotating/locking this down.

Planned high-level flow:
	1) Admin visits admin/admin_AUTH.php
		- Admin initiates “Register this device”.

	2) Browser collects basic device hints (best-effort):
		- user agent / platform / timezone / screen size / language
		- IP address comes from server request metadata (not JS)

	3) POST to /v1/auth/?action=register (or reuse /web/html/lib/auth/auth.php)
		- Server creates (or finds) a user/device record in db/users
		- Server issues a long random device token
		- Server sets cookie (prefer HttpOnly; SameSite=Lax; Secure only under HTTPS)

	4) Subsequent requests:
		- Cookie device token maps to user/device record
		- Roles determine access (admin vs user)
		- last_login + device info updated

Data model notes:
	- Users/devices live in db/users (username, password hash or token hash, roles, device info, last login, etc)
	- Do NOT rely on “user id 1 is admin”.

Proposed API shape (under /v1/auth):
	- /v1/auth/?action=register
		- Register admin & other devices (gated by pairing token or existing admin session)
	- /v1/auth/?action=verify
		- Verify device token cookie
	- /v1/auth/?action=logout
		- Clear device token cookie (optionally revoke token server-side)
	- /v1/auth/?action=login
		- Optional: username/password login that mints a device token cookie

*/

function e(string $s): string
{
	return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
	auth_session_start();
	if (empty($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(16));
	}
	return (string)$_SESSION['csrf_token'];
}

function csrf_check(): void
{
	auth_session_start();
	$ok = isset($_POST['csrf_token'], $_SESSION['csrf_token'])
		&& hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token']);
	if (!$ok) {
		http_response_code(400);
		echo '<h2>Bad Request</h2><p>CSRF check failed.</p>';
		exit;
	}
}

function mask_secret(string $v): string
{
	$v = (string)$v;
	$len = strlen($v);
	if ($len <= 8) return str_repeat('*', $len);
	return substr($v, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($v, -4);
}

$bootstrapPath = auth_bootstrap_token_path();
$messages = [];
$errors = [];
$newBootstrapToken = null;
$resetNotice = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	csrf_check();
	$action = (string)($_POST['action'] ?? '');
	if ($action === 'clear_device_cookie') {
		auth_cookie_clear(auth_device_cookie_name());
		$messages[] = 'Cleared device cookie on this browser.';
	}
	if ($action === 'clear_session') {
		auth_session_start();
		$sn = function_exists('session_name') ? (string)session_name() : '';
		if ($sn !== '') {
			auth_cookie_clear($sn);
		}
		auth_logout();
		$messages[] = 'Cleared PHP session for this browser.';
	}
	if ($action === 'delete_device') {
		$deviceId = (int)($_POST['device_id'] ?? 0);
		if ($deviceId <= 0) {
			$errors[] = 'Missing or invalid device_id.';
		} else {
			try {
				$db = auth_db_open();
				$stmt = $db->prepare('DELETE FROM devices WHERE id = :id');
				$stmt->execute([':id' => $deviceId]);
				if ($stmt->rowCount() > 0) {
					$messages[] = 'Deleted device #' . $deviceId;
				} else {
					$errors[] = 'Device not found: #' . $deviceId;
				}
			} catch (Throwable $t) {
				$errors[] = 'Failed to delete device: ' . $t->getMessage();
			}
		}
	}
	if ($action === 'change_admin_password') {
		$cur = (string)($_POST['current_password'] ?? '');
		$new1 = (string)($_POST['new_password'] ?? '');
		$new2 = (string)($_POST['new_password2'] ?? '');
		if ($new1 !== $new2) {
			$errors[] = 'New passwords do not match.';
		} else {
			$res = auth_change_admin_password($cur, $new1);
			if (!is_array($res) || empty($res['ok'])) {
				$errors[] = 'Failed to change password: ' . e((string)($res['error'] ?? 'unknown'));
			} else {
				$messages[] = 'Super Admin password updated.';
			}
		}
	}
	if ($action === 'rotate_bootstrap') {
		$path = auth_bootstrap_token_path();
		if (is_file($path)) {
			@chmod($path, 0660);
			if (!@unlink($path)) {
				$errors[] = 'Failed to delete bootstrap token file: ' . $path;
			}
		}
		$tok = auth_bootstrap_token(true);
		if ($tok === null || $tok === '') {
			$errors[] = 'Failed to create a new bootstrap token (check PRIVATE_ROOT permissions).';
		} else {
			$messages[] = 'Bootstrap token rotated. For safety, it is not shown in the UI; use SSH to read the token file.';
		}
	}
	if ($action === 'reset_super_admin') {
		// Danger zone: wipe admin auth + device auth DB so we can test bootstrap from scratch.
		$adminAuthPath = rtrim((string)PRIVATE_ROOT, "/\\") . '/admin_auth.json';
		if (is_file($adminAuthPath)) {
			@chmod($adminAuthPath, 0660);
			@unlink($adminAuthPath);
		}

		$dbNew = rtrim((string)PRIVATE_ROOT, "/\\") . '/db/auth.db';
		$dbOld = rtrim((string)PRIVATE_ROOT, "/\\") . '/db/auth.sqlite';
		if (is_file($dbNew)) {
			@chmod($dbNew, 0660);
			@unlink($dbNew);
		}
		if (is_file($dbOld)) {
			@chmod($dbOld, 0660);
			@unlink($dbOld);
		}

		// Rotate bootstrap token
		$path = auth_bootstrap_token_path();
		if (is_file($path)) {
			@chmod($path, 0660);
			@unlink($path);
		}
		$tok = auth_bootstrap_token(true);
		if ($tok === null || $tok === '') {
			$errors[] = 'Reset done, but failed to create a new bootstrap token (check PRIVATE_ROOT permissions).';
		} else {
			$newBootstrapToken = $tok;
		}

		// Clear session + cookie so browser is no longer logged in.
		auth_cookie_clear(auth_device_cookie_name());
		auth_logout();

		$resetNotice = 'Reset complete. Next step: SSH to the server and run: <code>cat ' . e($bootstrapPath) . '</code> then visit <code>/admin/admin_AUTH.php?bootstrap=&lt;token&gt;</code> to create the first admin again.';
	}
}

?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex,nofollow" />
	<title>Admin · Auth</title>
	<style>
		body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 18px; color: #111; }
		a { color: #0645ad; text-decoration: none; }
		a:hover { text-decoration: underline; }
		.box { border: 1px solid #ddd; border-radius: 10px; padding: 14px; margin-top: 14px; }
		.btn { display:inline-block; padding: 9px 12px; border: 1px solid #ccc; border-radius: 8px; background: #f7f7f7; cursor: pointer; }
		input, textarea { padding: 8px; border: 1px solid #ccc; border-radius: 8px; width: 100%; max-width: 680px; }
		.small { font-size: 12px; }
		.muted { color: #666; }
		.msg { margin: 10px 0; padding: 10px 12px; border-radius: 8px; }
		.msg.ok { background: #eef9f0; border: 1px solid #cde9d3; }
		.msg.bad { background: #fff4f4; border: 1px solid #ffd1d1; }
		.row { display:flex; gap: 12px; flex-wrap: wrap; align-items:flex-start; }
	</style>
</head>
<body>
	<div class="row" style="justify-content:space-between;">
		<div>
			<h1 style="margin:0;">Auth</h1>
			<div class="muted small">Device-token auth helper UI (pairs browser → cookie).</div>
		</div>
		<div>
			<a class="btn" href="/admin/index.php">Admin Home</a>
			<a class="btn" href="?logout=1" style="margin-left:8px;">Logout</a>
		</div>
	</div>

	<?php foreach ($messages as $m): ?>
		<div class="msg ok"><?php echo e($m); ?></div>
	<?php endforeach; ?>
	<?php foreach ($errors as $m): ?>
		<div class="msg bad"><?php echo e($m); ?></div>
	<?php endforeach; ?>

	<div class="box">
		<h2 style="margin:0 0 6px 0; font-size:15px;">Super Admin Bootstrap Token</h2>
		<div class="small muted">File: <code><?php echo e($bootstrapPath); ?></code></div>
		<div class="small muted" style="margin-top:6px;">Used only for initial pairing / first Super Admin bootstrap. Rotating it invalidates old bootstrap URLs.</div>

		<form method="post" action="" style="margin-top:10px;" onsubmit="return confirm('Rotate bootstrap token? Old token will stop working.');">
			<input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
			<input type="hidden" name="action" value="rotate_bootstrap" />
			<button class="btn" type="submit">Delete + Create New Token</button>
		</form>

		<div class="small muted" style="margin-top:10px;">Security note: the bootstrap token is never displayed in the UI. Use SSH to read <code><?php echo e($bootstrapPath); ?></code>.</div>


		<div class="small muted" style="margin-top:10px;">Note: rotating this token does not delete existing admin credentials; it only changes the pairing secret.</div>
	</div>

	<?php if ($resetNotice !== ''): ?>
		<div class="box">
			<h2 style="margin:0 0 6px 0; font-size:15px;">Reset Instructions</h2>
			<div class="small muted"><?php echo $resetNotice; ?></div>
		</div>
	<?php endif; ?>

	<div class="box">
		<h2 style="margin:0 0 6px 0; font-size:15px;">Danger Zone (testing)</h2>
		<div class="small muted">Resets Super Admin + device auth state so you can test bootstrap from scratch.</div>
		<ul class="small muted">
			<li>Deletes <code><?php echo e(rtrim((string)PRIVATE_ROOT, "/\\") . '/admin_auth.json'); ?></code></li>
			<li>Deletes <code><?php echo e(rtrim((string)PRIVATE_ROOT, "/\\") . '/db/auth.db'); ?></code> (and legacy <code>auth.sqlite</code> if present)</li>
			<li>Rotates <code><?php echo e($bootstrapPath); ?></code></li>
			<li>Logs this browser out and clears the device cookie</li>
		</ul>
		<form method="post" action="" style="margin-top:10px;" onsubmit="return confirm('RESET Super Admin auth? This will log you out and wipe auth DB.');">
			<input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
			<input type="hidden" name="action" value="reset_super_admin" />
			<button class="btn" type="submit" style="background:#fff4f4;">Reset Super Admin (wipe auth)</button>
		</form>
	</div>

	<div class="box">
		<h2 style="margin:0 0 6px 0; font-size:15px;">Super Admin Password</h2>
		<div class="small muted">Updates the password stored in <code><?php echo e(rtrim((string)PRIVATE_ROOT, "/\\") . '/admin_auth.json'); ?></code>. Device-cookie auth is unchanged.</div>
		<form method="post" action="" style="margin-top:10px;" onsubmit="return confirm('Change Super Admin password?');">
			<input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
			<input type="hidden" name="action" value="change_admin_password" />
			<div class="row">
				<div style="flex:1; min-width:280px;">
					<label class="small muted">Current password</label><br>
					<input type="password" name="current_password" autocomplete="current-password" />
				</div>
			</div>
			<div class="row" style="margin-top:8px;">
				<div style="flex:1; min-width:280px;">
					<label class="small muted">New password (min 10 chars)</label><br>
					<input type="password" name="new_password" autocomplete="new-password" />
				</div>
				<div style="flex:1; min-width:280px;">
					<label class="small muted">Repeat new password</label><br>
					<input type="password" name="new_password2" autocomplete="new-password" />
				</div>
			</div>
			<div style="margin-top:10px;">
				<button class="btn" type="submit" style="background:#fffbe6;">Change Password</button>
			</div>
		</form>
	</div>

	<div class="box">
		<h2 style="margin:0 0 6px 0; font-size:15px;">Create New Device Cookie</h2>
		<div class="small muted">Creates (or rotates) the long-lived <code>cw_device</code> cookie used for auto-login. Stored server-side in <code><?php echo e(auth_db_path()); ?></code>.</div>

		<div class="row" style="margin-top:10px;">
			<div style="flex:1; min-width:280px;">
				<label class="small muted">Label (optional)</label><br>
				<input id="label" type="text" value="my-browser" />
			</div>
			<div style="flex:1; min-width:280px;">
				<label class="small muted">Bootstrap token (optional)</label><br>
				<input id="bootstrap_token" type="text" placeholder="Optional (only needed if you are not already logged in)" />
				<div class="small muted" style="margin-top:6px;">Token file path: <code><?php echo e($bootstrapPath); ?></code></div>
			</div>
		</div>

		<div style="margin-top:10px;">
			<label class="small muted">Device info (auto-collected JSON)</label><br>
			<textarea id="device_info" rows="7" spellcheck="false"></textarea>
		</div>

		<div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
			<button class="btn" type="button" onclick="registerDevice()">Create/Rotate Cookie</button>
			<button class="btn" type="button" onclick="registerDebugDevice()" title="Creates a device token labeled bootstrap_debug_admin (visible server-side).">Create Debug Device Cookie</button>
			<button class="btn" type="button" onclick="verifyAuth()">Verify</button>
			<button class="btn" type="button" onclick="logoutAuth()">Logout (revoke cookie)</button>
		</div>

		<div id="out" style="margin-top:12px;"></div>
	</div>

	<div class="box">
		<h2 style="margin:0 0 6px 0; font-size:15px;">Tutor / README</h2>
		<div class="small muted">Quick reference so we don’t forget the intended flow.</div>
		<ul class="small muted">
			<li>Admin gate: most admin tools call <code>auth_require_admin()</code>; the wrapper does too now.</li>
			<li>Bootstrap pairing token: <code><?php echo e($bootstrapPath); ?></code> (rotate when testing).</li>
			<li>Device auth DB: <code><?php echo e(auth_db_path()); ?></code> (users + devices).</li>
			<li>API endpoints: <code>/v1/auth/?action=verify</code>, <code>register</code>, <code>logout</code>, <code>login</code>.</li>
			<li>Security: tokens are random; only hashes are stored; browser JS cannot read MAC addresses.</li>
			<li><strong>Delete device:</strong> removes that device row from <code>devices</code>. Any browser still holding the old cookie will be logged out on next request (cookie is cleared automatically).</li>
		</ul>
	</div>

	<div class="box">
		<h2 style="margin:0 0 6px 0; font-size:15px;">Auth DB (debug)</h2>
		<div class="small muted">Shows what’s in the DB for sanity checks. Token values are never shown (only hash prefixes).</div>
		<?php
			$authUsers = [];
			$authDevices = [];
			$authDbErr = '';
			try {
				$db = auth_db_open();
				$authUsers = $db->query('SELECT id, username, roles, disabled, created_at, updated_at FROM users ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
				$authDevices = $db->query('SELECT d.id AS device_id, u.username, u.roles, d.label, d.token_hash, d.created_at, d.last_seen_at, d.last_seen_ip, d.last_seen_ua, d.revoked_at FROM devices d JOIN users u ON u.id = d.user_id ORDER BY d.last_seen_at DESC, d.id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
			} catch (Throwable $t) {
				$authDbErr = $t->getMessage();
			}
		?>

		<?php if ($authDbErr !== ''): ?>
			<div class="msg bad">DB error: <?php echo e($authDbErr); ?></div>
		<?php else: ?>
			<div class="small muted">Users: <?php echo e((string)count($authUsers)); ?> · Devices (showing up to 50): <?php echo e((string)count($authDevices)); ?></div>

			<?php if (!empty($authUsers)): ?>
				<h3 style="margin:10px 0 0 0; font-size:14px;">Users</h3>
				<table style="width:100%; border-collapse:collapse; margin-top:8px;">
					<thead>
						<tr>
							<th>ID</th>
							<th>Username</th>
							<th>Roles</th>
							<th>Disabled</th>
							<th>Created</th>
							<th>Updated</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($authUsers as $u): ?>
							<tr>
								<td class="small"><?php echo e((string)($u['id'] ?? '')); ?></td>
								<td><code><?php echo e((string)($u['username'] ?? '')); ?></code></td>
								<td class="small"><code><?php echo e((string)($u['roles'] ?? '')); ?></code></td>
								<td class="small"><?php echo ((int)($u['disabled'] ?? 0) === 1) ? '<span style="color:#b00020;">yes</span>' : '<span style="color:#0a7a30;">no</span>'; ?></td>
								<td class="small muted"><?php echo !empty($u['created_at']) ? e(gmdate('c', (int)$u['created_at'])) : '—'; ?></td>
								<td class="small muted"><?php echo !empty($u['updated_at']) ? e(gmdate('c', (int)$u['updated_at'])) : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else: ?>
				<div class="small muted" style="margin-top:8px;">No users yet.</div>
			<?php endif; ?>

			<?php if (!empty($authDevices)): ?>
				<h3 style="margin:12px 0 0 0; font-size:14px;">Devices (recent)</h3>
				<table style="width:100%; border-collapse:collapse; margin-top:8px;">
					<thead>
						<tr>
							<th>ID</th>
							<th>User</th>
							<th>Label</th>
							<th>Token Hash</th>
							<th>Last Seen</th>
							<th>Status</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($authDevices as $d): ?>
							<?php
								$th = (string)($d['token_hash'] ?? '');
								$thShort = ($th !== '') ? (substr($th, 0, 12) . '…') : '';
								$revoked = !empty($d['revoked_at']);
							?>
							<tr>
								<td class="small"><?php echo e((string)($d['device_id'] ?? '')); ?></td>
								<td><code><?php echo e((string)($d['username'] ?? '')); ?></code></td>
								<td class="small"><code><?php echo e((string)($d['label'] ?? '')); ?></code></td>
								<td class="small"><code title="<?php echo e($th); ?>"><?php echo e($thShort); ?></code></td>
								<td class="small muted">
									<?php
										$ls = (int)($d['last_seen_at'] ?? 0);
										echo ($ls > 0) ? e(gmdate('c', $ls)) : '—';
										$ip = (string)($d['last_seen_ip'] ?? '');
										if ($ip !== '') echo '<br><span class="muted">ip: ' . e($ip) . '</span>';
									?>
								</td>
								<td class="small"><?php echo $revoked ? '<span style="color:#b00020;">revoked</span>' : '<span style="color:#0a7a30;">active</span>'; ?></td>
								<td>
									<form method="post" action="" style="margin:0;" onsubmit="return confirm('Delete this device record? This will invalidate the token.');">
										<input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
										<input type="hidden" name="action" value="delete_device" />
										<input type="hidden" name="device_id" value="<?php echo e((string)($d['device_id'] ?? '')); ?>" />
										<button class="btn" type="submit" style="background:#fff4f4; padding:6px 10px;">Delete</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else: ?>
				<div class="small muted" style="margin-top:8px;">No devices yet.</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="box">
		<h2 style="margin:0 0 6px 0; font-size:15px;">Cookies &amp; Session (debug)</h2>
		<div class="small muted">What the server sees for this request. Values are masked to avoid leaking secrets.</div>

		<?php
			auth_session_start();
			$cookieName = auth_device_cookie_name();
			$hasDeviceCookie = isset($_COOKIE[$cookieName]) && trim((string)$_COOKIE[$cookieName]) !== '';
			$sn = function_exists('session_name') ? (string)session_name() : '';
			$sid = function_exists('session_id') ? (string)session_id() : '';
		?>

		<div class="small" style="margin-top:8px;">
			<div><strong>Device cookie</strong> (<code><?php echo e($cookieName); ?></code>): <?php echo $hasDeviceCookie ? '<span style="color:#0a7a30;">present</span>' : '<span class="muted">not set</span>'; ?></div>
			<div><strong>PHP session</strong>: <?php echo ($sid !== '') ? '<span style="color:#0a7a30;">active</span>' : '<span class="muted">none</span>'; ?><?php if ($sn !== '') echo ' · cookie <code>' . e($sn) . '</code>'; ?></div>
		</div>

		<div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
			<form method="post" action="" style="margin:0;" onsubmit="return confirm('Clear the device cookie for this browser?');">
				<input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
				<input type="hidden" name="action" value="clear_device_cookie" />
				<button class="btn" type="submit">Clear Device Cookie</button>
			</form>
			<form method="post" action="" style="margin:0;" onsubmit="return confirm('Clear the PHP session for this browser (logout)?');">
				<input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
				<input type="hidden" name="action" value="clear_session" />
				<button class="btn" type="submit">Clear Session (Logout)</button>
			</form>
		</div>

		<?php if (!empty($_COOKIE) && is_array($_COOKIE)): ?>
			<h3 style="margin:12px 0 0 0; font-size:14px;">Cookies</h3>
			<table style="width:100%; border-collapse:collapse; margin-top:8px;">
				<thead>
					<tr>
						<th>Name</th>
						<th>Value (masked)</th>
						<th>Length</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($_COOKIE as $k => $v): ?>
						<?php
							$k = (string)$k;
							$vs = is_string($v) ? $v : (string)$v;
							$masked = ($k === $cookieName) ? ('(hidden) len=' . (string)strlen((string)$vs)) : mask_secret((string)$vs);
						?>
						<tr>
							<td class="small"><code><?php echo e($k); ?></code></td>
							<td class="small"><code><?php echo e($masked); ?></code></td>
							<td class="small muted"><?php echo e((string)strlen((string)$vs)); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else: ?>
			<div class="small muted" style="margin-top:8px;">No cookies received on this request.</div>
		<?php endif; ?>
	</div>

	<div class="box">
		<h2 style="margin:0 0 6px 0; font-size:15px;">What The Delete Button Does</h2>
		<div class="small muted">A quick tutorial note for future-you.</div>
		<ul class="small muted">
			<li><strong>Delete</strong> removes the selected row from the <code>devices</code> table (it’s not just a UI hide).</li>
			<li>This immediately invalidates that device token because the server can no longer find its <code>token_hash</code>.</li>
			<li>If a browser keeps sending the old cookie anyway, the auth layer treats it like a fake/unknown token and clears the cookie automatically.</li>
			<li>Use this to “kick out” a browser/device or to test how the system behaves with stale tokens.</li>
		</ul>
	</div>

	<script>
		function setOut(ok, obj) {
			var el = document.getElementById('out');
			var cls = ok ? 'msg ok' : 'msg bad';
			el.innerHTML = '<div class="' + cls + '"><pre style="margin:0; white-space:pre-wrap;">' + escapeHtml(JSON.stringify(obj, null, 2)) + '</pre></div>';
		}
		function escapeHtml(s) {
			return String(s).replace(/[&<>"']/g, function(c){
				return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]) || c;
			});
		}
		function collectDeviceInfo() {
			var info = {
				ua: navigator.userAgent || null,
				platform: navigator.platform || null,
				language: navigator.language || null,
				timezone: (Intl && Intl.DateTimeFormat) ? (Intl.DateTimeFormat().resolvedOptions().timeZone || null) : null,
				screen: (window.screen ? { w: screen.width, h: screen.height, dpr: window.devicePixelRatio || 1 } : null),
				created_at: new Date().toISOString(),
			};
			return info;
		}
		(function init() {
			try {
				document.getElementById('device_info').value = JSON.stringify(collectDeviceInfo(), null, 2);
			} catch (e) {}
		})();

		async function registerDevice() {
			var label = (document.getElementById('label').value || '').trim();
			var bootstrap_token = (document.getElementById('bootstrap_token').value || '').trim();
			var device_info = {};
			try { device_info = JSON.parse(document.getElementById('device_info').value || '{}'); } catch (e) { device_info = {}; }
			var payload = { label: label, bootstrap_token: bootstrap_token, device_info: device_info };
			var resp = await fetch('/v1/auth/?action=register', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(payload)
			});
			var data = await resp.json().catch(function(){ return { ok:false, error:'bad_json' }; });
			setOut(resp.ok && data && data.ok, data);
		}

		async function registerDebugDevice() {
			var bootstrap_token = (document.getElementById('bootstrap_token').value || '').trim();
			var device_info = {};
			try { device_info = JSON.parse(document.getElementById('device_info').value || '{}'); } catch (e) { device_info = {}; }
			var payload = { label: 'bootstrap_debug_admin', bootstrap_token: bootstrap_token, device_info: device_info };
			var resp = await fetch('/v1/auth/?action=register', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(payload)
			});
			var data = await resp.json().catch(function(){ return { ok:false, error:'bad_json' }; });
			setOut(resp.ok && data && data.ok, data);
		}

		async function verifyAuth() {
			var resp = await fetch('/v1/auth/?action=verify', { method: 'GET' });
			var data = await resp.json().catch(function(){ return { ok:false, error:'bad_json' }; });
			setOut(resp.ok && data && data.ok, data);
		}

		async function logoutAuth() {
			var resp = await fetch('/v1/auth/?action=logout&revoke=1', { method: 'POST' });
			var data = await resp.json().catch(function(){ return { ok:false, error:'bad_json' }; });
			setOut(resp.ok && data && data.ok, data);
		}
	</script>
</body>
</html>