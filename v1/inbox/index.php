<?php
// /web/html/v1/inbox.php
declare(strict_types=1);
/*
### Inbox Contract
- All POST bodies must include `db` and `table`
- Tables are append-only by default
- JSON objects are flattened when possible
- Unknown fields are stored as TEXT(JSON)
- No deletes via inbox
*/

// ---------- Error logging ----------
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', '/web/private/logs/inbox-error.log');

// ---------- CORS (browser UI optional; server-to-server doesn't need it) ----------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

// Always send JSON responses
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/schema_builder.php';
require_once dirname(__DIR__, 2) . '/lib/registry_logger.php';

// Prefer the unified guard entrypoint (recommended)
// If your bootstrap only has api_guard_once today, either:
//  - rename api_guard_once -> api_guard in bootstrap, OR
//  - replace the next line with api_guard_once('inbox', false);
/*
// API Guard: 'inbox' endpoint, no auth required
api_guard('inbox', false);
*/
if (function_exists('api_guard')) {
  api_guard('inbox', false);
} else if (function_exists('api_guard_once')) {
  // Fallback for older bootstraps
  api_guard_once('inbox', false);
}else {
  // No guard available
    http_response_code(200);
    echo json_encode([
      'ok' => false,
      'error' => 'server_misconfigured',
      'message' => 'API guard not available'
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Config ----------
define('MAX_BODY_BYTES', 2_000_000); // ~2MB cap (tweak)
$start = microtime(true);
$reqId = bin2hex(random_bytes(16));  // 32-char receipt
$clientIp = function_exists('get_client_ip_trusted') ? get_client_ip_trusted() : ($_SERVER['REMOTE_ADDR'] ?? '');
$ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

// ---------- Public/guest boundaries ----------
// If no API key is provided, treat caller as a guest and restrict:
// - No reads (GET)
// - Writes go to a dedicated guest DB, with allowlisted tables
// - Smaller payload cap
$clientKey = (string)($GLOBALS['APP_CLIENT_KEY'] ?? (function_exists('get_client_key') ? get_client_key() : ''));
$isAuthed = ($clientKey !== '');

// INBOX_PUBLIC_MODE:
// - guest (default): allow limited POST-only guest inbox
// - off: require an API key for all inbox use
// - open: legacy behavior (no auth boundaries)
$publicMode = strtolower((string)(function_exists('env') ? env('INBOX_PUBLIC_MODE', 'guest') : 'guest'));
if (!in_array($publicMode, ['guest', 'off', 'open'], true)) $publicMode = 'guest';

$guestDbPath = (string)(function_exists('env') ? env('INBOX_GUEST_DB', '/web/private/db/inbox_guest.db') : '/web/private/db/inbox_guest.db');
$guestTablesRaw = (string)(function_exists('env') ? env('INBOX_GUEST_TABLES', 'guest_inbox') : 'guest_inbox');
$guestTableAllow = [];
foreach (explode(',', $guestTablesRaw) as $t) {
  $t = sanitize_identifier(trim($t));
  if ($t !== '') $guestTableAllow[$t] = true;
}
if (empty($guestTableAllow)) $guestTableAllow['guest_inbox'] = true;

$guestMaxBodyBytes = (int)(function_exists('env') ? env('INBOX_GUEST_MAX_BODY_BYTES', 200000) : 200000);
if ($guestMaxBodyBytes < 1024) $guestMaxBodyBytes = 1024;
if ($guestMaxBodyBytes > MAX_BODY_BYTES) $guestMaxBodyBytes = MAX_BODY_BYTES;

try {
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

  // Hard off: require a key for anything (OPTIONS already handled).
  if (!$isAuthed && $publicMode === 'off') {
    http_response_code(401);
    echo json_encode([
      'ok' => false,
      'error' => 'unauthorized',
      'endpoint' => 'v1/inbox',
      'req_id' => $reqId,
      'message' => 'API key required for inbox.'
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
  }

  // -------------------------
  // GET: query inbox tables
  // -------------------------
  if ($method === 'GET') {
    // Guest mode: do not allow reading inbox contents without a key.
    if (!$isAuthed && $publicMode === 'guest') {
      if (isset($_GET['ping'])) {
        echo json_encode([
          'ok' => true,
          'endpoint' => 'v1/inbox',
          'mode' => 'ping',
          'auth' => 'guest',
          'req_id' => $reqId,
        ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
      }
      http_response_code(403);
      echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
        'reason' => 'guest_read_disabled',
        'endpoint' => 'v1/inbox',
        'req_id' => $reqId,
      ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
      exit;
    }

    // Query params:
    //   db (or service), table, limit, offset, order, desc, q, f_<col>=value
    $dbParam   = isset($_GET['db']) ? (string)$_GET['db'] : (isset($_GET['service']) ? (string)$_GET['service'] : '');
    $tableIn   = isset($_GET['table']) ? (string)$_GET['table'] : 'generic_input';
    $limitIn   = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $offsetIn  = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $orderIn   = isset($_GET['order']) ? (string)$_GET['order'] : 'received_at';
    $descIn    = (isset($_GET['desc']) ? (string)$_GET['desc'] : '1') !== '0';
    $qIn       = isset($_GET['q']) ? (string)$_GET['q'] : '';

    $dataForDb = [];
    if ($dbParam !== '') $dataForDb['db'] = $dbParam;
    if (isset($_GET['service'])) $dataForDb['service'] = (string)$_GET['service'];

    $dbPath = getDatabasePath($dataForDb);
    ensure_db_dir($dbPath);

    $table = sanitize_identifier($tableIn);
    if ($table === '') $table = 'generic_input';

    $pdo = new PDO("sqlite:$dbPath", null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("PRAGMA busy_timeout=5000;");

    if (!tableExists($pdo, $table)) {
      http_response_code(404);
      echo json_encode([
        'ok' => false,
        'error' => 'table_not_found',
        'req_id' => $reqId,
        'db' => basename($dbPath),
        'table' => $table
      ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
      exit;
    }

    // Introspect columns for safe filtering and ordering
    $cols = [];
    $textCols = [];
    $stmt = $pdo->query("PRAGMA table_info(`$table`)");
    $info = $stmt->fetchAll();
    foreach ($info as $c) {
      $nameLower = strtolower((string)$c['name']);
      $cols[$nameLower] = (string)$c['name'];
      $type = strtoupper((string)$c['type']);
      if (strpos($type, 'TEXT') !== false) $textCols[] = (string)$c['name'];
    }

    $limit  = max(1, min($limitIn, 1000));
    $offset = max(0, $offsetIn);

    $orderCol = isset($cols[strtolower($orderIn)]) ? $cols[strtolower($orderIn)] : (isset($cols['received_at']) ? $cols['received_at'] : 'rowid');
    $orderDir = $descIn ? 'DESC' : 'ASC';

    // Build WHERE from f_<col>=... and optional q across TEXT cols
    $wheres = [];
    $params = [];

    foreach ($_GET as $k => $v) {
      if (substr((string)$k, 0, 2) !== 'f_') continue;
      $colKey = substr((string)$k, 2);
      $col = isset($cols[strtolower($colKey)]) ? $cols[strtolower($colKey)] : null;
      if (!$col) continue;

      $paramName = 'f_' . $col;
      $wheres[] = "`$col` = :$paramName";
      $params[":$paramName"] = $v;
    }

    if ($qIn !== '' && !empty($textCols)) {
      $likeParts = [];
      foreach ($textCols as $tc) $likeParts[] = "`$tc` LIKE :_q";
      $wheres[] = '(' . implode(' OR ', $likeParts) . ')';
      $params[':_q'] = '%' . $qIn . '%';
    }

    $whereSql = $wheres ? ('WHERE ' . implode(' AND ', $wheres)) : '';
    $sql = "SELECT * FROM `$table` $whereSql ORDER BY `$orderCol` $orderDir LIMIT :_limit OFFSET :_offset";

    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':_limit',  $limit,  PDO::PARAM_INT);
    $st->bindValue(':_offset', $offset, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();

    echo json_encode([
      'ok'       => true,
      'service'  => defined('IER_SERVICE_NAME') ? IER_SERVICE_NAME : 'iernc-api',
      'endpoint' => 'v1/inbox',
      'mode'     => 'query',
      'req_id'   => $reqId,
      'db'       => basename($dbPath),
      'table'    => $table,
      'limit'    => $limit,
      'offset'   => $offset,
      'order'    => $orderCol,
      'desc'     => $descIn,
      'q'        => ($qIn !== '' ? $qIn : null),
      'rows'     => $rows,
      'next'     => (count($rows) === $limit) ? ($offset + $limit) : null,
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
  }

  // -------------------------
  // POST: ingest JSON payload
  // -------------------------
  if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode([
      'ok' => false,
      'error' => 'method_not_allowed',
      'req_id' => $reqId,
      'allowed' => ['GET','POST','OPTIONS'],
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Read JSON body (once), support gzip/deflate, PHP 7.3 safe
  try {
    list($data, $raw, $hdr) = read_json_body();
    dbg_log($hdr, $raw, true);
  } catch (Throwable $e) {
    dbg_log(function_exists('getallheaders') ? getallheaders() : [], '', false, $e->getMessage());
    http_response_code(400);
    echo json_encode([
      'ok' => false,
      'error' => 'bad_request',
      'message' => $e->getMessage(),
      'req_id' => $reqId
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n";
    exit;
  }

  if ($raw === false) $raw = '';
  $maxBodyBytes = (!$isAuthed && $publicMode === 'guest') ? $guestMaxBodyBytes : MAX_BODY_BYTES;
  if (strlen($raw) > $maxBodyBytes) {
    http_response_code(413);
    echo json_encode([
      'ok' => false,
      'error' => 'payload_too_large',
      'limit' => $maxBodyBytes,
      'req_id' => $reqId
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
  }

  if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
      'ok' => false,
      'error' => 'invalid_json',
      'req_id' => $reqId
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Decide table + db
  //$table = isset($data['service']) ? (string)$data['service'] : 'generic_input';
  $table = $data['table'] ?? ($data['service'] ?? 'generic_input');

  $table = sanitize_identifier($table);
  if ($table === '') $table = 'generic_input';

  $dbPath = getDatabasePath($data); // uses db/service

  // Guest boundary: force all unauthenticated writes into a dedicated DB and
  // restrict tables to an allowlist.
  if (!$isAuthed && $publicMode === 'guest') {
    if (!isset($guestTableAllow[$table])) {
      http_response_code(403);
      echo json_encode([
        'ok' => false,
        'error' => 'forbidden',
        'reason' => 'guest_table_not_allowed',
        'req_id' => $reqId,
        'table' => $table,
      ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
      exit;
    }
    $dbPath = $guestDbPath;
  }
  ensure_db_dir($dbPath);

  // SQLite connect
  $pdo = new PDO("sqlite:$dbPath", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec("PRAGMA journal_mode=WAL;");
  $pdo->exec("PRAGMA synchronous=NORMAL;");
  $pdo->exec("PRAGMA busy_timeout=5000;");

  // Meta registry table
  $pdo->exec('CREATE TABLE IF NOT EXISTS meta_registry (
    id INTEGER PRIMARY KEY,
    source_ip TEXT,
    user_agent TEXT,
    created_at TEXT,
    last_modified TEXT,
    notes TEXT
  );');

  // Build schema + standard meta cols
  $schema = buildSchemaFromJson($data);
  $schema['id'] = ['type' => 'INTEGER PRIMARY KEY AUTOINCREMENT'];
  $schema['received_at'] = ['type' => "TEXT DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))"];
  $schema['source_ip'] = ['type' => 'TEXT'];
  $schema['user_agent'] = ['type' => 'TEXT'];
  $schema['raw_json'] = ['type' => 'TEXT'];

  $pdo->beginTransaction();

  // Ensure table exists and columns exist
  $tableCreated = ensureTableAndColumns($pdo, $table, $schema);

  // Flatten row payload (arrays->JSON strings, bool->int)
  $row = [];
  foreach ($data as $k => $v) {
    $col = sanitize_identifier((string)$k);
    if ($col === '' || $col === 'id') continue;

    if (is_array($v) || is_object($v)) {
      $row[$col] = json_encode($v, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    } elseif (is_bool($v)) {
      $row[$col] = (int)$v;
    } else {
      $row[$col] = $v;
    }
  }

  // Meta
  $row['source_ip']  = $clientIp;
  $row['user_agent'] = $ua;
  $row['raw_json']   = $raw;

  // Insert
  $insertedId = safeInsert($pdo, $table, $row);

  // Log meta_registry
  $now = date('c');
  $metaStmt = $pdo->prepare('INSERT INTO meta_registry (source_ip, user_agent, created_at, last_modified, notes)
                             VALUES (?, ?, ?, ?, ?)');
  $metaStmt->execute([
    $clientIp,
    $ua,
    $now,
    $now,
    "Inbox ingest to $table (#$insertedId)"
  ]);

  $pdo->commit();

  // Receipt
  $elapsed = (int)round((microtime(true) - $start) * 1000);

  $receipt = [
    'ok'           => true,
    'service'      => defined('IER_SERVICE_NAME') ? IER_SERVICE_NAME : 'iernc-api',
    'endpoint'     => 'v1/inbox',
    'mode'         => 'ingest',
    'req_id'       => $reqId,
    'db'           => basename($dbPath),
    'table'        => $table,
    'insert_id'    => $insertedId,
    'table_created'=> (bool)$tableCreated,
    'timestamp'    => $now,
    'ms'           => $elapsed,
    'field_count'  => count($data),
    'mapped_fields'=> array_keys($row),
  ];

  // Your existing registry logger
  logRequest([
    'endpoint'  => 'inbox',
    'status'    => 'success',
    'db'        => basename($dbPath),
    'table'     => $table,
    'timestamp' => $now,
    'source_ip' => $clientIp,
    'req_id'    => $reqId,
    'ms'        => $elapsed,
  ]);

  http_response_code(201);
  echo json_encode($receipt, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }

  http_response_code(500);
  $resp = [
    'ok' => false,
    'error' => 'inbox_failed',
    'req_id' => isset($reqId) ? $reqId : null,
    'message' => $e->getMessage(),
  ];
  echo json_encode($resp, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

  try {
    logRequest([
      'endpoint'  => 'inbox',
      'status'    => 'error',
      'message'   => $e->getMessage(),
      'timestamp' => date('c'),
      'source_ip' => $clientIp ?? ($_SERVER['REMOTE_ADDR'] ?? null),
      'req_id'    => $reqId ?? null,
    ]);
  } catch (Throwable $e2) {}

  exit;
}

// ---------------- helpers local to this endpoint ----------------

function ensure_db_dir(string $dbPath): void {
  $dir = dirname($dbPath);
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0770, true) && !is_dir($dir)) {
      throw new RuntimeException("Cannot create db dir: $dir");
    }
  }
}

/**
 * Insert with fully-quoted identifiers and named params.
 * Returns last insert rowid.
 */
function safeInsert(PDO $pdo, string $table, array $row): int {
  if (!$row) $row = [];
  $cols = array_map(function($c){ return '`'.$c.'`'; }, array_keys($row));
  $params = array_map(function($c){ return ':'.$c; }, array_keys($row));
  $sql = "INSERT INTO `{$table}` (".implode(',', $cols).") VALUES (".implode(',', $params).")";
  $stmt = $pdo->prepare($sql);
  foreach ($row as $k => $v) $stmt->bindValue(':'.$k, $v);
  $stmt->execute();
  return (int)$pdo->lastInsertId();
}

// --- Headers polyfill ---
if (!function_exists('getallheaders')) {
  function getallheaders() {
    $headers = [];
    foreach ($_SERVER as $name => $value) {
      if (strpos($name, 'HTTP_') === 0) {
        $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
        $headers[$key] = $value;
      }
    }
    if (isset($_SERVER['CONTENT_TYPE']))    $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
    if (isset($_SERVER['CONTENT_LENGTH']))  $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
    if (isset($_SERVER['CONTENT_ENCODING']))$headers['Content-Encoding'] = $_SERVER['CONTENT_ENCODING'];
    return $headers;
  }
}

// --- Read JSON body once, support gzip/deflate, decode with BIGINT as string (PHP 7.3 safe) ---
function read_json_body(): array {
  $hdr = getallheaders();
  $enc = strtolower($hdr['Content-Encoding'] ?? '');

  static $cached = null;
  if ($cached === null) $cached = file_get_contents('php://input');
  $raw = $cached;

  if ($raw !== '' && $raw !== false) {
    if ($enc === 'gzip' || $enc === 'x-gzip') {
      $decoded = @gzdecode($raw);
      if ($decoded !== false) $raw = $decoded;
    } elseif ($enc === 'deflate') {
      $decoded = @gzinflate($raw);
      if ($decoded !== false) $raw = $decoded;
    }
  }

  if ($raw === '' || $raw === false) {
    throw new RuntimeException('empty-body');
  }

  $data = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
  if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    throw new RuntimeException('bad-json: ' . json_last_error_msg());
  }
  return [$data, $raw, $hdr];
}

// --- Tiny debug tap (safe logging) ---
function dbg_log(array $hdr, $raw, $ok=true, $note='') {
  $line = json_encode([
    'ts' => date('c'),
    'ok' => $ok,
    'note' => $note,
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'len' => strlen($raw ?? ''),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'enc' => $hdr['Content-Encoding'] ?? '',
    'ctype' => $hdr['Content-Type'] ?? '',
    'clen' => $hdr['Content-Length'] ?? '',
  ], JSON_UNESCAPED_SLASHES);
  @file_put_contents('/web/private/logs/inbox-debug.log', $line . "\n", FILE_APPEND);
}
