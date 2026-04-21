<?php

declare(strict_types=1);

function agent_bash_db_path(): string
{
    return PRIVATE_ROOT . '/db/agent_tools.db';
}

function agent_bash_settings_path(): string
{
    return PRIVATE_ROOT . '/agent_bash.json';
}

function agent_bash_tool_settings_path(): string
{
    return PRIVATE_ROOT . '/agent_tools.json';
}

function agent_bash_open_db(): PDO
{
    $path = agent_bash_db_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    agent_bash_ensure_schema($pdo);
    return $pdo;
}

function agent_bash_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bash_proposals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            command_text TEXT NOT NULL,
            cwd TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "proposed",
            risk_level TEXT NOT NULL DEFAULT "medium",
            operator_summary TEXT NOT NULL DEFAULT "",
            tutorial_summary TEXT NOT NULL DEFAULT "{}",
            metadata_json TEXT NOT NULL DEFAULT "{}",
            proposed_by TEXT NOT NULL DEFAULT "agent",
            proposed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_by TEXT NOT NULL DEFAULT "",
            approved_at TEXT,
            executed_by TEXT NOT NULL DEFAULT "",
            executed_at TEXT,
            exit_code INTEGER,
            stdout_preview TEXT NOT NULL DEFAULT "",
            stderr_preview TEXT NOT NULL DEFAULT "",
            result_json TEXT NOT NULL DEFAULT "{}",
            notes TEXT NOT NULL DEFAULT ""
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bash_proposals_status ON bash_proposals(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bash_proposals_time ON bash_proposals(proposed_at)');
}

function agent_bash_load_settings(): array
{
    $defaults = [
        'enabled' => true,
        'db_path' => agent_bash_db_path(),
        'max_command_length' => 1200,
        'proposal_limit' => 100,
        'execution_timeout_seconds' => 30,
        'allowed_roots' => [
            APP_ROOT,
            PRIVATE_ROOT,
            PRIVATE_ROOT . '/scripts',
            PRIVATE_ROOT . '/logs',
            '/tmp',
        ],
    ];

    $directPath = agent_bash_settings_path();
    if (is_file($directPath) && is_readable($directPath)) {
        $raw = @file_get_contents($directPath);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                $defaults[$key] = $value;
            }
        }
    }

    $legacyPath = agent_bash_tool_settings_path();
    if (is_file($legacyPath) && is_readable($legacyPath)) {
        $raw = @file_get_contents($legacyPath);
        if (is_string($raw) && trim($raw) !== '') {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['bash']) && is_array($data['bash'])) {
                foreach ($data['bash'] as $key => $value) {
                    if ($value === '' || $value === null) {
                        continue;
                    }
                    $defaults[$key] = $value;
                }
            }
        }
    }
    return $defaults;
}

function agent_bash_allowed_roots(): array
{
    $cfg = agent_bash_load_settings();
    $raw = isset($cfg['allowed_roots']) && is_array($cfg['allowed_roots']) ? $cfg['allowed_roots'] : [];
    $out = [];
    foreach ($raw as $root) {
        $text = trim((string)$root);
        if ($text === '') continue;
        $real = @realpath($text);
        if (is_string($real) && $real !== '') {
            $out[] = str_replace('\\', '/', $real);
        }
    }
    return array_values(array_unique($out));
}

function agent_bash_path_allowed(string $path): bool
{
    $real = @realpath($path);
    if (!is_string($real) || $real === '') {
        return false;
    }
    $real = str_replace('\\', '/', $real);
    foreach (agent_bash_allowed_roots() as $root) {
        if ($real === $root) return true;
        if (strpos($real, rtrim($root, '/') . '/') === 0) return true;
    }
    return false;
}

function agent_bash_list(PDO $pdo, int $limit = 100): array
{
    $stmt = $pdo->prepare('SELECT * FROM bash_proposals ORDER BY id DESC LIMIT ?');
    $stmt->bindValue(1, max(1, min($limit, 500)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function agent_bash_get(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM bash_proposals WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function agent_bash_set_status(PDO $pdo, int $id, string $status, string $user): bool
{
    $allowed = ['approved', 'canceled'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }
    if ($status === 'approved') {
        $stmt = $pdo->prepare('UPDATE bash_proposals SET status = ?, approved_by = ?, approved_at = CURRENT_TIMESTAMP WHERE id = ?');
        return $stmt->execute([$status, $user, $id]);
    }
    $stmt = $pdo->prepare('UPDATE bash_proposals SET status = ?, notes = CASE WHEN notes = "" THEN "Canceled by admin" ELSE notes END WHERE id = ?');
    return $stmt->execute([$status, $id]);
}

function agent_bash_delete(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM bash_proposals WHERE id = ?');
    return $stmt->execute([$id]);
}

function agent_bash_execute(PDO $pdo, int $id, string $user): array
{
    $row = agent_bash_get($pdo, $id);
    if (!$row) {
        return ['ok' => false, 'error' => 'proposal_not_found'];
    }
    if ((string)($row['status'] ?? '') !== 'approved') {
        return ['ok' => false, 'error' => 'proposal_not_approved'];
    }

    $cwd = (string)($row['cwd'] ?? '');
    if ($cwd === '' || !is_dir($cwd) || !agent_bash_path_allowed($cwd)) {
        return ['ok' => false, 'error' => 'cwd_not_allowed'];
    }

    $command = (string)($row['command_text'] ?? '');
    if (trim($command) === '') {
        return ['ok' => false, 'error' => 'empty_command'];
    }

    $cfg = agent_bash_load_settings();
    $timeout = max(1, (int)($cfg['execution_timeout_seconds'] ?? 30));
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $cmd = 'bash -lc ' . escapeshellarg($command);
    $pipes = [];
    $proc = @proc_open($cmd, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($proc)) {
        return ['ok' => false, 'error' => 'proc_open_failed'];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = microtime(true);
    $timedOut = false;
    $observedExitCode = null;

    while (true) {
        $status = proc_get_status($proc);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        if (!$status['running']) {
            if (isset($status['exitcode']) && (int)$status['exitcode'] >= 0) {
                $observedExitCode = (int)$status['exitcode'];
            }
            break;
        }
        if ((microtime(true) - $start) >= $timeout) {
            $timedOut = true;
            @proc_terminate($proc, 15);
            usleep(200000);
            $status = proc_get_status($proc);
            if (!empty($status['running'])) {
                @proc_terminate($proc, 9);
            }
            break;
        }
        usleep(100000);
    }

    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    if (!$timedOut && $exitCode === -1 && $observedExitCode !== null) {
        $exitCode = $observedExitCode;
    }
    if ($timedOut) {
        $exitCode = -1;
        if ($stderr === '') {
            $stderr = 'Timed out after ' . $timeout . ' seconds';
        }
    }

    $statusText = $exitCode === 0 ? 'executed' : 'failed';
    $result = [
        'exit_code' => $exitCode,
        'timed_out' => $timedOut,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'ran_at' => gmdate('c'),
    ];

    $stmt = $pdo->prepare(
        'UPDATE bash_proposals
         SET status = ?, executed_by = ?, executed_at = CURRENT_TIMESTAMP, exit_code = ?, stdout_preview = ?, stderr_preview = ?, result_json = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $statusText,
        $user,
        $exitCode,
        substr($stdout, 0, 4000),
        substr($stderr, 0, 4000),
        json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $id,
    ]);

    return ['ok' => true, 'status' => $statusText, 'result' => $result];
}
