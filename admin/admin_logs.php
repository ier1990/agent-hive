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

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function safe_tail_lines($path, $lineCount) {
    $lineCount = max(1, (int)$lineCount);
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }

    $fp = @fopen($path, 'rb');
    if (!$fp) {
        return false;
    }

    $buffer = '';
    $chunkSize = 8192;
    $linesFound = 0;

    if (@fseek($fp, 0, SEEK_END) !== 0) {
        fclose($fp);
        return false;
    }

    $position = @ftell($fp);
    if ($position === false) {
        fclose($fp);
        return false;
    }

    while ($position > 0 && $linesFound <= $lineCount) {
        $read = min($chunkSize, $position);
        $position -= $read;
        if (@fseek($fp, $position, SEEK_SET) !== 0) {
            break;
        }
        $chunk = @fread($fp, $read);
        if ($chunk === false) {
            break;
        }
        $buffer = $chunk . $buffer;
        $linesFound = substr_count($buffer, "\n");
    }

    fclose($fp);

    $parts = preg_split("/\r\n|\n|\r/", $buffer);
    if (!is_array($parts)) {
        return false;
    }

    $tail = array_slice($parts, -$lineCount);
    return implode("\n", $tail);
}

function safe_log_line_count($path, $sizeBytes) {
    if (!is_file($path) || !is_readable($path)) {
        return ['value' => 'unreadable', 'note' => 'File is not readable yet'];
    }

    if ($sizeBytes > 5 * 1024 * 1024) {
        return ['value' => 'skipped', 'note' => 'Skipped line count for large log'];
    }

    $fp = @fopen($path, 'rb');
    if (!$fp) {
        return ['value' => 'unreadable', 'note' => 'Could not open file'];
    }

    $count = 0;
    while (!feof($fp)) {
        $line = fgets($fp);
        if ($line === false) {
            if (!feof($fp)) {
                fclose($fp);
                return ['value' => 'error', 'note' => 'Read error while counting lines'];
            }
            break;
        }
        $count++;
    }
    fclose($fp);

    return ['value' => $count, 'note' => ''];
}

// Handle view/tail actions
if ($action === 'view' && $file) {
    if (!file_exists($file_path)) {
        die("File not found");
    }
    if (!is_readable($file_path)) {
        die("File is not readable yet.");
    }
    
    header('Content-Type: text/plain');
    readfile($file_path);
    exit;
}

if ($action === 'tail' && $file) {
    if (!file_exists($file_path)) {
        die("File not found");
    }
    if (!is_readable($file_path)) {
        die("File is not readable yet.");
    }
    
    header('Content-Type: text/plain');
    $tail = safe_tail_lines($file_path, $lines);
    if ($tail === false) {
        echo "Unable to read tail for this file.\n";
    } else {
        echo $tail;
    }
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
        tr:nth-child(even) { background-color: #0f172a; }
        tr:hover { background-color: #172033; }
        a { color: #2196F3; text-decoration: none; margin-right: 10px; }
        a:hover { text-decoration: underline; }
        .actions { white-space: nowrap; }
        .path { color: #666; font-size: 0.9em; }
    </style>
    <link rel="stylesheet" href="lib/admin_dark.css">
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
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($log_files as $log_file): 
                    $full_path = $logs_path . DIRECTORY_SEPARATOR . $log_file;
                    $size = @filesize($full_path);
                    $mtime = @filemtime($full_path);
                    $size = ($size === false) ? 0 : $size;
                    $mtime = ($mtime === false) ? 0 : $mtime;
                    $line_meta = safe_log_line_count($full_path, $size);
                    $line_count = $line_meta['value'];
                    $status_note = $line_meta['note'];
                    $readable = is_readable($full_path);
                ?>
                <tr>
                    <td><strong><?php echo h($log_file); ?></strong></td>
                    <td><?php echo formatBytes($size); ?></td>
                    <td><?php echo $mtime > 0 ? date('Y-m-d H:i:s', $mtime) : 'unknown'; ?></td>
                    <td>
                        <?php echo is_int($line_count) ? number_format($line_count) : h($line_count); ?>
                    </td>
                    <td><?php echo $status_note !== '' ? h($status_note) : ($readable ? 'ok' : 'unreadable'); ?></td>
                    <td class="actions">
                        <?php if ($readable): ?>
                            <a href="?action=view&file=<?php echo urlencode($log_file); ?>" target="_blank">View Full</a>
                            <a href="?action=tail&file=<?php echo urlencode($log_file); ?>&lines=50" target="_blank">Tail 50</a>
                            <a href="?action=tail&file=<?php echo urlencode($log_file); ?>&lines=100" target="_blank">Tail 100</a>
                            <a href="?action=tail&file=<?php echo urlencode($log_file); ?>&lines=500" target="_blank">Tail 500</a>
                        <?php else: ?>
                            <span class="path">Waiting for readable perms</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    Bottom of file.
</body>
</html>
