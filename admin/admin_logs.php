<?php

// Display errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$logs_path = '/web/private/logs';

// Get action and file from query params
$action = $_GET['action'] ?? 'list';
$file = $_GET['file'] ?? '';
$lines = (int)($_GET['lines'] ?? 100);

// Security: prevent directory traversal
if (strpos($file, '..') !== false || strpos($file, '/') !== false) {
    die("Invalid file name");
}

$file_path = $logs_path . DIRECTORY_SEPARATOR . $file;

// Handle view/tail actions
if ($action === 'view' && $file) {
    if (!file_exists($file_path)) {
        die("File not found");
    }
    
    header('Content-Type: text/plain');
    readfile($file_path);
    exit;
}

if ($action === 'tail' && $file) {
    if (!file_exists($file_path)) {
        die("File not found");
    }
    
    header('Content-Type: text/plain');
    $content = file($file_path);
    $tail_lines = array_slice($content, -$lines);
    echo implode('', $tail_lines);
    exit;
}

// List log files
$log_files = [];
if (is_dir($logs_path)) {
    $files = scandir($logs_path);
    foreach ($files as $file) {
        if (is_file($logs_path . DIRECTORY_SEPARATOR . $file) && preg_match('/\.log$/i', $file)) {
            $log_files[] = $file;
        }
    }
    sort($log_files);
} else {
    die("Logs directory not found at $logs_path");
}

// Format file size
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Log Files Viewer</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background-color: #4CAF50; color: white; }
        tr:nth-child(even) { background-color: #f2f2f2; }
        tr:hover { background-color: #ddd; }
        a { color: #2196F3; text-decoration: none; margin-right: 10px; }
        a:hover { text-decoration: underline; }
        .actions { white-space: nowrap; }
        .path { color: #666; font-size: 0.9em; }
    </style>
</head>
<body>
    <h1>Log Files Viewer</h1>
    <p class="path">Directory: <strong><?php echo htmlspecialchars($logs_path); ?></strong></p>
    
    <?php if (empty($log_files)): ?>
        <p>No log files found.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>File Name</th>
                    <th>Size</th>
                    <th>Modified</th>
                    <th>Lines</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($log_files as $log_file): 
                    $full_path = $logs_path . DIRECTORY_SEPARATOR . $log_file;
                    $size = filesize($full_path);
                    $mtime = filemtime($full_path);
                    $line_count = count(file($full_path));
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($log_file); ?></strong></td>
                    <td><?php echo formatBytes($size); ?></td>
                    <td><?php echo date('Y-m-d H:i:s', $mtime); ?></td>
                    <td><?php echo number_format($line_count); ?></td>
                    <td class="actions">
                        <a href="?action=view&file=<?php echo urlencode($log_file); ?>" target="_blank">View Full</a>
                        <a href="?action=tail&file=<?php echo urlencode($log_file); ?>&lines=50" target="_blank">Tail 50</a>
                        <a href="?action=tail&file=<?php echo urlencode($log_file); ?>&lines=100" target="_blank">Tail 100</a>
                        <a href="?action=tail&file=<?php echo urlencode($log_file); ?>&lines=500" target="_blank">Tail 500</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>