<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

header('Content-Type: text/html; charset=utf-8');

function bash_history_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bash_history_db_path()
{
    return rtrim((string)PRIVATE_ROOT, '/\\') . '/db/memory/bash_history.db';
}

function bash_history_format_number($value)
{
    return number_format((int)$value);
}

function bash_history_open_db()
{
    $path = bash_history_db_path();
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    return $db;
}

function bash_history_summary(SQLite3 $db)
{
    $summary = [
        'commands' => 0,
        'classified' => 0,
        'searchable' => 0,
        'pending' => 0,
    ];
    $row = $db->querySingle(
        'SELECT
            (SELECT COUNT(*) FROM commands) AS commands_count,
            (SELECT COUNT(*) FROM command_ai WHERE status = \'done\') AS classified_count,
            (SELECT COUNT(*) FROM command_ai WHERE status = \'done\' AND known = 1 AND search_query IS NOT NULL AND TRIM(search_query) <> \'\') AS searchable_count,
            (SELECT COUNT(*) FROM command_ai WHERE status IN (\'pending\', \'working\', \'error\')) AS pending_count',
        true
    );
    if (is_array($row)) {
        $summary['commands'] = (int)($row['commands_count'] ?? 0);
        $summary['classified'] = (int)($row['classified_count'] ?? 0);
        $summary['searchable'] = (int)($row['searchable_count'] ?? 0);
        $summary['pending'] = (int)($row['pending_count'] ?? 0);
    }
    return $summary;
}

function bash_history_fetch_rows(SQLite3 $db, $query, $status, $limit)
{
    $limit = max(1, min(250, (int)$limit));
    $sql = 'SELECT
                c.id,
                c.full_cmd,
                c.base_cmd,
                c.first_seen,
                c.last_seen,
                c.seen_count,
                COALESCE(a.status, \'\') AS ai_status,
                COALESCE(a.summary, \'\') AS ai_summary,
                COALESCE(a.search_query, \'\') AS search_query,
                COALESCE(a.known, 0) AS known,
                COALESCE(a.last_error, \'\') AS last_error
            FROM commands c
            LEFT JOIN command_ai a ON a.cmd_id = c.id
            WHERE 1 = 1';
    $stmt = null;
    if ($status !== '' && $status !== 'all') {
        $sql .= ' AND COALESCE(a.status, \'\') = :status';
    }
    if ($query !== '') {
        $sql .= ' AND (c.full_cmd LIKE :q OR c.base_cmd LIKE :q OR COALESCE(a.summary, \'\') LIKE :q OR COALESCE(a.search_query, \'\') LIKE :q)';
    }
    $sql .= ' ORDER BY c.last_seen DESC, c.id DESC LIMIT :lim';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($status !== '' && $status !== 'all') {
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
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

$db = bash_history_open_db();
$query = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'all'));
$summary = $db ? bash_history_summary($db) : null;
$rows = $db ? bash_history_fetch_rows($db, $query, $status, 200) : [];
$statuses = ['all', 'pending', 'working', 'done', 'error'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bash History</title>
    <style>
        :root {
            --bg: #0b1220;
            --panel: #111827;
            --line: #30415f;
            --text: #ecf2ff;
            --muted: #aab9d6;
            --accent: #7dd3fc;
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
        form.search { display: grid; grid-template-columns: 1fr 180px auto; gap: 10px; }
        input[type="text"], select { width: 100%; border-radius: 12px; border: 1px solid var(--line); background: #0a1220; color: var(--text); padding: 12px; font: inherit; }
        button { border: 1px solid var(--line); border-radius: 999px; background: #152238; color: var(--text); padding: 10px 14px; cursor: pointer; font: inherit; }
        .list { display: grid; gap: 10px; }
        .item { border: 1px solid var(--line); border-radius: 14px; padding: 12px; background: rgba(8,16,28,0.65); }
        .head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
        .cmd { font-family: "SFMono-Regular", Consolas, monospace; white-space: pre-wrap; word-break: break-word; color: #dbeafe; }
        .pill { display: inline-block; border: 1px solid var(--line); border-radius: 999px; padding: 3px 8px; margin-right: 6px; margin-bottom: 6px; color: var(--muted); font-size: 12px; }
        .summary { white-space: pre-wrap; word-break: break-word; color: #d8e3fb; }
        @media (max-width: 760px) {
            form.search { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="nav">
        <a href="/admin/admin_notes.php">Human Notes</a>
        <a href="/admin/admin_Bash_History.php">Bash History</a>
        <a href="/admin/admin_API_Search.php">Search Cache / API Search</a>
    </div>

    <div class="hero">
        <h1>Bash History</h1>
        <div class="muted">This page is read-only and separate from your manual notes. It reads <code><?php echo bash_history_h(bash_history_db_path()); ?></code> so bash ingestion/classification/search queue work is visible without mixing it into the human notes editor.</div>
        <?php if ($summary !== null): ?>
            <div class="stats">
                <div class="stat"><span class="muted">Commands</span><strong><?php echo bash_history_h(bash_history_format_number($summary['commands'])); ?></strong></div>
                <div class="stat"><span class="muted">Classified</span><strong><?php echo bash_history_h(bash_history_format_number($summary['classified'])); ?></strong></div>
                <div class="stat"><span class="muted">Searchable</span><strong><?php echo bash_history_h(bash_history_format_number($summary['searchable'])); ?></strong></div>
                <div class="stat"><span class="muted">Pending/Error</span><strong><?php echo bash_history_h(bash_history_format_number($summary['pending'])); ?></strong></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Browse Commands</h2>
        <form method="get" class="search">
            <input type="text" name="q" value="<?php echo bash_history_h($query); ?>" placeholder="Search full command, base command, summary, or search query">
            <select name="status">
                <?php foreach ($statuses as $value): ?>
                    <option value="<?php echo bash_history_h($value); ?>"<?php echo $status === $value ? ' selected' : ''; ?>><?php echo bash_history_h($value); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Search</button>
        </form>
        <?php if ($db === null): ?>
            <div class="item"><div class="summary muted">Bash history DB not found yet.</div></div>
        <?php else: ?>
            <div class="list">
                <?php if (empty($rows)): ?>
                    <div class="item"><div class="summary muted">No commands matched your current filters.</div></div>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <div class="item">
                        <div class="head">
                            <div>
                                <span class="pill"><?php echo bash_history_h((string)$row['base_cmd']); ?></span>
                                <span class="pill">status: <?php echo bash_history_h((string)$row['ai_status']); ?></span>
                                <span class="pill">seen: <?php echo bash_history_h(bash_history_format_number((int)$row['seen_count'])); ?></span>
                                <?php if ((int)($row['known'] ?? 0) === 1): ?><span class="pill">known</span><?php endif; ?>
                            </div>
                            <div class="muted"><?php echo bash_history_h((string)$row['last_seen']); ?></div>
                        </div>
                        <div class="cmd"><?php echo bash_history_h((string)$row['full_cmd']); ?></div>
                        <?php if (trim((string)$row['ai_summary']) !== ''): ?>
                            <div class="summary" style="margin-top:10px;"><?php echo bash_history_h((string)$row['ai_summary']); ?></div>
                        <?php endif; ?>
                        <?php if (trim((string)$row['search_query']) !== ''): ?>
                            <div class="summary" style="margin-top:8px;"><span class="muted">search_query:</span> <?php echo bash_history_h((string)$row['search_query']); ?></div>
                        <?php endif; ?>
                        <?php if (trim((string)$row['last_error']) !== ''): ?>
                            <div class="summary" style="margin-top:8px;"><span class="muted">last_error:</span> <?php echo bash_history_h((string)$row['last_error']); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
