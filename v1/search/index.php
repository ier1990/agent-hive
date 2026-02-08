<?php
// --- headers / CORS ---
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json; charset=utf-8');
header('X-Search-Handler: search.php');
header('X-Search-Build: 2025-11-07T14:00Z');


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// --- bootstrap ---
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/schema_builder.php';
require_once dirname(__DIR__, 2) . '/lib/registry_logger.php';

api_guard_once('search', true);

// --- search ---

// ---- env ----
// Search backend (Searx instance)
$SEARX_VERSION = '0.16.0';
$SEARX_URL  = getenv('SEARX_URL') ?: '';
if (!$SEARX_URL) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_misconfigured','message'=>'SEARX_URL not set'], JSON_UNESCAPED_SLASHES);
  exit;
}
// Search backend (Searx instance)
/* 
grep -n "secret_key" /web/private/searxng/settings.yml
grep -nE '^\s*secret_key\s*:' /web/private/searxng/settings.yml
SEARXNG_SECRET_KEY=...
*/
$IER_KEY    = getenv('IERNC_SEARCH_APIKEY') ?: '';


// ---- database ----
//$dbPath    = getenv('IERNC_SEARCH_DB_PATH') ?: '';
$dbPath    = '/web/private/db/memory/search_cache.db';
$log_file = '/web/private/logs/search_errors.log';


// ---- ensure table exists ----
// Cache snapshots (write a new snapshot only when stale; never update old ones)
$query='CREATE TABLE IF NOT EXISTS search_cache_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  key_hash CHAR(64) NOT NULL,
  q TEXT,
  body MEDIUMTEXT NOT NULL,
  top_urls TEXT,
  ai_notes TEXT,
  cached_at DATETIME DEFAULT CURRENT_TIMESTAMP
);';

$query_idx='CREATE INDEX IF NOT EXISTS idx_search_cache_history_key_time
  ON search_cache_history(key_hash, cached_at);';


// ---- helpers ----
function respond($code, $payload) { http_response_code($code); echo json_encode($payload, JSON_UNESCAPED_SLASHES); exit; }
function ok($items, $meta=[])      { respond(200, ['ok'=>true,'meta'=>$meta,'items'=>array_values($items)]); }
function bad($msg, $extra=[])      { respond(400, ['ok'=>false,'error'=>$msg]+$extra); }

function json_body() {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

function http_get_json($url, $headers = [], $ct=3, $t=15) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=> $headers ?: ['Accept: application/json'],
    CURLOPT_CONNECTTIMEOUT=>$ct,
    CURLOPT_TIMEOUT=>$t,
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  return ['code'=>(int)$code,'body'=>(string)$body,'err'=>(string)$err];
}

function http_get_text($url, $headers=[], $ct=3, $t=15) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=> $headers,
    CURLOPT_CONNECTTIMEOUT=>$ct,
    CURLOPT_TIMEOUT=>$t,
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  return ['code'=>(int)$code,'body'=>(string)$body,'err'=>(string)$err];
}

function domain_of($u) {
  $h = parse_url($u, PHP_URL_HOST);
  if (!$h) return '';
  $parts = explode('.', $h);
  return implode('.', array_slice($parts, -2));
}

// ---- normalize input (GET or POST JSON) ----
$body = ($_SERVER['REQUEST_METHOD'] === 'POST') ? json_body() : $_GET;

// Working URL
//http://192.168.0.191:8080/search?
// q=Teijin+Seiki+AR-60
// &category_general=1
// &language=en-US
// &time_range=
// &safesearch=0
// &theme=simple
$q          = trim($body['q'] ?? '');
$category_general = 1;
$language   = 'en-US';
$time_range = '';
$safesearch = 0;
$theme      = 'simple';

$meta = [
  'q'=>$q, 'category_general'=>$category_general, 'language'=>$language,
  'time_range'=>$time_range, 'safesearch'=>$safesearch, 'theme'=>$theme,
];

if (!$q) {
  bad('Missing search query "q" parameter.');
}

/*
Table: search_cache

Select data|Show structure|Alter table|New item
Column	Type
key_hash	char(64) NULL
q	text NULL
sources	text NULL
site	text NULL
time_range	text NULL
limit_i	int NULL
offset_i	int NULL
sort	text NULL
body	mediumtext
cached_at	datetime NULL [CURRENT_TIMESTAMP]
Indexes
PRIMARY	key_hash
*/

// ---- check cache ----
$hash_input = json_encode([
  'q'=>$q, 'category_general'=>$category_general, 'language'=>$language,
  'time_range'=>$time_range, 'safesearch'=>$safesearch, 'theme'=>$theme,
]);

$hash_key = hash('sha256', $hash_input);
try {
  $db = new PDO('sqlite:'.$dbPath);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec($query);
  $db->exec($query_idx);

  $TTL_DAYS = 7;

  $stmt = $db->prepare('
    SELECT body, cached_at, top_urls, ai_notes
    FROM search_cache_history
    WHERE key_hash = :key_hash
      AND cached_at >= datetime("now", :ttl)
    ORDER BY cached_at DESC
    LIMIT 1
  ');
  $stmt->bindValue(':key_hash', $hash_key, PDO::PARAM_STR);
  $stmt->bindValue(':ttl', '-' . (int)$TTL_DAYS . ' days', PDO::PARAM_STR);
  $stmt->execute();
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    $json = json_decode($row['body'], true);
    if (is_array($json)) {
      error_log('Search cache read Success: '.$q, 3, $log_file);
      $cachedTop = json_decode((string)($row['top_urls'] ?? '[]'), true);
      if (!is_array($cachedTop)) $cachedTop = [];
      if (count($cachedTop) === 0) {
        respond(200, [
          'ok' => false,
          'error' => 'no_results',
          'message' => 'No relevant results were found for the query.',
          'meta' => [
            'query' => $q,
            'count' => 0,
            'cached_at' => $row['cached_at'],
            'cache_hit' => true,
            'top_urls' => [],
          ],
          'items' => [],
        ]);
      }

      ok($json['results'] ?? [], ['query'=>$q, 'count'=>count($json['results'] ?? []), 'cached_at'=>$row['cached_at'], 'cache_hit'=>true, 'top_urls'=>$cachedTop]);
    }
  }
} catch (Exception $e) {
  // ignore cache errors
  error_log('Search cache read error: '.$e->getMessage(), 3, $log_file);
}











// ---- Do query ----
$ch = curl_init();
$qs = [
  'q'                => $q,
  'category_general' => $category_general,
  'language'        => $language,
  'time_range'      => $time_range,
  'safesearch'      => $safesearch,
  'theme'           => $theme,
];

function build_url($baseUrl, $qs, $spaces_as_plus = false) {
  // RFC1738 => space encoded as '+'
  $url = $baseUrl;
  $first = true;
  foreach ($qs as $k => $v) {
    if ($v === null || $v === '') continue;
    if ($first) {
      $url .= '?';
      $first = false;
    } else {
      $url .= '&';
    }
    if ($spaces_as_plus) {
      $url .= urlencode($k) . '=' . str_replace('%20', '+', urlencode($v));
    } else {
      $url .= urlencode($k) . '=' . urlencode($v);
    }
  }
  return $url;
}
$qs['format'] = 'json'; // Force JSON response
$headers = ['Accept: application/json'];
$url = build_url($SEARX_URL . '/search', $qs, false);
$res = http_get_json($url,$headers);
if ($res['code'] !== 200) {
    bad('Search backend error', 
    [
      'details'=>'Backend returned HTTP '.$res['code'].': '.$res['err']
    ]

  );
}

$json = json_decode($res['body'], true);
if (!is_array($json)) {
  bad('Invalid JSON response from search backend', ['raw'=>substr($res['body'], 0, 500)]);
}

// ---- Extract useful signals (top URLs) ----
$TOP_URLS_LIMIT = 10;
$results = is_array($json['results'] ?? null) ? $json['results'] : [];

$top_urls = [];

$pos = 0;
foreach ($results as $r) {
  if (!is_array($r)) continue;
  $u = (string)($r['url'] ?? ($r['link'] ?? ($r['href'] ?? '')));
  if ($u === '') continue;

  $pos++;
  if (count($top_urls) < $TOP_URLS_LIMIT) {
    $top_urls[] = $u;
  }
}

// If we got no usable URLs, treat this as a no-results error (not a successful search).
// This prevents caching and downstream pipelines from treating empty/failed SearXNG responses as OK.
if (count($top_urls) === 0) {
  respond(200, [
    'ok' => false,
    'error' => 'no_results',
    'message' => 'No relevant results were found for the query.',
    'meta' => [
      'query' => $q,
      'count' => 0,
      'cache_hit' => false,
      'top_urls' => [],
    ],
    'items' => [],
  ]);
}

// ---- save to cache ----
try {
  $insert = $db->prepare('INSERT INTO search_cache_history
    (key_hash, q, body, top_urls, ai_notes, cached_at)
    VALUES (:key_hash, :q, :body, :top_urls, :ai_notes, CURRENT_TIMESTAMP)');
  $insert->bindValue(':key_hash', $hash_key, PDO::PARAM_STR);
  $insert->bindValue(':q', $q, PDO::PARAM_STR);
  $insert->bindValue(':body', $res['body'], PDO::PARAM_STR);
  $insert->bindValue(':top_urls', json_encode($top_urls, JSON_UNESCAPED_SLASHES), PDO::PARAM_STR);
  // Placeholder for future: free-form notes about this cached result set.
  $insert->bindValue(':ai_notes', '', PDO::PARAM_STR);
  $insert->execute();
  error_log('Search cache write Success: '.$q, 3, $log_file);
} catch (Exception $e) {
  // ignore cache write errors write to $log_file
  error_log('Search cache write error: '.$e->getMessage(), 3, $log_file);
}

// Return the search results in our standard format
ok($json['results'] ?? [], ['query'=>$q, 'count'=>count($json['results'] ?? []), 'cache_hit'=>false, 'top_urls'=>$top_urls]);