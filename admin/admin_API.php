<?php
// /web/html/admin/admin_API.php – API Keys & Route Knowledge Base
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once dirname(__DIR__) . '/lib/bootstrap.php';

// ── Page params ──
$IS_EMBED  = in_array(strtolower($_GET['embed'] ?? ''), ['1','true','yes'], true);
$EMBED_QS  = $IS_EMBED ? '?embed=1' : '';
$tab       = $_GET['tab'] ?? 'keys';
if (!in_array($tab, ['keys', 'routes'], true)) { $tab = 'keys'; }

// ── Shared helpers ──
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['csrf_api'])) { $_SESSION['csrf_api'] = bin2hex(random_bytes(32)); }
function csrf_input(){ echo '<input type="hidden" name="csrf_token" value="'.h($_SESSION['csrf_api']).'">'; }
function csrf_valid($t){ return isset($_SESSION['csrf_api']) && hash_equals($_SESSION['csrf_api'], (string)$t); }

function kb_redirect($query=''){
  $base = $_SERVER['SCRIPT_NAME'] ?? 'admin_API.php';
  if (strpos($base, 'admin_API.php') === false) { $base = 'admin_API.php'; }
  $url = $base . ($query ? ('?' . $query) : '');
  if ($url[0] !== '/') {
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/admin_API.php'), '/');
    $url = $dir . '/' . $url;
  }
  header('Location: ' . $url, true, 303);
  exit;
}

function flash($t, $m){ $_SESSION['api_flash'][] = ['t' => $t, 'm' => $m]; }
function flashes(){ $f = $_SESSION['api_flash'] ?? []; unset($_SESSION['api_flash']); return $f; }

function mask_key($key) {
  $len = strlen($key);
  if ($len <= 10) return str_repeat("\u{2022}", $len);
  return substr($key, 0, 6) . str_repeat("\u{2022}", max($len - 10, 4)) . substr($key, -4);
}

// ══════════════════════════════════════════════════════
//  API Keys file management  (/web/private/api_keys.json)
// ══════════════════════════════════════════════════════
$API_KEYS_FILE = defined('API_KEYS_FILE') ? API_KEYS_FILE : '/web/private/api_keys.json';

function api_keys_read() {
  global $API_KEYS_FILE;
  if (!is_readable($API_KEYS_FILE)) return [];
  $raw = file_get_contents($API_KEYS_FILE);
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function api_keys_write(array $keys) {
  global $API_KEYS_FILE;
  $dir = dirname($API_KEYS_FILE);
  if (!is_dir($dir)) { mkdir($dir, 0770, true); }
  $json = json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  return file_put_contents($API_KEYS_FILE, $json . "\n") !== false;
}

function generate_api_key() {
  return 'sk-' . bin2hex(random_bytes(20));
}

// Scopes actively used across v1 endpoints
$KNOWN_SCOPES = ['chat', 'tools', 'health', 'search', 'inbox', 'push', 'receiving', 'incoming', 'responses'];

// ══════════════════════════════════════════════════════
//  v1 & AI config
// ══════════════════════════════════════════════════════
$API_Directory = '/web/html/v1';
$API_URL       = 'http://localhost/v1';

$ai_settings      = function_exists('ai_settings_get') ? ai_settings_get() : [];
$PROVIDER         = (string)($ai_settings['provider'] ?? 'openai');
$AI_BASE_URL      = (string)($ai_settings['base_url'] ?? 'https://api.openai.com/v1');
$OPENAI_API_KEY   = (string)($ai_settings['api_key'] ?? '');
$AI_MODEL_PRIMARY = (string)($ai_settings['model'] ?? 'gpt-4o-mini');
$AI_MODEL_FALLBACK= (string)env('API_HELP_MODEL_FALLBACK', $AI_MODEL_PRIMARY);
$ALLOW_AI         = ($PROVIDER !== 'openai') || ($OPENAI_API_KEY !== '');
$GLOBALS['AI_BASE_URL']    = $AI_BASE_URL;
$GLOBALS['OPENAI_API_KEY'] = $OPENAI_API_KEY;
$GLOBALS['PROVIDER']       = $PROVIDER;

// ══════════════════════════════════════════════════════
//  SQLite Knowledge Base
// ══════════════════════════════════════════════════════
$dbDir = dirname('/web/private/db/api_knowledge.db');
if (!is_dir($dbDir)) { @mkdir($dbDir, 0770, true); }
$dbPath = '/web/private/db/api_knowledge.db';
$db = new SQLite3($dbPath);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec("CREATE TABLE IF NOT EXISTS endpoint (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,
  method TEXT NOT NULL DEFAULT 'GET',
  path TEXT NOT NULL,
  description TEXT DEFAULT '',
  summary TEXT DEFAULT '',
  created_at TEXT DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now')),
  updated_at TEXT DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);
CREATE TABLE IF NOT EXISTS kv_meta (
  id INTEGER PRIMARY KEY,
  scope TEXT NOT NULL,
  k TEXT NOT NULL,
  v TEXT NOT NULL,
  created_at TEXT DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ','now'))
);");

// Ensure summary column for older schemas
$colsRes = $db->query("PRAGMA table_info(endpoint)");
$_haveSummary = false;
while ($c = $colsRes->fetchArray(SQLITE3_ASSOC)) {
  if ($c['name'] === 'summary') { $_haveSummary = true; break; }
}
if (!$_haveSummary) { $db->exec("ALTER TABLE endpoint ADD COLUMN summary TEXT DEFAULT ''"); }

// ── kv_meta helpers ──
function kv_get($db, $scope, $k){
  $stmt = $db->prepare('SELECT v FROM kv_meta WHERE scope=? AND k=? ORDER BY id DESC LIMIT 1');
  $stmt->bindValue(1, $scope, SQLITE3_TEXT);
  $stmt->bindValue(2, $k, SQLITE3_TEXT);
  $res = $stmt->execute();
  $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
  return isset($row['v']) ? $row['v'] : null;
}
function kv_set($db, $scope, $k, $v){
  $stmt = $db->prepare('DELETE FROM kv_meta WHERE scope=? AND k=?');
  $stmt->bindValue(1, $scope, SQLITE3_TEXT);
  $stmt->bindValue(2, $k, SQLITE3_TEXT);
  $stmt->execute();
  $stmt = $db->prepare('INSERT INTO kv_meta (scope,k,v) VALUES (?,?,?)');
  $stmt->bindValue(1, $scope, SQLITE3_TEXT);
  $stmt->bindValue(2, $k, SQLITE3_TEXT);
  $stmt->bindValue(3, $v, SQLITE3_TEXT);
  $stmt->execute();
}
function kv_add($db, $scope, $k, $v){
  $stmt = $db->prepare('INSERT INTO kv_meta (scope,k,v) VALUES (?,?,?)');
  $stmt->bindValue(1, $scope, SQLITE3_TEXT);
  $stmt->bindValue(2, $k, SQLITE3_TEXT);
  $stmt->bindValue(3, $v, SQLITE3_TEXT);
  $stmt->execute();
}
function kv_get_all($db, $scope, $k){
  $stmt = $db->prepare('SELECT id,v,created_at FROM kv_meta WHERE scope=? AND k=? ORDER BY id DESC');
  $stmt->bindValue(1, $scope, SQLITE3_TEXT);
  $stmt->bindValue(2, $k, SQLITE3_TEXT);
  $res = $stmt->execute();
  $out = [];
  while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $out[] = $row; }
  return $out;
}

// ── AI runtime tuning ──
$ai_temperature = 0.4;
$ai_max_tokens  = 1200;
$ai_timeout     = 900;
$_t = kv_get($db, 'admin_config', 'ai_temperature');
if ($_t !== null) { $ai_temperature = (float)$_t; }
$_t = kv_get($db, 'admin_config', 'ai_max_tokens');
if ($_t !== null) { $ai_max_tokens = (int)$_t; }
$_t = kv_get($db, 'admin_config', 'ai_timeout');
if ($_t !== null) { $ai_timeout = (int)$_t; }

// ══════════════════════════════════════════════════════
//  AI chat helper (shared)
// ══════════════════════════════════════════════════════
function openai_chat($model, $system, $user, $temperature = null, $max_tokens = null, $timeout = null, $api_key = ''){
  global $ai_temperature, $ai_max_tokens, $ai_timeout;
  $temperature = ($temperature !== null) ? $temperature : $ai_temperature;
  $max_tokens  = ($max_tokens  !== null) ? $max_tokens  : $ai_max_tokens;
  $timeout     = ($timeout     !== null) ? $timeout     : $ai_timeout;
  $payload = json_encode([
    'model'       => $model,
    'messages'    => [
      ['role' => 'system', 'content' => $system],
      ['role' => 'user',   'content' => $user],
    ],
    'temperature' => $temperature,
    'max_tokens'  => $max_tokens,
  ]);
  $url     = rtrim($GLOBALS['AI_BASE_URL'] ?? 'https://api.openai.com/v1', '/') . '/chat/completions';
  $headers = ['Content-Type: application/json'];
  $api_key = $api_key ? $api_key : ($GLOBALS['OPENAI_API_KEY'] ?? '');
  $base = (string)($GLOBALS['AI_BASE_URL'] ?? '');
  $requiresKey = (stripos($base, 'openai.com') !== false);
  if (!empty($api_key)) {
    $headers[] = 'Authorization: Bearer ' . $api_key;
  } elseif ($requiresKey) {
    throw new Exception('No API key provided for OpenAI request');
  }
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => $timeout,
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($resp === false) { throw new Exception('cURL error: ' . $err); }
  if ($code < 200 || $code >= 300) { throw new Exception('AI HTTP ' . $code . ' body: ' . $resp); }
  $data = json_decode($resp, true);
  if (!$data) { throw new Exception('Invalid JSON from AI'); }
  return trim(isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : '');
}

function ai_generate_full_docs($filePath, $filename, $modelPrimary, $modelFallback, $apiKey){
  $code = @file_get_contents($filePath);
  if (!$code) { throw new Exception('Cannot read file'); }
  $clean = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $code);
  $promptHtml = "You are a developer documentation assistant. Analyze the PHP API endpoint below and produce a concise, well-structured HTML help guide including: purpose, method & path, parameters/inputs, responses, errors, security considerations, example curl. Return ONLY HTML.\n---\n$clean\n---";
  try {
    $html = openai_chat($modelPrimary, 'You write concise API HTML docs.', $promptHtml, 0.4, 1400, null, $apiKey);
  } catch (Exception $e) {
    $html = openai_chat($modelFallback, 'You write concise API HTML docs.', $promptHtml, 0.4, 1400, null, $apiKey);
  }
  $stripped = preg_replace('/(^|\n)\s*(\/\/|#).*?(?=\n)/', '', $clean);
  $promptJson = "You are an API metadata generator. Produce ONLY valid JSON with keys: filename, purpose, inputs, outputs, params, auth, dependencies, side_effects, execution, tags (array), pseudocode, summary (<=120 chars). Omit null fields.\n---\nFILE: $filename\n$stripped\n---";
  try {
    $jsonMetaRaw = openai_chat($modelPrimary, 'You output strict JSON only.', $promptJson, 0.2, 800, null, $apiKey);
  } catch (Exception $e) {
    $jsonMetaRaw = openai_chat($modelFallback, 'You output strict JSON only.', $promptJson, 0.2, 800, null, $apiKey);
  }
  if (preg_match('/\{[\s\S]*\}$/', $jsonMetaRaw, $m)) { $jsonMetaRaw = $m[0]; }
  $decoded = json_decode($jsonMetaRaw, true);
  if (!is_array($decoded)) { $decoded = ['filename' => $filename, 'summary' => '(parse error)', 'raw' => $jsonMetaRaw]; }
  $summary = substr((string)(isset($decoded['summary']) ? $decoded['summary'] : ''), 0, 300);
  return [$html, $decoded, $summary];
}

// ══════════════════════════════════════════════════════
//  Endpoint scanning (v1 directory)
// ══════════════════════════════════════════════════════
function scanEndpoints($root){
  $out = [];
  if (!is_dir($root)) return $out;
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
  foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
    $full = str_replace('\\', '/', $f->getPathname());
    $rel = ltrim(str_replace($root, '', $full), '/');
    $relNoExt = preg_replace('/\.php$/i', '', $rel);
    $path = '/v1/' . $relNoExt;
    $name = 'v1/' . $relNoExt;

    $code = @file_get_contents($full);
    // Detect HTTP methods
    $methods = [];
    if ($code) {
      // Check for explicit method routing
      if (preg_match('~REQUEST_METHOD\s*[=!]=\s*["\']GET~i', $code) || !preg_match('~REQUEST_METHOD~', $code)) {
        $methods[] = 'GET';
      }
      if (preg_match('~\$_POST|php://input|REQUEST_METHOD\s*[=!]=\s*["\']POST~i', $code)) { $methods[] = 'POST'; }
      if (preg_match('~REQUEST_METHOD\s*[=!]=\s*["\']PUT~i', $code))    { $methods[] = 'PUT'; }
      if (preg_match('~REQUEST_METHOD\s*[=!]=\s*["\']PATCH~i', $code))  { $methods[] = 'PATCH'; }
      if (preg_match('~REQUEST_METHOD\s*[=!]=\s*["\']DELETE~i', $code)) { $methods[] = 'DELETE'; }
    }
    if (empty($methods)) { $methods[] = 'GET'; }
    $method = implode(', ', array_unique($methods));

    // Detect api_guard usage
    $guardEndpoint = '';
    $guardNeedsTools = false;
    if ($code && preg_match('~api_guard(?:_once)?\s*\(\s*[\'"]([^\'"]+)[\'"](?:\s*,\s*(true|false))?\s*\)~', $code, $gm)) {
      $guardEndpoint = $gm[1];
      $guardNeedsTools = isset($gm[2]) && $gm[2] === 'true';
    }

    $out[] = [
      'name'              => $name,
      'path'              => $path,
      'method'            => $method,
      'file'              => $full,
      'guard_endpoint'    => $guardEndpoint,
      'guard_needs_tools' => $guardNeedsTools,
    ];
  }
  return $out;
}

function generateSummaryFromFile($file){
  $code = @file_get_contents($file);
  if (!$code) return '';
  $lines = preg_split('/\r?\n/', $code);
  $collected = [];
  $count = 0;
  foreach ($lines as $ln) {
    $trim = trim($ln);
    if ($trim === '') continue;
    if (strpos($trim, '<?php') === 0) continue;
    if (strpos($trim, '/*') === 0 || strpos($trim, '//') === 0 || strpos($trim, '*') === 0) {
      $collected[] = preg_replace('/^\/\/*\s?/', '', $trim);
      $count++;
    }
    if ($count >= 12) break;
    if (preg_match('/function\s+/i', $trim)) break;
  }
  return substr(trim(implode(' ', $collected)), 0, 800);
}

// ══════════════════════════════════════════════════════
//  POST Action Handlers
// ══════════════════════════════════════════════════════
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_valid(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
    flash('error', 'Invalid CSRF token');
    kb_redirect($IS_EMBED ? 'embed=1' : '');
  }
  $qs = [];
  if ($IS_EMBED) { $qs[] = 'embed=1'; }

  // ── API Key: Create ──
  if ($action === 'add_key') {
    $customKey = trim(isset($_POST['custom_key']) ? $_POST['custom_key'] : '');
    $keyName   = trim(isset($_POST['key_name'])   ? $_POST['key_name']   : '');
    $scopes    = (isset($_POST['scopes']) && is_array($_POST['scopes'])) ? $_POST['scopes'] : [];

    $newKey = ($customKey !== '') ? $customKey : generate_api_key();
    $keys   = api_keys_read();

    if (isset($keys[$newKey])) {
      flash('error', 'That key already exists.');
    } else {
      $keys[$newKey] = [
        'active'     => true,
        'name'       => ($keyName !== '') ? $keyName : ('Key ' . substr($newKey, 0, 8)),
        'scopes'     => array_values($scopes),
        'created_at' => date('c'),
      ];
      if (api_keys_write($keys)) {
        flash('success', 'API key created.');
        $_SESSION['last_created_key'] = $newKey;
      } else {
        flash('error', 'Failed to write keys file. Check permissions on ' . $GLOBALS['API_KEYS_FILE']);
      }
    }
    $qs[] = 'tab=keys';
    kb_redirect(implode('&', $qs));
  }

  // ── API Key: Edit ──
  if ($action === 'edit_key') {
    $apiKey  = isset($_POST['api_key'])  ? $_POST['api_key']  : '';
    $keyName = trim(isset($_POST['key_name']) ? $_POST['key_name'] : '');
    $scopes  = (isset($_POST['scopes']) && is_array($_POST['scopes'])) ? $_POST['scopes'] : [];
    $keys    = api_keys_read();
    if (!isset($keys[$apiKey])) {
      flash('error', 'Key not found.');
    } else {
      if (!is_array($keys[$apiKey])) {
        $keys[$apiKey] = ['active' => true, 'scopes' => [], 'created_at' => date('c')];
      }
      $keys[$apiKey]['name']   = $keyName;
      $keys[$apiKey]['scopes'] = array_values($scopes);
      if (api_keys_write($keys)) {
        flash('success', 'Key updated.');
      } else {
        flash('error', 'Failed to write keys file.');
      }
    }
    $qs[] = 'tab=keys';
    kb_redirect(implode('&', $qs));
  }

  // ── API Key: Toggle active ──
  if ($action === 'toggle_key') {
    $apiKey = isset($_POST['api_key']) ? $_POST['api_key'] : '';
    $keys   = api_keys_read();
    if (isset($keys[$apiKey])) {
      if (!is_array($keys[$apiKey])) {
        $keys[$apiKey] = ['active' => true, 'scopes' => [], 'created_at' => date('c')];
      }
      $wasActive = isset($keys[$apiKey]['active']) ? (bool)$keys[$apiKey]['active'] : true;
      $keys[$apiKey]['active'] = !$wasActive;
      $label = $keys[$apiKey]['active'] ? 'activated' : 'deactivated';
      if (api_keys_write($keys)) {
        flash('success', "Key {$label}.");
      }
    }
    $qs[] = 'tab=keys';
    kb_redirect(implode('&', $qs));
  }

  // ── API Key: Delete ──
  if ($action === 'delete_key') {
    $apiKey = isset($_POST['api_key']) ? $_POST['api_key'] : '';
    $keys   = api_keys_read();
    if (isset($keys[$apiKey])) {
      unset($keys[$apiKey]);
      if (api_keys_write($keys)) {
        flash('success', 'Key deleted.');
      }
    } else {
      flash('error', 'Key not found.');
    }
    $qs[] = 'tab=keys';
    kb_redirect(implode('&', $qs));
  }

  // ── Routes: Refresh from disk ──
  if ($action === 'refresh_routes') {
    $found = scanEndpoints($API_Directory);
    $added = 0;
    $updated = 0;
    $res = $db->query('SELECT id,name,method,path FROM endpoint');
    $current = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $current[$r['name']] = $r; }
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
    // Remove endpoints no longer on disk
    $foundNames = array_column($found, 'name');
    $removed = 0;
    foreach ($current as $name => $row) {
      if (!in_array($name, $foundNames, true)) {
        $db->exec("DELETE FROM endpoint WHERE id=" . (int)$row['id']);
        $removed++;
      }
    }
    flash('success', "Routes refreshed: " . count($found) . " found, {$added} added, {$updated} updated, {$removed} removed.");
    $qs[] = 'tab=routes';
    kb_redirect(implode('&', $qs));
  }

  // ── Endpoint: Save description/summary ──
  if ($action === 'save_endpoint') {
    $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $description = trim(isset($_POST['description']) ? $_POST['description'] : '');
    $summary     = trim(isset($_POST['summary'])     ? $_POST['summary']     : '');
    $stmt = $db->prepare('UPDATE endpoint SET description=?, summary=?, updated_at=strftime("%Y-%m-%dT%H:%M:%SZ","now") WHERE id=?');
    $stmt->bindValue(1, $description, SQLITE3_TEXT);
    $stmt->bindValue(2, $summary, SQLITE3_TEXT);
    $stmt->bindValue(3, $id, SQLITE3_INTEGER);
    $ok = $stmt->execute();
    flash($ok ? 'success' : 'error', $ok ? 'Endpoint updated.' : 'Update failed.');
    $qs[] = 'tab=routes';
    if ($ok) $qs[] = 'id=' . $id;
    kb_redirect(implode('&', $qs));
  }

  // ── Endpoint: Auto-generate summary from source ──
  if ($action === 'auto_summary') {
    $id  = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    $row = $db->querySingle('SELECT name, path FROM endpoint WHERE id=' . $id, true);
    if ($row) {
      $file = $API_Directory . '/' . preg_replace('/^v1\//', '', $row['name']) . '.php';
      $sum  = generateSummaryFromFile($file);
      if ($sum === '') $sum = '(No leading comments found)';
      $stmt = $db->prepare('UPDATE endpoint SET summary=?, updated_at=strftime("%Y-%m-%dT%H:%M:%SZ","now") WHERE id=?');
      $stmt->bindValue(1, $sum, SQLITE3_TEXT);
      $stmt->bindValue(2, $id, SQLITE3_INTEGER);
      $stmt->execute();
      flash('success', 'Summary generated from source.');
    } else {
      flash('error', 'Endpoint not found');
    }
    $qs[] = 'tab=routes';
    if ($id) $qs[] = 'id=' . $id;
    kb_redirect(implode('&', $qs));
  }

  // ── Endpoint: AI full docs ──
  if ($action === 'ai_full') {
    $id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
    if (!$ALLOW_AI) {
      flash('error', 'AI not configured.');
      $qs[] = 'tab=routes';
      if ($id) $qs[] = 'id=' . $id;
      kb_redirect(implode('&', $qs));
    }
    $row = $db->querySingle('SELECT name FROM endpoint WHERE id=' . $id, true);
    if (!$row) {
      flash('error', 'Endpoint not found');
      $qs[] = 'tab=routes';
      kb_redirect(implode('&', $qs));
    }
    $rel  = preg_replace('/^v1\//', '', $row['name']);
    $file = $API_Directory . '/' . $rel . '.php';
    try {
      list($html, $meta, $summary) = ai_generate_full_docs($file, basename($file), $AI_MODEL_PRIMARY, $AI_MODEL_FALLBACK, $OPENAI_API_KEY);
      $scope = 'endpoint:' . $row['name'];
      kv_add($db, $scope, 'help_html', $html);
      kv_add($db, $scope, 'ai_meta_json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
      if (!empty($summary)) {
        $stmt = $db->prepare("UPDATE endpoint SET summary=COALESCE(NULLIF(summary,''), ?), updated_at=strftime('%Y-%m-%dT%H:%M:%SZ','now') WHERE id=?");
        $stmt->bindValue(1, $summary, SQLITE3_TEXT);
        $stmt->bindValue(2, $id, SQLITE3_INTEGER);
        $stmt->execute();
      }
      flash('success', 'AI help + metadata generated.');
    } catch (Exception $e) {
      flash('error', 'AI generation failed: ' . h($e->getMessage()));
    }
    $qs[] = 'tab=routes';
    $qs[] = 'id=' . $id;
    kb_redirect(implode('&', $qs));
  }

  // ── AI config save ──
  if ($action === 'save_config') {
    $temperature = (float)(isset($_POST['ai_temperature']) ? $_POST['ai_temperature'] : 0.4);
    $max_tokens  = (int)(isset($_POST['ai_max_tokens'])    ? $_POST['ai_max_tokens']    : 1200);
    $timeout     = (int)(isset($_POST['ai_timeout'])        ? $_POST['ai_timeout']        : 900);
    kv_set($db, 'admin_config', 'ai_temperature', (string)$temperature);
    kv_set($db, 'admin_config', 'ai_max_tokens',  (string)$max_tokens);
    kv_set($db, 'admin_config', 'ai_timeout',     (string)$timeout);
    flash('success', 'AI options saved.');
    $qs[] = 'tab=routes';
    kb_redirect(implode('&', $qs));
  }
}

// ══════════════════════════════════════════════════════
//  Load data for display
// ══════════════════════════════════════════════════════
$apiKeys        = api_keys_read();
$lastCreatedKey = isset($_SESSION['last_created_key']) ? $_SESSION['last_created_key'] : null;
unset($_SESSION['last_created_key']);
$editKeyId = isset($_GET['edit_key']) ? $_GET['edit_key'] : '';

// Scan live v1 directory for guard info (lightweight, used for scope reference)
$liveRoutes = scanEndpoints($API_Directory);
$guardMap = []; // name => ['guard_endpoint'=>..., 'guard_needs_tools'=>bool]
foreach ($liveRoutes as $lr) {
  $guardMap[$lr['name']] = [
    'guard_endpoint'    => $lr['guard_endpoint'],
    'guard_needs_tools' => $lr['guard_needs_tools'],
  ];
}

// Endpoints from DB
$eps = [];
$res = $db->query('SELECT * FROM endpoint ORDER BY name');
while ($r = $res->fetchArray(SQLITE3_ASSOC)) { $eps[] = $r; }
$byFolder = [];
foreach ($eps as $e) {
  $pathPart = preg_replace('/^v1\//', '', $e['name']);
  $segments = explode('/', $pathPart);
  $folder = (count($segments) > 1) ? $segments[0] : '(root)';
  $byFolder[$folder][] = $e;
}
ksort($byFolder);

// Selected endpoint detail
$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$selected = null;
if ($selectedId) { $selected = $db->querySingle('SELECT * FROM endpoint WHERE id=' . $selectedId, true); }
$selectedHelpHtml    = null;
$selectedMetaJson    = null;
$selectedHelpHtmlAll = [];
$selectedMetaJsonAll = [];
if ($selected) {
  $scope = 'endpoint:' . $selected['name'];
  $selectedHelpHtml    = kv_get($db, $scope, 'help_html');
  $selectedMetaJson    = kv_get($db, $scope, 'ai_meta_json');
  $selectedHelpHtmlAll = kv_get_all($db, $scope, 'help_html');
  $selectedMetaJsonAll = kv_get_all($db, $scope, 'ai_meta_json');
}

$fl = flashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>API Keys &amp; Knowledge Base</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    .scroll-thin::-webkit-scrollbar{width:6px;}
    .scroll-thin::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:3px;}
    .key-reveal{user-select:all;}
  </style>
</head>
<body class="bg-gray-50 min-h-screen">

<!-- ── Header ── -->
<div class="bg-gradient-to-r from-slate-800 to-indigo-900 text-white py-5 mb-6">
  <div class="container mx-auto px-4 flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-semibold">🔐 API Keys &amp; Knowledge Base</h1>
      <p class="text-sm opacity-80">Manage keys, scopes, and route documentation</p>
    </div>
    <div class="flex flex-wrap gap-2 justify-end">
      <a href="admin_AI_Setup.php<?php echo $EMBED_QS; ?>" class="bg-white/15 hover:bg-white/25 text-white px-4 py-2 rounded-md text-sm">AI Setup</a>
      <a href="index.php<?php echo $EMBED_QS; ?>" class="bg-white/15 hover:bg-white/25 text-white px-4 py-2 rounded-md text-sm">Admin Home</a>
    </div>
  </div>
</div>

<div class="container mx-auto px-4">

  <!-- Flash -->
  <?php if ($fl): ?>
  <div class="mb-4 space-y-2">
    <?php foreach ($fl as $f): ?>
    <div class="px-4 py-2 rounded-md text-sm <?php echo $f['t'] === 'success' ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-red-100 text-red-800 border border-red-200'; ?>"><?php echo h($f['m']); ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Newly created key banner -->
  <?php if ($lastCreatedKey): ?>
  <div class="mb-4 bg-amber-50 border border-amber-300 rounded-lg p-4">
    <div class="flex items-center gap-2 mb-2">
      <span class="text-amber-700 font-semibold text-sm">🔑 New API Key Created — Copy it now, it won't be shown again in full!</span>
    </div>
    <div class="flex items-center gap-2">
      <code id="newKeyValue" class="key-reveal flex-1 bg-white border border-amber-200 rounded px-3 py-2 text-sm font-mono text-gray-900"><?php echo h($lastCreatedKey); ?></code>
      <button onclick="copyKey()" class="bg-amber-600 hover:bg-amber-700 text-white text-sm px-4 py-2 rounded-md">📋 Copy</button>
    </div>
    <p class="text-xs text-amber-600 mt-2">Send this key via <code>X-API-Key</code> header or <code>Authorization: Bearer &lt;key&gt;</code></p>
  </div>
  <script>
  function copyKey(){
    var v = document.getElementById('newKeyValue');
    if (navigator.clipboard) { navigator.clipboard.writeText(v.textContent.trim()); }
    else { var r=document.createRange(); r.selectNode(v); window.getSelection().removeAllRanges(); window.getSelection().addRange(r); document.execCommand('copy'); }
  }
  </script>
  <?php endif; ?>

  <!-- ── Tab navigation ── -->
  <div class="flex border-b border-gray-200 mb-6">
    <a href="?<?php echo $IS_EMBED ? 'embed=1&' : ''; ?>tab=keys"
       class="px-5 py-3 text-sm font-medium border-b-2 transition <?php echo $tab === 'keys' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
      🔑 API Keys
    </a>
    <a href="?<?php echo $IS_EMBED ? 'embed=1&' : ''; ?>tab=routes"
       class="px-5 py-3 text-sm font-medium border-b-2 transition <?php echo $tab === 'routes' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
      🧭 Routes &amp; Docs
    </a>
  </div>

  <!-- ╔══════════════════════════════════════════════╗ -->
  <!-- ║           TAB: API Keys                      ║ -->
  <!-- ╚══════════════════════════════════════════════╝ -->
  <?php if ($tab === 'keys'): ?>

  <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

    <!-- Keys Table (2/3 width) -->
    <div class="xl:col-span-2">
      <div class="bg-white rounded-lg shadow">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
          <h2 class="text-lg font-semibold text-gray-800">Active Keys</h2>
          <span class="text-xs text-gray-400"><?php echo h($API_KEYS_FILE); ?></span>
        </div>
        <?php if (empty($apiKeys)): ?>
        <div class="px-5 py-10 text-center text-gray-400 text-sm">
          No API keys yet. Create one below.
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
              <tr>
                <th class="px-4 py-3 text-left">Name</th>
                <th class="px-4 py-3 text-left">Key</th>
                <th class="px-4 py-3 text-left">Scopes</th>
                <th class="px-4 py-3 text-center">Status</th>
                <th class="px-4 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <?php foreach ($apiKeys as $keyStr => $keyData): ?>
              <?php
                $isObj    = is_array($keyData) && isset($keyData['scopes']);
                $kName    = $isObj ? (isset($keyData['name']) ? $keyData['name'] : '') : '';
                $kScopes  = $isObj ? (is_array($keyData['scopes']) ? $keyData['scopes'] : []) : (is_array($keyData) ? $keyData : []);
                $kActive  = $isObj ? (isset($keyData['active']) ? (bool)$keyData['active'] : true) : true;
                $kCreated = $isObj && isset($keyData['created_at']) ? $keyData['created_at'] : '';
                $isEditing = ($editKeyId === $keyStr);
              ?>

              <?php if ($isEditing): ?>
              <!-- Edit form row -->
              <tr class="bg-indigo-50">
                <td colspan="5" class="px-4 py-4">
                  <form method="post" class="space-y-3">
                    <?php csrf_input(); ?>
                    <input type="hidden" name="action" value="edit_key">
                    <input type="hidden" name="api_key" value="<?php echo h($keyStr); ?>">
                    <?php if ($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <div class="flex items-center gap-3 flex-wrap">
                      <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Name</label>
                        <input type="text" name="key_name" value="<?php echo h($kName); ?>"
                               class="w-full border border-gray-300 rounded px-3 py-2 text-sm" placeholder="Friendly name">
                      </div>
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-gray-600 mb-1">Scopes</label>
                      <div class="flex flex-wrap gap-2">
                        <?php foreach ($KNOWN_SCOPES as $sc): ?>
                        <label class="inline-flex items-center gap-1 text-xs bg-gray-100 rounded px-2 py-1 cursor-pointer hover:bg-indigo-50">
                          <input type="checkbox" name="scopes[]" value="<?php echo h($sc); ?>" <?php echo in_array($sc, $kScopes, true) ? 'checked' : ''; ?>>
                          <?php echo h($sc); ?>
                        </label>
                        <?php endforeach; ?>
                      </div>
                    </div>
                    <div class="flex gap-2">
                      <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-4 py-2 rounded-md">Save</button>
                      <a href="?<?php echo $IS_EMBED ? 'embed=1&' : ''; ?>tab=keys" class="bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm px-4 py-2 rounded-md">Cancel</a>
                    </div>
                  </form>
                </td>
              </tr>
              <?php else: ?>
              <!-- Normal display row -->
              <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">
                  <div class="font-medium text-gray-800"><?php echo h($kName ? $kName : '(unnamed)'); ?></div>
                  <?php if ($kCreated): ?><div class="text-[10px] text-gray-400"><?php echo h(substr($kCreated, 0, 16)); ?></div><?php endif; ?>
                </td>
                <td class="px-4 py-3 font-mono text-xs text-gray-600">
                  <span title="<?php echo h($keyStr); ?>"><?php echo h(mask_key($keyStr)); ?></span>
                </td>
                <td class="px-4 py-3">
                  <div class="flex flex-wrap gap-1">
                    <?php if (empty($kScopes)): ?>
                    <span class="text-[10px] text-gray-400 italic">none</span>
                    <?php else: ?>
                    <?php foreach ($kScopes as $sc): ?>
                    <span class="text-[10px] px-1.5 py-0.5 rounded <?php echo $sc === 'tools' ? 'bg-amber-100 text-amber-700' : 'bg-indigo-100 text-indigo-700'; ?>"><?php echo h($sc); ?></span>
                    <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="px-4 py-3 text-center">
                  <form method="post" class="inline">
                    <?php csrf_input(); ?>
                    <input type="hidden" name="action" value="toggle_key">
                    <input type="hidden" name="api_key" value="<?php echo h($keyStr); ?>">
                    <?php if ($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <button type="submit" class="text-xs px-2 py-1 rounded-full <?php echo $kActive ? 'bg-green-100 text-green-700 hover:bg-green-200' : 'bg-red-100 text-red-600 hover:bg-red-200'; ?>"
                            title="Click to <?php echo $kActive ? 'deactivate' : 'activate'; ?>">
                      <?php echo $kActive ? '● Active' : '○ Inactive'; ?>
                    </button>
                  </form>
                </td>
                <td class="px-4 py-3 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <a href="?<?php echo $IS_EMBED ? 'embed=1&' : ''; ?>tab=keys&edit_key=<?php echo urlencode($keyStr); ?>"
                       class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 px-2 py-1 rounded">Edit</a>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this key? This cannot be undone.')">
                      <?php csrf_input(); ?>
                      <input type="hidden" name="action" value="delete_key">
                      <input type="hidden" name="api_key" value="<?php echo h($keyStr); ?>">
                      <?php if ($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                      <button type="submit" class="text-xs bg-red-50 hover:bg-red-100 text-red-600 px-2 py-1 rounded">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <!-- Create New Key -->
      <div class="bg-white rounded-lg shadow mt-6">
        <div class="px-5 py-4 border-b border-gray-100">
          <h2 class="text-lg font-semibold text-gray-800">Create New Key</h2>
        </div>
        <form method="post" class="px-5 py-4 space-y-4">
          <?php csrf_input(); ?>
          <input type="hidden" name="action" value="add_key">
          <?php if ($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Friendly Name</label>
              <input type="text" name="key_name" placeholder="e.g. Mobile App, CI Pipeline"
                     class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Custom Key <span class="text-gray-400">(leave blank to auto-generate)</span></label>
              <input type="text" name="custom_key" placeholder="sk-..."
                     class="w-full border border-gray-300 rounded px-3 py-2 text-sm font-mono">
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Scopes</label>
            <div class="flex flex-wrap gap-2">
              <?php foreach ($KNOWN_SCOPES as $sc): ?>
              <label class="inline-flex items-center gap-1 text-xs bg-gray-100 rounded px-2 py-1.5 cursor-pointer hover:bg-indigo-50">
                <input type="checkbox" name="scopes[]" value="<?php echo h($sc); ?>"
                       <?php echo in_array($sc, ['chat','health'], true) ? 'checked' : ''; ?>>
                <?php echo h($sc); ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="flex justify-end">
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-5 py-2 rounded-md">🔑 Create Key</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Right sidebar: How-to & Scope Reference -->
    <div class="xl:col-span-1 space-y-6">

      <!-- How to use -->
      <div class="bg-white rounded-lg shadow">
        <div class="px-5 py-4 border-b border-gray-100">
          <h3 class="text-sm font-semibold text-gray-700">How to Authenticate</h3>
        </div>
        <div class="px-5 py-4 text-xs text-gray-600 space-y-3">
          <div>
            <div class="font-medium text-gray-700 mb-1">Option 1: X-API-Key header</div>
            <code class="block bg-gray-900 text-green-300 rounded px-3 py-2 text-[11px] overflow-x-auto">curl -H "X-API-Key: sk-your-key" \<br>&nbsp; http://host/v1/ping</code>
          </div>
          <div>
            <div class="font-medium text-gray-700 mb-1">Option 2: Bearer token</div>
            <code class="block bg-gray-900 text-green-300 rounded px-3 py-2 text-[11px] overflow-x-auto">curl -H "Authorization: Bearer sk-your-key" \<br>&nbsp; http://host/v1/ping</code>
          </div>
        </div>
      </div>

      <!-- Scope reference -->
      <div class="bg-white rounded-lg shadow">
        <div class="px-5 py-4 border-b border-gray-100">
          <h3 class="text-sm font-semibold text-gray-700">Scope Reference</h3>
        </div>
        <div class="px-5 py-4">
          <table class="w-full text-[11px]">
            <thead class="text-gray-400 uppercase tracking-wide">
              <tr>
                <th class="text-left pb-2">Scope</th>
                <th class="text-left pb-2">Used by</th>
                <th class="text-center pb-2">Tools?</th>
              </tr>
            </thead>
            <tbody class="text-gray-600 divide-y divide-gray-50">
              <?php
              // Build scope-to-routes mapping from live scan
              $scopeUsage = [];
              foreach ($liveRoutes as $lr) {
                $ge = $lr['guard_endpoint'];
                if (!$ge) continue;
                if (!isset($scopeUsage[$ge])) {
                  $scopeUsage[$ge] = ['routes' => [], 'needs_tools' => $lr['guard_needs_tools']];
                }
                $short = preg_replace('/^v1\//', '', $lr['name']);
                $scopeUsage[$ge]['routes'][] = $short;
                if ($lr['guard_needs_tools']) { $scopeUsage[$ge]['needs_tools'] = true; }
              }
              ksort($scopeUsage);
              foreach ($scopeUsage as $ep => $info):
              ?>
              <tr>
                <td class="py-1.5 font-mono font-medium"><?php echo h($ep); ?></td>
                <td class="py-1.5"><?php echo h(implode(', ', array_unique($info['routes']))); ?></td>
                <td class="py-1.5 text-center"><?php echo $info['needs_tools'] ? '<span class="text-amber-600 font-bold">✓</span>' : ''; ?></td>
              </tr>
              <?php endforeach; ?>
              <tr>
                <td class="py-1.5 font-mono font-medium text-amber-700">tools</td>
                <td class="py-1.5 text-amber-700">Required for ✓ endpoints</td>
                <td class="py-1.5 text-center"><span class="text-amber-600 font-bold">✓</span></td>
              </tr>
            </tbody>
          </table>
          <p class="text-[10px] text-gray-400 mt-3">
            Any valid key = authenticated. The <strong>tools</strong> scope is additionally
            required for endpoints marked with ✓. Unguarded routes (health, models) need no key.
          </p>
        </div>
      </div>

      <!-- File info -->
      <div class="bg-gray-100 rounded-lg p-4 text-xs text-gray-500">
        <strong>Keys file:</strong> <?php echo h($API_KEYS_FILE); ?><br>
        <strong>Total keys:</strong> <?php echo count($apiKeys); ?><br>
        <strong>Active:</strong> <?php
          $activeCount = 0;
          foreach ($apiKeys as $_kd) {
            $a = is_array($_kd) && isset($_kd['active']) ? (bool)$_kd['active'] : true;
            if ($a) $activeCount++;
          }
          echo $activeCount;
        ?>
      </div>
    </div>
  </div>

  <?php endif; // end keys tab ?>


  <!-- ╔══════════════════════════════════════════════╗ -->
  <!-- ║           TAB: Routes & Docs                  ║ -->
  <!-- ╚══════════════════════════════════════════════╝ -->
  <?php if ($tab === 'routes'): ?>

  <!-- Toolbar -->
  <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
    <div class="text-sm text-gray-600">
      <strong><?php echo count($eps); ?></strong> routes indexed
      <?php if (!empty($eps)): ?>
        · Last scan stored in DB
      <?php endif; ?>
    </div>
    <div class="flex gap-2">
      <form method="post">
        <?php csrf_input(); ?>
        <input type="hidden" name="action" value="refresh_routes">
        <?php if ($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-4 py-2 rounded-md">🔄 Refresh Routes from <code class="text-indigo-200">/v1/</code></button>
      </form>
    </div>
  </div>

  <!-- AI Config (collapsible) -->
  <details class="mb-4">
    <summary class="cursor-pointer bg-white rounded-lg shadow px-5 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
      ⚙️ AI Configuration
      <span class="text-xs text-gray-400 ml-2"><?php echo h($PROVIDER); ?> · <?php echo h($AI_MODEL_PRIMARY); ?></span>
    </summary>
    <div class="bg-white rounded-b-lg shadow px-5 py-4 border-t border-gray-100">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs text-gray-700 mb-4">
        <div><span class="text-gray-500">Provider:</span> <?php echo h($PROVIDER); ?></div>
        <div><span class="text-gray-500">Model:</span> <?php echo h($AI_MODEL_PRIMARY); ?></div>
        <div class="md:col-span-2"><span class="text-gray-500">Base URL:</span> <?php echo h($AI_BASE_URL); ?></div>
      </div>
      <form method="post">
        <?php csrf_input(); ?>
        <input type="hidden" name="action" value="save_config">
        <?php if ($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Temperature</label>
            <input type="number" step="0.1" min="0" max="2" name="ai_temperature" value="<?php echo h($ai_temperature); ?>"
                   class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Max Tokens</label>
            <input type="number" min="1" name="ai_max_tokens" value="<?php echo h($ai_max_tokens); ?>"
                   class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Curl Timeout (sec)</label>
            <input type="number" min="1" name="ai_timeout" value="<?php echo h($ai_timeout); ?>"
                   class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
          </div>
        </div>
        <div class="mt-3 flex justify-end gap-2">
          <?php
            $setupUrl = 'admin_AI_Setup.php?' . ($IS_EMBED ? 'embed=1&' : '') . 'popup=1&postmessage=1';
          ?>
          <a href="<?php echo h($setupUrl); ?>" target="_blank"
             class="text-xs bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-2 rounded-md">Configure AI…</a>
          <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-4 py-2 rounded">Save Options</button>
        </div>
      </form>
    </div>
  </details>

  <script>
  window.addEventListener('message', function(ev){
    try { if(ev.origin!==window.location.origin) return; if(!ev.data||ev.data.type!=='ai_setup_saved') return; window.location.reload(); } catch(e){}
  });
  </script>

  <!-- Routes grid -->
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

    <!-- Endpoint tree -->
    <div class="lg:col-span-4 xl:col-span-3 bg-white rounded-lg shadow p-4 flex flex-col max-h-[70vh] overflow-y-auto scroll-thin">
      <h2 class="text-sm font-semibold text-gray-600 mb-2">Endpoints</h2>
      <?php if (empty($eps)): ?>
        <p class="text-xs text-gray-500">No routes indexed. Click <strong>Refresh Routes</strong> above to scan <code><?php echo h($API_Directory); ?></code>.</p>
      <?php else: ?>
        <ul class="space-y-3">
          <?php foreach ($byFolder as $folder => $items): ?>
          <li>
            <div class="text-xs uppercase tracking-wide text-gray-400 mb-1"><?php echo h($folder); ?></div>
            <ul class="space-y-1 ml-1">
              <?php foreach ($items as $ep):
                $active = ($ep['id'] == $selectedId);
                $gi = isset($guardMap[$ep['name']]) ? $guardMap[$ep['name']] : null;
              ?>
              <li>
                <a href="?<?php echo $IS_EMBED ? 'embed=1&' : ''; ?>tab=routes&id=<?php echo (int)$ep['id']; ?>"
                   class="block text-xs px-2 py-1 rounded <?php echo $active ? 'bg-indigo-600 text-white' : 'hover:bg-indigo-50 text-indigo-700'; ?>">
                  <?php echo h($ep['name']); ?>
                  <?php if (!empty($ep['summary'])): ?><span class="ml-1 text-[10px] text-green-500">●</span><?php endif; ?>
                  <?php if ($gi && $gi['guard_needs_tools']): ?><span class="ml-1 text-[10px] <?php echo $active ? 'text-amber-300' : 'text-amber-500'; ?>">🔒</span><?php endif; ?>
                  <?php if ($gi && !$gi['guard_endpoint']): ?><span class="ml-1 text-[10px] <?php echo $active ? 'text-green-300' : 'text-green-500'; ?>">○</span><?php endif; ?>
                </a>
              </li>
              <?php endforeach; ?>
            </ul>
          </li>
          <?php endforeach; ?>
        </ul>
        <div class="mt-4 pt-3 border-t text-[10px] text-gray-400 space-y-0.5">
          <div><span class="text-green-500">●</span> has summary &nbsp; <span class="text-amber-500">🔒</span> needs tools scope &nbsp; <span class="text-green-500">○</span> unguarded</div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Endpoint detail -->
    <div class="lg:col-span-8 xl:col-span-9">
      <?php if (!$selected): ?>
        <div class="bg-white rounded-lg shadow p-8 text-center">
          <p class="text-gray-500 mb-2 text-sm">Select an endpoint from the list to view &amp; edit its documentation.</p>
          <p class="text-xs text-gray-400">Click <strong>Refresh Routes</strong> to rescan <code>/v1/</code> for new files.</p>
        </div>
      <?php else: ?>
        <?php
          $gi = isset($guardMap[$selected['name']]) ? $guardMap[$selected['name']] : null;
        ?>
        <div class="bg-white rounded-lg shadow p-6 space-y-6">
          <!-- Endpoint header -->
          <div class="flex flex-wrap justify-between items-start gap-4">
            <div>
              <h2 class="text-xl font-semibold text-gray-800 mb-1"><?php echo h($selected['name']); ?></h2>
              <div class="text-xs text-gray-500 space-x-3">
                <span>Method: <span class="font-semibold text-indigo-600"><?php echo h($selected['method']); ?></span></span>
                <span>Path: <span class="font-mono"><?php echo h($selected['path']); ?></span></span>
              </div>
              <?php if ($gi): ?>
              <div class="mt-1.5 flex flex-wrap items-center gap-2 text-[11px]">
                <?php if ($gi['guard_endpoint']): ?>
                  <span class="bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded">guard: <?php echo h($gi['guard_endpoint']); ?></span>
                <?php else: ?>
                  <span class="bg-green-50 text-green-700 px-2 py-0.5 rounded">unguarded</span>
                <?php endif; ?>
                <?php if ($gi['guard_needs_tools']): ?>
                  <span class="bg-amber-50 text-amber-700 px-2 py-0.5 rounded">needs tools scope</span>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
            <div class="flex gap-2">
              <form method="post" class="inline-block">
                <?php csrf_input(); ?>
                <?php if ($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <input type="hidden" name="action" value="auto_summary">
                <input type="hidden" name="id" value="<?php echo (int)$selected['id']; ?>">
                <button type="submit" class="text-xs bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-md">✨ Summary</button>
              </form>
              <?php if ($ALLOW_AI): ?>
              <form method="post" class="inline-block">
                <?php csrf_input(); ?>
                <?php if ($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <input type="hidden" name="action" value="ai_full">
                <input type="hidden" name="id" value="<?php echo (int)$selected['id']; ?>">
                <button type="submit" class="text-xs bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded-md">🤖 AI Docs</button>
              </form>
              <?php endif; ?>
            </div>
          </div>

          <!-- Edit description + summary -->
          <form method="post" class="space-y-4">
            <?php csrf_input(); ?>
            <?php if ($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <input type="hidden" name="action" value="save_endpoint">
            <input type="hidden" name="id" value="<?php echo (int)$selected['id']; ?>">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
              <textarea name="description" rows="3" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        placeholder="Human-friendly purpose / usage notes..."><?php echo h($selected['description']); ?></textarea>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Summary</label>
              <textarea name="summary" rows="4" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                        placeholder="Short how-to..."><?php echo h($selected['summary']); ?></textarea>
            </div>
            <div class="flex justify-end gap-3">
              <button type="submit" class="bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-5 py-2 rounded-md">💾 Save</button>
              <a href="<?php echo h($API_URL . '/' . preg_replace('/^v1\//', '', $selected['name'])); ?>" target="_blank"
                 class="bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm font-medium px-5 py-2 rounded-md">Open Endpoint ↗</a>
            </div>
          </form>

          <!-- AI generated docs -->
          <div class="border-t pt-4">
            <h3 class="text-sm font-semibold text-gray-600 mb-2">AI Documentation</h3>
            <?php if (!$ALLOW_AI && !$selectedHelpHtml): ?>
              <p class="text-xs text-gray-500">Configure an AI provider via AI Setup to enable inline generation.</p>
            <?php endif; ?>

            <?php if (!empty($selectedHelpHtmlAll)): ?>
            <div class="mb-4">
              <label class="block text-[11px] font-semibold text-gray-500 mb-1">Help Versions</label>
              <select id="helpVersionSelect" class="border border-gray-300 rounded px-2 py-1 text-xs" onchange="showHelpVersion(this.value)">
                <?php foreach ($selectedHelpHtmlAll as $i => $ver): ?>
                <option value="<?php echo (int)$ver['id']; ?>" <?php echo $i === 0 ? 'selected' : ''; ?>>#<?php echo (int)$ver['id']; ?> @ <?php echo h(substr($ver['created_at'], 0, 16)); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div id="helpIframeWrapper" class="border rounded-md overflow-hidden" style="height:400px;">
              <iframe id="helpIframe" title="Help HTML" class="w-full h-full bg-white"
                      sandbox="allow-same-origin allow-popups allow-top-navigation-by-user-activation"
                      referrerpolicy="no-referrer"></iframe>
            </div>
            <script>
              var helpVersions = <?php echo json_encode($selectedHelpHtmlAll, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
              function showHelpVersion(id){
                var v = null;
                for (var i=0; i<helpVersions.length; i++) { if (helpVersions[i].id == id) { v = helpVersions[i]; break; } }
                var iframe = document.getElementById('helpIframe');
                if (!v) { iframe.srcdoc='<html><body><p style="font:12px sans-serif;color:#888">Version not found</p></body></html>'; return; }
                iframe.srcdoc = v.v;
              }
              document.addEventListener('DOMContentLoaded', function(){
                var sel = document.getElementById('helpVersionSelect');
                if (sel) showHelpVersion(sel.value);
              });
            </script>
            <?php endif; ?>

            <?php if (!empty($selectedMetaJsonAll)): ?>
            <details class="mt-4">
              <summary class="cursor-pointer text-xs text-indigo-600 font-medium">AI Metadata JSON (latest first)</summary>
              <div class="mt-2 space-y-3">
                <?php foreach ($selectedMetaJsonAll as $i => $ver): ?>
                <details <?php echo $i === 0 ? 'open' : ''; ?> class="border rounded">
                  <summary class="text-[11px] px-2 py-1 cursor-pointer bg-gray-100">#<?php echo (int)$ver['id']; ?> @ <?php echo h($ver['created_at']); ?></summary>
                  <pre class="m-0 p-2 text-xs bg-gray-900 text-green-200 overflow-auto" style="max-height:200px;"><?php echo h($ver['v']); ?></pre>
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

  <?php endif; // end routes tab ?>

</div>
</body>
</html>
