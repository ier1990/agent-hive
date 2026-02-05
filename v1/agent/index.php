<?php
// /v1/agent/index.php
// Universal Agent: The "DB-Bot" entry point
// - Receives intent/message
// - Looks up matching tool in DB
// - If found: runs it
// - If not found: asks AI to write the code, stores it, runs it
//
// This turns AgentHive from a "backend for agents" into a "self-evolving system"

declare(strict_types=0);

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

// ---- Config ----
define('AGENT_DB_PATH', PRIVATE_ROOT . '/db/agent_tools.db');
define('AGENT_LOG_PATH', PRIVATE_ROOT . '/logs/agent.log');
define('AGENT_AUTO_APPROVE', (bool)env('AGENT_AUTO_APPROVE', false)); // Safety: require manual approval for AI-generated tools
define('AGENT_MAX_CODE_LENGTH', 50000); // Max code size
define('AGENT_EXEC_TIMEOUT', 30); // Seconds

// ---- CORS ----
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

// ---- Database ----

function agent_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    
    $dir = dirname(AGENT_DB_PATH);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    
    $pdo = new PDO('sqlite:' . AGENT_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Ensure schema exists
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
    ');
    
    return $pdo;
}

function agent_log(string $msg): void {
    $dir = dirname(AGENT_LOG_PATH);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $ts = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    @file_put_contents(AGENT_LOG_PATH, "[$ts] [$ip] $msg\n", FILE_APPEND | LOCK_EX);
}

// ---- Tool Matching ----

function find_tool_by_name(string $name): ?array {
    $pdo = agent_db();
    $stmt = $pdo->prepare('SELECT * FROM tools WHERE name = ? AND is_approved = 1');
    $stmt->execute([$name]);
    return $stmt->fetch() ?: null;
}

function find_tool_by_intent(string $intent): ?array {
    $pdo = agent_db();
    $intent = strtolower(trim($intent));
    $words = preg_split('/\s+/', $intent);
    
    // Strategy 1: Exact name match
    $stmt = $pdo->prepare('SELECT * FROM tools WHERE LOWER(name) = ? AND is_approved = 1');
    $stmt->execute([$intent]);
    $match = $stmt->fetch();
    if ($match) return $match;
    
    // Strategy 2: Keyword scoring
    $stmt = $pdo->query('SELECT * FROM tools WHERE is_approved = 1');
    $tools = $stmt->fetchAll();
    
    $scored = [];
    foreach ($tools as $tool) {
        $score = 0;
        $keywords = strtolower($tool['keywords'] . ' ' . $tool['name'] . ' ' . $tool['description']);
        
        foreach ($words as $word) {
            if (strlen($word) < 3) continue;
            if (strpos($keywords, $word) !== false) {
                $score += 10;
            }
            // Fuzzy: check if word is substring
            if (strpos($keywords, substr($word, 0, 4)) !== false) {
                $score += 3;
            }
        }
        
        if ($score > 0) {
            $scored[] = ['tool' => $tool, 'score' => $score];
        }
    }
    
    if (empty($scored)) return null;
    
    // Return best match
    usort($scored, function($a, $b) { return $b['score'] - $a['score']; });
    
    // Only return if score is meaningful
    if ($scored[0]['score'] >= 10) {
        return $scored[0]['tool'];
    }
    
    return null;
}

// ---- Tool Execution ----

function execute_tool(array $tool, array $params): array {
    $startTime = microtime(true);
    $language = strtolower($tool['language'] ?? 'php');
    $code = $tool['code'] ?? '';
    
    if ($code === '') {
        return ['ok' => false, 'error' => 'Tool has no code'];
    }
    
    $result = null;
    $error = null;
    
    try {
        switch ($language) {
            case 'php':
                $result = execute_php_tool($code, $params);
                break;
            case 'python':
                $result = execute_python_tool($code, $params);
                break;
            case 'bash':
                $result = execute_bash_tool($code, $params);
                break;
            default:
                return ['ok' => false, 'error' => 'Unsupported language: ' . $language];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    
    $duration = (int)((microtime(true) - $startTime) * 1000);
    
    // Update run stats
    $pdo = agent_db();
    $pdo->prepare('UPDATE tools SET run_count = run_count + 1, last_run_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([$tool['id']]);
    
    // Log run
    $stmt = $pdo->prepare('INSERT INTO tool_runs (tool_id, tool_name, input_hash, input_preview, output_preview, success, duration_ms, client_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $tool['id'],
        $tool['name'],
        md5(json_encode($params)),
        substr(json_encode($params), 0, 500),
        substr(json_encode($result), 0, 500),
        $error === null ? 1 : 0,
        $duration,
        $_SERVER['REMOTE_ADDR'] ?? 'cli'
    ]);
    
    if ($error !== null) {
        return ['ok' => false, 'error' => $error, 'duration_ms' => $duration];
    }
    
    return ['ok' => true, 'result' => $result, 'duration_ms' => $duration];
}

function execute_php_tool(string $code, array $params): mixed {
    // Create isolated scope
    $__params = $params;
    $__result = null;
    
    // Wrap in function to isolate scope
    $wrappedCode = '
        return (function($params) {
            ' . $code . '
        })($__params);
    ';
    
    $__result = eval($wrappedCode);
    return $__result;
}

function execute_python_tool(string $code, array $params): mixed {
    $tmpScript = PRIVATE_ROOT . '/tmp/agent_py_' . bin2hex(random_bytes(8)) . '.py';
    $tmpDir = dirname($tmpScript);
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
    
    // Wrap code with params injection
    $fullCode = "import json, sys\n";
    $fullCode .= "params = json.loads('''" . json_encode($params) . "''')\n\n";
    $fullCode .= $code;
    $fullCode .= "\n";
    
    @file_put_contents($tmpScript, $fullCode);
    
    $output = [];
    $ret = 0;
    exec('timeout ' . AGENT_EXEC_TIMEOUT . ' python3 ' . escapeshellarg($tmpScript) . ' 2>&1', $output, $ret);
    
    @unlink($tmpScript);
    
    $outputStr = implode("\n", $output);
    
    if ($ret !== 0) {
        throw new RuntimeException('Python execution failed: ' . $outputStr);
    }
    
    // Try to parse as JSON, else return raw
    $json = json_decode($outputStr, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $json;
    }
    
    return $outputStr;
}

function execute_bash_tool(string $code, array $params): mixed {
    // Export params as env vars
    $envPrefix = '';
    foreach ($params as $k => $v) {
        if (is_scalar($v)) {
            $envPrefix .= 'export PARAM_' . strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $k)) . '=' . escapeshellarg((string)$v) . "\n";
        }
    }
    
    $fullCode = $envPrefix . $code;
    
    $output = [];
    $ret = 0;
    exec('timeout ' . AGENT_EXEC_TIMEOUT . ' bash -c ' . escapeshellarg($fullCode) . ' 2>&1', $output, $ret);
    
    $outputStr = implode("\n", $output);
    
    if ($ret !== 0) {
        throw new RuntimeException('Bash execution failed (exit ' . $ret . '): ' . $outputStr);
    }
    
    return $outputStr;
}

// ---- AI Code Generation ----

function generate_tool_with_ai(string $intent, array $context = []): ?array {
    $settings = function_exists('codewalker_llm_settings') ? codewalker_llm_settings() : [];
    $baseUrl = $settings['base_url'] ?? env('LLM_BASE_URL', 'http://127.0.0.1:1234');
    $apiKey = $settings['api_key'] ?? env('LLM_API_KEY', '');
    $model = $settings['model'] ?? env('LLM_MODEL', 'gpt-4');
    
    $systemPrompt = <<<'PROMPT'
You are a tool-generating AI for AgentHive, a self-hosted PHP application.
When given a user intent, you create a small, self-contained tool that accomplishes the task.

Rules:
1. Generate ONLY the function body code (no function declaration wrapper)
2. For PHP: Use $params array to access input parameters. Return the result.
3. For Python: Use params dict to access input. Print JSON or plain text output.
4. For Bash: Use PARAM_* environment variables. Echo output.
5. Keep tools simple, secure, and focused on one task
6. No network calls unless explicitly requested
7. No file system writes outside /web/private/tmp/
8. No shell_exec, exec, system calls in PHP unless absolutely necessary

Respond with JSON:
{
  "name": "tool_name_snake_case",
  "description": "What this tool does",
  "keywords": "comma, separated, keywords",
  "language": "php|python|bash",
  "parameters_schema": {"param1": "string", "param2": "number"},
  "code": "// the actual code"
}
PROMPT;

    $userPrompt = "Create a tool for this intent: " . $intent;
    if (!empty($context)) {
        $userPrompt .= "\n\nAdditional context: " . json_encode($context);
    }
    
    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'temperature' => 0.3,
        'max_tokens' => 2000,
    ];
    
    $ch = curl_init($baseUrl . '/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);
    
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($err || $code !== 200 || !is_string($resp)) {
        agent_log("AI generation failed: HTTP $code, error: $err");
        return null;
    }
    
    $data = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    
    if ($content === '') {
        agent_log("AI returned empty content");
        return null;
    }
    
    // Extract JSON from response (may be wrapped in markdown)
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $m)) {
        $content = $m[1];
    }
    
    $tool = json_decode(trim($content), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($tool)) {
        agent_log("AI returned invalid JSON: " . substr($content, 0, 500));
        return null;
    }
    
    // Validate required fields
    if (empty($tool['name']) || empty($tool['code'])) {
        agent_log("AI tool missing name or code");
        return null;
    }
    
    // Sanitize
    $tool['name'] = preg_replace('/[^a-z0-9_]/', '_', strtolower($tool['name']));
    $tool['is_ai_generated'] = 1;
    $tool['is_approved'] = AGENT_AUTO_APPROVE ? 1 : 0;
    
    return $tool;
}

function store_ai_tool(array $tool): ?int {
    $pdo = agent_db();
    
    $paramsSchema = $tool['parameters_schema'] ?? [];
    $paramsJson = is_string($paramsSchema) ? $paramsSchema : json_encode($paramsSchema);
    
    try {
        $stmt = $pdo->prepare('
            INSERT INTO tools (name, description, keywords, parameters_schema, code, language, is_approved, is_ai_generated)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ');
        $stmt->execute([
            $tool['name'],
            $tool['description'] ?? '',
            $tool['keywords'] ?? '',
            $paramsJson,
            $tool['code'],
            $tool['language'] ?? 'php',
            $tool['is_approved'] ?? 0,
        ]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // Might be duplicate name
        agent_log("Failed to store AI tool: " . $e->getMessage());
        return null;
    }
}

// ---- API Guard ----

function agent_guard(): void {
    // Use existing api_guard if available, else basic check
    if (function_exists('api_guard')) {
        api_guard('v1/agent');
        return;
    }
    
    // Basic rate limit by IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (function_exists('rl_check')) {
        list($ok, $rem, $reset) = rl_check("agent:$ip", 30, 60);
        if (!$ok) {
            http_json(429, ['ok' => false, 'error' => 'rate_limited', 'retry_after' => max(0, $reset - time())]);
        }
    }
}

// ---- Request Handling ----

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// GET: Show info/docs
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'tools') {
        // List available tools
        agent_guard();
        $pdo = agent_db();
        $stmt = $pdo->query('SELECT name, description, keywords, language, parameters_schema FROM tools WHERE is_approved = 1 ORDER BY name');
        $tools = $stmt->fetchAll();
        http_json(200, ['ok' => true, 'tools' => $tools]);
    }
    
    if ($action === 'schema') {
        // Get specific tool schema
        agent_guard();
        $name = $_GET['name'] ?? '';
        $tool = find_tool_by_name($name);
        if (!$tool) {
            http_json(404, ['ok' => false, 'error' => 'tool_not_found']);
        }
        http_json(200, [
            'ok' => true,
            'tool' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => json_decode($tool['parameters_schema'] ?: '{}', true),
            ]
        ]);
    }
    
    // Default: show endpoint info
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'endpoint' => 'v1/agent',
        'description' => 'Universal Agent - Self-evolving tool execution',
        'usage' => [
            'POST' => [
                'intent' => 'Natural language description of what you want',
                'tool' => 'Optional: specific tool name to run',
                'params' => 'Parameters to pass to the tool',
                'generate' => 'Set to true to allow AI tool generation if not found',
            ],
            'GET ?action=tools' => 'List available tools',
            'GET ?action=schema&name=X' => 'Get tool schema',
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// POST: Execute intent
if ($method === 'POST') {
    agent_guard();
    
    header('Content-Type: application/json; charset=utf-8');
    
    // Parse input
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
    } else {
        $input = $_POST;
    }
    
    $intent = trim($input['intent'] ?? $input['message'] ?? $input['query'] ?? '');
    $toolName = trim($input['tool'] ?? '');
    $params = $input['params'] ?? $input['parameters'] ?? [];
    $allowGenerate = !empty($input['generate']);
    
    if ($intent === '' && $toolName === '') {
        http_json(400, ['ok' => false, 'error' => 'missing_intent', 'message' => 'Provide "intent" or "tool" parameter']);
    }
    
    if (!is_array($params)) {
        $params = [];
    }
    
    agent_log("Intent: $intent | Tool: $toolName | Generate: " . ($allowGenerate ? 'yes' : 'no'));
    
    // Find tool
    $tool = null;
    $source = 'db';
    
    if ($toolName !== '') {
        $tool = find_tool_by_name($toolName);
        if (!$tool) {
            http_json(404, ['ok' => false, 'error' => 'tool_not_found', 'tool' => $toolName]);
        }
    } else {
        $tool = find_tool_by_intent($intent);
    }
    
    // If no tool found and generation allowed, create one
    if (!$tool && $allowGenerate) {
        agent_log("No tool found, generating with AI...");
        
        $newTool = generate_tool_with_ai($intent, ['params' => $params]);
        
        if (!$newTool) {
            http_json(500, [
                'ok' => false,
                'error' => 'generation_failed',
                'message' => 'AI could not generate a tool for this intent'
            ]);
        }
        
        $toolId = store_ai_tool($newTool);
        if (!$toolId) {
            http_json(500, [
                'ok' => false,
                'error' => 'storage_failed',
                'message' => 'Could not store generated tool'
            ]);
        }
        
        $source = 'ai_generated';
        
        // Check if auto-approved
        if (!AGENT_AUTO_APPROVE) {
            http_json(202, [
                'ok' => true,
                'status' => 'pending_approval',
                'message' => 'Tool generated but requires admin approval before execution',
                'tool' => [
                    'name' => $newTool['name'],
                    'description' => $newTool['description'],
                    'language' => $newTool['language'] ?? 'php',
                ],
            ]);
        }
        
        // Re-fetch for execution
        $tool = find_tool_by_name($newTool['name']);
    }
    
    if (!$tool) {
        http_json(404, [
            'ok' => false,
            'error' => 'no_matching_tool',
            'message' => 'No tool matches this intent. Set "generate": true to create one.',
            'intent' => $intent,
        ]);
    }
    
    // Execute!
    agent_log("Executing tool: {$tool['name']}");
    
    $execResult = execute_tool($tool, $params);
    
    if (!$execResult['ok']) {
        http_json(500, [
            'ok' => false,
            'error' => 'execution_failed',
            'message' => $execResult['error'] ?? 'Unknown error',
            'tool' => $tool['name'],
            'duration_ms' => $execResult['duration_ms'] ?? 0,
        ]);
    }
    
    http_json(200, [
        'ok' => true,
        'tool' => $tool['name'],
        'source' => $source,
        'result' => $execResult['result'],
        'duration_ms' => $execResult['duration_ms'],
    ]);
}

http_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
