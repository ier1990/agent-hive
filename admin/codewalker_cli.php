#!/usr/bin/env php
<?php


declare(strict_types=1);

// Load core bootstrap for env() and other helpers
$bootstrap = __DIR__ . '/../lib/bootstrap.php';
if (!is_file($bootstrap)) {
    $bootstrap = '/web/html/lib/bootstrap.php';
}
require_once $bootstrap;

$runner = __DIR__ . '/lib/codewalker_runner.php';
if (!is_file($runner)) {
    $runner = '/web/html/admin/lib/codewalker_runner.php';
}
require_once $runner;

function cw_cli_usage(): void
{
    $msg = "CodeWalker CLI (PHP)\n\n"
        . "Usage:\n"
        . "  php admin/codewalker_cli.php --action=summarize /abs/path/to/file.php\n"
        . "  php admin/codewalker_cli.php --action=auto /abs/path/to/file.php\n\n"
        . "Options:\n"
        . "  --action=auto|summarize|rewrite|audit|test|docs|refactor   Default: summarize\n"
        . "\nActions:\n"
        . "  summarize  - Generate code summary\n"
        . "  rewrite    - Refactor code with diffs\n"
        . "  audit      - Security/vulnerability analysis\n"
        . "  test       - Test coverage & strategy\n"
        . "  docs       - Generate documentation\n"
        . "  refactor   - Refactoring suggestions\n"
        . "  auto       - Pick random action based on percentages\n"
        . "\nNotes:\n"
        . "  - Reads config + prompts from /web/private/db/codewalker_settings.db\n"
        . "  - Writes results into the configured codewalker.db (db_path setting)\n";

    fwrite(STDERR, $msg);
}

$opts = getopt('', ['action::', 'help::']);
if (isset($opts['help'])) {
    cw_cli_usage();
    exit(0);
}

$action = isset($opts['action']) ? (string)$opts['action'] : 'summarize';

// Collect positional args as paths
$paths = [];
foreach ($argv as $i => $a) {
    if ($i === 0) continue;
    if (strpos($a, '--') === 0) continue;
    $paths[] = $a;
}

// If no paths provided, auto-scan from config
if (!$paths) {
    $cfg = cw_settings_get_all();
    $mode = strtolower(trim((string)($cfg['mode'] ?? 'cron')));
    $scanPath = rtrim((string)($cfg['scan_path'] ?? ''), '/');
    $fileTypes = (array)($cfg['file_types'] ?? ['php', 'py', 'sh', 'log']);
    $excludeDirs = (array)($cfg['exclude_dirs'] ?? []);
    $limit = (int)($cfg['limit_per_run'] ?? 5);
    $dbPath = (string)($cfg['db_path'] ?? '');
    
    // Load queued files first (used in both modes)
    $queuedPaths = [];
    if ($dbPath !== '' && is_file($dbPath)) {
        try {
            $pdo = cw_cwdb_pdo($dbPath);
            $stmt = $pdo->query("SELECT path FROM queued_files WHERE status='pending' ORDER BY id ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (isset($row['path']) && is_file($row['path'])) {
                    $queuedPaths[] = $row['path'];
                }
            }
        } catch (Throwable $e) {
            // Queued files table may not exist yet, continue
        }
    }
    
    // mode=que: only run queued work; if nothing queued, exit early (pause behavior)
    if ($mode === 'que' || $mode === 'queue') {
        if (empty($queuedPaths)) {
            fwrite(STDOUT, "Mode=que: no pending queued files; exiting.\n");
            exit(0);
        }
        $paths = array_slice($queuedPaths, 0, $limit);
        fwrite(STDOUT, "Mode=que: processing " . count($paths) . " queued files\n");
    } else {
        // mode=cron (default): scan filesystem and prioritize queued work first
        if ($scanPath === '' || !is_dir($scanPath)) {
            fwrite(STDERR, "No scan_path configured or not a directory: $scanPath\n");
            exit(2);
        }
        
        fwrite(STDOUT, "Mode=cron: scanning $scanPath (limit: $limit, types: " . implode(',', $fileTypes) . ")\n");
        
        // Collect all matching files (not just first N)
        $candidates = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scanPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $path = $file->getPathname();
            
            // Check excludes
            $skip = false;
            foreach ($excludeDirs as $excl) {
                $excl = trim((string)$excl, '/');
                if ($excl !== '' && strpos($path, '/' . $excl . '/') !== false) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;
            
            // Check file type
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, $fileTypes, true)) continue;
            
            $candidates[] = $path;
        }
        
        if (empty($candidates) && empty($queuedPaths)) {
            fwrite(STDOUT, "No files found to process.\n");
            exit(0);
        }
        
        // Prioritize queued files first, then add scanned files
        $seen = [];
        $prioritized = [];
        foreach ($queuedPaths as $p) {
            $prioritized[] = $p;
            $seen[realpath($p) ?: $p] = true;
        }
        
        // Randomize scanned files and add unseen ones
        shuffle($candidates);
        foreach ($candidates as $p) {
            $rp = realpath($p) ?: $p;
            if (!isset($seen[$rp])) {
                $prioritized[] = $p;
            }
        }
        
        $paths = array_slice($prioritized, 0, $limit);
        fwrite(STDOUT, "Found " . count($paths) . " files to process (" . count($queuedPaths) . " queued)\n");
    }
}

$exit = 0;
foreach ($paths as $p) {
    try {
        $res = cw_run_on_file($p, $action);
        $aid = isset($res['action_id']) ? (int)$res['action_id'] : 0;
        $st = isset($res['status']) ? (string)$res['status'] : 'unknown';
        $err = isset($res['error']) ? (string)$res['error'] : '';
        
        if ($st === 'skip') {
            // Skipped file (already processed) - not an error, just informational
            fwrite(STDOUT, "[skip] $p\n");
        } elseif ($st !== 'ok') {
            $exit = 1;
            fwrite(STDERR, "[$st] $p (action_id=$aid) $err\n");
        } else {
            fwrite(STDOUT, "[ok] $p (action_id=$aid)\n");
        }
    } catch (Throwable $e) {
        $exit = 1;
        fwrite(STDERR, "[error] $p: " . $e->getMessage() . "\n");
    }
}

exit($exit);
