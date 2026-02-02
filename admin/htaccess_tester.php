<?php

/*

    htaccess_tester.php

    A simple admin tool to test and visualize .htaccess RewriteRules.   

    current .htaccess file:
    AuthType Basic
AuthName "IER Admin"
AuthUserFile /web/private/.passwords/.htpassword

# Require a valid user from the htpasswd file
Require valid-user

# Optional hardening (LAN only). Uncomment if you want.
# Require ip 192.168.0.0/24

Test and if why its not working as expected.

*/

ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

require_once __DIR__ . '/../v1/lib/http.php';

// --- auth probe mode ---
// Used by this tester to make a loopback request to a protected resource
// without triggering recursive self-tests.
if (isset($_GET['__auth_probe']) && $_GET['__auth_probe'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "ok\n";
    exit;
}

// --- logout mode ---
// Note: HTTP Basic auth is cached by the browser; there is no perfect server-side "logout".
// This forces a 401 with a new realm, which typically makes the browser drop/re-prompt creds.
if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="IER Admin (logged out)"');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Logged out (browser should re-prompt on next request).\n";
    exit;
}

$htaccessPath = __DIR__ . '/.htaccess';
function html(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function detect_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // If this script is /admin/htaccess_tester.php, dirname is /admin
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin'), '/');
    if ($base === '') $base = '/';

    return $scheme . '://' . $host . $base;
}
function parse_htaccess(string $path): array {
    $out = [
        'rewrite_base' => null,
        'rules' => [],
        'errors' => [],
    ];  
    if (!is_readable($path)) {
        $out['errors'][] = "Cannot read: $path";
        return $out;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        $out['errors'][] = "Failed to read: $path";
        return $out;
    }
    foreach ($lines as $i => $rawLine) {
        $line = trim($rawLine);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (preg_match('#^RewriteBase\s+(\S+)#i', $line, $m)) {
            $out['rewrite_base'] = $m[1];
            continue;
        }
        if (preg_match('#^RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[([^\]]+)\])?#i', $line, $m)) {
            $pattern = $m[1];
            $target = $m[2];
            $flags = isset($m[3]) ? $m[3] : '';
            $out['rules'][] = [
                'line' => $i + 1,
                'raw' => $rawLine,
                'pattern' => $pattern,
                'target' => $target,
                'flags' => $flags,
            ];
        }
    }
    return $out;
}
function getRewriteRules($htaccessFile) {
    $parsed = parse_htaccess($htaccessFile);
    return $parsed;
}

function http_request_raw(string $url, array $headers = [], int $timeoutSec = 5, bool $followRedirects = false): array {
    // Returns: [code:int, headers:array<string,string>, body:string, err:?string]
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['code' => 0, 'headers' => [], 'body' => '', 'err' => 'curl_init failed'];
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSec);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        // Avoid keeping connections around in busy admin usage
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['code' => 0, 'headers' => [], 'body' => '', 'err' => $err ?: 'curl_exec failed'];
        }

        $hdrSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $hdrRaw = substr($resp, 0, $hdrSize);
        $body = substr($resp, $hdrSize);

        $hdrs = [];
        foreach (preg_split("/\r\n|\n|\r/", (string)$hdrRaw) as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'HTTP/') === 0) continue;
            $pos = strpos($line, ':');
            if ($pos === false) continue;
            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            if ($k !== '') $hdrs[$k] = $v;
        }

        return ['code' => $code, 'headers' => $hdrs, 'body' => (string)$body, 'err' => null];
    }

    // Fallback without cURL: use stream context (headers limited).
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $timeoutSec,
            'ignore_errors' => true,
        ]
    ]);
    $body = @file_get_contents($url, false, $context);
    $code = 0;
    $hdrs = [];
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $code = (int)$m[1];
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $k = trim(substr($line, 0, $pos));
                $v = trim(substr($line, $pos + 1));
                if ($k !== '') $hdrs[$k] = $v;
            }
        }
    }
    return ['code' => $code, 'headers' => $hdrs, 'body' => (string)($body ?: ''), 'err' => null];
}
// Fetch available models from local Ollama
$baseUrl = detect_base_url();
$base = 'http://localhost:11434';
$modelsResp = http_get_json($base . '/api/models', [], 1, 3);
if (($modelsResp['code'] ?? 0) >= 400 || empty($modelsResp['body'])) {
    $models = [];
} else {
    $modelsData = json_decode($modelsResp['body'] ?? '[]', true);
    $models = $modelsData['data'] ?? [];
}
$rulesData = getRewriteRules($htaccessPath);

// --- Current auth status ---
$authUserNow = $_SERVER['PHP_AUTH_USER'] ?? ($_SERVER['REMOTE_USER'] ?? '');
$authAuthed = ($authUserNow !== '');

// --- Real AuthType Basic test ---
$authResults = null;
$authError = null;
$authTarget = $_POST['auth_target'] ?? '/admin/htaccess_tester.php?__auth_probe=1';
$authUser = $_POST['auth_user'] ?? '';
$authPass = $_POST['auth_pass'] ?? '';
$authFollow = isset($_POST['auth_follow']) && $_POST['auth_follow'] === '1';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['do_auth_test']) && $_POST['do_auth_test'] === '1') {
    $target = trim((string)$authTarget);
    if ($target === '') {
        $authError = 'Target URL/path is required.';
    } else {
        // Allow either absolute URL or path.
        if (preg_match('#^https?://#i', $target)) {
            $url = $target;
        } else {
            $root = (function () {
                $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                $scheme = $https ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                return $scheme . '://' . $host;
            })();
            $url = $root . '/' . ltrim($target, '/');
        }

        $baseHeaders = [
            'User-Agent: htaccess_tester/1.0',
            'Accept: */*',
        ];

        $unauth = http_request_raw($url, $baseHeaders, 5, $authFollow);

        $auth = null;
        if ($authUser !== '' || $authPass !== '') {
            $hdr = $baseHeaders;
            $hdr[] = 'Authorization: Basic ' . base64_encode($authUser . ':' . $authPass);
            $auth = http_request_raw($url, $hdr, 5, $authFollow);
        }

        $authResults = [
            'url' => $url,
            'unauth' => $unauth,
            'auth' => $auth,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>.htaccess Rewrite Rules Tester</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #f4f4f4; }
        .error { color: red; }
    </style>
</head>
<body>
    <h1>.htaccess Rewrite Rules Tester</h1>

    <h2>Auth Status</h2>
    <?php if ($authAuthed): ?>
        <p>Logged in as: <strong><?php echo html((string)$authUserNow); ?></strong></p>
    <?php else: ?>
        <p class="muted">Not logged in (no PHP_AUTH_USER / REMOTE_USER).</p>
    <?php endif; ?>
    <p>
        <a href="?logout=1" onclick="return confirm('Force a Basic-Auth logout prompt?');">Log out</a>
        <span class="muted">(forces a 401; browser behavior varies)</span>
    </p>

    <h2>Detected Rewrite Rules</h2>
    <?php if (!empty($rulesData['errors'])): ?>
        <div class="error">
            <strong>Errors:</strong>
            <ul>
                <?php foreach ($rulesData['errors'] as $error): ?>
                    <li><?php echo html($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <table>
        <thead>
            <tr>
                <th>Line</th>
                <th>Pattern</th>
                <th>Target</th>
                <th>Flags</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rulesData['rules'] as $rule): ?>
                <tr>
                    <td><?php echo html((string)$rule['line']); ?></td>
                    <td><code><?php echo html($rule['pattern']); ?></code></td>             
                    <td><code><?php echo html($rule['target']); ?></code></td>
                    <td><?php echo html($rule['flags']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Real AuthType Basic Test</h2>
    <p class="muted">This makes a loopback HTTP request and shows the real status + headers (401/200, WWW-Authenticate, Location, etc.).</p>

    <?php if ($authError): ?>
        <div class="error"><strong>Error:</strong> <?php echo html($authError); ?></div>
    <?php endif; ?>

    <form method="post" style="margin: 12px 0; padding: 12px; border: 1px solid #ccc;">
        <input type="hidden" name="do_auth_test" value="1" />
        <div style="margin-bottom: 8px;">
            <label><strong>Target URL or path</strong></label><br>
            <input type="text" name="auth_target" value="<?php echo html((string)$authTarget); ?>" style="width: 100%; padding: 8px;" />
            <div class="muted">Example: <code>/admin/htaccess_tester.php?__auth_probe=1</code></div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <div style="flex:1; min-width: 220px;">
                <label><strong>Username</strong></label><br>
                <input type="text" name="auth_user" value="<?php echo html((string)$authUser); ?>" style="width: 100%; padding: 8px;" autocomplete="username" />
            </div>
            <div style="flex:1; min-width: 220px;">
                <label><strong>Password</strong></label><br>
                <input type="password" name="auth_pass" value="" style="width: 100%; padding: 8px;" autocomplete="current-password" />
                <div class="muted">Password is never echoed back.</div>
            </div>
            <div style="min-width: 220px; align-self: end;">
                <label>
                    <input type="checkbox" name="auth_follow" value="1" <?php echo $authFollow ? 'checked' : ''; ?> />
                    Follow redirects
                </label>
            </div>
        </div>
        <div style="margin-top: 10px;">
            <button type="submit" style="padding: 8px 12px;">Run Auth Test</button>
        </div>
    </form>

    <?php if ($authResults): ?>
        <table>
            <thead>
                <tr>
                    <th>Case</th>
                    <th>Status</th>
                    <th>WWW-Authenticate</th>
                    <th>Location</th>
                    <th>Body (first 300 chars)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                    $cases = [
                        'Unauthenticated' => $authResults['unauth'],
                    ];
                    if (is_array($authResults['auth'])) $cases['With Basic Auth'] = $authResults['auth'];
                ?>
                <?php foreach ($cases as $label => $r): ?>
                    <?php
                        $hdrs = $r['headers'] ?? [];
                        $www = $hdrs['WWW-Authenticate'] ?? ($hdrs['Www-Authenticate'] ?? '');
                        $loc = $hdrs['Location'] ?? ($hdrs['location'] ?? '');
                        $body = (string)($r['body'] ?? '');
                        $snippet = substr($body, 0, 300);
                    ?>
                    <tr>
                        <td><?php echo html($label); ?></td>
                        <td><?php echo html((string)($r['code'] ?? '0')); ?><?php if (!empty($r['err'])) echo ' ('.html((string)$r['err']).')'; ?></td>
                        <td><code><?php echo html((string)$www); ?></code></td>
                        <td><code><?php echo html((string)$loc); ?></code></td>
                        <td><code><?php echo html($snippet); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted">Test URL: <a href="<?php echo html($authResults['url']); ?>" target="_blank" rel="noopener"><?php echo html($authResults['url']); ?></a></p>
    <?php endif; ?>
    <h2>Available Models from Local Ollama</h2>
    <?php if (empty($models)): ?>
        <p>No models available or failed to fetch models.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($models as $model): ?>
                <li><?php echo html($model['id'] ?? 'Unknown Model'); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</body>
</html><?php
// ------------------------------------------------------------
// Client identity globals (set by api_guard in bootstrap.php)
// ------------------------------------------------------------