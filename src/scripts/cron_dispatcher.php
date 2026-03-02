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

function cron_dispatcher_pid_running($pid)
{
	$pid = (int)$pid;
	if ($pid <= 0) return false;
	if (function_exists('posix_kill')) {
		return @posix_kill($pid, 0);
	}
	$procPath = '/proc/' . $pid;
	return is_dir($procPath);
}

function cron_dispatcher_lock_meta_read($lockPath)
{
	if (!is_file($lockPath) || !is_readable($lockPath)) return [];
	$raw = @file_get_contents($lockPath);
	if (!is_string($raw) || trim($raw) === '') return [];
	$tmp = json_decode($raw, true);
	return is_array($tmp) ? $tmp : [];
}

function cron_dispatcher_lock_meta_write($lockFp, array $meta)
{
	if (!is_resource($lockFp)) return;
	$json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if (!is_string($json)) return;
	@ftruncate($lockFp, 0);
	@rewind($lockFp);
	@fwrite($lockFp, $json . "\n");
	@fflush($lockFp);
}

// Run-as filter for task selection.
// Convention: scripts named root_* run only under --run-as=root.
// All other scripts run under --run-as=samekhi.
$runAs = 'root'; // default safe
$stuckKillEnabled = false; // explicit opt-in via --stuck-kill=1
$stuckKillAfterSeconds = 900; // 15 minutes default threshold
$stuckKillSignal = 'TERM'; // TERM or KILL
if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv)) {
	foreach ($argv as $a) {
		if (is_string($a) && strpos($a, '--run-as=') === 0) {
			$runAs = substr($a, strlen('--run-as='));
			continue;
		}
		if (is_string($a) && strpos($a, '--stuck-kill=') === 0) {
			$v = strtolower(trim((string)substr($a, strlen('--stuck-kill='))));
			$stuckKillEnabled = in_array($v, ['1', 'true', 'yes', 'on'], true);
			continue;
		}
		if (is_string($a) && strpos($a, '--stuck-kill-after=') === 0) {
			$v = (int)substr($a, strlen('--stuck-kill-after='));
			if ($v >= 0) $stuckKillAfterSeconds = $v;
			continue;
		}
		if (is_string($a) && strpos($a, '--stuck-kill-signal=') === 0) {
			$v = strtoupper(trim((string)substr($a, strlen('--stuck-kill-signal='))));
			if (in_array($v, ['TERM', 'KILL'], true)) $stuckKillSignal = $v;
			continue;
		}
	}
}
$runAs = in_array($runAs, ['root', 'samekhi'], true) ? $runAs : 'root';

$privateRoot = defined('PRIVATE_ROOT') ? (string)PRIVATE_ROOT : '/web/private';
$locksDir = rtrim($privateRoot, "/\\") . '/locks';
if (!is_dir($locksDir)) {
	@mkdir($locksDir, 0775, true);
}
$dispatcherPid = (int)getmypid();
$dispatcherHost = php_uname('n');
$lockWarnAfterSeconds = 600;

$lockPath = $locksDir . '/cron_dispatcher_' . $runAs . '.lock';
$lockFp = @fopen($lockPath, 'c+');
if (!$lockFp) {
	cron_dispatcher_log_line('ERROR: cannot open lock file: ' . $lockPath);
	exit(1);
}

// Read any previous metadata for diagnostics before trying lock.
$existingLockMeta = cron_dispatcher_lock_meta_read($lockPath);
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
	$holderPid = isset($existingLockMeta['pid']) ? (int)$existingLockMeta['pid'] : 0;
	$holderRunAs = isset($existingLockMeta['run_as']) ? (string)$existingLockMeta['run_as'] : '';
	$holderStarted = isset($existingLockMeta['started_at']) ? (string)$existingLockMeta['started_at'] : '';
	$holderAlive = $holderPid > 0 ? cron_dispatcher_pid_running($holderPid) : false;
	$ageSec = -1;
	if ($holderStarted !== '') {
		$ts = strtotime($holderStarted);
		if ($ts !== false) $ageSec = max(0, time() - (int)$ts);
	}
	$msg = 'LOCK_BUSY run_as=' . $runAs
		. ' lock=' . $lockPath
		. ' holder_pid=' . $holderPid
		. ' holder_run_as=' . ($holderRunAs !== '' ? $holderRunAs : 'unknown')
		. ' holder_started=' . ($holderStarted !== '' ? $holderStarted : 'unknown')
		. ' holder_alive=' . ($holderAlive ? 'yes' : 'no')
		. ' age_sec=' . $ageSec
		. ' self_pid=' . $dispatcherPid;
	if ($ageSec >= $lockWarnAfterSeconds) {
		$msg .= ' warn=possible_stuck_dispatcher';
	}
	cron_dispatcher_log_line($msg);

	// Optional self-healing: terminate stale lock holder.
	$canTryKill = $stuckKillEnabled
		&& $stuckKillAfterSeconds > 0
		&& $ageSec >= $stuckKillAfterSeconds
		&& $holderPid > 0
		&& $holderAlive
		&& (empty($existingLockMeta['run_as']) || (string)$existingLockMeta['run_as'] === $runAs);
	if ($canTryKill) {
		if (!function_exists('posix_kill')) {
			cron_dispatcher_log_line('LOCK_KILL_SKIP run_as=' . $runAs . ' pid=' . $holderPid . ' reason=posix_kill_unavailable');
		} else {
			$sigNo = ($stuckKillSignal === 'KILL') ? 9 : 15;
			$ok = @posix_kill($holderPid, $sigNo);
			cron_dispatcher_log_line('LOCK_KILL_ATTEMPT run_as=' . $runAs . ' pid=' . $holderPid . ' signal=' . $stuckKillSignal . ' ok=' . ($ok ? 'yes' : 'no'));
			if ($ok) {
				usleep(300000);
				$aliveAfter = cron_dispatcher_pid_running($holderPid);
				cron_dispatcher_log_line('LOCK_KILL_RESULT run_as=' . $runAs . ' pid=' . $holderPid . ' alive_after=' . ($aliveAfter ? 'yes' : 'no'));
			}
		}
	}
	exit(0);
}

cron_dispatcher_lock_meta_write($lockFp, [
	'pid' => $dispatcherPid,
	'run_as' => $runAs,
	'host' => $dispatcherHost,
	'script' => __FILE__,
	'started_at' => gmdate('c'),
]);

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
cron_dispatcher_log_line(
	'START: run_as=' . $runAs
	. ' pid=' . $dispatcherPid
	. ' tasks=' . count($tasks)
	. ' stuck_kill=' . ($stuckKillEnabled ? 'on' : 'off')
	. ' stuck_kill_after=' . $stuckKillAfterSeconds
	. ' stuck_kill_signal=' . $stuckKillSignal
);

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
$peakMemMb = round(memory_get_peak_usage(true) / (1024 * 1024), 2);
cron_dispatcher_log_line('DONE: run_as=' . $runAs . ' pid=' . $dispatcherPid . ' ran=' . $ran . ' skipped=' . $skipped . ' errors=' . $errors . ' ms=' . $elapsedMs . ' peak_mem_mb=' . $peakMemMb);

// Keep lock held until end of process
@flock($lockFp, LOCK_UN);
@fclose($lockFp);

exit($errors > 0 ? 2 : 0);
