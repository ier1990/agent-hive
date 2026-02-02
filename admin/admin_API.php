<?php
// Admin API Knowledge Base
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once dirname(__DIR__) . '/lib/bootstrap.php';

// Preserve embed behavior (so we stay within iframe)
$IS_EMBED = in_array(strtolower($_GET['embed'] ?? ''), ['1','true','yes'], true);
$EMBED_QS = $IS_EMBED ? '?embed=1' : '';

function h($s){return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8');}
if (empty($_SESSION['csrf_api'])) { $_SESSION['csrf_api'] = bin2hex(random_bytes(32)); }
function csrf_input(){ echo '<input type="hidden" name="csrf_token" value="'.h($_SESSION['csrf_api']).'">'; }
function csrf_valid($t){ return isset($_SESSION['csrf_api']) && hash_equals($_SESSION['csrf_api'], (string)$t); }

// Robust redirect helper (avoids relative path issues that yield 404 inside frames)
function kb_redirect($query=''){ // $query without leading ?
  $base = $_SERVER['SCRIPT_NAME'] ?? 'admin_API.php';
  if (strpos($base,'admin_API.php')===false) { $base = 'admin_API.php'; }
  $url = $base . ($query ? ('?'.$query) : '');
  // Fallback to absolute path if not starting with /
  if ($url[0] !== '/') {
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/admin_API.php'),'/');
    $url = $dir . '/' . $url;
  }
  header('Location: '.$url, true, 303);
  exit;
}

$API_Directory = "/web/html/v1"; // root directory of v1 endpoints
$API_URL       = "http://localhost/v1";          // public base

// Shared AI configuration: canonical source is CodeWalker settings DB + /web/private/.env
$ai_settings = function_exists('ai_settings_get') ? ai_settings_get() : [];
$PROVIDER = (string)($ai_settings['provider'] ?? 'openai');
$AI_BASE_URL = (string)($ai_settings['base_url'] ?? 'https://api.openai.com/v1');
$OPENAI_API_KEY = (string)($ai_settings['api_key'] ?? '');
$AI_MODEL_PRIMARY = (string)($ai_settings['model'] ?? 'gpt-4o-mini');
$AI_MODEL_FALLBACK = (string)env('API_HELP_MODEL_FALLBACK', $AI_MODEL_PRIMARY);

// Allow AI: local servers often don't require a key; OpenAI does.
$ALLOW_AI = ($PROVIDER !== 'openai') || ($OPENAI_API_KEY !== '');

$GLOBALS['AI_BASE_URL'] = $AI_BASE_URL;
$GLOBALS['OPENAI_API_KEY'] = $OPENAI_API_KEY;
$GLOBALS['PROVIDER'] = $PROVIDER;

// Convenience URL for debugging
$lmstudio_url = rtrim($AI_BASE_URL,'/').'/chat/completions';

// For legacy debug block compatibility
$ENV = [];



// SQLite schema
$dbPath = '/web/private/db/api_knowledge.db';
$db = new SQLite3($dbPath);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec("CREATE TABLE IF NOT EXISTS endpoint (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,      -- canonical name e.g. v1/receiving
  method TEXT NOT NULL DEFAULT 'GET',
  path TEXT NOT NULL,             -- /v1/receiving
  description TEXT DEFAULT '',    -- user edited description
  summary TEXT DEFAULT '',        -- auto / AI generated quick how-to
  created_at TEXT DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
  updated_at TEXT DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE TABLE IF NOT EXISTS tool (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  kind TEXT DEFAULT 'script',
  entrypoint TEXT DEFAULT '',
  description TEXT DEFAULT '',
  created_at TEXT DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
  updated_at TEXT DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE TABLE IF NOT EXISTS example (
  id INTEGER PRIMARY KEY,
  endpoint_id INTEGER,
  tool_id INTEGER,
  title TEXT NOT NULL,
  request_json TEXT DEFAULT '',
  response_json TEXT DEFAULT '',
  notes TEXT DEFAULT '',
  tags TEXT DEFAULT '',
  created_at TEXT DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE TABLE IF NOT EXISTS note (
  id INTEGER PRIMARY KEY,
  subject TEXT NOT NULL,
  body TEXT NOT NULL,
  tags TEXT DEFAULT '',
  created_at TEXT DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE TABLE IF NOT EXISTS kv_meta (
  id INTEGER PRIMARY KEY,
  scope TEXT NOT NULL,
  k TEXT NOT NULL,
  v TEXT NOT NULL,
  created_at TEXT DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);");

// Add summary column if older schema
$colsRes=$db->query("PRAGMA table_info(endpoint)");
$haveSummary=false; while($c=$colsRes->fetchArray(SQLITE3_ASSOC)){ if($c['name']==='summary'){ $haveSummary=true; break; }}
if(!$haveSummary){ $db->exec("ALTER TABLE endpoint ADD COLUMN summary TEXT DEFAULT ''"); }

// Per-page AI runtime tuning (provider/base/model live in admin_AI_Setup.php)
$ai_provider = $PROVIDER;
$ai_temperature = (float)(kv_get($db, 'admin_config', 'ai_temperature') ?: 0.4);
$ai_max_tokens = (int)(kv_get($db, 'admin_config', 'ai_max_tokens') ?: 1200);
$ai_timeout = (int)(kv_get($db, 'admin_config', 'ai_timeout') ?: 900);

// Flash helpers
function flash($t,$m){ $_SESSION['api_flash'][]=['t'=>$t,'m'=>$m]; }
function flashes(){ $f=$_SESSION['api_flash']??[]; unset($_SESSION['api_flash']); return $f; }

// kv_meta helpers
function kv_get($db,$scope,$k){
  $stmt=$db->prepare('SELECT v FROM kv_meta WHERE scope=? AND k=? ORDER BY id DESC LIMIT 1');
  $stmt->bindValue(1,$scope,SQLITE3_TEXT); $stmt->bindValue(2,$k,SQLITE3_TEXT);
  $res=$stmt->execute(); $row=$res?$res->fetchArray(SQLITE3_ASSOC):null; return $row['v']??null;
}
function kv_set($db,$scope,$k,$v){ // legacy overwrite behavior (still available)
  $stmt=$db->prepare('DELETE FROM kv_meta WHERE scope=? AND k=?');
  $stmt->bindValue(1,$scope,SQLITE3_TEXT); $stmt->bindValue(2,$k,SQLITE3_TEXT); $stmt->execute();
  $stmt=$db->prepare('INSERT INTO kv_meta (scope,k,v) VALUES (?,?,?)');
  $stmt->bindValue(1,$scope,SQLITE3_TEXT); $stmt->bindValue(2,$k,SQLITE3_TEXT); $stmt->bindValue(3,$v,SQLITE3_TEXT); $stmt->execute();
}
function kv_add($db,$scope,$k,$v){ // append new version
  $stmt=$db->prepare('INSERT INTO kv_meta (scope,k,v) VALUES (?,?,?)');
  $stmt->bindValue(1,$scope,SQLITE3_TEXT); $stmt->bindValue(2,$k,SQLITE3_TEXT); $stmt->bindValue(3,$v,SQLITE3_TEXT); $stmt->execute();
}
function kv_get_all($db,$scope,$k){
  $stmt=$db->prepare('SELECT id,v,created_at FROM kv_meta WHERE scope=? AND k=? ORDER BY id DESC');
  $stmt->bindValue(1,$scope,SQLITE3_TEXT); $stmt->bindValue(2,$k,SQLITE3_TEXT); $res=$stmt->execute();
  $out=[]; while($row=$res->fetchArray(SQLITE3_ASSOC)){ $out[]=$row; } return $out;
}

// Basic OpenAI call wrappers (Chat Completions API) if key present
function openai_chat($model,$system,$user,$temperature=null,$max_tokens=null,$timeout=null,$api_key=''){
  global $ai_temperature, $ai_max_tokens, $ai_timeout;
  $temperature = $temperature ?? $ai_temperature;
  $max_tokens = $max_tokens ?? $ai_max_tokens;
  $timeout = $timeout ?? $ai_timeout;
  $payload = json_encode([
    'model'=>$model,
    'messages'=>[
      ['role'=>'system','content'=>$system],
      ['role'=>'user','content'=>$user]
    ],
    'temperature'=>$temperature,
    'max_tokens'=>$max_tokens,
  ]);
  $url = rtrim($GLOBALS['AI_BASE_URL'] ?? 'https://api.openai.com/v1','/').'/chat/completions';
  $headers = ['Content-Type: application/json'];
  $api_key = $api_key ?: ($GLOBALS['OPENAI_API_KEY'] ?? '');
  // Only require a key for real OpenAI endpoints.
  $base = (string)($GLOBALS['AI_BASE_URL'] ?? '');
  $requiresKey = (stripos($base, 'openai.com') !== false);
  if(!empty($api_key)) {
    $headers[] = 'Authorization: Bearer '.$api_key;
  } elseif ($requiresKey) {
    throw new Exception('No API key provided for OpenAI request');
  }
  $ch = curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>$headers,
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_TIMEOUT=>$timeout,
  ]);
  $resp = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
  if($resp===false){ throw new Exception('cURL error: '.$err); }
  if($code<200||$code>=300){ throw new Exception('OpenAI HTTP '.$code.' body: '.$resp); }
  $data = json_decode($resp,true); if(!$data) throw new Exception('Invalid JSON from OpenAI');
  $choice = $data['choices'][0]['message']['content'] ?? '';
  return trim($choice);
}

function ai_generate_full_docs($filePath,$filename,$modelPrimary,$modelFallback,$apiKey){
  $code = @file_get_contents($filePath);
  if(!$code) throw new Exception('Cannot read file');
  $clean = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/','',$code);

  // HTML help
  $promptHtml = "You are a developer documentation assistant. Analyze the PHP API endpoint below and produce a concise, well-structured HTML help guide including: purpose, method & path, parameters/inputs, responses, errors, security considerations, example curl. Return ONLY HTML.\n---\n$clean\n---";
  try {
    $html = openai_chat($modelPrimary,'You write concise API HTML docs.',$promptHtml,0.4,1400,null,$apiKey);
  } catch(Exception $e){
    $html = openai_chat($modelFallback,'You write concise API HTML docs.',$promptHtml,0.4,1400,null,$apiKey);
  }

  // JSON metadata
  $stripped = preg_replace('/(^|\n)\s*(\/\/|#).*?(?=\n)/','',$clean); // remove line comments simple
  $promptJson = "You are an API metadata generator. Produce ONLY valid JSON with keys: filename, purpose, inputs, outputs, params, auth, dependencies, side_effects, execution, tags (array), pseudocode, summary (<=120 chars). Omit null fields.\n---\nFILE: $filename\n$stripped\n---";
  try {
    $jsonMetaRaw = openai_chat($modelPrimary,'You output strict JSON only.',$promptJson,0.2,800,null,$apiKey);
  } catch(Exception $e){
    $jsonMetaRaw = openai_chat($modelFallback,'You output strict JSON only.',$promptJson,0.2,800,null,$apiKey);
  }

  // Attempt to isolate a JSON object
  if(preg_match('/\{[\s\S]*\}$/',$jsonMetaRaw,$m)){ $jsonMetaRaw=$m[0]; }
  $decoded = json_decode($jsonMetaRaw,true);
  if(!is_array($decoded)){ $decoded=['filename'=>$filename,'summary'=>'(parse error)','raw'=>$jsonMetaRaw]; }
  $summary = substr((string)($decoded['summary']??''),0,300);
  return [$html,$decoded,$summary];
}

// Recursive scan for PHP endpoints under $API_Directory
function scanEndpoints($root){
    $out=[]; if(!is_dir($root)) return $out; $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach($it as $f){
        if($f->isFile() && strtolower($f->getExtension())==='php'){
            $full = str_replace('\\','/',$f->getPathname());
            // Build name relative to root parent (strip leading slash)
            $rel = ltrim(str_replace($root,'',$full), '/');
            // Remove extension for name
            $relNoExt = preg_replace('/\.php$/i','',$rel);
            // path exposed (assuming filename maps to route); join with /v1/
            $path = '/v1/' . $relNoExt;
            $name = 'v1/' . $relNoExt; // canonical
            // Guess method: look for $_POST or file_put_contents, etc.
            $code = @file_get_contents($full);
            $method='GET';
            if($code && preg_match('~\$_POST|php://input|REQUEST_METHOD\s*==\s*["\']POST~i',$code)){ $method='POST'; }
            elseif($code && preg_match('~REQUEST_METHOD\s*==\s*["\']DELETE~i',$code)){ $method='DELETE'; }
            elseif($code && preg_match('~REQUEST_METHOD\s*==\s*["\']PUT~i',$code)){ $method='PUT'; }
            $out[] = [ 'name'=>$name, 'path'=>$path, 'method'=>$method, 'file'=>$full ];
        }
    }
    return $out;
}

// Generate quick summary heuristically from top comments of file
function generateSummaryFromFile($file){
    $code = @file_get_contents($file);
    if(!$code) return '';
    // Extract initial block comments or first ~15 lines
    $lines = preg_split('/\r?\n/',$code);
    $collected=[]; $count=0;
    foreach($lines as $ln){
        $trim = trim($ln);
        if($trim==='') continue;
        if(strpos($trim,'<?php')===0) continue;
        if(strpos($trim,'/*')===0 || strpos($trim,'//')===0 || strpos($trim,'*')===0){
            $collected[] = preg_replace('/^\/\/*\s?/','',$trim);
            $count++;
        }
        if($count>=12) break;
        if(preg_match('/function\s+/i',$trim)) break; // stop at first function
    }
    $summary = trim(implode(" ", $collected));
    return substr($summary,0,800);
}

// Handle actions
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_valid($_POST['csrf_token'] ?? '')){ flash('error','Invalid CSRF token'); kb_redirect($IS_EMBED?'embed=1':''); }
    if($action==='save_endpoint'){
        $id = (int)($_POST['id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $summary     = trim($_POST['summary'] ?? '');
        $stmt = $db->prepare('UPDATE endpoint SET description=?, summary=?, updated_at=strftime("%Y-%m-%dT%H:%M:%SZ","now") WHERE id=?');
        $stmt->bindValue(1,$description,SQLITE3_TEXT);
        $stmt->bindValue(2,$summary,SQLITE3_TEXT);
        $stmt->bindValue(3,$id,SQLITE3_INTEGER);
        $ok=$stmt->execute();
        flash($ok?'success':'error',$ok?'Endpoint updated.':'Update failed.');
        kb_redirect(trim(($IS_EMBED?'embed=1&':'').($ok?'id='.$id:''),'&'));
    } elseif($action==='auto_summary'){
        $id=(int)($_POST['id']??0);
        $row=$db->querySingle('SELECT name, path FROM endpoint WHERE id='.$id, true);
        if($row){
            $file = $API_Directory.'/'.preg_replace('/^v1\//','',$row['name']).'.php';
            $sum = generateSummaryFromFile($file);
            if($sum==='') $sum='(No leading comments found)';
            $stmt=$db->prepare('UPDATE endpoint SET summary=?, updated_at=strftime("%Y-%m-%dT%H:%M:%SZ","now") WHERE id=?');
            $stmt->bindValue(1,$sum,SQLITE3_TEXT); $stmt->bindValue(2,$id,SQLITE3_INTEGER); $stmt->execute();
            flash('success','Summary generated from source.');
        } else flash('error','Endpoint not found');
        kb_redirect(trim(($IS_EMBED?'embed=1&':'').($id?'id='.$id:''),'&'));
  } elseif($action==='ai_full'){
    if(!$ALLOW_AI){ flash('error','AI not configured (missing OPENAI_API_KEY).'); kb_redirect(trim(($IS_EMBED?'embed=1&':'').($_POST['id']?'id='.(int)$_POST['id']:''),'&')); }
    $id=(int)($_POST['id']??0); $row=$db->querySingle('SELECT name FROM endpoint WHERE id='.$id,true);
    if(!$row){ flash('error','Endpoint not found'); kb_redirect($IS_EMBED?'embed=1':''); }
    $rel = preg_replace('/^v1\//','',$row['name']);
    $file = $API_Directory.'/'. $rel .'.php';
    try {
      list($html,$meta,$summary) = ai_generate_full_docs($file, basename($file), $AI_MODEL_PRIMARY, $AI_MODEL_FALLBACK, $OPENAI_API_KEY);
      $scope='endpoint:'.$row['name'];
  // Append new versions (keep history)
  kv_add($db,$scope,'help_html',$html);
  kv_add($db,$scope,'ai_meta_json', json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
      if(!empty($summary)){
        // Preserve existing summary if already set (only fill when empty)
        $stmt=$db->prepare("UPDATE endpoint SET summary=COALESCE(NULLIF(summary,''), ?), updated_at=strftime('%Y-%m-%dT%H:%M:%SZ','now') WHERE id=?");
        $stmt->bindValue(1,$summary,SQLITE3_TEXT); $stmt->bindValue(2,$id,SQLITE3_INTEGER); $stmt->execute();
      }
      flash('success','AI help + metadata generated.');
    } catch(Exception $e){
      flash('error','AI generation failed: '.h($e->getMessage()));
    }
    kb_redirect(trim(($IS_EMBED?'embed=1&':'').'id='.$id,'&'));
  } elseif($action==='save_config'){
  $temperature = (float)($_POST['ai_temperature'] ?? 0.4);
  $max_tokens = (int)($_POST['ai_max_tokens'] ?? 1200);
  $timeout = (int)($_POST['ai_timeout'] ?? 900);
  kv_set($db, 'admin_config', 'ai_temperature', (string)$temperature);
  kv_set($db, 'admin_config', 'ai_max_tokens', (string)$max_tokens);
  kv_set($db, 'admin_config', 'ai_timeout', (string)$timeout);
  flash('success', 'AI options saved.');
  kb_redirect($IS_EMBED?'embed=1':'');
  }
}

// Auto-index API endpoints on page load (throttled) instead of a manual Sync button.
$AUTO_SYNC_API_STATS = null;
try {
  $now = time();
  $last = (int)($_SESSION['kb_last_api_sync'] ?? 0);
  if (($now - $last) >= 10) {
    $_SESSION['kb_last_api_sync'] = $now;
    $found = scanEndpoints($API_Directory);
    $added = 0;
    $updated = 0;
    $res = $db->query('SELECT id,name,method,path FROM endpoint');
    $current = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
      $current[$r['name']] = $r;
    }
    foreach ($found as $ep) {
      if (!isset($current[$ep['name']])) {
        $stmt = $db->prepare('INSERT INTO endpoint (name, method, path) VALUES (?,?,?)');
        $stmt->bindValue(1, $ep['name']);
        $stmt->bindValue(2, $ep['method']);
        $stmt->bindValue(3, $ep['path']);
        if ($stmt->execute()) $added++;
      } else {
        if ($current[$ep['name']]['method'] !== $ep['method'] || $current[$ep['name']]['path'] !== $ep['path']) {
          $stmt = $db->prepare('UPDATE endpoint SET method=?, path=?, updated_at=strftime("%Y-%m-%dT%H:%M:%SZ","now") WHERE name=?');
          $stmt->bindValue(1, $ep['method']);
          $stmt->bindValue(2, $ep['path']);
          $stmt->bindValue(3, $ep['name']);
          if ($stmt->execute()) $updated++;
        }
      }
    }
    $AUTO_SYNC_API_STATS = ['found' => count($found), 'added' => $added, 'updated' => $updated];
  }
} catch (Throwable $t) {
  // best-effort
}

// Fetch endpoints for tree
$eps=[]; $res=$db->query('SELECT * FROM endpoint ORDER BY name'); while($r=$res->fetchArray(SQLITE3_ASSOC)){ $eps[]=$r; }
$byFolder=[]; foreach($eps as $e){
    $pathPart = preg_replace('/^v1\//','',$e['name']);
    $segments = explode('/', $pathPart);
    if(count($segments)>1){ $folder=$segments[0]; } else { $folder='(root)'; }
    $byFolder[$folder][]=$e;
}
ksort($byFolder);

// Selected endpoint detail
$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$selected = null; if($selectedId){ $selected = $db->querySingle('SELECT * FROM endpoint WHERE id='.$selectedId, true); }
$selectedHelpHtml = null; $selectedMetaJson = null;
if($selected){
  $scope='endpoint:'.$selected['name'];
  $selectedHelpHtml = kv_get($db,$scope,'help_html'); // latest
  $selectedMetaJson = kv_get($db,$scope,'ai_meta_json'); // latest
  $selectedHelpHtmlAll = kv_get_all($db,$scope,'help_html');
  $selectedMetaJsonAll = kv_get_all($db,$scope,'ai_meta_json');
}
$fl = flashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>API Knowledge Base</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>.scroll-thin::-webkit-scrollbar{width:6px;} .scroll-thin::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:3px;}</style>
</head>
<body class="bg-gray-50 min-h-screen">
  <div class="bg-gradient-to-r from-sky-500 to-indigo-600 text-white py-5 mb-6">
    <div class="container mx-auto px-4 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold">🧭 API Knowledge Base</h1>
        <p class="text-sm opacity-90">Indexed PHP routes with editable docs & generated summaries</p>
      </div>
      <div class="flex flex-wrap gap-2 justify-end">
        <a href="admin_AI_Headers.php" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-md text-sm whitespace-nowrap">AI Headers</a>
        <a href="admin_AI_Setup.php<?php echo $IS_EMBED?'?embed=1':''; ?>" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-md text-sm whitespace-nowrap">AI Setup</a>
        <a href="admin_Crontab.php" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-md text-sm whitespace-nowrap">Crontab</a>
        <a href="admin_API.php<?php echo $IS_EMBED?'?embed=1':''; ?>" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-md text-sm whitespace-nowrap">Home</a>
      </div>
    </div>
  </div>
  <div class="container mx-auto px-4">
    <?php if(isset($_GET['debug'])): ?>
      <div class="mb-4 bg-yellow-50 border border-yellow-300 rounded p-3 text-xs font-mono overflow-auto">
        <strong>Debug:</strong><br>
        SCRIPT_NAME: <?php echo h($_SERVER['SCRIPT_NAME'] ?? ''); ?><br>
        REQUEST_URI: <?php echo h($_SERVER['REQUEST_URI'] ?? ''); ?><br>
        EMBED: <?php echo $IS_EMBED?'yes':'no'; ?><br>
        ACTION: <?php echo h($action); ?><br>
        POST id: <?php echo h($_POST['id'] ?? ''); ?><br>
        AI_BASE_URL: <?php echo h($AI_BASE_URL); ?><br>
        PROVIDER: <?php echo h($PROVIDER ?? ''); ?><br>
        MODEL_PRIMARY: <?php echo h($AI_MODEL_PRIMARY); ?><br>
        MODEL_FALLBACK: <?php echo h($AI_MODEL_FALLBACK); ?><br>
        OPENAI_API_KEY set: <?php echo !empty($OPENAI_API_KEY)?'yes':'no'; ?><br>
        ALLOW_AI: <?php echo $ALLOW_AI?'yes':'no'; ?><br>
        AI_PROVIDER: <?php echo h($ai_provider); ?><br>
        AI_TEMPERATURE: <?php echo h($ai_temperature); ?><br>
        AI_MAX_TOKENS: <?php echo h($ai_max_tokens); ?><br>
        AI_TIMEOUT: <?php echo h($ai_timeout); ?><br>
      </div>
    <?php endif; ?>
    <?php if($fl): ?>
      <div class="mb-4 space-y-2">
        <?php foreach($fl as $f): ?>
          <div class="px-4 py-2 rounded-md text-sm <?php echo $f['t']==='success'?'bg-green-100 text-green-800 border border-green-200':'bg-red-100 text-red-800 border border-red-200'; ?>"><?php echo h($f['m']); ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="mb-4 bg-white rounded-lg shadow p-4">
      <div class="flex items-center justify-between gap-2 flex-wrap">
        <div>
          <div class="text-sm font-semibold text-gray-700">AI Setup</div>
          <div class="text-xs text-gray-500">Provider/model/base URL are shared across admin tools.</div>
        </div>
        <?php
          $setupReturn = 'admin_API.php' . ($IS_EMBED ? '?embed=1' : '');
          $setupUrl = 'admin_AI_Setup.php?' . ($IS_EMBED ? 'embed=1&' : '') . 'popup=1&postmessage=1&return=' . urlencode($setupReturn);
        ?>
        <a href="<?php echo h($setupUrl); ?>" target="_blank" class="text-xs bg-white border border-slate-300 hover:bg-slate-50 text-slate-800 px-3 py-2 rounded-md whitespace-nowrap">Configure AI…</a>
      </div>

      <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3 text-xs text-gray-700">
        <div><span class="text-gray-500">Provider:</span> <?php echo h($PROVIDER); ?></div>
        <div><span class="text-gray-500">Model:</span> <?php echo h($AI_MODEL_PRIMARY); ?></div>
        <div class="md:col-span-2"><span class="text-gray-500">Base URL:</span> <?php echo h($AI_BASE_URL); ?></div>
      </div>

      <form method="post" class="mt-4">
        <?php csrf_input(); ?>
        <?php if($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <input type="hidden" name="action" value="save_config">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Temperature</label>
            <input type="number" step="0.1" min="0" max="2" name="ai_temperature" value="<?php echo h($ai_temperature); ?>" class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Max Tokens</label>
            <input type="number" min="1" name="ai_max_tokens" value="<?php echo h($ai_max_tokens); ?>" class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Curl Timeout (sec)</label>
            <input type="number" min="1" name="ai_timeout" value="<?php echo h($ai_timeout); ?>" class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
          </div>
        </div>
        <div class="mt-3 flex justify-end">
          <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-4 py-2 rounded">Save Options</button>
        </div>
      </form>
    </div>

    <script>
      window.addEventListener('message', function (ev) {
        try {
          if (ev.origin !== window.location.origin) return;
          if (!ev.data || ev.data.type !== 'ai_setup_saved') return;
          window.location.reload();
        } catch (e) {}
      });
    </script>
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
      <!-- Tree -->
      <div class="lg:col-span-4 xl:col-span-3 bg-white rounded-lg shadow p-4 flex flex-col max-h-[70vh] overflow-y-auto scroll-thin">
        <h2 class="text-sm font-semibold text-gray-600 mb-2">Endpoints</h2>
        <?php if(empty($eps)): ?>
          <p class="text-xs text-gray-500">No endpoints indexed yet. Reload this page; it auto-indexes <code><?php echo h($API_Directory); ?></code>.</p>
        <?php else: ?>
          <ul class="space-y-3">
            <?php foreach($byFolder as $folder=>$items): ?>
              <li>
                <div class="text-xs uppercase tracking-wide text-gray-400 mb-1"><?php echo h($folder); ?></div>
                <ul class="space-y-1 ml-1">
                  <?php foreach($items as $ep): $active = ($ep['id']==$selectedId); ?>
                    <li>
                      <a href="admin_API.php?<?php echo $IS_EMBED?'embed=1&':''; ?>id=<?php echo (int)$ep['id']; ?>" class="block text-xs px-2 py-1 rounded <?php echo $active?'bg-indigo-600 text-white':'hover:bg-indigo-50 text-indigo-700'; ?>">
                        <?php echo h($ep['name']); ?>
                        <?php if(!empty($ep['summary'])): ?><span class="ml-1 text-[10px] text-green-600">●</span><?php endif; ?>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <!-- Detail -->
      <div class="lg:col-span-8 xl:col-span-9">
        <?php if(!$selected): ?>
          <div class="bg-white rounded-lg shadow p-8 text-center">
            <p class="text-gray-500 mb-4 text-sm">Select an endpoint from the left to view & edit its documentation.</p>
            <p class="text-xs text-gray-400">Green dot indicates summary present.</p>
          </div>
        <?php else: ?>
          <div class="bg-white rounded-lg shadow p-6 space-y-6">
            <div class="flex justify-between items-start">
              <div>
                <h2 class="text-xl font-semibold text-gray-800 mb-1"><?php echo h($selected['name']); ?></h2>
                <div class="text-xs text-gray-500">Method: <span class="font-semibold text-indigo-600"><?php echo h($selected['method']); ?></span> • Path: <span class="font-mono"><?php echo h($selected['path']); ?></span></div>
              </div>
              <form method="post" class="inline-block ml-4">
                <?php csrf_input(); ?>
                <?php if($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <input type="hidden" name="action" value="auto_summary">
                <input type="hidden" name="id" value="<?php echo (int)$selected['id']; ?>">
                <button type="submit" class="text-xs bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-md">✨ Generate Summary</button>
              </form>
              <?php if($ALLOW_AI): ?>
              <form method="post" class="inline-block ml-2">
                <?php csrf_input(); ?>
                <?php if($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <input type="hidden" name="action" value="ai_full">
                <input type="hidden" name="id" value="<?php echo (int)$selected['id']; ?>">
                <button type="submit" class="text-xs bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded-md">🤖 AI Generate Full Help</button>
              </form>
              <?php endif; ?>
            </div>
            <form method="post" class="space-y-4">
              <?php csrf_input(); ?>
              <?php if($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
              <input type="hidden" name="action" value="save_endpoint">
              <input type="hidden" name="id" value="<?php echo (int)$selected['id']; ?>">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description (manual)</label>
                <textarea name="description" rows="4" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Human-friendly purpose / usage notes..."><?php echo h($selected['description']); ?></textarea>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Summary (auto/AI)</label>
                <textarea name="summary" rows="5" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Short how-to for quick reference..."><?php echo h($selected['summary']); ?></textarea>
              </div>
              <div class="flex justify-end gap-3">
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-5 py-2 rounded-md">💾 Save</button>
                <a href="<?php echo h($API_URL . '/' . preg_replace('/^v1\//','',$selected['name'])); ?>" target="_blank" class="bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm font-medium px-5 py-2 rounded-md">Open Endpoint ↗</a>
              </div>
            </form>
            <div class="border-t pt-4">
              <h3 class="text-sm font-semibold text-gray-600 mb-2">AI Documentation</h3>
              <?php if(!$ALLOW_AI && !$selectedHelpHtml): ?>
                  <p class="text-xs text-gray-500">Set OPENAI_API_KEY in environment to enable inline AI generation.</p>
              <?php endif; ?>
              <?php if(!empty($selectedHelpHtmlAll)): ?>
                <div class="mb-4">
                  <label class="block text-[11px] font-semibold text-gray-500 mb-1">Help Versions</label>
                  <select id="helpVersionSelect" class="border border-gray-300 rounded px-2 py-1 text-xs" onchange="showHelpVersion(this.value)">
                    <?php foreach($selectedHelpHtmlAll as $i=>$ver): ?>
                      <option value="<?php echo (int)$ver['id']; ?>" <?php echo $i===0?'selected':''; ?>>#<?php echo (int)$ver['id']; ?> @ <?php echo h(substr($ver['created_at'],0,16)); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div id="helpIframeWrapper" class="border rounded-md overflow-hidden" style="height:400px;">
                  <iframe id="helpIframe" title="Help HTML" class="w-full h-full bg-white" sandbox="allow-same-origin allow-popups allow-top-navigation-by-user-activation" referrerpolicy="no-referrer"></iframe>
                </div>
                <script>
                  const helpVersions = <?php echo json_encode($selectedHelpHtmlAll, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
                  function showHelpVersion(id){
                    const v = helpVersions.find(h=>h.id==id);
                    const iframe = document.getElementById('helpIframe');
                    if(!v){ iframe.srcdoc='<html><body><p style="font:12px sans-serif;color:#888">Version not found</p></body></html>'; return; }
                    iframe.srcdoc = v.v;
                  }
                  document.addEventListener('DOMContentLoaded',()=>{
                    const sel=document.getElementById('helpVersionSelect'); if(sel) showHelpVersion(sel.value);
                  });
                </script>
              <?php endif; ?>
              <?php if(!empty($selectedMetaJsonAll)): ?>
                <details class="mt-4">
                  <summary class="cursor-pointer text-xs text-indigo-600 font-medium">AI Metadata JSON Versions (latest first)</summary>
                  <div class="mt-2 space-y-3">
                    <?php foreach($selectedMetaJsonAll as $i=>$ver): ?>
                      <details <?php echo $i===0?'open':''; ?> class="border rounded">
                        <summary class="text-[11px] px-2 py-1 cursor-pointer bg-gray-100">#<?php echo (int)$ver['id']; ?> @ <?php echo h($ver['created_at']); ?></summary>
                        <pre class="m-0 p-2 text-xs bg-gray-900 text-green-200 overflow-auto" style="max-height:200px;">
<?php echo h($ver['v']); ?>
                        </pre>
                      </details>
                    <?php endforeach; ?>
                  </div>
                </details>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>







