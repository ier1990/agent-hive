<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalize_path($path)
{
    $p = str_replace('\\', '/', (string)$path);
    $p = rtrim($p, '/');
    return $p === '' ? '/' : $p;
}

function starts_with_path($candidate, $parent)
{
    $candidateN = normalize_path($candidate);
    $parentN = normalize_path($parent);
    if ($candidateN === $parentN) {
        return true;
    }
    return strpos($candidateN . '/', $parentN . '/') === 0;
}

function quote_ident($name)
{
    return '"' . str_replace('"', '""', (string)$name) . '"';
}

function discover_db_files($root)
{
    $rows = [];
    if (!is_dir($root)) {
        return $rows;
    }

    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $name = (string)$fileInfo->getFilename();
            if (strtolower(substr($name, -3)) !== '.db') {
                continue;
            }

            $abs = (string)$fileInfo->getPathname();
            $real = realpath($abs);
            if ($real === false || !starts_with_path($real, $root)) {
                continue;
            }

            $rel = ltrim(str_replace('\\', '/', substr($real, strlen(rtrim($root, '/\\')))), '/');
            $rows[] = [
                'rel' => $rel,
                'abs' => $real,
                'size' => @filesize($real),
                'mtime' => @filemtime($real),
            ];
        }
    } catch (Throwable $e) {
        return $rows;
    }

    usort($rows, function ($a, $b) {
        return strcmp((string)$a['rel'], (string)$b['rel']);
    });

    return $rows;
}

function resolve_db_file($dbParam, $dbRoot)
{
    if (!is_string($dbParam) || trim($dbParam) === '') {
        return null;
    }

    $dbParam = str_replace('\\', '/', trim($dbParam));
    if (strpos($dbParam, "\0") !== false) {
        return null;
    }

    if ($dbParam[0] === '/') {
        $dbParam = ltrim($dbParam, '/');
    }

    $candidate = $dbRoot . '/' . $dbParam;
    $real = realpath($candidate);
    if ($real === false) {
        return null;
    }
    if (!is_file($real)) {
        return null;
    }
    if (!starts_with_path($real, $dbRoot)) {
        return null;
    }
    if (strtolower(substr($real, -3)) !== '.db') {
        return null;
    }

    return [
        'abs' => $real,
        'rel' => ltrim(str_replace('\\', '/', substr($real, strlen(rtrim($dbRoot, '/\\')))), '/'),
    ];
}

function list_tables(PDO $pdo)
{
    $out = [];
    $sql = "SELECT name, COALESCE(sql,'') AS create_sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC";
    $stmt = $pdo->query($sql);
    if (!$stmt) {
        return $out;
    }

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($r)) {
            continue;
        }
        $name = (string)($r['name'] ?? '');
        if ($name === '') {
            continue;
        }

        $count = null;
        try {
            $q = $pdo->query('SELECT COUNT(*) AS c FROM ' . quote_ident($name));
            $row = $q ? $q->fetch(PDO::FETCH_ASSOC) : null;
            $count = is_array($row) ? (int)($row['c'] ?? 0) : 0;
        } catch (Throwable $e) {
            $count = null;
        }

        $out[] = [
            'name' => $name,
            'create_sql' => (string)($r['create_sql'] ?? ''),
            'row_count' => $count,
        ];
    }

    return $out;
}

function table_exists($tables, $table)
{
    foreach ($tables as $t) {
        if ((string)($t['name'] ?? '') === (string)$table) {
            return true;
        }
    }
    return false;
}

function table_schema_columns(PDO $pdo, $table)
{
    $cols = [];
    $q = $pdo->query('PRAGMA table_info(' . quote_ident($table) . ')');
    if (!$q) {
        return $cols;
    }

    while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($r)) {
            continue;
        }
        $cols[] = [
            'name' => (string)($r['name'] ?? ''),
            'type' => (string)($r['type'] ?? ''),
            'notnull' => (int)($r['notnull'] ?? 0),
            'dflt_value' => isset($r['dflt_value']) ? (string)$r['dflt_value'] : null,
            'pk' => (int)($r['pk'] ?? 0),
        ];
    }

    return $cols;
}

function build_url($params)
{
    return '?' . http_build_query($params);
}

$privateRoot = defined('PRIVATE_ROOT') ? (string)PRIVATE_ROOT : '/web/private';
$dbRoot = rtrim($privateRoot, '/\\') . '/db';
$dbRootReal = realpath($dbRoot);
$errors = [];

if ($dbRootReal === false || !is_dir($dbRootReal)) {
    $errors[] = 'DB root directory not found: ' . $dbRoot;
    $dbRootReal = $dbRoot;
}

$dbFiles = discover_db_files($dbRootReal);
$dbParam = isset($_GET['db']) ? (string)$_GET['db'] : '';
$tableParam = isset($_GET['table']) ? (string)$_GET['table'] : '';
$q = isset($_GET['q']) ? (string)$_GET['q'] : '';
$col = isset($_GET['col']) ? (string)$_GET['col'] : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per = isset($_GET['per']) ? (int)$_GET['per'] : 50;
$allowedPer = [25, 50, 100, 200];
if (!in_array($per, $allowedPer, true)) {
    $per = 50;
}

$selectedDb = resolve_db_file($dbParam, $dbRootReal);
$pdo = null;
$tables = [];
$selectedTable = null;
$tableColumns = [];
$tableSchemaSql = '';
$rows = [];
$totalRows = 0;
$totalPages = 1;
$canPrev = false;
$canNext = false;

if ($dbParam !== '' && $selectedDb === null) {
    $errors[] = 'Invalid db selection. Allowed path is under PRIVATE_ROOT/db and must be an existing .db file.';
}

if ($selectedDb !== null) {
    try {
        $pdo = new PDO('sqlite:' . $selectedDb['abs']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $errors[] = 'Failed to open database: ' . $e->getMessage();
    }
}

if ($pdo instanceof PDO) {
    $tables = list_tables($pdo);

    if ($tableParam !== '') {
        if (table_exists($tables, $tableParam)) {
            $selectedTable = $tableParam;
        } else {
            $errors[] = 'Invalid table selection.';
        }
    }

    if ($selectedTable !== null) {
        $tableColumns = table_schema_columns($pdo, $selectedTable);

        foreach ($tables as $t) {
            if ((string)($t['name'] ?? '') === $selectedTable) {
                $tableSchemaSql = (string)($t['create_sql'] ?? '');
                break;
            }
        }

        $colNames = [];
        foreach ($tableColumns as $c) {
            $name = (string)($c['name'] ?? '');
            if ($name !== '') {
                $colNames[] = $name;
            }
        }

        $whereSql = '';
        $bindings = [];

        if ($q !== '') {
            if ($col !== '' && in_array($col, $colNames, true)) {
                $whereSql = ' WHERE CAST(' . quote_ident($col) . ' AS TEXT) LIKE :q';
                $bindings[':q'] = '%' . $q . '%';
            } elseif ($col !== '') {
                $errors[] = 'Search column not found in selected table.';
            }
        }

        try {
            $countSql = 'SELECT COUNT(*) AS c FROM ' . quote_ident($selectedTable) . $whereSql;
            $countStmt = $pdo->prepare($countSql);
            foreach ($bindings as $k => $v) {
                $countStmt->bindValue($k, $v, PDO::PARAM_STR);
            }
            $countStmt->execute();
            $countRow = $countStmt->fetch();
            $totalRows = is_array($countRow) ? (int)($countRow['c'] ?? 0) : 0;
        } catch (Throwable $e) {
            $errors[] = 'Failed counting rows: ' . $e->getMessage();
            $totalRows = 0;
        }

        $totalPages = max(1, (int)ceil($totalRows / max(1, $per)));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = max(0, ($page - 1) * $per);

        try {
            $dataSql = 'SELECT * FROM ' . quote_ident($selectedTable) . $whereSql . ' LIMIT :limit OFFSET :offset';
            $dataStmt = $pdo->prepare($dataSql);
            foreach ($bindings as $k => $v) {
                $dataStmt->bindValue($k, $v, PDO::PARAM_STR);
            }
            $dataStmt->bindValue(':limit', (int)$per, PDO::PARAM_INT);
            $dataStmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            $dataStmt->execute();
            $rows = $dataStmt->fetchAll();
        } catch (Throwable $e) {
            $errors[] = 'Failed reading rows: ' . $e->getMessage();
            $rows = [];
        }

        $canPrev = $page > 1;
        $canNext = $page < $totalPages;
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin DB Browser</title>
    <style>
        :root {
            --bg: #000;
            --bg-soft: #0f0f0f;
            --bg-panel: #121212;
            --line: #2a2a2a;
            --text: #fff;
            --muted: #b3b3b3;
            --accent: #66b3ff;
            --danger: #ff8f8f;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .wrap {
            display: grid;
            grid-template-columns: 320px 1fr;
            min-height: 100vh;
        }
        .left {
            border-right: 1px solid var(--line);
            background: var(--bg-panel);
            padding: 12px;
            overflow: auto;
        }
        .right {
            padding: 16px;
            overflow: auto;
        }
        .title {
            font-size: 16px;
            margin: 0 0 6px;
        }
        .meta {
            color: var(--muted);
            font-size: 12px;
            margin: 0 0 12px;
        }
        .box {
            border: 1px solid var(--line);
            background: var(--bg-soft);
            padding: 10px;
            margin-bottom: 12px;
        }
        .db-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .db-list li {
            padding: 6px 4px;
            border-bottom: 1px solid #1f1f1f;
            word-break: break-word;
        }
        .db-list li:last-child { border-bottom: 0; }
        .active { color: #fff; font-weight: bold; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            border: 1px solid var(--line);
            padding: 6px;
            vertical-align: top;
            text-align: left;
        }
        th { background: #151515; }
        tr:nth-child(even) td { background: #0d0d0d; }
        .muted { color: var(--muted); }
        .error {
            border: 1px solid #4a1d1d;
            background: #1b0f0f;
            color: var(--danger);
            padding: 10px;
            margin-bottom: 12px;
        }
        .controls {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        input, select, button {
            background: #0b0b0b;
            color: #fff;
            border: 1px solid var(--line);
            padding: 6px 8px;
            font: inherit;
        }
        button { cursor: pointer; }
        pre {
            background: #070707;
            border: 1px solid var(--line);
            padding: 10px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
            margin: 0;
        }
        .pagination {
            display: flex;
            gap: 10px;
            align-items: center;
            margin: 12px 0;
        }
        @media (max-width: 980px) {
            .wrap { grid-template-columns: 1fr; }
            .left { border-right: 0; border-bottom: 1px solid var(--line); }
        }
    </style>
</head>
<body>
<div class="wrap">
    <aside class="left">
        <h1 class="title">Admin DB Browser</h1>
        <p class="meta">Read-only SQLite browser</p>

        <div class="box">
            <div class="muted" style="margin-bottom:6px;">DB root</div>
            <div><?= e($dbRootReal) ?></div>
        </div>

        <div class="box">
            <div style="margin-bottom:8px;">Databases (<?= count($dbFiles) ?>)</div>
            <ul class="db-list">
                <?php foreach ($dbFiles as $dbFile):
                    $isActive = ($selectedDb !== null && (string)$selectedDb['rel'] === (string)$dbFile['rel']);
                    $url = build_url(['db' => $dbFile['rel']]);
                    ?>
                    <li>
                        <a href="<?= e($url) ?>" class="<?= $isActive ? 'active' : '' ?>"><?= e($dbFile['rel']) ?></a>
                        <div class="muted" style="font-size:11px;">
                            <?= e((string)($dbFile['size'] !== false ? $dbFile['size'] : 0)) ?> bytes
                            <?php if (!empty($dbFile['mtime'])): ?>
                                | <?= e(date('Y-m-d H:i:s', (int)$dbFile['mtime'])) ?>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($dbFiles)): ?>
                    <li class="muted">No *.db files found.</li>
                <?php endif; ?>
            </ul>
        </div>
    </aside>

    <main class="right">
        <?php foreach ($errors as $err): ?>
            <div class="error"><?= e($err) ?></div>
        <?php endforeach; ?>

        <?php if ($selectedDb === null): ?>
            <h2 class="title">Select a database</h2>
            <p class="muted">Choose a file from the left panel.</p>
        <?php else: ?>
            <div class="box">
                <div><strong>Selected DB:</strong> <?= e($selectedDb['rel']) ?></div>
                <div class="muted" style="margin-top:4px;"><?= e($selectedDb['abs']) ?></div>
            </div>

            <div class="box">
                <h2 class="title">Tables</h2>
                <?php if (empty($tables)): ?>
                    <p class="muted">No tables found.</p>
                <?php else: ?>
                    <table>
                        <thead>
                        <tr>
                            <th>Table</th>
                            <th>Rows</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tables as $t):
                            $name = (string)$t['name'];
                            $rowCount = $t['row_count'];
                            $u = build_url(['db' => $selectedDb['rel'], 'table' => $name, 'page' => 1, 'per' => $per]);
                            ?>
                            <tr>
                                <td>
                                    <a href="<?= e($u) ?>" class="<?= ($selectedTable === $name ? 'active' : '') ?>"><?= e($name) ?></a>
                                </td>
                                <td><?= $rowCount === null ? 'n/a' : e((string)$rowCount) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php if ($selectedTable !== null): ?>
                <div class="box">
                    <h2 class="title">Schema: <?= e($selectedTable) ?></h2>
                    <pre><?= e($tableSchemaSql !== '' ? $tableSchemaSql : '(No CREATE SQL available)') ?></pre>

                    <?php if (!empty($tableColumns)): ?>
                        <div style="margin-top:10px;">
                            <table>
                                <thead>
                                <tr>
                                    <th>Column</th>
                                    <th>Type</th>
                                    <th>NOT NULL</th>
                                    <th>PK</th>
                                    <th>Default</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($tableColumns as $c): ?>
                                    <tr>
                                        <td><?= e($c['name']) ?></td>
                                        <td><?= e($c['type']) ?></td>
                                        <td><?= e((string)$c['notnull']) ?></td>
                                        <td><?= e((string)$c['pk']) ?></td>
                                        <td><?= e($c['dflt_value'] === null ? '' : $c['dflt_value']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="box">
                    <h2 class="title">Browse Rows</h2>

                    <form method="get" class="controls">
                        <input type="hidden" name="db" value="<?= e($selectedDb['rel']) ?>">
                        <input type="hidden" name="table" value="<?= e($selectedTable) ?>">

                        <label for="col">Column</label>
                        <select name="col" id="col">
                            <option value="">(none)</option>
                            <?php foreach ($tableColumns as $c):
                                $cn = (string)$c['name'];
                                ?>
                                <option value="<?= e($cn) ?>" <?= ($col === $cn ? 'selected' : '') ?>><?= e($cn) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label for="q">Search</label>
                        <input type="text" id="q" name="q" value="<?= e($q) ?>" placeholder="LIKE term">

                        <label for="per">Per page</label>
                        <select name="per" id="per">
                            <?php foreach ($allowedPer as $pp): ?>
                                <option value="<?= e((string)$pp) ?>" <?= ($per === $pp ? 'selected' : '') ?>><?= e((string)$pp) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit">Apply</button>
                    </form>

                    <div class="muted">Rows: <?= e((string)$totalRows) ?> | Page <?= e((string)$page) ?> / <?= e((string)$totalPages) ?></div>

                    <div class="pagination">
                        <?php
                        $baseParams = ['db' => $selectedDb['rel'], 'table' => $selectedTable, 'q' => $q, 'col' => $col, 'per' => $per];
                        if ($canPrev):
                            $prevParams = $baseParams;
                            $prevParams['page'] = $page - 1;
                            ?>
                            <a href="<?= e(build_url($prevParams)) ?>">&laquo; Prev</a>
                        <?php else: ?>
                            <span class="muted">&laquo; Prev</span>
                        <?php endif; ?>

                        <?php if ($canNext):
                            $nextParams = $baseParams;
                            $nextParams['page'] = $page + 1;
                            ?>
                            <a href="<?= e(build_url($nextParams)) ?>">Next &raquo;</a>
                        <?php else: ?>
                            <span class="muted">Next &raquo;</span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($rows)): ?>
                        <table>
                            <thead>
                            <tr>
                                <?php foreach (array_keys($rows[0]) as $colName): ?>
                                    <th><?= e($colName) ?></th>
                                <?php endforeach; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <?php foreach ($row as $value): ?>
                                        <td><?= e(is_scalar($value) || $value === null ? (string)$value : json_encode($value)) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="muted">No rows returned.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
