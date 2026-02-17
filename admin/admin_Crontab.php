<?php
// /web/html/admin/admin_Crontab.php
// Shows cron candidates under /web/private/scripts and manages dispatcher schedules.

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
require_once APP_LIB . '/cron.php';
require_once APP_LIB . '/cron_dispatcher.php';
auth_require_admin();

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');

function e(string $s): string
{
	return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cron_task_run_as(string $scriptPath): string
{
	$base = basename($scriptPath);
	return (strpos($base, 'root_') === 0) ? 'root' : 'samekhi';
}

function cron_task_log_file(string $scriptPath): string
{
	$base = basename($scriptPath);
	$logBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $base);
	return 'cron_' . $logBase . '.log';
}

function run_as_label(string $runAs): string
{
	if ($runAs === 'root') return 'root';
	if ($runAs === 'samekhi') return 'samekhi';
	return 'all';
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
		header('Content-Type: text/html; charset=utf-8');
		echo '<h2>Bad Request</h2><p>CSRF check failed.</p>';
		exit;
	}
}

function detect_cron_hint(string $path): string
{
	$fh = @fopen($path, 'rb');
	if (!$fh) return '';

	$maxLines = 30;
	$lineNo = 0;
	$hint = '';
	while (!feof($fh) && $lineNo < $maxLines) {
		$line = fgets($fh);
		if ($line === false) break;
		$lineNo++;
		$line = trim((string)$line);
		if ($line === '') continue;

		// Matches:
		//   # CRON: */15 * * * *
		//   // CRON: 0 * * * *
		//   CRON: @hourly
		if (preg_match('/\bCRON\s*:\s*([^#\/;]+)\s*$/i', $line, $m)) {
			$hint = trim((string)($m[1] ?? ''));
			break;
		}
		if (preg_match('/\bCRON\b\s+(@\w+|\*\/\d+\s+\*\s+\*\s+\*\s+\*|\d+\s+\d+\s+\*\s+\*\s+\*|[^\s]+\s+[^\s]+\s+[^\s]+\s+[^\s]+\s+[^\s]+)/i', $line, $m)) {
			$hint = trim((string)($m[1] ?? ''));
			break;
		}
	}

	@fclose($fh);
	return $hint;
}

function detect_shebang(string $path): string
{
	$fh = @fopen($path, 'rb');
	if (!$fh) return '';
	$line = fgets($fh);
	@fclose($fh);
	if (!is_string($line)) return '';
	$line = trim($line);
	return (strpos($line, '#!') === 0) ? $line : '';
}

function infer_interpreter(string $path, string $shebang): string
{
	$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
	if ($ext === 'py') return '/usr/bin/python3';
	if ($ext === 'sh') return '/bin/bash';
	if ($ext === 'php') return '/usr/bin/php';

	if ($shebang !== '') {
		if (stripos($shebang, 'python') !== false) return '/usr/bin/python3';
		if (stripos($shebang, 'bash') !== false) return '/bin/bash';
		if (stripos($shebang, 'sh') !== false) return '/bin/sh';
		if (stripos($shebang, 'php') !== false) return '/usr/bin/php';
	}

	return '';
}

function cron_field_syntax_valid(string $field, int $min, int $max): bool
{
	$field = trim($field);
	if ($field === '*') return true;

	if (strpos($field, '*/') === 0) {
		$n = trim(substr($field, 2));
		if ($n === '' || !ctype_digit($n)) return false;
		return ((int)$n) > 0;
	}

	if (strpos($field, ',') !== false) {
		$parts = explode(',', $field);
		foreach ($parts as $p) {
			$p = trim($p);
			if ($p === '' || !ctype_digit($p)) return false;
			$v = (int)$p;
			if ($v < $min || $v > $max) return false;
		}
		return true;
	}

	if (!ctype_digit($field)) return false;
	$v = (int)$field;
	return !($v < $min || $v > $max);
}

function cron_expr_syntax_valid(string $expr): bool
{
	$expr = trim($expr);
	if ($expr === '') return false;

	$probe = cron_expand_macros($expr);
	$parts = preg_split('/\s+/', trim($probe));
	if (!is_array($parts) || count($parts) !== 5) return false;

	return (
		cron_field_syntax_valid((string)$parts[0], 0, 59)
		&& cron_field_syntax_valid((string)$parts[1], 0, 23)
		&& cron_field_syntax_valid((string)$parts[2], 1, 31)
		&& cron_field_syntax_valid((string)$parts[3], 1, 12)
		&& cron_field_syntax_valid((string)$parts[4], 0, 6)
	);
}

function cron_normalize_for_preset(string $schedule): string
{
	$s = trim($schedule);
	$expanded = cron_expand_macros($s);
	if ($expanded === '0 * * * *') return '@hourly';
	if ($expanded === '0 0 * * *') return '@daily';
	if ($expanded === '0 0 * * 0') return '@weekly';
	return $s;
}

function cron_guess_preset(string $schedule): string
{
	$s = cron_normalize_for_preset($schedule);
	$known = [
		'* * * * *' => true,
		'*/5 * * * *' => true,
		'*/15 * * * *' => true,
		'@hourly' => true,
		'@daily' => true,
		'@weekly' => true,
	];
	return isset($known[$s]) ? $s : 'custom';
}

function list_cron_candidates(string $rootDir): array
{
	$out = [];
	if (!is_dir($rootDir)) return $out;

	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS)
	);

	foreach ($it as $fi) {
		/** @var SplFileInfo $fi */
		if (!$fi->isFile()) continue;
		$path = $fi->getPathname();
		if (strpos($path, '/__pycache__/') !== false) continue;
		if (substr($path, -4) === '.pyc') continue;

		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		$allowedExt = ['py' => true, 'sh' => true, 'php' => true];
		if (!isset($allowedExt[$ext])) {
			// Allow extensionless executables with shebang
			if ($ext !== '') continue;
		}

		$shebang = detect_shebang($path);
		$interp = infer_interpreter($path, $shebang);
		$cronHint = detect_cron_hint($path);
		$isExec = is_executable($path);

		$out[] = [
			'path' => $path,
			'rel' => ltrim(str_replace($rootDir, '', $path), '/'),
			'ext' => $ext,
			'exec' => $isExec,
			'size' => (int)$fi->getSize(),
			'mtime' => (int)$fi->getMTime(),
			'shebang' => $shebang,
			'interpreter' => $interp,
			'cron_hint' => $cronHint,
		];
	}

	usort($out, function ($a, $b) {
		$pa = (string)($a['rel'] ?? '');
		$pb = (string)($b['rel'] ?? '');
		return strcmp($pa, $pb);
	});

	return $out;
}

$privateRoot = defined('PRIVATE_ROOT') ? (string)PRIVATE_ROOT : '/web/private';
$scriptsRoot = rtrim($privateRoot, "/\\") . '/scripts';
$items = list_cron_candidates($scriptsRoot);

$viewRunAs = (string)($_GET['run_as'] ?? ($_POST['run_as'] ?? 'all'));
if (!in_array($viewRunAs, ['all', 'root', 'samekhi'], true)) {
	$viewRunAs = 'all';
}

$messages = [];
$errors = [];

try {
	$db = cron_dispatcher_open_db();
} catch (Throwable $t) {
	$db = null;
	$errors[] = 'Failed to open dispatcher DB: ' . $t->getMessage();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	csrf_check();
	$action = (string)($_POST['action'] ?? '');
	if ($action === 'export_tasks' && $db) {
		try {
			$exportTasks = $db->query('SELECT script_path, schedule, args_text, enabled FROM cron_tasks ORDER BY enabled DESC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
			$backupFile = rtrim($privateRoot, "/\\") . '/cron_tasks_backup.json';
			$jsonData = json_encode($exportTasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			if (file_put_contents($backupFile, $jsonData) !== false) {
				$messages[] = 'Exported ' . count($exportTasks) . ' task(s) to ' . $backupFile;
			} else {
				$errors[] = 'Failed to write backup file: ' . $backupFile;
			}
		} catch (Throwable $t) {
			$errors[] = 'Export failed: ' . $t->getMessage();
		}
	}
	if ($action === 'import_defaults' && $db) {
		try {
			$defaultsFile = __DIR__ . '/cron_tasks_backup.json';
			if (!is_file($defaultsFile)) {
				$errors[] = 'Defaults file not found: ' . $defaultsFile;
			} else {
				$jsonContent = file_get_contents($defaultsFile);
				$tasks = json_decode($jsonContent, true);
				if (!is_array($tasks)) {
					$errors[] = 'Invalid JSON in defaults file';
				} else {
					$imported = 0;
					$skipped = 0;
					$ts = time();
					foreach ($tasks as $task) {
						$scriptPath = (string)($task['script_path'] ?? '');
						$schedule = (string)($task['schedule'] ?? '');
						$argsText = (string)($task['args_text'] ?? '');
						$enabled = (int)($task['enabled'] ?? 0);
						
						if ($scriptPath === '' || $schedule === '') {
							$skipped++;
							continue;
						}
						
						if (!cron_expr_syntax_valid($schedule)) {
							$errors[] = 'Invalid schedule for ' . basename($scriptPath) . ': ' . $schedule;
							$skipped++;
							continue;
						}
						
						$ins = $db->prepare('INSERT INTO cron_tasks (script_path, schedule, args_text, enabled, created_at, updated_at) VALUES (:p,:s,:a,:e,:c,:u) ON CONFLICT(script_path) DO UPDATE SET schedule=excluded.schedule, args_text=excluded.args_text, enabled=excluded.enabled, updated_at=excluded.updated_at');
						$ins->execute([
							':p' => $scriptPath,
							':s' => $schedule,
							':a' => $argsText,
							':e' => $enabled,
							':c' => $ts,
							':u' => $ts,
						]);
						$imported++;
					}
					$messages[] = 'Imported ' . $imported . ' task(s) from defaults' . ($skipped > 0 ? ' (' . $skipped . ' skipped)' : '');
				}
			}
		} catch (Throwable $t) {
			$errors[] = 'Import failed: ' . $t->getMessage();
		}
	}
	if ($action === 'delete_task' && $db) {
		$taskId = (int)($_POST['task_id'] ?? 0);
		if ($taskId <= 0) {
			$errors[] = 'Missing or invalid task_id.';
		} else {
			try {
				$db->beginTransaction();
				$delRuns = $db->prepare('DELETE FROM cron_runs WHERE task_id = :id');
				$delRuns->execute([':id' => $taskId]);
				$delTask = $db->prepare('DELETE FROM cron_tasks WHERE id = :id');
				$delTask->execute([':id' => $taskId]);
				$db->commit();
				$messages[] = 'Deleted scheduled task #' . $taskId;
			} catch (Throwable $t) {
				if ($db->inTransaction()) {
					$db->rollBack();
				}
				$errors[] = 'Failed to delete task: ' . $t->getMessage();
			}
		}
	}
	if ($action === 'upsert_task' && $db) {
		$scriptPath = trim((string)($_POST['script_path'] ?? ''));
		$schedulePreset = trim((string)($_POST['schedule_preset'] ?? ''));
		$scheduleCustom = trim((string)($_POST['schedule_custom'] ?? ''));
		$scheduleLegacy = trim((string)($_POST['schedule'] ?? ''));
		$argsText = (string)($_POST['args_text'] ?? '');
		$argsText = str_replace("\0", '', $argsText);
		$argsText = trim($argsText);
		if (strlen($argsText) > 2048) {
			$argsText = substr($argsText, 0, 2048);
		}

		$schedule = '';
		if ($schedulePreset !== '') {
			$schedule = ($schedulePreset === 'custom') ? $scheduleCustom : $schedulePreset;
		} elseif ($scheduleLegacy !== '') {
			// Backward compat (older form)
			$schedule = $scheduleLegacy;
		}
		$schedule = trim($schedule);
		$enabled = isset($_POST['enabled']) ? 1 : 0;

		if ($scriptPath === '') {
			$errors[] = 'Missing script_path.';
		} elseif ($schedule === '') {
			$errors[] = 'Missing schedule.';
		} else {
			$rp = cron_dispatcher_safe_realpath_under($scriptPath, $scriptsRoot);
			if ($rp === null || !is_file($rp)) {
				$errors[] = 'Invalid script path (must exist under ' . $scriptsRoot . ').';
			} elseif (!cron_expr_syntax_valid($schedule)) {
				$errors[] = 'Bad schedule format. Use 5 fields like "*/15 * * * *" or @hourly/@daily/@weekly.';
			} else {
				$ts = time();
				$ins = $db->prepare('INSERT INTO cron_tasks (script_path, schedule, args_text, enabled, created_at, updated_at) VALUES (:p,:s,:a,:e,:c,:u) ON CONFLICT(script_path) DO UPDATE SET schedule=excluded.schedule, args_text=excluded.args_text, enabled=excluded.enabled, updated_at=excluded.updated_at');
				$ins->execute([
					':p' => $rp,
					':s' => $schedule,
					':a' => $argsText,
					':e' => $enabled,
					':c' => $ts,
					':u' => $ts,
				]);
				$messages[] = 'Saved schedule for ' . $rp;
			}
		}
	}
}

$tasksByPath = [];
$tasks = [];
if ($db) {
	try {
		$tasks = $db->query('SELECT * FROM cron_tasks ORDER BY enabled DESC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
		foreach ($tasks as $t) {
			$tasksByPath[(string)$t['script_path']] = $t;
		}
	} catch (Throwable $t) {
		$errors[] = 'Failed to load tasks: ' . $t->getMessage();
		$tasks = [];
	}
}

$hasRoot = false;
$hasSamekhi = false;
foreach ($tasks as $t) {
	$ra = cron_task_run_as((string)($t['script_path'] ?? ''));
	if ($ra === 'root') $hasRoot = true;
	if ($ra === 'samekhi') $hasSamekhi = true;
}
foreach ($items as $it) {
	$ra = cron_task_run_as((string)($it['path'] ?? ''));
	if ($ra === 'root') $hasRoot = true;
	if ($ra === 'samekhi') $hasSamekhi = true;
}

$tasksFiltered = $tasks;
if ($viewRunAs !== 'all') {
	$tasksFiltered = array_values(array_filter($tasks, function ($t) use ($viewRunAs) {
		return cron_task_run_as((string)($t['script_path'] ?? '')) === $viewRunAs;
	}));
}

$itemsFiltered = $items;
if ($viewRunAs !== 'all') {
	$itemsFiltered = array_values(array_filter($items, function ($it) use ($viewRunAs) {
		return cron_task_run_as((string)($it['path'] ?? '')) === $viewRunAs;
	}));
}

?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex,nofollow" />
	<title>Admin · Crontab Scripts</title>
	<style>
		body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 18px; color: #111; }
		a { color: #0645ad; text-decoration: none; }
		a:hover { text-decoration: underline; }
		.muted { color: #666; }
		.box { border: 1px solid #ddd; border-radius: 10px; padding: 14px; margin-top: 14px; }
		.top { display:flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
		.btn { display:inline-block; padding: 9px 12px; border: 1px solid #ccc; border-radius: 8px; background: #f7f7f7; cursor: pointer; }
		table { width: 100%; border-collapse: collapse; margin-top: 10px; }
		th, td { padding: 10px 8px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
		th { color:#333; font-weight: 700; }
		code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }
		.small { font-size: 12px; }
		.pill { display:inline-block; padding: 2px 8px; border-radius: 999px; border: 1px solid #ddd; color: #555; font-size: 12px; }
		.ok { color: #0a7a30; }
		.bad { color: #b00020; }
		.trunc { max-width: 520px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.args-wrap { max-width: 300px; word-wrap: break-word; word-break: break-all; overflow-wrap: break-word; }
		.msg { margin: 10px 0; padding: 10px 12px; border-radius: 8px; }
		.msg.ok { background: #eef9f0; border: 1px solid #cde9d3; }
		.msg.bad { background: #fff4f4; border: 1px solid #ffd1d1; }
	</style>
	<script>
		function copyText(id) {
			var el = document.getElementById(id);
			if (!el) return;
			var txt = el.textContent || '';
			if (!txt) return;
			if (navigator && navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(txt);
				return;
			}
			var ta = document.createElement('textarea');
			ta.value = txt;
			document.body.appendChild(ta);
			ta.select();
			try { document.execCommand('copy'); } catch (e) {}
			document.body.removeChild(ta);
		}
	</script>
</head>
<body>
	<div class="top">
		<div>
			<h1 style="margin:0">Crontab Scripts</h1>
			<div class="muted">Scans <code><?php echo e($scriptsRoot); ?></code> recursively and lists cron candidates.</div>
		</div>
		<div>
			<a class="btn" href="/admin/index.php">Admin Home</a>
		</div>
	</div>

	<div class="box">
		<div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
			<div>
				<h2 style="margin:0 0 6px 0; font-size:15px;">Dispatcher Run-As Selector</h2>
				<div class="small muted">Scripts whose basename starts with <code>root_</code> are dispatched with <code>--run-as=root</code>. Everything else uses <code>--run-as=samekhi</code>.</div>
			</div>
			<form method="get" action="" style="margin:0; display:flex; gap:8px; align-items:center;">
				<label class="small muted">View:</label>
				<select name="run_as" style="padding:8px; border:1px solid #ccc; border-radius:8px;">
					<option value="all" <?php echo ($viewRunAs === 'all') ? 'selected' : ''; ?>>All</option>
					<?php if ($hasRoot): ?>
						<option value="root" <?php echo ($viewRunAs === 'root') ? 'selected' : ''; ?>>root (root_*)</option>
					<?php endif; ?>
					<?php if ($hasSamekhi): ?>
						<option value="samekhi" <?php echo ($viewRunAs === 'samekhi') ? 'selected' : ''; ?>>samekhi (non-root_*)</option>
					<?php endif; ?>
				</select>
				<button class="btn" type="submit">Apply</button>
			</form>
		</div>
	</div>

	<div class="box">
		<h2 style="margin:0 0 6px 0; font-size:15px;">Cron Entries (dispatcher)</h2>
		<div class="small muted">Create one entry per OS user. Root runs root tasks; samekhi runs everything else.</div>
		<div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-start; margin-top:8px;">
			<?php
				$dispatcherPath = APP_ROOT . '/src/scripts/cron_dispatcher.php';
				$logRoot = rtrim($privateRoot, "/\\") . '/logs/cron_dispatcher_root.log';
				$logSamekhi = rtrim($privateRoot, "/\\") . '/logs/cron_dispatcher_samekhi.log';
				$cronLineRoot = '* * * * * /usr/bin/php ' . $dispatcherPath . ' --run-as=root >> ' . $logRoot . ' 2>&1';
				$cronLineSamekhi = '* * * * * /usr/bin/php ' . $dispatcherPath . ' --run-as=samekhi >> ' . $logSamekhi . ' 2>&1';
			?>
			<?php if ($viewRunAs === 'all' || $viewRunAs === 'root'): ?>
				<div style="min-width: 320px;">
					<div class="small muted" style="margin-bottom:6px;">Root crontab:</div>
					<code id="dispatcher_cron_root" style="display:block; white-space:pre-wrap;"><?php echo e($cronLineRoot); ?></code>
					<button class="btn" type="button" onclick="copyText('dispatcher_cron_root')" style="margin-top:8px;">Copy</button>
				</div>
			<?php endif; ?>
			<?php if ($viewRunAs === 'all' || $viewRunAs === 'samekhi'): ?>
				<div style="min-width: 320px;">
					<div class="small muted" style="margin-bottom:6px;">samekhi user crontab:</div>
					<code id="dispatcher_cron_samekhi" style="display:block; white-space:pre-wrap;"><?php echo e($cronLineSamekhi); ?></code>
					<button class="btn" type="button" onclick="copyText('dispatcher_cron_samekhi')" style="margin-top:8px;">Copy</button>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php foreach ($messages as $m): ?>
		<div class="msg ok"><?php echo e($m); ?></div>
	<?php endforeach; ?>
	<?php foreach ($errors as $m): ?>
		<div class="msg bad"><?php echo e($m); ?></div>
	<?php endforeach; ?>

	<div class="box">
		<div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
			<h2 style="margin:0; font-size:15px;">Scheduled Tasks</h2>
			<div style="display:flex; gap:8px; flex-wrap:wrap;">
				<?php if (is_file(__DIR__ . '/cron_tasks_backup.json')): ?>
					<form method="post" action="" style="margin:0;" onsubmit="return confirm('Import default tasks? This will update/add tasks from the defaults file.');">
						<input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
						<input type="hidden" name="action" value="import_defaults" />
						<input type="hidden" name="run_as" value="<?php echo e($viewRunAs); ?>" />
						<button class="btn" type="submit" style="background:#e3f2fd; border-color:#64b5f6;">📥 Load Defaults</button>
					</form>
				<?php endif; ?>
				<?php if (!empty($tasks)): ?>
					<form method="post" action="" style="margin:0;">
						<input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
						<input type="hidden" name="action" value="export_tasks" />
						<input type="hidden" name="run_as" value="<?php echo e($viewRunAs); ?>" />
						<button class="btn" type="submit" style="background:#e8f5e9; border-color:#81c784;">💾 Export to JSON Backup</button>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php if (empty($tasksFiltered)): ?>
			<div class="muted">No tasks scheduled yet. Use the “Scripts Inventory” section below to add schedules.</div>
		<?php else: ?>
			<table>
				<thead>
					<tr>
						<th>Script</th>
						<th>Run-As</th>
						<th>Schedule</th>
						<th>Args</th>
						<th>Enabled</th>
						<th>Last Run</th>
						<th>Status</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($tasksFiltered as $t): ?>
						<?php $ra = cron_task_run_as((string)($t['script_path'] ?? '')); ?>
						<tr>
							<td class="trunc"><code title="<?php echo e((string)$t['script_path']); ?>"><?php echo e((string)$t['script_path']); ?></code></td>
							<td><span class="pill"><?php echo e(run_as_label($ra)); ?></span></td>
							<td><code><?php echo e((string)$t['schedule']); ?></code></td>
						<td class="args-wrap"><code><?php echo e((string)($t['args_text'] ?? '')); ?></code></td>
							<td><?php echo ((int)($t['enabled'] ?? 0) === 1) ? '<span class="ok">yes</span>' : '<span class="bad">no</span>'; ?></td>
							<td class="small muted"><?php echo !empty($t['last_run_at']) ? e(gmdate('c', (int)$t['last_run_at'])) : '<span class="muted">—</span>'; ?></td>
							<td><?php
								$st = (string)($t['last_status'] ?? '');
								if ($st === 'ok') echo '<span class="ok">ok</span>';
								elseif ($st === 'error') echo '<span class="bad">error</span>';
								elseif ($st === 'missing') echo '<span class="bad">missing</span>';
								else echo '<span class="muted">—</span>';
							?></td>
							<td>
								<?php
									$logFile = cron_task_log_file((string)($t['script_path'] ?? ''));
									$logUrl = '/admin/admin_logs.php?action=tail&file=' . rawurlencode($logFile) . '&lines=50';
								?>
								<div style="display:flex; gap:8px; align-items:center;">
									<a class="btn" href="<?php echo e($logUrl); ?>">View Logs</a>
									<form method="post" action="" style="margin:0;" onsubmit="return confirm('Delete this scheduled task?');">
										<input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
										<input type="hidden" name="action" value="delete_task" />
										<input type="hidden" name="task_id" value="<?php echo e((string)($t['id'] ?? '')); ?>" />
										<input type="hidden" name="run_as" value="<?php echo e($viewRunAs); ?>" />
										<button class="btn" type="submit" style="background:#fff4f4;">Delete</button>
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
		<div class="small muted">
			Tip: add a schedule hint near the top of a script like <code># CRON: */15 * * * *</code> (or <code>// CRON: @hourly</code>) and it will be used for the suggested entry.
		</div>

		<?php if (!is_dir($scriptsRoot)): ?>
			<p class="bad">Missing scripts directory: <code><?php echo e($scriptsRoot); ?></code></p>
		<?php elseif (empty($itemsFiltered)): ?>
			<p class="muted">No cron candidates found (currently scanning for <code>.py</code>, <code>.sh</code>, <code>.php</code>).</p>
		<?php else: ?>
			<h2 style="margin:12px 0 6px 0; font-size:15px;">Scripts Inventory (<?php echo e((string)count($itemsFiltered)); ?>)</h2>
			<table>
				<thead>
					<tr>
						<th>Script</th>
						<th>Run-As</th>
						<th>Type</th>
						<th>Exec</th>
						<th>Dispatcher schedule</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($itemsFiltered as $it): ?>
						<?php
							$path = (string)($it['path'] ?? '');
							$rel = (string)($it['rel'] ?? '');
							$ext = (string)($it['ext'] ?? '');
							$hint = trim((string)($it['cron_hint'] ?? ''));
							$defaultSchedule = ($hint !== '') ? $hint : '*/15 * * * *';
							$existing = $tasksByPath[$path] ?? null;
							$curSchedule = $existing ? (string)($existing['schedule'] ?? $defaultSchedule) : $defaultSchedule;
							$curArgs = $existing ? (string)($existing['args_text'] ?? '') : '';
							$preset = cron_guess_preset($curSchedule);
							$customVal = ($preset === 'custom') ? $curSchedule : '';
							$curEnabled = $existing ? ((int)($existing['enabled'] ?? 0) === 1) : true;
							$ra = cron_task_run_as($path);
						?>
						<tr>
							<td>
								<div class="trunc"><code title="<?php echo e($path); ?>"><?php echo e($rel); ?></code></div>
								<div class="small muted">mtime: <?php echo e(gmdate('c', (int)($it['mtime'] ?? 0))); ?> · size: <?php echo e((string)($it['size'] ?? 0)); ?> bytes</div>
								<?php if (!empty($it['shebang'])): ?>
									<div class="small muted">shebang: <code><?php echo e((string)$it['shebang']); ?></code></div>
								<?php endif; ?>
								<?php if ($hint !== ''): ?>
									<div class="small"><span class="pill">CRON hint</span> <code><?php echo e($hint); ?></code></div>
								<?php endif; ?>
							</td>
							<td><span class="pill"><?php echo e(run_as_label($ra)); ?></span></td>
							<td><span class="pill"><?php echo e($ext !== '' ? $ext : 'bin'); ?></span></td>
							<td>
								<?php if (!empty($it['exec'])): ?>
									<span class="ok">yes</span>
								<?php else: ?>
									<span class="bad">no</span>
								<?php endif; ?>
							</td>
							<td>
								<form method="post" action="" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:0;">
									<input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
									<input type="hidden" name="action" value="upsert_task" />
									<input type="hidden" name="script_path" value="<?php echo e($path); ?>" />
									<input type="hidden" name="run_as" value="<?php echo e($viewRunAs); ?>" />
									<select name="schedule_preset" style="padding:8px; border:1px solid #ccc; border-radius:8px;">
										<?php
											$opts = [
												'* * * * *' => 'Every 1 min',
												'*/5 * * * *' => 'Every 5 min',
												'*/15 * * * *' => 'Every 15 min',
												'@hourly' => 'Hourly',
												'@daily' => 'Daily',
												'@weekly' => 'Weekly',
												'custom' => 'Custom…',
											];
											foreach ($opts as $val => $label) {
												$sel = ($preset === $val) ? 'selected' : '';
												echo '<option value="' . e($val) . '" ' . $sel . '>' . e($label) . '</option>';
											}
										?>
									</select>
									<input type="text" name="schedule_custom" value="<?php echo e($customVal); ?>" placeholder="Custom cron" style="padding:8px; border:1px solid #ccc; border-radius:8px; min-width:190px;" />
									<input type="text" name="args_text" value="<?php echo e($curArgs); ?>" placeholder="Args (optional)" style="padding:8px; border:1px solid #ccc; border-radius:8px; min-width:220px;" />
									<label class="small" style="display:flex; gap:6px; align-items:center;">
										<input type="checkbox" name="enabled" <?php echo $curEnabled ? 'checked' : ''; ?> />
										Enabled
									</label>
									<button class="btn" type="submit">Save</button>
								</form>
								<div class="small muted">Custom examples: <code>*/5 * * * *</code>, <code>@hourly</code>, <code>0 3 * * *</code></div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="box">
		<h2 style="margin:0 0 6px 0; font-size:15px;">Note</h2>
		<div class="muted small">
			This page is the “inventory” for the canonical scripts location (<code>/web/private/scripts</code>) and manages schedules for the every-minute dispatcher.
		</div>
	</div>
</body>
</html>
