<?php
// /web/html/v1/lib/bootstrap.php
/*
1) The one invariant: “APP_ROOT = where bootstrap.php lives”

No matter whether Apache docroot is /var/www/html or /web/html or you’re inside iernc.com/public_html, you can always find the app root relative to the current file.

In every entrypoint (/v1/notes/index.php, /v1/search.php, /admin/index.php, etc.):

APP lives in /lib/bootstrap.php dir.
APP_ROOT = __DIR__ . '/../'
APP_LIB  = APP_ROOT . '/lib'
WEB_ROOT = $_SERVER['DOCUMENT_ROOT'] or best guess
PRIVATE_ROOT = /web/private or /var/www/private

/v1/ AI API 
mkdir -p /web/private/{db/memory,logs,locks,cache/http,scripts,prompts,config,ratelimit}

APP_ROOT is not determined by current working directory, nor by document root. 
It is always relative to the location of bootstrap.php.
which is always in ROOT/lib.
We dont want current called dir or document root to affect 
ROOT or APP_ROOT.


And in bootstrap.php you compute everything from __DIR__.

In lib/bootstrap.php (core idea)

Determine:

ROOT /

APP /

APP_URL (URL path to app root)

APP_ROOT (one level above /lib)

APP_LIB (this directory)

WEB_ROOT (document root; or best guess)

PRIVATE_ROOT (either /web/private or /var/www/private or “adjacent private”)

Never assume one layout.

Prefer env var overrides.



Test case:
http://192.168.0.142/v1/notes/?view=human
$json = json_encode($GLOBALS['APP_BOOTSTRAP_CONFIG'], JSON_PRETTY_PRINT);
echo "<pre>$json</pre>";
{
    "APP_URL": "\/v1\/notes",
    "APP_ROOT": "\/web\/html",
    "APP_LIB": "\/web\/html\/lib",
    "APP_SERVICE_NAME": "iernc-api",
    "APP_ENV_FILE": "\/web\/private\/.env",
    "API_KEYS_FILE": "\/web\/private\/api_keys.json",
    "WEB_ROOT": "\/web\/html",
    "PRIVATE_ROOT": "\/web\/private",
    "PRIVATE_SCRIPTS": "\/web\/private\/scripts",
    "BOOTSTRAPPED": true
}

//View env too
$json = json_encode($GLOBALS['APP_BOOTSTRAP_ENV'], JSON_PRETTY_PRINT);
echo "<pre>$json</pre>";



We should be able to include bootstrap.php from any location and have it work.
Services we provide (API endpoints, web apps, admin UIs) can live anywhere under document root.
-- They just include bootstrap.php and get consistent paths.
-- We don't need to change the path in bootstrap.php based on where it's included from.



*/



//ROOT
if (!defined('ROOT')) {
  define('ROOT', dirname(__DIR__));
}
// APP_ROOT (one level above current directory) /lib)
if (!defined('APP_ROOT')) {
  define('APP_ROOT', dirname(__DIR__));
}


// ENTRY_URL: URL base for the current entrypoint (directory of SCRIPT_NAME)
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$entryUrl = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($entryUrl === '') $entryUrl = '/';
if (!defined('ENTRY_URL')) define('ENTRY_URL', $entryUrl);

// APP_URL: URL base for the app root (best effort)
$appUrl = '/';
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($docRoot) {
  $docRoot = rtrim(str_replace('\\','/',$docRoot), '/');
  $appRootN = rtrim(str_replace('\\','/',APP_ROOT), '/');
  if (strpos($appRootN, $docRoot) === 0) {
    $rel = substr($appRootN, strlen($docRoot));
    $rel = '/' . ltrim($rel, '/');
    $appUrl = rtrim($rel, '/');
    if ($appUrl === '') $appUrl = '/';
  }
}
if (!defined('APP_URL')) define('APP_URL', $appUrl);



//APP_LIB_ROOT
if (!defined('APP_LIB')) {
  define('APP_LIB', __DIR__);
}
//WEB_ROOT
if (!defined('WEB_ROOT')) {
  $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
  if ($docRoot && strpos(APP_ROOT, $docRoot) === 0) {
    define('WEB_ROOT', rtrim($docRoot, '/\\'));
  } else {
    define('WEB_ROOT', APP_ROOT);
  } 
}

if (!function_exists('normalize_path_for_compare')) {
  function normalize_path_for_compare($path) {
    $p = (string)$path;
    $p = str_replace('\\', '/', $p);
    $p = rtrim($p, '/');
    return $p === '' ? '/' : $p;
  }
}

if (!function_exists('path_is_within')) {
  function path_is_within($candidate, $parent) {
    $candidateN = normalize_path_for_compare($candidate);
    $parentN = normalize_path_for_compare($parent);
    if ($candidateN === $parentN) return true;
    return strpos($candidateN . '/', $parentN . '/') === 0;
  }
}

// ------------------------------------------------------------
// PRIVATE_ROOT (filesystem-only, writable, never web-served)
// Priority:
//  0) APP_PRIVATE_ROOT env var if set (can be used for first-run paths)
//  1) /web/private if it exists
//  2) /var/www/private if it exists
// Else: hard fail (deployment must be smarter)
// ------------------------------------------------------------
if (!defined('PRIVATE_ROOT')) {
  $envPrivateRoot = getenv('APP_PRIVATE_ROOT');
  if ($envPrivateRoot === false || $envPrivateRoot === '') {
    $envPrivateRoot = $_ENV['APP_PRIVATE_ROOT'] ?? ($_SERVER['APP_PRIVATE_ROOT'] ?? '');
  }

  if (is_string($envPrivateRoot) && trim($envPrivateRoot) !== '') {
    define('PRIVATE_ROOT', rtrim(trim($envPrivateRoot), '/\\'));

  } elseif (is_dir('/web/private')) {
    define('PRIVATE_ROOT', '/web/private');

  } elseif (is_dir('/var/www/private')) {
    define('PRIVATE_ROOT', '/var/www/private');

  } else {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok' => false,
      'error' => 'server_misconfigured',
      'message' => 'APP_CODE_ROOT not set we need SMARTER deployment'
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
  }
}

if (path_is_within(PRIVATE_ROOT, WEB_ROOT)) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok' => false,
    'error' => 'server_misconfigured',
    'message' => 'PRIVATE_ROOT must be outside WEB_ROOT',
    'web_root' => WEB_ROOT,
    'private_root' => PRIVATE_ROOT
  ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}

// Defense-in-depth: if private data is ever exposed by path mapping,
// ensure Apache denies direct access.
$privateHtaccess = rtrim(PRIVATE_ROOT, '/\\') . '/.htaccess';
if (!file_exists($privateHtaccess)) {
  $denyRules = "<IfModule mod_authz_core.c>\n"
    . "  Require all denied\n"
    . "</IfModule>\n"
    . "<IfModule !mod_authz_core.c>\n"
    . "  Deny from all\n"
    . "</IfModule>\n";
  @file_put_contents($privateHtaccess, $denyRules, LOCK_EX);
}

// PRIVATE_SCRIPTS
if (!defined('PRIVATE_SCRIPTS')) {
  define('PRIVATE_SCRIPTS', PRIVATE_ROOT . '/scripts');
}

// ---- Rate limiting ----
//require_once __DIR__ . '/ratelimit.php';
// Testing: adjust path to where lib/ is located
require_once APP_LIB . '/ratelimit.php';


// ---- Basic identity ----
if (!defined('APP_SERVICE_NAME')) {
  define('APP_SERVICE_NAME', 'iernc-api');
}
if (!defined('APP_ENV_FILE')) {
  define('APP_ENV_FILE', PRIVATE_ROOT . '/.env');
}
if (!defined('API_KEYS_FILE')) {
  define('API_KEYS_FILE', PRIVATE_ROOT . '/api_keys.json');
}

// Auto-create default .env if missing (first-run setup)
if (!file_exists(APP_ENV_FILE)) {
    // Ensure PRIVATE_ROOT directory structure exists
    $privateRoot = dirname(APP_ENV_FILE);
    if (!is_dir($privateRoot)) {
        @mkdir($privateRoot, 0755, true);
    }
    
    $defaults = "# Security Mode: lan (trust RFC1918 IPs) or public (require keys for all)\nSECURITY_MODE=lan\n\n# IPs that can access without API key (comma-separated CIDR)\n# Only used in lan mode\nALLOW_IPS_WITHOUT_KEY=127.0.0.1/32,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16\n\n# Require API key even for allowed IPs (0=no, 1=yes)\nREQUIRE_KEY_FOR_ALL=0\n\n# LLM Backend (set via admin UI or manually)\nLLM_BASE_URL=http://127.0.0.1:1234\nLLM_API_KEY=\nAPP_API_KEY=\n\n# Service Identity\nAPP_VERSION=1.0.0\nAPP_SERVICE_NAME=iernc-api\n";
    @file_put_contents(APP_ENV_FILE, $defaults);
    @chmod(APP_ENV_FILE, 0640);
}



// save current config state for later use
$GLOBALS['APP_BOOTSTRAP_CONFIG'] = [
  'ROOT'        => ROOT,
  'APP_URL'      => APP_URL,
  'ENTRY_URL'    => ENTRY_URL,
  'APP_ROOT'     => APP_ROOT,
  'APP_LIB'      => APP_LIB,
  'APP_SERVICE_NAME' => APP_SERVICE_NAME,
  'APP_ENV_FILE' => APP_ENV_FILE,
  'API_KEYS_FILE' => API_KEYS_FILE,
  'WEB_ROOT'     => WEB_ROOT,
  'PRIVATE_ROOT' => PRIVATE_ROOT  ,
  'PRIVATE_SCRIPTS' => PRIVATE_SCRIPTS,  

];

  // Write a stable, non-secret paths file for non-PHP tooling (cron/python).
  // This is intentionally *paths-only* (no env secrets).
  // Location: ${PRIVATE_ROOT}/bootstrap_paths.json
  if (defined('PRIVATE_ROOT') && is_string(PRIVATE_ROOT) && PRIVATE_ROOT !== '') {
    $pathsFile = rtrim(PRIVATE_ROOT, '/\\') . '/bootstrap_paths.json';
    $paths = [
      'APP_ROOT' => APP_ROOT,
      'APP_LIB' => APP_LIB,
      'PRIVATE_ROOT' => PRIVATE_ROOT,
      'PRIVATE_SCRIPTS' => PRIVATE_SCRIPTS,
    ];
    $json = json_encode($paths, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
    if ($json !== false) {
      $prev = @file_get_contents($pathsFile);
      if ($prev === false || trim($prev) !== trim($json)) {
        @file_put_contents($pathsFile, $json . "\n", LOCK_EX);
      }
    }
  }

//Hardcode overrides for local testing
/*
$GLOBALS['APP_BOOTSTRAP_CONFIG']['PRIVATE_ROOT'] = '/web/private';
$GLOBALS['APP_BOOTSTRAP_CONFIG']['PRIVATE_SCRIPTS'] = '/web/private/scripts';
$GLOBALS['APP_BOOTSTRAP_CONFIG']['APP_LIB'] = '/web/html/lib';
$GLOBALS['APP_BOOTSTRAP_CONFIG']['WEB_ROOT'] = '/web/html'; 

$GLOBALS['APP_BOOTSTRAP_CONFIG']['LLM_BASE_URL'] = 'http://localhost:8000';
$GLOBALS['APP_BOOTSTRAP_CONFIG']['LLM_API_KEY']  = 'testkey123';
$GLOBALS['APP_BOOTSTRAP_CONFIG']['APP_API_KEY']  = 'iernc-test-key-456';
$GLOBALS['APP_BOOTSTRAP_CONFIG']['APP_VERSION']  = 'dev';
$GLOBALS['APP_BOOTSTRAP_CONFIG']['APP_SERVICE_NAME'] = 'iernc-api';
$GLOBALS['APP_BOOTSTRAP_CONFIG']['APP_ENV_FILE'] = '/web/private/.env';
$GLOBALS['APP_BOOTSTRAP_CONFIG']['API_KEYS_FILE'] = '/web/private/api_keys.json';
*/

// ---- Bootstrap complete ----

$GLOBALS['APP_BOOTSTRAP_CONFIG']['BOOTSTRAPPED'] = true;
define('APP_BOOTSTRAPPED', true);

// Only trust proxies you explicitly list. Empty = trust none.
$TRUSTED_PROXIES = [];

// Security config is derived from env after env-loader runs.
// (Defaults are set below.)
$ALLOW_IPS_WITHOUT_KEY = [];
$requireKeyForAll = true;

// ---- Env loader (idempotent) ----
//env should be loaded into $GLOBALS['APP_BOOTSTRAP_ENV'] = true;
if (!defined('APP_ENV_LOADED')) {
  define('APP_ENV_LOADED', true);

  if (!function_exists('env')) {
    function env(string $k, $d=null) {
      $v = $_ENV[$k] ?? $_SERVER[$k] ?? getenv($k);
      return ($v === false || $v === null || $v === '') ? $d : $v;
    }
  }

  if (!function_exists('load_env_file')) {
    function load_env_file(string $file, bool $override=false): void {
      if (!is_readable($file)) return;
      $fh = fopen($file, 'rb'); if (!$fh) return;

      while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;

        if (stripos($line, 'export ') === 0) $line = trim(substr($line, 7));
        if (strpos($line, '=') === false) continue;

        list($key, $val) = explode('=', $line, 2);
        $key = trim($key);
        $val = ltrim(rtrim($val));

        $quoted = false;
        if ((strlen($val) >= 2) && (
          ($val[0] === '"' && substr($val, -1) === '"') ||
          ($val[0] === "'" && substr($val, -1) === "'")
        )) {
          $quoted = $val[0];
          $val = substr($val, 1, -1);
        } else {
          $hashPos = strpos($val, ' #');
          if ($hashPos !== false) $val = substr($val, 0, $hashPos);
          $val = rtrim($val);
        }

        if ($quoted === '"') {
          $val = strtr($val, ["\\n"=>"\n","\\r"=>"\r","\\t"=>"\t","\\\\"=>"\\", "\\\"" => "\""]);
        }

        $val = preg_replace_callback('/\$\{([A-Za-z0-9_]+)\}/', function($m){
          return env($m[1], '');
        }, $val);

        if (!$override && getenv($key) !== false) continue;
        putenv("$key=$val");
        $_ENV[$key]    = $val;
        $_SERVER[$key] = $val;
        // Also save to global bootstrap env array
        if (isset($_ENV[$key])) $GLOBALS['APP_BOOTSTRAP_ENV'][$key] = $val;       
      }// end while
      fclose($fh);
    }
  }

  // Try vlucas/phpdotenv if present; fallback to our loader
  if (class_exists(Dotenv\Dotenv::class)) {
    $dotenv = Dotenv\Dotenv::createImmutable(PRIVATE_ROOT, ['.env'], true);
    $dotenv->safeLoad();
  } else {
    load_env_file(APP_ENV_FILE, false);
  }
}

// ---- Security knobs (no per-server edits) ----
$SECURITY_MODE = env('SECURITY_MODE', 'lan'); // lan|public
$ALLOW_IPS_WITHOUT_KEY = array_filter(array_map('trim', explode(',', (string)env('ALLOW_IPS_WITHOUT_KEY', ''))));

if (!$ALLOW_IPS_WITHOUT_KEY && $SECURITY_MODE !== 'public') {
  // default RFC1918 + loopback in lan mode
  $ALLOW_IPS_WITHOUT_KEY = [
    '127.0.0.1/32',
    '10.0.0.0/8',
    '172.16.0.0/12',
    '192.168.0.0/16',
  ];
}

$requireKeyForAll = ($SECURITY_MODE === 'public') ? true : (bool)env('REQUIRE_KEY_FOR_ALL', '0');


// ---- Common headers (set once) ----
if (!headers_sent()) {
  header('X-IER-Service: ' . APP_SERVICE_NAME);
  header('X-IER-Host: ' . gethostname());
  header('X-IER-Version: ' . (string)env('APP_VERSION', 'dev'));
}

// ---- PHP 7.3 compatibility polyfills ----
// PHP 8 introduced these helpers; define them for older runtimes.
if (!function_exists('str_contains')) {
  function str_contains(string $haystack, string $needle): bool {
    if ($needle == '') return true;
    return strpos($haystack, $needle) !== false;
  }
}

if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool {
    if ($needle == '') return true;
    return strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}

if (!function_exists('str_ends_with')) {
  function str_ends_with(string $haystack, string $needle): bool {
    if ($needle == '') return true;
    $len = strlen($needle);
    if ($len === 0) return true;
    return substr($haystack, -$len) === $needle;
  }
}

// ===== Helpers =====

// ---- CodeWalker JSON config (optional) ----
// Source of truth for LLM routing knobs when present:
//   /web/private/codewalker.json
// Expected schema (from admin CodeWalker backup):
//   { schema, created_at, settings: { backend, base_url, api_key, model, model_timeout_seconds, ... }, prompt_templates: [...] }

if (!function_exists('codewalker_json_path')) {
  function codewalker_json_path(): string {
    if (defined('PRIVATE_ROOT')) {
      return rtrim((string)PRIVATE_ROOT, '/\\') . '/codewalker.json';
    }
    return '/web/private/codewalker.json';
  }
}

if (!function_exists('codewalker_json_read')) {
  function codewalker_json_read(?string $path = null): ?array {
    $path = $path ?: codewalker_json_path();
    if (!is_string($path) || $path === '' || !is_readable($path)) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return null;
    $j = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($j)) return null;
    return $j;
  }
}

if (!function_exists('codewalker_llm_settings')) {
  function codewalker_llm_settings(?string $path = null): array {
    // Source of truth is the CodeWalker settings SQLite DB managed by admin Settings.
    // codewalker.json is treated as a backup/export artifact, not runtime config.

    $dbPath = (defined('PRIVATE_ROOT') ? (rtrim((string)PRIVATE_ROOT, '/\\') . '/db/codewalker_settings.db') : '/web/private/db/codewalker_settings.db');
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }

    try {
      $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);

      $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)');

      // Ensure minimal defaults exist so v1 endpoints work on first run.
      // These can be changed later via admin Settings.
      $defaults = [
        'backend' => 'lmstudio',
        'base_url' => 'http://127.0.0.1:1234',
        'api_key' => null,
        'model' => 'openai/gpt-oss-20b',
        'model_timeout_seconds' => 900,
      ];
      $ins = $pdo->prepare('INSERT OR IGNORE INTO settings(key, value) VALUES(?, ?)');
      foreach ($defaults as $k => $v) {
        $ins->execute([(string)$k, json_encode($v)]);
      }

      $want = ['backend', 'base_url', 'api_key', 'model', 'model_timeout_seconds'];
      $placeholders = implode(',', array_fill(0, count($want), '?'));
      $stmt = $pdo->prepare('SELECT key, value FROM settings WHERE key IN (' . $placeholders . ')');
      $stmt->execute($want);
      $rows = $stmt->fetchAll();

      $out = [];
      foreach ($rows as $r) {
        $k = (string)($r['key'] ?? '');
        if ($k === '') continue;
        $raw = (string)($r['value'] ?? '');
        $val = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
          $out[$k] = $val;
        } else {
          $out[$k] = $raw;
        }
      }
      return $out;
    } catch (Throwable $e) {
      // Fail closed to empty; callers should handle missing config.
      return [];
    }
  }
}

// Shared AI settings helpers (admin pages + KB tools)
require_once __DIR__ . '/ai_bootstrap.php';

function get_client_ip_trusted(): string {
  global $TRUSTED_PROXIES;
  return rl_client_ip($TRUSTED_PROXIES);
}

function http_json(int $code, array $data): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  exit;
}

function get_client_key(): string {
  $k = $_SERVER['HTTP_X_API_KEY'] ?? '';
  if (!$k) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($auth, 'Bearer ') === 0) $k = trim(substr($auth, 7));
  }
  return trim($k);
}

function load_scopes(string $key): array {
  if (!$key) return [];
  if (!is_readable(API_KEYS_FILE)) return [];
  $map = json_decode(file_get_contents(API_KEYS_FILE), true) ?: [];
  $entry = $map[$key] ?? null;

  if (is_array($entry) && isset($entry['active']) && !$entry['active']) return [];

  if (is_array($entry) && isset($entry['scopes']) && is_array($entry['scopes'])) return $entry['scopes'];
  return is_array($entry) ? $entry : [];
}

// IPv4 CIDR check (good enough for your LAN use); accepts /32 and plain IP too
function ip_in_list(string $ip, array $list): bool {
  foreach ($list as $cidr) {
    if (strpos($cidr, '/') === false) {
      if ($ip === $cidr) return true;
      continue;
    }
    [$net, $mask] = explode('/', $cidr, 2);
    $mask = (int)$mask;
    if ($mask < 0 || $mask > 32) continue;

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
    if (!filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;

    $ipn  = ip2long($ip);
    $netn = ip2long($net);
    $maskn = $mask === 0 ? 0 : (-1 << (32 - $mask));
    if (($ipn & $maskn) === ($netn & $maskn)) return true;
  }
  return false;
}

// Guard entrypoint
function api_guard(string $endpoint, bool $needsTools=false): void {
  global $ALLOW_IPS_WITHOUT_KEY, $requireKeyForAll;

  $client_ip  = get_client_ip_trusted();
  $client_key = get_client_key();
  $scopes     = load_scopes($client_key);

  // Auth
  if ($requireKeyForAll) {
    if (ip_in_list($client_ip, $ALLOW_IPS_WITHOUT_KEY)) {
      $scopes = $scopes ?: ['chat','tools','health'];
    }
    if (empty($scopes)) http_json(401, ['ok'=>false,'error'=>'unauthorized','endpoint'=>$endpoint,'client_ip'=>$client_ip]);
  } else {
    if ($needsTools && empty($scopes)) http_json(403, ['ok'=>false,'error'=>'forbidden','reason'=>'missing_scopes']);
  }

  if ($needsTools && !in_array('tools', $scopes, true)) {
    http_json(403, ['ok'=>false,'error'=>'forbidden','reason'=>'missing_tools_scope']);
  }

  // Rate limits (IP and key)
  [$ok_ip, $rem_ip, $reset_ip] = rl_check("ip:$client_ip", 60, 60);
  rl_headers(60, $rem_ip, $reset_ip);
  if (!$ok_ip) http_json(429, ['ok'=>false,'error'=>'rate_limited','by'=>'ip','retry_after'=>max(0, $reset_ip - time())]);

  if ($client_key) {
    [$ok_k, $rem_k, $reset_k] = rl_check("key:$client_key", 120, 60);
    // Optional: emit a second set of headers with key prefix to avoid overwriting
    header('X-RateLimit-Key-Limit: 120');
    header('X-RateLimit-Key-Remaining: ' . $rem_k);
    header('X-RateLimit-Key-Reset: ' . $reset_k);

    if (!$ok_k) http_json(429, ['ok'=>false,'error'=>'rate_limited','by'=>'key','retry_after'=>max(0, $reset_k - time())]);
  }


  // You can optionally attach these to globals for logging
  $GLOBALS['APP_CLIENT_IP']  = $client_ip;
  $GLOBALS['APP_CLIENT_KEY'] = $client_key;
  $GLOBALS['APP_SCOPES']     = $scopes;
}


if (!function_exists('api_guard_once')) {
  function api_guard_once(string $endpoint, bool $needsTools=false): void {
    api_guard($endpoint, $needsTools);
  }
}


// Require LLM env keys only when needed
function require_llm_env(): void {
  $missing = [];
  foreach (['LLM_BASE_URL','LLM_API_KEY','APP_API_KEY'] as $k) {
    if (!env($k)) $missing[] = $k;
  }
  if ($missing) {
    http_json(500, ['ok'=>false,'error'=>'missing_env','keys'=>$missing]);
  }
}

// Convenience for upstream proxying endpoints
function http_out_or_502(array $result): void {
  $code = $result['code'] ?? 0;
  $body = $result['body'] ?? '';
  $err  = $result['err'] ?? null;
  if ($err || $code >= 400 || $code === 0) {
    http_json(502, ['ok'=>false,'error'=>'upstream_failed','upstream_code'=>$code,'detail'=>$err,'resp'=>$body]);
  }
  header('Content-Type: application/json; charset=utf-8');
  echo $body;
  exit;
}
