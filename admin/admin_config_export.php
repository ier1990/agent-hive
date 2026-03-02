<?php
/**
 * Config Export/Import Utility
 * Export sysinfo configuration in portable format for other servers
 */

require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

$action = $_GET['action'] ?? 'view';
$result = null;
$error = null;

// Load current .env config
function read_dotenv() {
    $path = rtrim((string)PRIVATE_ROOT, '/\\') . '/.env';
    $config = [];
    if (file_exists($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) continue; // skip comments
            if (strpos($line, '=') === false) continue;
            list($key, $val) = explode('=', $line, 2);
            $config[trim($key)] = trim($val);
        }
    }
    return $config;
}

$dotenv = read_dotenv();

// Export configuration
if ($action === 'export') {
    $export = [
        'SYSINFO_API_URL' => $dotenv['SYSINFO_API_URL'] ?? 'http://127.0.0.1/v1/inbox/',
        'SYSINFO_API_KEY' => $dotenv['SYSINFO_API_KEY'] ?? '',
        'IER_API_KEY' => $dotenv['IER_API_KEY'] ?? '',
    ];
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename=sysinfo_config_export_' . date('Y-m-d_His') . '.json');
    echo json_encode($export, JSON_PRETTY_PRINT);
    exit;
}

// Generate shell export format
if ($action === 'export_shell') {
    $url = $dotenv['SYSINFO_API_URL'] ?? 'http://127.0.0.1/v1/inbox/';
    $key = $dotenv['SYSINFO_API_KEY'] ?? $dotenv['IER_API_KEY'] ?? '';
    $shell = <<<SHELL
# Copy-paste these lines to other AgentHive servers:
export SYSINFO_API_URL="$url"
export SYSINFO_API_KEY="$key"

# Or add to /web/private/.env:
# SYSINFO_API_URL=$url
# SYSINFO_API_KEY=$key
SHELL;
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename=sysinfo_config.sh');
    echo $shell;
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Config Export - AGENTS</title>
    <style>
        body { font-family: monospace; background: #222; color: #0f0; margin: 20px; }
        .container { max-width: 900px; }
        h1 { color: #0ff; }
        .section { margin: 20px 0; padding: 15px; background: #1a1a1a; border: 1px solid #0f0; }
        .config-value { 
            background: #0a0a0a;
            padding: 10px;
            margin: 10px 0;
            border-left: 3px solid #0f0;
            word-break: break-all;
        }
        .config-label { color: #0ff; font-weight: bold; }
        .button { 
            display: inline-block;
            padding: 10px 20px;
            margin: 5px;
            background: #0f0;
            color: #000;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-family: monospace;
        }
        .button:hover { background: #0dd; }
        .warning { color: #ff0; }
        .success { color: #0f0; }
        code { background: #0a0a0a; padding: 2px 4px; }
        pre { background: #0a0a0a; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔒 Sysinfo Configuration Export</h1>
    
    <div class="section">
        <h2>Current Server Configuration</h2>
        <p>Copy this config to other AgentHive servers running <code>root_sysinfo_local.sh</code></p>
        
        <div class="config-value">
            <div class="config-label">SYSINFO_API_URL:</div>
            <div><?php echo htmlspecialchars($dotenv['SYSINFO_API_URL'] ?? 'http://127.0.0.1/v1/inbox/'); ?></div>
        </div>
        
        <div class="config-value">
            <div class="config-label">SYSINFO_API_KEY:</div>
            <div><?php echo !empty($dotenv['SYSINFO_API_KEY']) ? '<span class="success">[SET]</span>' : '<span class="warning">[NOT SET - using IER_API_KEY fallback]</span>'; ?></div>
        </div>
        
        <div class="config-value">
            <div class="config-label">IER_API_KEY (fallback):</div>
            <div><?php echo !empty($dotenv['IER_API_KEY']) ? '<span class="success">[SET]</span>' : '<span class="warning">[NOT SET]</span>'; ?></div>
        </div>
    </div>

    <div class="section">
        <h2>Export Formats</h2>
        
        <h3>1. Shell Environment (for manual setup)</h3>
        <p>Download as shell script or copy-paste:</p>
        <a href="?action=export_shell" class="button">📥 Export as Shell Script</a>
        
        <h3>2. JSON Format (for automation)</h3>
        <p>Machine-readable format for API-driven imports:</p>
        <a href="?action=export" class="button">📥 Export as JSON</a>
    </div>

    <div class="section">
        <h2>How to Apply on Another Server</h2>
        
        <h3>Option A: Manual (Recommended for Testing)</h3>
        <pre>ssh user@other-server
# Download the shell script above, then:
source sysinfo_config.sh

# Test the sender:
SYSINFO_API_URL="$SYSINFO_API_URL" SYSINFO_API_KEY="$SYSINFO_API_KEY" \
  /bin/bash /web/html/src/scripts/root_sysinfo_local.sh

# If OK, add to /web/private/.env on remote server:
cat &lt;&lt;EOF &gt;&gt; /web/private/.env
SYSINFO_API_URL=<?php echo htmlspecialchars($dotenv['SYSINFO_API_URL'] ?? 'http://127.0.0.1/v1/inbox/'); ?>
SYSINFO_API_KEY=<?php echo htmlspecialchars($dotenv['SYSINFO_API_KEY'] ?? ''); ?>
EOF</pre>

        <h3>Option B: Automated (via API)</h3>
        <pre>curl -X POST https://other-server.lan/v1/config/import \
  -H "X-API-Key: their-api-key" \
  -H "Content-Type: application/json" \
  -d @sysinfo_config_export.json</pre>
        <p><span class="warning">Note:</span> Requires the other server to have import endpoint (not yet implemented)</p>
    </div>

    <div class="section">
        <h2>Verification</h2>
        <p>After copying config to another server:</p>
        <pre># SSH into remote server
ssh user@other-server

# Check config was applied
grep SYSINFO /web/private/.env

# Test sender can reach target inbox
curl -X POST "<?php echo htmlspecialchars($dotenv['SYSINFO_API_URL'] ?? 'http://127.0.0.1/v1/inbox/'); ?>" \
  -H "X-API-Key: <?php echo htmlspecialchars($dotenv['SYSINFO_API_KEY'] ?? $dotenv['IER_API_KEY'] ?? ''); ?>" \
  -H "Content-Type: application/json" \
  -d '{"test":"ok"}'

# Should return 201 or 403 (gateway reachable but table restricted)
# 404 = URL wrong, 403 = auth failed, 201 = success</pre>
    </div>

    <div class="section">
        <h2>Architecture</h2>
        <p>Each AgentHive server can POST sysinfo to:</p>
        <ul>
            <li><strong>Local inbox:</strong> <code><?php echo htmlspecialchars($dotenv['SYSINFO_API_URL'] ?? 'http://127.0.0.1/v1/inbox/'); ?></code></li>
            <li><strong>Remote hub:</strong> Any publicly reachable <code>/v1/inbox</code> endpoint (another AgentHive server)</li>
            <li><strong>Custom ingester:</strong> Any endpoint that accepts <code>POST JSON</code> with <code>X-API-Key</code> auth</li>
        </ul>
        <p>This enables distributed monitoring: send all sysinfo to central hub for aggregated dashboards.</p>
    </div>

    <?php $envPath = rtrim((string)PRIVATE_ROOT, '/\\') . '/.env'; ?>
    <hr>
    <p style="color:#888; font-size: 0.9em;">
        Config last modified: <?php echo is_file($envPath) ? date('Y-m-d H:i:s', (int)filemtime($envPath)) : 'n/a'; ?> | 
        <a href="/admin/" style="color:#0f0;">← Back to Admin</a>
    </p>
</div>
</body>
</html>
