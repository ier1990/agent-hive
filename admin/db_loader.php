<?php
// /admin/db_loader.php
// Bootstrap Tool: Loads tool definitions from JSON into SQLite
// Creates the agent_tools database that powers v1/agent/

declare(strict_types=0);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth/auth.php';

auth_require_admin();

// ---- Config ----
define('AGENT_DB_PATH', PRIVATE_ROOT . '/db/agent_tools.db');
define('TOOLS_JSON_PATH', __DIR__ . '/defaults/agent_tools.json');

// ---- Database Setup ----

function agent_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    
    $dir = dirname(AGENT_DB_PATH);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    
    $pdo = new PDO('sqlite:' . AGENT_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Create schema
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS tools (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            description TEXT NOT NULL,
            keywords TEXT DEFAULT "",
            parameters_schema TEXT DEFAULT "{}",
            code TEXT NOT NULL,
            language TEXT DEFAULT "php",
            is_approved INTEGER DEFAULT 0,
            is_ai_generated INTEGER DEFAULT 0,
            run_count INTEGER DEFAULT 0,
            last_run_at TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE INDEX IF NOT EXISTS idx_tools_name ON tools(name);
        CREATE INDEX IF NOT EXISTS idx_tools_keywords ON tools(keywords);
        CREATE INDEX IF NOT EXISTS idx_tools_approved ON tools(is_approved);
    ');
    
    // Execution log for auditing
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS tool_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tool_id INTEGER,
            tool_name TEXT,
            input_hash TEXT,
            input_preview TEXT,
            output_preview TEXT,
            success INTEGER,
            duration_ms INTEGER,
            client_ip TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE INDEX IF NOT EXISTS idx_runs_tool ON tool_runs(tool_id);
        CREATE INDEX IF NOT EXISTS idx_runs_time ON tool_runs(created_at);
    ');
    
    return $pdo;
}

function load_tools_from_json(string $jsonPath): array {
    if (!is_readable($jsonPath)) {
        return ['ok' => false, 'error' => 'JSON file not found: ' . $jsonPath];
    }
    
    $raw = @file_get_contents($jsonPath);
    if (!is_string($raw) || $raw === '') {
        return ['ok' => false, 'error' => 'Cannot read JSON file'];
    }
    
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['ok' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()];
    }
    
    $tools = $data['tools'] ?? $data;
    if (!is_array($tools)) {
        return ['ok' => false, 'error' => 'JSON must contain a "tools" array'];
    }
    
    return ['ok' => true, 'tools' => $tools];
}

function upsert_tool(array $tool): array {
    $name = trim($tool['name'] ?? '');
    if ($name === '') {
        return ['ok' => false, 'error' => 'Tool name required'];
    }
    
    $description = trim($tool['description'] ?? '');
    $keywords = trim($tool['keywords'] ?? '');
    $code = trim($tool['code'] ?? '');
    $language = trim($tool['language'] ?? 'php');
    $paramsSchema = $tool['parameters_schema'] ?? $tool['parameters'] ?? [];
    $isApproved = (int)($tool['is_approved'] ?? 1); // Manual loads are approved by default
    $isAiGenerated = (int)($tool['is_ai_generated'] ?? 0);
    
    if ($code === '') {
        return ['ok' => false, 'error' => 'Tool code required'];
    }
    
    $pdo = agent_db();
    
    // Check if exists
    $stmt = $pdo->prepare('SELECT id FROM tools WHERE name = ?');
    $stmt->execute([$name]);
    $existing = $stmt->fetch();
    
    $paramsJson = is_string($paramsSchema) ? $paramsSchema : json_encode($paramsSchema);
    
    if ($existing) {
        $stmt = $pdo->prepare('
            UPDATE tools SET 
                description = ?,
                keywords = ?,
                parameters_schema = ?,
                code = ?,
                language = ?,
                is_approved = ?,
                is_ai_generated = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE name = ?
        ');
        $stmt->execute([$description, $keywords, $paramsJson, $code, $language, $isApproved, $isAiGenerated, $name]);
        return ['ok' => true, 'action' => 'updated', 'name' => $name];
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO tools (name, description, keywords, parameters_schema, code, language, is_approved, is_ai_generated)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$name, $description, $keywords, $paramsJson, $code, $language, $isApproved, $isAiGenerated]);
        return ['ok' => true, 'action' => 'created', 'name' => $name];
    }
}

function list_tools(): array {
    $pdo = agent_db();
    $stmt = $pdo->query('SELECT id, name, description, keywords, language, is_approved, is_ai_generated, run_count, created_at, updated_at FROM tools ORDER BY name');
    return $stmt->fetchAll();
}

function get_tool(string $name): ?array {
    $pdo = agent_db();
    $stmt = $pdo->prepare('SELECT * FROM tools WHERE name = ?');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function delete_tool(string $name): bool {
    $pdo = agent_db();
    $stmt = $pdo->prepare('DELETE FROM tools WHERE name = ?');
    $stmt->execute([$name]);
    return $stmt->rowCount() > 0;
}

function toggle_approval(string $name): array {
    $pdo = agent_db();
    $stmt = $pdo->prepare('UPDATE tools SET is_approved = 1 - is_approved, updated_at = CURRENT_TIMESTAMP WHERE name = ?');
    $stmt->execute([$name]);
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'error' => 'Tool not found'];
    }
    $tool = get_tool($name);
    return ['ok' => true, 'name' => $name, 'is_approved' => (int)($tool['is_approved'] ?? 0)];
}

// ---- Request Handling ----

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// AJAX endpoints
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    
    if ($action === 'list') {
        $tools = list_tools();
        echo json_encode(['ok' => true, 'tools' => $tools], JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    if ($action === 'load_json' && $method === 'POST') {
        $jsonPath = $_POST['json_path'] ?? TOOLS_JSON_PATH;
        $result = load_tools_from_json($jsonPath);
        if (!$result['ok']) {
            echo json_encode($result);
            exit;
        }
        
        $loaded = 0;
        $errors = [];
        foreach ($result['tools'] as $tool) {
            $r = upsert_tool($tool);
            if ($r['ok']) {
                $loaded++;
            } else {
                $errors[] = ($tool['name'] ?? '?') . ': ' . ($r['error'] ?? 'unknown');
            }
        }
        
        echo json_encode(['ok' => true, 'loaded' => $loaded, 'errors' => $errors]);
        exit;
    }
    
    if ($action === 'upsert' && $method === 'POST') {
        $tool = [
            'name' => $_POST['name'] ?? '',
            'description' => $_POST['description'] ?? '',
            'keywords' => $_POST['keywords'] ?? '',
            'code' => $_POST['code'] ?? '',
            'language' => $_POST['language'] ?? 'php',
            'parameters_schema' => $_POST['parameters_schema'] ?? '{}',
            'is_approved' => isset($_POST['is_approved']) ? (int)$_POST['is_approved'] : 1,
        ];
        $result = upsert_tool($tool);
        echo json_encode($result);
        exit;
    }
    
    if ($action === 'get') {
        $name = $_GET['name'] ?? '';
        $tool = get_tool($name);
        if ($tool) {
            echo json_encode(['ok' => true, 'tool' => $tool]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Tool not found']);
        }
        exit;
    }
    
    if ($action === 'delete' && $method === 'POST') {
        $name = $_POST['name'] ?? '';
        if (delete_tool($name)) {
            echo json_encode(['ok' => true, 'deleted' => $name]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Tool not found']);
        }
        exit;
    }
    
    if ($action === 'toggle_approval' && $method === 'POST') {
        $name = $_POST['name'] ?? '';
        $result = toggle_approval($name);
        echo json_encode($result);
        exit;
    }
    
    if ($action === 'upload_json' && $method === 'POST') {
        if (!isset($_FILES['json_file']) || $_FILES['json_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => 'No file uploaded or upload error']);
            exit;
        }
        
        $file = $_FILES['json_file'];
        if ($file['type'] !== 'application/json') {
            echo json_encode(['ok' => false, 'error' => 'File must be JSON type']);
            exit;
        }
        
        $raw = @file_get_contents($file['tmp_name']);
        if (!is_string($raw) || $raw === '') {
            echo json_encode(['ok' => false, 'error' => 'Cannot read uploaded file']);
            exit;
        }
        
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
            exit;
        }
        
        $tools = $data['tools'] ?? $data;
        if (!is_array($tools)) {
            echo json_encode(['ok' => false, 'error' => 'JSON must contain a "tools" array']);
            exit;
        }
        
        $loaded = 0;
        $errors = [];
        foreach ($tools as $tool) {
            $r = upsert_tool($tool);
            if ($r['ok']) {
                $loaded++;
            } else {
                $errors[] = ($tool['name'] ?? '?') . ': ' . ($r['error'] ?? 'unknown');
            }
        }
        
        echo json_encode(['ok' => true, 'loaded' => $loaded, 'errors' => $errors]);
        exit;
    }
    
    if ($action === 'export') {
        $tools = list_tools();
        // Get full code for each
        $export = [];
        foreach ($tools as $t) {
            $full = get_tool($t['name']);
            if ($full) {
                $export[] = [
                    'name' => $full['name'],
                    'description' => $full['description'],
                    'keywords' => $full['keywords'],
                    'parameters_schema' => json_decode($full['parameters_schema'] ?: '{}', true),
                    'code' => $full['code'],
                    'language' => $full['language'],
                    'is_approved' => (int)$full['is_approved'],
                ];
            }
        }
        echo json_encode(['tools' => $export], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
    
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ---- Render HTML UI ----

$tools = list_tools();
$dbExists = is_file(AGENT_DB_PATH);
$jsonExists = is_file(TOOLS_JSON_PATH);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Tools DB Loader</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0d1117;
            color: #c9d1d9;
            margin: 0;
            padding: 20px;
            line-height: 1.6;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #58a6ff; border-bottom: 1px solid #30363d; padding-bottom: 10px; }
        h2 { color: #8b949e; margin-top: 30px; }
        .card {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .status { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; }
        .status.ok { background: #238636; color: white; }
        .status.warn { background: #9e6a03; color: white; }
        .status.err { background: #da3633; color: white; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #30363d; }
        th { color: #8b949e; font-weight: 500; }
        tr:hover { background: #1c2128; }
        .btn {
            display: inline-block;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-primary { background: #238636; color: white; }
        .btn-primary:hover { background: #2ea043; }
        .btn-secondary { background: #21262d; color: #c9d1d9; border: 1px solid #30363d; }
        .btn-secondary:hover { background: #30363d; }
        .btn-danger { background: #da3633; color: white; }
        .btn-danger:hover { background: #f85149; }
        .btn-sm { padding: 4px 8px; font-size: 12px; }
        .btn-group { display: flex; gap: 8px; flex-wrap: wrap; margin: 16px 0; }
        input, textarea, select {
            background: #0d1117;
            border: 1px solid #30363d;
            color: #c9d1d9;
            padding: 8px 12px;
            border-radius: 6px;
            font-family: inherit;
            font-size: 14px;
            width: 100%;
        }
        input:focus, textarea:focus { outline: none; border-color: #58a6ff; }
        textarea { min-height: 200px; font-family: 'Monaco', 'Menlo', monospace; font-size: 13px; }
        label { display: block; margin-bottom: 4px; color: #8b949e; font-size: 13px; }
        .form-group { margin-bottom: 16px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal.active { display: flex; }
        .modal-content {
            background: #161b22;
            border: 1px solid #30363d;
            border-radius: 8px;
            padding: 24px;
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .modal-header h3 { margin: 0; color: #58a6ff; }
        .close { background: none; border: none; color: #8b949e; font-size: 24px; cursor: pointer; }
        .close:hover { color: #c9d1d9; }
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 6px;
            color: white;
            font-size: 14px;
            z-index: 1001;
            animation: slideIn 0.3s ease;
        }
        .toast.success { background: #238636; }
        .toast.error { background: #da3633; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        code { background: #0d1117; padding: 2px 6px; border-radius: 4px; font-family: monospace; }
        .mono { font-family: 'Monaco', 'Menlo', monospace; font-size: 12px; }
        .approved { color: #3fb950; }
        .pending { color: #d29922; }
        .ai-badge { background: #8957e5; color: white; padding: 2px 6px; border-radius: 4px; font-size: 11px; }
        a { color: #58a6ff; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧠 Agent Tools Database</h1>
        
        <div class="card">
            <h2 style="margin-top:0">System Status</h2>
            <p>
                <strong>Database:</strong> 
                <?php if ($dbExists): ?>
                    <span class="status ok">Ready</span> <code class="mono"><?= htmlspecialchars(AGENT_DB_PATH) ?></code>
                <?php else: ?>
                    <span class="status warn">Will be created</span>
                <?php endif; ?>
            </p>
            <p>
                <strong>Tools JSON:</strong>
                <?php if ($jsonExists): ?>
                    <span class="status ok">Found</span> <code class="mono"><?= htmlspecialchars(TOOLS_JSON_PATH) ?></code>
                <?php else: ?>
                    <span class="status warn">Not found</span> - Create at <code><?= htmlspecialchars(TOOLS_JSON_PATH) ?></code>
                <?php endif; ?>
            </p>
            <p><strong>Tools loaded:</strong> <?= count($tools) ?></p>
            
            <div class="btn-group">
                <button class="btn btn-primary" onclick="loadFromJson()">📥 Load Defaults</button>
                <button class="btn btn-secondary" onclick="importJsonFile()">📂 Import JSON File</button>
                <button class="btn btn-secondary" onclick="openEditor()">➕ Add Tool</button>
                <button class="btn btn-secondary" onclick="exportTools()">📤 Export All</button>
            </div>
            <input type="file" id="json-file-input" style="display:none" accept=".json" onchange="handleJsonFileUpload(event)">
        </div>
        
        <div class="card">
            <h2 style="margin-top:0">Registered Tools</h2>
            <table id="tools-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Language</th>
                        <th>Status</th>
                        <th>Runs</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tools)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#8b949e;padding:40px;">No tools registered yet. Load from JSON or add manually.</td></tr>
                    <?php else: ?>
                    <?php foreach ($tools as $t): ?>
                    <tr data-name="<?= htmlspecialchars($t['name']) ?>">
                        <td>
                            <strong><?= htmlspecialchars($t['name']) ?></strong>
                            <?php if ($t['is_ai_generated']): ?><span class="ai-badge">AI</span><?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars(substr($t['description'], 0, 60)) ?><?= strlen($t['description']) > 60 ? '...' : '' ?></td>
                        <td><code><?= htmlspecialchars($t['language']) ?></code></td>
                        <td>
                            <?php if ($t['is_approved']): ?>
                                <span class="approved">✓ Approved</span>
                            <?php else: ?>
                                <span class="pending">⏳ Pending</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$t['run_count'] ?></td>
                        <td>
                            <button class="btn btn-secondary btn-sm" onclick="editTool('<?= htmlspecialchars($t['name']) ?>')">Edit</button>
                            <button class="btn btn-secondary btn-sm" onclick="toggleApproval('<?= htmlspecialchars($t['name']) ?>')">
                                <?= $t['is_approved'] ? 'Revoke' : 'Approve' ?>
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="deleteTool('<?= htmlspecialchars($t['name']) ?>')">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <p style="margin-top: 40px; color: #8b949e; font-size: 13px;">
            <a href="/admin/">← Back to Admin</a> |
            <a href="/v1/agent/">Test Agent Endpoint →</a>
        </p>
    </div>
    
    <!-- Tool Editor Modal -->
    <div class="modal" id="editor-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="editor-title">Add Tool</h3>
                <button class="close" onclick="closeEditor()">&times;</button>
            </div>
            <form id="tool-form" onsubmit="saveTool(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label for="tool-name">Name (unique identifier)</label>
                        <input type="text" id="tool-name" name="name" required placeholder="get_weather">
                    </div>
                    <div class="form-group">
                        <label for="tool-language">Language</label>
                        <select id="tool-language" name="language">
                            <option value="php">PHP</option>
                            <option value="python">Python</option>
                            <option value="bash">Bash</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="tool-description">Description (what this tool does)</label>
                    <input type="text" id="tool-description" name="description" required placeholder="Fetches current weather for a given city">
                </div>
                <div class="form-group">
                    <label for="tool-keywords">Keywords (comma-separated, for matching)</label>
                    <input type="text" id="tool-keywords" name="keywords" placeholder="weather, temperature, forecast, climate">
                </div>
                <div class="form-group">
                    <label for="tool-params">Parameters Schema (JSON)</label>
                    <textarea id="tool-params" name="parameters_schema" style="min-height:80px">{}</textarea>
                </div>
                <div class="form-group">
                    <label for="tool-code">Code</label>
                    <textarea id="tool-code" name="code" required placeholder="// Your tool code here..."></textarea>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="tool-approved" name="is_approved" value="1" checked>
                        Approved for execution
                    </label>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">Save Tool</button>
                    <button type="button" class="btn btn-secondary" onclick="closeEditor()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function toast(msg, type) {
            const t = document.createElement('div');
            t.className = 'toast ' + type;
            t.textContent = msg;
            document.body.appendChild(t);
            setTimeout(() => t.remove(), 3000);
        }
        
        async function loadFromJson() {
            if (!confirm('Load tools from JSON file? This will update existing tools with same name.')) return;
            try {
                const resp = await fetch('?', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=load_json'
                });
                const data = await resp.json();
                if (data.ok) {
                    toast(`Loaded ${data.loaded} tools`, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    toast(data.error || 'Load failed', 'error');
                }
            } catch (e) {
                toast('Error: ' + e.message, 'error');
            }
        }
        
        function openEditor(tool) {
            document.getElementById('editor-title').textContent = tool ? 'Edit Tool' : 'Add Tool';
            document.getElementById('tool-name').value = tool?.name || '';
            document.getElementById('tool-name').disabled = !!tool;
            document.getElementById('tool-description').value = tool?.description || '';
            document.getElementById('tool-keywords').value = tool?.keywords || '';
            document.getElementById('tool-language').value = tool?.language || 'php';
            document.getElementById('tool-params').value = typeof tool?.parameters_schema === 'string' 
                ? tool.parameters_schema 
                : JSON.stringify(tool?.parameters_schema || {}, null, 2);
            document.getElementById('tool-code').value = tool?.code || '';
            document.getElementById('tool-approved').checked = tool ? !!tool.is_approved : true;
            document.getElementById('editor-modal').classList.add('active');
        }
        
        function closeEditor() {
            document.getElementById('editor-modal').classList.remove('active');
        }
        
        async function editTool(name) {
            try {
                const resp = await fetch('?action=get&name=' + encodeURIComponent(name));
                const data = await resp.json();
                if (data.ok && data.tool) {
                    openEditor(data.tool);
                } else {
                    toast('Tool not found', 'error');
                }
            } catch (e) {
                toast('Error: ' + e.message, 'error');
            }
        }
        
        async function saveTool(e) {
            e.preventDefault();
            const form = document.getElementById('tool-form');
            const formData = new FormData(form);
            formData.append('action', 'upsert');
            if (!document.getElementById('tool-approved').checked) {
                formData.set('is_approved', '0');
            }
            
            try {
                const resp = await fetch('?', {
                    method: 'POST',
                    body: new URLSearchParams(formData)
                });
                const data = await resp.json();
                if (data.ok) {
                    toast(`Tool ${data.action}: ${data.name}`, 'success');
                    closeEditor();
                    setTimeout(() => location.reload(), 500);
                } else {
                    toast(data.error || 'Save failed', 'error');
                }
            } catch (e) {
                toast('Error: ' + e.message, 'error');
            }
        }
        
        async function deleteTool(name) {
            if (!confirm(`Delete tool "${name}"? This cannot be undone.`)) return;
            try {
                const resp = await fetch('?', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=delete&name=' + encodeURIComponent(name)
                });
                const data = await resp.json();
                if (data.ok) {
                    toast('Tool deleted', 'success');
                    document.querySelector(`tr[data-name="${name}"]`)?.remove();
                } else {
                    toast(data.error || 'Delete failed', 'error');
                }
            } catch (e) {
                toast('Error: ' + e.message, 'error');
            }
        }
        
        async function toggleApproval(name) {
            try {
                const resp = await fetch('?', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=toggle_approval&name=' + encodeURIComponent(name)
                });
                const data = await resp.json();
                if (data.ok) {
                    toast(`Tool ${data.is_approved ? 'approved' : 'revoked'}`, 'success');
                    setTimeout(() => location.reload(), 500);
                } else {
                    toast(data.error || 'Toggle failed', 'error');
                }
            } catch (e) {
                toast('Error: ' + e.message, 'error');
            }
        }
        
        function exportTools() {
            window.open('?action=export', '_blank');
        }
        
        function importJsonFile() {
            document.getElementById('json-file-input').click();
        }
        
        async function handleJsonFileUpload(e) {
            const file = e.target.files[0];
            if (!file) return;
            
            if (!file.type.includes('json') && !file.name.endsWith('.json')) {
                toast('Please select a JSON file', 'error');
                e.target.value = '';
                return;
            }
            
            if (!confirm(`Import tools from "${file.name}"? This will update existing tools with same name.`)) {
                e.target.value = '';
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'upload_json');
            formData.append('json_file', file);
            
            try {
                const resp = await fetch('?', {
                    method: 'POST',
                    body: formData
                });
                const data = await resp.json();
                if (data.ok) {
                    toast(`Imported ${data.loaded} tools`, 'success');
                    if (data.errors.length > 0) {
                        console.warn('Import errors:', data.errors);
                    }
                    setTimeout(() => location.reload(), 1000);
                } else {
                    toast(data.error || 'Import failed', 'error');
                }
            } catch (err) {
                toast('Error: ' + err.message, 'error');
            } finally {
                e.target.value = '';
            }
        }
    </script>
</body>
</html>
