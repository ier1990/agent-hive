<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

header('Content-Type: text/html; charset=utf-8');

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
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
$baseUrl = infer_base_url();
$defaultSearxUrl = trim((string)(getenv('SEARX_URL') ?: ''));
$defaultV1SearchUrl = normalize_v1_search_url($notesSearchApiBase, $baseUrl);

$query = trim((string)($_POST['q'] ?? ($_GET['q'] ?? 'teijin seiki ar-60')));
$searxUrl = trim((string)($_POST['searx_url'] ?? ($_GET['searx_url'] ?? $defaultSearxUrl)));
$v1SearchUrl = normalize_v1_search_url((string)($_POST['v1_search_url'] ?? ($_GET['v1_search_url'] ?? $defaultV1SearchUrl)), $baseUrl);
$apiKey = trim((string)($_POST['api_key'] ?? ($_GET['api_key'] ?? '')));
$testTarget = trim((string)($_POST['test_target'] ?? ''));

$searxResult = null;
$v1Result = null;
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if (!auth_csrf_ok($csrf)) {
        $error = 'Invalid CSRF token.';
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
        @media (max-width: 900px) { .grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>API Search Tester</h1>
        <div class="muted">Test raw SearXNG and your API endpoint <code>/v1/search</code>. Search cache UI: <a href="/admin/admin_notes.php?view=search_cache">/admin/admin_notes.php?view=search_cache</a></div>
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
                    <label>API Key for /v1/search (optional)</label>
                    <input type="text" name="api_key" value="<?php echo h($apiKey); ?>" placeholder="key with search scope">
                </div>
            </div>
            <div class="row"><button type="submit">Run Test</button></div>
        </form>
        <?php if ($notesSearchApiBase !== ''): ?>
            <div class="row muted">Configured in Notes app_settings (<code>search.api.base</code>): <code><?php echo h($notesSearchApiBase); ?></code></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="row err"><strong>Error:</strong> <?php echo h($error); ?></div>
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
            <div class="row"><pre><?php echo h((string)$searxResult['raw']); ?></pre></div>
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
            ?>
            <?php if (is_array($v1Result['json'])): ?>
                <div class="row muted">ok: <strong><?php echo h($ok); ?></strong> | cache_hit: <strong><?php echo h((string)($meta['cache_hit'] ?? '')); ?></strong> | count: <strong><?php echo h((string)($meta['count'] ?? '')); ?></strong></div>
            <?php endif; ?>
            <div class="row"><pre><?php echo h((string)$v1Result['raw']); ?></pre></div>
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
                        <td><?php echo h((string)($r['id'] ?? '')); ?></td>
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
</body>
</html>
