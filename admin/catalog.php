<?php
// /web/html/admin/catalog.php
// Catalog every file under scan_path into code_base.db (hourly).
// Deterministic, idempotent: DB converges to disk state.

declare(strict_types=1);

require_once __DIR__ . '/lib/codewalker_settings.php';

function iso(): string { return gmdate('c'); }
function now_int(): int { return time(); }

function ensure_dir(string $path): void {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
}

function pdo_sqlite(string $dbPath): PDO {
    ensure_dir($dbPath);
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec('PRAGMA busy_timeout=5000');
    return $pdo;
}

function init_schema(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        path TEXT NOT NULL UNIQUE,
        ext TEXT,
        size_bytes INTEGER,
        mtime INTEGER,
        sha256 TEXT,
        first_seen INTEGER,
        last_seen INTEGER,
        deleted_at INTEGER
    )');

    // Migration: add columns if they don't exist
    try {
        $cols = $pdo->query("PRAGMA table_info(files)")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'name');
        
        if (!in_array('sha256', $colNames, true)) {
            $pdo->exec('ALTER TABLE files ADD COLUMN sha256 TEXT');
        }
        if (!in_array('size_bytes', $colNames, true)) {
            $pdo->exec('ALTER TABLE files ADD COLUMN size_bytes INTEGER');
        }
        if (!in_array('mtime', $colNames, true)) {
            $pdo->exec('ALTER TABLE files ADD COLUMN mtime INTEGER');
        }
        if (!in_array('first_seen', $colNames, true)) {
            $pdo->exec('ALTER TABLE files ADD COLUMN first_seen INTEGER');
        }
        if (!in_array('deleted_at', $colNames, true)) {
            $pdo->exec('ALTER TABLE files ADD COLUMN deleted_at INTEGER');
        }
    } catch (Throwable $e) {
        // Ignore if columns already exist
    }

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_files_last_seen ON files(last_seen)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_files_sha256 ON files(sha256)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_files_deleted ON files(deleted_at)');

    // Optional for later (OFF by default; see cfg store_blobs)
    $pdo->exec('CREATE TABLE IF NOT EXISTS file_blobs (
        sha256 TEXT PRIMARY KEY,
        bytes INTEGER,
        created_at INTEGER,
        content BLOB
    )');
}

function sha256_file_fast(string $path): string {
    $h = @hash_file('sha256', $path);
    return is_string($h) ? $h : '';
}

function should_skip_dir(string $path, array $skipNames): bool {
    $base = basename($path);
    return in_array($base, $skipNames, true);
}

function normalize_ext(string $path): string {
    return strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
}

function catalog(PDO $pdo, array $cfg): array {
    $scanPath = rtrim((string)($cfg['scan_path'] ?? ''), '/');
    if ($scanPath === '' || !is_dir($scanPath)) {
        throw new RuntimeException('Missing/invalid scan_path');
    }

    // Extensions filter (optional). If empty -> allow all.
    $extensions = $cfg['file_types'] ?? $cfg['extensions'] ?? [];
    if (is_string($extensions)) {
        $extensions = array_values(array_filter(array_map('trim', explode(',', $extensions))));
    }
    if (!is_array($extensions)) $extensions = [];
    $extensions = array_map('strtolower', $extensions);

    // Hard excludes (boring defaults)
    $skipDirNames = $cfg['skip_dir_names'] ?? ['.git', 'vendor', '.b', '.admin', 'node_modules', '.cache', 'cache', 'tmp', 'logs'];
    if (is_string($skipDirNames)) {
        $skipDirNames = array_values(array_filter(array_map('trim', explode(',', $skipDirNames))));
    }
    if (!is_array($skipDirNames)) $skipDirNames = ['.git', 'vendor', 'node_modules', '.cache', 'cache', 'tmp', 'logs', '.b', '.admin'];

    // Optional: store blobs? default false
    $storeBlobs = (bool)($cfg['store_blobs'] ?? false);
    $maxBlobKb  = (int)($cfg['max_blob_kb'] ?? 256);
    $maxBlobBytes = max(1, $maxBlobKb) * 1024;

    $host = function_exists('gethostname') ? (string)gethostname() : php_uname('n');
    $started = now_int();

    $seenPaths = [];
    $scanned = 0;
    $updated = 0;
    $inserted = 0;
    $skipped = 0;
    $hashed = 0;

    $qSel = $pdo->prepare('SELECT id, size_bytes, mtime, sha256, deleted_at FROM files WHERE path=?');
    $qIns = $pdo->prepare('INSERT INTO files(path,ext,size_bytes,mtime,sha256,first_seen,last_seen,deleted_at)
                           VALUES(?,?,?,?,?,?,?,NULL)');
    $qUpd = $pdo->prepare('UPDATE files SET ext=?, size_bytes=?, mtime=?, sha256=?, last_seen=?, deleted_at=NULL WHERE id=?');
    $qTouch = $pdo->prepare('UPDATE files SET last_seen=?, deleted_at=NULL WHERE id=?');

    $qBlobSel = $pdo->prepare('SELECT sha256 FROM file_blobs WHERE sha256=?');
    $qBlobIns = $pdo->prepare('INSERT OR IGNORE INTO file_blobs(sha256,bytes,created_at,content) VALUES(?,?,?,?)');

    // Walk
    $dirIter = new RecursiveDirectoryIterator($scanPath, RecursiveDirectoryIterator::SKIP_DOTS);
    $iter = new RecursiveIteratorIterator($dirIter, RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iter as $f) {
        /** @var SplFileInfo $f */
        $path = $f->getPathname();

        if ($f->isDir()) {
            if (should_skip_dir($path, $skipDirNames)) {
                // Skip entire subtree
                $iter->next();
            }
            continue;
        }
        if (!$f->isFile()) continue;

        // If parent dir is excluded by name, skip quickly
        foreach ($skipDirNames as $sd) {
            if (strpos($path, DIRECTORY_SEPARATOR . $sd . DIRECTORY_SEPARATOR) !== false) {
                $skipped++;
                continue 2;
            }
        }

        $ext = normalize_ext($path);
        if (!empty($extensions) && !in_array($ext, $extensions, true)) {
            $skipped++;
            continue;
        }

        $size = $f->getSize();
        $mtime = $f->getMTime();
        $scanned++;

        $seenPaths[] = $path;

        // Load existing record
        $qSel->execute([$path]);
        $row = $qSel->fetch();

        if (!$row) {
            $sha = sha256_file_fast($path);
            if ($sha === '') { $skipped++; continue; }
            $hashed++;

            $qIns->execute([$path, $ext, (int)$size, (int)$mtime, $sha, $started, $started]);
            $inserted++;

            if ($storeBlobs) {
                $qBlobSel->execute([$sha]);
                if (!$qBlobSel->fetch()) {
                    $content = @file_get_contents($path, false, null, 0, $maxBlobBytes);
                    if (!is_string($content)) $content = '';
                    $qBlobIns->execute([$sha, (int)strlen($content), $started, $content]);
                }
            }
            continue;
        }

        $id = (int)$row['id'];
        $oldSize = (int)($row['size_bytes'] ?? 0);
        $oldMtime = (int)($row['mtime'] ?? 0);
        $oldSha = (string)($row['sha256'] ?? '');

        // If unchanged: just touch last_seen
        if ($oldSize === (int)$size && $oldMtime === (int)$mtime && $oldSha !== '') {
            $qTouch->execute([$started, $id]);
            continue;
        }

        // Changed: rehash and update
        $sha = sha256_file_fast($path);
        if ($sha === '') { $skipped++; continue; }
        $hashed++;

        $qUpd->execute([$ext, (int)$size, (int)$mtime, $sha, $started, $id]);
        $updated++;

        if ($storeBlobs) {
            $qBlobSel->execute([$sha]);
            if (!$qBlobSel->fetch()) {
                $content = @file_get_contents($path, false, null, 0, $maxBlobBytes);
                if (!is_string($content)) $content = '';
                $qBlobIns->execute([$sha, (int)strlen($content), $started, $content]);
            }
        }
    }

    // Soft-delete anything not seen this run
    // We use last_seen != started to detect unseen (simple + fast)
    $del = $pdo->prepare('UPDATE files SET deleted_at=? WHERE (deleted_at IS NULL) AND last_seen < ?');
    $del->execute([$started, $started]);
    $deleted = $del->rowCount();

    $finished = now_int();

    return [
        'host' => $host,
        'scan_path' => $scanPath,
        'extensions_filter' => $extensions,
        'store_blobs' => $storeBlobs,
        'max_blob_kb' => $maxBlobKb,
        'started_at' => $started,
        'finished_at' => $finished,
        'duration_sec' => ($finished - $started),
        'scanned' => $scanned,
        'inserted' => $inserted,
        'updated' => $updated,
        'hashed' => $hashed,
        'skipped' => $skipped,
        'deleted_marked' => $deleted,
    ];
}

function parse_args(array $argv): array {
    $out = [];
    foreach ($argv as $i => $arg) {
        if ($i === 0) continue;
        if (strpos($arg, '--') !== 0) continue;
        $eq = strpos($arg, '=');
        if ($eq === false) {
            $key = substr($arg, 2);
            $out[$key] = true;
        } else {
            $key = substr($arg, 2, $eq - 2);
            $val = substr($arg, $eq + 1);
            $out[$key] = $val;
        }
    }
    return $out;
}

// -------- main --------
$args = parse_args($argv);
$cfg = cw_settings_get_all();

// Allow override from CLI if you want
if (isset($args['scan_path'])) $cfg['scan_path'] = (string)$args['scan_path'];
if (isset($args['store_blobs'])) $cfg['store_blobs'] = in_array((string)$args['store_blobs'], ['1','true','yes'], true);

// Fixed catalog database location (separate from codewalker actions DB)
$dbPath = '/web/private/db/memory/code_base.db';

$pdo = pdo_sqlite($dbPath);
init_schema($pdo);

try {
    $pdo->beginTransaction();
    $result = catalog($pdo, $cfg);
    $pdo->commit();

    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "[catalog:error] " . $e->getMessage() . "\n");
    exit(1);
}
