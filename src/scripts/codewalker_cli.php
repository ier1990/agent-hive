#!/usr/bin/env php
<?php
/**
 * CodeWalker CLI wrapper
 * This script forwards to the canonical implementation in /web/html/admin/
 * to avoid maintaining two copies
 */

declare(strict_types=1);

$mainScript = '/web/html/admin/codewalker_cli.php';
if (!is_file($mainScript)) {
    fwrite(STDERR, "Error: Main script not found: $mainScript\n");
    exit(1);
}

// Execute the main script with all arguments
require_once $mainScript;
