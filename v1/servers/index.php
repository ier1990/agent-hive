<?php
// /web/html/v1/servers/index.php
// Multi-server coordination: register, heartbeat, list
declare(strict_types=1);

// API keys for server scope should look like:
// {"srv-lan-01-xxxx": {"active": true, "scopes": ["server", "health"], "name": "LAN Server 1"}}

ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', '/web/private/logs/servers-error.log');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/db.php';
require_once dirname(__DIR__, 2) . '/lib/schema_builder.php';

// --- Auth: rate limit + require 'server' scope ---
api_guard('servers', false);

$scopes = isset($GLOBALS['APP_SCOPES']) ? $GLOBALS['APP_SCOPES'] : [];
if (!in_array('server', $scopes, true)) {
    http_json(403, [
        'ok' => false,
        'error' => 'forbidden',
        'reason' => 'missing_server_scope',
        'endpoint' => 'v1/servers',
    ]);
}

// --- Setup ---
$start = microtime(true);
$reqId = bin2hex(random_bytes(16));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = isset($_GET['after']) ? trim((string)$_GET['after']) : '';

// Stale threshold: servers not seen in 5 minutes
$STALE_SECONDS = 300;

// --- Database ---
$dbDir = PRIVATE_ROOT . '/db';
if (!is_dir($dbDir)) {
    @mkdir($dbDir, 0775, true);
}
$dbPath = $dbDir . '/cluster.db';

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA journal_mode=WAL');
$pdo->exec('PRAGMA synchronous=NORMAL');
$pdo->exec('PRAGMA busy_timeout=5000');

$schema = [
    'server_id'    => ['type' => 'TEXT PRIMARY KEY'],
    'hostname'     => ['type' => 'TEXT NOT NULL'],
    'ip_lan'       => ['type' => "TEXT DEFAULT ''"],
    'ip_public'    => ['type' => "TEXT DEFAULT ''"],
    'location'     => ['type' => "TEXT DEFAULT 'lan'"],
    'capabilities' => ['type' => "TEXT DEFAULT '{}'"],
    'load_1m'      => ['type' => 'REAL DEFAULT 0'],
    'load_5m'      => ['type' => 'REAL DEFAULT 0'],
    'load_15m'     => ['type' => 'REAL DEFAULT 0'],
    'mem_total_mb'  => ['type' => 'INTEGER DEFAULT 0'],
    'mem_used_mb'   => ['type' => 'INTEGER DEFAULT 0'],
    'disk_total_gb' => ['type' => 'INTEGER DEFAULT 0'],
    'disk_used_gb'  => ['type' => 'INTEGER DEFAULT 0'],
    'status'       => ['type' => "TEXT DEFAULT 'online'"],
    'version'      => ['type' => "TEXT DEFAULT ''"],
    'api_key_hash' => ['type' => "TEXT DEFAULT ''"],
    'registered_at' => ['type' => "TEXT DEFAULT (datetime('now'))"],
    'last_seen'     => ['type' => "TEXT DEFAULT (datetime('now'))"],
    'meta'         => ['type' => "TEXT DEFAULT '{}'"],
];

ensureTableAndColumns($pdo, 'servers', $schema);
ensure_heartbeat_samples_schema($pdo);

// --- Routing ---
try {
    if ($method === 'POST' && $action === 'register') {
        handle_register($pdo, $reqId, $start);
    } elseif ($method === 'POST' && $action === 'heartbeat') {
        handle_heartbeat($pdo, $reqId, $start);
    } elseif ($method === 'GET' && ($action === 'list' || $action === '')) {
        handle_list($pdo, $reqId, $start);
    } else {
        $elapsed = (int)round((microtime(true) - $start) * 1000);
        http_json(400, [
            'ok' => false,
            'error' => 'bad_request',
            'message' => 'Unknown action. Use: register, heartbeat, list',
            'endpoint' => 'v1/servers',
            'req_id' => $reqId,
            'ms' => $elapsed,
        ]);
    }
} catch (Throwable $e) {
    $elapsed = (int)round((microtime(true) - $start) * 1000);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage(),
        'endpoint' => 'v1/servers',
        'req_id' => $reqId,
        'ms' => $elapsed,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ===================== Handlers =====================

function handle_register(PDO $pdo, string $reqId, float $start) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_json(400, ['ok' => false, 'error' => 'invalid_json', 'req_id' => $reqId]);
    }

    $hostname = isset($data['hostname']) ? trim((string)$data['hostname']) : '';
    if ($hostname === '') {
        http_json(400, [
            'ok' => false,
            'error' => 'missing_field',
            'field' => 'hostname',
            'req_id' => $reqId,
        ]);
    }

    // If server_id provided and exists, upsert; otherwise generate new
    $serverId = isset($data['server_id']) ? trim((string)$data['server_id']) : '';
    $isUpdate = false;

    if ($serverId !== '') {
        $check = $pdo->prepare('SELECT server_id FROM servers WHERE server_id = ?');
        $check->execute([$serverId]);
        if ($check->fetchColumn()) {
            $isUpdate = true;
        }
    }

    if (!$isUpdate) {
        $serverId = ulid();
    }

    // Gather fields
    $ipLan       = isset($data['ip_lan']) ? trim((string)$data['ip_lan']) : '';
    $ipPublic    = isset($data['ip_public']) ? trim((string)$data['ip_public']) : '';
    $location    = isset($data['location']) ? trim((string)$data['location']) : 'lan';
    $capabilities = isset($data['capabilities']) ? $data['capabilities'] : [];
    $capJson     = is_array($capabilities) ? json_encode($capabilities, JSON_UNESCAPED_SLASHES) : (string)$capabilities;
    $load1m      = isset($data['load_1m']) ? (float)$data['load_1m'] : 0;
    $load5m      = isset($data['load_5m']) ? (float)$data['load_5m'] : 0;
    $load15m     = isset($data['load_15m']) ? (float)$data['load_15m'] : 0;
    $memTotal    = isset($data['mem_total_mb']) ? (int)$data['mem_total_mb'] : 0;
    $memUsed     = isset($data['mem_used_mb']) ? (int)$data['mem_used_mb'] : 0;
    $diskTotal   = isset($data['disk_total_gb']) ? (int)$data['disk_total_gb'] : 0;
    $diskUsed    = isset($data['disk_used_gb']) ? (int)$data['disk_used_gb'] : 0;
    $status      = isset($data['status']) ? trim((string)$data['status']) : 'online';
    $version     = isset($data['version']) ? trim((string)$data['version']) : '';
    $meta        = isset($data['meta']) ? $data['meta'] : [];
    $metaJson    = is_array($meta) ? json_encode($meta, JSON_UNESCAPED_SLASHES) : (string)$meta;

    // Validate location and status
    $validLocations = ['lan', 'cloud'];
    if (!in_array($location, $validLocations, true)) {
        $location = 'lan';
    }
    $validStatuses = ['online', 'offline', 'degraded', 'maintenance'];
    if (!in_array($status, $validStatuses, true)) {
        $status = 'online';
    }

    // API key hash for audit
    $clientKey = isset($GLOBALS['APP_CLIENT_KEY']) ? (string)$GLOBALS['APP_CLIENT_KEY'] : '';
    $keyHash = $clientKey !== '' ? hash('sha256', $clientKey) : '';

    $now = gmdate('Y-m-d H:i:s');

    if ($isUpdate) {
        $sql = 'UPDATE servers SET
            hostname = ?, ip_lan = ?, ip_public = ?, location = ?,
            capabilities = ?, load_1m = ?, load_5m = ?, load_15m = ?,
            mem_total_mb = ?, mem_used_mb = ?, disk_total_gb = ?, disk_used_gb = ?,
            status = ?, version = ?, api_key_hash = ?, last_seen = ?, meta = ?
            WHERE server_id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $hostname, $ipLan, $ipPublic, $location,
            $capJson, $load1m, $load5m, $load15m,
            $memTotal, $memUsed, $diskTotal, $diskUsed,
            $status, $version, $keyHash, $now, $metaJson,
            $serverId,
        ]);
    } else {
        $sql = 'INSERT INTO servers
            (server_id, hostname, ip_lan, ip_public, location,
             capabilities, load_1m, load_5m, load_15m,
             mem_total_mb, mem_used_mb, disk_total_gb, disk_used_gb,
             status, version, api_key_hash, registered_at, last_seen, meta)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $serverId, $hostname, $ipLan, $ipPublic, $location,
            $capJson, $load1m, $load5m, $load15m,
            $memTotal, $memUsed, $diskTotal, $diskUsed,
            $status, $version, $keyHash, $now, $now, $metaJson,
        ]);
    }

    // Best-effort audit trail for history charts.
    try {
        record_heartbeat_sample($pdo, $serverId, 'register');
    } catch (Throwable $e) {
        // Keep register path resilient even if history insert fails.
    }

    $elapsed = (int)round((microtime(true) - $start) * 1000);
    http_json($isUpdate ? 200 : 201, [
        'ok' => true,
        'endpoint' => 'v1/servers',
        'action' => 'register',
        'server_id' => $serverId,
        'updated' => $isUpdate,
        'req_id' => $reqId,
        'ms' => $elapsed,
    ]);
}

function handle_heartbeat(PDO $pdo, string $reqId, float $start) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_json(400, ['ok' => false, 'error' => 'invalid_json', 'req_id' => $reqId]);
    }

    $serverId = isset($data['server_id']) ? trim((string)$data['server_id']) : '';
    if ($serverId === '') {
        http_json(400, [
            'ok' => false,
            'error' => 'missing_field',
            'field' => 'server_id',
            'req_id' => $reqId,
        ]);
    }

    // Verify server exists
    $check = $pdo->prepare('SELECT server_id FROM servers WHERE server_id = ?');
    $check->execute([$serverId]);
    if (!$check->fetchColumn()) {
        http_json(404, [
            'ok' => false,
            'error' => 'server_not_found',
            'server_id' => $serverId,
            'req_id' => $reqId,
        ]);
    }

    $now = gmdate('Y-m-d H:i:s');

    // Build dynamic UPDATE from allowed fields
    $allowed = [
        'load_1m'      => 'float',
        'load_5m'      => 'float',
        'load_15m'     => 'float',
        'mem_total_mb'  => 'int',
        'mem_used_mb'   => 'int',
        'disk_total_gb' => 'int',
        'disk_used_gb'  => 'int',
        'status'       => 'string',
        'version'      => 'string',
    ];

    $sets = ['last_seen = ?'];
    $params = [$now];

    foreach ($allowed as $field => $type) {
        if (!isset($data[$field])) continue;
        $val = $data[$field];
        if ($type === 'float') {
            $val = (float)$val;
        } elseif ($type === 'int') {
            $val = (int)$val;
        } else {
            $val = trim((string)$val);
        }
        // Validate status
        if ($field === 'status') {
            $validStatuses = ['online', 'offline', 'degraded', 'maintenance'];
            if (!in_array($val, $validStatuses, true)) continue;
        }
        $sets[] = "$field = ?";
        $params[] = $val;
    }

    // Optional meta merge
    if (isset($data['meta']) && is_array($data['meta'])) {
        $existing = $pdo->prepare('SELECT meta FROM servers WHERE server_id = ?');
        $existing->execute([$serverId]);
        $oldMeta = json_decode((string)$existing->fetchColumn(), true);
        if (!is_array($oldMeta)) $oldMeta = [];
        $merged = array_merge($oldMeta, $data['meta']);
        $sets[] = 'meta = ?';
        $params[] = json_encode($merged, JSON_UNESCAPED_SLASHES);
    }

    $params[] = $serverId;
    $sql = 'UPDATE servers SET ' . implode(', ', $sets) . ' WHERE server_id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Best-effort audit trail for history charts.
    try {
        record_heartbeat_sample($pdo, $serverId, 'heartbeat');
    } catch (Throwable $e) {
        // Keep heartbeat path resilient even if history insert fails.
    }

    $elapsed = (int)round((microtime(true) - $start) * 1000);
    http_json(200, [
        'ok' => true,
        'endpoint' => 'v1/servers',
        'action' => 'heartbeat',
        'server_id' => $serverId,
        'last_seen' => $now,
        'req_id' => $reqId,
        'ms' => $elapsed,
    ]);
}

function ensure_heartbeat_samples_schema(PDO $pdo): void {
    $schema = [
        'id'            => ['type' => 'INTEGER PRIMARY KEY AUTOINCREMENT'],
        'server_id'     => ['type' => 'TEXT NOT NULL'],
        'hostname'      => ['type' => "TEXT DEFAULT ''"],
        'location'      => ['type' => "TEXT DEFAULT 'lan'"],
        'status'        => ['type' => "TEXT DEFAULT 'online'"],
        'load_1m'       => ['type' => 'REAL DEFAULT 0'],
        'load_5m'       => ['type' => 'REAL DEFAULT 0'],
        'load_15m'      => ['type' => 'REAL DEFAULT 0'],
        'mem_total_mb'  => ['type' => 'INTEGER DEFAULT 0'],
        'mem_used_mb'   => ['type' => 'INTEGER DEFAULT 0'],
        'disk_total_gb' => ['type' => 'INTEGER DEFAULT 0'],
        'disk_used_gb'  => ['type' => 'INTEGER DEFAULT 0'],
        'sampled_at'    => ['type' => "TEXT DEFAULT (datetime('now'))"],
        'source_action' => ['type' => "TEXT DEFAULT ''"],
    ];

    ensureTableAndColumns($pdo, 'heartbeat_samples', $schema);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_heartbeat_samples_time ON heartbeat_samples(sampled_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_heartbeat_samples_server_time ON heartbeat_samples(server_id, sampled_at)');
}

function record_heartbeat_sample(PDO $pdo, string $serverId, string $sourceAction): void {
    $sel = $pdo->prepare('SELECT server_id, hostname, location, status, load_1m, load_5m, load_15m, mem_total_mb, mem_used_mb, disk_total_gb, disk_used_gb, last_seen FROM servers WHERE server_id = ? LIMIT 1');
    $sel->execute([$serverId]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return;

    $ins = $pdo->prepare('
        INSERT INTO heartbeat_samples
            (server_id, hostname, location, status, load_1m, load_5m, load_15m, mem_total_mb, mem_used_mb, disk_total_gb, disk_used_gb, sampled_at, source_action)
        VALUES
            (:server_id, :hostname, :location, :status, :load_1m, :load_5m, :load_15m, :mem_total_mb, :mem_used_mb, :disk_total_gb, :disk_used_gb, :sampled_at, :source_action)
    ');
    $ins->bindValue(':server_id', (string)($row['server_id'] ?? $serverId), PDO::PARAM_STR);
    $ins->bindValue(':hostname', (string)($row['hostname'] ?? ''), PDO::PARAM_STR);
    $ins->bindValue(':location', (string)($row['location'] ?? 'lan'), PDO::PARAM_STR);
    $ins->bindValue(':status', (string)($row['status'] ?? 'online'), PDO::PARAM_STR);
    $ins->bindValue(':load_1m', (float)($row['load_1m'] ?? 0));
    $ins->bindValue(':load_5m', (float)($row['load_5m'] ?? 0));
    $ins->bindValue(':load_15m', (float)($row['load_15m'] ?? 0));
    $ins->bindValue(':mem_total_mb', (int)($row['mem_total_mb'] ?? 0), PDO::PARAM_INT);
    $ins->bindValue(':mem_used_mb', (int)($row['mem_used_mb'] ?? 0), PDO::PARAM_INT);
    $ins->bindValue(':disk_total_gb', (int)($row['disk_total_gb'] ?? 0), PDO::PARAM_INT);
    $ins->bindValue(':disk_used_gb', (int)($row['disk_used_gb'] ?? 0), PDO::PARAM_INT);
    $ins->bindValue(':sampled_at', (string)($row['last_seen'] ?? gmdate('Y-m-d H:i:s')), PDO::PARAM_STR);
    $ins->bindValue(':source_action', $sourceAction, PDO::PARAM_STR);
    $ins->execute();
}

function handle_list(PDO $pdo, string $reqId, float $start) {
    global $STALE_SECONDS;

    $wheres = [];
    $params = [];

    // Optional filters
    if (isset($_GET['status']) && trim((string)$_GET['status']) !== '') {
        $wheres[] = 'status = ?';
        $params[] = trim((string)$_GET['status']);
    }
    if (isset($_GET['location']) && trim((string)$_GET['location']) !== '') {
        $wheres[] = 'location = ?';
        $params[] = trim((string)$_GET['location']);
    }

    $sql = 'SELECT * FROM servers';
    if ($wheres) {
        $sql .= ' WHERE ' . implode(' AND ', $wheres);
    }
    $sql .= ' ORDER BY hostname ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Add stale flag and decode JSON fields
    $now = time();
    foreach ($rows as &$row) {
        $lastSeen = isset($row['last_seen']) ? strtotime($row['last_seen'] . ' UTC') : 0;
        $row['stale'] = ($now - $lastSeen) > $STALE_SECONDS;

        // Decode JSON text fields for cleaner output
        if (isset($row['capabilities'])) {
            $decoded = json_decode($row['capabilities'], true);
            if (is_array($decoded)) $row['capabilities'] = $decoded;
        }
        if (isset($row['meta'])) {
            $decoded = json_decode($row['meta'], true);
            if (is_array($decoded)) $row['meta'] = $decoded;
        }
    }
    unset($row);

    $elapsed = (int)round((microtime(true) - $start) * 1000);
    http_json(200, [
        'ok' => true,
        'endpoint' => 'v1/servers',
        'action' => 'list',
        'count' => count($rows),
        'servers' => $rows,
        'req_id' => $reqId,
        'ms' => $elapsed,
    ]);
}
