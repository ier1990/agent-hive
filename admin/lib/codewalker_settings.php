<?php

declare(strict_types=1);

// CodeWalker settings stored in SQLite, mirroring admin/cron.hourly/codewalker.py behavior.

function cw_settings_db_path(): string
{
    return '/web/private/db/codewalker_settings.db';
}

function cw_config_template(): array
{
    // Keep this aligned with admin/cron.hourly/codewalker.py CONFIG_TEMPLATE, plus write_root for the admin UI.
    return [
        'name' => 'CodeWalker',
        'mode' => 'que',
        'scan_path' => '/web',
        'write_root' => '/web',
        'file_types' => ['php', 'py', 'sh', 'log'],
        'actions' => ['summarize', 'rewrite', 'audit', 'test', 'docs', 'refactor'],
        'rewrite_prompt' => 'Make this code more readable and modular.',
        'deterministic_per_file' => false,
        'db_path' => '/web/private/db/inbox/codewalker.db',
        'log_path' => '/web/private/logs/codewalker.log',
        'exclude_dirs' => [
            '.git', 'vendor', 'node_modules', 'storage', 'cache', 'tmp', 'uploads', 'images', 'assets',
            '/web/iernc.com/public_cache', '/web/iernc.net', 'private/db', 'private/logs', 'private/cache', 'private/tmp',
        ],
        'max_filesize_kb' => 512,
        'log_tail_lines' => 1200,
        'backend' => 'ollama',
        'base_url' => 'http://127.0.0.1:11434',
        'api_key' => null,
        'model' => 'gpt-oss:latest',
        'model_timeout_seconds' => 900,
        'use_active_ai' => false,
        'percent_rewrite' => 40,
        'percent_audit' => 15,
        'percent_test' => 15,
        'percent_docs' => 15,
        'percent_refactor' => 15,
        'limit_per_run' => 5,
        'lockfile' => '/tmp/codewalker.lock',
        'respect_gitignore' => true,

        // Prompt templates (stored in the same settings DB)
        'prompt_rewrite_template' => 'rewrite',
        'prompt_summarize_template' => 'summarize',
        'prompt_audit_template' => 'audit',
        'prompt_test_template' => 'test',
        'prompt_docs_template' => 'docs',
        'prompt_refactor_template' => 'refactor',
    ];
}

function cw_prompt_template_defaults(): array
{
    return [
        'rewrite' => [
            'name' => 'rewrite',
            'description' => 'Default rewrite system prompt (PHP 7.3 safe)',
            'content' => (
                "You are CodeWalker, a careful refactoring assistant. Rewrite the file for clarity and modularity while preserving behavior. "
                . "Target language must remain the same. Keep comments helpful. "
                . "If the target language is PHP, ensure PHP 7.3 compatibility (avoid PHP 8+ features like match, named args, nullsafe operator, str_contains, etc). "
                . "Output only one fenced code block with the full rewritten file."
            ),
            'is_default' => 1,
        ],
        'summarize' => [
            'name' => 'summarize',
            'description' => 'Default summarize system prompt',
            'content' => (
                "You are CodeWalker, an expert static analyzer. Read the file content and produce a compact, actionable JSON summary. "
                . "Focus on purpose, key functions, inputs/outputs, dependencies, side effects, security or performance risks, and immediate TODOs. "
                . "If it is a LOG, extract patterns, error types, and anomalies from the given tail. Return *valid JSON only* with keys: "
                . "{file_purpose, key_functions, inputs_outputs, dependencies, side_effects, risks, todos, test_ideas}."
            ),
            'is_default' => 1,
        ],
        'audit' => [
            'name' => 'audit',
            'description' => 'Security and vulnerability analysis',
            'content' => (
                "You are a security auditor analyzing code for vulnerabilities, unsafe practices, and compliance issues. "
                . "Read the file carefully and produce a JSON report. Focus on: SQL injection risks, command injection, XSS vulnerabilities, authentication/authorization issues, "
                . "hardcoded secrets, insecure dependencies, unsafe file operations, race conditions, and OWASP top issues. "
                . "Return *valid JSON only* with keys: {file_path, risk_level, vulnerabilities[], unsafe_practices[], recommendations[], can_fix_automatically}. "
                . "Rate each issue as LOW, MEDIUM, HIGH, or CRITICAL."
            ),
            'is_default' => 1,
        ],
        'test' => [
            'name' => 'test',
            'description' => 'Test coverage and testing strategy analysis',
            'content' => (
                "You are a testing strategist. Analyze this code and suggest a comprehensive test strategy. "
                . "Identify functions/methods that should be tested, edge cases, error conditions, and integration points. "
                . "Return *valid JSON only* with keys: {testable_units[], edge_cases[], test_scenarios[], integration_points[], coverage_gaps[], sample_test_cases[]}. "
                . "For each test case, include: name, description, inputs, expected_output, edge_case_type."
            ),
            'is_default' => 1,
        ],
        'docs' => [
            'name' => 'docs',
            'description' => 'Generate documentation from code',
            'content' => (
                "You are a technical writer. Generate comprehensive documentation for this code. "
                . "Create clear, concise documentation in Markdown format that includes: overview, function/method descriptions (with parameters, return values, exceptions), "
                . "usage examples, dependencies, configuration options, and common pitfalls. "
                . "Use headers, code fences, and lists for clarity. Output should be immediately usable as README or API docs."
            ),
            'is_default' => 1,
        ],
        'refactor' => [
            'name' => 'refactor',
            'description' => 'Refactoring suggestions (non-breaking improvements)',
            'content' => (
                "You are a code improvement specialist. Analyze this code and suggest refactoring improvements that preserve behavior. "
                . "Focus on: code duplication, naming clarity, reducing complexity, performance quick wins, and readability. "
                . "Return *valid JSON only* with keys: {suggestions[], refactoring_priorities[], breaking_changes_risk[], estimated_effort[]}. "
                . "For each suggestion, include: title, reason, impact (LOW/MEDIUM/HIGH), code_example, risk_level."
            ),
            'is_default' => 1,
        ],
    ];
}

function cw_settings_init(PDO $pdo, array $defaults): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS prompt_templates (name TEXT PRIMARY KEY, description TEXT, content TEXT NOT NULL, is_default INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT)');

    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings(key, value) VALUES(?, ?)');
    foreach ($defaults as $k => $v) {
        $stmt->execute([(string)$k, json_encode($v)]);
    }

    // Ensure the two default templates always exist.
    $now = gmdate('c');
    $tplStmt = $pdo->prepare('INSERT OR IGNORE INTO prompt_templates(name, description, content, is_default, created_at, updated_at) VALUES(?,?,?,?,?,?)');
    foreach (cw_prompt_template_defaults() as $name => $tpl) {
        $tplStmt->execute([
            (string)$name,
            (string)($tpl['description'] ?? ''),
            (string)($tpl['content'] ?? ''),
            (int)($tpl['is_default'] ?? 0),
            $now,
            $now,
        ]);
    }
}

function cw_prompt_templates_list(?string $dbPath = null): array
{
    $dbPath = $dbPath ?: cw_settings_db_path();
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    cw_settings_init($pdo, cw_config_template());

    $rows = $pdo->query('SELECT name, description, is_default, created_at, updated_at FROM prompt_templates ORDER BY is_default DESC, name ASC')->fetchAll();
    return is_array($rows) ? $rows : [];
}

function cw_prompt_template_get(string $name, ?string $dbPath = null): ?array
{
    $dbPath = $dbPath ?: cw_settings_db_path();
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    cw_settings_init($pdo, cw_config_template());

    $stmt = $pdo->prepare('SELECT name, description, content, is_default, created_at, updated_at FROM prompt_templates WHERE name = ?');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function cw_prompt_template_upsert(string $name, string $description, string $content, ?string $dbPath = null): void
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Template name required');
    }

    $dbPath = $dbPath ?: cw_settings_db_path();
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    cw_settings_init($pdo, cw_config_template());

    $now = gmdate('c');
    $existing = cw_prompt_template_get($name, $dbPath);
    $isDefault = $existing ? (int)($existing['is_default'] ?? 0) : 0;

    $stmt = $pdo->prepare('INSERT INTO prompt_templates(name, description, content, is_default, created_at, updated_at) VALUES(?,?,?,?,?,?)\nON CONFLICT(name) DO UPDATE SET description=excluded.description, content=excluded.content, updated_at=excluded.updated_at');
    $stmt->execute([$name, $description, $content, $isDefault, $now, $now]);
}

function cw_prompt_template_delete(string $name, ?string $dbPath = null): bool
{
    $dbPath = $dbPath ?: cw_settings_db_path();
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    cw_settings_init($pdo, cw_config_template());

    $row = cw_prompt_template_get($name, $dbPath);
    if (!$row) return false;
    if ((int)($row['is_default'] ?? 0) === 1) {
        return false;
    }

    $stmt = $pdo->prepare('DELETE FROM prompt_templates WHERE name = ?');
    $stmt->execute([$name]);
    return $stmt->rowCount() > 0;
}

function cw_settings_get_all(?string $dbPath = null): array
{
    $defaults = cw_config_template();
    $dbPath = $dbPath ?: cw_settings_db_path();

    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    cw_settings_init($pdo, $defaults);

    $rows = $pdo->query('SELECT key, value FROM settings')->fetchAll();
    $out = $defaults;

    foreach ($rows as $r) {
        $k = (string)($r['key'] ?? '');
        if ($k === '') continue;
        $raw = (string)($r['value'] ?? '');
        $val = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $out[$k] = $val;
        } else {
            // Fallback: store raw
            $out[$k] = $raw;
        }
    }

    // Allow environment override for DB path (viewer DB) if set.
    $envDb = getenv('CODEWALKER_DB');
    if (is_string($envDb) && $envDb !== '') {
        $out['db_path'] = $envDb;
    }

    // Optionally follow the active AI connection (admin AI Setup)
    $useActive = !empty($out['use_active_ai']);
    if ($useActive) {
        $aiBootstrap = __DIR__ . '/../../lib/ai_bootstrap.php';
        if (is_file($aiBootstrap)) {
            require_once $aiBootstrap;
        }
        if (function_exists('ai_settings_get') && function_exists('ai_base_without_v1')) {
            try {
                $active = ai_settings_get();
                $provider = strtolower((string)($active['provider'] ?? ''));
                $backend = 'openai_compat';
                if ($provider === 'ollama') {
                    $backend = 'ollama';
                } elseif ($provider === 'local' || $provider === 'lmstudio') {
                    $backend = 'lmstudio';
                } elseif ($provider === 'openai' || $provider === 'openrouter' || $provider === 'anthropic' || $provider === 'custom') {
                    $backend = 'openai_compat';
                }

                $base = (string)($active['base_url'] ?? '');
                $base = ai_base_without_v1($base);
                $apiKey = (string)($active['api_key'] ?? '');

                // Only apply active settings if we have at least base_url and api_key (for auth-required providers)
                if ($base !== '' && ($backend === 'lmstudio' || $backend === 'ollama' || $apiKey !== '')) {
                    $out['base_url'] = $base;
                    $out['backend'] = $backend;
                    $out['api_key'] = $apiKey;
                    if (!empty($active['model'])) {
                        $out['model'] = (string)$active['model'];
                    }
                    if (!empty($active['timeout_seconds'])) {
                        $out['model_timeout_seconds'] = (int)$active['timeout_seconds'];
                    }
                } else {
                    // Active connection incomplete, fall back to hardcoded settings
                    if ($base === '') {
                        error_log('CodeWalker: Active AI connection missing base_url, using fallback');
                    }
                    if ($apiKey === '' && ($backend === 'openai_compat' || $backend === 'openrouter' || $backend === 'anthropic')) {
                        error_log('CodeWalker: Active AI connection missing API key for ' . $backend . ', using fallback');
                    }
                }
            } catch (Throwable $e) {
                error_log('CodeWalker: Failed to load active AI connection: ' . $e->getMessage());
                // Fall through to use hardcoded settings
            }
        }
    }

    return $out;
}

function cw_settings_update_many(array $values, ?string $dbPath = null): void
{
    $dbPath = $dbPath ?: cw_settings_db_path();

    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    cw_settings_init($pdo, cw_config_template());

    $stmt = $pdo->prepare('INSERT OR REPLACE INTO settings(key, value) VALUES(?, ?)');
    foreach ($values as $k => $v) {
        $stmt->execute([(string)$k, json_encode($v)]);
    }
}

function cw_settings_set(string $key, $value, ?string $dbPath = null): void
{
    $key = trim($key);
    if ($key === '') {
        throw new InvalidArgumentException('Setting key is required');
    }
    cw_settings_update_many([$key => $value], $dbPath);
}

function cw_settings_delete_key(string $key, ?string $dbPath = null): bool
{
    $key = trim($key);
    if ($key === '') return false;

    $dbPath = $dbPath ?: cw_settings_db_path();
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    cw_settings_init($pdo, cw_config_template());

    $stmt = $pdo->prepare('DELETE FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    return $stmt->rowCount() > 0;
}

function cw_settings_get_raw(?string $dbPath = null): array
{
    $dbPath = $dbPath ?: cw_settings_db_path();

    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    cw_settings_init($pdo, cw_config_template());

    $rows = $pdo->query('SELECT key, value FROM settings')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $k = (string)($r['key'] ?? '');
        if ($k === '') continue;
        $raw = (string)($r['value'] ?? '');
        $val = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $out[$k] = $val;
        } else {
            $out[$k] = $raw;
        }
    }
    return $out;
}

function cw_prompt_templates_list_full(?string $dbPath = null): array
{
    $dbPath = $dbPath ?: cw_settings_db_path();
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    cw_settings_init($pdo, cw_config_template());
    $rows = $pdo->query('SELECT name, description, content, is_default, created_at, updated_at FROM prompt_templates ORDER BY is_default DESC, name ASC')->fetchAll();
    return is_array($rows) ? $rows : [];
}

function cw_backup_export_array(?string $dbPath = null): array
{
    $dbPath = $dbPath ?: cw_settings_db_path();

    return [
        'schema' => 'codewalker_backup_v1',
        'created_at' => gmdate('c'),
        'settings' => cw_settings_get_raw($dbPath),
        'prompt_templates' => cw_prompt_templates_list_full($dbPath),
    ];
}

function cw_backup_write_json(string $path, ?string $dbPath = null): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create backup directory: ' . $dir);
        }
    }

    $payload = cw_backup_export_array($dbPath);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode backup JSON');
    }

    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write temp backup file');
    }
    @chmod($tmp, 0660);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Failed to move backup into place');
    }
}

function cw_backup_import_array(array $backup, ?string $dbPath = null): void
{
    $dbPath = $dbPath ?: cw_settings_db_path();

    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    cw_settings_init($pdo, cw_config_template());

    $pdo->beginTransaction();
    try {
        if (isset($backup['settings']) && is_array($backup['settings'])) {
            $stmt = $pdo->prepare('INSERT OR REPLACE INTO settings(key, value) VALUES(?, ?)');
            foreach ($backup['settings'] as $k => $v) {
                if (!is_string($k) || $k === '') continue;
                $stmt->execute([$k, json_encode($v)]);
            }
        }

        // Templates: upsert all provided; ensure defaults exist and remain is_default=1.
        $tplUp = $pdo->prepare(
            'INSERT INTO prompt_templates(name, description, content, is_default, created_at, updated_at) VALUES(?,?,?,?,?,?)\n'
            . 'ON CONFLICT(name) DO UPDATE SET description=excluded.description, content=excluded.content, updated_at=excluded.updated_at, is_default=prompt_templates.is_default'
        );

        $now = gmdate('c');
        if (isset($backup['prompt_templates']) && is_array($backup['prompt_templates'])) {
            foreach ($backup['prompt_templates'] as $t) {
                if (!is_array($t)) continue;
                $nm = isset($t['name']) ? trim((string)$t['name']) : '';
                if ($nm === '') continue;
                $desc = isset($t['description']) ? (string)$t['description'] : '';
                $content = isset($t['content']) ? (string)$t['content'] : '';
                if ($content === '') continue;
                $isDefault = 0; // do not allow import to mark defaults
                $tplUp->execute([$nm, $desc, $content, $isDefault, $now, $now]);
            }
        }

        // Always ensure defaults exist (and can be updated if included in backup).
        $defaults = cw_prompt_template_defaults();
        foreach ($defaults as $nm => $tpl) {
            $content = (string)($tpl['content'] ?? '');
            $desc = (string)($tpl['description'] ?? '');
            $pdo->prepare('INSERT OR IGNORE INTO prompt_templates(name, description, content, is_default, created_at, updated_at) VALUES(?,?,?,?,?,?)')
                ->execute([$nm, $desc, $content, 1, $now, $now]);
            // Ensure protected flag stays
            $pdo->prepare('UPDATE prompt_templates SET is_default=1 WHERE name=?')->execute([$nm]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function cw_http_get_json(string $url, array $headers = [], int $timeoutSeconds = 2): ?array
{
    $timeoutSeconds = max(1, $timeoutSeconds);

    // Prefer curl when available (more reliable for hosts without allow_url_fopen).
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) return null;

        $hdrs = [];
        foreach ($headers as $k => $v) {
            $hdrs[] = $k . ': ' . $v;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        if ($hdrs) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);

        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body) || $body === '') return null;
        if ($code < 200 || $code >= 300) return null;

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) return null;
        return $decoded;
    }

    $headerLines = [];
    foreach ($headers as $k => $v) {
        $headerLines[] = $k . ': ' . $v;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headerLines),
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    if (!is_string($body) || $body === '') return null;

    $decoded = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) return null;
    return $decoded;
}

function cw_discover_models(array $cfg, int $timeoutSeconds = 2): array
{
    $backend = strtolower((string)($cfg['backend'] ?? ''));
    $baseUrl = rtrim((string)($cfg['base_url'] ?? ''), '/');
    if ($baseUrl === '') return [];

    $apiKey = $cfg['api_key'] ?? null;
    $apiKey = is_string($apiKey) && $apiKey !== '' ? $apiKey : null;

    $commonHeaders = [
        'Accept' => 'application/json',
    ];

    $openaiHeaders = $commonHeaders;
    if ($apiKey !== null) {
        $openaiHeaders['Authorization'] = 'Bearer ' . $apiKey;
    }

    $candidates = [];

    // If backend is auto/blank, try OpenAI-compatible first, then Ollama.
    if ($backend === '' || $backend === 'auto' || $backend === 'lmstudio' || $backend === 'openai_compat') {
        $candidates[] = ['kind' => 'openai', 'url' => $baseUrl . '/v1/models'];
    }
    if ($backend === '' || $backend === 'auto' || $backend === 'ollama') {
        $candidates[] = ['kind' => 'ollama', 'url' => $baseUrl . '/api/tags'];
    }

    $models = [];

    foreach ($candidates as $cand) {
        $kind = (string)$cand['kind'];
        $url = (string)$cand['url'];
        $headers = $kind === 'openai' ? $openaiHeaders : $commonHeaders;

        $data = cw_http_get_json($url, $headers, $timeoutSeconds);
        if (!is_array($data)) continue;

        if ($kind === 'ollama') {
            // Ollama: {"models": [{"name": "llama3.2:latest"}, ...]}
            if (isset($data['models']) && is_array($data['models'])) {
                foreach ($data['models'] as $m) {
                    if (is_array($m) && isset($m['name']) && is_string($m['name']) && $m['name'] !== '') {
                        $models[] = $m['name'];
                    }
                }
            }
        } else {
            // OpenAI-compatible: {"data": [{"id":"gpt-4o-mini"}, ...]}
            if (isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $m) {
                    if (is_array($m) && isset($m['id']) && is_string($m['id']) && $m['id'] !== '') {
                        $models[] = $m['id'];
                    }
                }
            } elseif (isset($data['models']) && is_array($data['models'])) {
                foreach ($data['models'] as $m) {
                    if (is_string($m) && $m !== '') $models[] = $m;
                    if (is_array($m) && isset($m['id']) && is_string($m['id']) && $m['id'] !== '') {
                        $models[] = $m['id'];
                    }
                }
            }
        }

        if ($models) break;
    }

    // De-dupe + stable sort
    $uniq = [];
    foreach ($models as $m) {
        if (!is_string($m)) continue;
        $m = trim($m);
        if ($m === '') continue;
        $uniq[$m] = true;
    }
    $out = array_keys($uniq);
    sort($out, SORT_STRING);
    return $out;
}
