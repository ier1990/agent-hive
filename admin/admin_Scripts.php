<?php
// Admin Scripts Knowledge Base
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Shared bootstrap helpers (paths, env loader, CodeWalker JSON reader)
require_once dirname(__DIR__) . '/lib/bootstrap.php';

require_once __DIR__ . '/AI_Templates/AI_Template.php';

$ai = new AI_Template([
	'debug' => true,
	// Policy: missing vars ignored
	'missing_policy' => 'ignore',
]);

function ai_template_db_path(): string {
  $root = defined('PRIVATE_ROOT') ? (string)PRIVATE_ROOT : '/web/private';
  return rtrim($root, "/\\") . '/db/memory/ai_header.db';
}

function ai_template_get_template_text_by_name(string $name): string {
  $path = ai_template_db_path();
  if (!is_file($path)) return '';
  $db = new SQLite3($path);
  // Table is created by /admin/AI_Templates/index.php or /admin/admin_AI_Templates.php.
  $stmt = $db->prepare('SELECT template_text FROM ai_template_templates WHERE name = :name LIMIT 1');
  $stmt->bindValue(':name', $name, SQLITE3_TEXT);
  $res = $stmt->execute();
  $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
  $db->close();
  return is_array($row) ? (string)($row['template_text'] ?? '') : '';
}

// Preserve embed behavior (so we stay within iframe)
$IS_EMBED = in_array(strtolower($_GET['embed'] ?? ''), ['1','true','yes'], true);
$EMBED_QS = $IS_EMBED ? '?embed=1' : '';

$errors = [];

// Debug control
// - Set to true to force debug on for all requests
// - Set to false to force debug off
// - Leave null to follow ?debug=1 (and preserve through POST)
$DEBUG_OVERRIDE = null;
$DEBUG = ($DEBUG_OVERRIDE !== null)
  ? (bool)$DEBUG_OVERRIDE
  : (isset($_GET['debug']) || isset($_POST['debug']));
$GLOBALS['KB_DEBUG'] = $DEBUG;

// Shared AI configuration is managed via admin_AI_Setup.php

function h($s){return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8');}

function help_inline_format($escaped)
{
  $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
  $escaped = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $escaped);
  $escaped = preg_replace('/`(.+?)`/s', '<code>$1</code>', $escaped);
  return $escaped;
}

function render_help_markdown($md)
{
  $lines = preg_split("/\r\n|\r|\n/", (string)$md);
  if (!is_array($lines)) {
    $lines = [(string)$md];
  }

  $out = '';
  $para = [];
  $inList = false;
  $inCode = false;
  $codeLines = [];

  $flushPara = function () use (&$out, &$para) {
    if (empty($para)) return;
    $escaped = htmlspecialchars(implode("\n", $para), ENT_QUOTES, 'UTF-8');
    $escaped = help_inline_format($escaped);
    $out .= '<p>' . nl2br($escaped) . '</p>';
    $para = [];
  };
  $closeList = function () use (&$out, &$inList) {
    if ($inList) {
      $out .= '</ul>';
      $inList = false;
    }
  };
  $flushCode = function () use (&$out, &$inCode, &$codeLines) {
    if (!$inCode) return;
    $escaped = htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES, 'UTF-8');
    $out .= '<pre><code>' . $escaped . "\n" . '</code></pre>';
    $codeLines = [];
    $inCode = false;
  };

  foreach ($lines as $line) {
    $line = (string)$line;

    if (preg_match('/^```/', $line)) {
      $flushPara();
      $closeList();
      if ($inCode) {
        $flushCode();
      } else {
        $inCode = true;
        $codeLines = [];
      }
      continue;
    }

    if ($inCode) {
      $codeLines[] = $line;
      continue;
    }

    if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
      $flushPara();
      $closeList();
      $level = strlen((string)$m[1]);
      $text = help_inline_format(htmlspecialchars((string)$m[2], ENT_QUOTES, 'UTF-8'));
      $out .= '<h' . $level . '>' . $text . '</h' . $level . '>';
      continue;
    }

    if (preg_match('/^\s*-\s+(.*)$/', $line, $m)) {
      $flushPara();
      if (!$inList) {
        $out .= '<ul>';
        $inList = true;
      }
      $item = help_inline_format(htmlspecialchars((string)$m[1], ENT_QUOTES, 'UTF-8'));
      $out .= '<li>' . $item . '</li>';
      continue;
    }

    if (trim($line) === '') {
      $flushPara();
      $closeList();
      continue;
    }

    $para[] = $line;
  }

  $flushPara();
  $closeList();
  if ($inCode) {
    $flushCode();
  }

  return $out;
}
if (empty($_SESSION['csrf_api'])) { $_SESSION['csrf_api'] = bin2hex(random_bytes(32)); }
function csrf_input(){
  echo '<input type="hidden" name="csrf_token" value="'.h($_SESSION['csrf_api']).'">';
  if (!empty($GLOBALS['KB_DEBUG'])) {
    echo '<input type="hidden" name="debug" value="1">';
  }
}
function csrf_valid($t){ return isset($_SESSION['csrf_api']) && hash_equals($_SESSION['csrf_api'], (string)$t); }

// Robust redirect helper (avoids relative path issues that yield 404 inside frames)
function kb_redirect($query=''){ // $query without leading ?
  $base = $_SERVER['SCRIPT_NAME'] ?? 'admin_Scripts.php';
  if (strpos($base,'admin_Scripts.php')===false) { $base = 'admin_Scripts.php'; }

  // Preserve debug across POST -> redirect -> GET.
  if (!empty($GLOBALS['KB_DEBUG']) && strpos((string)$query, 'debug=') === false) {
    $query = (string)$query;
    $query .= ($query !== '' ? '&' : '') . 'debug=1';
  }

  $url = $base . ($query ? ('?'.$query) : '');
  // Fallback to absolute path if not starting with /
  if ($url[0] !== '/') {
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/admin_Scripts.php'),'/');
    $url = $dir . '/' . $url;
  }
  header('Location: '.$url, true, 303);
  exit;
}

$Script_Directory = "/web/private/scripts"; 

// Settings source of truth: CodeWalker settings DB.
$cw = function_exists('codewalker_llm_settings') ? codewalker_llm_settings() : [];

// File types (extensions) allowed for Scripts KB.
// Source of truth: CodeWalker settings DB.
$SCRIPT_FILE_TYPES = [];
if (isset($cw['file_types'])) {
  if (is_array($cw['file_types'])) {
    $SCRIPT_FILE_TYPES = $cw['file_types'];
  } elseif (is_string($cw['file_types']) && $cw['file_types'] !== '') {
    $tmp = json_decode($cw['file_types'], true);
    if (is_array($tmp)) $SCRIPT_FILE_TYPES = $tmp;
  }
}
if (empty($SCRIPT_FILE_TYPES) || !is_array($SCRIPT_FILE_TYPES)) {
  $SCRIPT_FILE_TYPES = ['php', 'py', 'sh'];
}
// Normalize and dedupe
$SCRIPT_FILE_TYPES = array_values(array_unique(array_filter(array_map(function ($v) {
  $v = strtolower(trim((string)$v));
  return $v;
}, $SCRIPT_FILE_TYPES), function ($v) {
  return $v !== '';
})));

// Shared AI endpoint configuration (provider/base/model/key)
$ai_settings = function_exists('ai_settings_get') ? ai_settings_get() : [];
$PROVIDER = (string)($ai_settings['provider'] ?? 'local');
$AI_BASE_URL = (string)($ai_settings['base_url'] ?? 'http://127.0.0.1:1234/v1');
$OPENAI_API_KEY = (string)($ai_settings['api_key'] ?? '');
$AI_MODEL_PRIMARY = (string)($ai_settings['model'] ?? 'openai/gpt-oss-20b');
$AI_MODEL_FALLBACK = (string)env('SCRIPTS_HELP_MODEL_FALLBACK', $AI_MODEL_PRIMARY);

$AI_TIMEOUT = (int)($ai_settings['timeout_seconds'] ?? 120);
if ($AI_TIMEOUT < 1) $AI_TIMEOUT = 120;

$ALLOW_AI = ($PROVIDER !== 'openai') || ($OPENAI_API_KEY !== '');

$GLOBALS['AI_BASE_URL'] = $AI_BASE_URL;
$GLOBALS['OPENAI_API_KEY'] = $OPENAI_API_KEY;
$GLOBALS['PROVIDER'] = $PROVIDER;
$GLOBALS['AI_TIMEOUT'] = $AI_TIMEOUT;

// Convenience URL for debugging



// SQLite schema
$dbPath = '/web/private/db/scripts_knowledge.db';
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
function openai_chat($model,$system,$user,$temperature=0.4,$max_tokens=1200,$api_key=''){
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
  if(!empty($api_key)) { $headers[] = 'Authorization: Bearer '.$api_key; }
  $ch = curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>$headers,
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_TIMEOUT=>(int)($GLOBALS['AI_TIMEOUT'] ?? 120),
  ]);
  $resp = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
  if($resp===false){ throw new Exception('cURL error: '.$err); }
  if($code<200||$code>=300){ throw new Exception('OpenAI HTTP '.$code.' body: '.$resp); }
  $data = json_decode($resp,true); if(!$data) throw new Exception('Invalid JSON from OpenAI');
  $choice = $data['choices'][0]['message']['content'] ?? '';
  return trim($choice);
}

function ai_generate_help_markdown($filePath,$filename,$modelPrimary,$modelFallback,$apiKey,&$used=array()){
  global $errors;
  global $ai;
  
  $errors[] = 'filePath: '.$filePath;
  $errors[] = 'Generating AI docs for '.$filename;
  $errors[] = 'Using model: '.$modelPrimary;
  $errors[] = 'Using fallback: '.$modelFallback;


  $code = @file_get_contents($filePath);
  if(!$code) throw new Exception('Cannot read file');
  $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/','',$code);
  $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
  // Language names: .py -> Python, .php -> PHP, .sh -> BASH
  $lang = ($ext==='py') ? 'Python' : (($ext==='php') ? 'PHP' : (($ext==='sh') ? 'BASH' : strtoupper($ext)));
  // Markdown help (AI_Template template)
  $tpl = ai_template_get_template_text_by_name('Scripts KB - Markdown Help');
  $payload = [];
  if ($tpl !== '' && $ai instanceof AI_Template) {
    $payload = $ai->compilePayload($tpl, [
      'lang' => $lang,
      'filename' => $filename,
      'code' => $clean,
    ]);
  }

  $fallbackUser = "Respond in ENGLISH only.\n\nWrite a concise, well-structured help guide in MARKDOWN for an internal scripts knowledge base.\n- Output MARKDOWN only (no preamble like 'Answer' or 'जवाब').\n- Include: Purpose, Language (${lang}), How to run (example command), Inputs/flags/env vars, Outputs/artifacts, Error handling/exit codes, Dependencies, Security considerations.\n\nSCRIPT (${lang}) SOURCE:\n---\n$clean\n---";

  $sys = (string)($payload['system'] ?? 'You respond in English only and output Markdown only.');
  $user = (string)($payload['user'] ?? $fallbackUser);
  $temp = (float)($payload['options']['temperature'] ?? 0.4);
  $max  = (int)($payload['options']['max_tokens'] ?? 1400);

  $used['md_model'] = $modelPrimary;
  try {
    $md = openai_chat($modelPrimary,$sys,$user,$temp,$max,$apiKey);
  } catch(Exception $e){
    $used['md_model'] = $modelFallback;
    $md = openai_chat($modelFallback,$sys,$user,$temp,$max,$apiKey);
  }

  // Derive a short summary from the Markdown for endpoint.summary.
  $summaryText = trim(preg_replace('/\s+/', ' ', strip_tags(render_help_markdown((string)$md))));
  $summaryText = substr($summaryText, 0, 300);

  return [$md,$summaryText];
}

// Recursive scan for scripts under $Script_Directory (PHP and Python)
function scanEndpoints($root, $allowedExts){
  $out=[]; if(!is_dir($root)) return $out;
  $allowed = is_array($allowedExts) ? $allowedExts : [];
  $allowed = array_values(array_unique(array_map(function($v){ return strtolower(trim((string)$v)); }, $allowed)));
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
  foreach($it as $f){
    if($f->isFile()){
      $ext = strtolower($f->getExtension());
      if(!empty($allowed) && !in_array($ext, $allowed, true)) continue;
      $full = str_replace('\\','/',$f->getPathname());
      $rel = ltrim(str_replace($root,'',$full), '/'); // relative path within scripts dir
      $type = ($ext==='py') ? 'PYTHON' : (($ext==='sh') ? 'BASH' : 'PHP');
      $out[] = [ 'name'=>$rel, 'path'=>$rel, 'method'=>$type, 'file'=>$full ];
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
  $collected=[]; $count=0; $inTriple=false; $triple='';
  foreach($lines as $ln){
    $trim = trim($ln);
    if($trim==='') continue;
    if(strpos($trim,'<?php')===0) continue; // ignore PHP tag
    // Python triple quotes
    if(!$inTriple && (strpos($trim,'"""')===0 || strpos($trim,"'''")===0)){
      $inTriple=true; $triple = (strpos($trim,'"""')===0)?'"""':"'''";
      $collected[] = trim($trim,$triple." ");
      if(substr_count($trim,$triple)===2){ $inTriple=false; }
      $count++; if($count>=12) break; else continue;
    }
    if($inTriple){
      $collected[] = rtrim($trim,$triple);
      if(strpos($trim,$triple)!==false){ $inTriple=false; }
      $count++; if($count>=12) break; else continue;
    }
    // Line comments for PHP //, *, and Python #
    if(strpos($trim,'/*')===0 || strpos($trim,'//')===0 || strpos($trim,'*')===0 || strpos($trim,'#')===0){
      $collected[] = preg_replace('/^(\/\/|\*|#)+\s?/','',$trim);
      $count++;
    }
    if($count>=12) break;
    if(preg_match('/function\s+/i',$trim) || preg_match('/^def\s+/',$trim)) break; // stop at first function/def
  }
    $summary = trim(implode(" ", $collected));
    return substr($summary,0,800);
}

// Auto-sync scripts directory into the DB.
// This replaces the manual "Sync" button: the page keeps itself up to date.
function kb_sync_scripts_into_db($db, $scriptDir, $allowedExts, $debug = false, &$debugErrors = null) {
  if (!($db instanceof SQLite3)) return ['found' => 0, 'added' => 0, 'updated' => 0, 'changed' => 0];
  if (!is_dir($scriptDir)) return ['found' => 0, 'added' => 0, 'updated' => 0, 'changed' => 0];

  $found = scanEndpoints($scriptDir, $allowedExts);
  $added = 0;
  $updated = 0;
  $changed = 0;

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

    // Refresh stored source only when file content changes (sha256 differs).
    $scope = 'endpoint:' . $ep['name'];
    $maxBytes = 1024 * 1024; // 1 MiB safety limit
    $fs = @filesize($ep['file']);
    if ($fs === false || $fs > $maxBytes) {
      $meta = [
        'sha256' => null,
        'size' => $fs,
        'rel_path' => $ep['path'],
        'abs_path' => $ep['file'],
        'language' => $ep['method'],
        'captured_at' => gmdate('c'),
        'skipped' => 'file too large or size unknown'
      ];
      kv_set($db, $scope, 'source_meta', json_encode($meta, JSON_UNESCAPED_UNICODE));
      continue;
    }

    $sha = @hash_file('sha256', $ep['file']);
    if (!$sha) continue;
    $prevMetaRaw = kv_get($db, $scope, 'source_meta');
    $prevMeta = is_string($prevMetaRaw) ? json_decode($prevMetaRaw, true) : null;
    $prevSha = is_array($prevMeta) ? (string)($prevMeta['sha256'] ?? '') : '';

    if ($prevSha === $sha) {
      continue;
    }

    $code = @file_get_contents($ep['file']);
    if ($code === false) {
      if ($debug && is_array($debugErrors)) {
        $debugErrors[] = 'Failed to read changed file: ' . $ep['file'];
      }
      continue;
    }

    $meta = [
      'sha256' => $sha,
      'size' => strlen($code),
      'rel_path' => $ep['path'],
      'abs_path' => $ep['file'],
      'language' => $ep['method'],
      'captured_at' => gmdate('c'),
    ];
    kv_set($db, $scope, 'source_code', $code);
    kv_set($db, $scope, 'source_meta', json_encode($meta, JSON_UNESCAPED_UNICODE));
    $changed++;
  }

  return ['found' => count($found), 'added' => $added, 'updated' => $updated, 'changed' => $changed];
}

// Handle actions
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (!csrf_valid($_POST['csrf_token'] ?? '')) {
    flash('error', 'Invalid CSRF token');
    kb_redirect($IS_EMBED ? 'embed=1' : '');
  }

  if ($action === 'set_model_override') {
    $id = (int)($_POST['id'] ?? 0);
    $row = $db->querySingle('SELECT name FROM endpoint WHERE id=' . $id, true);
    if (!$row) {
      flash('error', 'Script not found');
      kb_redirect($IS_EMBED ? 'embed=1' : '');
    }
    $scope = 'endpoint:' . $row['name'];
    $modelOverride = trim((string)($_POST['model_override'] ?? ''));
    // Store blank to clear.
    kv_set($db, $scope, 'ai_model_override', $modelOverride);
    flash('success', $modelOverride === '' ? 'AI model override cleared.' : 'AI model override saved.');
    kb_redirect(trim(($IS_EMBED ? 'embed=1&' : '') . 'id=' . $id, '&'));
  }

  if ($action === 'save_endpoint') {
    $id = (int)($_POST['id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $summary     = trim($_POST['summary'] ?? '');

    $stmt = $db->prepare('UPDATE endpoint SET description=?, summary=?, updated_at=strftime("%Y-%m-%dT%H:%M:%SZ","now") WHERE id=?');
    $stmt->bindValue(1, $description, SQLITE3_TEXT);
    $stmt->bindValue(2, $summary, SQLITE3_TEXT);
    $stmt->bindValue(3, $id, SQLITE3_INTEGER);
    $ok = $stmt->execute();

    flash($ok ? 'success' : 'error', $ok ? 'Script updated.' : 'Update failed.');
    kb_redirect(trim(($IS_EMBED ? 'embed=1&' : '') . ($ok ? 'id=' . $id : ''), '&'));
  } elseif ($action === 'auto_summary') {
    $id = (int)($_POST['id'] ?? 0);
    $row = $db->querySingle('SELECT name, path FROM endpoint WHERE id=' . $id, true);
    if ($row) {
      $file = rtrim($Script_Directory, '/') . '/' . $row['path'];
      $sum = generateSummaryFromFile($file);
      if ($sum === '') $sum = '(No leading comments found)';

      $stmt = $db->prepare('UPDATE endpoint SET summary=?, updated_at=strftime("%Y-%m-%dT%H:%M:%SZ","now") WHERE id=?');
      $stmt->bindValue(1, $sum, SQLITE3_TEXT);
      $stmt->bindValue(2, $id, SQLITE3_INTEGER);
      $stmt->execute();
      flash('success', 'Summary generated from source.');
    } else {
      flash('error', 'Script not found');
    }

    kb_redirect(trim(($IS_EMBED ? 'embed=1&' : '') . ($id ? 'id=' . $id : ''), '&'));
  } elseif ($action === 'ai_full') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$ALLOW_AI) {
      flash('error', 'AI not configured (missing API key or local base_url).');
      kb_redirect(trim(($IS_EMBED ? 'embed=1&' : '') . ($id ? 'id=' . $id : ''), '&'));
    }

    $row = $db->querySingle('SELECT name FROM endpoint WHERE id=' . $id, true);
    if (!$row) {
      flash('error', 'Script not found');
      kb_redirect($IS_EMBED ? 'embed=1' : '');
    }

    $scope = 'endpoint:' . $row['name'];
    $modelOverride = trim((string)kv_get($db, $scope, 'ai_model_override'));
    $modelPrimary = $modelOverride !== '' ? $modelOverride : $AI_MODEL_PRIMARY;

    $rel = $row['name'];
    $file = rtrim($Script_Directory, '/') . '/' . $rel;

    try {
      $used = [];
      list($md, $summary) = ai_generate_help_markdown($file, basename($file), $modelPrimary, $AI_MODEL_FALLBACK, $OPENAI_API_KEY, $used);

      // Append new versions (keep history)
      kv_add($db, $scope, 'help_md', $md);

      $run = [
        'ran_at' => gmdate('c'),
        'provider' => (string)($GLOBALS['PROVIDER'] ?? ''),
        'base_url' => (string)($GLOBALS['AI_BASE_URL'] ?? ''),
        'model_primary' => $modelPrimary,
        'model_fallback' => (string)$AI_MODEL_FALLBACK,
        'model_override' => $modelOverride,
        'used' => $used,
      ];
      kv_set($db, $scope, 'ai_run_meta', json_encode($run, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

      if (!empty($summary)) {
        // Preserve existing summary if already set (only fill when empty)
        $stmt = $db->prepare("UPDATE endpoint SET summary=COALESCE(NULLIF(summary,''), ?), updated_at=strftime('%Y-%m-%dT%H:%M:%SZ','now') WHERE id=?");
        $stmt->bindValue(1, $summary, SQLITE3_TEXT);
        $stmt->bindValue(2, $id, SQLITE3_INTEGER);
        $stmt->execute();
      }
      flash('success', 'AI help generated.');
    } catch (Exception $e) {
      $errors[] = 'ai_full failed: ' . $e->getMessage();
      flash('error', 'AI generation failed: ' . h($e->getMessage()));
    }

    kb_redirect(trim(($IS_EMBED ? 'embed=1&' : '') . 'id=' . $id, '&'));
  } elseif ($action === 'store_source') {
    $id = (int)($_POST['id'] ?? 0);
    $row = $db->querySingle('SELECT name, path, method FROM endpoint WHERE id=' . $id, true);
    if (!$row) {
      flash('error', 'Script not found');
      kb_redirect($IS_EMBED ? 'embed=1' : '');
    }

    $file = rtrim($Script_Directory, '/') . '/' . $row['path'];
    $scope = 'endpoint:' . $row['name'];
    $maxBytes = 1024 * 1024; // 1 MiB safety cap
    $fs = @filesize($file);

    if ($fs !== false && $fs <= $maxBytes) {
      $code = @file_get_contents($file);
      if ($code !== false) {
        $meta = [
          'sha256' => hash('sha256', $code),
          'size' => strlen($code),
          'rel_path' => $row['path'],
          'abs_path' => $file,
          'language' => $row['method'],
          'captured_at' => gmdate('c'),
        ];
        kv_set($db, $scope, 'source_code', $code);
        kv_set($db, $scope, 'source_meta', json_encode($meta, JSON_UNESCAPED_UNICODE));
        flash('success', 'Source captured.');
      } else {
        flash('error', 'Failed to read file.');
      }
    } else {
      flash('error', 'File too large or unknown size.');
    }

    kb_redirect(trim(($IS_EMBED ? 'embed=1&' : '') . 'id=' . $id, '&'));
  }
}

// Auto sync on page load (throttled). This avoids needing a manual Sync button.
$AUTO_SYNC_STATS = null;
try {
  $now = time();
  $last = (int)($_SESSION['kb_last_scripts_sync'] ?? 0);
  if (($now - $last) >= 10) {
    $_SESSION['kb_last_scripts_sync'] = $now;
    $AUTO_SYNC_STATS = kb_sync_scripts_into_db($db, $Script_Directory, $SCRIPT_FILE_TYPES, !empty($DEBUG), $errors);
  }
} catch (Throwable $t) {
  if (!empty($DEBUG)) {
    $errors[] = 'Auto-sync failed: ' . $t->getMessage();
  }
}

// Fetch endpoints for tree
$eps=[]; $res=$db->query('SELECT * FROM endpoint ORDER BY name'); while($r=$res->fetchArray(SQLITE3_ASSOC)){ $eps[]=$r; }
$byFolder=[]; foreach($eps as $e){
  $pathPart = $e['name'];
    $segments = explode('/', $pathPart);
    if(count($segments)>1){ $folder=$segments[0]; } else { $folder='(root)'; }
    $byFolder[$folder][]=$e;
}
ksort($byFolder);

// Selected endpoint detail
$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$selected = null; if($selectedId){ $selected = $db->querySingle('SELECT * FROM endpoint WHERE id='.$selectedId, true); }
$selectedHelpMd = null;
$selectedHelpHtml = null;
if($selected){
  $scope='endpoint:'.$selected['name'];
  $selectedHelpMd = kv_get($db,$scope,'help_md'); // latest
  $selectedHelpMdAll = kv_get_all($db,$scope,'help_md');
  // Legacy fallback: older entries stored as HTML.
  if (empty($selectedHelpMdAll)) {
    $selectedHelpHtml = kv_get($db,$scope,'help_html');
    $selectedHelpHtmlAll = kv_get_all($db,$scope,'help_html');
  }
  $selectedModelOverride = trim((string)kv_get($db,$scope,'ai_model_override'));
  $selectedAiRunMetaRaw = (string)kv_get($db,$scope,'ai_run_meta');
  $selectedAiRunMeta = json_decode($selectedAiRunMetaRaw, true);
  if (!is_array($selectedAiRunMeta)) $selectedAiRunMeta = null;
}
$fl = flashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Scripts Knowledge Base</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>.scroll-thin::-webkit-scrollbar{width:6px;} .scroll-thin::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:3px;}</style>
</head>
<body class="bg-gray-50 min-h-screen">
  <div class="bg-gradient-to-r from-sky-500 to-indigo-600 text-white py-5 mb-6">
    <div class="container mx-auto px-4 flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold">🧭 Scripts Knowledge Base</h1>
        <p class="text-sm opacity-90">Indexed Scripts with editable docs & generated summaries</p>
      </div>
      <div class="flex flex-wrap gap-2 justify-end">
        <a href="admin_AI_Templates.php" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-md text-sm whitespace-nowrap">AI Templates</a>
        <a href="admin_AI_Setup.php<?php echo $IS_EMBED?'?embed=1':''; ?>" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-md text-sm whitespace-nowrap">AI Setup</a>
        <a href="admin_Crontab.php" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-md text-sm whitespace-nowrap">Crontab</a>
        <a href="admin_Scripts.php<?php echo $IS_EMBED?'?embed=1':''; ?>" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-md text-sm whitespace-nowrap">Home</a>
      </div>
    </div>
  </div>
  <div class="container mx-auto px-4">
    <?php if(!empty($DEBUG)): ?>
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
      </div>
    <?php endif; ?>
    <?php if($fl): ?>
      <div class="mb-4 space-y-2">
        <?php foreach($fl as $f): ?>
          <div class="px-4 py-2 rounded-md text-sm <?php echo $f['t']==='success'?'bg-green-100 text-green-800 border border-green-200':'bg-red-100 text-red-800 border border-red-200'; ?>"><?php echo h($f['m']); ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
      <!-- Tree -->
      <div class="lg:col-span-4 xl:col-span-3 bg-white rounded-lg shadow p-4 flex flex-col max-h-[70vh] overflow-y-auto scroll-thin">
  <h2 class="text-sm font-semibold text-gray-600 mb-2">Scripts</h2>
        <?php if(empty($eps)): ?>
          <p class="text-xs text-gray-500">No scripts indexed yet. Run Sync.</p>
        <?php else: ?>
          <ul class="space-y-3">
            <?php foreach($byFolder as $folder=>$items): ?>
              <li>
                <div class="text-xs uppercase tracking-wide text-gray-400 mb-1"><?php echo h($folder); ?></div>
                <ul class="space-y-1 ml-1">
                  <?php foreach($items as $ep): $active = ($ep['id']==$selectedId); ?>
                    <li>
                      <a href="admin_Scripts.php?<?php echo $IS_EMBED?'embed=1&':''; ?>id=<?php echo (int)$ep['id']; ?>" class="block text-xs px-2 py-1 rounded <?php echo $active?'bg-indigo-600 text-white':'hover:bg-indigo-50 text-indigo-700'; ?>">
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
            <p class="text-gray-500 mb-4 text-sm">Select a script from the left to view & edit its documentation.</p>
            <p class="text-xs text-gray-400">Green dot indicates summary present.</p>
          </div>
        <?php else: ?>
          <div class="bg-white rounded-lg shadow p-6 space-y-6">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
              <div class="min-w-0">
                <h2 class="text-xl font-semibold text-gray-800 mb-1 break-words"><?php echo h($selected['name']); ?></h2>
                <div class="text-xs text-gray-500 break-words">Type: <span class="font-semibold text-indigo-600"><?php echo h($selected['method']); ?></span> • Path: <span class="font-mono"><?php echo h($selected['path']); ?></span></div>
                <div class="mt-2 text-xs text-gray-600 break-words">
                  AI Model Override: <span class="font-mono"><?php echo h($selectedModelOverride ?? ''); ?></span>
                  <?php if(!empty($selectedAiRunMeta) && is_array($selectedAiRunMeta)): ?>
                    <span class="text-gray-400"> • Last AI Run: <?php echo h((string)($selectedAiRunMeta['ran_at'] ?? '')); ?></span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="w-full lg:w-auto lg:ml-4 flex flex-col gap-3 lg:items-end">
                <form method="post" class="w-full lg:w-auto flex flex-col sm:flex-row gap-2 sm:items-center">
                  <?php csrf_input(); ?>
                  <?php if($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                  <input type="hidden" name="action" value="set_model_override">
                  <input type="hidden" name="id" value="<?php echo (int)$selected['id']; ?>">
                  <input type="text" name="model_override" value="<?php echo h($selectedModelOverride ?? ''); ?>" placeholder="(blank = default)" class="border border-gray-300 rounded px-2 py-1 text-xs w-full sm:w-56">
                  <div class="flex gap-2 items-center">
                    <button type="submit" class="text-xs bg-slate-800 hover:bg-slate-900 text-white px-3 py-2 rounded-md whitespace-nowrap">Save Model</button>
                    <a class="text-xs bg-white border border-slate-300 hover:bg-slate-50 text-slate-800 px-3 py-2 rounded-md whitespace-nowrap" href="admin_Scripts.php?<?php echo $IS_EMBED?'embed=1&':''; ?>id=<?php echo (int)$selected['id']; ?>&models=1<?php echo !empty($DEBUG)?'&debug=1':''; ?>">Load models</a>
                  </div>
                </form>

                <div class="w-full lg:w-auto flex flex-wrap gap-2 lg:justify-end">
                  <form method="post" class="inline-block">
                    <?php csrf_input(); ?>
                    <?php if($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <input type="hidden" name="action" value="auto_summary">
                    <input type="hidden" name="id" value="<?php echo (int)$selected['id']; ?>">
                    <button type="submit" class="text-xs bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 rounded-md whitespace-nowrap">✨ Generate Summary</button>
                  </form>
                  <form method="post" class="inline-block" title="Store script source + metadata for vector DB">
                    <?php csrf_input(); ?>
                    <?php if($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <input type="hidden" name="action" value="store_source">
                    <input type="hidden" name="id" value="<?php echo (int)$selected['id']; ?>">
                    <button type="submit" class="text-xs bg-slate-600 hover:bg-slate-700 text-white px-3 py-2 rounded-md whitespace-nowrap">🗂️ Store Source</button>
                  </form>
                  <?php if($ALLOW_AI): ?>
                  <form method="post" class="inline-block">
                    <?php csrf_input(); ?>
                    <?php if($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <input type="hidden" name="action" value="ai_full">
                    <input type="hidden" name="id" value="<?php echo (int)$selected['id']; ?>">
                    <button type="submit" class="text-xs bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded-md whitespace-nowrap">🤖 AI Generate Full Help</button>
                  </form>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php
              $modelList = null;
              if ($selected && isset($_GET['models'])) {
                $modelList = ai_list_models($AI_BASE_URL, $PROVIDER ?? '', $OPENAI_API_KEY);
              }
            ?>
            <?php if(is_array($modelList)): ?>
              <div class="bg-slate-50 border border-slate-200 rounded p-3 text-xs">
                <div class="font-semibold text-slate-700">Available Models (<?php echo h((string)($modelList['source'] ?? '')); ?>)</div>
                <?php if(!empty($modelList['ok'])): ?>
                  <form method="post" class="mt-2 flex gap-2 items-center">
                    <?php csrf_input(); ?>
                    <?php if($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <input type="hidden" name="action" value="set_model_override">
                    <input type="hidden" name="id" value="<?php echo (int)$selected['id']; ?>">
                    <select name="model_override" class="border border-gray-300 rounded px-2 py-1 text-xs">
                      <option value="">(blank = default)</option>
                      <?php foreach(($modelList['models'] ?? []) as $m): ?>
                        <option value="<?php echo h($m); ?>" <?php echo ($selectedModelOverride===$m)?'selected':''; ?>><?php echo h($m); ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="text-xs bg-slate-800 hover:bg-slate-900 text-white px-3 py-2 rounded-md">Use Selected</button>
                  </form>
                <?php else: ?>
                  <div class="mt-1 text-red-700">Failed to load models: <?php echo h((string)($modelList['error'] ?? '')); ?></div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <div class="border-t pt-4">
              <h3 class="text-sm font-semibold text-gray-600 mb-2">AI Documentation</h3>
        <?php if(!$ALLOW_AI && empty($selectedHelpMd) && empty($selectedHelpHtml)): ?>
          <p class="text-xs text-gray-500">AI generation disabled. Configure CodeWalker AI settings in <code>Admin → AI Config</code> (stored in <code>/web/private/db/codewalker_settings.db</code>).</p>
        <?php endif; ?>
              <?php if(!empty($selectedHelpMdAll)): ?>
                <?php
                  $selectedHelpRenderedAll = [];
                  foreach ($selectedHelpMdAll as $ver) {
                    $selectedHelpRenderedAll[] = [
                      'id' => (int)($ver['id'] ?? 0),
                      'created_at' => (string)($ver['created_at'] ?? ''),
                      'v' => render_help_markdown((string)($ver['v'] ?? '')),
                    ];
                  }
                ?>
                <div class="mb-4">
                  <label class="block text-[11px] font-semibold text-gray-500 mb-1">Help Versions</label>
                  <select id="helpVersionSelect" class="border border-gray-300 rounded px-2 py-1 text-xs" onchange="showHelpVersion(this.value)">
                    <?php foreach($selectedHelpMdAll as $i=>$ver): ?>
                      <option value="<?php echo (int)$ver['id']; ?>" <?php echo $i===0?'selected':''; ?>>#<?php echo (int)$ver['id']; ?> @ <?php echo h(substr($ver['created_at'],0,16)); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div id="helpIframeWrapper" class="border rounded-md overflow-hidden" style="height:400px;">
                  <iframe id="helpIframe" title="Help HTML" class="w-full h-full bg-white" sandbox="allow-same-origin allow-popups allow-top-navigation-by-user-activation" referrerpolicy="no-referrer"></iframe>
                </div>
                <script>
                  const helpVersions = <?php echo json_encode($selectedHelpRenderedAll, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
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
              <?php elseif(!empty($selectedHelpHtmlAll)): ?>
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

              <div class="flex justify-end gap-3">
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-5 py-2 rounded-md">💾 Save</button>
              </div>
            </form>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
<?php
// For debugging purposes
if (!empty($DEBUG)) {
  echo "AI_BASE_URL=" . h($AI_BASE_URL) . "<br>\n";
  echo "AI_MODEL_PRIMARY=" . h($AI_MODEL_PRIMARY) . "<br>\n";
  echo "AI_MODEL_FALLBACK=" . h($AI_MODEL_FALLBACK) . "<br>\n";
  echo "Allow AI: " . ($ALLOW_AI ? 'yes' : 'no') . "<br>\n";

  if (!empty($errors)) {
    echo "<h3>Debug Errors:</h3>\n";
    foreach ($errors as $err) {
      echo '<div style="color:red;font-size:12px;">' . h($err) . "</div>\n";
    }
  }
}

?>




