<?php

/*
Then you put these in real crontabs:

root crontab

* * * * * /usr/bin/php /web/html/src/scripts/cron_dispatcher.php --run-as=root >> /web/private/logs/cron_dispatcher_root.log 2>&1


samekhi crontab

* * * * * /usr/bin/php /web/html/src/scripts/cron_dispatcher.php --run-as=samekhi >> /web/private/logs/cron_dispatcher_samekhi.log 2>&1

*/

// NOTE: The dispatcher runner parses --run-as= itself.
// Keep this file side-effect free when included from web/admin pages.




// Shared helpers for the cron dispatcher (PHP 7.3+).

function cron_dispatcher_db_path(): string
{
	$root = defined('PRIVATE_ROOT') ? (string)PRIVATE_ROOT : '/web/private';
	return rtrim($root, "/\\") . '/db/memory/cron_dispatcher.db';
}

function cron_dispatcher_open_db(): PDO
{
	$path = cron_dispatcher_db_path();
	$dir = dirname($path);
	if (!is_dir($dir)) {
		@mkdir($dir, 0775, true);
	}

	$db = new PDO('sqlite:' . $path);
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$db->exec('PRAGMA journal_mode=WAL');
	$db->exec('PRAGMA synchronous=NORMAL');
	$db->exec('PRAGMA busy_timeout=5000');

	cron_dispatcher_init_db($db);
	return $db;
}

function cron_dispatcher_init_db(PDO $db): void
{
	$db->exec('CREATE TABLE IF NOT EXISTS cron_tasks (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		script_path TEXT NOT NULL UNIQUE,
		schedule TEXT NOT NULL,
		args_text TEXT NOT NULL DEFAULT "",
		enabled INTEGER NOT NULL DEFAULT 1,
		last_run_minute INTEGER,
		last_run_at INTEGER,
		last_status TEXT,
		last_exit_code INTEGER,
		last_duration_ms INTEGER,
		last_output TEXT,
		created_at INTEGER NOT NULL,
		updated_at INTEGER NOT NULL
	)');
	$db->exec('CREATE INDEX IF NOT EXISTS idx_cron_tasks_enabled ON cron_tasks(enabled)');
	$db->exec('CREATE INDEX IF NOT EXISTS idx_cron_tasks_last_run ON cron_tasks(last_run_at DESC)');

	$db->exec('CREATE TABLE IF NOT EXISTS cron_runs (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		task_id INTEGER NOT NULL,
		started_at INTEGER NOT NULL,
		ended_at INTEGER,
		exit_code INTEGER,
		status TEXT,
		output TEXT,
		FOREIGN KEY(task_id) REFERENCES cron_tasks(id)
	)');
	$db->exec('CREATE INDEX IF NOT EXISTS idx_cron_runs_task_started ON cron_runs(task_id, started_at DESC)');

	// Lightweight migrations for existing installs
	if (!cron_dispatcher_db_has_column($db, 'cron_tasks', 'args_text')) {
		$db->exec('ALTER TABLE cron_tasks ADD COLUMN args_text TEXT NOT NULL DEFAULT ""');
	}
}

function cron_dispatcher_db_has_column(PDO $db, string $table, string $col): bool
{
	$stmt = $db->query('PRAGMA table_info(' . $table . ')');
	$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	foreach ($rows as $r) {
		if ((string)($r['name'] ?? '') === $col) return true;
	}
	return false;
}

function cron_dispatcher_defaults_file_path(): string
{
	$root = dirname(__DIR__);
	$preferred = $root . '/admin/cron_tasks_backup.json';
	if (is_file($preferred)) {
		return $preferred;
	}
	return $root . '/admin/defaults/cron_tasks_backup.json';
}

function cron_dispatcher_schedule_is_valid(string $expr): bool
{
	$expr = trim($expr);
	if ($expr === '') return false;

	$probe = cron_expand_macros($expr);
	$parts = preg_split('/\s+/', trim($probe));
	if (!is_array($parts) || count($parts) !== 5) return false;

	return (
		cron_dispatcher_schedule_field_is_valid((string)$parts[0], 0, 59)
		&& cron_dispatcher_schedule_field_is_valid((string)$parts[1], 0, 23)
		&& cron_dispatcher_schedule_field_is_valid((string)$parts[2], 1, 31)
		&& cron_dispatcher_schedule_field_is_valid((string)$parts[3], 1, 12)
		&& cron_dispatcher_schedule_field_is_valid((string)$parts[4], 0, 6)
	);
}

function cron_dispatcher_schedule_field_is_valid(string $field, int $min, int $max): bool
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
		foreach ($parts as $part) {
			$part = trim($part);
			if ($part === '' || !ctype_digit($part)) return false;
			$value = (int)$part;
			if ($value < $min || $value > $max) return false;
		}
		return true;
	}

	if (!ctype_digit($field)) return false;
	$value = (int)$field;
	return !($value < $min || $value > $max);
}

function cron_dispatcher_import_default_tasks(PDO $db, string $defaultsFile = '', string $scriptsRoot = ''): array
{
	$result = [
		'ok' => false,
		'defaults_file' => $defaultsFile !== '' ? $defaultsFile : cron_dispatcher_defaults_file_path(),
		'imported' => 0,
		'skipped' => 0,
		'errors' => [],
	];

	if ($scriptsRoot === '') {
		$privateRoot = defined('PRIVATE_ROOT') ? (string)PRIVATE_ROOT : '/web/private';
		$scriptsRoot = defined('PRIVATE_SCRIPTS') ? (string)PRIVATE_SCRIPTS : (rtrim($privateRoot, "/\\") . '/scripts');
	}

	$defaultsFile = $result['defaults_file'];
	if (!is_file($defaultsFile)) {
		$result['errors'][] = 'Defaults file not found: ' . $defaultsFile;
		return $result;
	}

	$jsonContent = @file_get_contents($defaultsFile);
	$tasks = json_decode((string)$jsonContent, true);
	if (!is_array($tasks)) {
		$result['errors'][] = 'Invalid JSON in defaults file: ' . $defaultsFile;
		return $result;
	}

	$ts = time();
	$ins = $db->prepare('INSERT INTO cron_tasks (script_path, schedule, args_text, enabled, created_at, updated_at) VALUES (:p,:s,:a,:e,:c,:u) ON CONFLICT(script_path) DO UPDATE SET schedule=excluded.schedule, args_text=excluded.args_text, enabled=excluded.enabled, updated_at=excluded.updated_at');

	foreach ($tasks as $task) {
		$scriptPath = trim((string)($task['script_path'] ?? ''));
		if (strpos($scriptPath, '/web/private/scripts/') === 0) {
			$scriptPath = $scriptsRoot . substr($scriptPath, strlen('/web/private/scripts'));
		}

		$schedule = trim((string)($task['schedule'] ?? ''));
		$argsText = (string)($task['args_text'] ?? '');
		$enabled = (int)($task['enabled'] ?? 0);

		if ($scriptPath === '' || $schedule === '') {
			$result['skipped']++;
			continue;
		}

		if (!cron_dispatcher_schedule_is_valid($schedule)) {
			$result['errors'][] = 'Invalid schedule for ' . basename($scriptPath) . ': ' . $schedule;
			$result['skipped']++;
			continue;
		}

		$ins->execute([
			':p' => $scriptPath,
			':s' => $schedule,
			':a' => $argsText,
			':e' => $enabled,
			':c' => $ts,
			':u' => $ts,
		]);
		$result['imported']++;
	}

	$result['ok'] = true;
	return $result;
}

function cron_dispatcher_parse_args(string $s): array
{
	// Very small argv parser:
	// - splits on whitespace
	// - supports single and double quotes
	// - supports backslash escaping inside quotes and outside
	$s = trim($s);
	if ($s === '') return [];

	$args = [];
	$cur = '';
	$inSingle = false;
	$inDouble = false;
	$len = strlen($s);

	for ($i = 0; $i < $len; $i++) {
		$ch = $s[$i];

		if ($ch === '\\') {
			// Escape next char if present
			if ($i + 1 < $len) {
				$cur .= $s[$i + 1];
				$i++;
				continue;
			}
			$cur .= $ch;
			continue;
		}

		if ($inSingle) {
			if ($ch === "'") {
				$inSingle = false;
			} else {
				$cur .= $ch;
			}
			continue;
		}

		if ($inDouble) {
			if ($ch === '"') {
				$inDouble = false;
			} else {
				$cur .= $ch;
			}
			continue;
		}

		if ($ch === "'") {
			$inSingle = true;
			continue;
		}
		if ($ch === '"') {
			$inDouble = true;
			continue;
		}

		if (ctype_space($ch)) {
			if ($cur !== '') {
				$args[] = $cur;
				$cur = '';
			}
			continue;
		}

		$cur .= $ch;
	}

	if ($cur !== '') {
		$args[] = $cur;
	}

	// If quotes were left open, fall back to a single-arg (safer than guessing)
	if ($inSingle || $inDouble) {
		return [$s];
	}

	return $args;
}

function cron_dispatcher_build_cmd(string $interpreter, string $scriptPath, string $argsText): string
{
	$parts = [];
	if (trim($interpreter) !== '') {
		$parts[] = $interpreter;
	}
	$parts[] = escapeshellarg($scriptPath);

	$args = cron_dispatcher_parse_args($argsText);
	foreach ($args as $a) {
		$parts[] = escapeshellarg($a);
	}

	return implode(' ', $parts);
}

function cron_dispatcher_safe_realpath_under(string $path, string $rootDir): ?string
{
	$rp = realpath($path);
	if ($rp === false) return null;
	$rp = rtrim(str_replace('\\', '/', (string)$rp), '/');
	$root = rtrim(str_replace('\\', '/', (string)$rootDir), '/');
	if ($root === '') return null;
	if (strpos($rp, $root . '/') !== 0 && $rp !== $root) return null;
	return $rp;
}

function cron_dispatcher_detect_shebang(string $path): string
{
	$fh = @fopen($path, 'rb');
	if (!$fh) return '';
	$line = fgets($fh);
	@fclose($fh);
	if (!is_string($line)) return '';
	$line = trim($line);
	return (strpos($line, '#!') === 0) ? $line : '';
}

function cron_dispatcher_infer_interpreter(string $path, string $shebang): string
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

function cron_dispatcher_log_line(string $message): void
{
	$root = defined('PRIVATE_ROOT') ? (string)PRIVATE_ROOT : '/web/private';
	$logDir = rtrim($root, "/\\") . '/logs';
	if (!is_dir($logDir)) {
		@mkdir($logDir, 0775, true);
	}
	$path = $logDir . '/cron_dispatcher.log';
	$line = '[' . gmdate('c') . '] ' . $message . "\n";
	@file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}
