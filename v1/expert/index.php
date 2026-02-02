<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
api_guard_once('push', true);

$SCRIPTS_DIR = '/web/private/scripts';
@mkdir($SCRIPTS_DIR, 0775, true);


// ---- config ----------------------------------------------------------------
$EXPERTS_ROOT = '/web/private/experts';                 // folder per expert
@mkdir($EXPERTS_ROOT, 0775, true);
$CHROMA_NS_PREFIX = 'expert:';                     // namespace prefix
$PY_INDEXER = '/web/AI/bin/expert_indexer.py';     // python indexer
$PY_ASK     = '/web/AI/bin/expert_ask.py';         // python runtime
$ALLOW_URL_PULL = true;                            // allow ingest by URL

// ---- router ----------------------------------------------------------------
$reqPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');            // /v1/expert
$rel  = preg_replace('#^' . preg_quote($base, '#') . '/?#', '', $reqPath);
$method = $_SERVER['REQUEST_METHOD'];

// normalize JSON body
$body = [];
if (in_array($method, ['POST','PUT','PATCH'])) {
  $raw = file_get_contents('php://input');
  if ($raw && empty($_FILES)) {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) $body = $tmp;
  }
}

// ---- db: ensure schema ------------------------------------------------------
$dbh = dbh();
$dbh->exec("
CREATE TABLE IF NOT EXISTS experts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT UNIQUE NOT NULL,
  make TEXT NOT NULL,
  model TEXT NOT NULL,
  title TEXT,
  namespace TEXT NOT NULL,
  status TEXT DEFAULT 'ready',
  doc_count INTEGER DEFAULT 0,
  embed_count INTEGER DEFAULT 0,
  notes_count INTEGER DEFAULT 0,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  last_indexed_at TEXT
);
");
$dbh->exec("
CREATE TABLE IF NOT EXISTS expert_docs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  expert_id INTEGER NOT NULL,
  source_type TEXT NOT NULL,    -- 'upload' | 'url'
  source_uri TEXT,              -- original name or URL
  local_path TEXT NOT NULL,     -- path under /experts/{slug}
  sha256 TEXT NOT NULL,
  bytes INTEGER NOT NULL,
  added_at TEXT NOT NULL,
  indexed_at TEXT,
  status TEXT DEFAULT 'new',
  UNIQUE(expert_id, sha256),
  FOREIGN KEY(expert_id) REFERENCES experts(id) ON DELETE CASCADE
);
");
$dbh->exec("
CREATE TABLE IF NOT EXISTS expert_jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  expert_id INTEGER NOT NULL,
  kind TEXT NOT NULL,    -- 'reindex'
  payload_json TEXT,
  status TEXT DEFAULT 'queued',
  created_at TEXT NOT NULL,
  started_at TEXT,
  finished_at TEXT,
  error TEXT,
  FOREIGN KEY(expert_id) REFERENCES experts(id) ON DELETE CASCADE
);
");
$dbh->exec("
CREATE TABLE IF NOT EXISTS expert_notes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  expert_id INTEGER NOT NULL,
  kind TEXT DEFAULT 'note',
  title TEXT,
  body_md TEXT,
  created_at TEXT NOT NULL,
  FOREIGN KEY(expert_id) REFERENCES experts(id) ON DELETE CASCADE
);
");
// Track bootstrap/discovery requests keyed by req_id (uid)
$dbh->exec("
CREATE TABLE IF NOT EXISTS expert_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  expert_id INTEGER NOT NULL,
  req_id TEXT UNIQUE NOT NULL,
  purpose TEXT,
  requester TEXT,
  seeds_json TEXT,
  keywords_json TEXT,
  status TEXT DEFAULT 'queued',
  created_at TEXT NOT NULL,
  started_at TEXT,
  finished_at TEXT,
  error TEXT,
  meta_json TEXT,
  FOREIGN KEY(expert_id) REFERENCES experts(id) ON DELETE CASCADE
);
");
$dbh->exec("
CREATE TABLE IF NOT EXISTS expert_queries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  expert_id INTEGER NOT NULL,
  question TEXT NOT NULL,
  answer TEXT,
  citations_json TEXT,
  confidence REAL,
  created_at TEXT NOT NULL,
  FOREIGN KEY(expert_id) REFERENCES experts(id) ON DELETE CASCADE
);
");

// ---- utils -----------------------------------------------------------------
function jexit($arr, int $code=200) {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}
function slugify($s) { return trim(preg_replace('~[^a-z0-9]+~','-', strtolower($s)),'-'); }
function now_iso() { return date('c'); }
function expert_dir(string $slug) { global $EXPERTS_ROOT; return "$EXPERTS_ROOT/$slug"; }
function ensure_dirs(string $slug) {
  $root = expert_dir($slug);
  @mkdir("$root/_inbox", 0775, true);
  @mkdir("$root/docs", 0775, true);
  @mkdir("$root/media", 0775, true);
  @mkdir("$root/meta", 0775, true);
  if (!file_exists("$root/quickstart.md")) file_put_contents("$root/quickstart.md", "# $slug Quickstart\n\n");
  if (!file_exists("$root/faq.md"))        file_put_contents("$root/faq.md",        "# $slug FAQ\n\n");
  if (!file_exists("$root/meta/metadata.json")) {
    $ns = $GLOBALS['CHROMA_NS_PREFIX'] . $slug;
    file_put_contents("$root/meta/metadata.json", json_encode([
      'slug'=>$slug,'namespace'=>$ns,'embed_model'=>'snowflake-arctic-embed-gguf',
      'llm_model'=>'qwen2.5-7b-instruct','chunk'=>['max_chars'=>1200,'overlap'=>150],
      'created_at'=>now_iso()
    ], JSON_PRETTY_PRINT));
  }
  return $root;
}
function gen_uid32(){ return bin2hex(random_bytes(16)); }
function db_get_expert_by_slug(string $slug) {
  $q = dbh()->prepare("SELECT * FROM experts WHERE slug=?");
  $q->execute([$slug]); return $q->fetch(PDO::FETCH_ASSOC);
}
function db_insert_request(int $expert_id, string $req_id, ?string $purpose, ?string $requester, array $seeds=[], array $keywords=[]){
  $q = dbh()->prepare("INSERT INTO expert_requests (expert_id,req_id,purpose,requester,seeds_json,keywords_json,created_at) VALUES (?,?,?,?,?,?,?)");
  $q->execute([$expert_id,$req_id,$purpose,$requester,json_encode($seeds, JSON_UNESCAPED_SLASHES),json_encode($keywords, JSON_UNESCAPED_SLASHES),now_iso()]);
}
function db_upsert_expert(string $slug, string $make, string $model, ?string $title) {
  $exists = db_get_expert_by_slug($slug);
  $ns = $GLOBALS['CHROMA_NS_PREFIX'] . $slug;
  if ($exists) {
    $q = dbh()->prepare("UPDATE experts SET make=?, model=?, title=COALESCE(?,title), updated_at=? WHERE slug=?");
    $q->execute([$make,$model,$title,now_iso(),$slug]);
    return db_get_expert_by_slug($slug);
  }
  $q = dbh()->prepare("INSERT INTO experts (slug,make,model,title,namespace,status,created_at,updated_at) VALUES (?,?,?,?,?,'ready',?,?)");
  $t = now_iso();
  $q->execute([$slug,$make,$model,$title,$ns,$t,$t]);
  return db_get_expert_by_slug($slug);
}
function db_list_experts() {
  $q = dbh()->query("SELECT id,slug,make,model,title,status,doc_count,embed_count,notes_count,last_indexed_at,created_at,updated_at FROM experts ORDER BY updated_at DESC");
  return $q->fetchAll(PDO::FETCH_ASSOC);
}
function db_add_doc($expert_id, $source_type, $source_uri, $local_path, $sha256, $bytes) {
  $q = dbh()->prepare("INSERT OR IGNORE INTO expert_docs (expert_id,source_type,source_uri,local_path,sha256,bytes,added_at) VALUES (?,?,?,?,?,?,?)");
  $q->execute([$expert_id,$source_type,$source_uri,$local_path,$sha256,$bytes,now_iso()]);
  // bump doc_count
  dbh()->prepare("UPDATE experts SET doc_count=(SELECT COUNT(*) FROM expert_docs WHERE expert_id=?), updated_at=? WHERE id=?")
      ->execute([$expert_id, now_iso(), $expert_id]);
}
function db_doc_exists($expert_id, $sha) {
  $q = dbh()->prepare("SELECT 1 FROM expert_docs WHERE expert_id=? AND sha256=?");
  $q->execute([$expert_id,$sha]); return (bool)$q->fetchColumn();
}
function normalize_to_docs($srcPath, $docsDir) {
  @mkdir($docsDir, 0775, true);
  $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
  $base = basename($srcPath);
  $dest = "$docsDir/$base";
  // For now: copy as-is; optional: convert .html -> .md here.
  if (!@rename($srcPath, $dest)) { copy($srcPath, $dest); }
  return $dest;
}
function enqueue_reindex($expert_id, $slug) {
  $payload = ['slug'=>$slug, 'namespace'=>$GLOBALS['CHROMA_NS_PREFIX'].$slug];
  // record in expert_jobs table (for audit) AND use your global queue
  $q = dbh()->prepare("INSERT INTO expert_jobs (expert_id,kind,payload_json,status,created_at) VALUES (?,?,?,?,?)");
  $q->execute([$expert_id,'reindex',json_encode($payload),'queued',now_iso()]);
  // also push to your existing queue (if you want a single worker loop)
  queue_enqueue('expert_reindex', $payload);  // uses /v1/lib/queue.php
  return true;
}
function enqueue_discover($expert_id, $slug, $req_id, $purpose, array $seeds, array $keywords){
  $payload = [
    'req_id'=>$req_id,
    'slug'=>$slug,
    'purpose'=>$purpose,
    'seeds'=>$seeds,
    'keywords'=>$keywords,
    // where to report and where to fetch originals
    'incoming_status'=>"/v1/incoming/req/$req_id",
    'outgoing_result'=>"/v1/outgoing/$req_id",
  ];
  if (function_exists('queue_enqueue')) {
    queue_enqueue('expert_discover', $payload);
  }
  return true;
}
function esc_cmd($s) { return escapeshellarg($s); }

// ---- routes ----------------------------------------------------------------

// POST /v1/expert  {make, model, title?}
if ($method==='POST' && ($rel==='' || $rel==='index.php')) {
  $make  = trim($body['make']  ?? '');
  $model = trim($body['model'] ?? '');
  $title = isset($body['title']) ? trim((string)$body['title']) : null;
  if (!$make || !$model) jexit(['ok'=>false,'error'=>'make+model required'], 400);

  $slug = slugify("$make-$model");
  $root = ensure_dirs($slug);
  $row  = db_upsert_expert($slug, $make, $model, $title);

  jexit(['ok'=>true,'slug'=>$slug,'dir'=>$root,'namespace'=>$row['namespace']]);
}

// GET /v1/expert
if ($method==='GET' && ($rel==='' || $rel==='index.php')) {
  jexit(['ok'=>true,'experts'=>db_list_experts()]);
}

// GET /v1/expert/{slug}
if ($method==='GET' && preg_match('#^([a-z0-9\-]+)$#', $rel, $m)) {
  $slug = $m[1];
  $row = db_get_expert_by_slug($slug);
  if (!$row) jexit(['ok'=>false,'error'=>'unknown expert'], 404);
  $root = expert_dir($slug);
  jexit(['ok'=>true,'expert'=>$row,'paths'=>[
    'root'=>$root,'docs'=>"$root/docs",'inbox'=>"$root/_inbox",'meta'=>"$root/meta"
  ]]);
}

// POST /v1/expert/router  {unit:"make:model", title?, purpose?, seeds?:[], keywords?:[]}
if ($method==='POST' && $rel==='router') {
  $unit = isset($body['unit']) ? strtolower(trim((string)$body['unit'])) : '';
  if (!$unit || !str_contains($unit, ':')) jexit(['ok'=>false,'error'=>'unit must be make:model'], 400);
  [$make,$model] = array_map('trim', explode(':', $unit, 2));
  if ($make==='' || $model==='') jexit(['ok'=>false,'error'=>'bad unit'],400);
  $title = isset($body['title']) ? trim((string)$body['title']) : null;
  $purpose = isset($body['purpose']) ? trim((string)$body['purpose']) : null;
  $seeds = isset($body['seeds']) && is_array($body['seeds']) ? $body['seeds'] : [];
  $keywords = isset($body['keywords']) && is_array($body['keywords']) ? $body['keywords'] : [];
  $slug = slugify("$make-$model");
  $root = ensure_dirs($slug);
  $row  = db_upsert_expert($slug, $make, $model, $title);
  $req_id = gen_uid32();
  db_insert_request((int)$row['id'], $req_id, $purpose, null, $seeds, $keywords);
  enqueue_discover((int)$row['id'], $slug, $req_id, $purpose, $seeds, $keywords);
  jexit(['ok'=>true,'slug'=>$slug,'req_id'=>$req_id,'paths'=>[
    'incoming_status'=>"/v1/incoming/req/$req_id",
    'outgoing_result'=>"/v1/outgoing/$req_id",
    'expert'=>"/v1/expert/$slug"
  ]], 202);
}

// POST /v1/expert/{slug}/ingest (file upload OR {"url":"..."})
if ($method==='POST' && preg_match('#^([a-z0-9\-]+)/ingest$#', $rel, $m)) {
  $slug = $m[1];
  $row = db_get_expert_by_slug($slug);
  if (!$row) jexit(['ok'=>false,'error'=>'unknown expert'], 404);
  $root = ensure_dirs($slug);

  // 1) file upload
  if (!empty($_FILES['file'])) {
    $tmp  = $_FILES['file']['tmp_name'];
    $name = basename($_FILES['file']['name']);
    $inbox = "$root/_inbox/$name";
    if (!move_uploaded_file($tmp, $inbox)) jexit(['ok'=>false,'error'=>'upload_failed'], 400);
    $sha = hash_file('sha256', $inbox);
    if (db_doc_exists((int)$row['id'], $sha)) { @unlink($inbox); jexit(['ok'=>true,'dedup'=>true]); }
    $norm = normalize_to_docs($inbox, "$root/docs");
    db_add_doc((int)$row['id'], 'upload', $name, $norm, $sha, filesize($norm));
    jexit(['ok'=>true,'path'=>$norm,'sha256'=>$sha]);
  }

  // 2) url pull
  if (!empty($body['url'])) {
    if (!$ALLOW_URL_PULL) jexit(['ok'=>false,'error'=>'url_pull_disabled'], 403);
    $url = $body['url'];
    $ctx = stream_context_create(['http'=>['timeout'=>20],'https'=>['timeout'=>20]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data===false) jexit(['ok'=>false,'error'=>'fetch_failed'], 400);
    $name = basename(parse_url($url, PHP_URL_PATH)) ?: ('doc_'.time().'.bin');
    $inbox = "$root/_inbox/$name";
    file_put_contents($inbox, $data);
    $sha = hash_file('sha256', $inbox);
    if (db_doc_exists((int)$row['id'], $sha)) { @unlink($inbox); jexit(['ok'=>true,'dedup'=>true]); }
    $norm = normalize_to_docs($inbox, "$root/docs");
    db_add_doc((int)$row['id'], 'url', $url, $norm, $sha, filesize($norm));
    jexit(['ok'=>true,'path'=>$norm,'sha256'=>$sha]);
  }

  jexit(['ok'=>false,'error'=>'no file or url'], 400);
}

// POST /v1/expert/{slug}/reindex
if ($method==='POST' && preg_match('#^([a-z0-9\-]+)/reindex$#', $rel, $m)) {
  $slug = $m[1];
  $row = db_get_expert_by_slug($slug);
  if (!$row) jexit(['ok'=>false,'error'=>'unknown expert'], 404);
  enqueue_reindex((int)$row['id'], $slug);
  jexit(['ok'=>true,'queued'=>true]);
}

// POST /v1/expert/{slug}/discover  {purpose?, seeds?:[], keywords?:[]}
if ($method==='POST' && preg_match('#^([a-z0-9\-]+)/discover$#', $rel, $m)) {
  $slug = $m[1];
  $row = db_get_expert_by_slug($slug);
  if (!$row) jexit(['ok'=>false,'error'=>'unknown expert'], 404);
  $purpose = isset($body['purpose']) ? trim((string)$body['purpose']) : null;
  $seeds = isset($body['seeds']) && is_array($body['seeds']) ? $body['seeds'] : [];
  $keywords = isset($body['keywords']) && is_array($body['keywords']) ? $body['keywords'] : [];
  $req_id = gen_uid32();
  db_insert_request((int)$row['id'], $req_id, $purpose, null, $seeds, $keywords);
  enqueue_discover((int)$row['id'], $slug, $req_id, $purpose, $seeds, $keywords);
  jexit(['ok'=>true,'slug'=>$slug,'req_id'=>$req_id,'paths'=>[
    'incoming_status'=>"/v1/incoming/req/$req_id",
    'outgoing_result'=>"/v1/outgoing/$req_id",
    'expert'=>"/v1/expert/$slug"
  ]], 202);
}

// GET /v1/expert/request/{req_id}
if ($method==='GET' && preg_match('#^request/([a-f0-9]{32})$#i', $rel, $m)) {
  $rid = strtolower($m[1]);
  $q = dbh()->prepare("SELECT er.*, e.slug FROM expert_requests er JOIN experts e ON e.id=er.expert_id WHERE er.req_id=?");
  $q->execute([$rid]);
  $rec = $q->fetch(PDO::FETCH_ASSOC);
  if (!$rec) jexit(['ok'=>false,'error'=>'not_found'], 404);
  jexit(['ok'=>true,'request'=>$rec,'paths'=>[
    'incoming_status'=>"/v1/incoming/req/$rid",
    'outgoing_result'=>"/v1/outgoing/$rid",
    'expert'=>"/v1/expert/{$rec['slug']}"
  ]]);
}

// POST /v1/expert/{slug}/ask   {"q":"...", "mode":"strict|helpful"}
if ($method==='POST' && preg_match('#^([a-z0-9\-]+)/ask$#', $rel, $m)) {
  $slug = $m[1];
  $row = db_get_expert_by_slug($slug);
  if (!$row) jexit(['ok'=>false,'error'=>'unknown expert'], 404);

  $q  = isset($body['q']) ? trim((string)$body['q']) : '';
  $mode = (isset($body['mode']) && strtolower($body['mode'])==='helpful') ? 'helpful' : 'strict';
  if ($q==='') jexit(['ok'=>false,'error'=>'q required'], 400);

  $ns = $row['namespace'];
  $cmd = sprintf(
    'python3 %s %s %s %s',
    escapeshellcmd($GLOBALS['PY_ASK']),
    esc_cmd($ns),
    esc_cmd($mode),
    esc_cmd($q)
  );
  exec($cmd.' 2>&1', $out, $rc);
  if ($rc!==0) jexit(['ok'=>false,'error'=>'runtime_failed','out'=>$out], 500);

  // expect JSON from expert_ask.py; if text, wrap it
  $js = json_decode(implode("\n",$out), true);
  if (!is_array($js)) $js = ['answer'=>implode("\n",$out)];

  // log query
  $qins = dbh()->prepare("INSERT INTO expert_queries (expert_id,question,answer,citations_json,confidence,created_at) VALUES (?,?,?,?,?,?)");
  $qins->execute([
    $row['id'], $q,
    isset($js['answer']) ? (string)$js['answer'] : null,
    isset($js['citations']) ? json_encode($js['citations'], JSON_UNESCAPED_SLASHES) : null,
    isset($js['confidence']) ? (float)$js['confidence'] : null,
    now_iso()
  ]);

  jexit(['ok'=>true,'slug'=>$slug,'response'=>$js]);
}

// POST /v1/expert/{slug}/note  {"title":"...","body_md":"..."}
if ($method==='POST' && preg_match('#^([a-z0-9\-]+)/note$#', $rel, $m)) {
  $slug = $m[1];
  $row = db_get_expert_by_slug($slug);
  if (!$row) jexit(['ok'=>false,'error'=>'unknown expert'], 404);

  $title = isset($body['title']) ? trim((string)$body['title']) : null;
  $body_md = isset($body['body_md']) ? (string)$body['body_md'] : '';
  if ($body_md==='') jexit(['ok'=>false,'error'=>'body_md required'], 400);

  $ins = dbh()->prepare("INSERT INTO expert_notes (expert_id,title,body_md,created_at) VALUES (?,?,?,?)");
  $ins->execute([$row['id'],$title,$body_md,now_iso()]);
  dbh()->prepare("UPDATE experts SET notes_count=notes_count+1, updated_at=? WHERE id=?")->execute([now_iso(),$row['id']]);

  // also append to /experts/{slug}/faq.md for RAG context
  $faq = expert_dir($slug).'/faq.md';
  file_put_contents($faq, "\n\n## ".($title ?: ('Note '.date('Y-m-d H:i')))."\n\n".$body_md."\n", FILE_APPEND);

  jexit(['ok'=>true,'saved'=>true]);
}

// 404
jexit(['ok'=>false,'error'=>'not_found'], 404);
