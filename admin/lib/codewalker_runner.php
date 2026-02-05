<?php

declare(strict_types=1);

require_once __DIR__ . '/codewalker_helpers.php';
require_once __DIR__ . '/codewalker_settings.php';

function cw_cwdb_pdo(string $dbPath): PDO
{
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    return $pdo;
}

function cw_cwdb_init(PDO $pdo): void
{
    // Mirrors the schema used by admin/cron.hourly/codewalker.py
    $pdo->exec('PRAGMA journal_mode=WAL');

    $pdo->exec('CREATE TABLE IF NOT EXISTS files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        path TEXT UNIQUE,
        ext TEXT,
        first_seen TEXT,
        last_seen TEXT,
        last_hash TEXT
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS runs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        started_at TEXT,
        finished_at TEXT,
        host TEXT,
        pid INTEGER,
        config_json TEXT
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS actions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        run_id INTEGER,
        file_id INTEGER,
        action TEXT,
        model TEXT,
        backend TEXT,
        prompt TEXT,
        file_hash TEXT,
        tokens_in INTEGER,
        tokens_out INTEGER,
        status TEXT,
        error TEXT,
        created_at TEXT
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS summaries (
        action_id INTEGER PRIMARY KEY,
        summary TEXT
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS rewrites (
        action_id INTEGER PRIMARY KEY,
        rewrite TEXT,
        diff TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS audits (
        action_id INTEGER PRIMARY KEY,
        findings TEXT
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS tests (
        action_id INTEGER PRIMARY KEY,
        strategy TEXT
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS docs (
        action_id INTEGER PRIMARY KEY,
        documentation TEXT
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS refactors (
        action_id INTEGER PRIMARY KEY,
        suggestions TEXT
    )');
    $pdo->exec('CREATE VIEW IF NOT EXISTS vw_last_actions AS
        SELECT a.*, f.path FROM actions a
        JOIN files f ON f.id = a.file_id
        WHERE a.id IN (
            SELECT MAX(id) FROM actions GROUP BY file_id
        )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS queued_files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        path TEXT UNIQUE,
        requested_at TEXT,
        requested_by TEXT,
        notes TEXT,
        status TEXT DEFAULT "pending"
    )');

    // Used by the admin apply UI.
    $pdo->exec('CREATE TABLE IF NOT EXISTS applied_rewrites (
        action_id INTEGER PRIMARY KEY,
        applied_at TEXT,
        applied_by TEXT,
        backup_path TEXT,
        result TEXT,
        notes TEXT
    )');
}

function cw_iso(): string
{
    // Use UTC-ish ISO string.
    return gmdate('c');
}

// Create a fingerprint hash for deduplication (filename + filesize + model + action)
function cw_file_fingerprint(string $path, string $model, string $action): string
{
    $filesize = @filesize($path);
    if ($filesize === false) $filesize = 0;
    $key = $path . '|' . (int)$filesize . '|' . $model . '|' . $action;
    return hash('sha256', $key);
}

// Pick a random action based on configured percentages
function cw_pick_random_action(array $cfg): string
{
    $percent_rewrite = (int)($cfg['percent_rewrite'] ?? 40);
    $percent_audit = (int)($cfg['percent_audit'] ?? 15);
    $percent_test = (int)($cfg['percent_test'] ?? 15);
    $percent_docs = (int)($cfg['percent_docs'] ?? 15);
    $percent_refactor = (int)($cfg['percent_refactor'] ?? 15);
    
    // Normalize to 100% (in case they don't add up)
    $total = $percent_rewrite + $percent_audit + $percent_test + $percent_docs + $percent_refactor;
    if ($total === 0) {
        // Default fallback
        return 'summarize';
    }
    
    // Generate random number 0-99 and pick action based on ranges
    $rand = random_int(0, 99);
    $cumulative = 0;
    
    $cumulative += $percent_rewrite;
    if ($rand < $cumulative) return 'rewrite';
    
    $cumulative += $percent_audit;
    if ($rand < $cumulative) return 'audit';
    
    $cumulative += $percent_test;
    if ($rand < $cumulative) return 'test';
    
    $cumulative += $percent_docs;
    if ($rand < $cumulative) return 'docs';
    
    $cumulative += $percent_refactor;
    if ($rand < $cumulative) return 'refactor';
    
    // Fallback
    return 'summarize';
}

function cw_is_file_already_processed(PDO $pdo, string $path, string $model, string $action): bool
{
    $filesize = @filesize($path);
    if ($filesize === false) $filesize = 0;
    
    $stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM actions a JOIN files f ON f.id = a.file_id 
        WHERE f.path = ? AND a.model = ? AND a.action = ? AND a.status = ?');
    $stmt->execute([$path, $model, strtolower($action), 'ok']);
    $row = $stmt->fetch();
    $count = (int)($row['cnt'] ?? 0);
    
    if ($count === 0) {
        return false; // Not processed yet
    }
    
    // Found a previous action - check if file has changed by comparing filesize or last_seen
    $stmt = $pdo->prepare('SELECT MAX(a.created_at) as last_run FROM actions a JOIN files f ON f.id = a.file_id 
        WHERE f.path = ? AND a.model = ? AND a.action = ? AND a.status = ? LIMIT 1');
    $stmt->execute([$path, $model, strtolower($action), 'ok']);
    $row = $stmt->fetch();
    $lastRunTime = isset($row['last_run']) ? strtotime((string)$row['last_run']) : 0;
    $lastModTime = (int)@filemtime($path);
    
    // If file was modified after last run, reprocess it
    if ($lastModTime > $lastRunTime) {
        return false;
    }
    
    return true;
}

function cw_tail_lines(string $path, int $n): string
{
    $n = max(1, $n);
    if (!is_file($path)) return '';

    $fh = @fopen($path, 'rb');
    if (!$fh) return '';

    $buffer = '';
    $chunkSize = 8192;
    $pos = -1;
    $lines = 0;

    fseek($fh, 0, SEEK_END);
    $filesize = ftell($fh);
    if ($filesize === false) {
        fclose($fh);
        return '';
    }

    $cursor = $filesize;

    while ($cursor > 0 && $lines <= $n) {
        $read = min($chunkSize, $cursor);
        $cursor -= $read;
        fseek($fh, $cursor);
        $chunk = fread($fh, $read);
        if ($chunk === false) break;
        $buffer = $chunk . $buffer;
        $lines = substr_count($buffer, "\n");
    }

    fclose($fh);

    $parts = preg_split('/\r\n|\n|\r/', $buffer);
    if (!is_array($parts)) return '';
    $tail = array_slice($parts, -$n);
    return implode("\n", $tail);
}

function cw_read_payload(string $path, array $cfg): array
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if ($ext === 'log') {
        $n = (int)($cfg['log_tail_lines'] ?? 1200);
        return [cw_tail_lines($path, $n), $ext];
    }

    $maxKb = (int)($cfg['max_filesize_kb'] ?? 512);
    $maxBytes = max(1, $maxKb) * 1024;

    $data = @file_get_contents($path, false, null, 0, $maxBytes);
    if (!is_string($data)) $data = '';
    return [$data, $ext];
}

function cw_extract_first_codeblock(string $text): ?array
{
    if (preg_match('/```([a-zA-Z0-9_+\-]*)\n([\s\S]*?)```/m', $text, $m)) {
        $lang = isset($m[1]) ? trim((string)$m[1]) : '';
        $body = isset($m[2]) ? (string)$m[2] : '';
        return [$lang, $body];
    }
    return null;
}

function cw_unified_diff_external(string $oldText, string $newText): string
{
    // Best-effort diff via system diff -u. If diff is unavailable, return empty string.
    $tmpDir = sys_get_temp_dir();
    $a = tempnam($tmpDir, 'cw_old_');
    $b = tempnam($tmpDir, 'cw_new_');
    if (!$a || !$b) return '';

    file_put_contents($a, $oldText);
    file_put_contents($b, $newText);

    $cmd = 'diff -u ' . escapeshellarg($a) . ' ' . escapeshellarg($b);

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = @proc_open($cmd, $descriptors, $pipes);
    $out = '';
    if (is_resource($proc)) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        // diff returns 1 when files differ, 0 when identical.
        if (is_string($stdout) && $stdout !== '') {
            $out = $stdout;
        } elseif ($code === 127) {
            $out = '';
        } else {
            $out = is_string($stderr) ? $stderr : '';
        }
    }

    @unlink($a);
    @unlink($b);

    return (string)$out;
}

class CWLLMError extends RuntimeException {}

function cw_llm_chat_openai_compat(array $cfg, array $messages, string $model): array
{
    $base = rtrim((string)($cfg['base_url'] ?? ''), '/');
    if ($base === '') throw new CWLLMError('Missing base_url');

    $url = $base . '/v1/chat/completions';

    $timeout = (int)($cfg['model_timeout_seconds'] ?? ($cfg['timeout_seconds'] ?? 900));
    if ($timeout < 1) $timeout = 1;

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.2,
    ];

    $headers = [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];

    $apiKey = $cfg['api_key'] ?? null;
    if (is_string($apiKey) && $apiKey !== '') {
        $headers['Authorization'] = 'Bearer ' . $apiKey;
    }

    $ch = curl_init($url);
    if ($ch === false) throw new CWLLMError('curl_init failed');

    $hdrs = [];
    foreach ($headers as $k => $v) $hdrs[] = $k . ': ' . $v;

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!is_string($body)) throw new CWLLMError('HTTP request failed: ' . $err);
    if ($code >= 400) throw new CWLLMError('HTTP ' . $code . ': ' . substr($body, 0, 200));

    $j = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($j)) {
        throw new CWLLMError('Invalid JSON response');
    }

    $text = '';
    if (isset($j['choices'][0]['message']['content'])) {
        $text = (string)$j['choices'][0]['message']['content'];
    }

    $usage = isset($j['usage']) && is_array($j['usage']) ? $j['usage'] : [];

    if ($text === '') throw new CWLLMError('Empty content');

    return [$text, ['backend' => 'openai_compat', 'raw' => $j, 'usage' => $usage]];
}

function cw_llm_chat_ollama(array $cfg, array $messages, string $model): array
{
    $base = rtrim((string)($cfg['base_url'] ?? ''), '/');
    if ($base === '') throw new CWLLMError('Missing base_url');

    $url = $base . '/api/chat';

    $timeout = (int)($cfg['model_timeout_seconds'] ?? ($cfg['timeout_seconds'] ?? 900));
    if ($timeout < 1) $timeout = 1;

    // Ollama can be picky about model names; use configured model.
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'options' => ['temperature' => 0.2],
        'stream' => false,
    ];

    $ch = curl_init($url);
    if ($ch === false) throw new CWLLMError('curl_init failed');

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!is_string($body)) throw new CWLLMError('HTTP request failed: ' . $err);
    if ($code >= 400) throw new CWLLMError('HTTP ' . $code . ': ' . substr($body, 0, 200));

    $j = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($j)) {
        // Tolerate NDJSON: take last parseable line.
        $last = null;
        foreach (preg_split('/\r\n|\n|\r/', trim($body)) as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            $tmp = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                $last = $tmp;
            }
        }
        if (!is_array($last)) throw new CWLLMError('Invalid JSON response');
        $j = $last;
    }

    $text = '';
    if (isset($j['message']['content'])) {
        $text = (string)$j['message']['content'];
    } elseif (isset($j['content'])) {
        $text = (string)$j['content'];
    }

    if ($text === '') throw new CWLLMError('Empty content');

    return [$text, ['backend' => 'ollama', 'raw' => $j]];
}

function cw_llm_chat(array $cfg, array $messages, ?string $modelOverride = null): array
{
    $backend = strtolower((string)($cfg['backend'] ?? 'auto'));
    $model = $modelOverride ?: (string)($cfg['model'] ?? 'gpt-oss:latest');

    $tried = [];
    $last = null;

    $tryFns = [];
    if ($backend === 'auto' || $backend === '') {
        $tryFns = ['lmstudio', 'ollama', 'openai_compat'];
    } elseif ($backend === 'lmstudio') {
        $tryFns = ['lmstudio'];
    } elseif ($backend === 'ollama') {
        $tryFns = ['ollama'];
    } elseif (
        $backend === 'openai_compat'
        || $backend === 'custom'
        || $backend === 'openai'
        || $backend === 'openrouter'
        || $backend === 'anthropic'
    ) {
        $tryFns = ['openai_compat'];
    } else {
        throw new CWLLMError('Unknown backend: ' . $backend);
    }

    foreach ($tryFns as $name) {
        try {
            $tried[] = $name;
            if ($name === 'ollama') {
                return cw_llm_chat_ollama($cfg, $messages, $model);
            }
            // LM Studio is OpenAI-compatible.
            if ($name === 'lmstudio') {
                return cw_llm_chat_openai_compat($cfg, $messages, $model);
            }
            if ($name === 'openai_compat') {
                return cw_llm_chat_openai_compat($cfg, $messages, $model);
            }
        } catch (Throwable $e) {
            $last = $e;
            continue;
        }
    }

    throw new CWLLMError('All backends failed (tried: ' . implode(',', $tried) . '): ' . ($last ? $last->getMessage() : 'unknown'));
}

function cw_prompt_content(array $cfg, string $kind): string
{
    // $kind: rewrite|summarize
    $defaults = cw_prompt_template_defaults();
    $fallback = isset($defaults[$kind]['content']) ? (string)$defaults[$kind]['content'] : '';

    $key = $kind === 'rewrite' ? 'prompt_rewrite_template' : 'prompt_summarize_template';
    $name = (string)($cfg[$key] ?? $kind);

    $tpl = cw_prompt_template_get($name);
    if (is_array($tpl) && isset($tpl['content']) && is_string($tpl['content']) && trim($tpl['content']) !== '') {
        return (string)$tpl['content'];
    }

    // Fall back to default name if misconfigured.
    $tpl2 = cw_prompt_template_get($kind);
    if (is_array($tpl2) && isset($tpl2['content']) && is_string($tpl2['content']) && trim($tpl2['content']) !== '') {
        return (string)$tpl2['content'];
    }

    return $fallback;
}

function cw_cwdb_get_or_create_file(PDO $pdo, string $path, string $ext, string $hash): int
{
    $now = cw_iso();

    $stmt = $pdo->prepare('SELECT id FROM files WHERE path=?');
    $stmt->execute([$path]);
    $row = $stmt->fetch();

    if (is_array($row) && isset($row['id'])) {
        $id = (int)$row['id'];
        $pdo->prepare('UPDATE files SET last_seen=?, ext=?, last_hash=? WHERE id=?')->execute([$now, $ext, $hash, $id]);
        return $id;
    }

    $pdo->prepare('INSERT INTO files(path,ext,first_seen,last_seen,last_hash) VALUES(?,?,?,?,?)')
        ->execute([$path, $ext, $now, $now, $hash]);

    return (int)$pdo->lastInsertId();
}

function cw_cwdb_insert_run(PDO $pdo, array $cfg): int
{
    $now = cw_iso();
    $host = function_exists('gethostname') ? (string)gethostname() : php_uname('n');
    $pid = function_exists('getmypid') ? (int)getmypid() : 0;

    $pdo->prepare('INSERT INTO runs(started_at,finished_at,host,pid,config_json) VALUES(?,?,?,?,?)')
        ->execute([$now, $now, $host, $pid, json_encode($cfg, JSON_UNESCAPED_SLASHES)]);

    return (int)$pdo->lastInsertId();
}

function cw_cwdb_insert_action(PDO $pdo, int $runId, int $fileId, string $action, string $model, string $backend, string $prompt, string $fileHash, string $status, ?string $error, ?int $tokensIn, ?int $tokensOut): int
{
    $pdo->prepare('INSERT INTO actions(run_id,file_id,action,model,backend,prompt,file_hash,tokens_in,tokens_out,status,error,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$runId, $fileId, $action, $model, $backend, $prompt, $fileHash, $tokensIn, $tokensOut, $status, $error ?: '', cw_iso()]);

    return (int)$pdo->lastInsertId();
}

function cw_run_on_file(string $path, ?string $actionOverride = null): array
{
    $cfg = cw_settings_get_all();

    $dbPath = isset($cfg['db_path']) ? (string)$cfg['db_path'] : '';
    if ($dbPath === '') throw new RuntimeException('Missing db_path');

    // Debug logging for settings (especially when use_active_ai is enabled)
    if (!empty($cfg['use_active_ai']) && php_sapi_name() === 'cli') {
        error_log('[CodeWalker] use_active_ai=true, backend=' . ($cfg['backend'] ?? 'unknown') . ', base_url=' . ($cfg['base_url'] ?? 'unknown'));
    }

    $scanRoot = isset($cfg['scan_path']) ? rtrim((string)$cfg['scan_path'], '/') : '';
    if ($scanRoot !== '' && !cw_starts_with($path, $scanRoot . '/')) {
        // Allow exact scan root file, too.
        if ($path !== $scanRoot) {
            throw new RuntimeException('Blocked path outside scan_path');
        }
    }

    if (!is_file($path)) throw new RuntimeException('Not a file: ' . $path);

    $pdo = cw_cwdb_pdo($dbPath);
    cw_cwdb_init($pdo);

    // Determine action early for deduplication check
    $action = $actionOverride ? strtolower($actionOverride) : '';
    if ($action === '' || $action === 'auto') {
        [$payload, $ext] = cw_read_payload($path, $cfg);
        // Summarize logs, pick random action for code
        if ($ext === 'log') {
            $action = 'summarize';
        } else {
            $action = cw_pick_random_action($cfg);
        }
    } else {
        [$payload, $ext] = cw_read_payload($path, $cfg);
    }
    
    // Validate action
    $validActions = ['summarize', 'rewrite', 'audit', 'test', 'docs', 'refactor'];
    if (!in_array($action, $validActions, true)) {
        throw new RuntimeException('Invalid action: ' . $action);
    }

    $model = isset($cfg['model']) ? (string)$cfg['model'] : 'gpt-oss:latest';

    // Check if already processed with same model/action (deduplication)
    if (cw_is_file_already_processed($pdo, $path, $model, $action)) {
        // Return a synthetic result indicating it was skipped
        return [
            'action_id' => 0,
            'status' => 'skip',
            'error' => 'Already processed',
        ];
    }

    $runId = cw_cwdb_insert_run($pdo, $cfg);

    $full = @file_get_contents($path);
    if (!is_string($full)) $full = '';

    $hash = sha256_file_s($path) ?: '';
    if ($hash === '') throw new RuntimeException('Failed to hash file');

    if (trim((string)$payload) === '') throw new RuntimeException('Empty payload');

    $fileId = cw_cwdb_get_or_create_file($pdo, $path, $ext, $hash);

    $fileMeta = "File: {$path}\nExt: {$ext}\nSize: " . (string)filesize($path) . " bytes\nLastModified: " . date('c', (int)filemtime($path)) . "\n";

    $status = 'ok';
    $err = null;
    $backendUsed = (string)($cfg['backend'] ?? '');
    $tokensIn = null;
    $tokensOut = null;

    if ($action === 'summarize') {
        $system = cw_prompt_content($cfg, 'summarize');
        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $fileMeta . "\nCONTENT:\n```{$ext}\n{$payload}\n```"],
        ];

        try {
            [$text, $meta] = cw_llm_chat($cfg, $messages, $model);
            if (isset($meta['backend'])) $backendUsed = (string)$meta['backend'];
            if (isset($meta['usage']['prompt_tokens'])) $tokensIn = (int)$meta['usage']['prompt_tokens'];
            if (isset($meta['usage']['completion_tokens'])) $tokensOut = (int)$meta['usage']['completion_tokens'];

            $summary = trim((string)$text);
            
            // Validate non-empty response
            if ($summary === '') {
                throw new RuntimeException('Empty response from AI model');
            }
            
            // validate JSON
            $tmp = json_decode($summary, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $summary = json_encode(['raw' => $text], JSON_UNESCAPED_SLASHES);
            }
        } catch (Throwable $e) {
            $status = 'error';
            $err = $e->getMessage();
            $summary = '';
        }

        $actionId = cw_cwdb_insert_action($pdo, $runId, $fileId, 'summarize', $model, $backendUsed, $system, $hash, $status, $err, $tokensIn, $tokensOut);
        if ($status === 'ok' && $summary !== '') {
            $pdo->prepare('INSERT OR REPLACE INTO summaries(action_id,summary) VALUES(?,?)')->execute([$actionId, $summary]);
        }

        return ['action_id' => $actionId, 'status' => $status, 'error' => $err];
    }

    // Handle other action types (rewrite, audit, test, docs, refactor)
    $promptKey = 'prompt_' . $action . '_template';
    $defaultPromptName = $action;
    $system = cw_prompt_content($cfg, $defaultPromptName);
    
    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $fileMeta . "\n```{$ext}\n" . str_replace('```', '``\\`', $payload) . "\n```"],
    ];

    $resultText = '';
    $diff = '';
    $status = 'ok';
    $err = null;

    try {
        [$text, $meta] = cw_llm_chat($cfg, $messages, $model);
        if (isset($meta['backend'])) $backendUsed = (string)$meta['backend'];
        if (isset($meta['usage']['prompt_tokens'])) $tokensIn = (int)$meta['usage']['prompt_tokens'];
        if (isset($meta['usage']['completion_tokens'])) $tokensOut = (int)$meta['usage']['completion_tokens'];

        $resultText = (string)$text;
        
        // For rewrite action, extract code block and compute diff
        if ($action === 'rewrite') {
            $blk = cw_extract_first_codeblock((string)$text);
            $resultText = $blk ? (string)$blk[1] : (string)$text;
            $diff = cw_unified_diff_external($full, $resultText);
        }
    } catch (Throwable $e) {
        $status = 'error';
        $err = $e->getMessage();
    }

    // Validate result is not empty before saving
    $resultTrimmed = trim($resultText);
    if ($status === 'ok' && $resultTrimmed === '') {
        $status = 'error';
        $err = 'Empty response from AI model';
    }
    
    $actionId = cw_cwdb_insert_action($pdo, $runId, $fileId, $action, $model, $backendUsed, $system, $hash, $status, $err, $tokensIn, $tokensOut);
    
    if ($status === 'ok' && $resultTrimmed !== '') {
        // Store results in action-specific tables (only if non-empty)
        if ($action === 'summarize') {
            $pdo->prepare('INSERT OR REPLACE INTO summaries(action_id,summary) VALUES(?,?)')->execute([$actionId, $resultText]);
        } elseif ($action === 'rewrite') {
            $pdo->prepare('INSERT OR REPLACE INTO rewrites(action_id,rewrite,diff) VALUES(?,?,?)')->execute([$actionId, $resultText, $diff]);
        } elseif ($action === 'audit') {
            $pdo->prepare('INSERT OR REPLACE INTO audits(action_id,findings) VALUES(?,?)')->execute([$actionId, $resultText]);
        } elseif ($action === 'test') {
            $pdo->prepare('INSERT OR REPLACE INTO tests(action_id,strategy) VALUES(?,?)')->execute([$actionId, $resultText]);
        } elseif ($action === 'docs') {
            $pdo->prepare('INSERT OR REPLACE INTO docs(action_id,documentation) VALUES(?,?)')->execute([$actionId, $resultText]);
        } elseif ($action === 'refactor') {
            $pdo->prepare('INSERT OR REPLACE INTO refactors(action_id,suggestions) VALUES(?,?)')->execute([$actionId, $resultText]);
        }
    }

    return ['action_id' => $actionId, 'status' => $status, 'error' => $err];
}
