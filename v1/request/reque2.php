<?php
// /public_html/v1/reque.php
defined('APP_BOOTSTRAPPED') || require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

// --- logging (separate from the rest) ---
@mkdir('/web/private/logs', 0770, true);
ini_set('log_errors', '1');
ini_set('error_log', '/web/private/logs/reque-error.log');
$DBG_FILE = '/web/private/logs/reque-debug.log';
function dbg($m){ file_put_contents($GLOBALS['DBG_FILE'], '['.gmdate('c')."] $m\n", FILE_APPEND); }

// --- guard like others ---
api_guard_once('incoming', false);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// --- fixed DB path ---
$DBFILE = '/web/private/db/requee.db';
@mkdir(dirname($DBFILE), 0770, true);
$db = new SQLite3($DBFILE);
$db->busyTimeout(3000);
$db->exec('PRAGMA journal_mode=WAL;');
$db->exec('PRAGMA synchronous=NORMAL;');
$db->exec('PRAGMA foreign_keys=ON;');

// --- helpers ---
function bad($code, $err){ http_response_code($code); echo json_encode(['error'=>$err], JSON_UNESCAPED_SLASHES); exit; }
function ok($code, $data){ http_response_code($code); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
function sanitize_table($t){
  $t = trim($t);
  if ($t === '') return '';
  if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $t)) bad(400,'bad_table');
  return $t;
}
// infer a simple column type for scalar values
function infer_type($v){
  if (is_int($v)) return 'INTEGER';
  if (is_float($v)) return 'REAL';
  if (is_bool($v)) return 'INTEGER'; // store 0/1
  return 'TEXT';
}
function col_exists($db, $table, $col){
  $stmt = $db->prepare("PRAGMA table_info($table)");
  $res = $stmt->execute();
  while ($r = $res->fetchArray(SQLITE3_ASSOC)){
    if (strcasecmp($r['name'],$col)===0) return true;
  }
  return false;
}
function ensure_table($db, $table){
  $sql = "CREATE TABLE IF NOT EXISTS $table (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    service     TEXT,
    ts_iso      TEXT,
    created_at  INTEGER NOT NULL,
    client_ip   TEXT,
    doc         TEXT NOT NULL
  )";
  $db->exec($sql);
  $db->exec("CREATE INDEX IF NOT EXISTS idx_{$table}_created ON $table(created_at)");
}
function evolve_schema($db, $table, $doc){
  // add columns for top-level scalars (skip known cols / huge values)
  $skip = ['id','service','ts_iso','created_at','client_ip','doc'];
  foreach ($doc as $k => $v){
    if (in_array($k,$skip,true)) continue;
    if (is_array($v) || is_object($v) || $v === null) continue;
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $k)) continue;
    if (!col_exists($db, $table, $k)){
      $type = infer_type($v);
      @$db->exec("ALTER TABLE $table ADD COLUMN $k $type");
    }
  }
}

$method = $_SERVER['REQUEST_METHOD'];

// GET: list last N rows from a given table (default: generic_input)
if ($method === 'GET'){
  $table = sanitize_table($_GET['table'] ?? 'generic_input');
  $limit = (int)($_GET['limit'] ?? 50);
  if ($limit < 1 || $limit > 500) $limit = 50;

  ensure_table($db, $table);

  $stmt = $db->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT :lim");
  $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
  $rows = [];
  $res = $stmt->execute();
  while ($r = $res->fetchArray(SQLITE3_ASSOC)){
    // decode doc JSON if caller asked for parsed
    if (isset($_GET['parsed']) && $_GET['parsed']==='1' && !empty($r['doc'])){
      $r['doc_parsed'] = json_decode($r['doc'], true);
    }
    $rows[] = $r;
  }
  ok(200, ['ok'=>true,'table'=>$table,'count'=>count($rows),'items'=>$rows]);
}

// POST: insert universal JSON into service-named (or generic) table
if ($method === 'POST'){
  $raw = file_get_contents('php://input');
  if ($raw === '' || $raw === false) bad(400,'empty_body');
  $json = json_decode($raw, true);
  if (!is_array($json)) bad(400,'invalid_json');

  // choose table: prefer "service" else ?table= else generic_input
  $service = isset($json['service']) && is_string($json['service']) ? trim($json['service']) : '';
  $table = sanitize_table($service !== '' ? $service : ($_GET['table'] ?? 'generic_input'));

  ensure_table($db, $table);
  evolve_schema($db, $table, $json);

  // compute ts + client ip
  $ts_iso = gmdate('c');
  $created = time();
  $client_ip = rl_client_ip($GLOBALS['TRUSTED_PROXIES'] ?? []);

  // build INSERT with dynamic columns
  $cols = ['service','ts_iso','created_at','client_ip','doc'];
  $vals = [$service, $ts_iso, $created, $client_ip, json_encode($json, JSON_UNESCAPED_SLASHES)];
  $ph   = ['?','?','?','?','?'];

  foreach ($json as $k => $v){
    if (in_array($k, ['service','ts_iso','created_at','client_ip','doc'], true)) continue;
    if (is_array($v) || is_object($v) || $v === null) continue;
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $k)) continue;
    $cols[] = $k;
    $ph[]   = '?';
    if (is_bool($v)) $v = $v ? 1 : 0;
    $vals[] = $v;
  }

  $sql = "INSERT INTO $table (".implode(',', $cols).") VALUES (".implode(',', $ph).")";
  $stmt = $db->prepare($sql);
  foreach ($vals as $i => $v){
    $stmt->bindValue($i+1, $v, is_int($v)||is_float($v) ? SQLITE3_NUM : SQLITE3_TEXT);
  }

  $ok = $stmt->execute();
  if (!$ok){ dbg("insert_failed table=$table sql=$sql"); bad(500,'insert_failed'); }

  $id = $db->lastInsertRowID();
  ok(201, ['ok'=>true,'table'=>$table,'id'=>$id,'created_at'=>$created,'ts_iso'=>$ts_iso]);
}

// others
bad(405,'method_not_allowed');
