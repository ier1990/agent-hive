#!/usr/bin/env php
<?php
/**
 * Cleanup script to remove CodeWalker actions with empty responses
 * This finds actions marked as 'ok' but have no corresponding result data or empty result data
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../lib/codewalker_settings.php';

$cfg = cw_settings_get_all();
$DB_PATH = $cfg['db_path'] ?? null;

if (!$DB_PATH || !is_string($DB_PATH) || !file_exists($DB_PATH)) {
    fwrite(STDERR, "Error: Cannot find codewalker database at: " . ($DB_PATH ?: 'NOT SET') . "\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: Cannot open database: " . $e->getMessage() . "\n");
    exit(1);
}

echo "CodeWalker Empty Response Cleanup\n";
echo "Database: $DB_PATH\n\n";

$totalDeleted = 0;

// Check each action type for empty results
$checks = [
    'summarize' => ['table' => 'summaries', 'column' => 'summary'],
    'rewrite' => ['table' => 'rewrites', 'column' => 'rewrite'],
    'audit' => ['table' => 'audits', 'column' => 'findings'],
    'test' => ['table' => 'tests', 'column' => 'strategy'],
    'docs' => ['table' => 'docs', 'column' => 'documentation'],
    'refactor' => ['table' => 'refactors', 'column' => 'suggestions'],
];

foreach ($checks as $action => $info) {
    $table = $info['table'];
    $column = $info['column'];
    
    // Find actions with no result record or empty result
    $sql = "SELECT a.id, a.action, f.path 
            FROM actions a 
            JOIN files f ON f.id = a.file_id
            LEFT JOIN $table r ON r.action_id = a.id
            WHERE a.action = ? AND a.status = 'ok' 
            AND (r.action_id IS NULL OR r.$column IS NULL OR TRIM(r.$column) = '')";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$action]);
    $rows = $stmt->fetchAll();
    
    if (count($rows) > 0) {
        echo "Found " . count($rows) . " empty $action actions:\n";
        
        foreach ($rows as $row) {
            echo "  - Action #{$row['id']}: {$row['path']}\n";
            
            // Delete from result table if exists
            $pdo->prepare("DELETE FROM $table WHERE action_id = ?")->execute([$row['id']]);
            
            // Delete action record
            $pdo->prepare("DELETE FROM actions WHERE id = ?")->execute([$row['id']]);
            
            $totalDeleted++;
        }
    } else {
        echo "No empty $action actions found.\n";
    }
}

// Also check for orphaned result records (result exists but no action)
echo "\nChecking for orphaned result records...\n";
foreach ($checks as $action => $info) {
    $table = $info['table'];
    
    $sql = "SELECT r.action_id 
            FROM $table r 
            LEFT JOIN actions a ON a.id = r.action_id
            WHERE a.id IS NULL";
    
    $stmt = $pdo->query($sql);
    $orphans = $stmt->fetchAll();
    
    if (count($orphans) > 0) {
        echo "Found " . count($orphans) . " orphaned records in $table:\n";
        foreach ($orphans as $row) {
            echo "  - Orphaned action_id: {$row['action_id']}\n";
            $pdo->prepare("DELETE FROM $table WHERE action_id = ?")->execute([$row['action_id']]);
        }
    }
}

echo "\n✅ Cleanup complete. Deleted $totalDeleted actions with empty responses.\n";
exit(0);
