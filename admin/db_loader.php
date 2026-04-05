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

function tool_allowed_statuses(): array {
    return ['draft', 'registered', 'approved', 'disabled', 'deprecated', 'rejected', 'superseded'];
}

function tool_allowed_source_types(): array {
    return ['human', 'ai', 'imported'];
}

function normalize_tool_status($status, string $fallback = 'registered'): string {
    $value = strtolower(trim((string)$status));
    return in_array($value, tool_allowed_statuses(), true) ? $value : $fallback;
}

function normalize_tool_source_type($sourceType, string $fallback = 'human'): string {
    $value = strtolower(trim((string)$sourceType));
    return in_array($value, tool_allowed_source_types(), true) ? $value : $fallback;
}

function tool_status_is_approved(string $status): bool {
    return normalize_tool_status($status) === 'approved';
}

function tool_table_has_column(PDO $pdo, string $table, string $column): bool {
    $rows = $pdo->query("PRAGMA table_info(" . $table . ")")->fetchAll();
    foreach ($rows as $row) {
        if (isset($row['name']) && strcasecmp((string)$row['name'], $column) === 0) {
            return true;
        }
    }
    return false;
}

function ensure_agent_tools_schema(PDO $pdo): void {
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
        )
    ');

    $columns = [
        'status' => "ALTER TABLE tools ADD COLUMN status TEXT DEFAULT 'registered'",
        'approved_by' => "ALTER TABLE tools ADD COLUMN approved_by TEXT DEFAULT ''",
        'approved_at' => "ALTER TABLE tools ADD COLUMN approved_at TEXT",
        'review_notes' => "ALTER TABLE tools ADD COLUMN review_notes TEXT DEFAULT ''",
        'replaces_tool_id' => "ALTER TABLE tools ADD COLUMN replaces_tool_id INTEGER",
        'source_type' => "ALTER TABLE tools ADD COLUMN source_type TEXT DEFAULT 'human'",
        'lineage_key' => "ALTER TABLE tools ADD COLUMN lineage_key TEXT DEFAULT ''",
    ];
    foreach ($columns as $column => $sql) {
        if (!tool_table_has_column($pdo, 'tools', $column)) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec("UPDATE tools SET status = CASE WHEN COALESCE(is_approved, 0) = 1 THEN 'approved' ELSE 'registered' END WHERE COALESCE(TRIM(status), '') = ''");
    $pdo->exec("UPDATE tools SET source_type = CASE WHEN COALESCE(is_ai_generated, 0) = 1 THEN 'ai' ELSE 'human' END WHERE COALESCE(TRIM(source_type), '') = ''");
    $pdo->exec("UPDATE tools SET approved_at = COALESCE(approved_at, updated_at, created_at) WHERE status = 'approved' AND approved_at IS NULL");
    $pdo->exec("UPDATE tools SET is_approved = CASE WHEN status = 'approved' THEN 1 ELSE 0 END");

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tools_name ON tools(name)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tools_keywords ON tools(keywords)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tools_approved ON tools(is_approved)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tools_status ON tools(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tools_source_type ON tools(source_type)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tools_lineage_key ON tools(lineage_key)');
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
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_tool ON tool_runs(tool_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_time ON tool_runs(created_at)');
}

// ---- Database Setup ----

function templates_db(): SQLite3 {
    static $db = null;
    if ($db !== null) return $db;
    
    $path = PRIVATE_ROOT . '/db/memory/ai_header.db';
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    
    $db = new SQLite3($path);
    $db->exec('CREATE TABLE IF NOT EXISTS ai_header_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        type TEXT DEFAULT "payload",
        template_text TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    return $db;
}

function agent_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    
    $dir = dirname(AGENT_DB_PATH);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    
    $pdo = new PDO('sqlite:' . AGENT_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    ensure_agent_tools_schema($pdo);
    
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
    $sourceType = normalize_tool_source_type($tool['source_type'] ?? ((int)($tool['is_ai_generated'] ?? 0) === 1 ? 'ai' : 'human'));
    $defaultStatus = $sourceType === 'ai' ? 'draft' : 'registered';
    $status = normalize_tool_status($tool['status'] ?? (((int)($tool['is_approved'] ?? -1) === 1) ? 'approved' : $defaultStatus), $defaultStatus);
    $isApproved = tool_status_is_approved($status) ? 1 : 0;
    $isAiGenerated = (int)($tool['is_ai_generated'] ?? 0);
    $approvedBy = trim((string)($tool['approved_by'] ?? ''));
    $approvedAt = trim((string)($tool['approved_at'] ?? ''));
    $reviewNotes = trim((string)($tool['review_notes'] ?? ''));
    $replacesToolId = isset($tool['replaces_tool_id']) && $tool['replaces_tool_id'] !== '' ? (int)$tool['replaces_tool_id'] : null;
    $lineageKey = trim((string)($tool['lineage_key'] ?? $tool['draft_group'] ?? ''));
    $currentUser = (string)($_SERVER['PHP_AUTH_USER'] ?? $_SERVER['REMOTE_USER'] ?? 'admin');
    if ($isApproved === 1 && $approvedBy === '') {
        $approvedBy = $currentUser;
    }
    if ($isApproved === 1 && $approvedAt === '') {
        $approvedAt = gmdate('Y-m-d H:i:s');
    }
    
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
                status = ?,
                is_approved = ?,
                is_ai_generated = ?,
                approved_by = ?,
                approved_at = ?,
                review_notes = ?,
                replaces_tool_id = ?,
                source_type = ?,
                lineage_key = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE name = ?
        ');
        $stmt->execute([$description, $keywords, $paramsJson, $code, $language, $status, $isApproved, $isAiGenerated, $approvedBy, $approvedAt !== '' ? $approvedAt : null, $reviewNotes, $replacesToolId, $sourceType, $lineageKey, $name]);
        return ['ok' => true, 'action' => 'updated', 'name' => $name, 'status' => $status];
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO tools (name, description, keywords, parameters_schema, code, language, status, is_approved, is_ai_generated, approved_by, approved_at, review_notes, replaces_tool_id, source_type, lineage_key)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$name, $description, $keywords, $paramsJson, $code, $language, $status, $isApproved, $isAiGenerated, $approvedBy, $approvedAt !== '' ? $approvedAt : null, $reviewNotes, $replacesToolId, $sourceType, $lineageKey]);
        return ['ok' => true, 'action' => 'created', 'name' => $name, 'status' => $status];
    }
}

function list_tools(): array {
    $pdo = agent_db();
    $stmt = $pdo->query('SELECT id, name, description, keywords, language, status, is_approved, is_ai_generated, approved_by, approved_at, review_notes, replaces_tool_id, source_type, lineage_key, run_count, created_at, updated_at FROM tools ORDER BY name');
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

function set_tool_status(string $name, string $status, string $reviewNotes = ''): array {
    $pdo = agent_db();
    $status = normalize_tool_status($status, 'registered');
    $approved = tool_status_is_approved($status) ? 1 : 0;
    $approvedBy = $approved ? (string)($_SERVER['PHP_AUTH_USER'] ?? $_SERVER['REMOTE_USER'] ?? 'admin') : '';
    $approvedAt = $approved ? gmdate('Y-m-d H:i:s') : null;
    $sql = 'UPDATE tools SET status = ?, is_approved = ?, updated_at = CURRENT_TIMESTAMP';
    $params = [$status, $approved];
    if ($approved) {
        $sql .= ', approved_by = ?, approved_at = ?';
        $params[] = $approvedBy;
        $params[] = $approvedAt;
    }
    if ($reviewNotes !== '') {
        $sql .= ', review_notes = ?';
        $params[] = $reviewNotes;
    }
    $sql .= ' WHERE name = ?';
    $params[] = $name;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'error' => 'Tool not found'];
    }
    $tool = get_tool($name);
    return ['ok' => true, 'name' => $name, 'status' => (string)($tool['status'] ?? 'registered'), 'is_approved' => (int)($tool['is_approved'] ?? 0)];
}

// ---- Template Functions ----

function load_templates_from_json(string $jsonPath): array {
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
    
    // Handle both {templates:[]} and {items:[]} formats
    $templates = $data['templates'] ?? $data['items'] ?? $data;
    if (!is_array($templates)) {
        return ['ok' => false, 'error' => 'JSON must contain templates array'];
    }
    
    $loaded = 0;
    $errors = [];
    $db = templates_db();
    
    foreach ($templates as $tpl) {
        $name = trim($tpl['name'] ?? '');
        $type = trim($tpl['type'] ?? 'payload');
        $text = $tpl['template_text'] ?? $tpl['text'] ?? '';
        
        if ($name === '' || $text === '') {
            $errors[] = 'Skipped invalid template (missing name or text)';
            continue;
        }
        
        try {
            $stmt = $db->prepare('INSERT OR REPLACE INTO ai_header_templates (name, type, template_text, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)');
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':type', $type, SQLITE3_TEXT);
            $stmt->bindValue(':text', $text, SQLITE3_TEXT);
            // Use question marks since bindValue doesn't work as expected
            $db->exec("INSERT OR REPLACE INTO ai_header_templates (name, type, template_text, created_at) VALUES ('$name', '$type', '" . $db->escapeString($text) . "', CURRENT_TIMESTAMP)");
            $loaded++;
        } catch (Throwable $e) {
            $errors[] = $name . ': ' . $e->getMessage();
        }
    }
    
    $db->close();
    return ['ok' => true, 'loaded' => $loaded, 'errors' => $errors];
}

function list_templates(): array {
    $db = templates_db();
    $res = $db->query('SELECT name, type, created_at FROM ai_header_templates ORDER BY name');
    $out = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $out[] = $row;
    }
    $db->close();
    return $out;
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
            'status' => $_POST['status'] ?? '',
            'parameters_schema' => $_POST['parameters_schema'] ?? '{}',
            'approved_by' => $_POST['approved_by'] ?? '',
            'approved_at' => $_POST['approved_at'] ?? '',
            'review_notes' => $_POST['review_notes'] ?? '',
            'replaces_tool_id' => $_POST['replaces_tool_id'] ?? '',
            'source_type' => $_POST['source_type'] ?? '',
            'lineage_key' => $_POST['lineage_key'] ?? '',
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
        $current = get_tool($name);
        if (!$current) {
            echo json_encode(['ok' => false, 'error' => 'Tool not found']);
            exit;
        }
        $currentStatus = normalize_tool_status($current['status'] ?? (((int)($current['is_approved'] ?? 0) === 1) ? 'approved' : 'registered'));
        $nextStatus = $currentStatus === 'approved' ? 'registered' : 'approved';
        $result = set_tool_status($name, $nextStatus);
        echo json_encode($result);
        exit;
    }

    if ($action === 'set_status' && $method === 'POST') {
        $name = $_POST['name'] ?? '';
        $status = $_POST['status'] ?? 'registered';
        $reviewNotes = $_POST['review_notes'] ?? '';
        $result = set_tool_status($name, $status, $reviewNotes);
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
                    'status' => $full['status'] ?? (((int)$full['is_approved'] === 1) ? 'approved' : 'registered'),
                    'is_approved' => (int)$full['is_approved'],
                    'approved_by' => $full['approved_by'] ?? '',
                    'approved_at' => $full['approved_at'] ?? '',
                    'review_notes' => $full['review_notes'] ?? '',
                    'replaces_tool_id' => isset($full['replaces_tool_id']) ? (int)$full['replaces_tool_id'] : null,
                    'source_type' => $full['source_type'] ?? ((int)$full['is_ai_generated'] === 1 ? 'ai' : 'human'),
                    'lineage_key' => $full['lineage_key'] ?? '',
                ];
            }
        }
        echo json_encode(['tools' => $export], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
    
    // ---- Template Actions ----
    if ($action === 'load_templates' && $method === 'POST') {
        $jsonPath = $_POST['json_path'] ?? '';
        if ($jsonPath === '') {
            echo json_encode(['ok' => false, 'error' => 'No JSON path provided']);
            exit;
        }
        $result = load_templates_from_json($jsonPath);
        echo json_encode($result);
        exit;
    }
    
    if ($action === 'list_templates' && $method === 'GET') {
        $templates = list_templates();
        echo json_encode(['ok' => true, 'templates' => $templates], JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    if ($action === 'available_template_files' && $method === 'GET') {
        $dir = __DIR__ . '/AI_Story';
        $files = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') as $f) {
                $name = basename($f);
                if (strpos($name, 'story_') === 0 || strpos($name, 'template_') === 0) {
                    $files[] = ['path' => $f, 'name' => $name, 'size' => filesize($f)];
                }
            }
        }
        echo json_encode(['ok' => true, 'files' => $files]);
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
    <title>Agent Toolsmith Workbench</title>
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
        .status-label { display:inline-block; padding: 3px 8px; border-radius: 999px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; }
        .status-approved { color: #bbf7d0; background: rgba(34, 197, 94, 0.16); }
        .status-draft { color: #fde68a; background: rgba(245, 158, 11, 0.16); }
        .status-registered { color: #bfdbfe; background: rgba(59, 130, 246, 0.16); }
        .status-disabled, .status-deprecated, .status-superseded, .status-rejected { color: #fca5a5; background: rgba(239, 68, 68, 0.16); }
        .ai-badge { background: #8957e5; color: white; padding: 2px 6px; border-radius: 4px; font-size: 11px; }
        .source-badge { background: #1f2937; color: #cbd5e1; padding: 2px 6px; border-radius: 4px; font-size: 11px; text-transform: uppercase; }
        a { color: #58a6ff; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧠 Agent Toolsmith Workbench</h1>
        
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
                <strong>Default tools JSON:</strong>
                <?php if ($jsonExists): ?>
                    <span class="status ok">Found</span> <code class="mono"><?= htmlspecialchars(TOOLS_JSON_PATH) ?></code>
                <?php else: ?>
                    <span class="status warn">Not found</span> - Create at <code><?= htmlspecialchars(TOOLS_JSON_PATH) ?></code>
                <?php endif; ?>
            </p>
            <p><strong>Tools registered:</strong> <?= count($tools) ?></p>
            <p style="color:#8b949e; margin-top:8px;">
                Normal runtime execution only allows tools with <code>status = approved</code>.
                AI-created tools should start as <code>draft</code>. Human and imported tools should usually start as <code>registered</code>.
            </p>
            
            <div class="btn-group">
                <button class="btn btn-primary" onclick="loadFromJson()">📥 Load Default Tools</button>
                <button class="btn btn-secondary" onclick="importJsonFile()">📂 Import Tool JSON</button>
                <button class="btn btn-secondary" onclick="openEditor()">➕ Add Tool Draft</button>
                <button class="btn btn-secondary" onclick="exportTools()">📤 Export Tool Registry</button>
            </div>
            <input type="file" id="json-file-input" style="display:none" accept=".json" onchange="handleJsonFileUpload(event)">
        </div>
        
        <div class="card">
            <h2 style="margin-top:0">Tool Registry</h2>
            <p style="color:#8b949e; margin-top:0;">
                Use statuses to move tools through the workflow:
                <code>draft</code>,
                <code>registered</code>,
                <code>approved</code>,
                <code>disabled</code>,
                <code>deprecated</code>,
                <code>rejected</code>,
                <code>superseded</code>.
            </p>
            <table id="tools-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Language</th>
                        <th>Status</th>
                        <th>Review</th>
                        <th>Lineage</th>
                        <th>Runs</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tools)): ?>
                    <tr><td colspan="8" style="text-align:center;color:#8b949e;padding:40px;">No tools registered yet. Load default tools, import JSON, or add a draft manually.</td></tr>
                    <?php else: ?>
                    <?php foreach ($tools as $t): ?>
                    <tr data-name="<?= htmlspecialchars($t['name']) ?>">
                        <td>
                            <strong><?= htmlspecialchars($t['name']) ?></strong>
                            <?php if ($t['is_ai_generated']): ?><span class="ai-badge">AI</span><?php endif; ?>
                            <span class="source-badge"><?= htmlspecialchars($t['source_type'] ?: ($t['is_ai_generated'] ? 'ai' : 'human')) ?></span>
                        </td>
                        <td><?= htmlspecialchars(substr($t['description'], 0, 60)) ?><?= strlen($t['description']) > 60 ? '...' : '' ?></td>
                        <td><code><?= htmlspecialchars($t['language']) ?></code></td>
                        <td>
                            <?php $status = normalize_tool_status($t['status'] ?? (((int)$t['is_approved'] === 1) ? 'approved' : 'registered')); ?>
                            <span class="status-label status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></span>
                        </td>
                        <td>
                            <?php if (!empty($t['approved_by']) || !empty($t['approved_at'])): ?>
                                <div style="font-size:12px; color:#c9d1d9;">
                                    <?= htmlspecialchars((string)($t['approved_by'] ?: 'approved')) ?>
                                </div>
                                <?php if (!empty($t['approved_at'])): ?>
                                    <div style="font-size:11px; color:#8b949e;"><?= htmlspecialchars((string)$t['approved_at']) ?></div>
                                <?php endif; ?>
                            <?php elseif (!empty($t['review_notes'])): ?>
                                <div style="font-size:12px; color:#8b949e;"><?= htmlspecialchars(substr((string)$t['review_notes'], 0, 60)) ?><?= strlen((string)$t['review_notes']) > 60 ? '...' : '' ?></div>
                            <?php else: ?>
                                <span style="color:#8b949e;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($t['lineage_key'])): ?>
                                <div><code><?= htmlspecialchars((string)$t['lineage_key']) ?></code></div>
                            <?php endif; ?>
                            <?php if (!empty($t['replaces_tool_id'])): ?>
                                <div style="font-size:11px; color:#8b949e;">replaces #<?= (int)$t['replaces_tool_id'] ?></div>
                            <?php elseif (empty($t['lineage_key'])): ?>
                                <span style="color:#8b949e;">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$t['run_count'] ?></td>
                        <td>
                            <button class="btn btn-secondary btn-sm" onclick="editTool('<?= htmlspecialchars($t['name']) ?>')">Edit</button>
                            <select class="btn btn-secondary btn-sm" onchange="setStatus('<?= htmlspecialchars($t['name']) ?>', this.value)">
                                <?php foreach (tool_allowed_statuses() as $allowedStatus): ?>
                                    <option value="<?= htmlspecialchars($allowedStatus) ?>"<?= $status === $allowedStatus ? ' selected' : '' ?>><?= htmlspecialchars($allowedStatus) ?></option>
                                <?php endforeach; ?>
                            </select>
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
        
        <div class="card" style="margin-top:40px;">
            <h2 style="margin-top:0">📖 AI Story Templates</h2>
            <p>Load story templates into <code>ai_header.db</code> for the AI Story feature.</p>
            
            <div id="template-files-list" style="margin-bottom:16px;">
                <p style="color:#8b949e;">Scanning for JSON files...</p>
            </div>
            
            <div id="template-status" style="margin-bottom:16px;"></div>
            <div id="templates-list" style="margin-bottom:16px;"></div>
            
            <div class="btn-group">
                <button class="btn btn-primary" onclick="loadAvailableTemplates()">🔄 Discover Templates</button>
                <button class="btn btn-secondary" onclick="listLoadedTemplates()">📋 List Loaded</button>
            </div>
        </div>
    </div>
    
    <!-- Tool Editor Modal -->
    <div class="modal" id="editor-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="editor-title">Add Tool Draft</h3>
                <button class="close" onclick="closeEditor()">&times;</button>
            </div>
            <form id="tool-form" onsubmit="saveTool(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label for="tool-name">Name (unique identifier)</label>
                        <input type="text" id="tool-name" name="name" required placeholder="get_weather">
                        <div style="font-size:11px; color:#8b949e; margin-top:6px;">
                            Existing tool names are locked during edit so status and review changes update the same record.
                        </div>
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
                <div class="form-row">
                    <div class="form-group">
                        <label for="tool-status">Status</label>
                        <select id="tool-status" name="status">
                            <?php foreach (tool_allowed_statuses() as $allowedStatus): ?>
                                <option value="<?= htmlspecialchars($allowedStatus) ?>"><?= htmlspecialchars($allowedStatus) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="tool-source-type">Source Type</label>
                        <select id="tool-source-type" name="source_type">
                            <?php foreach (tool_allowed_source_types() as $allowedSourceType): ?>
                                <option value="<?= htmlspecialchars($allowedSourceType) ?>"><?= htmlspecialchars($allowedSourceType) ?></option>
                            <?php endforeach; ?>
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
                <div class="form-row">
                    <div class="form-group">
                        <label for="tool-replaces-tool-id">Replaces Tool ID (optional)</label>
                        <input type="number" id="tool-replaces-tool-id" name="replaces_tool_id" min="1" placeholder="42">
                    </div>
                    <div class="form-group">
                        <label for="tool-lineage-key">Lineage Key (optional)</label>
                        <input type="text" id="tool-lineage-key" name="lineage_key" placeholder="weather-tools">
                    </div>
                </div>
                <div class="form-group">
                    <label for="tool-review-notes">Review Notes</label>
                    <textarea id="tool-review-notes" name="review_notes" style="min-height:90px" placeholder="Why this draft exists, what it replaces, or why it was approved, rejected, or superseded."></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="tool-approved-by">Approved By</label>
                        <input type="text" id="tool-approved-by" disabled placeholder="Set automatically when status becomes approved">
                    </div>
                    <div class="form-group">
                        <label for="tool-approved-at">Approved At</label>
                        <input type="text" id="tool-approved-at" disabled placeholder="Set automatically when status becomes approved">
                    </div>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">Save Tool Record</button>
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
            if (!confirm('Load tools from the default JSON file? Existing tool records with the same name will be updated, but status remains governed by the lifecycle contract.')) return;
            try {
                const resp = await fetch('?', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=load_json'
                });
                const data = await resp.json();
                if (data.ok) {
                    toast(`Loaded ${data.loaded} tool records`, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    toast(data.error || 'Load failed', 'error');
                }
            } catch (e) {
                toast('Error: ' + e.message, 'error');
            }
        }
        
        function openEditor(tool) {
            document.getElementById('editor-title').textContent = tool ? 'Edit Tool Record' : 'Add Tool Draft';
            document.getElementById('tool-name').value = tool?.name || '';
            document.getElementById('tool-name').readOnly = !!tool;
            document.getElementById('tool-name').style.opacity = tool ? '0.75' : '1';
            document.getElementById('tool-description').value = tool?.description || '';
            document.getElementById('tool-keywords').value = tool?.keywords || '';
            document.getElementById('tool-language').value = tool?.language || 'php';
            document.getElementById('tool-status').value = tool?.status || (tool?.is_ai_generated ? 'draft' : 'registered');
            document.getElementById('tool-source-type').value = tool?.source_type || (tool?.is_ai_generated ? 'ai' : 'human');
            document.getElementById('tool-params').value = typeof tool?.parameters_schema === 'string' 
                ? tool.parameters_schema 
                : JSON.stringify(tool?.parameters_schema || {}, null, 2);
            document.getElementById('tool-code').value = tool?.code || '';
            document.getElementById('tool-replaces-tool-id').value = tool?.replaces_tool_id || '';
            document.getElementById('tool-lineage-key').value = tool?.lineage_key || '';
            document.getElementById('tool-review-notes').value = tool?.review_notes || '';
            document.getElementById('tool-approved-by').value = tool?.approved_by || '';
            document.getElementById('tool-approved-at').value = tool?.approved_at || '';
            document.getElementById('editor-modal').classList.add('active');
        }
        
        function closeEditor() {
            document.getElementById('editor-modal').classList.remove('active');
            document.getElementById('tool-name').readOnly = false;
            document.getElementById('tool-name').style.opacity = '1';
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
            
            try {
                const resp = await fetch('?', {
                    method: 'POST',
                    body: new URLSearchParams(formData)
                });
                const data = await resp.json();
                if (data.ok) {
                    toast(`Tool ${data.action}: ${data.name} (${data.status || 'saved'})`, 'success');
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
            if (!confirm(`Delete tool record "${name}"? This cannot be undone.`)) return;
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
            return setStatus(name, 'approved');
        }

        async function setStatus(name, status) {
            try {
                const resp = await fetch('?', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=set_status&name=' + encodeURIComponent(name) + '&status=' + encodeURIComponent(status)
                });
                const data = await resp.json();
                if (data.ok) {
                    toast(`Tool status set to ${data.status}`, 'success');
                    setTimeout(() => location.reload(), 500);
                } else {
                    toast(data.error || 'Status update failed', 'error');
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
            
            if (!confirm(`Import tool records from "${file.name}"? Existing records with the same name will be updated, but approval still depends on status.`)) {
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
                    toast(`Imported ${data.loaded} tool records`, 'success');
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
        
        // ---- Template Functions ----
        
        async function loadAvailableTemplates() {
            try {
                const resp = await fetch('?action=available_template_files');
                const data = await resp.json();
                if (!data.ok || !data.files) {
                    toast('No template files found in /admin/AI_Story/', 'error');
                    return;
                }
                
                let html = '<h3 style="margin-top:0;">Available Template Files</h3>';
                if (data.files.length === 0) {
                    html += '<p style="color:#8b949e;">No template JSON files found in /admin/AI_Story/</p>';
                } else {
                    html += '<table><thead><tr><th>Filename</th><th>Size</th><th>Action</th></tr></thead><tbody>';
                    for (const f of data.files) {
                        html += `<tr>
                            <td><code class="mono">${f.name}</code></td>
                            <td>${(f.size / 1024).toFixed(1)} KB</td>
                            <td><button class="btn btn-primary btn-sm" onclick="loadTemplateFile('${f.path}', '${f.name}')">Load</button></td>
                        </tr>`;
                    }
                    html += '</tbody></table>';
                }
                document.getElementById('template-files-list').innerHTML = html;
            } catch (e) {
                toast('Error: ' + e.message, 'error');
            }
        }
        
        async function loadTemplateFile(path, name) {
            if (!confirm(`Load templates from "${name}"?`)) return;
            
            try {
                const resp = await fetch('?', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=load_templates&json_path=' + encodeURIComponent(path)
                });
                const data = await resp.json();
                if (data.ok) {
                    toast(`Loaded ${data.loaded} templates${data.errors.length > 0 ? ` (${data.errors.length} errors)` : ''}`, 'success');
                    if (data.errors.length > 0) {
                        console.warn('Load errors:', data.errors);
                    }
                    setTimeout(() => listLoadedTemplates(), 500);
                } else {
                    toast(data.error || 'Load failed', 'error');
                }
            } catch (e) {
                toast('Error: ' + e.message, 'error');
            }
        }
        
        async function listLoadedTemplates() {
            try {
                const resp = await fetch('?action=list_templates');
                const data = await resp.json();
                if (!data.ok || !data.templates) {
                    toast('Failed to fetch templates', 'error');
                    return;
                }
                
                let html = '<h3 style="margin-top:0;">Loaded Templates</h3>';
                if (data.templates.length === 0) {
                    html += '<p style="color:#8b949e;">No templates loaded yet. Click "Discover Templates" above.</p>';
                } else {
                    html += `<p><strong>${data.templates.length} template(s) loaded</strong></p>`;
                    html += '<table><thead><tr><th>Name</th><th>Type</th><th>Loaded</th></tr></thead><tbody>';
                    for (const t of data.templates) {
                        const loaded = new Date(t.created_at).toLocaleString();
                        html += `<tr>
                            <td><code class="mono">${t.name}</code></td>
                            <td>${t.type}</td>
                            <td style="color:#8b949e;font-size:12px;">${loaded}</td>
                        </tr>`;
                    }
                    html += '</tbody></table>';
                }
                document.getElementById('templates-list').innerHTML = html;
            } catch (e) {
                toast('Error: ' + e.message, 'error');
            }
        }
    </script>
</body>
</html>
