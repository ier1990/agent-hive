<?php
// /admin/admin_Update.php
// Admin UI for self-hosted update management.
// Fetches release info from /v1/releases/latest?app=html internally,
// downloads tarball, extracts selected filesets.

declare(strict_types=0);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth/auth.php';

auth_require_admin();

// ---- Config ----
// Which app name to fetch from /v1/releases/
define('UPDATE_APP_NAME', 'html');

// Updatable filesets: key => config
// 'src' is relative path inside the tarball (after "html/" prefix)
// 'dest' is absolute path on disk
$UPDATABLE_TARGETS = [
    'lib' => [
        'label'   => 'Core Library (lib/)',
        'src'     => 'lib',
        'dest'    => APP_ROOT . '/lib',
        'enabled' => true,
    ],
    'v1' => [
        'label'   => 'API Endpoints (v1/)',
        'src'     => 'v1',
        'dest'    => APP_ROOT . '/v1',
        'enabled' => true,
    ],
    'admin' => [
        'label'   => 'Admin Tools (admin/)',
        'src'     => 'admin',
        'dest'    => APP_ROOT . '/admin',
        'enabled' => true,
    ],
    'scripts' => [
        'label'   => 'Source Scripts (src/scripts/)',
        'src'     => 'src/scripts',
        'dest'    => APP_ROOT . '/src/scripts',
        'enabled' => true,
    ],
];

require_once __DIR__ . '/lib/update_helpers.php';

// ---- Request handling ----

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// AJAX: fetch latest info
if ($action === 'check') {
    header('Content-Type: application/json; charset=utf-8');
    $info = update_fetch_latest_info();
    $currentVersion = update_read_current_version();
    $latest = isset($info['latest']) && is_array($info['latest']) ? $info['latest'] : [];
    $latestVersion = (string)($latest['version'] ?? '');
    $latestCommit = strtolower((string)($latest['commit'] ?? ''));
    $currentCommit = update_extract_commit_from_version($currentVersion);
    $upToDate = false;
    if ($latestCommit !== '' && $currentCommit !== '') {
        $upToDate = (strpos($latestCommit, $currentCommit) === 0) || (strpos($currentCommit, $latestCommit) === 0);
    } elseif ($latestVersion !== '' && $currentVersion !== '') {
        $upToDate = ($latestVersion === $currentVersion);
    }

    $info['current_version'] = $currentVersion;
    $info['current_commit'] = $currentCommit;
    $info['latest_commit'] = $latestCommit;
    $info['up_to_date'] = $upToDate;
    $info['history'] = update_release_history(12);
    $writeMap = [];
    foreach ($UPDATABLE_TARGETS as $k => $cfg) {
        $writeMap[$k] = [
            'dest' => (string)$cfg['dest'],
            'writable' => update_target_is_writable((string)$cfg['dest']),
        ];
    }
    $info['write_targets'] = $writeMap;
    echo json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST' && $action === 'preview') {
    header('Content-Type: application/json; charset=utf-8');
    $selected = $_POST['targets'] ?? [];
    if (!is_array($selected) || empty($selected)) {
        echo json_encode(['ok' => false, 'error' => 'No targets selected']);
        exit;
    }
    foreach ($selected as $key) {
        if (!isset($UPDATABLE_TARGETS[$key]) || !$UPDATABLE_TARGETS[$key]['enabled']) {
            echo json_encode(['ok' => false, 'error' => "Invalid target: $key"]);
            exit;
        }
    }

    $info = update_fetch_latest_info();
    if (empty($info['ok']) || empty($info['latest'])) {
        echo json_encode(['ok' => false, 'error' => (string)($info['error'] ?? 'No release info available')]);
        exit;
    }
    $latest = (array)$info['latest'];
    $prep = update_prepare_release_tree($latest);
    if (empty($prep['ok'])) {
        echo json_encode(['ok' => false, 'error' => (string)($prep['error'] ?? 'Preview preparation failed')]);
        exit;
    }

    $appRoot = (string)$prep['app_root'];
    $results = [];
    $writeMap = [];
    foreach ($selected as $key) {
        $cfg = $UPDATABLE_TARGETS[$key];
        $srcPath = $appRoot . '/' . $cfg['src'];
        $destPath = (string)$cfg['dest'];
        $results[$key] = update_preview_target_changes($srcPath, $destPath);
        $writeMap[$key] = update_target_is_writable($destPath);
    }
    update_cleanup((string)$prep['tmp_dir']);

    $filename = (string)($latest['filename'] ?? '');
    $version = (string)($latest['version'] ?? '');
    $manual = update_manual_rsync_commands($filename, $version, $selected, $UPDATABLE_TARGETS);

    echo json_encode([
        'ok' => true,
        'preview' => $results,
        'version' => $version,
        'filename' => $filename,
        'write_targets' => $writeMap,
        'manual_commands' => $manual,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// POST: perform update
if ($method === 'POST' && $action === 'update') {
    header('Content-Type: application/json; charset=utf-8');
    
    $selected = $_POST['targets'] ?? [];
    if (!is_array($selected) || empty($selected)) {
        echo json_encode(['ok' => false, 'error' => 'No targets selected']);
        exit;
    }
    
    // Validate targets
    foreach ($selected as $key) {
        if (!isset($UPDATABLE_TARGETS[$key]) || !$UPDATABLE_TARGETS[$key]['enabled']) {
            echo json_encode(['ok' => false, 'error' => "Invalid target: $key"]);
            exit;
        }
    }
    
    update_log('Update started. Targets: ' . implode(', ', $selected));
    
    // 1) Fetch latest info
    $info = update_fetch_latest_info();
    if (empty($info['ok']) || empty($info['latest'])) {
        $err = $info['error'] ?? 'No release info available';
        update_log("Update failed: $err");
        echo json_encode(['ok' => false, 'error' => $err]);
        exit;
    }
    
    $latest = $info['latest'];
    $filename = $latest['filename'] ?? '';
    $sha256 = $latest['sha256'] ?? '';
    $version = $latest['version'] ?? '';
    
    if ($filename === '') {
        update_log('Update failed: No filename in release info');
        echo json_encode(['ok' => false, 'error' => 'No filename in release info']);
        exit;
    }
    
    $prep = update_prepare_release_tree($latest);
    if (empty($prep['ok'])) {
        $err = (string)($prep['error'] ?? 'Failed preparing release');
        update_log("Update failed: $err");
        echo json_encode(['ok' => false, 'error' => $err]);
        exit;
    }
    $tmpDir = (string)$prep['tmp_dir'];
    $appRoot = (string)$prep['app_root'];

    $blocked = [];
    foreach ($selected as $key) {
        $destPath = (string)$UPDATABLE_TARGETS[$key]['dest'];
        if (!update_target_is_writable($destPath)) {
            $blocked[] = $key;
        }
    }
    if (!empty($blocked)) {
        $manual = update_manual_rsync_commands($filename, $version, $selected, $UPDATABLE_TARGETS);
        update_cleanup($tmpDir);
        $err = 'Web user cannot write target(s): ' . implode(', ', $blocked);
        update_log("Update blocked: $err");
        echo json_encode([
            'ok' => false,
            'manual_required' => true,
            'error' => $err,
            'manual_commands' => $manual,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 6) Sync selected targets
    $results = [];
    foreach ($selected as $key) {
        $cfg = $UPDATABLE_TARGETS[$key];
        $srcPath = $appRoot . '/' . $cfg['src'];
        $destPath = $cfg['dest'];
        
        if (!is_dir($srcPath)) {
            $results[$key] = ['ok' => false, 'error' => "Source not found in tarball: {$cfg['src']}"];
            continue;
        }
        
        $sync = update_sync_dir($srcPath, $destPath);
        $results[$key] = $sync;
        
        if (!empty($sync['ok'])) {
            update_log("Updated target: $key ({$cfg['dest']})");
        } else {
            update_log("Failed target: $key - " . ($sync['error'] ?? 'unknown'));
        }
    }
    
    // 7) Update VERSION file
    if ($version !== '') {
        update_write_version($version);
    }
    
    // 8) Cleanup
    update_cleanup($tmpDir);
    
    // Check if any failed
    $allOk = true;
    foreach ($results as $r) {
        if (empty($r['ok'])) {
            $allOk = false;
            break;
        }
    }
    
    update_log('Update finished. Success: ' . ($allOk ? 'yes' : 'partial'));
    
    echo json_encode([
        'ok' => $allOk,
        'results' => $results,
        'version' => $version,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Render HTML UI ----

$currentVersion = update_read_current_version();
$latestInfoForUi = update_fetch_latest_info();
$latestVersionForUi = '';
if (!empty($latestInfoForUi['ok']) && !empty($latestInfoForUi['latest']) && is_array($latestInfoForUi['latest'])) {
    $latestVersionForUi = (string)($latestInfoForUi['latest']['version'] ?? '');
}
if ($latestVersionForUi === '') {
    $latestVersionForUi = 'unknown';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Update</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #1a1a2e;
            color: #eaeaea;
            margin: 0;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        h1 {
            color: #00d9ff;
            border-bottom: 2px solid #00d9ff;
            padding-bottom: 10px;
        }
        .card {
            background: #16213e;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #0f3460;
        }
        .card h2 {
            margin-top: 0;
            color: #e94560;
        }
        .version-info {
            display: flex;
            gap: 40px;
            flex-wrap: wrap;
        }
        .version-box {
            flex: 1;
            min-width: 200px;
        }
        .version-box label {
            display: block;
            color: #888;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .version-box .value {
            font-size: 24px;
            font-weight: bold;
            font-family: monospace;
        }
        .version-box .value.current { color: #00d9ff; }
        .version-box .value.latest { color: #4ade80; }
        .version-box .value.unknown { color: #888; }
        .targets-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .targets-list li {
            padding: 12px;
            border-bottom: 1px solid #0f3460;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .targets-list li:last-child {
            border-bottom: none;
        }
        .targets-list label {
            flex: 1;
            cursor: pointer;
        }
        .targets-list input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        .targets-list .path {
            font-size: 12px;
            color: #888;
            font-family: monospace;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #e94560;
            color: white;
        }
        .btn-primary:hover {
            background: #ff6b8a;
        }
        .btn-primary:disabled {
            background: #555;
            cursor: not-allowed;
        }
        .btn-secondary {
            background: #0f3460;
            color: #00d9ff;
        }
        .btn-secondary:hover {
            background: #1a4a7a;
        }
        .btn-group {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }
        .status {
            padding: 12px;
            border-radius: 6px;
            margin-top: 20px;
            display: none;
        }
        .status.info { background: #0f3460; color: #00d9ff; display: block; }
        .status.success { background: #065f46; color: #4ade80; display: block; }
        .status.error { background: #7f1d1d; color: #fca5a5; display: block; }
        .status.loading { background: #1e3a5f; color: #60a5fa; display: block; }
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #60a5fa;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 8px;
            vertical-align: middle;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .log {
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 6px;
            padding: 12px;
            margin-top: 20px;
            font-family: monospace;
            font-size: 13px;
            max-height: 300px;
            overflow-y: auto;
            white-space: pre-wrap;
            display: none;
        }
        .log.visible { display: block; }
        .log .line { margin: 2px 0; }
        .log .line.ok { color: #4ade80; }
        .log .line.err { color: #fca5a5; }
        .history-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .history-list li {
            border-bottom: 1px solid #0f3460;
            padding: 8px 0;
            font-family: monospace;
            font-size: 12px;
        }
        .history-list li:last-child { border-bottom: none; }
        .manual-box {
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 6px;
            padding: 12px;
            margin-top: 12px;
            font-family: monospace;
            font-size: 12px;
            white-space: pre-wrap;
            display: none;
        }
        .manual-box.visible { display: block; }
        a { color: #00d9ff; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 System Update Preview</h1>
        
        <div class="card">
            <h2>Version Information</h2>
            <div class="version-info">
                <div class="version-box">
                    <label>Current Version</label>
                    <div class="value current" id="current-version"><?= htmlspecialchars($currentVersion) ?></div>
                </div>
                <div class="version-box">
                    <label>Latest Available</label>
                    <div class="value latest unknown" id="latest-version">—</div>
                </div>
            </div>
            <div class="btn-group">
                <button class="btn btn-secondary" onclick="checkForUpdates()">Check for Updates</button>
            </div>
            <div class="status" id="check-status"></div>
        </div>

        <div class="card">
            <h2>Release Cache History</h2>
            <ul class="history-list" id="release-history">
                <li>Run "Check for Updates" to load release cache history.</li>
            </ul>
        </div>
        
        <div class="card">
            <h2>Update Targets</h2>
            <p>This page is preview-only. Select targets to estimate file changes against the latest cached release.</p>
            <ul class="targets-list">
                <?php foreach ($UPDATABLE_TARGETS as $key => $cfg): ?>
                <?php if (!$cfg['enabled']) continue; ?>
                <li>
                    <input type="checkbox" id="target-<?= htmlspecialchars($key) ?>" 
                           name="targets[]" value="<?= htmlspecialchars($key) ?>" checked>
                    <label for="target-<?= htmlspecialchars($key) ?>">
                        <strong><?= htmlspecialchars($cfg['label']) ?></strong>
                        <div class="path"><?= htmlspecialchars($cfg['dest']) ?></div>
                    </label>
                </li>
                <?php endforeach; ?>
            </ul>
            <div class="btn-group">
                <button class="btn btn-secondary" id="preview-btn" onclick="runPreview()">Preview Changes</button>
            </div>
            <div class="status" id="update-status"></div>
            <div class="log" id="update-log"></div>
            <div class="manual-box" id="manual-commands"></div>
        </div>

        <div class="card">
            <h2>Terminal Workflow</h2>
            <p>Use SSH and run updates manually. This keeps deploys pinned and auditable.</p>
            <div class="log visible" style="max-height:none;">
# 1) Create/update local pinned release from GitHub (choose commit SHA)
cd /web/html/src/scripts
./release_fetch_pinned.sh --sha 38d53d74d5c8

# 2) Preview changes in this page (/admin/admin_Update.php)

# 3) Apply manually (no --delete)
# Copy the generated commands from "Preview Changes" output
            </div>
            <p style="margin-top:10px;">If you manually apply a release, update installed VERSION to match:</p>
            <div class="log visible" style="max-height:none;" id="set-version-cmd">echo "<?= htmlspecialchars($latestVersionForUi, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" | sudo tee /web/html/VERSION >/dev/null</div>
            <p style="margin-top:10px;">Weekly cache refresh (pinned to current <code>main</code> commit at run time):</p>
            <div class="log visible" style="max-height:none;">
# /etc/cron.weekly/update_release_cache
#!/bin/bash
set -euo pipefail
SHA="$(git ls-remote https://github.com/ier1990/agent-hive.git refs/heads/main | awk '{print $1}')"
/web/html/src/scripts/release_fetch_pinned.sh --sha "$SHA" >> /web/private/logs/release_cache.log 2>&1
            </div>
        </div>
        
        <p style="margin-top: 40px; color: #666; font-size: 13px;">
            <a href="/admin/">← Back to Admin</a>
        </p>
    </div>

    <script>
        function setStatus(id, type, msg) {
            const el = document.getElementById(id);
            el.className = 'status ' + type;
            el.innerHTML = (type === 'loading' ? '<span class="spinner"></span>' : '') + msg;
        }
        
        function logLine(msg, cls) {
            const log = document.getElementById('update-log');
            log.classList.add('visible');
            const line = document.createElement('div');
            line.className = 'line' + (cls ? ' ' + cls : '');
            line.textContent = msg;
            log.appendChild(line);
            log.scrollTop = log.scrollHeight;
        }
        
        async function checkForUpdates() {
            setStatus('check-status', 'loading', 'Checking for updates...');
            try {
                const resp = await fetch('?action=check');
                const data = await resp.json();
                
                if (data.ok && data.latest) {
                    const ver = data.latest.version || data.latest.filename || 'available';
                    document.getElementById('latest-version').textContent = ver;
                    document.getElementById('latest-version').classList.remove('unknown');
                    
                    if (data.up_to_date) {
                        setStatus('check-status', 'success', 'Installed version matches latest cached release.');
                    } else {
                        const cv = data.current_version || 'unknown';
                        setStatus('check-status', 'info', 'A newer cached release is available. Installed: ' + cv + ' | Latest: ' + ver);
                    }
                    const setVersionCmd = document.getElementById('set-version-cmd');
                    if (setVersionCmd) {
                        setVersionCmd.textContent = `echo "${ver}" | sudo tee /web/html/VERSION >/dev/null`;
                    }

                    const hist = document.getElementById('release-history');
                    hist.innerHTML = '';
                    const rows = Array.isArray(data.history) ? data.history : [];
                    if (rows.length === 0) {
                        const li = document.createElement('li');
                        li.textContent = 'No releases found in /web/private/releases/html';
                        hist.appendChild(li);
                    } else {
                        for (const row of rows) {
                            const li = document.createElement('li');
                            const dt = row.mtime ? new Date(row.mtime * 1000).toISOString() : '';
                            const bytes = row.bytes || 0;
                            li.textContent = `${row.version || row.filename}  (${bytes} bytes)  ${dt}`;
                            hist.appendChild(li);
                        }
                    }
                } else {
                    const err = data.error || 'No release found';
                    setStatus('check-status', 'error', 'Could not check for updates: ' + err);
                    document.getElementById('latest-version').textContent = '—';
                    document.getElementById('latest-version').classList.add('unknown');
                }
            } catch (e) {
                setStatus('check-status', 'error', 'Network error: ' + e.message);
            }
        }
        
        function selectedTargets() {
            const checkboxes = document.querySelectorAll('input[name="targets[]"]:checked');
            return Array.from(checkboxes).map(cb => cb.value);
        }

        function renderManualCommands(text) {
            const box = document.getElementById('manual-commands');
            if (!text) {
                box.textContent = '';
                box.classList.remove('visible');
                return;
            }
            box.textContent = text;
            box.classList.add('visible');
        }

        async function runPreview() {
            const btn = document.getElementById('preview-btn');
            const log = document.getElementById('update-log');
            log.innerHTML = '';
            log.classList.add('visible');
            renderManualCommands('');

            const targets = selectedTargets();
            if (targets.length === 0) {
                setStatus('update-status', 'error', 'Please select at least one target.');
                return;
            }

            btn.disabled = true;
            setStatus('update-status', 'loading', 'Building preview...');
            logLine('Previewing selected targets: ' + targets.join(', '));

            try {
                const formData = new FormData();
                formData.append('action', 'preview');
                targets.forEach(t => formData.append('targets[]', t));

                const resp = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                const data = await resp.json();

                if (!data.ok) {
                    setStatus('update-status', 'error', 'Preview failed: ' + (data.error || 'unknown'));
                    logLine('Preview error: ' + (data.error || 'unknown'), 'err');
                    return;
                }

                const preview = data.preview || {};
                let totalChanged = 0;
                for (const [key, result] of Object.entries(preview)) {
                    if (result && result.ok) {
                        const changed = Number(result.changed || 0);
                        totalChanged += changed;
                        logLine(`• ${key}: ${changed} files (new ${result.created || 0}, updated ${result.updated || 0})`, changed > 0 ? 'ok' : '');
                    } else {
                        logLine(`• ${key}: preview unavailable (${(result && result.error) || 'unknown'})`, 'err');
                    }
                }
                setStatus('update-status', 'info', `Preview complete: ${totalChanged} file changes.`);
                if (data.manual_commands) renderManualCommands(data.manual_commands);
            } catch (e) {
                setStatus('update-status', 'error', 'Preview failed: ' + e.message);
                logLine('Error: ' + e.message, 'err');
            } finally {
                btn.disabled = false;
            }
        }

        // Auto-check on load
        checkForUpdates();
    </script>
</body>
</html>
