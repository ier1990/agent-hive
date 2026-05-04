<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

header('Content-Type: text/html; charset=utf-8');

function ai_search_notes_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ai_search_notes_search_href($query)
{
    $query = trim((string)$query);
    if ($query === '') {
        return '/v1/search/?cached_ai_summary=1';
    }
    return '/v1/search/?q=' . rawurlencode($query) . '&cached_ai_summary=1';
}

function ai_search_notes_db_path()
{
    return rtrim((string)PRIVATE_ROOT, '/\\') . '/db/memory/ai_search_notes.db';
}

function ai_search_cache_db_path()
{
    return rtrim((string)PRIVATE_ROOT, '/\\') . '/db/memory/search_cache.db';
}

function ai_search_notes_open_db($path)
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    return $db;
}

function ai_search_notes_ensure_schema(SQLite3 $db)
{
    $db->exec('PRAGMA journal_mode=WAL');
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

    $db->exec(
        'CREATE TABLE IF NOT EXISTS job_runs (
            job TEXT PRIMARY KEY,
            last_start TEXT,
            last_ok TEXT,
            last_status TEXT,
            last_message TEXT,
            last_duration_ms INTEGER
        )'
    );
}

function ai_search_notes_summary(SQLite3 $db)
{
    $summary = [
        'total' => 0,
        'ai_generated' => 0,
        'recent' => '',
        'jobs' => 0,
        'latest_job' => null,
    ];
    $row = $db->querySingle(
        "SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN notes_type = 'ai_generated' THEN 1 ELSE 0 END) AS ai_generated_count,
            MAX(updated_at) AS updated_at_max
         FROM notes",
        true
    );
    if (is_array($row)) {
        $summary['total'] = (int)($row['total_count'] ?? 0);
        $summary['ai_generated'] = (int)($row['ai_generated_count'] ?? 0);
        $summary['recent'] = (string)($row['updated_at_max'] ?? '');
    }
    $jobs = $db->querySingle('SELECT COUNT(*) FROM job_runs', true);
    if (is_array($jobs)) {
        $summary['jobs'] = (int)array_values($jobs)[0];
    }
    $job = $db->querySingle(
        "SELECT job, last_start, last_ok, last_status, last_message, last_duration_ms
         FROM job_runs
         ORDER BY COALESCE(last_start, '') DESC
         LIMIT 1",
        true
    );
    if (is_array($job) && !empty($job)) {
        $summary['latest_job'] = $job;
    }
    return $summary;
}

function ai_search_notes_fetch_rows(SQLite3 $db, $query, $typeFilter, $limit)
{
    $limit = max(1, min(250, (int)$limit));
    $sql = "SELECT id, notes_type, topic, note, created_at, updated_at
            FROM notes
            WHERE 1 = 1";
    if ($typeFilter !== '' && $typeFilter !== 'all') {
        $sql .= ' AND notes_type = :type_filter';
    }
    if ($query !== '') {
        $sql .= ' AND (COALESCE(topic, \'\') LIKE :q OR COALESCE(note, \'\') LIKE :q OR COALESCE(notes_type, \'\') LIKE :q)';
    }
    $sql .= ' ORDER BY updated_at DESC, id DESC LIMIT :lim';
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

function ai_search_notes_excerpt($text, $limit)
{
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    if (strlen($text) > $limit) {
        $text = substr($text, 0, $limit) . '...';
    }
    return $text;
}

function ai_search_notes_parse_note($text)
{
    $text = (string)$text;
    $parsed = [
        'search_cache_id' => 0,
        'cached_at' => '',
        'query' => '',
        'top_urls' => [],
        'summary' => trim($text),
    ];

    if (preg_match('/^search_cache_id:\s*(\d+)/mi', $text, $m)) {
        $parsed['search_cache_id'] = (int)$m[1];
    }
    if (preg_match('/^cached_at:\s*(.+)$/mi', $text, $m)) {
        $parsed['cached_at'] = trim($m[1]);
    }
    if (preg_match('/^query:\s*(.+)$/mi', $text, $m)) {
        $parsed['query'] = trim($m[1]);
    }

    if (preg_match('/top_urls:\s*(.*?)\n\s*summary:\s*/is', $text, $m)) {
        $urlBlock = trim($m[1]);
        if ($urlBlock !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $urlBlock);
            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, '- ') === 0) {
                    $line = trim(substr($line, 2));
                }
                if ($line !== '') {
                    $parsed['top_urls'][] = $line;
                }
            }
        }
    }

    if (preg_match('/\nsummary:\s*(.*)$/is', $text, $m)) {
        $parsed['summary'] = trim($m[1]);
    }

    return $parsed;
}

function ai_search_notes_extract_ids($rows)
{
    $ids = [];
    foreach ($rows as $row) {
        $parsed = ai_search_notes_parse_note(isset($row['note']) ? $row['note'] : '');
        if (!empty($parsed['search_cache_id'])) {
            $ids[$parsed['search_cache_id']] = $parsed['search_cache_id'];
        }
    }
    return array_values($ids);
}

function ai_search_notes_decode_ai_payload($raw)
{
    $raw = trim((string)$raw);
    $payload = [
        'summary_text' => $raw,
        'overview' => $raw,
        'bullets' => [],
        'notable_urls' => [],
        'format' => 'text',
    ];
    if ($raw === '') {
        return $payload;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $payload;
    }

    $payload['summary_text'] = trim((string)($decoded['summary_text'] ?? ''));
    $payload['overview'] = trim((string)($decoded['overview'] ?? ''));
    $payload['format'] = 'json';
    $payload['bullets'] = [];
    $payload['notable_urls'] = [];

    if (isset($decoded['bullets']) && is_array($decoded['bullets'])) {
        foreach ($decoded['bullets'] as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $payload['bullets'][] = $item;
            }
        }
    }
    if (isset($decoded['notable_urls']) && is_array($decoded['notable_urls'])) {
        foreach ($decoded['notable_urls'] as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $payload['notable_urls'][] = $item;
            }
        }
    }

    if ($payload['summary_text'] === '') {
        $payload['summary_text'] = $payload['overview'];
    }
    if ($payload['overview'] === '') {
        $payload['overview'] = $payload['summary_text'];
    }

    return $payload;
}

function ai_search_notes_fetch_cache_payloads($path, $ids)
{
    $out = [];
    if (!is_array($ids) || empty($ids) || !is_file($path)) {
        return $out;
    }

    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    $safeIds = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $safeIds[] = $id;
        }
    }
    if (empty($safeIds)) {
        $db->close();
        return $out;
    }

    $sql = "SELECT id, COALESCE(ai_notes, '') AS ai_notes
            FROM search_cache_history
            WHERE id IN (" . implode(',', $safeIds) . ")";
    $res = $db->query($sql);
    while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
        $out[(int)$row['id']] = ai_search_notes_decode_ai_payload((string)$row['ai_notes']);
    }
    $db->close();
    return $out;
}

$db = ai_search_notes_open_db(ai_search_notes_db_path());
ai_search_notes_ensure_schema($db);
$query = trim((string)($_GET['q'] ?? ''));
$typeFilter = trim((string)($_GET['type'] ?? 'all'));
if ($typeFilter === '') {
    $typeFilter = 'all';
}
$summary = ai_search_notes_summary($db);
$rows = ai_search_notes_fetch_rows($db, $query, $typeFilter, 200);
$cachePayloads = ai_search_notes_fetch_cache_payloads(ai_search_cache_db_path(), ai_search_notes_extract_ids($rows));
$types = ['all', 'ai_generated'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Search Notes</title>
    <style>
        :root {
            --bg: #0b1220;
            --panel: #111827;
            --panel-2: #0d1627;
            --line: #30415f;
            --text: #ecf2ff;
            --muted: #aab9d6;
            --accent: #7dd3fc;
            --accent-2: #34d399;
            --warm: #fbbf24;
            --radius: 12px;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: linear-gradient(180deg, #08101c 0%, #0f172a 100%); color: var(--text); font-family: Georgia, "Times New Roman", serif; }
        a { color: var(--accent); }
        .wrap { max-width: 1500px; margin: 0 auto; padding: 18px; }
        .nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
        .nav a { text-decoration: none; color: var(--text); background: rgba(17,24,39,0.9); border: 1px solid var(--line); border-radius: 999px; padding: 8px 12px; font-size: 14px; }
        .hero, .card { background: rgba(17,24,39,0.95); border: 1px solid var(--line); border-radius: 16px; padding: 16px; margin-bottom: 14px; }
        .hero h1, .card h2 { margin: 0 0 10px; }
        .muted { color: var(--muted); }
        .stats { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .stat { background: rgba(8,16,28,0.7); border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; min-width: 140px; }
        .stat strong { display: block; font-size: 20px; }
        .hero-grid { display: grid; grid-template-columns: minmax(0, 1.7fr) minmax(280px, 0.95fr); gap: 14px; align-items: start; }
        form.search { display: grid; grid-template-columns: 1fr 180px auto; gap: 10px; }
        input[type="text"], select { width: 100%; border-radius: 12px; border: 1px solid var(--line); background: #0a1220; color: var(--text); padding: 12px; font: inherit; }
        button { border: 1px solid var(--line); border-radius: 999px; background: #152238; color: var(--text); padding: 10px 14px; cursor: pointer; font: inherit; }
        .list { display: grid; gap: 10px; }
        .item { border: 1px solid var(--line); border-radius: 16px; padding: 14px; background: linear-gradient(135deg, rgba(125,211,252,0.12), rgba(52,211,153,0.09)), var(--panel); box-shadow: inset 0 1px 0 rgba(255,255,255,0.03); }
        .head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
        .summary { white-space: pre-wrap; word-break: break-word; color: #d8e3fb; }
        .pill { display: inline-block; border: 1px solid var(--line); border-radius: 999px; padding: 3px 8px; margin-right: 6px; margin-bottom: 6px; color: var(--muted); font-size: 12px; }
        .title { margin: 0 0 6px; font-size: 22px; line-height: 1.2; }
        .title a { color: var(--text); text-decoration: none; border-bottom: 1px dashed rgba(125,211,252,0.45); }
        .title a:hover { color: var(--accent); border-bottom-color: rgba(125,211,252,0.9); }
        .meta { display: flex; gap: 8px; flex-wrap: wrap; margin: 8px 0 12px; }
        .grid { display: grid; grid-template-columns: minmax(0, 1.45fr) minmax(260px, 0.85fr); gap: 12px; }
        .section { border: 1px solid rgba(48,65,95,0.75); border-radius: 14px; padding: 12px; background: linear-gradient(180deg, rgba(8,16,28,0.7), rgba(13,22,39,0.9)); }
        .section h3 { margin: 0 0 8px; font-size: 13px; letter-spacing: 0.04em; text-transform: uppercase; color: var(--accent); }
        .section ul { margin: 0; padding-left: 18px; }
        .section li { margin-bottom: 8px; color: #d8e3fb; }
        .url-list { display: grid; gap: 8px; }
        .url-list a { display: block; padding: 10px 12px; border-radius: 12px; border: 1px solid rgba(48,65,95,0.75); background: rgba(8,16,28,0.5); text-decoration: none; word-break: break-all; }
        .job-card { border: 1px solid var(--line); border-radius: 14px; padding: 14px; background: linear-gradient(180deg, rgba(14,22,38,0.92), rgba(8,16,28,0.92)); }
        .job-card h2 { margin: 0 0 10px; font-size: 18px; }
        .job-status { display: inline-block; padding: 4px 9px; border-radius: 999px; border: 1px solid var(--line); color: var(--text); }
        .job-status.ok { background: rgba(52,211,153,0.14); border-color: rgba(52,211,153,0.45); color: #b7f7df; }
        .job-status.error { background: rgba(248,113,113,0.14); border-color: rgba(248,113,113,0.45); color: #ffd0d0; }
        .job-status.running { background: rgba(251,191,36,0.14); border-color: rgba(251,191,36,0.45); color: #ffe6a1; }
        .job-meta { display: grid; gap: 8px; margin-top: 10px; }
        .job-meta div { border-top: 1px solid rgba(48,65,95,0.65); padding-top: 8px; }
        .muted code { color: #d7efff; }
        @media (max-width: 760px) {
            .hero-grid,
            .grid { grid-template-columns: 1fr; }
            form.search { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="nav">
        <a href="/admin/admin_notes.php">Human Notes</a>
        <a href="/admin/admin_Bash_History.php">Bash History</a>
        <a href="/admin/admin_AI_Bash_Notes.php">AI Bash Notes</a>
        <a href="/admin/admin_AI_Search_Notes.php">AI Search Notes</a>
        <a href="/admin/admin_API_Search.php">Search Cache / API Search</a>
    </div>

    <div class="hero">
        <div class="hero-grid">
            <div>
                <h1>AI Search Notes</h1>
                <div class="muted">This page is read-only and separate from Human Notes. It reads <code><?php echo ai_search_notes_h(ai_search_notes_db_path()); ?></code> for archived search notes and cross-references <code><?php echo ai_search_notes_h(ai_search_cache_db_path()); ?></code> so structured AI summaries can be shown as query, overview, bullets, and URLs.</div>
                <div class="stats">
                    <div class="stat"><span class="muted">Total Notes</span><strong><?php echo ai_search_notes_h(number_format($summary['total'])); ?></strong></div>
                    <div class="stat"><span class="muted">AI Generated</span><strong><?php echo ai_search_notes_h(number_format($summary['ai_generated'])); ?></strong></div>
                    <div class="stat"><span class="muted">Last Updated</span><strong style="font-size:14px;"><?php echo ai_search_notes_h($summary['recent'] !== '' ? $summary['recent'] : 'n/a'); ?></strong></div>
                    <div class="stat"><span class="muted">Jobs</span><strong><?php echo ai_search_notes_h(number_format($summary['jobs'])); ?></strong></div>
                </div>
            </div>
            <div class="job-card">
                <h2>Latest Job</h2>
                <?php if (is_array($summary['latest_job'])): ?>
                    <?php $job = $summary['latest_job']; $jobStatus = trim((string)($job['last_status'] ?? '')); ?>
                    <div><span class="job-status <?php echo ai_search_notes_h($jobStatus); ?>"><?php echo ai_search_notes_h($jobStatus !== '' ? $jobStatus : 'unknown'); ?></span></div>
                    <div class="job-meta">
                        <div><span class="muted">Job</span><br><strong><?php echo ai_search_notes_h((string)($job['job'] ?? '')); ?></strong></div>
                        <div><span class="muted">Last Start</span><br><strong><?php echo ai_search_notes_h((string)($job['last_start'] ?? 'n/a')); ?></strong></div>
                        <div><span class="muted">Last OK</span><br><strong><?php echo ai_search_notes_h((string)($job['last_ok'] ?? 'n/a')); ?></strong></div>
                        <div><span class="muted">Duration</span><br><strong><?php echo ai_search_notes_h((string)($job['last_duration_ms'] ?? 'n/a')); ?> ms</strong></div>
                        <div><span class="muted">Message</span><br><span class="summary"><?php echo ai_search_notes_h(ai_search_notes_excerpt((string)($job['last_message'] ?? ''), 320)); ?></span></div>
                    </div>
                <?php else: ?>
                    <div class="muted">No `job_runs` row yet. If the DB was recreated by loading this page first, that is expected until `ai_search_summ.py` completes a run.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>Browse AI Search Notes</h2>
        <form method="get" class="search">
            <input type="text" name="q" value="<?php echo ai_search_notes_h($query); ?>" placeholder="Search topic or note content">
            <select name="type">
                <?php foreach ($types as $value): ?>
                    <option value="<?php echo ai_search_notes_h($value); ?>"<?php echo $typeFilter === $value ? ' selected' : ''; ?>><?php echo ai_search_notes_h($value); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Search</button>
        </form>
        <div class="list" style="margin-top:12px;">
            <?php if (empty($rows)): ?>
                <div class="item"><div class="summary muted">No AI search notes matched your current filters.</div></div>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $parsed = ai_search_notes_parse_note((string)$row['note']);
                $cacheId = (int)$parsed['search_cache_id'];
                $payload = isset($cachePayloads[$cacheId]) ? $cachePayloads[$cacheId] : null;
                $queryTitle = trim($parsed['query']) !== '' ? $parsed['query'] : trim((string)$row['topic']);
                $overview = $payload && trim((string)$payload['overview']) !== '' ? trim((string)$payload['overview']) : ai_search_notes_excerpt((string)$parsed['summary'], 420);
                $summaryText = $payload && trim((string)$payload['summary_text']) !== '' ? trim((string)$payload['summary_text']) : trim((string)$parsed['summary']);
                $bullets = $payload && isset($payload['bullets']) && is_array($payload['bullets']) ? $payload['bullets'] : [];
                $urls = $payload && isset($payload['notable_urls']) && is_array($payload['notable_urls']) && !empty($payload['notable_urls']) ? $payload['notable_urls'] : $parsed['top_urls'];
                ?>
                <div class="item">
                    <div class="head">
                        <div>
                            <span class="pill"><?php echo ai_search_notes_h((string)$row['notes_type']); ?></span>
                            <?php if ($cacheId > 0): ?><span class="pill">cache #<?php echo ai_search_notes_h((string)$cacheId); ?></span><?php endif; ?>
                            <?php if ($payload && isset($payload['format'])): ?><span class="pill"><?php echo ai_search_notes_h((string)$payload['format']); ?> payload</span><?php endif; ?>
                            <?php if (trim((string)$parsed['cached_at']) !== ''): ?><span class="pill"><?php echo ai_search_notes_h((string)$parsed['cached_at']); ?></span><?php endif; ?>
                        </div>
                        <div class="muted"><?php echo ai_search_notes_h((string)$row['updated_at']); ?></div>
                    </div>
                    <h3 class="title"><a href="<?php echo ai_search_notes_h(ai_search_notes_search_href($parsed['query'] !== '' ? $parsed['query'] : $queryTitle)); ?>" target="_blank" rel="noopener noreferrer"><?php echo ai_search_notes_h($queryTitle !== '' ? $queryTitle : 'Untitled Search Summary'); ?></a></h3>
                    <div class="meta">
                        <?php if (trim((string)$row['topic']) !== ''): ?><span class="pill"><?php echo ai_search_notes_h((string)$row['topic']); ?></span><?php endif; ?>
                        <span class="pill">created <?php echo ai_search_notes_h((string)$row['created_at']); ?></span>
                    </div>
                    <div class="grid">
                        <div class="section">
                            <h3>Overview</h3>
                            <div class="summary"><?php echo ai_search_notes_h($overview); ?></div>
                        </div>
                        <div class="section">
                            <h3>Notable URLs</h3>
                            <div class="url-list">
                                <?php if (empty($urls)): ?>
                                    <div class="muted">No notable URLs captured.</div>
                                <?php endif; ?>
                                <?php foreach ($urls as $url): ?>
                                    <a href="<?php echo ai_search_notes_h((string)$url); ?>" target="_blank" rel="noopener noreferrer"><?php echo ai_search_notes_h((string)$url); ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($bullets)): ?>
                        <div class="section" style="margin-top:12px;">
                            <h3>Key Findings</h3>
                            <ul>
                                <?php foreach ($bullets as $bullet): ?>
                                    <li><?php echo ai_search_notes_h((string)$bullet); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <div class="section" style="margin-top:12px;">
                        <h3>Stored Summary</h3>
                        <div class="summary"><?php echo ai_search_notes_h(ai_search_notes_excerpt($summaryText, 2200)); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
</body>
</html>
