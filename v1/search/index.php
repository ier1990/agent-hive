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

// Search endpoint should not require tools scope.
// Keep `false` here: the second argument only means "needs tools scope".
api_guard_once('search', false);

// `api_guard()` does not enforce endpoint-specific scopes on its own.
// For this route, require an authenticated key to include `search` or `tools`.
$scopes = isset($GLOBALS['APP_SCOPES']) && is_array($GLOBALS['APP_SCOPES']) ? $GLOBALS['APP_SCOPES'] : [];
$clientKey = isset($GLOBALS['APP_CLIENT_KEY']) ? (string)$GLOBALS['APP_CLIENT_KEY'] : '';
if ($clientKey !== '' && !in_array('search', $scopes, true) && !in_array('tools', $scopes, true)) {
  http_json(403, ['ok' => false, 'error' => 'forbidden', 'reason' => 'missing_search_scope']);
}

// --- search ---

// ---- env / provider settings ----
$SEARX_VERSION = '0.16.0';
$SEARCH_PROVIDER_SETTINGS = search_load_external_provider_settings();
if (
  trim((string)$SEARCH_PROVIDER_SETTINGS['primary']['url']) === '' &&
  trim((string)$SEARCH_PROVIDER_SETTINGS['secondary']['url']) === ''
) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_misconfigured','message'=>'No external search provider configured'], JSON_UNESCAPED_SLASHES);
  exit;
}
// Search backend (Searx instance)
/* 
grep -n "secret_key" /web/private/searxng/settings.yml
grep -nE '^\s*secret_key\s*:' /web/private/searxng/settings.yml
SEARXNG_SECRET_KEY=...
*/

//Unused for now, but we can use this for authenticated API access to SearXNG if needed in the future.
//$IER_KEY    = getenv('IERNC_SEARCH_APIKEY') ?: '';


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
  provider_type TEXT,
  provider_slot TEXT,
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

function clean_search_query($q) {
  $q = (string)$q;
  // Drop ASCII control characters that can confuse logs, cache keys, or upstream parsing.
  $q = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $q);
  // Normalize all whitespace runs to a single ASCII space.
  $q = preg_replace('/\s+/', ' ', $q);
  $q = trim((string)$q);
  // Keep query size operationally boring for cache keys and upstream requests.
  if (strlen($q) > 500) {
    $q = substr($q, 0, 500);
    $q = rtrim($q);
  }
  return $q;
}

function search_log_line($message) {
  global $log_file;
  $message = trim((string)$message);
  if ($message === '') return;
  error_log('[' . gmdate('c') . '] ' . $message . "\n", 3, $log_file);
}

function search_ensure_history_columns($db) {
  try {
    $cols = [];
    $stmt = $db->query('PRAGMA table_info(search_cache_history)');
    if ($stmt) {
      while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (isset($row['name'])) $cols[(string)$row['name']] = true;
      }
    }
    if (!isset($cols['provider_type'])) {
      $db->exec('ALTER TABLE search_cache_history ADD COLUMN provider_type TEXT');
    }
    if (!isset($cols['provider_slot'])) {
      $db->exec('ALTER TABLE search_cache_history ADD COLUMN provider_slot TEXT');
    }
  } catch (Exception $e) {
    search_log_line('search schema ensure failed: ' . $e->getMessage());
  }
}

function search_settings_db_path() {
  return rtrim((string)PRIVATE_ROOT, '/\\') . '/db/codewalker_settings.db';
}

function search_load_external_provider_settings() {
  $settings = [
    'default_provider' => 'primary',
    'primary' => [
      'type' => 'searxng',
      'url' => trim((string)(getenv('SEARX_URL') ?: '')),
      'api_key' => trim((string)(getenv('IERNC_SEARCH_APIKEY') ?: '')),
    ],
    'secondary' => [
      'type' => '',
      'url' => '',
      'api_key' => '',
    ],
  ];

  $path = search_settings_db_path();
  if (!is_file($path) || !is_readable($path)) {
    return $settings;
  }

  try {
    $db = new SQLite3($path);
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = :k LIMIT 1');
    $stmt->bindValue(':k', 'search.external.providers', SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
    $db->close();
    if (!is_array($row) || !isset($row['value'])) {
      return $settings;
    }
    $decoded = json_decode((string)$row['value'], true);
    if (!is_array($decoded)) {
      return $settings;
    }

    if (isset($decoded['default_provider']) && ($decoded['default_provider'] === 'primary' || $decoded['default_provider'] === 'secondary')) {
      $settings['default_provider'] = $decoded['default_provider'];
    }

    foreach (['primary', 'secondary'] as $slot) {
      if (!isset($decoded[$slot]) || !is_array($decoded[$slot])) continue;
      if (isset($decoded[$slot]['type'])) $settings[$slot]['type'] = trim((string)$decoded[$slot]['type']);
      if (isset($decoded[$slot]['url'])) $settings[$slot]['url'] = trim((string)$decoded[$slot]['url']);
      if (isset($decoded[$slot]['api_key'])) $settings[$slot]['api_key'] = trim((string)$decoded[$slot]['api_key']);
    }
  } catch (Throwable $e) {
    return $settings;
  }

  return $settings;
}

function search_provider_order($settings) {
  $defaultProvider = isset($settings['default_provider']) ? (string)$settings['default_provider'] : 'primary';
  if ($defaultProvider === 'secondary') {
    return ['secondary', 'primary'];
  }
  return ['primary', 'secondary'];
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
$q          = clean_search_query($body['q'] ?? '');
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
  search_ensure_history_columns($db);

  $TTL_DAYS = 365; // 1 year TTL for search cache (can be long since we only write new snapshots and never update old ones)

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
      //error_log('Search cache read Success: '.$q, 3, $log_file);
      $cachedTop = json_decode((string)($row['top_urls'] ?? '[]'), true);
      if (!is_array($cachedTop)) $cachedTop = [];
      $cachedResults = isset($json['results']) && is_array($json['results']) ? $json['results'] : [];
      $cachedProviderMeta = search_cached_meta_from_payload($json);
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
            'provider' => isset($cachedProviderMeta['provider']) ? $cachedProviderMeta['provider'] : 'cache',
            'top_urls' => [],
          ],
          'items' => [],
        ]);
      }

      $cacheMeta = [
        'query' => $q,
        'count' => count($cachedResults),
        'cached_at' => $row['cached_at'],
        'cache_hit' => true,
        'provider' => isset($cachedProviderMeta['provider']) ? $cachedProviderMeta['provider'] : 'cache',
        'top_urls' => $cachedTop,
      ];
      search_log_line('search cache_hit provider=' . (isset($cachedProviderMeta['provider']) ? (string)$cachedProviderMeta['provider'] : 'unknown') . ' slot=' . (isset($cachedProviderMeta['provider_slot']) ? (string)$cachedProviderMeta['provider_slot'] : '') . ' q=' . json_encode($q));
      if (isset($cachedProviderMeta['provider_slot'])) $cacheMeta['provider_slot'] = $cachedProviderMeta['provider_slot'];
      if (isset($cachedProviderMeta['search_information'])) $cacheMeta['search_information'] = $cachedProviderMeta['search_information'];
      if (isset($cachedProviderMeta['search_metadata'])) $cacheMeta['search_metadata'] = $cachedProviderMeta['search_metadata'];
      if (isset($cachedProviderMeta['search_parameters'])) $cacheMeta['search_parameters'] = $cachedProviderMeta['search_parameters'];
      if (isset($cachedProviderMeta['ai_overview'])) $cacheMeta['ai_overview'] = $cachedProviderMeta['ai_overview'];
      if (isset($cachedProviderMeta['related_searches'])) $cacheMeta['related_searches'] = $cachedProviderMeta['related_searches'];
      if (isset($cachedProviderMeta['images'])) $cacheMeta['images'] = $cachedProviderMeta['images'];

      ok($cachedResults, $cacheMeta);
    }
  }
} catch (Exception $e) {
  // ignore cache errors
  // add new line to $log_file for each error with timestamp and error message
  error_log('Search cache error: '.$e->getMessage()."\n", 3, $log_file);
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

function search_provider_request($provider, $qs) {
  $type = isset($provider['type']) ? trim((string)$provider['type']) : '';
  $baseUrl = isset($provider['url']) ? trim((string)$provider['url']) : '';
  $apiKey = isset($provider['api_key']) ? trim((string)$provider['api_key']) : '';
  if ($type === '' || $baseUrl === '') {
    return ['ok' => false, 'error' => 'provider_not_configured'];
  }

  if ($type === 'searchapi_google') {
    $url = rtrim($baseUrl, '/') . '?' . http_build_query([
      'engine' => 'google',
      'q' => (string)$qs['q'],
      'api_key' => $apiKey,
    ]);
    $headers = ['Accept: application/json'];
    $res = http_get_json($url, $headers);
    $json = json_decode($res['body'], true);
    if (!is_array($json)) {
      return [
        'ok' => false,
        'error' => 'invalid_json',
        'http_code' => (int)$res['code'],
        'curl_error' => (string)$res['err'],
        'raw' => (string)$res['body'],
      ];
    }
    $results = [];
    $organicResults = isset($json['organic_results']) && is_array($json['organic_results']) ? $json['organic_results'] : [];
    foreach ($organicResults as $item) {
      if (!is_array($item)) continue;
      $results[] = [
        'title' => isset($item['title']) ? (string)$item['title'] : '',
        'url' => isset($item['link']) ? (string)$item['link'] : '',
        'content' => isset($item['snippet']) ? (string)$item['snippet'] : '',
        'engine' => 'searchapi_google',
      ];
    }
    return [
      'ok' => (int)$res['code'] === 200 && count($results) > 0,
      'provider_type' => 'searchapi_google',
      'provider_url' => $baseUrl,
      'http_code' => (int)$res['code'],
      'curl_error' => (string)$res['err'],
      'result_count' => count($results),
      'raw' => (string)$res['body'],
      'json' => ['results' => $results],
      'upstream_json' => $json,
    ];
  }

  if ($type === 'brave') {
    $url = rtrim($baseUrl, '/') . '?' . http_build_query([
      'q' => (string)$qs['q'],
      'count' => 20,
      'search_lang' => (string)$qs['language'],
      'safesearch' => ((string)$qs['safesearch'] === '0' || (int)$qs['safesearch'] === 0) ? 'off' : 'moderate',
    ]);
    $headers = ['Accept: application/json'];
    if ($apiKey !== '') {
      $headers[] = 'X-Subscription-Token: ' . $apiKey;
    }
    $res = http_get_json($url, $headers);
    $json = json_decode($res['body'], true);
    if (!is_array($json)) {
      return [
        'ok' => false,
        'error' => 'invalid_json',
        'http_code' => (int)$res['code'],
        'curl_error' => (string)$res['err'],
        'raw' => (string)$res['body'],
      ];
    }
    $results = [];
    $webResults = isset($json['web']['results']) && is_array($json['web']['results']) ? $json['web']['results'] : [];
    foreach ($webResults as $item) {
      if (!is_array($item)) continue;
      $results[] = [
        'title' => isset($item['title']) ? (string)$item['title'] : '',
        'url' => isset($item['url']) ? (string)$item['url'] : '',
        'content' => isset($item['description']) ? (string)$item['description'] : '',
        'engine' => 'brave',
      ];
    }
    return [
      'ok' => (int)$res['code'] === 200 && count($results) > 0,
      'provider_type' => 'brave',
      'provider_url' => $baseUrl,
      'http_code' => (int)$res['code'],
      'curl_error' => (string)$res['err'],
      'result_count' => count($results),
      'raw' => (string)$res['body'],
      'json' => ['results' => $results],
      'upstream_json' => $json,
    ];
  }

  $url = build_url(rtrim($baseUrl, '/') . '/search', $qs, false);
  $headers = ['Accept: application/json'];
  if ($apiKey !== '') {
    $headers[] = 'X-API-Key: ' . $apiKey;
  }
  $res = http_get_json($url, $headers);
  $json = json_decode($res['body'], true);
  return [
    'ok' => (int)$res['code'] === 200 && is_array($json) && count(isset($json['results']) && is_array($json['results']) ? $json['results'] : []) > 0,
    'provider_type' => 'searxng',
    'provider_url' => $baseUrl,
    'http_code' => (int)$res['code'],
    'curl_error' => (string)$res['err'],
    'result_count' => count(isset($json['results']) && is_array($json['results']) ? $json['results'] : []),
    'raw' => (string)$res['body'],
    'json' => is_array($json) ? $json : null,
    'upstream_json' => is_array($json) ? $json : null,
  ];
}

function search_cache_payload_from_provider_response($providerResponse, $providerSlotUsed) {
  $results = isset($providerResponse['json']['results']) && is_array($providerResponse['json']['results']) ? $providerResponse['json']['results'] : [];
  $upstream = isset($providerResponse['upstream_json']) && is_array($providerResponse['upstream_json']) ? $providerResponse['upstream_json'] : [];
  return [
    'results' => array_values($results),
    'provider' => isset($providerResponse['provider_type']) ? (string)$providerResponse['provider_type'] : '',
    'provider_slot' => (string)$providerSlotUsed,
    'upstream' => $upstream,
  ];
}

function search_cached_meta_from_payload($payload) {
  $meta = [];
  if (!is_array($payload)) return $meta;
  if (isset($payload['provider'])) $meta['provider'] = (string)$payload['provider'];
  if (isset($payload['provider_slot'])) $meta['provider_slot'] = (string)$payload['provider_slot'];
  if (isset($payload['upstream']) && is_array($payload['upstream'])) {
    $upstream = $payload['upstream'];
    if (isset($upstream['search_information']) && is_array($upstream['search_information'])) {
      $meta['search_information'] = $upstream['search_information'];
    }
    if (isset($upstream['search_metadata']) && is_array($upstream['search_metadata'])) {
      $meta['search_metadata'] = $upstream['search_metadata'];
    }
    if (isset($upstream['search_parameters']) && is_array($upstream['search_parameters'])) {
      $meta['search_parameters'] = $upstream['search_parameters'];
    }
    if (isset($upstream['ai_overview'])) {
      $meta['ai_overview'] = $upstream['ai_overview'];
    }
    if (isset($upstream['related_searches'])) {
      $meta['related_searches'] = $upstream['related_searches'];
    }
    if (isset($upstream['images'])) {
      $meta['images'] = $upstream['images'];
    }
  }
  return $meta;
}
$qs['format'] = 'json'; // Force JSON response
$providerAttempts = [];
$providerResponse = null;
$providerSlotUsed = '';
foreach (search_provider_order($SEARCH_PROVIDER_SETTINGS) as $slot) {
  $provider = isset($SEARCH_PROVIDER_SETTINGS[$slot]) && is_array($SEARCH_PROVIDER_SETTINGS[$slot]) ? $SEARCH_PROVIDER_SETTINGS[$slot] : [];
  $attempt = search_provider_request($provider, $qs);
  $providerAttempts[] = [
    'slot' => $slot,
    'type' => isset($provider['type']) ? (string)$provider['type'] : '',
    'url' => isset($provider['url']) ? (string)$provider['url'] : '',
    'http_code' => isset($attempt['http_code']) ? (int)$attempt['http_code'] : 0,
    'curl_error' => isset($attempt['curl_error']) ? (string)$attempt['curl_error'] : '',
    'error' => isset($attempt['error']) ? (string)$attempt['error'] : '',
  ];
  if (!empty($attempt['ok']) && isset($attempt['json']) && is_array($attempt['json'])) {
    $providerResponse = $attempt;
    $providerSlotUsed = $slot;
    search_log_line('search upstream_ok provider=' . (isset($attempt['provider_type']) ? (string)$attempt['provider_type'] : '') . ' slot=' . $slot . ' http=' . (isset($attempt['http_code']) ? (int)$attempt['http_code'] : 0) . ' results=' . (isset($attempt['result_count']) ? (int)$attempt['result_count'] : 0) . ' q=' . json_encode($q));
    break;
  }
}

if (!is_array($providerResponse)) {
  search_log_line('search upstream_failed q=' . json_encode($q) . ' attempts=' . json_encode($providerAttempts));
  bad('Search backend error', [
    'details' => 'No configured provider returned a usable response.',
    'providers' => $providerAttempts,
  ]);
}

$json = $providerResponse['json'];

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
      'provider' => isset($providerResponse['provider_type']) ? (string)$providerResponse['provider_type'] : '',
      'provider_slot' => $providerSlotUsed,
      'top_urls' => [],
    ],
    'items' => [],
  ]);
}

// ---- save to cache ----
try {
  $cacheBody = json_encode(
    search_cache_payload_from_provider_response($providerResponse, $providerSlotUsed),
    JSON_UNESCAPED_SLASHES
  );
  if (!is_string($cacheBody) || $cacheBody === '') {
    $cacheBody = isset($providerResponse['raw']) ? (string)$providerResponse['raw'] : '';
  }
  $insert = $db->prepare('INSERT INTO search_cache_history
    (key_hash, q, body, top_urls, ai_notes, provider_type, provider_slot, cached_at)
    VALUES (:key_hash, :q, :body, :top_urls, :ai_notes, :provider_type, :provider_slot, CURRENT_TIMESTAMP)');
  $insert->bindValue(':key_hash', $hash_key, PDO::PARAM_STR);
  $insert->bindValue(':q', $q, PDO::PARAM_STR);
  $insert->bindValue(':body', $cacheBody, PDO::PARAM_STR);
  $insert->bindValue(':top_urls', json_encode($top_urls, JSON_UNESCAPED_SLASHES), PDO::PARAM_STR);
  // Placeholder for future: free-form notes about this cached result set.
  $insert->bindValue(':ai_notes', '', PDO::PARAM_STR);
  $insert->bindValue(':provider_type', isset($providerResponse['provider_type']) ? (string)$providerResponse['provider_type'] : '', PDO::PARAM_STR);
  $insert->bindValue(':provider_slot', (string)$providerSlotUsed, PDO::PARAM_STR);
  $insert->execute();
  search_log_line('search cache_write provider=' . (isset($providerResponse['provider_type']) ? (string)$providerResponse['provider_type'] : '') . ' slot=' . $providerSlotUsed . ' q=' . json_encode($q));
} catch (Exception $e) {
  // ignore cache write errors write to $log_file
  error_log('Search cache write error: '.$e->getMessage()."\n", 3, $log_file);

}

// Return the search results in our standard format
$responseMeta = [
  'query'=>$q,
  'count'=>count($json['results'] ?? []),
  'cache_hit'=>false,
  'provider'=>isset($providerResponse['provider_type']) ? (string)$providerResponse['provider_type'] : '',
  'provider_slot'=>$providerSlotUsed,
  'top_urls'=>$top_urls
];
$upstreamMeta = search_cached_meta_from_payload(search_cache_payload_from_provider_response($providerResponse, $providerSlotUsed));
if (isset($upstreamMeta['search_information'])) $responseMeta['search_information'] = $upstreamMeta['search_information'];
if (isset($upstreamMeta['search_metadata'])) $responseMeta['search_metadata'] = $upstreamMeta['search_metadata'];
if (isset($upstreamMeta['search_parameters'])) $responseMeta['search_parameters'] = $upstreamMeta['search_parameters'];
if (isset($upstreamMeta['ai_overview'])) $responseMeta['ai_overview'] = $upstreamMeta['ai_overview'];
if (isset($upstreamMeta['related_searches'])) $responseMeta['related_searches'] = $upstreamMeta['related_searches'];
if (isset($upstreamMeta['images'])) $responseMeta['images'] = $upstreamMeta['images'];

ok($json['results'] ?? [], $responseMeta);
