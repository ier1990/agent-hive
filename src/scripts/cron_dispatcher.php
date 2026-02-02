<?php
// Cron dispatcher: run this once per minute from system crontab.
// Example crontab line:
//   * * * * * /usr/bin/php /web/html/src/scripts/cron_dispatcher.php >> /web/private/logs/cron_dispatcher_cron.log 2>&1
//
// Schedules are stored in SQLite at: PRIVATE_ROOT/db/memory/cron_dispatcher.db
// Tasks are managed via: /admin/admin_Crontab.php

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once APP_LIB . '/cron.php';
require_once APP_LIB . '/cron_dispatcher.php';

// Run-as filter for task selection.
// Convention: scripts named root_* run only under --run-as=root.
// All other scripts run under --run-as=samekhi.
$runAs = 'root'; // default safe
if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv)) {
	foreach ($argv as $a) {
		if (is_string($a) && strpos($a, '--run-as=') === 0) {
			$runAs = substr($a, strlen('--run-as='));
			break;
		}
	}
}
$runAs = in_array($runAs, ['root', 'samekhi'], true) ? $runAs : 'root';

$privateRoot = defined('PRIVATE_ROOT') ? (string)PRIVATE_ROOT : '/web/private';
$locksDir = rtrim($privateRoot, "/\\") . '/locks';
if (!is_dir($locksDir)) {
	@mkdir($locksDir, 0775, true);
}

$lockPath = $locksDir . '/cron_dispatcher_' . $runAs . '.lock';
$lockFp = @fopen($lockPath, 'c+');
if (!$lockFp) {
	cron_dispatcher_log_line('ERROR: cannot open lock file: ' . $lockPath);
	exit(1);
}

if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
	// Another dispatcher instance is running.
	exit(0);
}

$startedAt = microtime(true);
$nowTs = time();
$nowMinute = (int)floor($nowTs / 60);

try {
	$db = cron_dispatcher_open_db();
} catch (Throwable $t) {
	cron_dispatcher_log_line('ERROR: DB open failed: ' . $t->getMessage());
	exit(1);
}

// Load enabled tasks
try {
	$stmt = $db->query('SELECT id, script_path, schedule, args_text, enabled, last_run_minute FROM cron_tasks WHERE enabled = 1 ORDER BY id ASC');
	$tasks = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $t) {
	cron_dispatcher_log_line('ERROR: DB query failed: ' . $t->getMessage());
	$tasks = [];
}

// Filter tasks by script basename using the root_ prefix convention.
$tasks = array_values(array_filter($tasks, function ($t) use ($runAs) {
	$p = (string)($t['script_path'] ?? '');
	$base = basename($p);
	$isRoot = (strpos($base, 'root_') === 0);
	if ($runAs === 'root') return $isRoot;
	return !$isRoot; // samekhi runs non-root_
}));

$ran = 0;
$skipped = 0;
$errors = 0;

$privateScripts = defined('PRIVATE_SCRIPTS') ? (string)PRIVATE_SCRIPTS : (rtrim($privateRoot, "/\\") . '/scripts');

foreach ($tasks as $task) {
	$taskId = (int)($task['id'] ?? 0);
	$scriptPath = (string)($task['script_path'] ?? '');
	$schedule = (string)($task['schedule'] ?? '');
	$argsText = (string)($task['args_text'] ?? '');
	$lastRunMinute = isset($task['last_run_minute']) ? (int)$task['last_run_minute'] : -1;

	if ($taskId <= 0 || $scriptPath === '' || $schedule === '') {
		$errors++;
		continue;
	}

	// Avoid double-run within the same minute
	if ($lastRunMinute === $nowMinute) {
		$skipped++;
		continue;
	}

	if (!cron_matches($schedule, $nowTs)) {
		$skipped++;
		continue;
	}

	// Path safety: must resolve under PRIVATE_SCRIPTS
	$rp = cron_dispatcher_safe_realpath_under($scriptPath, $privateScripts);
	if ($rp === null || !is_file($rp)) {
		$errors++;
		$msg = 'Task #' . $taskId . ' path invalid or missing: ' . $scriptPath;
		cron_dispatcher_log_line('ERROR: ' . $msg);
		try {
			$upd = $db->prepare('UPDATE cron_tasks SET last_run_minute=:m, last_run_at=:t, last_status=:s, updated_at=:u WHERE id=:id');
			$upd->execute([':m' => $nowMinute, ':t' => $nowTs, ':s' => 'missing', ':u' => $nowTs, ':id' => $taskId]);
		} catch (Throwable $t) {
			// ignore
		}
		continue;
	}

	$shebang = cron_dispatcher_detect_shebang($rp);
	$interp = cron_dispatcher_infer_interpreter($rp, $shebang);
	$cmd = cron_dispatcher_build_cmd($interp, $rp, $argsText);

	$runStart = microtime(true);
	$outputLines = [];
	$exitCode = 0;

	// Log file per task
	$logDir = rtrim($privateRoot, "/\\") . '/logs';
	if (!is_dir($logDir)) {
		@mkdir($logDir, 0775, true);
	}
	$logBase = preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($rp));
	$taskLog = $logDir . '/cron_' . $logBase . '.log';

	cron_dispatcher_log_line('RUN: task #' . $taskId . ' schedule="' . $schedule . '" cmd=' . $cmd);

	// Execute and capture output (stderr redirected)
	@exec($cmd . ' 2>&1', $outputLines, $exitCode);

	$durationMs = (int)round((microtime(true) - $runStart) * 1000);
	$outText = is_array($outputLines) ? implode("\n", $outputLines) : '';
	if (strlen($outText) > 50000) {
		$outText = substr($outText, 0, 50000) . "\n... (truncated)";
	}

	// Append to per-task log
	$logLine = '[' . gmdate('c') . '] exit=' . $exitCode . ' ms=' . $durationMs . "\n";
	@file_put_contents($taskLog, $logLine . $outText . "\n\n", FILE_APPEND | LOCK_EX);

	$status = ($exitCode === 0) ? 'ok' : 'error';
	$endedAt = time();

	try {
		$db->beginTransaction();

		$ins = $db->prepare('INSERT INTO cron_runs (task_id, started_at, ended_at, exit_code, status, output) VALUES (:tid,:s,:e,:c,:st,:o)');
		$ins->execute([
			':tid' => $taskId,
			':s' => (int)$nowTs,
			':e' => (int)$endedAt,
			':c' => (int)$exitCode,
			':st' => $status,
			':o' => $outText,
		]);

		$upd = $db->prepare('UPDATE cron_tasks SET last_run_minute=:m, last_run_at=:t, last_status=:s, last_exit_code=:c, last_duration_ms=:d, last_output=:o, updated_at=:u WHERE id=:id');
		$upd->execute([
			':m' => $nowMinute,
			':t' => (int)$nowTs,
			':s' => $status,
			':c' => (int)$exitCode,
			':d' => (int)$durationMs,
			':o' => $outText,
			':u' => (int)$nowTs,
			':id' => $taskId,
		]);

		$db->commit();
	} catch (Throwable $t) {
		if ($db->inTransaction()) {
			$db->rollBack();
		}
		cron_dispatcher_log_line('ERROR: DB write failed for task #' . $taskId . ': ' . $t->getMessage());
	}

	$ran++;
	if ($exitCode !== 0) $errors++;
}

$elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
cron_dispatcher_log_line('DONE: ran=' . $ran . ' skipped=' . $skipped . ' errors=' . $errors . ' ms=' . $elapsedMs);

// Keep lock held until end of process
@flock($lockFp, LOCK_UN);
@fclose($lockFp);

exit($errors > 0 ? 2 : 0);
