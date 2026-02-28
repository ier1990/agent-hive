<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

header('Content-Type: text/html; charset=utf-8');

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function infer_base_url() {
    $https = isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'localhost';
    return $scheme . '://' . $host;
}

function build_servers_list_url($baseUrl, $statusFilter, $locationFilter) {
    $baseUrl = rtrim((string)$baseUrl, '/');
    $params = [];
    if ($statusFilter !== '') $params['status'] = $statusFilter;
    if ($locationFilter !== '') $params['location'] = $locationFilter;
    $qs = $params ? ('?' . http_build_query($params)) : '';
    return $baseUrl . '/v1/servers/list' . $qs;
}

function fetch_servers_list($baseUrl, $apiKey, $statusFilter, $locationFilter) {
    $url = build_servers_list_url($baseUrl, $statusFilter, $locationFilter);
    $headers = ['Accept: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'X-API-Key: ' . $apiKey;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string)curl_error($ch);
    curl_close($ch);

    $decoded = null;
    if (is_string($body) && $body !== '') {
        $tmp = json_decode($body, true);
        if (is_array($tmp)) $decoded = $tmp;
    }

    return [
        'url' => $url,
        'http_code' => $code,
        'curl_error' => $err,
        'raw' => is_string($body) ? $body : '',
        'json' => $decoded,
    ];
}

function auth_csrf_token() {
    if (function_exists('auth_session_start')) {
        auth_session_start();
    } elseif (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_ai_servers'])) {
        $_SESSION['csrf_ai_servers'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf_ai_servers'];
}

function auth_csrf_ok($token) {
    if (function_exists('auth_session_start')) {
        auth_session_start();
    } elseif (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return isset($_SESSION['csrf_ai_servers']) && is_string($token) && hash_equals((string)$_SESSION['csrf_ai_servers'], $token);
}

function cluster_config_path() {
    return rtrim((string)PRIVATE_ROOT, '/\\') . '/config/cluster_receiver.json';
}

function read_cluster_config($path) {
    if (!is_readable($path)) return [];
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return [];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function write_cluster_config($path, array $cfg) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) return false;
    return file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

$saveMsg = '';
$saveErr = '';
$cfgPath = cluster_config_path();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if (!auth_csrf_ok($csrf)) {
        $saveErr = 'Invalid CSRF token.';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        if ($action === 'save_cluster_receiver') {
            $receiverUrl = trim((string)($_POST['receiver_url'] ?? ''));
            $receiverKey = trim((string)($_POST['receiver_api_key'] ?? ''));
            $serverLocation = trim((string)($_POST['server_location'] ?? 'lan'));
            $heartbeat = (int)($_POST['heartbeat_interval'] ?? 60);
            if ($heartbeat < 10) $heartbeat = 10;
            if (!in_array($serverLocation, ['lan', 'cloud'], true)) $serverLocation = 'lan';
            if ($receiverUrl === '' || $receiverKey === '') {
                $saveErr = 'Receiver URL and API key are required.';
            } else {
                $cfg = [
                    'receiver_url' => rtrim($receiverUrl, '/'),
                    'api_key' => $receiverKey,
                    'server_location' => $serverLocation,
                    'heartbeat_interval' => $heartbeat,
                    'updated_at' => gmdate('c'),
                ];
                if (write_cluster_config($cfgPath, $cfg)) {
                    $saveMsg = 'Cluster receiver config saved to ' . $cfgPath;
                } else {
                    $saveErr = 'Failed writing ' . $cfgPath . ' (check permissions).';
                }
            }
        } elseif ($action === 'clear_cluster_receiver') {
            if (is_file($cfgPath) && !@unlink($cfgPath)) {
                $saveErr = 'Failed to remove ' . $cfgPath;
            } else {
                $saveMsg = 'Cluster receiver config cleared.';
            }
        }
    }
}

$clusterCfg = read_cluster_config($cfgPath);
$cfgReceiverUrl = trim((string)($clusterCfg['receiver_url'] ?? ''));
$cfgReceiverKey = trim((string)($clusterCfg['api_key'] ?? ''));
$cfgServerLocation = trim((string)($clusterCfg['server_location'] ?? 'lan'));
$cfgHeartbeatInterval = (int)($clusterCfg['heartbeat_interval'] ?? 60);
if ($cfgHeartbeatInterval < 10) $cfgHeartbeatInterval = 60;

$baseUrl = trim((string)($_GET['base_url'] ?? ($cfgReceiverUrl !== '' ? $cfgReceiverUrl : infer_base_url())));
$apiKey = trim((string)($_GET['api_key'] ?? $cfgReceiverKey));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$locationFilter = trim((string)($_GET['location'] ?? ''));
$run = isset($_GET['run']) && (string)$_GET['run'] === '1';

$result = null;
$servers = [];
$error = '';

if ($run) {
    if ($baseUrl === '') {
        $error = 'Base URL is required.';
    } else {
        $result = fetch_servers_list($baseUrl, $apiKey, $statusFilter, $locationFilter);
        if ($result['curl_error'] !== '') {
            $error = 'cURL error: ' . $result['curl_error'];
        } elseif (!is_array($result['json'])) {
            $error = 'Endpoint did not return JSON.';
        } else {
            if (!empty($result['json']['ok'])) {
                $servers = is_array($result['json']['servers'] ?? null) ? $result['json']['servers'] : [];
            } else {
                $error = (string)($result['json']['error'] ?? 'request_failed');
                $reason = (string)($result['json']['reason'] ?? '');
                if ($reason !== '') $error .= ' (' . $reason . ')';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Servers</title>
    <style>
        :root {
            --bg: #0b1220;
            --panel: #131d31;
            --panel2: #182643;
            --ink: #e5ecff;
            --muted: #9aa8c7;
            --ok: #29c36a;
            --warn: #f2a93b;
            --bad: #ec5a5a;
            --line: #2b3a5b;
            --accent: #5aa3ff;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: radial-gradient(circle at 20% 0%, #1a2c50 0%, #0b1220 55%);
            color: var(--ink);
            font: 14px/1.45 "Segoe UI", Tahoma, sans-serif;
            padding: 16px;
        }
        h1, h2, h3 { margin: 0 0 10px; }
        .wrap { max-width: 1200px; margin: 0 auto; }
        .panel {
            background: linear-gradient(180deg, var(--panel) 0%, var(--panel2) 100%);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 12px;
        }
        .muted { color: var(--muted); }
        .grid {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(4, minmax(140px, 1fr));
        }
        label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 4px; }
        input, select, button, textarea {
            width: 100%;
            border: 1px solid var(--line);
            background: #0d1628;
            color: var(--ink);
            border-radius: 8px;
            padding: 9px 10px;
            font: inherit;
        }
        button {
            background: var(--accent);
            color: #081425;
            font-weight: 700;
            cursor: pointer;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            border-bottom: 1px solid var(--line);
            text-align: left;
            padding: 8px;
            vertical-align: top;
        }
        th { color: var(--muted); font-weight: 600; }
        .badge {
            display: inline-block;
            border-radius: 999px;
            padding: 2px 8px;
            font-size: 12px;
            border: 1px solid var(--line);
            background: #10203a;
        }
        .ok { color: var(--ok); }
        .warn { color: var(--warn); }
        .bad { color: var(--bad); }
        code, pre {
            background: #0b1629;
            border: 1px solid var(--line);
            border-radius: 8px;
        }
        code { padding: 2px 5px; }
        pre {
            margin: 0;
            padding: 10px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .row { margin: 8px 0 0; }
        @media (max-width: 900px) {
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="panel">
        <h1>AI Servers</h1>
        <div class="muted">Cluster endpoint is <code>/v1/servers/*</code>. There is no separate <code>/v1/register</code> route in this repo.</div>
    </div>

    <div class="panel">
        <h2>Cluster Receiver Config (for server_register.sh)</h2>
        <div class="muted">Saved at <code><?php echo h($cfgPath); ?></code>. Joining nodes can run <code>bash /web/html/src/scripts/server_register.sh</code> without passing env vars.</div>
        <?php if ($saveMsg !== ''): ?>
            <div class="row ok"><strong>Saved:</strong> <?php echo h($saveMsg); ?></div>
        <?php endif; ?>
        <?php if ($saveErr !== ''): ?>
            <div class="row bad"><strong>Error:</strong> <?php echo h($saveErr); ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo h(auth_csrf_token()); ?>">
            <input type="hidden" name="action" value="save_cluster_receiver">
            <div class="grid">
                <div>
                    <label>Receiver URL</label>
                    <input type="text" name="receiver_url" value="<?php echo h($cfgReceiverUrl); ?>" placeholder="http://192.168.0.142" required>
                </div>
                <div>
                    <label>Receiver API Key (<code>server</code> scope)</label>
                    <input type="text" name="receiver_api_key" value="<?php echo h($cfgReceiverKey); ?>" placeholder="srv-cluster-1" required>
                </div>
                <div>
                    <label>Server Location</label>
                    <select name="server_location">
                        <option value="lan"<?php echo $cfgServerLocation === 'lan' ? ' selected' : ''; ?>>lan</option>
                        <option value="cloud"<?php echo $cfgServerLocation === 'cloud' ? ' selected' : ''; ?>>cloud</option>
                    </select>
                </div>
                <div>
                    <label>Heartbeat Interval (seconds)</label>
                    <input type="number" name="heartbeat_interval" min="10" max="3600" value="<?php echo h((string)$cfgHeartbeatInterval); ?>">
                </div>
            </div>
            <div class="row"><button type="submit">Save Cluster Config</button></div>
        </form>
        <form method="post" class="row">
            <input type="hidden" name="csrf_token" value="<?php echo h(auth_csrf_token()); ?>">
            <input type="hidden" name="action" value="clear_cluster_receiver">
            <button type="submit">Clear Saved Config</button>
        </form>
    </div>

    <div class="panel">
        <h2>Cluster Viewer</h2>
        <form method="get">
            <input type="hidden" name="run" value="1">
            <div class="grid">
                <div>
                    <label>Base URL</label>
                    <input type="text" name="base_url" value="<?php echo h($baseUrl); ?>" placeholder="http://192.168.1.10">
                </div>
                <div>
                    <label>API Key (must include <code>server</code> scope)</label>
                    <input type="text" name="api_key" value="<?php echo h($apiKey); ?>" placeholder="srv-cluster-1">
                </div>
                <div>
                    <label>Status Filter</label>
                    <select name="status">
                        <?php
                        $statusOptions = ['', 'online', 'offline', 'degraded', 'maintenance'];
                        foreach ($statusOptions as $opt) {
                            $sel = ($statusFilter === $opt) ? ' selected' : '';
                            $label = ($opt === '') ? '(all)' : $opt;
                            echo '<option value="' . h($opt) . '"' . $sel . '>' . h($label) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label>Location Filter</label>
                    <select name="location">
                        <?php
                        $locationOptions = ['', 'lan', 'cloud'];
                        foreach ($locationOptions as $opt) {
                            $sel = ($locationFilter === $opt) ? ' selected' : '';
                            $label = ($opt === '') ? '(all)' : $opt;
                            echo '<option value="' . h($opt) . '"' . $sel . '>' . h($label) . '</option>';
                        }
                        ?>
                    </select>
                </div>
            </div>
            <div class="row"><button type="submit">Fetch /v1/servers/list</button></div>
        </form>

        <?php if ($run): ?>
            <div class="row muted">Request URL: <code><?php echo h($result ? $result['url'] : ''); ?></code></div>
            <?php if ($result): ?>
                <div class="row muted">HTTP: <code><?php echo h((string)$result['http_code']); ?></code></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="row bad"><strong>Error:</strong> <?php echo h($error); ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>Server List</h2>
        <?php if (!$run): ?>
            <div class="muted">Run a query above to load data.</div>
        <?php elseif ($error !== ''): ?>
            <div class="muted">No rows due to error. See details above.</div>
        <?php elseif (empty($servers)): ?>
            <div class="muted">No servers returned.</div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Hostname</th>
                    <th>Status</th>
                    <th>Last Seen</th>
                    <th>LAN / Public IP</th>
                    <th>Load</th>
                    <th>Memory</th>
                    <th>Disk</th>
                    <th>Server ID</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($servers as $s): ?>
                    <?php
                    $status = (string)($s['status'] ?? '');
                    $isStale = !empty($s['stale']);
                    $statusClass = 'ok';
                    if ($isStale || $status === 'offline') $statusClass = 'bad';
                    elseif ($status === 'degraded' || $status === 'maintenance') $statusClass = 'warn';
                    ?>
                    <tr>
                        <td><?php echo h((string)($s['hostname'] ?? '')); ?></td>
                        <td>
                            <span class="badge <?php echo h($statusClass); ?>"><?php echo h($status !== '' ? $status : 'unknown'); ?></span>
                            <?php if ($isStale): ?><span class="badge bad">stale</span><?php endif; ?>
                        </td>
                        <td><?php echo h((string)($s['last_seen'] ?? '')); ?></td>
                        <td><?php echo h((string)($s['ip_lan'] ?? '')); ?><br><span class="muted"><?php echo h((string)($s['ip_public'] ?? '')); ?></span></td>
                        <td><?php echo h((string)($s['load_1m'] ?? 0)); ?> / <?php echo h((string)($s['load_5m'] ?? 0)); ?> / <?php echo h((string)($s['load_15m'] ?? 0)); ?></td>
                        <td><?php echo h((string)($s['mem_used_mb'] ?? 0)); ?> / <?php echo h((string)($s['mem_total_mb'] ?? 0)); ?> MB</td>
                        <td><?php echo h((string)($s['disk_used_gb'] ?? 0)); ?> / <?php echo h((string)($s['disk_total_gb'] ?? 0)); ?> GB</td>
                        <td><code><?php echo h((string)($s['server_id'] ?? '')); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h2>How To Connect 3 Local Servers</h2>
        <ol>
            <li>Choose one node as your cluster receiver (for example <code>http://192.168.1.10</code>).</li>
            <li>Use /admin/admin_API.php to create an API key with <code>server</code> scope, stored at <code>/web/private/api_keys.json</code>.</li>
            <li>On each joining node, save the receiver URL + key above in this page.</li>
            <li>On each joining node, run <code>/web/html/src/scripts/server_register.sh</code> (it will auto-load <code><?php echo h($cfgPath); ?></code>).</li>
            <li>Keep that script running by cron <code>@reboot</code> or systemd so heartbeats continue.</li>
            <li>Verify from any admin node by calling <code>/v1/servers/list</code> with the same key.</li>
        </ol>

<pre>{
  "srv-cluster-1": {"active": true, "scopes": ["server","health"], "name": "Cluster Server Key"}
}</pre>

<pre>bash /web/html/src/scripts/server_register.sh</pre>

<pre># Optional one-time override (takes precedence over saved config)
AGENTHIVE_URL=http://192.168.1.10 AGENTHIVE_API_KEY=srv-cluster-1 \
bash /web/html/src/scripts/server_register.sh</pre>

<pre>curl -s -H "X-API-Key: srv-cluster-1" \
http://192.168.1.10/v1/servers/list</pre>

        <div class="row muted">
            If you get <code>missing_server_scope</code>, your API key is valid but missing the <code>server</code> scope.
        </div>
    </div>

    <?php if ($run && $result && $result['raw'] !== ''): ?>
    <div class="panel">
        <h3>Raw Response</h3>
        <pre><?php echo h($result['raw']); ?></pre>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
