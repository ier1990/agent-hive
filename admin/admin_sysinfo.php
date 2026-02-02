<?php
// System Info Dashboard - Infrastructure Monitoring
// Fetches and displays system monitoring data from remote hosts
// 
// Expected Environment Variables:
//   IER_API_KEY - API key for accessing the inbox endpoint
//
// Sender script parameters (for reference):
//   API: /v1/inbox endpoint
//   DB: sysinfo_new (logical database name)
//   SERVICE: daily_sysinfo (table name)

// Determine base URL for API
$url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$API_BASE = $url . '/v1/';

// Use the logical DB name used by senders (inbox.php resolves this)
// DB path: /web/private/db/inbox/sysinfo_new.db
$DB_NAME = 'sysinfo_new';
// Table name used by the sysinfo sender script (from SERVICE)
$TABLE_NAME = 'daily_sysinfo';

// Load .env /web/private/.env
$dotenvPath = '/web/private/.env';
if (file_exists($dotenvPath)) {
    $lines = file($dotenvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue; // skip comments
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$API_KEY = $_ENV['IER_API_KEY'] ?? '';

if (empty($API_KEY)) {
    http_response_code(500);
    echo "Server misconfiguration: API key not set.";
    exit;
}

// Helper function to make API requests
function apiRequest($endpoint, $params = []) {
    global $API_BASE, $API_KEY;
    
    $url = $API_BASE . $endpoint;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    
    $headers = ['Content-Type: application/json'];
    if (!empty($API_KEY)) {
        $headers[] = 'X-API-Key: ' . $API_KEY;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);  // Follow redirects
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);           // Max 5 redirects
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200 && $response !== false) {
        return json_decode($response, true);
    }
    
    // Debug: log error details
    error_log("API Request failed: URL=$url, HTTP=$httpCode, Error=$curlError");
    
    return null;
}

// Get filter parameters
$limit = (int)($_GET['limit'] ?? 50);
$host_filter = $_GET['host'] ?? '';
$hours_back = (int)($_GET['hours'] ?? 0);
$debug_mode = isset($_GET['debug']); // Add debug mode to bypass filters

// Build receiving query parameters (server-side filter + order)
$params = [
    'db'    => $DB_NAME,
    'table' => $TABLE_NAME,
    'limit' => min($limit, 500), // Cap at 500 for performance
    'order' => 'ts',             // sort by the sender's timestamp
    'desc'  => '1',              // newest first
];

// Host filter (server-side via f_<column>)
if (!empty($host_filter)) {
    $params['f_host'] = $host_filter;
}

// Fetch rows from existing API (/v1/inbox)
$resp = apiRequest('/inbox', $params);
$rows = is_array($resp) ? ($resp['rows'] ?? null) : null;
if (!is_array($rows)) { $rows = []; }

// Debug: Store original count before filtering
$original_row_count = count($rows);
$filter_debug = [];
$time_filter_bypassed = false;

// Client-side time filter (inbox.php doesn't support 'since' on GET)
// Skip filtering in debug mode
if ($hours_back > 0 && !empty($rows) && !$debug_mode) {
    $cutoff = strtotime("-{$hours_back} hours");
    $filtered_rows = array_values(array_filter($rows, function($r) use ($cutoff, &$filter_debug) {
        $ts = isset($r['ts']) ? (string)$r['ts'] : '';
        if ($ts === '') {
            $filter_debug[] = ['ts' => 'EMPTY', 'reason' => 'No timestamp'];
            return false;
        }
        
        // Try to parse timestamp - could be ISO8601, unix timestamp, or other format
        $ts_unix = is_numeric($ts) ? (int)$ts : strtotime($ts);
        
        if ($ts_unix === false || $ts_unix === -1) {
            $filter_debug[] = ['ts' => $ts, 'reason' => 'Failed to parse', 'cutoff' => $cutoff];
            return false;
        }
        
        $passed = $ts_unix >= $cutoff;
        if (!$passed && count($filter_debug) < 5) {
            $filter_debug[] = [
                'ts' => $ts, 
                'ts_unix' => $ts_unix, 
                'ts_readable' => date('Y-m-d H:i:s', $ts_unix),
                'cutoff' => $cutoff,
                'cutoff_readable' => date('Y-m-d H:i:s', $cutoff),
                'reason' => 'Too old'
            ];
        }
        
        return $passed;
    }));
    
    // If time filter removed all rows, show all data anyway (don't hide everything)
    if (empty($filtered_rows) && !empty($rows)) {
        $time_filter_bypassed = true;
        $filter_debug[] = ['notice' => 'Time filter removed all rows - showing all available data instead'];
    } else {
        $rows = $filtered_rows;
    }
} elseif ($debug_mode) {
    $filter_debug[] = ['notice' => 'DEBUG MODE: Time filtering disabled'];
}

// Sort again by ts desc to be safe after client-side filtering
usort($rows, function($a, $b) {
    return strcmp((string)($b['ts'] ?? ''), (string)($a['ts'] ?? ''));
});

// Derive hosts list from recent data (no separate API needed)
$hosts = [];
foreach ($rows as $r) {
    if (!empty($r['host'])) { $hosts[] = $r['host']; }
}
$hosts = array_values(array_unique($hosts));

// Helper functions
function formatBytes($size) {
    if ($size === 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($size) / log(1024));
    return round($size / pow(1024, $i), 2) . ' ' . $units[$i];
}

function formatUptime($seconds) {
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    
    if ($days > 0) {
        return "{$days}d {$hours}h {$minutes}m";
    } elseif ($hours > 0) {
        return "{$hours}h {$minutes}m";
    } else {
        return "{$minutes}m";
    }
}

function getStatusColor($uptime_seconds) {
    if ($uptime_seconds < 300) return 'bg-red-500'; // Less than 5 minutes
    if ($uptime_seconds < 3600) return 'bg-yellow-500'; // Less than 1 hour
    return 'bg-green-500'; // Stable
}

function parseMemory($mem_string) {
    if (preg_match('/(\d+)MB used \/ (\d+)MB total/', $mem_string, $matches)) {
        $used = (int)$matches[1];
        $total = (int)$matches[2];
        $percentage = $total > 0 ? ($used / $total) * 100 : 0;
        return ['used' => $used, 'total' => $total, 'percentage' => $percentage];
    }
    return ['used' => 0, 'total' => 0, 'percentage' => 0];
}

function parseDisk($disk_string) {
    if (preg_match('/[\d.]+[KMGT]? used \/ ([\d.]+[KMGT]?) total \((\d+)% used\)/', $disk_string, $matches)) {
        return ['total' => $matches[1], 'percentage' => (int)$matches[2]];
    }
    return ['total' => 'Unknown', 'percentage' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Info Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        .gradient-bg { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .card-hover { transition: all 0.3s ease; }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .progress-bar { transition: width 0.3s ease; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="gradient-bg text-white py-6 mb-8">
        <div class="container mx-auto px-4">
            <h1 class="text-3xl font-bold mb-2">🖥️ System Info Dashboard</h1>
            <p class="opacity-90">Real-time monitoring of your infrastructure</p>
        </div>
    </div>

    <div class="container mx-auto px-4" x-data="dashboard()">
        <!-- Filters -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-semibold mb-4">Filters & Controls</h2>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Host Filter</label>
                    <select name="host" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">All Hosts</option>
                        <?php foreach($hosts as $host): ?>
                            <option value="<?= htmlspecialchars($host) ?>" <?= $host_filter === $host ? 'selected' : '' ?>>
                                <?= htmlspecialchars(explode(':', $host)[1] ?? $host) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Time Range</label>
                    <select name="hours" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="0" <?= $hours_back === 0 ? 'selected' : '' ?>>All Time (No Filter)</option>
                        <option value="1" <?= $hours_back === 1 ? 'selected' : '' ?>>Last Hour</option>
                        <option value="6" <?= $hours_back === 6 ? 'selected' : '' ?>>Last 6 Hours</option>
                        <option value="24" <?= $hours_back === 24 ? 'selected' : '' ?>>Last 24 Hours</option>
                        <option value="168" <?= $hours_back === 168 ? 'selected' : '' ?>>Last Week</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Limit</label>
                    <select name="limit" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="25" <?= $limit === 25 ? 'selected' : '' ?>>25 Records</option>
                        <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50 Records</option>
                        <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100 Records</option>
                        <option value="200" <?= $limit === 200 ? 'selected' : '' ?>>200 Records</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        Apply Filters
                    </button>
                </div>
            </form>
        </div>

    <?php if (empty($rows)): ?>
            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-md mb-8">
                <h3 class="font-semibold text-lg mb-3">⚠️ No Data Found</h3>
                <p class="mb-4">No system information data was found for the current filters. Try adjusting your search parameters.</p>
                
                <!-- Debug Information -->
                <div class="bg-white border border-yellow-300 rounded-md p-4 mt-4 text-sm">
                    <h4 class="font-semibold text-gray-800 mb-2">🔍 Debug Information:</h4>
                    <div class="space-y-2 font-mono text-xs">
                        <div><strong>API Base URL:</strong> <?= htmlspecialchars($API_BASE) ?></div>
                        <div><strong>Database:</strong> <?= htmlspecialchars($DB_NAME) ?></div>
                        <div><strong>Table:</strong> <?= htmlspecialchars($TABLE_NAME) ?></div>
                        <div><strong>API Key Set:</strong> <?= !empty($API_KEY) ? '✓ Yes' : '✗ No' ?></div>
                        <div class="border-t pt-2 mt-2">
                            <strong>Request Parameters:</strong>
                            <pre class="bg-gray-100 p-2 rounded mt-1 overflow-x-auto"><?= htmlspecialchars(json_encode($params, JSON_PRETTY_PRINT)) ?></pre>
                        </div>
                        <div class="border-t pt-2 mt-2">
                            <strong>API Response:</strong>
                            <pre class="bg-gray-100 p-2 rounded mt-1 overflow-x-auto"><?= htmlspecialchars(json_encode($resp, JSON_PRETTY_PRINT) ?: 'NULL or Invalid Response') ?></pre>
                        </div>
                        <div class="border-t pt-2 mt-2">
                            <strong>Full API URL:</strong>
                            <?php 
                            $debug_url = $API_BASE . 'inbox?' . http_build_query($params);
                            ?>
                            <div class="bg-gray-100 p-2 rounded mt-1 break-all"><?= htmlspecialchars($debug_url) ?></div>
                        </div>
                        <div class="border-t pt-2 mt-2">
                            <strong>Applied Filters:</strong>
                            <ul class="list-disc list-inside mt-1">
                                <li>Host Filter: <?= !empty($host_filter) ? htmlspecialchars($host_filter) : 'None (All Hosts)' ?></li>
                                <li>Time Range: <?= $debug_mode ? '<span class="text-red-600 font-bold">DISABLED (Debug Mode)</span>' : ($hours_back > 0 ? "Last {$hours_back} hours" : "All Time") ?> <?= $hours_back > 0 ? "(cutoff: " . date('Y-m-d H:i:s', strtotime("-{$hours_back} hours")) . ")" : "" ?></li>
                                <li>Limit: <?= $limit ?> records</li>
                            </ul>
                        </div>
                        <?php if (!empty($filter_debug)): ?>
                        <div class="border-t pt-2 mt-2">
                            <strong>Time Filter Debug (first 5 filtered items):</strong>
                            <pre class="bg-gray-100 p-2 rounded mt-1 overflow-x-auto text-xs"><?= htmlspecialchars(json_encode($filter_debug, JSON_PRETTY_PRINT)) ?></pre>
                        </div>
                        <?php endif; ?>
                        <div class="border-t pt-2 mt-2">
                            <strong>Server Timezone:</strong> <?= date_default_timezone_get() ?> | 
                            <strong>Current Server Time:</strong> <?= date('Y-m-d H:i:s T') ?>
                        </div>
                        <?php if (isset($resp['rows']) && is_array($resp['rows'])): ?>
                        <div class="border-t pt-2 mt-2">
                            <strong>Raw API Response Count:</strong> <?= $original_row_count ?? 0 ?> rows received from API
                            <?php if (($original_row_count ?? 0) > 0 && empty($rows)): ?>
                                <div class="text-red-600 mt-2 p-2 bg-red-50 rounded">
                                    ⚠️ <strong>All <?= $original_row_count ?> rows were filtered out by time range!</strong>
                                    <div class="mt-2 text-xs">
                                        Sample timestamps from API:
                                        <ul class="list-disc list-inside mt-1">
                                        <?php foreach (array_slice($resp['rows'], 0, 3) as $idx => $row): ?>
                                            <li>Row <?= $idx + 1 ?>: <code><?= htmlspecialchars($row['ts'] ?? 'NO TS') ?></code>
                                                <?php 
                                                if (isset($row['ts'])) {
                                                    $ts_val = $row['ts'];
                                                    $parsed = is_numeric($ts_val) ? (int)$ts_val : strtotime($ts_val);
                                                    if ($parsed) {
                                                        echo ' → ' . date('Y-m-d H:i:s', $parsed);
                                                    }
                                                }
                                                ?>
                                            </li>
                                        <?php endforeach; ?>
                                        </ul>
                                        <div class="mt-2">Cutoff time: <?= date('Y-m-d H:i:s', strtotime("-{$hours_back} hours")) ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="border-t pt-2 mt-2">
                            <strong>Database File Path:</strong>
                            <code>/web/private/db/inbox/<?= htmlspecialchars($DB_NAME) ?>.db</code>
                            <div class="mt-1">
                                File exists: <?= file_exists("/web/private/db/inbox/{$DB_NAME}.db") ? '✓ Yes' : '✗ No' ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4 pt-3 border-t border-yellow-300">
                        <h5 class="font-semibold text-gray-800 mb-2">💡 Troubleshooting Steps:</h5>
                        <ol class="list-decimal list-inside space-y-1 text-xs">
                            <li><strong class="text-blue-600">Try Debug Mode:</strong> <a href="?debug=1" class="underline text-blue-600">Click here to view ALL data (bypasses time filter)</a></li>
                            <li>Verify the sender script is running and sending data</li>
                            <li>Check if the database file exists at the path shown above</li>
                            <li>Try expanding the time range to "Last Week"</li>
                            <li>Remove the host filter to see all hosts</li>
                            <li>Check PHP error logs: <code>tail -f /var/log/apache2/error.log</code></li>
                            <li>Test API directly: <code>curl -H "X-API-Key: YOUR_KEY" "<?= htmlspecialchars($debug_url) ?>"</code></li>
                        </ol>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Time Filter Notice -->
            <?php if ($time_filter_bypassed): ?>
            <div class="bg-blue-100 border border-blue-400 text-blue-800 px-4 py-3 rounded-md mb-6">
                <div class="flex items-center">
                    <span class="text-2xl mr-3">ℹ️</span>
                    <div>
                        <h3 class="font-semibold">Showing All Available Data</h3>
                        <p class="text-sm">No records found within the last <?= $hours_back ?> hours. Displaying all <?= count($rows) ?> records regardless of age.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Summary Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow-md p-6 card-hover">
                    <div class="flex items-center">
                        <div class="p-2 bg-blue-100 rounded-lg">
                            <span class="text-2xl">📊</span>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-semibold text-gray-700">Total Records</h3>
                            <p class="text-2xl font-bold text-blue-600"><?= count($rows) ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow-md p-6 card-hover">
                    <div class="flex items-center">
                        <div class="p-2 bg-green-100 rounded-lg">
                            <span class="text-2xl">🖥️</span>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-semibold text-gray-700">Unique Hosts</h3>
                            <p class="text-2xl font-bold text-green-600"><?= count($hosts) ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow-md p-6 card-hover">
                    <div class="flex items-center">
                        <div class="p-2 bg-purple-100 rounded-lg">
                            <span class="text-2xl">⏱️</span>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-semibold text-gray-700">Latest Update</h3>
                            <p class="text-sm font-semibold text-purple-600">
                                <?= !empty($rows) ? date('M j, Y H:i', strtotime($rows[0]['ts'])) : 'N/A' ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- System Info Cards -->
            <div class="space-y-6">
                <?php foreach($rows as $record): 
                    // Decode JSON-encoded columns from inbox.php
                    $identity = $record['identity'] ?? [];
                    if (is_string($identity)) { $identity = json_decode($identity, true) ?: []; }
                    $sysinfo = $record['sysinfo'] ?? [];
                    if (is_string($sysinfo)) { $sysinfo = json_decode($sysinfo, true) ?: []; }
                    $docker = $record['docker'] ?? null;
                    if (is_string($docker)) { $docker = json_decode($docker, true) ?: null; }
                    $gpu = $record['gpu'] ?? null;
                    if (is_string($gpu)) { $gpu = json_decode($gpu, true) ?: null; }
                    $ollama = $record['ollama'] ?? null;
                    if (is_string($ollama)) { $ollama = json_decode($ollama, true) ?: null; }
                    
                    $mem_info = parseMemory($sysinfo['memory'] ?? '');
                    $disk_info = parseDisk($sysinfo['disk'] ?? '');
                    $uptime_seconds = (int)($sysinfo['uptime_seconds'] ?? 0);
                ?>
                <div class="bg-white rounded-lg shadow-md p-6 card-hover">
                    <!-- Header -->
                    <div class="flex justify-between items-start mb-6">
                        <div class="flex items-center">
                            <div class="w-3 h-3 rounded-full <?= getStatusColor($uptime_seconds) ?> mr-3"></div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-800">
                                    <?= htmlspecialchars($identity['hostname'] ?? 'Unknown Host') ?>
                                </h3>
                                <p class="text-sm text-gray-500">
                                    IP: <?= htmlspecialchars($identity['primary_ip'] ?? 'Unknown') ?> | 
                                    Updated: <?= date('M j, Y H:i:s', strtotime($record['ts'])) ?>
                                </p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-500">Public IP</p>
                            <p class="font-semibold"><?= htmlspecialchars($sysinfo['public_ip'] ?? 'Unknown') ?></p>
                        </div>
                    </div>

                    <!-- System Overview -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                        <!-- Uptime -->
                        <div class="text-center">
                            <p class="text-2xl mb-1">⏰</p>
                            <p class="text-sm text-gray-500">Uptime</p>
                            <p class="font-semibold"><?= htmlspecialchars($sysinfo['uptime'] ?? 'Unknown') ?></p>
                        </div>
                        
                        <!-- Load -->
                        <div class="text-center">
                            <p class="text-2xl mb-1">⚡</p>
                            <p class="text-sm text-gray-500">Load Average</p>
                            <p class="font-semibold"><?= htmlspecialchars($sysinfo['load'] ?? 'Unknown') ?></p>
                        </div>
                        
                        <!-- CPU -->
                        <div class="text-center">
                            <p class="text-2xl mb-1">💻</p>
                            <p class="text-sm text-gray-500">CPU Cores</p>
                            <p class="font-semibold"><?= htmlspecialchars($sysinfo['cpu_count'] ?? 'Unknown') ?></p>
                        </div>
                        
                        <!-- OS -->
                        <div class="text-center">
                            <p class="text-2xl mb-1">🐧</p>
                            <p class="text-sm text-gray-500">OS</p>
                            <p class="font-semibold text-xs"><?= htmlspecialchars($sysinfo['os'] ?? 'Unknown') ?></p>
                        </div>
                    </div>

                    <!-- Resource Usage -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <!-- Memory -->
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-sm font-medium text-gray-700">Memory Usage</span>
                                <span class="text-sm text-gray-500"><?= number_format($mem_info['percentage'], 1) ?>%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="progress-bar h-2 rounded-full <?= $mem_info['percentage'] > 80 ? 'bg-red-500' : ($mem_info['percentage'] > 60 ? 'bg-yellow-500' : 'bg-green-500') ?>" 
                                     style="width: <?= $mem_info['percentage'] ?>%"></div>
                            </div>
                            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($sysinfo['memory'] ?? 'Unknown') ?></p>
                        </div>
                        
                        <!-- Disk -->
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-sm font-medium text-gray-700">Disk Usage</span>
                                <span class="text-sm text-gray-500"><?= $disk_info['percentage'] ?>%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="progress-bar h-2 rounded-full <?= $disk_info['percentage'] > 80 ? 'bg-red-500' : ($disk_info['percentage'] > 60 ? 'bg-yellow-500' : 'bg-green-500') ?>" 
                                     style="width: <?php echo $disk_info['percentage'] ?>%"></div>
                            </div>
                            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($sysinfo['disk'] ?? 'Unknown') ?></p>
                        </div>
                    </div>

                    <!-- Additional Services -->
                    <?php if ($docker || $gpu || $ollama): ?>
                    <div class="border-t pt-4">
                        <h4 class="font-semibold text-gray-700 mb-3">Additional Services</h4>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <?php if ($docker): ?>
                            <div class="bg-blue-50 rounded-lg p-3">
                                <div class="flex items-center mb-2">
                                    <span class="text-lg mr-2">🐳</span>
                                    <span class="font-semibold text-blue-700">Docker</span>
                                </div>
                                <p class="text-sm text-blue-600">
                                    <?= $docker['containers_running'] ?? 0 ?>/<?= $docker['containers_total'] ?? 0 ?> containers running
                                </p>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($gpu): ?>
                            <div class="bg-green-50 rounded-lg p-3">
                                <div class="flex items-center mb-2">
                                    <span class="text-lg mr-2">🎮</span>
                                    <span class="font-semibold text-green-700">GPU</span>
                                </div>
                                <p class="text-xs text-green-600 mb-1"><?= htmlspecialchars($gpu['name'] ?? 'Unknown') ?></p>
                                <p class="text-sm text-green-600">
                                    <?= $gpu['util_pct'] ?? 0 ?>% util | <?= $gpu['temp_c'] ?? 'N/A' ?>°C
                                </p>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($ollama): ?>
                            <div class="bg-purple-50 rounded-lg p-3">
                                <div class="flex items-center mb-2">
                                    <span class="text-lg mr-2">🤖</span>
                                    <span class="font-semibold text-purple-700">Ollama</span>
                                </div>
                                <p class="text-sm text-purple-600">
                                    v<?= htmlspecialchars($ollama['version'] ?? 'Unknown') ?>
                                </p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- System Details (Collapsible) -->
                    <div class="border-t pt-4 mt-4" x-data="{ open: false }">
                        <button @click="open = !open" class="flex items-center justify-between w-full text-left">
                            <span class="font-semibold text-gray-700">System Details</span>
                            <span class="transform transition-transform" :class="{'rotate-180': open}">▼</span>
                        </button>
                        <div x-show="open" x-transition class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div><strong>CPU Model:</strong> <?= htmlspecialchars($sysinfo['cpu'] ?? 'Unknown') ?></div>
                            <div><strong>Kernel:</strong> <?= htmlspecialchars($sysinfo['kernel'] ?? 'Unknown') ?></div>
                            <div><strong>PHP Version:</strong> <?= htmlspecialchars($sysinfo['php_version'] ?? 'N/A') ?></div>
                            <div><strong>Machine ID:</strong> <?= htmlspecialchars($identity['machine'] ?? 'Unknown') ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Auto-refresh -->
        <div class="mt-8 text-center">
            <button @click="location.reload()" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                🔄 Refresh Data
            </button>
        </div>
    </div>

    <script>
        function dashboard() {
            return {
                // Add any Alpine.js functionality here if needed
            }
        }
        
        // Auto-refresh every 5 minutes
        setInterval(() => {
            location.reload();
        }, 300000);
    </script>
</body>
</html>
