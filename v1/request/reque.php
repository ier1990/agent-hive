<?php
// /web/api.iernc.net/public_html/v1/reque.php (incoming queue)
declare(strict_types=1);



ini_set('log_errors','1');
ini_set('display_errors','0'); // keep off in prod
ini_set('error_log','/web/private/logs/reque-error.log');



// ---------- CORS ----------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Always send JSON responses
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

#umask(0007); // new files: 660; new dirs: 770

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/schema_builder.php';
require_once dirname(__DIR__, 2) . '/lib/registry_logger.php';

// Enforce API key / IP allowlist and rate limits (defined in bootstrap)
api_guard_once('incoming', false);

// ---------- Config ----------
const MAX_BODY_BYTES = 2_000_000; // ~2MB cap (tweak)

$start = microtime(true);
$reqId = bin2hex(random_bytes(16)); // 32-char receipt

try {
    // ---------- Method routing ----------
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';


  if ($method === 'GET') {
        // Query params: db, service, table, limit, offset, order, desc, q, f_<col>=value
        $dbParam   = $_GET['db']      ?? ($_GET['service'] ?? null);
        $tableIn   = $_GET['table']   ?? 'generic_input';
        $limitIn   = (int)($_GET['limit']  ?? 100);
        $offsetIn  = (int)($_GET['offset'] ?? 0);
        $orderIn   = $_GET['order']   ?? 'received_at';
        $descIn    = ($_GET['desc']   ?? '1') !== '0';
        $qIn       = $_GET['q']       ?? null;

  // Fixed DB path for incoming queue
  $dbPath = '/web/private/db/requee.db';
        ensure_db_dir($dbPath);

        $table = sanitize_identifier((string)$tableIn);
        if ($table === '') $table = 'generic_input';

  $pdo = new PDO("sqlite:$dbPath", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
  $pdo->exec("PRAGMA journal_mode=WAL;");
  $pdo->exec("PRAGMA synchronous=NORMAL;");
  $pdo->exec("PRAGMA busy_timeout=10000;");

  // If a req_id is provided, lookup across tables (so client need not know table)
  $reqIdLookup = $_GET['req_id'] ?? null;
  if (is_string($reqIdLookup)) {
    $reqIdLookup = trim($reqIdLookup);
    if ($reqIdLookup !== '' && preg_match('/^[A-Fa-f0-9]{32}$/', $reqIdLookup)) {
      // Find candidate tables with a req_id column
      $tablesStmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT IN ('sqlite_sequence','meta_registry')");
      $found = null; $foundTable = null;
      foreach ($tablesStmt->fetchAll(PDO::FETCH_COLUMN, 0) as $tname) {
        try {
          $colsInfo = $pdo->query("PRAGMA table_info(`$tname`)")->fetchAll();
          $hasReq = false; $hasRaw = false;
          foreach ($colsInfo as $ci) {
            $n = strtolower($ci['name'] ?? '');
            if ($n === 'req_id') $hasReq = true;
            if ($n === 'raw_json') $hasRaw = true;
          }
          if (!$hasReq) continue;
          $st2 = $pdo->prepare("SELECT * FROM `".$tname."` WHERE `req_id` = :rid ORDER BY rowid DESC LIMIT 1");
          $st2->bindValue(':rid', $reqIdLookup, PDO::PARAM_STR);
          $st2->execute();
          $row = $st2->fetch(PDO::FETCH_ASSOC);
          if ($row) { $found = $row; $foundTable = $tname; break; }
        } catch (Throwable $e) { continue; }
      }
      if ($found) {
        if (isset($_GET['parsed']) && $_GET['parsed'] === '1' && isset($found['raw_json'])) {
          $parsed = json_decode((string)$found['raw_json'], true);
          if (json_last_error() === JSON_ERROR_NONE) $found['doc_parsed'] = $parsed;
        }
        echo json_encode([
          'status' => 'ok',
          'db' => $dbPath,
          'table' => $foundTable,
          'req_id' => $reqIdLookup,
          'row' => $found,
        ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
      }
      http_response_code(404);
      echo json_encode(['error'=>'not_found','req_id'=>$reqIdLookup]);
      exit;
    }
  }

  // Table must exist
        if (!tableExists($pdo, $table)) {
            http_response_code(404);
            echo json_encode(['error'=>'table_not_found','db'=>$dbPath,'table'=>$table]);
            exit;
        }

  // Ensure a basic index for faster reads
  try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_".$table."_received_at ON `".$table."`(received_at)"); } catch (Throwable $e) {}

        // Introspect columns so we can safely filter/order
        $cols = [];
        $textCols = [];
        $stmt = $pdo->query("PRAGMA table_info(`$table`)");
        foreach ($stmt->fetchAll() as $c) {
            $name = strtolower($c['name']);
            $cols[$name] = $c['name']; // original case
            $type = strtoupper((string)$c['type']);
            if (str_contains($type, 'TEXT')) $textCols[] = $c['name'];
        }

        // Limit/offset sane bounds
        $limit  = max(1, min($limitIn, 1000));
        $offset = max(0, $offsetIn);

        // Order by validated column (fallback to rowid)
        $orderCol = $cols[strtolower($orderIn)] ?? ($cols['received_at'] ?? 'rowid');
        $orderDir = $descIn ? 'DESC' : 'ASC';

        // Build WHERE from f_<col>=... and optional q across TEXT columns
        $wheres = [];
        $params = [];

        foreach ($_GET as $k => $v) {
            if (!str_starts_with($k, 'f_')) continue;
            $colKey = substr($k, 2); // after f_
            $col    = $cols[strtolower($colKey)] ?? null;
            if (!$col) continue;
            $paramName = 'f_' . $col;
            $wheres[] = "`$col` = :$paramName";
            $params[":$paramName"] = $v;
        }

        if ($qIn && $textCols) {
            $likeParts = [];
            foreach ($textCols as $tc) { $likeParts[] = "`$tc` LIKE :_q"; }
            $wheres[] = '(' . implode(' OR ', $likeParts) . ')';
            $params[':_q'] = '%' . $qIn . '%';
        }

        $whereSql = $wheres ? ('WHERE ' . implode(' AND ', $wheres)) : '';
        $sql = "SELECT * FROM `$table` $whereSql ORDER BY `$orderCol` $orderDir LIMIT :_limit OFFSET :_offset";

        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) { $st->bindValue($k, $v); }
        $st->bindValue(':_limit',  $limit,  PDO::PARAM_INT);
        $st->bindValue(':_offset', $offset, PDO::PARAM_INT);
        $st->execute();
    $rows = $st->fetchAll();

    // Optional parse of raw_json for convenience symmetry with reque2
    if (isset($_GET['parsed']) && $_GET['parsed'] === '1') {
      foreach ($rows as &$r) {
        if (isset($r['raw_json']) && is_string($r['raw_json']) && $r['raw_json'] !== '') {
          $parsed = json_decode($r['raw_json'], true);
          if (json_last_error() === JSON_ERROR_NONE) $r['doc_parsed'] = $parsed;
        }
      }
      unset($r);
    }

        echo json_encode([
            'status'  => 'ok',
            'db'      => $dbPath,
            'table'   => $table,
            'limit'   => $limit,
            'offset'  => $offset,
            'order'   => $orderCol,
            'desc'    => $descIn,
          'filters' => array_keys(array_filter($_GET, function ($k) {
            return str_starts_with($k, 'f_');
          }, ARRAY_FILTER_USE_KEY)),
            'q'       => $qIn,
            'rows'    => $rows,
            'next'    => count($rows) === $limit ? ($offset + $limit) : null
        ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'method_not_allowed', 'req_id' => $reqId]);
        exit;
    }



    // ---------- Content-Type + size ----------
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if ($ct && stripos($ct, 'application/json') === false) {
        // Let it pass but warn – some clients forget to set it
        // You can hard fail instead:
        // http_response_code(415); ...
    }

    //$raw = file_get_contents('php://input');
    //[$data, $raw, $hdr] = read_json_body();
try {
  [$data, $raw, $hdr] = read_json_body();
  dbg_log($hdr, $raw, true);
} catch (Throwable $e) {
  dbg_log(getallheaders(), '', false, $e->getMessage());
  http_response_code(400);
  echo json_encode(['error'=>'bad_request','message'=>$e->getMessage()], JSON_UNESCAPED_SLASHES), "\n";
  exit;
}

    if ($raw === false) $raw = '';

    if (strlen($raw) > MAX_BODY_BYTES) {
        http_response_code(413);
        echo json_encode(['error' => 'payload_too_large', 'limit' => MAX_BODY_BYTES, 'req_id' => $reqId]);
        exit;
    }

    $data = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
    if (!is_array($data)) {
        $err = json_last_error_msg();
        http_response_code(400);
        echo json_encode(['error' => 'invalid_json', 'detail' => $err, 'req_id' => $reqId]);
        exit;
    }

  // ---------- Routing (db + table) ----------
    // Table defaults to service or 'generic_input'
  $table = isset($data['service']) ? $data['service'] : 'generic_input';
    $table = sanitize_identifier((string)$table);              // e.g., "my-service" -> "my_service"
    if ($table === '') $table = 'generic_input';

  // Fixed DB path for incoming queue
  $dbPath = '/web/private/db/requee.db';
    ensure_db_dir($dbPath);

    // ---------- SQLite connection ----------
  $pdo = new PDO("sqlite:$dbPath", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  $pdo->exec("PRAGMA journal_mode=WAL;");
  $pdo->exec("PRAGMA synchronous=NORMAL;");
  $pdo->exec("PRAGMA busy_timeout=10000;");

    // ---------- Registry meta table ----------
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta_registry (
      id INTEGER PRIMARY KEY,
      source_ip TEXT,
      user_agent TEXT,
      created_at TEXT,
      last_modified TEXT,
      notes TEXT
    );');

  // ---------- Build schema (safe) ----------
    // Always include metadata columns on every ingest table
    $schema = buildSchemaFromJson($data);                      // infers types + quotes cols safely
    $schema['id'] = ['type' => 'INTEGER PRIMARY KEY AUTOINCREMENT'];
    $schema['received_at'] = ['type' => "TEXT DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))"];
    $schema['source_ip'] = ['type' => 'TEXT'];
    $schema['user_agent'] = ['type' => 'TEXT'];
    $schema['raw_json'] = ['type' => 'TEXT'];                  // full payload for fidelity
  // Stable external reference ID for the entry
  $schema['uid'] = ['type' => 'TEXT'];

    $pdo->beginTransaction();

    // OLD:
    // $tableCreated = createTableIfMissing($pdo, $table, $schema);

    // NEW:
  $tableCreated = ensureTableAndColumns($pdo, $table, $schema);
  try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_".$table."_received_at ON `".$table."`(received_at)"); } catch (Throwable $e) {}
  // Ensure uniqueness and fast lookup of uid
  try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_".$table."_uid ON `".$table."`(uid)"); } catch (Throwable $e) {}


  // ---------- Flatten row payload ----------
    // Convert arrays/objects to JSON strings; sanitize/quote column names
    $row = [];
  // Generate unique external id (32 hex chars)
  $uid = bin2hex(random_bytes(16));
    foreach ($data as $k => $v) {
        $col = sanitize_identifier((string)$k);
        if ($col === '' || $col === 'id') continue;            // skip invalid or clash with PK
        if (is_array($v) || is_object($v)) {
            $row[$col] = json_encode($v, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        } elseif (is_bool($v)) {
            $row[$col] = (int)$v;
        } else {
            $row[$col] = $v;
        }
    }
    // Meta columns
    $row['source_ip']  = $_SERVER['REMOTE_ADDR'] ?? null;
    $row['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $row['raw_json']   = $raw;
  $row['uid']        = $uid;

    // ---------- Insert ----------
    // Insert with minimal retry in the unlikely event of uid collision
    $insertedId = 0;
    for ($attempt=0; $attempt<3; $attempt++) {
      try {
        $insertedId = safeInsert($pdo, $table, $row);
        break;
      } catch (Throwable $ex) {
        // If unique constraint on uid, regenerate and retry
        if (strpos($ex->getMessage(), 'UNIQUE') !== false && strpos($ex->getMessage(), '(uid)') !== false) {
          $row['uid'] = $uid = bin2hex(random_bytes(16));
          continue;
        }
        throw $ex;
      }
    }

    // ---------- Log into meta_registry ----------
    $metaStmt = $pdo->prepare('INSERT INTO meta_registry (source_ip, user_agent, created_at, last_modified, notes)
                               VALUES (?, ?, ?, ?, ?)');
    $now = date('c');
    $metaStmt->execute([
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
        $now, $now,
        "Auto-ingest to $table (#$insertedId)"
    ]);

    $pdo->commit();

    // ---------- Receipt ----------
    $elapsed = round((microtime(true) - $start) * 1000);
    $receipt = [
        'status'     => 'success',
        'req_id'     => $reqId,
        'db'         => basename($dbPath),
        'db_path'    => $dbPath,
        'table'      => $table,
  'insert_id'  => $insertedId,
  'uid'        => $uid,
        'table_created' => (bool)$tableCreated,
        'timestamp'  => $now,
        'ms'         => $elapsed,
        'field_count'=> count($data),
        'mapped_fields' => array_keys($row),
    ];

    logRequest([
  'endpoint'  => 'incoming',
        'status'    => 'success',
        'db'        => basename($dbPath),
        'table'     => $table,
        'timestamp' => $now,
        'source_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'req_id'    => $reqId,
        'ms'        => $elapsed,
    ]);

    http_response_code(201);
    echo json_encode($receipt, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    $resp = ['error' => 'intake_failed', 'req_id' => $reqId, 'message' => $e->getMessage()];
    echo json_encode($resp);
    try {
    logRequest([
      'endpoint'  => 'incoming',
            'status'    => 'error',
            'message'   => $e->getMessage(),
            'timestamp' => date('c'),
            'source_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'req_id'    => $reqId,
        ]);
    } catch (Throwable $e2) {}
    exit;
}

// ------------- helpers local to this endpoint -------------
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
    // Quote identifiers with backticks (SQLite accepts them). Double any backticks inside (after sanitize, there shouldn’t be any).
  $cols = array_map(function ($c) { return '`' . $c . '`'; }, array_keys($row));
  $params = array_map(function ($c) { return ':' . $c; }, array_keys($row));
    $sql = "INSERT INTO `{$table}` (".implode(',', $cols).") VALUES (".implode(',', $params).")";
    $stmt = $pdo->prepare($sql);
    foreach ($row as $k => $v) { $stmt->bindValue(':'.$k, $v); }
    $stmt->execute();
    return (int)$pdo->lastInsertId();
}









// --- Helpers ---
if (!function_exists('getallheaders')) {
  function getallheaders() {
    $headers = [];
    foreach ($_SERVER as $name => $value) {
      if (strpos($name, 'HTTP_') === 0) {
        $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
        $headers[$key] = $value;
      }
    }
    if (isset($_SERVER['CONTENT_TYPE']))    $headers['Content-Type']    = $_SERVER['CONTENT_TYPE'];
    if (isset($_SERVER['CONTENT_LENGTH']))  $headers['Content-Length']  = $_SERVER['CONTENT_LENGTH'];
    if (isset($_SERVER['CONTENT_ENCODING']))$headers['Content-Encoding']= $_SERVER['CONTENT_ENCODING'];
    return $headers;
  }
}

function read_json_body(): array {
  $hdr = getallheaders();
  $enc = strtolower($hdr['Content-Encoding'] ?? '');
  $ctype = strtolower($hdr['Content-Type'] ?? '');
  // Read ONCE and cache
  static $cached = null;
  if ($cached === null) $cached = file_get_contents('php://input');
  $raw = $cached;

  // Handle common encodings
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

  // Try JSON either way (don’t hard fail just because Content-Type is wrong)
  $data = json_decode($raw, true);
  if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    // Optional: accept NDJSON arrays if you want
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
  // Make sure this file is writable by www-data group: chmod 660; chown samekhi:www-data
  @file_put_contents('/web/private/logs/reque-debug.log', $line . "\n", FILE_APPEND);
}









