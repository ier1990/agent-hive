#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/codewalker_runner.php';

function cw_cli_usage(): void
{
    $msg = "CodeWalker CLI (PHP)\n\n"
        . "Usage:\n"
        . "  php admin/codewalker_cli.php --action=summarize /abs/path/to/file.php\n"
        . "  php admin/codewalker_cli.php --action=rewrite /abs/path/to/file.php\n\n"
        . "Options:\n"
        . "  --action=auto|summarize|rewrite   Default: summarize\n"
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

if (!$paths) {
    cw_cli_usage();
    exit(2);
}

$exit = 0;
foreach ($paths as $p) {
    try {
        $res = cw_run_on_file($p, $action);
        $aid = isset($res['action_id']) ? (int)$res['action_id'] : 0;
        $st = isset($res['status']) ? (string)$res['status'] : 'unknown';
        $err = isset($res['error']) ? (string)$res['error'] : '';
        if ($st !== 'ok') {
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
