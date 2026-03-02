<?php
/**
 * Crowdsec Management Dashboard
 * Monitor and manage Crowdsec intrusion detection and firewall bouncer
 * Crowdsec runs as root, but we can display status and common management commands
 */

require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

header('Content-Type: text/html; charset=utf-8');

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Check if crowdsec is installed
$crowdsec_installed = false;
$cscli_path = '';
$crowdsec_version = '';
exec('which cscli 2>/dev/null', $which_output);
if (!empty($which_output)) {
    $crowdsec_installed = true;
    $cscli_path = trim($which_output[0]);
}

// Get crowdsec version if installed
if ($crowdsec_installed) {
    $version_output = [];
    exec('cscli version 2>&1', $version_output);
    $crowdsec_version = isset($version_output[0]) ? trim($version_output[0]) : 'Unknown';
}

// Get systemd service status
function get_service_status($service_name) {
    $output = [];
    $status = 0;
    exec("systemctl is-active $service_name 2>&1", $output, $status);
    return [
        'active' => $status === 0,
        'status' => trim($output[0] ?? 'unknown')
    ];
}

$crowdsec_service = get_service_status('crowdsec');
$bouncer_service = get_service_status('crowdsec-firewall-bouncer');

// Get running process info via systemctl (non-root call shows limited output but OK for status)
function get_systemctl_full_status($service) {
    $output = [];
    exec("systemctl status $service 2>&1", $output);
    return $output;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Crowdsec Management - AGENTS</title>
    <style>
        body { font-family: monospace; background: #1a1a1a; color: #0f0; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #0ff; text-transform: uppercase; letter-spacing: 2px; }
        h2 { color: #0ff; margin-top: 40px; border-bottom: 2px solid #0ff; padding-bottom: 5px; }
        h3 { color: #0f0; }
        
        .status-panel {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin: 20px 0;
        }
        
        .status-box {
            background: #0a0a0a;
            border: 2px solid #0f0;
            padding: 15px;
            border-radius: 3px;
        }
        
        .status-box.ok { border-color: #0f0; background: rgba(0,255,0,0.05); }
        .status-box.error { border-color: #f00; background: rgba(255,0,0,0.05); }
        .status-box.warning { border-color: #ff0; background: rgba(255,255,0,0.05); }
        
        .status-label { color: #0ff; font-weight: bold; font-size: 0.9em; text-transform: uppercase; }
        .status-value { font-size: 1.1em; margin-top: 5px; word-break: break-all; }
        .status-value.running { color: #0f0; }
        .status-value.inactive { color: #ff0; }
        .status-value.failed { color: #f00; }
        
        .section { background: #0a0a0a; border: 1px solid #0f0; padding: 20px; margin: 20px 0; }
        .section h3 { margin-top: 0; }
        
        .command-block {
            background: #000;
            border-left: 3px solid #0f0;
            padding: 10px;
            margin: 10px 0;
            overflow-x: auto;
            font-size: 0.9em;
        }
        
        .install-section {
            background: rgba(255,255,0,0.05);
            border: 2px dashed #ff0;
            padding: 20px;
            margin: 20px 0;
        }
        
        .code-section {
            background: #000;
            border: 1px solid #0f0;
            padding: 15px;
            margin: 15px 0;
            overflow-x: auto;
        }
        
        .code-section pre { margin: 0; }
        
        .grid-cols-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        
        .info-box { background: rgba(0,255,255,0.05); border-left: 4px solid #0ff; padding: 10px; margin: 10px 0; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        
        th { 
            background: #0f0;
            color: #000;
            padding: 10px;
            text-align: left;
        }
        
        td {
            border-bottom: 1px solid #0f0;
            padding: 10px;
        }
        
        tr:hover { background: rgba(0,255,0,0.05); }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.85em;
            font-weight: bold;
        }
        
        .badge.installed { background: #0f0; color: #000; }
        .badge.missing { background: #f00; color: #fff; }
        .badge.running { background: #0f0; color: #000; }
        .badge.stopped { background: #ff0; color: #000; }
    </style>
</head>
<body>
<div class="container">
    <h1>🛡️ Crowdsec Intrusion Detection System</h1>
    <p>Behavioral threat detection agent protecting against network attacks and security threats</p>

    <!-- Installation Status -->
    <h2>Installation Status</h2>
    <div class="status-panel">
        <div class="status-box <?php echo $crowdsec_installed ? 'ok' : 'error'; ?>">
            <div class="status-label">Crowdsec Agent</div>
            <div class="status-value">
                <?php if ($crowdsec_installed): ?>
                    <span class="badge installed">✓ INSTALLED</span>
                <?php else: ?>
                    <span class="badge missing">✗ NOT INSTALLED</span>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="status-box <?php echo $crowdsec_installed ? 'ok' : 'warning'; ?>">
            <div class="status-label">Service Status</div>
            <div class="status-value <?php echo $crowdsec_service['active'] ? 'running' : 'inactive'; ?>">
                <?php echo $crowdsec_service['active'] ? '<span class="badge running">✓ RUNNING</span>' : '<span class="badge stopped">⊗ INACTIVE</span>'; ?>
            </div>
        </div>
        
        <div class="status-box <?php echo $bouncer_service['active'] ? 'ok' : 'warning'; ?>">
            <div class="status-label">Firewall Bouncer</div>
            <div class="status-value <?php echo $bouncer_service['active'] ? 'running' : 'inactive'; ?>">
                <?php echo $bouncer_service['active'] ? '<span class="badge running">✓ RUNNING</span>' : '<span class="badge stopped">⊗ INACTIVE</span>'; ?>
            </div>
        </div>
        
        <div class="status-box ok">
            <div class="status-label">Version</div>
            <div class="status-value">
                <?php echo $crowdsec_installed ? h($crowdsec_version) : 'N/A'; ?>
            </div>
        </div>
    </div>

    <?php if (!$crowdsec_installed): ?>
    <div class="install-section">
        <h3>⚠️ Crowdsec Not Installed</h3>
        <p>Installation is required on all servers. The installation commands used on this network are documented below.</p>
        
        <h4>Quick Install (Debian/Ubuntu):</h4>
        <div class="code-section">
            <pre>curl -s https://packagecloud.io/install/repositories/crowdsec/crowdsec/script.deb.sh | sudo bash
sudo apt install crowdsec -y
sudo apt install crowdsec-firewall-bouncer-iptables -y
sudo systemctl enable --now crowdsec
sudo systemctl enable --now crowdsec-firewall-bouncer</pre>
        </div>

        <h4>Then Install Common Rules/Scenarios:</h4>
        <div class="code-section">
            <pre>sudo cscli collections install crowdsecurity/linux
sudo cscli collections install crowdsecurity/apache2
sudo cscli scenarios install crowdsecurity/ssh-bf crowdsecurity/http-probing
sudo cscli scenarios install crowdsecurity/http-bad-user-agent
sudo cscli scenarios install crowdsecurity/http-crawl-non_statics
sudo cscli scenarios install crowdsecurity/http-scan
sudo cscli hub install crowdsecurity/blocklist-ip</pre>
        </div>

        <h4>Installation Tips:</h4>
        <ul>
            <li>Crowdsec requires root access to manage firewall rules via IPtables/nftables</li>
            <li>The firewall bouncer integrates with iptables or nftables to drop/reject malicious traffic</li>
            <li>By default, Crowdsec protects SSH, HTTP, and common services</li>
            <li>After install, verify with: <code>sudo systemctl status crowdsec</code></li>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Service Management -->
    <h2>Service Management (Requires sudo or root)</h2>
    
    <div class="grid-cols-2">
        <div class="section">
            <h3>Crowdsec Agent</h3>
            
            <div class="info-box">
                <strong>Current Status:</strong> 
                <?php echo $crowdsec_service['active'] ? '✓ Running' : '✗ Inactive'; ?>
            </div>
            
            <h4>Control Commands:</h4>
            <div class="command-block">sudo systemctl start crowdsec</div>
            <div class="command-block">sudo systemctl stop crowdsec</div>
            <div class="command-block">sudo systemctl restart crowdsec</div>
            <div class="command-block">sudo systemctl status crowdsec</div>
            <div class="command-block">sudo systemctl enable crowdsec</div>
            <div class="command-block">sudo systemctl disable crowdsec</div>
            
            <h4>View Logs:</h4>
            <div class="command-block">sudo journalctl -u crowdsec -f</div>
            <div class="command-block">sudo journalctl -u crowdsec -n 50</div>
            <div class="command-block">tail -f /var/log/crowdsec/agent.log</div>
        </div>

        <div class="section">
            <h3>Firewall Bouncer</h3>
            
            <div class="info-box">
                <strong>Current Status:</strong> 
                <?php echo $bouncer_service['active'] ? '✓ Running' : '✗ Inactive'; ?>
            </div>
            
            <h4>Control Commands:</h4>
            <div class="command-block">sudo systemctl start crowdsec-firewall-bouncer</div>
            <div class="command-block">sudo systemctl stop crowdsec-firewall-bouncer</div>
            <div class="command-block">sudo systemctl restart crowdsec-firewall-bouncer</div>
            <div class="command-block">sudo systemctl status crowdsec-firewall-bouncer</div>
            <div class="command-block">sudo systemctl enable crowdsec-firewall-bouncer</div>
            <div class="command-block">sudo systemctl disable crowdsec-firewall-bouncer</div>
            
            <h4>View Logs:</h4>
            <div class="command-block">sudo journalctl -u crowdsec-firewall-bouncer -f</div>
            <div class="command-block">sudo journalctl -u crowdsec-firewall-bouncer -n 50</div>
        </div>
    </div>

    <!-- Verification & Monitoring -->
    <h2>Verification Commands</h2>
    
    <div class="grid-cols-2">
        <div class="section">
            <h3>Check Installation</h3>
            <div class="command-block">which cscli crowdsec</div>
            <div class="command-block">cscli version</div>
            <div class="command-block">sudo cscli config show</div>
        </div>

        <div class="section">
            <h3>Monitor Active Rules</h3>
            <div class="command-block">sudo cscli collections list</div>
            <div class="command-block">sudo cscli scenarios list</div>
            <div class="command-block">sudo cscli postoverflows list</div>
            <div class="command-block">sudo cscli hub list crowdsecurity/</div>
        </div>
    </div>

    <!-- Firewall Status -->
    <h2>Firewall Status</h2>
    
    <div class="grid-cols-2">
        <div class="section">
            <h3>iptables Rules</h3>
            <div class="command-block">sudo iptables -S | grep CROWDSEC</div>
            <div class="command-block">sudo iptables -S INPUT | grep CROWDSEC</div>
            <div class="info-box">Shows all CROWDSEC firewall rules currently installed by the bouncer</div>
        </div>

        <div class="section">
            <h3>IP Blocklist</h3>
            <div class="command-block">sudo ipset list crowdsec-blacklists-0 | head -20</div>
            <div class="command-block">sudo ipset list crowdsec-blacklists-0 | wc -l</div>
            <div class="info-box">View IPs currently blocked by Crowdsec (crowdsec-blacklists-0 is the default set)</div>
        </div>
    </div>

    <h2>Configuration Files</h2>
    
    <table>
        <thead>
            <tr>
                <th>File</th>
                <th>Purpose</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>/etc/crowdsec/config.yaml</code></td>
                <td>Main Crowdsec configuration</td>
            </tr>
            <tr>
                <td><code>/etc/crowdsec/bouncers/crowdsec-firewall-bouncer.yaml</code></td>
                <td>Firewall bouncer settings (iptables/nftables)</td>
            </tr>
            <tr>
                <td><code>/etc/crowdsec/whitelists.yaml</code></td>
                <td>IP/CIDR whitelist (exempt from blocking)</td>
            </tr>
            <tr>
                <td><code>/etc/crowdsec/local_api_credentials.yaml</code></td>
                <td>API credentials for local bouncer communication</td>
            </tr>
            <tr>
                <td><code>/etc/crowdsec/profiles.yaml</code></td>
                <td>Remediation actions (ban, captcha, etc.)</td>
            </tr>
            <tr>
                <td><code>/var/lib/crowdsec/</code></td>
                <td>Database and state files</td>
            </tr>
        </tbody>
    </table>

    <div class="info-box">
        <strong>Edit config with:</strong> <code>sudo nano /etc/crowdsec/config.yaml</code> then <code>sudo systemctl restart crowdsec</code>
    </div>

    <!-- Common Network Usage -->
    <h2>Common Usage History (From Bash History)</h2>
    
    <div class="section">
        <h3>Install Collections & Scenarios</h3>
        <div class="command-block">sudo cscli collections install crowdsecurity/linux</div>
        <div class="command-block">sudo cscli collections install crowdsecurity/apache2</div>
        <div class="command-block">sudo cscli scenarios install crowdsecurity/ssh-bf crowdsecurity/http-probing</div>
        <div class="command-block">sudo cscli scenarios install crowdsecurity/http-bad-user-agent</div>
        <div class="command-block">sudo cscli scenarios install crowdsecurity/http-crawl-non_statics</div>
        <div class="command-block">sudo cscli scenarios install crowdsecurity/http-scan</div>
        <div class="command-block">sudo cscli hub install crowdsecurity/blocklist-ip</div>
    </div>

    <div class="section">
        <h3>Bouncer Management</h3>
        <div class="command-block">sudo apt install crowdsec-firewall-bouncer-iptables -y</div>
        <div class="command-block">sudo systemctl restart crowdsec-firewall-bouncer</div>
        <div class="command-block">cd /etc/crowdsec/bouncers</div>
    </div>

    <div class="section">
        <h3>Configuration Edits (use with nano or vim)</h3>
        <div class="command-block">sudo nano /etc/crowdsec/config.yaml</div>
        <div class="command-block">sudo nano /etc/crowdsec/bouncers/crowdsec-firewall-bouncer.yaml</div>
        <div class="command-block">sudo nano /etc/crowdsec/whitelists.yaml</div>
        <div class="command-block">sudo nano /etc/crowdsec/local_api_credentials.yaml</div>
    </div>

    <!-- Alert & Decision History -->
    <h2>Query Recent Decisions</h2>
    
    <div class="section">
        <h4>List Recent Alerts/Bans:</h4>
        <div class="command-block">sudo cscli decisions list</div>
        <div class="command-block">sudo cscli decisions list -o json</div>
        <div class="command-block">sudo cscli alerts list -n 20</div>
        <div class="command-block">sudo cscli alerts list -n 20 -o json</div>
        
        <h4>Delete or Unban IPs:</h4>
        <div class="command-block">sudo cscli decisions delete -i &lt;IP_ADDRESS&gt;</div>
        <div class="command-block">sudo cscli decisions delete -r "ssh:bruteforce"</div>
    </div>

    <!-- Bouncer Variants & nftables -->
    <h2>Bouncer Variants</h2>
    
    <div class="info-box">
        <strong>Default on this system:</strong> crowdsec-firewall-bouncer-iptables
        <br>
        Other variants available:
        <ul>
            <li><strong>nftables:</strong> <code>sudo apt install crowdsec-firewall-bouncer-nftables</code> (modern replacement for iptables)</li>
            <li><strong>pf:</strong> FreeBSD packet filter</li>
            <li><strong>VIP:</strong> Virtual IP management</li>
        </ul>
    </div>

    <div class="section">
        <h4>Check nftables Status (if available):</h4>
        <div class="command-block">sudo nft list tables | grep -i crowdsec</div>
    </div>

    <hr style="border: none; border-top: 2px solid #0f0; margin: 40px 0;">
    <p style="color: #888; font-size: 0.9em;">
        <strong>Note:</strong> All Crowdsec operations require root/sudo access. 
        This dashboard shows status and provides command references for system administrators.
        <br>
        <strong>Last verified:</strong> <?php echo date('Y-m-d H:i:s'); ?>
        <br>
        <a href="/admin/" style="color: #0f0; text-decoration: none;">← Back to Admin Console</a>
    </p>
</div>
</body>
</html>
