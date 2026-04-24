<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

header('Content-Type: text/html; charset=utf-8');

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function mask_key($key) {
    $key = (string)$key;
    $len = strlen($key);
    if ($len <= 10) return str_repeat('*', $len);
    return substr($key, 0, 6) . str_repeat('*', max($len - 10, 4)) . substr($key, -4);
}

function auth_csrf_token() {
    if (function_exists('auth_session_start')) {
        auth_session_start();
    } elseif (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_api_search'])) {
        $_SESSION['csrf_api_search'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf_api_search'];
}

function auth_csrf_ok($token) {
    if (function_exists('auth_session_start')) {
        auth_session_start();
    } elseif (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return isset($_SESSION['csrf_api_search']) && is_string($token) && hash_equals((string)$_SESSION['csrf_api_search'], $token);
}

function infer_base_url() {
    $https = isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'localhost';
    return $scheme . '://' . $host;
}

function notes_db_path() {
    return rtrim((string)PRIVATE_ROOT, '/\\') . '/db/memory/human_notes.db';
}

function search_cache_db_path() {
    return rtrim((string)PRIVATE_ROOT, '/\\') . '/db/memory/search_cache.db';
}

function format_bytes($bytes) {
    $bytes = (float)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = (int)floor(log($bytes, 1024));
    if ($pow < 0) $pow = 0;
    if ($pow > count($units) - 1) $pow = count($units) - 1;
    $value = $bytes / pow(1024, $pow);
    return number_format($value, $pow === 0 ? 0 : 2) . ' ' . $units[$pow];
}

function search_cache_db_summary() {
    $path = search_cache_db_path();
    $exists = is_file($path);
    $sizeBytes = ($exists && is_readable($path)) ? (int)@filesize($path) : 0;
    $rowCount = null;
    if ($exists && is_readable($path)) {
        try {
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $value = $pdo->query('SELECT COUNT(*) FROM search_cache_history')->fetchColumn();
            $rowCount = $value === false ? null : (int)$value;
        } catch (Throwable $e) {
            $rowCount = null;
        }
    }
    return [
        'path' => $path,
        'exists' => $exists,
        'size_bytes' => $sizeBytes,
        'size_human' => format_bytes($sizeBytes),
        'row_count' => $rowCount,
    ];
}

function codewalker_settings_db_path() {
    return rtrim((string)PRIVATE_ROOT, '/\\') . '/db/codewalker_settings.db';
}

function api_keys_file_path() {
    return defined('API_KEYS_FILE') ? (string)API_KEYS_FILE : rtrim((string)PRIVATE_ROOT, '/\\') . '/api_keys.json';
}

function load_api_keys_for_search() {
    $path = api_keys_file_path();
    if (!is_file($path) || !is_readable($path)) return [];

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return [];

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];

    $rows = [];
    foreach ($decoded as $key => $entry) {
        $name = '';
        $active = true;
        $scopes = [];
        $createdAt = '';

        if (is_array($entry) && array_key_exists('scopes', $entry)) {
            $name = isset($entry['name']) ? trim((string)$entry['name']) : '';
            $active = !isset($entry['active']) || (bool)$entry['active'];
            $scopes = isset($entry['scopes']) && is_array($entry['scopes']) ? array_values($entry['scopes']) : [];
            $createdAt = isset($entry['created_at']) ? (string)$entry['created_at'] : '';
        } elseif (is_array($entry)) {
            $scopes = array_values($entry);
        }

        $hasSearchAccess = in_array('search', $scopes, true) || in_array('tools', $scopes, true);
        $rows[] = [
            'key' => (string)$key,
            'masked_key' => mask_key((string)$key),
            'name' => $name,
            'active' => $active,
            'scopes' => $scopes,
            'created_at' => $createdAt,
            'has_search_access' => $hasSearchAccess,
        ];
    }

    usort($rows, function ($a, $b) {
        if ($a['has_search_access'] !== $b['has_search_access']) {
            return $a['has_search_access'] ? -1 : 1;
        }
        if ($a['active'] !== $b['active']) {
            return $a['active'] ? -1 : 1;
        }
        return strcmp((string)$a['name'], (string)$b['name']);
    });

    return $rows;
}

function load_search_api_base_from_codewalker_db() {
    $path = codewalker_settings_db_path();
    if (!is_file($path) || !is_readable($path)) return '';
    try {
        $db = new SQLite3($path);
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :k LIMIT 1');
        $stmt->bindValue(':k', 'search_api_base', SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
        $db->close();
        if (!is_array($row) || !isset($row['value'])) return '';

        $decoded = json_decode((string)$row['value'], true);
        if (is_string($decoded)) return trim($decoded);
        if (is_string($row['value'])) return trim((string)$row['value']);
    } catch (Throwable $e) {
        return '';
    }
    return '';
}

function save_search_api_base_to_codewalker_db($value) {
    $path = codewalker_settings_db_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $v = trim((string)$value);
    if ($v === '') return false;
    try {
        $db = new SQLite3($path);
        $db->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)');
        $stmt = $db->prepare('INSERT INTO settings(key, value) VALUES(:k, :v) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $stmt->bindValue(':k', 'search_api_base', SQLITE3_TEXT);
        $stmt->bindValue(':v', json_encode($v), SQLITE3_TEXT);
        $ok = $stmt->execute() !== false;
        $db->close();
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function search_provider_defaults() {
    return [
        'default_provider' => 'primary',
        'primary' => [
            'type' => 'searxng',
            'url' => trim((string)(getenv('SEARX_URL') ?: '')),
            'api_key' => trim((string)(getenv('IERNC_SEARCH_APIKEY') ?: '')),
        ],
        'secondary' => [
            'type' => 'searchapi_google',
            'url' => 'https://www.searchapi.io/api/v1/search',
            'api_key' => '',
        ],
    ];
}

function load_external_search_provider_settings() {
    $settings = search_provider_defaults();
    $path = codewalker_settings_db_path();
    if (!is_file($path) || !is_readable($path)) return $settings;
    try {
        $db = new SQLite3($path);
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :k LIMIT 1');
        $stmt->bindValue(':k', 'search.external.providers', SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
        $db->close();
        if (!is_array($row) || !isset($row['value'])) return $settings;
        $decoded = json_decode((string)$row['value'], true);
        if (!is_array($decoded)) return $settings;
        if (isset($decoded['default_provider']) && ($decoded['default_provider'] === 'primary' || $decoded['default_provider'] === 'secondary')) {
            $settings['default_provider'] = $decoded['default_provider'];
        }
        foreach (['primary', 'secondary'] as $slot) {
            if (!isset($decoded[$slot]) || !is_array($decoded[$slot])) continue;
            if (isset($decoded[$slot]['type'])) $settings[$slot]['type'] = trim((string)$decoded[$slot]['type']);
            if (isset($decoded[$slot]['url'])) $settings[$slot]['url'] = trim((string)$decoded[$slot]['url']);
            if (isset($decoded[$slot]['api_key'])) $settings[$slot]['api_key'] = trim((string)$decoded[$slot]['api_key']);
        }
    } catch (Throwable $e) {
        return $settings;
    }
    return $settings;
}

function save_external_search_provider_settings($settings) {
    $path = codewalker_settings_db_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    try {
        $db = new SQLite3($path);
        $db->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)');
        $stmt = $db->prepare('INSERT INTO settings(key, value) VALUES(:k, :v) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $stmt->bindValue(':k', 'search.external.providers', SQLITE3_TEXT);
        $stmt->bindValue(':v', json_encode($settings, JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
        $ok = $stmt->execute() !== false;
        $db->close();
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function load_search_api_base_from_notes_db() {
    $path = notes_db_path();
    if (!is_file($path) || !is_readable($path)) return '';
    try {
        $db = new SQLite3($path);
        $stmt = $db->prepare('SELECT v FROM app_settings WHERE k = :k LIMIT 1');
        $stmt->bindValue(':k', 'search.api.base', SQLITE3_TEXT);
        $res = $stmt->execute();
        $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
        $db->close();
        if (is_array($row) && isset($row['v'])) return trim((string)$row['v']);
    } catch (Throwable $e) {
        return '';
    }
    return '';
}

function normalize_v1_search_url($raw, $fallbackBase) {
    $raw = trim((string)$raw);
    if ($raw === '') return rtrim((string)$fallbackBase, '/') . '/v1/search/';
    if (strpos($raw, '/v1/search') !== false) {
        $parts = explode('?', $raw, 2);
        return rtrim((string)$parts[0], '/') . '/';
    }
    return rtrim($raw, '/') . '/';
}

function http_get_json($url, array $headers, $timeoutSec) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => (int)$timeoutSec,
        CURLOPT_CONNECTTIMEOUT => min(5, (int)$timeoutSec),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string)curl_error($ch);
    curl_close($ch);

    $json = null;
    if (is_string($body) && $body !== '') {
        $tmp = json_decode($body, true);
        if (is_array($tmp)) $json = $tmp;
    }

    return [
        'url' => $url,
        'http_code' => $code,
        'curl_error' => $err,
        'raw' => is_string($body) ? $body : '',
        'json' => $json,
    ];
}

function ai_overview_plain_text($payload) {
    if (!is_array($payload) || !isset($payload['ai_overview']) || !is_array($payload['ai_overview'])) return '';
    $blocks = isset($payload['ai_overview']['text_blocks']) && is_array($payload['ai_overview']['text_blocks']) ? $payload['ai_overview']['text_blocks'] : [];
    $parts = [];
    foreach ($blocks as $block) {
        if (!is_array($block)) continue;
        $answer = isset($block['answer']) ? trim((string)$block['answer']) : '';
        if ($answer !== '') $parts[] = $answer;
    }
    return trim(implode("\n\n", $parts));
}

function related_search_labels($payload) {
    if (!is_array($payload) || !isset($payload['related_searches']) || !is_array($payload['related_searches'])) return [];
    $labels = [];
    foreach ($payload['related_searches'] as $item) {
        if (is_array($item) && isset($item['query'])) {
            $q = trim((string)$item['query']);
            if ($q !== '') $labels[] = $q;
        } elseif (is_string($item) && trim($item) !== '') {
            $labels[] = trim($item);
        }
    }
    return $labels;
}

function pretty_json_text($raw) {
    $raw = (string)$raw;
    if ($raw === '') return '';
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return $raw;
    $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return is_string($pretty) ? $pretty : $raw;
}

function fetch_search_cache_row_by_id($id) {
    $path = search_cache_db_path();
    $id = (int)$id;
    if ($id < 1 || !is_file($path) || !is_readable($path)) return null;
    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare('SELECT id, q, body, top_urls, ai_notes, cached_at FROM search_cache_history WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $row['body_json'] = json_decode((string)($row['body'] ?? ''), true);
        $row['top_urls_json'] = json_decode((string)($row['top_urls'] ?? '[]'), true);
        if (!is_array($row['top_urls_json'])) $row['top_urls_json'] = [];
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function fetch_recent_search_cache_rows($limit) {
    $path = search_cache_db_path();
    if (!is_file($path) || !is_readable($path)) return [];
    $limit = (int)$limit;
    if ($limit < 1) $limit = 10;
    if ($limit > 100) $limit = 100;
    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $rows = $pdo->query('SELECT id, q, top_urls, cached_at FROM search_cache_history ORDER BY id DESC LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) return [];
        foreach ($rows as &$r) {
            $arr = json_decode((string)($r['top_urls'] ?? '[]'), true);
            $r['top_count'] = is_array($arr) ? count($arr) : 0;
        }
        unset($r);
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

$notesSearchApiBase = load_search_api_base_from_notes_db();
$cwSearchApiBase = load_search_api_base_from_codewalker_db();
$baseUrl = infer_base_url();
$defaultSearxUrl = trim((string)(getenv('SEARX_URL') ?: ''));
$defaultConfiguredSearch = $cwSearchApiBase !== '' ? $cwSearchApiBase : $notesSearchApiBase;
$defaultV1SearchUrl = normalize_v1_search_url($defaultConfiguredSearch, $baseUrl);

$query = trim((string)($_POST['q'] ?? ($_GET['q'] ?? 'teijin seiki ar-60')));
$searxUrl = trim((string)($_POST['searx_url'] ?? ($_GET['searx_url'] ?? $defaultSearxUrl)));
$v1SearchUrl = normalize_v1_search_url((string)($_POST['v1_search_url'] ?? ($_GET['v1_search_url'] ?? $defaultV1SearchUrl)), $baseUrl);
$apiKey = trim((string)($_POST['api_key'] ?? ($_GET['api_key'] ?? '')));
$testTarget = trim((string)($_POST['test_target'] ?? ''));

$searxResult = null;
$v1Result = null;
$error = '';
$flash = '';
$searchApiKeys = load_api_keys_for_search();
$searchCacheSummary = search_cache_db_summary();
$providerSettings = load_external_search_provider_settings();
$primaryProviderType = trim((string)($_POST['primary_provider_type'] ?? ($_GET['primary_provider_type'] ?? $providerSettings['primary']['type'])));
$primaryProviderUrl = trim((string)($_POST['primary_provider_url'] ?? ($_GET['primary_provider_url'] ?? $providerSettings['primary']['url'])));
$primaryProviderApiKey = trim((string)($_POST['primary_provider_api_key'] ?? ($_GET['primary_provider_api_key'] ?? $providerSettings['primary']['api_key'])));
$secondaryProviderType = trim((string)($_POST['secondary_provider_type'] ?? ($_GET['secondary_provider_type'] ?? $providerSettings['secondary']['type'])));
$secondaryProviderUrl = trim((string)($_POST['secondary_provider_url'] ?? ($_GET['secondary_provider_url'] ?? $providerSettings['secondary']['url'])));
$secondaryProviderApiKey = trim((string)($_POST['secondary_provider_api_key'] ?? ($_GET['secondary_provider_api_key'] ?? $providerSettings['secondary']['api_key'])));
$defaultProviderSlot = trim((string)($_POST['default_provider_slot'] ?? ($_GET['default_provider_slot'] ?? $providerSettings['default_provider'])));
if ($defaultProviderSlot !== 'secondary') $defaultProviderSlot = 'primary';
$selectedCacheId = (int)($_GET['cache_id'] ?? 0);
$selectedCacheRow = $selectedCacheId > 0 ? fetch_search_cache_row_by_id($selectedCacheId) : null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : 'run_test';
    $csrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if (!auth_csrf_ok($csrf)) {
        $error = 'Invalid CSRF token.';
    } elseif ($action === 'save_external_provider_settings') {
        $settingsToSave = [
            'default_provider' => $defaultProviderSlot,
            'primary' => [
                'type' => $primaryProviderType,
                'url' => $primaryProviderUrl,
                'api_key' => $primaryProviderApiKey,
            ],
            'secondary' => [
                'type' => $secondaryProviderType,
                'url' => $secondaryProviderUrl,
                'api_key' => $secondaryProviderApiKey,
            ],
        ];
        if (save_external_search_provider_settings($settingsToSave)) {
            $flash = 'Saved external search provider settings.';
            $providerSettings = $settingsToSave;
        } else {
            $error = 'Failed to save external search provider settings.';
        }
    } elseif ($action === 'save_search_base') {
        $normalized = normalize_v1_search_url($v1SearchUrl, $baseUrl);
        if (save_search_api_base_to_codewalker_db($normalized)) {
            $flash = 'Saved search_api_base to CodeWalker settings.';
            $cwSearchApiBase = $normalized;
            $v1SearchUrl = $normalized;
        } else {
            $error = 'Failed to save search_api_base to CodeWalker settings DB.';
        }
    } elseif ($query === '') {
        $error = 'Search query is required.';
    } else {
        if ($testTarget === 'searx' || $testTarget === 'both') {
            if ($searxUrl === '') {
                $error = 'SearXNG URL is required for SearX test.';
            } else {
                $url = rtrim($searxUrl, '/') . '/search?' . http_build_query([
                    'q' => $query,
                    'format' => 'json',
                    'language' => 'en-US',
                    'safesearch' => 0,
                ]);
                $searxResult = http_get_json($url, ['Accept: application/json'], 20);
            }
        }

        if ($error === '' && ($testTarget === 'v1' || $testTarget === 'both')) {
            $headers = ['Accept: application/json'];
            if ($apiKey !== '') $headers[] = 'X-API-Key: ' . $apiKey;
            $url = $v1SearchUrl . '?' . http_build_query(['q' => $query]);
            $v1Result = http_get_json($url, $headers, 20);
        }
    }
}

$recentCacheRows = fetch_recent_search_cache_rows(15);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API Search Tester</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 16px; background:#0f172a; color:#e2e8f0; }
        .wrap { max-width: 1200px; margin: 0 auto; }
        .card { background:#111827; border:1px solid #334155; border-radius:10px; padding:14px; margin-bottom:12px; }
        .grid { display:grid; gap:10px; grid-template-columns:repeat(2, minmax(220px, 1fr)); }
        label { display:block; font-size:12px; color:#94a3b8; margin-bottom:4px; }
        input, select, button, textarea { width:100%; border:1px solid #334155; border-radius:8px; padding:8px 10px; background:#020617; color:#e2e8f0; }
        button { background:#0ea5e9; color:#082f49; font-weight:700; cursor:pointer; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th, td { border-bottom:1px solid #334155; padding:8px; text-align:left; vertical-align:top; }
        th { color:#94a3b8; }
        .muted { color:#94a3b8; }
        .err { color:#f87171; }
        pre { margin:0; padding:10px; border:1px solid #334155; border-radius:8px; background:#020617; overflow:auto; white-space:pre-wrap; word-break:break-word; }
        code { background:#020617; border:1px solid #334155; border-radius:6px; padding:1px 4px; }
        .row { margin-top:8px; }
        a { color:#38bdf8; }
        .pill { display:inline-block; margin:2px 6px 2px 0; padding:2px 8px; border-radius:999px; border:1px solid #334155; background:#0b1220; font-size:12px; color:#cbd5e1; }
        .pill.ok { border-color:#14532d; color:#86efac; background:#052e16; }
        .pill.off { border-color:#7f1d1d; color:#fca5a5; background:#450a0a; }
        .copy-btn { width:auto; background:#1d4ed8; color:#dbeafe; font-weight:600; }
        .use-btn { width:auto; background:#0f766e; color:#ccfbf1; font-weight:600; }
        .key-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
        .helper-grid { display:grid; gap:12px; grid-template-columns:repeat(2, minmax(280px, 1fr)); }
        @media (max-width: 900px) { .grid { grid-template-columns:1fr; } }
        @media (max-width: 900px) { .helper-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>API Search Tester</h1>
        <div class="muted">Test raw SearXNG and your API endpoint <code>/v1/search</code>. Search cache UI: <a href="/admin/admin_db.php?db=memory%2Fsearch_cache.db&table=search_cache_history&page=1&per=99999">admin_db.php?db=</a></div>
        <div class="row muted">Search cache DB: <code><?php echo h((string)$searchCacheSummary['path']); ?></code> | size: <strong><?php echo h((string)$searchCacheSummary['size_human']); ?></strong> | rows: <strong><?php echo h($searchCacheSummary['row_count'] === null ? 'n/a' : number_format((int)$searchCacheSummary['row_count'])); ?></strong><?php if (!$searchCacheSummary['exists']): ?> | missing<?php endif; ?></div>
    </div>

    <div class="card">
        <h2>How This Page Works</h2>
        <div class="helper-grid">
            <div>
                <div class="row"><strong>SearXNG URL</strong> is the upstream engine this server talks to, often a local or Tailscale address.</div>
                <div class="row"><strong>/v1/search URL</strong> is the public API endpoint your agents or other servers call, for example <code>https://www.iernc.com/v1/search/</code>.</div>
                <div class="row">When public mode is enabled, <code>/v1/search</code> needs a valid API key from this server's key store.</div>
            </div>
            <div>
                <div class="row"><strong>Keys are stored at</strong> <code><?php echo h(api_keys_file_path()); ?></code>.</div>
                <div class="row">This tester does not auto-inject a key unless you paste one or use a key from the list below.</div>
                <div class="row"><a href="/admin/admin_API.php?tab=keys">Open API Key Manager</a> to create, edit, activate, or remove keys and scopes.</div>
            </div>
        </div>
    </div>

    <div class="card">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo h(auth_csrf_token()); ?>">
            <div class="grid">
                <div>
                    <label>Search Query</label>
                    <input type="text" name="q" value="<?php echo h($query); ?>" placeholder="example: teijin seiki ar-60" required>
                </div>
                <div>
                    <label>Test Target</label>
                    <select name="test_target">
                        <option value="both"<?php echo $testTarget === 'both' || $testTarget === '' ? ' selected' : ''; ?>>Both (SearX + /v1/search)</option>
                        <option value="searx"<?php echo $testTarget === 'searx' ? ' selected' : ''; ?>>SearXNG only</option>
                        <option value="v1"<?php echo $testTarget === 'v1' ? ' selected' : ''; ?>>/v1/search only</option>
                    </select>
                </div>
                <div>
                    <label>SearXNG URL</label>
                    <input type="text" name="searx_url" value="<?php echo h($searxUrl); ?>" placeholder="http://192.168.0.142:8080">
                </div>
                <div>
                    <label>/v1/search URL</label>
                    <input type="text" name="v1_search_url" value="<?php echo h($v1SearchUrl); ?>" placeholder="http://192.168.0.142/v1/search">
                </div>
                <div>
                    <label>API Key for /v1/search</label>
                    <input type="text" id="api-key-input" name="api_key" value="<?php echo h($apiKey); ?>" placeholder="key with search or tools scope">
                </div>
            </div>
            <div class="row" style="display:flex;gap:10px;flex-wrap:wrap">
                <button type="submit" name="action" value="run_test" style="width:auto">Run Test</button>
                <button type="submit" name="action" value="save_search_base" style="width:auto;background:#22c55e;color:#052e16">Save /v1/search URL To CodeWalker</button>
            </div>
        </form>
        <?php if ($flash !== ''): ?>
            <div class="row" style="color:#4ade80"><strong><?php echo h($flash); ?></strong></div>
        <?php endif; ?>
        <?php if ($cwSearchApiBase !== ''): ?>
            <div class="row muted">Configured in CodeWalker settings (<code>search_api_base</code>): <code><?php echo h($cwSearchApiBase); ?></code></div>
        <?php endif; ?>
        <?php if ($notesSearchApiBase !== ''): ?>
            <div class="row muted">Configured in Notes app_settings (<code>search.api.base</code>): <code><?php echo h($notesSearchApiBase); ?></code></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="row err"><strong>Error:</strong> <?php echo h($error); ?></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>External Search Providers</h2>
        <div class="muted">Configure the upstream engines that <code>/v1/search</code> can use. The default slot is tried first, and the other slot is used as backup if the first one fails.</div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo h(auth_csrf_token()); ?>">
            <div class="grid">
                <div>
                    <label>Default Provider Slot</label>
                    <select name="default_provider_slot">
                        <option value="primary"<?php echo $defaultProviderSlot === 'primary' ? ' selected' : ''; ?>>Primary first</option>
                        <option value="secondary"<?php echo $defaultProviderSlot === 'secondary' ? ' selected' : ''; ?>>Secondary first</option>
                    </select>
                </div>
            </div>
            <div class="helper-grid" style="margin-top:12px;">
                <div class="card" style="margin-bottom:0;">
                    <h3 style="margin-bottom:10px;">Primary Provider</h3>
                    <label>Type</label>
                    <select name="primary_provider_type">
                        <option value="searxng"<?php echo $primaryProviderType === 'searxng' ? ' selected' : ''; ?>>SearXNG</option>
                        <option value="brave"<?php echo $primaryProviderType === 'brave' ? ' selected' : ''; ?>>Brave Search API</option>
                        <option value="searchapi_google"<?php echo $primaryProviderType === 'searchapi_google' ? ' selected' : ''; ?>>SearchAPI.io (Google)</option>
                    </select>
                    <div class="row">
                        <label>Base URL</label>
                        <input type="text" name="primary_provider_url" value="<?php echo h($primaryProviderUrl); ?>" placeholder="http://tailscale-or-lan:8080 or https://www.searchapi.io/api/v1/search">
                    </div>
                    <div class="row">
                        <label>API Key / Token</label>
                        <input type="password" name="primary_provider_api_key" value="<?php echo h($primaryProviderApiKey); ?>" placeholder="optional for SearXNG, required for Brave or SearchAPI.io">
                    </div>
                </div>
                <div class="card" style="margin-bottom:0;">
                    <h3 style="margin-bottom:10px;">Secondary Provider</h3>
                    <label>Type</label>
                    <select name="secondary_provider_type">
                        <option value=""<?php echo $secondaryProviderType === '' ? ' selected' : ''; ?>>Disabled</option>
                        <option value="searxng"<?php echo $secondaryProviderType === 'searxng' ? ' selected' : ''; ?>>SearXNG</option>
                        <option value="brave"<?php echo $secondaryProviderType === 'brave' ? ' selected' : ''; ?>>Brave Search API</option>
                        <option value="searchapi_google"<?php echo $secondaryProviderType === 'searchapi_google' ? ' selected' : ''; ?>>SearchAPI.io (Google)</option>
                    </select>
                    <div class="row">
                        <label>Base URL</label>
                        <input type="text" name="secondary_provider_url" value="<?php echo h($secondaryProviderUrl); ?>" placeholder="leave blank if disabled">
                    </div>
                    <div class="row">
                        <label>API Key / Token</label>
                        <input type="password" name="secondary_provider_api_key" value="<?php echo h($secondaryProviderApiKey); ?>" placeholder="optional for SearXNG, required for Brave or SearchAPI.io">
                    </div>
                </div>
            </div>
            <div class="row" style="display:flex;gap:10px;flex-wrap:wrap">
                <button type="submit" name="action" value="save_external_provider_settings" style="width:auto;background:#22c55e;color:#052e16">Save External Search Providers</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Keys With Access Context</h2>
        <div class="muted">These entries come from <code><?php echo h(api_keys_file_path()); ?></code>. Keys with <code>search</code> or <code>tools</code> scope can call <code>/v1/search</code> on this server.</div>
        <?php if (empty($searchApiKeys)): ?>
            <div class="row muted">No readable keys found.</div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Masked Key</th>
                    <th>Status</th>
                    <th>Scopes</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($searchApiKeys as $row): ?>
                    <tr>
                        <td><?php echo h($row['name'] !== '' ? $row['name'] : 'Unnamed key'); ?></td>
                        <td><code><?php echo h($row['masked_key']); ?></code></td>
                        <td>
                            <span class="pill <?php echo $row['active'] ? 'ok' : 'off'; ?>">
                                <?php echo $row['active'] ? 'active' : 'inactive'; ?>
                            </span>
                            <?php if ($row['has_search_access']): ?>
                                <span class="pill ok">search access</span>
                            <?php else: ?>
                                <span class="pill off">no search scope</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row['scopes'])): ?>
                                <?php foreach ($row['scopes'] as $scope): ?>
                                    <span class="pill"><?php echo h((string)$scope); ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="muted">none</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo h($row['created_at'] !== '' ? $row['created_at'] : '-'); ?></td>
                        <td>
                            <div class="key-actions">
                                <button type="button" class="use-btn js-use-key" data-key="<?php echo h($row['key']); ?>">Use In Tester</button>
                                <button type="button" class="copy-btn js-copy-key" data-key="<?php echo h($row['key']); ?>">Copy Key</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if (is_array($searxResult)): ?>
        <div class="card">
            <h2>SearXNG Result</h2>
            <div class="muted">URL: <code><?php echo h((string)$searxResult['url']); ?></code></div>
            <div class="row muted">HTTP: <code><?php echo h((string)$searxResult['http_code']); ?></code><?php if ((string)$searxResult['curl_error'] !== ''): ?> | cURL: <code><?php echo h((string)$searxResult['curl_error']); ?></code><?php endif; ?></div>
            <?php
                $count = 0;
                if (is_array($searxResult['json']) && isset($searxResult['json']['results']) && is_array($searxResult['json']['results'])) $count = count($searxResult['json']['results']);
            ?>
            <div class="row muted">Result count: <strong><?php echo h((string)$count); ?></strong></div>
            <div class="row"><pre><?php echo h(pretty_json_text((string)$searxResult['raw'])); ?></pre></div>
        </div>
    <?php endif; ?>

    <?php if (is_array($v1Result)): ?>
        <div class="card">
            <h2>/v1/search Result</h2>
            <div class="muted">URL: <code><?php echo h((string)$v1Result['url']); ?></code></div>
            <div class="row muted">HTTP: <code><?php echo h((string)$v1Result['http_code']); ?></code><?php if ((string)$v1Result['curl_error'] !== ''): ?> | cURL: <code><?php echo h((string)$v1Result['curl_error']); ?></code><?php endif; ?></div>
            <?php
                $ok = is_array($v1Result['json']) ? (string)($v1Result['json']['ok'] ?? '') : '';
                $meta = is_array($v1Result['json']) && is_array($v1Result['json']['meta'] ?? null) ? $v1Result['json']['meta'] : [];
                $aiOverviewText = ai_overview_plain_text($meta);
                $relatedSearches = related_search_labels($meta);
            ?>
            <?php if (is_array($v1Result['json'])): ?>
                <div class="row muted">ok: <strong><?php echo h($ok); ?></strong> | cache_hit: <strong><?php echo h((string)($meta['cache_hit'] ?? '')); ?></strong> | count: <strong><?php echo h((string)($meta['count'] ?? '')); ?></strong><?php if (isset($meta['provider'])): ?> | provider: <strong><?php echo h((string)$meta['provider']); ?></strong><?php endif; ?></div>
            <?php endif; ?>
            <?php if ($aiOverviewText !== ''): ?>
                <div class="row">
                    <h3 style="margin-bottom:8px;">AI Overview</h3>
                    <pre><?php echo h($aiOverviewText); ?></pre>
                </div>
            <?php endif; ?>
            <?php if (!empty($relatedSearches)): ?>
                <div class="row">
                    <h3 style="margin-bottom:8px;">Related Searches</h3>
                    <div>
                        <?php foreach ($relatedSearches as $label): ?>
                            <span class="pill"><?php echo h((string)$label); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="row"><pre><?php echo h(pretty_json_text((string)$v1Result['raw'])); ?></pre></div>
        </div>
    <?php endif; ?>

    <?php if (is_array($selectedCacheRow)): ?>
        <?php
            $selectedBody = is_array($selectedCacheRow['body_json']) ? $selectedCacheRow['body_json'] : [];
            $selectedMeta = isset($selectedBody['upstream']) && is_array($selectedBody['upstream']) ? $selectedBody['upstream'] : [];
            $selectedAiOverview = ai_overview_plain_text($selectedMeta);
            $selectedRelated = related_search_labels($selectedMeta);
        ?>
        <div class="card">
            <h2>Search Cache Row #<?php echo h((string)$selectedCacheRow['id']); ?></h2>
            <div class="row muted">Query: <strong><?php echo h((string)($selectedCacheRow['q'] ?? '')); ?></strong> | Cached At: <strong><?php echo h((string)($selectedCacheRow['cached_at'] ?? '')); ?></strong></div>
            <?php if (!empty($selectedCacheRow['top_urls_json'])): ?>
                <div class="row">
                    <h3 style="margin-bottom:8px;">Top URLs</h3>
                    <div>
                        <?php foreach ($selectedCacheRow['top_urls_json'] as $url): ?>
                            <span class="pill"><?php echo h((string)$url); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($selectedAiOverview !== ''): ?>
                <div class="row">
                    <h3 style="margin-bottom:8px;">AI Overview</h3>
                    <pre><?php echo h($selectedAiOverview); ?></pre>
                </div>
            <?php endif; ?>
            <?php if (!empty($selectedRelated)): ?>
                <div class="row">
                    <h3 style="margin-bottom:8px;">Related Searches</h3>
                    <div>
                        <?php foreach ($selectedRelated as $label): ?>
                            <span class="pill"><?php echo h((string)$label); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="row">
                <h3 style="margin-bottom:8px;">Cached JSON</h3>
                <pre><?php echo h(pretty_json_text((string)($selectedCacheRow['body'] ?? ''))); ?></pre>
            </div>
        </div>
    <?php elseif ($selectedCacheId > 0): ?>
        <div class="card">
            <h2>Search Cache Row</h2>
            <div class="err">Cache row <strong><?php echo h((string)$selectedCacheId); ?></strong> was not found.</div>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Recent Search Cache Rows</h2>
        <div class="muted">From <code><?php echo h(search_cache_db_path()); ?></code>. Open full editor at <a href="/admin/admin_notes.php?view=search_cache">Search Cache view</a>.</div>
        <?php if (empty($recentCacheRows)): ?>
            <div class="row muted">No rows found (or DB not readable).</div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Cached At</th>
                    <th>Query</th>
                    <th>Top URLs</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recentCacheRows as $r): ?>
                    <tr>
                        <td><a href="/admin/admin_API_Search.php?cache_id=<?php echo urlencode((string)($r['id'] ?? '')); ?>"><?php echo h((string)($r['id'] ?? '')); ?></a></td>
                        <td><?php echo h((string)($r['cached_at'] ?? '')); ?></td>
                        <td><?php echo h((string)($r['q'] ?? '')); ?></td>
                        <td><?php echo h((string)($r['top_count'] ?? 0)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    var input = document.getElementById('api-key-input');
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
    document.querySelectorAll('.js-use-key').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!input) return;
            input.value = btn.getAttribute('data-key') || '';
            input.focus();
        });
    });
    document.querySelectorAll('.js-copy-key').forEach(function (btn) {
        btn.addEventListener('click', function () {
            copyText(btn.getAttribute('data-key') || '');
        });
    });
})();
</script>
</body>
</html>
