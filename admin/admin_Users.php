<?php

require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

header('Content-Type: text/html; charset=utf-8');

function e(string $s): string
{
	return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
	auth_session_start();
	if (empty($_SESSION['csrf_token_users'])) {
		$_SESSION['csrf_token_users'] = bin2hex(random_bytes(16));
	}
	return (string)$_SESSION['csrf_token_users'];
}

function csrf_check(): void
{
	auth_session_start();
	$ok = isset($_POST['csrf_token'], $_SESSION['csrf_token_users'])
		&& hash_equals((string)$_SESSION['csrf_token_users'], (string)$_POST['csrf_token']);
	if (!$ok) {
		http_response_code(400);
		echo '<h2>Bad Request</h2><p>CSRF check failed.</p>';
		exit;
	}
}

$messages = [];
$errors = [];

try {
	$db = auth_db_open();
} catch (Throwable $t) {
	http_response_code(500);
	echo '<h2>Auth DB Error</h2><pre>' . e($t->getMessage()) . '</pre>';
	exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	csrf_check();
	$action = (string)($_POST['action'] ?? '');

	if ($action === 'create_user') {
		$username = trim((string)($_POST['username'] ?? ''));
		$roles = trim((string)($_POST['roles'] ?? 'user'));
		$password = (string)($_POST['password'] ?? '');
		$now = time();

		if ($username === '' || strlen($username) > 80) {
			$errors[] = 'Invalid username.';
		} else {
			try {
				$ph = '';
				if (trim($password) !== '') {
					if (strlen($password) < 10) {
						throw new RuntimeException('Password must be at least 10 characters (or leave blank).');
					}
					$ph = (string)password_hash($password, PASSWORD_DEFAULT);
				}

				$stmt = $db->prepare('INSERT INTO users (username, password_hash, roles, disabled, created_at, updated_at) VALUES (:u,:ph,:r,0,:c,:t)');
				$stmt->execute([
					':u' => $username,
					':ph' => $ph,
					':r' => $roles,
					':c' => $now,
					':t' => $now,
				]);
				$messages[] = 'Created user: ' . $username;
			} catch (Throwable $t) {
				$errors[] = 'Create failed: ' . $t->getMessage();
			}
		}
	}

	if ($action === 'set_password') {
		$userId = (int)($_POST['user_id'] ?? 0);
		$new1 = (string)($_POST['new_password'] ?? '');
		$new2 = (string)($_POST['new_password2'] ?? '');
		if ($userId <= 0) {
			$errors[] = 'Invalid user id.';
		} elseif ($new1 !== $new2) {
			$errors[] = 'Passwords do not match.';
		} elseif (strlen($new1) < 10) {
			$errors[] = 'Password must be at least 10 characters.';
		} else {
			try {
				$ph = (string)password_hash($new1, PASSWORD_DEFAULT);
				$upd = $db->prepare('UPDATE users SET password_hash=:ph, updated_at=:t WHERE id=:id');
				$upd->execute([':ph' => $ph, ':t' => time(), ':id' => $userId]);
				$messages[] = 'Updated password for user #' . (string)$userId;
			} catch (Throwable $t) {
				$errors[] = 'Password update failed: ' . $t->getMessage();
			}
		}
	}

	if ($action === 'toggle_disabled') {
		$userId = (int)($_POST['user_id'] ?? 0);
		if ($userId <= 0) {
			$errors[] = 'Invalid user id.';
		} else {
			try {
				$upd = $db->prepare('UPDATE users SET disabled = CASE disabled WHEN 1 THEN 0 ELSE 1 END, updated_at=:t WHERE id=:id');
				$upd->execute([':t' => time(), ':id' => $userId]);
				$messages[] = 'Toggled disabled for user #' . (string)$userId;
			} catch (Throwable $t) {
				$errors[] = 'Toggle failed: ' . $t->getMessage();
			}
		}
	}

	if ($action === 'delete_user') {
		$userId = (int)($_POST['user_id'] ?? 0);
		if ($userId <= 0) {
			$errors[] = 'Invalid user id.';
		} else {
			try {
				$del = $db->prepare('DELETE FROM users WHERE id=:id');
				$del->execute([':id' => $userId]);
				if ($del->rowCount() > 0) {
					$messages[] = 'Deleted user #' . (string)$userId . ' (devices deleted via cascade).';
				} else {
					$errors[] = 'User not found: #' . (string)$userId;
				}
			} catch (Throwable $t) {
				$errors[] = 'Delete failed: ' . $t->getMessage();
			}
		}
	}
}

$users = [];
$devicesByUser = [];
try {
	$users = $db->query('SELECT id, username, roles, disabled, created_at, updated_at, password_hash FROM users ORDER BY username ASC')->fetchAll(PDO::FETCH_ASSOC);
	$dev = $db->query('SELECT user_id, COUNT(*) AS c FROM devices GROUP BY user_id')->fetchAll(PDO::FETCH_ASSOC);
	if (is_array($dev)) {
		foreach ($dev as $row) {
			$uid = (int)($row['user_id'] ?? 0);
			$devicesByUser[$uid] = (int)($row['c'] ?? 0);
		}
	}
} catch (Throwable $t) {
	$errors[] = 'Query failed: ' . $t->getMessage();
}

?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex,nofollow" />
	<title>Admin · Users</title>
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
		table { width: 100%; border-collapse: collapse; }
		th, td { border-bottom: 1px solid #eee; padding: 8px 6px; text-align: left; vertical-align: top; }
		th { font-size: 12px; color: #555; }
	</style>
</head>
<body>
	<div class="row" style="justify-content:space-between;">
		<div>
			<h1 style="margin:0;">Users</h1>
			<div class="muted small">Manages <code><?php echo e(auth_db_path()); ?></code> users (roles/disabled/password hash).</div>
		</div>
		<div>
			<a class="btn" href="/admin/index.php">Admin Home</a>
		</div>
	</div>

	<?php foreach ($messages as $m): ?>
		<div class="msg ok"><?php echo e($m); ?></div>
	<?php endforeach; ?>
	<?php foreach ($errors as $m): ?>
		<div class="msg bad"><?php echo e($m); ?></div>
	<?php endforeach; ?>

	<div class="box">
		<h2 style="margin:0 0 6px 0; font-size:15px;">Create User</h2>
		<div class="small muted">Creates a row in <code>users</code>. This does not automatically grant admin; use roles like <code>user</code> or <code>admin</code>.</div>
		<form method="post" action="" style="margin-top:10px;">
			<input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
			<input type="hidden" name="action" value="create_user" />
			<div class="row">
				<div style="flex:1; min-width:240px;">
					<label class="small muted">Username</label><br>
					<input name="username" type="text" placeholder="samekhi" />
				</div>
				<div style="flex:1; min-width:240px;">
					<label class="small muted">Roles (comma-separated)</label><br>
					<input name="roles" type="text" value="user" />
				</div>
			</div>
			<div class="row" style="margin-top:8px;">
				<div style="flex:1; min-width:240px;">
					<label class="small muted">Password (optional, min 10 chars)</label><br>
					<input name="password" type="password" autocomplete="new-password" />
				</div>
			</div>
			<div style="margin-top:10px;">
				<button class="btn" type="submit">Create</button>
			</div>
		</form>
	</div>

	<div class="box">
		<h2 style="margin:0 0 6px 0; font-size:15px;">Users</h2>
		<div class="small muted">Devices count is from the <code>devices</code> table. Password is shown only as “set/unset”.</div>

		<?php if (empty($users)): ?>
			<div class="small muted" style="margin-top:8px;">No users found.</div>
		<?php else: ?>
			<table style="margin-top:8px;">
				<thead>
					<tr>
						<th>ID</th>
						<th>Username</th>
						<th>Roles</th>
						<th>Status</th>
						<th>Devices</th>
						<th>Password</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($users as $u): ?>
						<?php
							$uid = (int)($u['id'] ?? 0);
							$disabled = ((int)($u['disabled'] ?? 0) === 1);
							$pwSet = trim((string)($u['password_hash'] ?? '')) !== '';
							$devCount = (int)($devicesByUser[$uid] ?? 0);
						?>
						<tr>
							<td class="small"><?php echo e((string)$uid); ?></td>
							<td><code><?php echo e((string)($u['username'] ?? '')); ?></code></td>
							<td class="small"><code><?php echo e((string)($u['roles'] ?? '')); ?></code></td>
							<td class="small"><?php echo $disabled ? '<span style="color:#b00020;">disabled</span>' : '<span style="color:#0a7a30;">active</span>'; ?></td>
							<td class="small"><?php echo e((string)$devCount); ?></td>
							<td class="small"><?php echo $pwSet ? '<span style="color:#0a7a30;">set</span>' : '<span class="muted">unset</span>'; ?></td>
							<td>
								<div class="row">
									<form method="post" action="" style="margin:0;" onsubmit="return confirm('Toggle disabled for this user?');">
										<input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
										<input type="hidden" name="action" value="toggle_disabled" />
										<input type="hidden" name="user_id" value="<?php echo e((string)$uid); ?>" />
										<button class="btn" type="submit" style="padding:6px 10px;">Enable/Disable</button>
									</form>

									<form method="post" action="" style="margin:0;" onsubmit="return confirm('Delete this user? Devices will also be deleted.');">
										<input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
										<input type="hidden" name="action" value="delete_user" />
										<input type="hidden" name="user_id" value="<?php echo e((string)$uid); ?>" />
										<button class="btn" type="submit" style="background:#fff4f4; padding:6px 10px;">Delete</button>
									</form>
								</div>

								<div style="margin-top:8px;">
									<form method="post" action="" style="margin:0;" onsubmit="return confirm('Set/reset password for this user?');">
										<input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
										<input type="hidden" name="action" value="set_password" />
										<input type="hidden" name="user_id" value="<?php echo e((string)$uid); ?>" />
										<div class="row">
											<div style="flex:1; min-width:200px;">
												<input type="password" name="new_password" placeholder="new password" autocomplete="new-password" />
											</div>
											<div style="flex:1; min-width:200px;">
												<input type="password" name="new_password2" placeholder="repeat" autocomplete="new-password" />
											</div>
											<div>
												<button class="btn" type="submit" style="padding:6px 10px; background:#fffbe6;">Set Password</button>
											</div>
										</div>
									</form>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="box">
		<h2 style="margin:0 0 6px 0; font-size:15px;">Notes</h2>
		<ul class="small muted">
			<li>This page only manages records in <code><?php echo e(auth_db_path()); ?></code>. It does not yet add a public/user-facing login flow.</li>
			<li>Admin access is still controlled by device cookie (<code>cw_device</code>) or the Super Admin password (<code>admin_auth.json</code>).</li>
			<li>To fully support regular-user logins, we can add a separate <code>auth_require_user()</code> gate + endpoints later.</li>
		</ul>
	</div>
</body>
</html>
