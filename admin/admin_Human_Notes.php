<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

header('Content-Type: text/html; charset=utf-8');

function human_notes_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function human_notes_session_start()
{
    if (function_exists('auth_session_start')) {
        auth_session_start();
    } elseif (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function human_notes_csrf_token()
{
    human_notes_session_start();
    if (empty($_SESSION['csrf_human_notes'])) {
        $_SESSION['csrf_human_notes'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf_human_notes'];
}

function human_notes_csrf_ok($token)
{
    human_notes_session_start();
    return isset($_SESSION['csrf_human_notes']) && is_string($token) && hash_equals((string)$_SESSION['csrf_human_notes'], $token);
}

function human_notes_db_path()
{
    return rtrim((string)PRIVATE_ROOT, '/\\') . '/db/memory/human_notes.db';
}

function human_notes_types()
{
    return [
        'human' => 'General',
        'passwords' => 'Passwords',
        'code_snippet' => 'Code Snippet',
        'links' => 'Links',
        'files' => 'Files',
        'reminders' => 'Reminders',
        'system_generated' => 'System Generated',
        'ai_generated' => 'AI Generated',
        'logs' => 'Logs',
    ];
}

function human_notes_default_form()
{
    return [
        'id' => 0,
        'topic' => '',
        'tags_csv' => '',
        'notes_type' => 'human',
        'note' => '',
    ];
}

function human_notes_open_db()
{
    $path = human_notes_db_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec(
        'CREATE TABLE IF NOT EXISTS notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            notes_type TEXT NOT NULL,
            topic TEXT,
            node TEXT,
            path TEXT,
            version TEXT,
            ts TEXT,
            note TEXT NOT NULL,
            parent_id INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $db->exec('CREATE INDEX IF NOT EXISTS idx_notes_created ON notes(created_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_notes_type_created ON notes(notes_type, created_at DESC)');

    $columns = [];
    $res = $db->query('PRAGMA table_info(notes)');
    while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
        $columns[strtolower((string)$row['name'])] = true;
    }
    if (!isset($columns['tags_csv'])) {
        $db->exec('ALTER TABLE notes ADD COLUMN tags_csv TEXT');
    }
    if (!isset($columns['is_archived'])) {
        $db->exec('ALTER TABLE notes ADD COLUMN is_archived INTEGER DEFAULT 0');
    }
    $db->exec('CREATE INDEX IF NOT EXISTS idx_notes_archived_updated ON notes(is_archived, updated_at DESC)');

    return $db;
}

function human_notes_normalize_tags($tagsCsv)
{
    $parts = preg_split('/[\r\n,]+/', (string)$tagsCsv);
    $seen = [];
    $out = [];
    if (!is_array($parts)) {
        return '';
    }
    foreach ($parts as $part) {
        $tag = trim((string)$part);
        if ($tag === '') {
            continue;
        }
        $key = strtolower($tag);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $tag;
    }
    return implode(', ', $out);
}

function human_notes_fetch_note_by_id(SQLite3 $db, $id)
{
    $stmt = $db->prepare('SELECT id, topic, notes_type, note, COALESCE(tags_csv, \'\') AS tags_csv, COALESCE(is_archived, 0) AS is_archived, created_at, updated_at FROM notes WHERE id = :id LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
    return is_array($row) ? $row : null;
}

function human_notes_execute_or_throw(SQLite3 $db, $stmt, $message)
{
    if (!$stmt) {
        throw new RuntimeException($message . ': ' . $db->lastErrorMsg());
    }
    $result = $stmt->execute();
    if ($result === false) {
        throw new RuntimeException($message . ': ' . $db->lastErrorMsg());
    }
    return $result;
}

function human_notes_search_notes(SQLite3 $db, $query, $typeFilter, $limit)
{
    $limit = max(1, min(250, (int)$limit));
    $sql = 'SELECT id, topic, notes_type, note, COALESCE(tags_csv, \'\') AS tags_csv, COALESCE(is_archived, 0) AS is_archived, created_at, updated_at
            FROM notes
            WHERE (parent_id IS NULL OR parent_id = 0)';
    if ($typeFilter !== '' && $typeFilter !== 'all') {
        $sql .= ' AND notes_type = :type_filter';
    }
    if ($query !== '') {
        $sql .= ' AND (topic LIKE :q OR note LIKE :q OR notes_type LIKE :q OR COALESCE(tags_csv, \'\') LIKE :q)';
    }
    $sql .= ' ORDER BY is_archived ASC, updated_at DESC, id DESC LIMIT :lim';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($typeFilter !== '' && $typeFilter !== 'all') {
        $stmt->bindValue(':type_filter', $typeFilter, SQLITE3_TEXT);
    }
    if ($query !== '') {
        $stmt->bindValue(':q', '%' . $query . '%', SQLITE3_TEXT);
    }
    $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $rows = [];
    while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
        $rows[] = $row;
    }
    return $rows;
}

function human_notes_summary(SQLite3 $db)
{
    $summary = [
        'total' => 0,
        'active' => 0,
        'archived' => 0,
        'passwords' => 0,
        'ai_generated' => 0,
    ];
    $row = $db->querySingle(
        'SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN COALESCE(is_archived, 0) = 0 THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN COALESCE(is_archived, 0) = 1 THEN 1 ELSE 0 END) AS archived_count,
            SUM(CASE WHEN notes_type = \'passwords\' THEN 1 ELSE 0 END) AS password_count,
            SUM(CASE WHEN notes_type = \'ai_generated\' THEN 1 ELSE 0 END) AS ai_generated_count
         FROM notes',
        true
    );
    if (is_array($row)) {
        $summary['total'] = (int)($row['total_count'] ?? 0);
        $summary['active'] = (int)($row['active_count'] ?? 0);
        $summary['archived'] = (int)($row['archived_count'] ?? 0);
        $summary['passwords'] = (int)($row['password_count'] ?? 0);
        $summary['ai_generated'] = (int)($row['ai_generated_count'] ?? 0);
    }
    return $summary;
}

function human_notes_excerpt($note, $isPassword)
{
    if ($isPassword) {
        return '[Sensitive note preview hidden]';
    }
    $text = trim(preg_replace('/\s+/', ' ', (string)$note));
    if (strlen($text) > 220) {
        $text = substr($text, 0, 220) . '...';
    }
    return $text;
}

function human_notes_is_private_host($host)
{
    $host = strtolower(trim((string)$host));
    if ($host === '' || $host === 'localhost') {
        return true;
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        if (strpos($host, '10.') === 0 || strpos($host, '127.') === 0 || strpos($host, '192.168.') === 0) {
            return true;
        }
        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host)) {
            return true;
        }
        return false;
    }
    if ($host === '::1') {
        return true;
    }
    return (substr($host, -6) === '.local');
}

function human_notes_ai_capability()
{
    $settings = function_exists('ai_settings_get') ? ai_settings_get() : [];
    $provider = strtolower((string)($settings['provider'] ?? ''));
    $baseUrl = trim((string)($settings['base_url'] ?? ''));
    $model = trim((string)($settings['model'] ?? ''));
    $apiKey = trim((string)($settings['api_key'] ?? ''));
    $host = '';
    if ($baseUrl !== '') {
        $parsedHost = parse_url($baseUrl, PHP_URL_HOST);
        $host = is_string($parsedHost) ? $parsedHost : '';
    }
    $safeProvider = in_array($provider, ['local', 'ollama', 'lmstudio'], true);
    $safeHost = human_notes_is_private_host($host);
    return [
        'enabled' => $safeProvider && $safeHost && $baseUrl !== '' && $model !== '',
        'provider' => $provider,
        'base_url' => $baseUrl,
        'model' => $model,
        'api_key' => $apiKey,
        'safe_host' => $safeHost,
    ];
}

function human_notes_http_post_json($url, array $payload, array $headers, $timeout)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => min(5, (int)$timeout),
        CURLOPT_TIMEOUT => (int)$timeout,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string)curl_error($ch);
    curl_close($ch);
    return [
        'code' => $code,
        'body' => is_string($body) ? $body : '',
        'err' => $err,
    ];
}

function human_notes_ai_draft($mode, $content, array $capability)
{
    $content = trim((string)$content);
    if ($content === '') {
        throw new RuntimeException('Add note content before asking AI to draft anything.');
    }
    if (!$capability['enabled']) {
        throw new RuntimeException('AI drafting is disabled because the configured model endpoint is not local-only.');
    }

    $provider = (string)$capability['provider'];
    $baseUrl = rtrim((string)$capability['base_url'], '/');
    $model = (string)$capability['model'];
    $apiKey = (string)$capability['api_key'];

    if ($mode === 'title') {
        $system = "You create short note titles for a private admin notebook. Output plain text only. Keep it under 10 words.";
        $user = "Draft a concise title for this note:\n\n" . $content;
    } else {
        $system = "You create short comma-separated tags for a private admin notebook. Output plain text only. Use 3 to 8 tags, lowercase, no explanations.";
        $user = "Draft tags for this note:\n\n" . $content;
    }

    if ($provider === 'ollama') {
        $url = preg_match('~/v1$~', $baseUrl) ? substr($baseUrl, 0, -3) . '/api/chat' : $baseUrl . '/api/chat';
        $payload = [
            'model' => $model,
            'stream' => false,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'options' => ['temperature' => 0.2],
        ];
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $res = human_notes_http_post_json($url, $payload, $headers, 45);
        if ($res['err'] !== '' || $res['code'] >= 400 || $res['code'] === 0) {
            throw new RuntimeException('Local AI request failed: HTTP ' . $res['code'] . ' ' . $res['err']);
        }
        $json = json_decode($res['body'], true);
        $text = '';
        if (is_array($json)) {
            $text = (string)($json['message']['content'] ?? '');
        }
    } else {
        $url = $baseUrl . '/chat/completions';
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => 0.2,
            'max_tokens' => $mode === 'title' ? 40 : 80,
        ];
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        $res = human_notes_http_post_json($url, $payload, $headers, 45);
        if ($res['err'] !== '' || $res['code'] >= 400 || $res['code'] === 0) {
            throw new RuntimeException('Local AI request failed: HTTP ' . $res['code'] . ' ' . $res['err']);
        }
        $json = json_decode($res['body'], true);
        $text = '';
        if (is_array($json) && isset($json['choices'][0]['message']['content'])) {
            $text = (string)$json['choices'][0]['message']['content'];
        }
    }

    $text = trim(str_replace(["\r"], '', (string)$text));
    if ($mode === 'title') {
        $text = preg_replace('/\s+/', ' ', $text);
        return trim(substr((string)$text, 0, 120));
    }
    $text = str_replace("\n", ', ', $text);
    return human_notes_normalize_tags($text);
}

$db = human_notes_open_db();
$errors = [];
$success = [];
$form = human_notes_default_form();
$query = trim((string)($_GET['q'] ?? ''));
$typeFilter = trim((string)($_GET['type'] ?? 'all'));
if ($typeFilter === '') {
    $typeFilter = 'all';
}
$editId = (int)($_GET['edit_id'] ?? 0);
$aiCapability = human_notes_ai_capability();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $query = trim((string)($_POST['q'] ?? $query));
    $typeFilter = trim((string)($_POST['type'] ?? $typeFilter));
    $action = trim((string)($_POST['action'] ?? 'save'));
    $token = (string)($_POST['csrf_token'] ?? '');
    $form = [
        'id' => (int)($_POST['note_id'] ?? 0),
        'topic' => trim((string)($_POST['topic'] ?? '')),
        'tags_csv' => human_notes_normalize_tags($_POST['tags_csv'] ?? ''),
        'notes_type' => trim((string)($_POST['notes_type'] ?? 'human')),
        'note' => (string)($_POST['note'] ?? ''),
    ];

    if (!human_notes_csrf_ok($token)) {
        $errors[] = 'Invalid CSRF token.';
    } elseif (!array_key_exists($form['notes_type'], human_notes_types())) {
        $errors[] = 'Invalid note type.';
    } else {
        try {
            if ($action === 'new') {
                $form = human_notes_default_form();
                $success[] = 'Started a fresh note.';
            } elseif ($action === 'save') {
                if (trim($form['note']) === '') {
                    throw new RuntimeException('Note content is required.');
                }
                if ($form['id'] > 0) {
                    $stmt = $db->prepare('UPDATE notes SET topic = :topic, tags_csv = :tags_csv, notes_type = :notes_type, note = :note, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stmt->bindValue(':id', $form['id'], SQLITE3_INTEGER);
                    $stmt->bindValue(':topic', $form['topic'], SQLITE3_TEXT);
                    $stmt->bindValue(':tags_csv', $form['tags_csv'], SQLITE3_TEXT);
                    $stmt->bindValue(':notes_type', $form['notes_type'], SQLITE3_TEXT);
                    $stmt->bindValue(':note', $form['note'], SQLITE3_TEXT);
                    human_notes_execute_or_throw($db, $stmt, 'Failed to update note');
                    $success[] = 'Note updated.';
                } else {
                    $stmt = $db->prepare('INSERT INTO notes (notes_type, topic, note, parent_id, tags_csv, created_at, updated_at) VALUES (:notes_type, :topic, :note, NULL, :tags_csv, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
                    $stmt->bindValue(':notes_type', $form['notes_type'], SQLITE3_TEXT);
                    $stmt->bindValue(':topic', $form['topic'], SQLITE3_TEXT);
                    $stmt->bindValue(':note', $form['note'], SQLITE3_TEXT);
                    $stmt->bindValue(':tags_csv', $form['tags_csv'], SQLITE3_TEXT);
                    human_notes_execute_or_throw($db, $stmt, 'Failed to save note');
                    $form['id'] = (int)$db->lastInsertRowID();
                    if ($form['id'] <= 0) {
                        $fallbackId = (int)$db->querySingle('SELECT last_insert_rowid()');
                        if ($fallbackId > 0) {
                            $form['id'] = $fallbackId;
                        }
                    }
                    if ($form['id'] <= 0) {
                        throw new RuntimeException('Note insert did not return a row id.');
                    }
                    $success[] = 'Note saved.';
                }
                $editId = (int)$form['id'];
            } elseif ($action === 'delete') {
                if ($form['id'] <= 0) {
                    throw new RuntimeException('Choose a note before deleting.');
                }
                $stmt = $db->prepare('DELETE FROM notes WHERE id = :id');
                $stmt->bindValue(':id', $form['id'], SQLITE3_INTEGER);
                $stmt->execute();
                $success[] = 'Note deleted.';
                $form = human_notes_default_form();
                $editId = 0;
            } elseif ($action === 'delete_ai_generated') {
                $db->exec("DELETE FROM notes WHERE notes_type = 'ai_generated'");
                $deleted = (int)$db->changes();
                $success[] = 'Deleted ' . number_format($deleted) . ' ai_generated notes.';
                if ($typeFilter === 'ai_generated') {
                    $typeFilter = 'all';
                }
            } elseif ($action === 'archive') {
                if ($form['id'] <= 0) {
                    throw new RuntimeException('Choose a note before archiving.');
                }
                $stmt = $db->prepare('UPDATE notes SET is_archived = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stmt->bindValue(':id', $form['id'], SQLITE3_INTEGER);
                $stmt->execute();
                $success[] = 'Note archived.';
            } elseif ($action === 'restore') {
                if ($form['id'] <= 0) {
                    throw new RuntimeException('Choose a note before restoring.');
                }
                $stmt = $db->prepare('UPDATE notes SET is_archived = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stmt->bindValue(':id', $form['id'], SQLITE3_INTEGER);
                $stmt->execute();
                $success[] = 'Note restored.';
            } elseif ($action === 'ai_title') {
                $form['topic'] = human_notes_ai_draft('title', $form['note'], $aiCapability);
                $success[] = 'Drafted a title from current content using local AI.';
            } elseif ($action === 'ai_tags') {
                $form['tags_csv'] = human_notes_ai_draft('tags', $form['note'], $aiCapability);
                $success[] = 'Drafted tags from current content using local AI.';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

if ($editId > 0 && (int)$form['id'] === 0) {
    $loaded = human_notes_fetch_note_by_id($db, $editId);
    if (is_array($loaded)) {
        $form = [
            'id' => (int)$loaded['id'],
            'topic' => (string)($loaded['topic'] ?? ''),
            'tags_csv' => (string)($loaded['tags_csv'] ?? ''),
            'notes_type' => (string)($loaded['notes_type'] ?? 'human'),
            'note' => (string)($loaded['note'] ?? ''),
        ];
    }
}

$rows = human_notes_search_notes($db, $query, $typeFilter, 200);
$summary = human_notes_summary($db);
$types = human_notes_types();
$aiStatusText = $aiCapability['enabled']
    ? 'AI drafting is local-only via ' . $aiCapability['provider'] . ' at ' . $aiCapability['base_url']
    : 'AI drafting is disabled unless the configured model endpoint is local-only. This page never calls SearXNG.';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Human Notes</title>
    <style>
        :root {
            --bg: #0b1220;
            --panel: #111827;
            --panel-2: #162033;
            --line: #30415f;
            --text: #ecf2ff;
            --muted: #aab9d6;
            --accent: #7dd3fc;
            --accent-2: #34d399;
            --warn: #f59e0b;
            --danger: #f87171;
            --radius: 12px;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: linear-gradient(180deg, #08101c 0%, #0f172a 100%); color: var(--text); font-family: Georgia, "Times New Roman", serif; }
        a { color: var(--accent); }
        .wrap { max-width: 1500px; margin: 0 auto; padding: 18px; }
        .topbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .nav { display: flex; gap: 8px; flex-wrap: wrap; }
        .nav a { text-decoration: none; color: var(--text); background: rgba(17,24,39,0.9); border: 1px solid var(--line); border-radius: 999px; padding: 8px 12px; font-size: 14px; }
        .hero { background: linear-gradient(135deg, rgba(125,211,252,0.12), rgba(52,211,153,0.09)), var(--panel); border: 1px solid var(--line); border-radius: 18px; padding: 18px; margin-bottom: 14px; }
        .hero h1 { margin: 0 0 8px; font-size: 30px; }
        .hero p { margin: 0; color: var(--muted); max-width: 980px; }
        .stats { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .stat { background: rgba(8,16,28,0.7); border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; min-width: 130px; }
        .stat strong { display: block; font-size: 20px; }
        .grid { display: grid; grid-template-columns: minmax(540px, 1.25fr) minmax(380px, 0.95fr); gap: 14px; align-items: start; }
        .card { background: rgba(17,24,39,0.95); border: 1px solid var(--line); border-radius: 16px; padding: 16px; }
        .card h2 { margin: 0 0 10px; font-size: 20px; }
        .muted { color: var(--muted); }
        .notice { margin-top: 10px; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--line); background: rgba(8,16,28,0.7); color: var(--muted); }
        .error { border-color: rgba(248,113,113,0.5); color: #fecaca; background: rgba(127,29,29,0.25); }
        .success { border-color: rgba(52,211,153,0.5); color: #bbf7d0; background: rgba(6,78,59,0.3); }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        label { display: block; margin-bottom: 6px; color: var(--muted); font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; }
        input[type="text"], select, textarea { width: 100%; border-radius: 12px; border: 1px solid var(--line); background: #0a1220; color: var(--text); padding: 12px; font: inherit; }
        textarea { min-height: 440px; resize: vertical; font-family: "SFMono-Regular", Consolas, "Liberation Mono", monospace; line-height: 1.5; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
        button { border: 1px solid var(--line); border-radius: 999px; background: #152238; color: var(--text); padding: 10px 14px; cursor: pointer; font: inherit; }
        button.primary { background: linear-gradient(135deg, #0369a1, #0f766e); border-color: transparent; }
        button.secondary { background: #18263d; }
        button.warn { background: #4a3412; }
        button.danger { background: #3f1d20; }
        button:disabled { opacity: 0.45; cursor: not-allowed; }
        .searchbar { display: grid; grid-template-columns: 1fr 180px auto; gap: 10px; margin-bottom: 12px; }
        .note-list { display: grid; gap: 10px; }
        .note-item { border: 1px solid var(--line); border-radius: 14px; padding: 12px; background: rgba(8,16,28,0.65); }
        .note-item.archived { opacity: 0.7; }
        .note-head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; align-items: start; }
        .note-title { font-size: 18px; margin: 0; }
        .pill { display: inline-block; border: 1px solid var(--line); border-radius: 999px; padding: 3px 8px; margin-right: 6px; margin-bottom: 6px; color: var(--muted); font-size: 12px; }
        .pill.sensitive { border-color: rgba(245,158,11,0.5); color: #fde68a; }
        .excerpt { color: #d8e3fb; white-space: pre-wrap; word-break: break-word; font-size: 14px; line-height: 1.5; }
        .mini-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
        .mini-actions a, .mini-actions button { font-size: 13px; padding: 7px 10px; text-decoration: none; }
        .hidden-copy { position: absolute; left: -9999px; top: -9999px; }
        @media (max-width: 1100px) {
            .grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 760px) {
            .row, .searchbar { grid-template-columns: 1fr; }
            textarea { min-height: 340px; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div class="nav">
            <a href="/admin/admin_notes.php">Human Notes</a>
            <a href="/admin/admin_Bash_History.php">Bash History</a>
            <a href="/admin/admin_API_Search.php">Search Cache / API Search</a>
        </div>
    </div>

    <div class="hero">
        <h1>Human Notes</h1>
        <p>This page is for your own notes first: paste raw text, markdown, passwords, rsync commands, scratch drafts, and AI output you want to keep locally in <code><?php echo human_notes_h(human_notes_db_path()); ?></code>. It does not call SearXNG. The AI draft buttons are local-only and stay disabled unless the configured model endpoint is private.</p>
            <div class="stats">
                <div class="stat"><span class="muted">Total Notes</span><strong><?php echo human_notes_h(number_format($summary['total'])); ?></strong></div>
                <div class="stat"><span class="muted">Active</span><strong><?php echo human_notes_h(number_format($summary['active'])); ?></strong></div>
                <div class="stat"><span class="muted">Archived</span><strong><?php echo human_notes_h(number_format($summary['archived'])); ?></strong></div>
                <div class="stat"><span class="muted">Passwords</span><strong><?php echo human_notes_h(number_format($summary['passwords'])); ?></strong></div>
                <div class="stat"><span class="muted">AI Generated</span><strong><?php echo human_notes_h(number_format($summary['ai_generated'])); ?></strong></div>
            </div>
        </div>

    <div class="grid">
        <div class="card">
            <h2><?php echo (int)$form['id'] > 0 ? 'Edit Note #' . (int)$form['id'] : 'New Note'; ?></h2>
            <div class="notice"><?php echo human_notes_h($aiStatusText); ?></div>
            <?php foreach ($errors as $msg): ?>
                <div class="notice error"><?php echo human_notes_h($msg); ?></div>
            <?php endforeach; ?>
            <?php foreach ($success as $msg): ?>
                <div class="notice success"><?php echo human_notes_h($msg); ?></div>
            <?php endforeach; ?>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo human_notes_h(human_notes_csrf_token()); ?>">
                <input type="hidden" name="note_id" value="<?php echo (int)$form['id']; ?>">
                <input type="hidden" name="q" value="<?php echo human_notes_h($query); ?>">
                <input type="hidden" name="type" value="<?php echo human_notes_h($typeFilter); ?>">

                <div class="row">
                    <div>
                        <label for="topic">Title</label>
                        <input id="topic" type="text" name="topic" value="<?php echo human_notes_h($form['topic']); ?>" placeholder="Optional title">
                    </div>
                    <div>
                        <label for="notes_type">Type</label>
                        <select id="notes_type" name="notes_type">
                            <?php foreach ($types as $value => $label): ?>
                                <option value="<?php echo human_notes_h($value); ?>"<?php echo $form['notes_type'] === $value ? ' selected' : ''; ?>><?php echo human_notes_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div style="grid-column: 1 / -1;">
                        <label for="tags_csv">Tags</label>
                        <input id="tags_csv" type="text" name="tags_csv" value="<?php echo human_notes_h($form['tags_csv']); ?>" placeholder="comma, separated, tags">
                    </div>
                </div>

                <div>
                    <label for="note">Content</label>
                    <textarea id="note" name="note" placeholder="Paste anything here. Markdown stays as-is."><?php echo human_notes_h($form['note']); ?></textarea>
                </div>

                <div class="actions">
                    <button class="primary" type="submit" name="action" value="save">Save</button>
                    <button class="secondary" type="submit" name="action" value="new">New</button>
                    <button class="secondary" type="submit" name="action" value="ai_title"<?php echo $aiCapability['enabled'] ? '' : ' disabled'; ?>>AI Create Title From Current Content</button>
                    <button class="secondary" type="submit" name="action" value="ai_tags"<?php echo $aiCapability['enabled'] ? '' : ' disabled'; ?>>AI Create Tags From Current Content</button>
                    <button class="warn" type="submit" name="action" value="delete_ai_generated" onclick="return confirm('Delete all ai_generated notes? This only removes generated notes, not your manual notes.');">Delete All AI Generated</button>
                    <?php if ((int)$form['id'] > 0): ?>
                        <button class="warn" type="submit" name="action" value="archive">Archive</button>
                        <button class="secondary" type="submit" name="action" value="restore">Restore</button>
                        <button class="danger" type="submit" name="action" value="delete" onclick="return confirm('Delete this note?');">Delete</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Search And Browse</h2>
            <form method="get" class="searchbar">
                <input type="text" name="q" value="<?php echo human_notes_h($query); ?>" placeholder="Search note body, title, tags, or type">
                <select name="type">
                    <option value="all">All Types</option>
                    <?php foreach ($types as $value => $label): ?>
                        <option value="<?php echo human_notes_h($value); ?>"<?php echo $typeFilter === $value ? ' selected' : ''; ?>><?php echo human_notes_h($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="secondary" type="submit">Search</button>
            </form>
            <div class="notice">Use this for manual notes only. Bash history lives on <a href="/admin/admin_Bash_History.php">its own page</a>. Search cache stays on <a href="/admin/admin_API_Search.php">the search admin page</a>.</div>
            <div class="note-list">
                <?php if (empty($rows)): ?>
                    <div class="note-item"><div class="excerpt muted">No notes matched your current search.</div></div>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $id = (int)$row['id'];
                    $isPassword = ((string)$row['notes_type'] === 'passwords');
                    $isArchived = (int)($row['is_archived'] ?? 0) === 1;
                    $title = trim((string)($row['topic'] ?? ''));
                    if ($title === '') {
                        $title = 'Untitled note #' . $id;
                    }
                    $tagsCsv = trim((string)($row['tags_csv'] ?? ''));
                    $excerpt = human_notes_excerpt((string)($row['note'] ?? ''), $isPassword);
                    ?>
                    <div class="note-item<?php echo $isArchived ? ' archived' : ''; ?>">
                        <div class="note-head">
                            <div>
                                <h3 class="note-title"><?php echo human_notes_h($title); ?></h3>
                                <div>
                                    <span class="pill<?php echo $isPassword ? ' sensitive' : ''; ?>"><?php echo human_notes_h((string)$row['notes_type']); ?></span>
                                    <?php if ($isArchived): ?><span class="pill">archived</span><?php endif; ?>
                                    <?php if ($tagsCsv !== ''): ?>
                                        <?php foreach (explode(',', $tagsCsv) as $tag): ?>
                                            <?php $tag = trim((string)$tag); if ($tag === '') continue; ?>
                                            <span class="pill">#<?php echo human_notes_h($tag); ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="muted"><?php echo human_notes_h((string)($row['updated_at'] ?? '')); ?></div>
                        </div>
                        <div class="excerpt"><?php echo human_notes_h($excerpt); ?></div>
                        <textarea class="hidden-copy" id="copy-note-<?php echo $id; ?>"><?php echo human_notes_h((string)($row['note'] ?? '')); ?></textarea>
                        <div class="mini-actions">
                            <a href="/admin/admin_notes.php?edit_id=<?php echo $id; ?>&q=<?php echo urlencode($query); ?>&type=<?php echo urlencode($typeFilter); ?>">Edit</a>
                            <button type="button" class="js-copy-note" data-target="copy-note-<?php echo $id; ?>">Copy Content</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    function copyText(text) {
        if (!text) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
            return;
        }
        var temp = document.createElement('textarea');
        temp.value = text;
        document.body.appendChild(temp);
        temp.select();
        document.execCommand('copy');
        document.body.removeChild(temp);
    }
    document.querySelectorAll('.js-copy-note').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-target') || '';
            var el = document.getElementById(id);
            if (!el) return;
            copyText(el.value || '');
        });
    });
})();
</script>
</body>
</html>
