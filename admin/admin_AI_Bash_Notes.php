<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

header('Content-Type: text/html; charset=utf-8');

function ai_bash_notes_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ai_bash_notes_db_path()
{
    return rtrim((string)PRIVATE_ROOT, '/\\') . '/db/memory/bash_history.db';
}

function ai_bash_notes_open_db($path)
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    return $db;
}

function ai_bash_notes_summary(SQLite3 $db)
{
    $summary = [
        'commands' => 0,
        'done' => 0,
        'known' => 0,
        'with_notes' => 0,
    ];
    $row = $db->querySingle(
        "SELECT
            (SELECT COUNT(*) FROM commands) AS commands_count,
            (SELECT COUNT(*) FROM command_ai WHERE status = 'done') AS done_count,
            (SELECT COUNT(*) FROM command_ai WHERE status = 'done' AND known = 1) AS known_count,
            (SELECT COUNT(*) FROM command_ai WHERE status = 'done' AND TRIM(COALESCE(result_json, '')) <> '') AS with_notes_count",
        true
    );
    if (is_array($row)) {
        $summary['commands'] = (int)($row['commands_count'] ?? 0);
        $summary['done'] = (int)($row['done_count'] ?? 0);
        $summary['known'] = (int)($row['known_count'] ?? 0);
        $summary['with_notes'] = (int)($row['with_notes_count'] ?? 0);
    }
    return $summary;
}

function ai_bash_notes_fetch_rows(SQLite3 $db, $query, $status, $knownFilter, $limit)
{
    $limit = max(1, min(250, (int)$limit));
    $sql = "SELECT
                c.id,
                c.full_cmd,
                c.base_cmd,
                c.last_seen,
                c.seen_count,
                COALESCE(a.status, '') AS ai_status,
                COALESCE(a.summary, '') AS ai_summary,
                COALESCE(a.result_json, '') AS result_json,
                COALESCE(a.known, 0) AS known,
                COALESCE(a.model, '') AS model_name,
                COALESCE(a.last_error, '') AS last_error,
                COALESCE(a.updated_at, '') AS updated_at
            FROM commands c
            LEFT JOIN command_ai a ON a.cmd_id = c.id
            WHERE 1 = 1";
    if ($status !== '' && $status !== 'all') {
        $sql .= ' AND COALESCE(a.status, \'\') = :status';
    }
    if ($knownFilter === 'known') {
        $sql .= ' AND COALESCE(a.known, 0) = 1';
    } elseif ($knownFilter === 'unknown') {
        $sql .= ' AND COALESCE(a.known, 0) = 0';
    }
    if ($query !== '') {
        $sql .= " AND (
                    COALESCE(c.full_cmd, '') LIKE :q
                    OR COALESCE(c.base_cmd, '') LIKE :q
                    OR COALESCE(a.summary, '') LIKE :q
                    OR COALESCE(a.result_json, '') LIKE :q
                )";
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

function ai_bash_notes_payload(array $row)
{
    $payload = json_decode((string)($row['result_json'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function ai_bash_notes_payload_string(array $payload, $key)
{
    if (!isset($payload[$key])) {
        return '';
    }
    return trim((string)$payload[$key]);
}

function ai_bash_notes_payload_list(array $payload, $key)
{
    if (!isset($payload[$key]) || !is_array($payload[$key])) {
        return '';
    }
    $parts = [];
    foreach ($payload[$key] as $item) {
        $item = trim((string)$item);
        if ($item !== '') {
            $parts[] = $item;
        }
    }
    return implode(', ', $parts);
}

function ai_bash_notes_render_markdownish($text)
{
    $escaped = htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
    $escaped = preg_replace('/`(.+?)`/s', '<code>$1</code>', $escaped);
    $escaped = nl2br($escaped);
    return $escaped;
}

$db = ai_bash_notes_open_db(ai_bash_notes_db_path());
$query = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? 'done'));
if ($status === '') {
    $status = 'done';
}
$known = trim((string)($_GET['known'] ?? 'all'));
if ($known === '') {
    $known = 'all';
}
$summary = $db ? ai_bash_notes_summary($db) : null;
$rows = $db ? ai_bash_notes_fetch_rows($db, $query, $status, $known, 200) : [];
$statuses = ['all', 'pending', 'working', 'done', 'error'];
$knownOptions = ['all', 'known', 'unknown'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Bash Notes</title>
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
        code { background: rgba(8,16,28,0.55); padding: 1px 4px; border-radius: 6px; }
        .wrap { max-width: 1500px; margin: 0 auto; padding: 18px; }
        .nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
        .nav a { text-decoration: none; color: var(--text); background: rgba(17,24,39,0.9); border: 1px solid var(--line); border-radius: 999px; padding: 8px 12px; font-size: 14px; }
        .hero, .card { background: rgba(17,24,39,0.95); border: 1px solid var(--line); border-radius: 16px; padding: 16px; margin-bottom: 14px; }
        .hero h1, .card h2 { margin: 0 0 10px; }
        .muted { color: var(--muted); }
        .stats { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .stat { background: rgba(8,16,28,0.7); border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; min-width: 140px; }
        .stat strong { display: block; font-size: 20px; }
        form.search { display: grid; grid-template-columns: 1fr 180px 180px auto; gap: 10px; }
        input[type="text"], select { width: 100%; border-radius: 12px; border: 1px solid var(--line); background: #0a1220; color: var(--text); padding: 12px; font: inherit; }
        button { border: 1px solid var(--line); border-radius: 999px; background: #152238; color: var(--text); padding: 10px 14px; cursor: pointer; font: inherit; }
        .list { display: grid; gap: 10px; }
        .item { border: 1px solid var(--line); border-radius: 14px; padding: 14px; background: linear-gradient(135deg, rgba(125,211,252,0.12), rgba(52,211,153,0.09)), var(--panel); }
        .head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
        .cmd { font-family: "SFMono-Regular", Consolas, monospace; white-space: pre-wrap; word-break: break-word; color: #dbeafe; background: rgba(8,16,28,0.55); padding: 10px; border-radius: 10px; }
        .summary { white-space: pre-wrap; word-break: break-word; color: #d8e3fb; line-height: 1.55; }
        .pill { display: inline-block; border: 1px solid var(--line); border-radius: 999px; padding: 3px 8px; margin-right: 6px; margin-bottom: 6px; color: var(--muted); font-size: 12px; }
        .section { margin-top: 10px; }
        .section-title { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; }
        @media (max-width: 860px) {
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
        <h1>AI Bash Notes</h1>
        <div class="muted">This page is the bash tutor view. It reads <code><?php echo ai_bash_notes_h(ai_bash_notes_db_path()); ?></code> and shows the AI classification for each command so you can learn what a command does, when to use it, and what it means in practice.</div>
        <?php if ($summary !== null): ?>
            <div class="stats">
                <div class="stat"><span class="muted">Commands</span><strong><?php echo ai_bash_notes_h(number_format($summary['commands'])); ?></strong></div>
                <div class="stat"><span class="muted">AI Done</span><strong><?php echo ai_bash_notes_h(number_format($summary['done'])); ?></strong></div>
                <div class="stat"><span class="muted">Known</span><strong><?php echo ai_bash_notes_h(number_format($summary['known'])); ?></strong></div>
                <div class="stat"><span class="muted">AI Rows</span><strong><?php echo ai_bash_notes_h(number_format($summary['with_notes'])); ?></strong></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Browse Bash Tutor Notes</h2>
        <form method="get" class="search">
            <input type="text" name="q" value="<?php echo ai_bash_notes_h($query); ?>" placeholder="Search command, intent, explanation, or keywords">
            <select name="status">
                <?php foreach ($statuses as $value): ?>
                    <option value="<?php echo ai_bash_notes_h($value); ?>"<?php echo $status === $value ? ' selected' : ''; ?>><?php echo ai_bash_notes_h($value); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="known">
                <?php foreach ($knownOptions as $value): ?>
                    <option value="<?php echo ai_bash_notes_h($value); ?>"<?php echo $known === $value ? ' selected' : ''; ?>><?php echo ai_bash_notes_h($value); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Search</button>
        </form>
        <?php if ($db === null): ?>
            <div class="item"><div class="summary muted">Bash history DB not found yet.</div></div>
        <?php else: ?>
            <div class="list" style="margin-top:12px;">
                <?php if (empty($rows)): ?>
                    <div class="item"><div class="summary muted">No AI bash tutor notes matched your current filters.</div></div>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $payload = ai_bash_notes_payload($row);
                    $intent = ai_bash_notes_payload_string($payload, 'intent');
                    $notes = ai_bash_notes_payload_string($payload, 'notes');
                    $keywords = ai_bash_notes_payload_list($payload, 'keywords');
                    ?>
                    <div class="item">
                        <div class="head">
                            <div>
                                <span class="pill"><?php echo ai_bash_notes_h((string)$row['base_cmd']); ?></span>
                                <span class="pill">status: <?php echo ai_bash_notes_h((string)$row['ai_status']); ?></span>
                                <span class="pill"><?php echo (int)($row['known'] ?? 0) === 1 ? 'known' : 'unknown'; ?></span>
                                <span class="pill">seen: <?php echo ai_bash_notes_h(number_format((int)$row['seen_count'])); ?></span>
                            </div>
                            <div class="muted"><?php echo ai_bash_notes_h((string)$row['last_seen']); ?></div>
                        </div>
                        <div class="cmd"><?php echo ai_bash_notes_h((string)$row['full_cmd']); ?></div>
                        <?php if ($intent !== '' || trim((string)$row['ai_summary']) !== ''): ?>
                            <div class="section">
                                <div class="section-title">What It Does</div>
                                <div class="summary"><?php echo ai_bash_notes_render_markdownish($intent !== '' ? $intent : (string)$row['ai_summary']); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($notes !== ''): ?>
                            <div class="section">
                                <div class="section-title">Tutor Notes</div>
                                <div class="summary"><?php echo ai_bash_notes_render_markdownish($notes); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($keywords !== ''): ?>
                            <div class="section">
                                <div class="section-title">Keywords</div>
                                <div class="summary"><?php echo ai_bash_notes_h($keywords); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if (trim((string)$row['model_name']) !== ''): ?>
                            <div class="section">
                                <div class="summary"><span class="muted">model:</span> <?php echo ai_bash_notes_h((string)$row['model_name']); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if (trim((string)$row['last_error']) !== ''): ?>
                            <div class="section">
                                <div class="summary"><span class="muted">last_error:</span> <?php echo ai_bash_notes_h((string)$row['last_error']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
