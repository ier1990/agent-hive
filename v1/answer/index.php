<?php
// /v1/answer — Search -> LLM summarize with citations (no tools)
/*
Example cURL

curl -X POST https://api.iernc.net/v1/answer \
-H "Content-Type: application/json" \
-H "Authorization: Bearer YOUR_API_KEY" \
-d '{
  "q": "Lincoln Electric 300 Circuit board & screen",
  "limit": 10,
  "format": "markdown"
}' 
*/
// --- Headers / CORS / self-test ---
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json; charset=utf-8');
header('X-Answer-Handler: answer.php');
header('X-Answer-Build: 2025-11-08T00:00Z');

register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error'=>'fatal','detail'=>$e['message']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }
});

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (isset($_GET['ping'])) { echo json_encode(['ok'=>true,'who'=>'answer.php','php'=>PHP_VERSION,'cwd'=>getcwd()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }

// --- Bootstrap / guard ---
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
api_guard_once('answer', /* needsTools? */ false);

const JSONF = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

// --- helpers ---
function respond_error(int $code, string $msg, array $extra = []) { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg] + $extra, JSONF); exit; }
function jexit($arr, $code=200){ http_response_code($code); echo json_encode($arr, JSONF); exit; }
//function json_body(){ if(function_exists('request_json')){ $b=request_json(); return is_array($b)?$b:[]; } $raw=file_get_contents('php://input'); $j=json_decode($raw,true); return is_array($j)?$j:[]; }
// Read JSON body safely (no framework deps)
function json_body(): array {
  $raw = file_get_contents('php://input');
  if ($raw === '' || $raw === false) return [];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

// Collapse whitespace in q and trim (nice to have)
function normalize_query(string $q): string {
  $q = preg_replace('/\s+/', ' ', $q);
  return trim($q);
}

// Simple GET with query string; spaces -> '+' by default
function http_get_qs(string $baseUrl, array $qs = [], int $connectTimeout = 3, int $timeout = 30, bool $spaces_as_plus = true): array {
  if (!empty($qs['q']) && is_string($qs['q'])) {
    $qs['q'] = normalize_query($qs['q']);
  }

  // RFC1738 => space encoded as '+'
  // RFC3986 => space encoded as '%20'
  $enc = $spaces_as_plus ? PHP_QUERY_RFC1738 : PHP_QUERY_RFC3986;
  $query = $qs ? http_build_query($qs, '', '&', $enc) : '';
  $url = $baseUrl . ($query ? ((strpos($baseUrl, '?') === false ? '?' : '&') . $query) : '');

  // ---- Cache setup ----
  $dbPath = getenv('IERNC_SEARCH_DB_PATH') ?: '/web/private/db/search_cache.db';
  $log_file = '/web/private/logs/answer_search.log';
  
  $createTableSQL = 'CREATE TABLE IF NOT EXISTS search_cache (
    key_hash CHAR(64) PRIMARY KEY,
    q TEXT, sources TEXT, site TEXT, time_range TEXT,
    limit_i INT, offset_i INT, sort TEXT,
    body MEDIUMTEXT NOT NULL,
    cached_at DATETIME DEFAULT CURRENT_TIMESTAMP
  )';

  $hash_input = json_encode($qs);
  $key_hash = hash('sha256', $hash_input);
  $db = null;

  // ---- Check cache ----
  try {
    $db = new PDO('sqlite:'.$dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec($createTableSQL);

    $stmt = $db->prepare('SELECT body, cached_at FROM search_cache WHERE key_hash = :key_hash LIMIT 1');
    $stmt->bindValue(':key_hash', $key_hash, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      file_put_contents($log_file, date('c') . " - Cache HIT: " . ($qs['q'] ?? 'unknown') . "\n", FILE_APPEND);
      return ['code' => 200, 'body' => $row['body'], 'err' => '', 'url' => $url, 'cached_at' => $row['cached_at'], 'cache_hit' => true];
    }
  } catch (Exception $e) {
    file_put_contents($log_file, date('c') . " - Cache read error: " . $e->getMessage() . "\n", FILE_APPEND);
  }

  // ---- Execute search (cache miss) ----
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => $connectTimeout,
    CURLOPT_TIMEOUT        => $timeout,
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  // ---- Save to cache ----
  if ($code === 200 && $body && $db) {
    try {
      $insert = $db->prepare('INSERT OR REPLACE INTO search_cache 
        (key_hash, q, sources, site, time_range, limit_i, offset_i, sort, body, cached_at) 
        VALUES (:key_hash, :q, :sources, :site, :time_range, :limit_i, :offset_i, :sort, :body, CURRENT_TIMESTAMP)');
      $insert->bindValue(':key_hash', $key_hash, PDO::PARAM_STR);
      $insert->bindValue(':q', $qs['q'] ?? '', PDO::PARAM_STR);
      $insert->bindValue(':sources', $qs['sources'] ?? '', PDO::PARAM_STR);
      $insert->bindValue(':site', $qs['site'] ?? '', PDO::PARAM_STR);
      $insert->bindValue(':time_range', $qs['time_range'] ?? '', PDO::PARAM_STR);
      $insert->bindValue(':limit_i', (int)($qs['limit'] ?? 0), PDO::PARAM_INT);
      $insert->bindValue(':offset_i', (int)($qs['offset'] ?? 0), PDO::PARAM_INT);
      $insert->bindValue(':sort', $qs['sort'] ?? '', PDO::PARAM_STR);
      $insert->bindValue(':body', $body, PDO::PARAM_STR);
      $insert->execute();
      file_put_contents($log_file, date('c') . " - Cache WRITE: " . ($qs['q'] ?? 'unknown') . "\n", FILE_APPEND);
    } catch (Exception $e) {
      file_put_contents($log_file, date('c') . " - Cache write error: " . $e->getMessage() . "\n", FILE_APPEND);
    }
  }

  return ['code' => (int)$code, 'body' => (string)$body, 'err' => (string)$err, 'url' => $url, 'cache_hit' => false];
}



function http_post_json_auth($url,$payload,$headers=[],$ct=2,$to=900){ $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload, JSONF),CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json'],$headers),CURLOPT_CONNECTTIMEOUT=>$ct,CURLOPT_TIMEOUT=>$to]); $b=curl_exec($ch); $c=curl_getinfo($ch,CURLINFO_HTTP_CODE); $e=curl_error($ch); curl_close($ch); return ['code'=>$c,'body'=>$b,'err'=>$e]; }
function is_admin_ip(): bool { $ip = $_SERVER['REMOTE_ADDR'] ?? ''; $env = getenv('ADMIN_IPS') ?: ''; $list = array_filter(array_map('trim', explode(',', $env))); if ($list && in_array($ip, $list, true)) return true; if ($ip==='127.0.0.1'||$ip==='::1') return true; if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/',$ip)) return true; return false; }

// --- config ---
// Source of truth: CodeWalker settings DB (admin AI Config).
$cw = function_exists('codewalker_llm_settings') ? codewalker_llm_settings() : [];
$cwBackend = strtolower((string)($cw['backend'] ?? 'lmstudio'));
$cwBaseUrl = (string)($cw['base_url'] ?? '');
$cwApiKey  = (string)($cw['api_key'] ?? '');
$cwModel   = (string)($cw['model'] ?? '');
$cwTimeout = (int)($cw['model_timeout_seconds'] ?? 0);
if ($cwTimeout < 1) $cwTimeout = 900;

// This endpoint uses an OpenAI-compatible server (LM Studio /v1).
$base = $cwBaseUrl !== '' ? $cwBaseUrl : 'http://127.0.0.1:1234';
$LLM_BASE = rtrim($base, '/');
if (!preg_match('~/v1$~', $LLM_BASE)) $LLM_BASE .= '/v1';

$LLM_KEY  = $cwApiKey !== '' ? $cwApiKey : 'lm-studio';
$SEARCH   = rtrim(getenv('SEARCH_API') ?: 'https://api.iernc.net/v1/search', '/');
$SEARX_URL= rtrim(getenv('SEARX_URL') ?: 'http://192.168.0.191:8080', '/'); // e.g., http://192.168.0.191:8080
// --- input ---
$body = array_merge(json_body(), $_GET);
$q = trim((string)($body['q'] ?? ''));
if ($q === '') respond_error(400, 'missing_query');
//does q actuall look like q=Teijin+Seiki+AR-60
//convert spaces to +
//$q = str_replace(' ', '+', $q); // replace spaces with +


$limit      = max(1, min((int)($body['limit'] ?? 8), 15));
$offset     = max(0, (int)($body['offset'] ?? 0));
$language   = (string)($body['language'] ?? 'en-US');
$time_range = (string)($body['time_range'] ?? '');
$safesearch = isset($body['safesearch']) ? (int)$body['safesearch'] : 1;
$sort       = (string)($body['sort'] ?? 'relevance');
$cache      = isset($body['cache']) ? (is_string($body['cache']) ? strtolower($body['cache']) !== 'false' : (bool)$body['cache']) : true;
$sources    = $body['sources'] ?? 'searx,iernc,rss';
$site       = trim((string)($body['site'] ?? ($body['domain'] ?? '')));
$rss_url    = trim((string)($body['rss_url'] ?? ''));
$engine     = (string)($body['engine'] ?? $body['search_engine'] ?? 'unified'); // 'unified' or 'searx'
$format     = ($body['format'] ?? 'markdown') === 'text' ? 'text' : 'markdown';
$temperature= (float)($body['temperature'] ?? 0.4);
$max_tokens = (int)($body['max_tokens'] ?? 12000);//32768 for gpt-oss-20b / 3
$model      = is_string($body['model_key'] ?? null) ? $body['model_key'] : ($cwModel !== '' ? $cwModel : 'openai/gpt-oss-20b');

// --- 1) search ---
// Helper: simple domain extraction for citations
function domain_of(string $u): string {
  $host = parse_url($u, PHP_URL_HOST);
  if (!$host) return '';
  $parts = explode('.', $host);
  $cnt = count($parts);
  if ($cnt >= 2) return $parts[$cnt-2] . '.' . $parts[$cnt-1];
  return $host;
}

$log_file = '/web/private/logs/answer_search.log';
file_put_contents($log_file, date('c') . " - Engine: {$engine}\n", FILE_APPEND);
file_put_contents($log_file, date('c') . " - Search Q: " . $q . "\n", FILE_APPEND);

if ($engine === 'searx' && $SEARX_URL) {
  // Direct SearXNG call with UI-like flags
  $pageno = (int)floor($offset / max($limit,1)) + 1;
  $searx_qs = [
    'q'          => $q,
    'categories' => 'general',
    'language'   => $language,
    'time_range' => $time_range, // allow '' to pass as empty
    'safesearch' => $safesearch,
    'theme'      => 'simple',
    'format'     => 'json',
    'pageno'     => $pageno,
  ];
  // Note: SearX doesn't support arbitrary 'site' unless embedded in q: use site: filter
  if ($site) $searx_qs['q'] .= ' site:' . $site;

  file_put_contents($log_file, date('c') . " - SearX QS: " . json_encode($searx_qs, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

  $searx_base = rtrim($SEARX_URL, '/') . '/search';
  $sr = http_get_qs($searx_base, $searx_qs, 3, 30, true);
  file_put_contents($log_file, date('c') . " - SearX URL: " . ($sr['url'] ?? '') . "\n", FILE_APPEND);
} else {
  // Unified cached search API (calls SearXNG directly)
  $pageno = (int)floor($offset / max($limit,1)) + 1;
  $qs = [
    'q'          => $q,
    'categories' => 'general',
    'language'   => $language,
    'time_range' => $time_range,
    'safesearch' => $safesearch,
    'theme'      => 'simple',
    'format'     => 'json',
    'pageno'     => $pageno,
  ];
  if ($site) $qs['q'] .= ' site:' . $site;
  file_put_contents($log_file, date('c') . " - Unified QS: " . json_encode($qs, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
  $sr = http_get_qs($SEARX_URL . '/search', $qs, 3, 30, true);
  file_put_contents($log_file, date('c') . " - Unified URL: " . ($sr['url'] ?? '') . "\n", FILE_APPEND);
}

// Common error handling for search
if (($sr['code'] ?? 0) >= 400 || !($sr['body'] ?? '')) respond_error(502, 'search_failed', ['code'=>$sr['code'] ?? 0,'detail'=>$sr['err'] ?: substr((string)$sr['body'],0,500)]);
file_put_contents($log_file, date('c') . " - HTTP " . ($sr['code'] ?? 0) . ", bytes=" . strlen((string)($sr['body'] ?? '')) . "\n", FILE_APPEND);

// Parse results from SearX JSON
$sj = json_decode($sr['body'], true);
$items = [];
if (is_array($sj) && isset($sj['results']) && is_array($sj['results'])) {
  $slice = array_slice($sj['results'], 0, $limit);
  foreach ($slice as $row) {
    $url = (string)($row['url'] ?? '');
    $items[] = [
      'title'        => (string)($row['title'] ?? ''),
      'url'          => $url,
      'snippet'      => (string)($row['content'] ?? ''),
      'domain'       => domain_of($url),
      'source'       => 'searx',
      'published_at' => !empty($row['publishedDate']) ? gmdate('c', strtotime($row['publishedDate'])) : null,
      'score'        => isset($row['score']) ? (int)$row['score'] : null,
    ];
  }
}

file_put_contents($log_file, date('c') . " - Parsed " . count($items) . " items\n", FILE_APPEND);

// Build numbered sources and compact context for the LLM
$max_sources = min($limit, 8);
$sources_list = [];
$lines = [];
$chars=0; $cap=10000; $i=0;
foreach ($items as $it) {
  if ($i >= $max_sources) break;
  $i++;
  $title = (string)($it['title'] ?? '(untitled)');
  $url   = (string)($it['url'] ?? '');
  $domain= (string)($it['domain'] ?? '');
  $pub   = (string)($it['published_at'] ?? '');
  $snip  = trim((string)($it['snippet'] ?? ''));
  $sources_list[] = ['n'=>$i,'title'=>$title,'domain'=>$domain,'url'=>$url,'published_at'=>$pub];
  $row = sprintf("[%d] %s (%s) %s\n%s\n%s", $i, $title, $domain, $pub ? (substr($pub,0,10)) : '', $snip, $url);
  $len = strlen($row); if ($chars + $len > $cap) break; $lines[]=$row; $chars+=$len;
}
$context = implode("\n\n", $lines);

// --- 2) LLM prompt ---
$system = "You are an industrial electronics repair technician writing intake notes.

Your task: Analyze search results for {$q} and produce a 2-5 sentence technical summary for a repair ticket.

PRIORITY RULES:
1. If search results contain specific fault patterns, common failures, or repair procedures for this exact model → summarize those concisely
2. If results only mention the manufacturer/product line without unit-specific details → provide general diagnostics applicable to similar industrial equipment (servo drives, controllers, etc.)
3. NEVER output phrases like 'no information available' or 'cannot determine' — always provide actionable guidance

OUTPUT FORMAT:
- Start with what the unit is (e.g., 'AR-60 is a servo motor controller used in...')
- Include known failure modes if found, OR generic failure points for this equipment class
- End with 2-3 first-check diagnostic steps (power supply verification, I/O checks, visual inspection, error code review)
- Strictly 2-5 sentences
- No URLs, citation markers, or promotional language
- Technical tone, as if briefing another technician

Query context: {$q}";

$user = "Search results:\n{$context}\n\nGenerate repair intake summary now.";

$messages = [
  ['role'=>'system','content'=>$system],
  ['role'=>'user','content'=>$user],
];

// --- 3) LM Studio chat/completions (no tools) ---
$endpoint = rtrim($LLM_BASE, '/') . '/chat/completions';
$payload = [
  'model' => $model,
  'messages' => $messages,
  'temperature' => $temperature,
  'max_tokens'  => $max_tokens,
];

// Admin preview (no upstream call) if ?debug=1 or body.debug and admin IP
$debug = (!empty($_GET['debug']) || !empty($body['debug'])) && is_admin_ip();
if ($debug) {
  $preview = [
    'ok' => true,
    'dry_run' => true,
    'llm_endpoint' => $endpoint,
    'payload' => $payload,
    'citations' => $sources_list,
    'search_meta' => $sj['meta'] ?? null,
  ];
  jexit($preview, 200);
}

$headers = ['Authorization: Bearer ' . $LLM_KEY, 'Content-Type: application/json'];
$lr = http_post_json_auth($endpoint, $payload, $headers, 2, (int)($cwTimeout ?? 900));
if (($lr['code'] ?? 0) >= 400 || !($lr['body'] ?? '')) respond_error(502, 'lmstudio_failed', ['code'=>$lr['code'] ?? 0,'detail'=>$lr['err'] ?: substr((string)$lr['body'],0,1000)]);
$lj = json_decode($lr['body'], true);
$answer = (string)($lj['choices'][0]['message']['content'] ?? '');

// --- 4) Response ---
if ($format === 'text') {
  jexit(['ok'=>true,'query'=>$q,'answer_text'=>$answer,'citations'=>$sources_list,'meta'=>['model'=>$model,'count_sources'=>count($sources_list)]], 200);
}

jexit(['ok'=>true,'query'=>$q,'answer_markdown'=>$answer,'citations'=>$sources_list,'meta'=>['model'=>$model,'count_sources'=>count($sources_list)]], 200);
