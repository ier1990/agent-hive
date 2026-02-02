<?php

/*

    htaccess_tester.php

    A simple admin tool to test and visualize .htaccess RewriteRules.   

    current .htaccess file:
    AuthType Basic
AuthName "Domain Memory Admin"
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

require_once __DIR__ . '/../lib/http.php';

function csrf_token(): string
{
    auth_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf_token'];
}

function csrf_check(): void
{
    auth_session_start();
    $ok = isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token']);
    if (!$ok) {
        http_response_code(400);
        echo '<h2>Bad Request</h2><p>CSRF check failed.</p>';
        exit;
    }
}

function admin_htpasswd_path(): string
{
    return '/web/private/.passwords/.htpassword';
}

function admin_htaccess_recommended_template(string $authName, string $authUserFile): string
{
    $authName = trim($authName);
    if ($authName === '') $authName = 'Admin';
    $authName = str_replace('"', '', $authName);

    return "AuthType Basic\n"
        . 'AuthName "' . $authName . "\"\n"
        . 'AuthUserFile ' . $authUserFile . "\n\n"
        . "# Require a valid user from the htpasswd file\n"
        . "Require valid-user\n\n"
        . "# Optional hardening (LAN only). Uncomment if you want.\n"
        . "# Require ip 192.168.0.0/24\n";
}

function admin_htpasswd_username_ok(string $u): bool
{
    $u = trim($u);
    if ($u === '' || strlen($u) > 80) return false;
    return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $u);
}

function admin_htpasswd_hash_apr1(string $password): ?string
{
    // Prefer APR1-MD5 which Apache supports broadly. Use system crypt() if available.
    $salt = substr(strtr(base64_encode(random_bytes(12)), '+/', './'), 0, 8);
    $hash = crypt($password, '$apr1$' . $salt . '$');
    if (is_string($hash) && strpos($hash, '$apr1$') === 0) {
        return $hash;
    }
    return null;
}

function admin_htpasswd_set_user(string $path, string $username, string $password): array
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    if (!is_dir($dir)) return ['ok' => false, 'error' => 'htpasswd_dir_missing', 'detail' => $dir];
    if (!is_writable($dir) && !is_file($path)) return ['ok' => false, 'error' => 'htpasswd_dir_not_writable', 'detail' => $dir];

    $hash = admin_htpasswd_hash_apr1($password);
    if ($hash === null || $hash === '') return ['ok' => false, 'error' => 'hash_failed', 'detail' => 'crypt($apr1$) unavailable'];

    $lines = [];
    if (is_file($path)) {
        $raw = @file($path, FILE_IGNORE_NEW_LINES);
        if ($raw === false) return ['ok' => false, 'error' => 'read_failed', 'detail' => $path];
        foreach ($raw as $ln) {
            $ln = rtrim((string)$ln, "\r\n");
            if ($ln === '') continue;
            $lines[] = $ln;
        }
    }

    $found = false;
    $prefix = $username . ':';
    for ($i = 0; $i < count($lines); $i++) {
        if (strpos($lines[$i], $prefix) === 0) {
            $lines[$i] = $username . ':' . $hash;
            $found = true;
            break;
        }
    }
    if (!$found) $lines[] = $username . ':' . $hash;

    $payload = implode("\n", $lines) . "\n";
    @chmod($path, 0660);
    $ok = @file_put_contents($path, $payload, LOCK_EX);
    if ($ok === false) return ['ok' => false, 'error' => 'write_failed', 'detail' => $path];
    @chmod($path, 0640);

    return ['ok' => true, 'created' => !$found, 'username' => $username];
}

function admin_htaccess_write_template(string $path, string $template): array
{
    $dir = dirname($path);
    if (!is_dir($dir)) return ['ok' => false, 'error' => 'admin_dir_missing', 'detail' => $dir];
    if (!is_writable($dir) && !is_file($path)) return ['ok' => false, 'error' => 'admin_dir_not_writable', 'detail' => $dir];

    if (is_file($path)) {
        $bak = $path . '.bak.' . gmdate('Ymd_His');
        @copy($path, $bak);
    }

    @chmod($path, 0660);
    $ok = @file_put_contents($path, $template, LOCK_EX);
    if ($ok === false) return ['ok' => false, 'error' => 'write_failed', 'detail' => $path];
    @chmod($path, 0644);
    return ['ok' => true];
}

function detect_v1_dir(): ?string
{
    // Allow overriding for nonstandard docroots.
    if (function_exists('env')) {
        $ov = trim((string)env('CW_V1_DIR', ''));
        if ($ov !== '' && is_dir($ov)) return $ov;
        $ov = trim((string)env('V1_DIR', ''));
        if ($ov !== '' && is_dir($ov)) return $ov;
    }

    // Default layout: /admin and /v1 are siblings under the web root.
    $cand = dirname(__DIR__) . '/v1';
    if (is_dir($cand)) return $cand;

    // Fallback to DOCUMENT_ROOT if available.
    $doc = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($doc !== '') {
        $cand = rtrim($doc, "/\\") . '/v1';
        if (is_dir($cand)) return $cand;
    }

    return null;
}

function detect_v1_htaccess_path(): ?string
{
    $dir = detect_v1_dir();
    if ($dir === null) return null;
    return rtrim($dir, "/\\") . '/.htaccess';
}

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
    header('WWW-Authenticate: Basic realm="Domain Memory Admin (logged out)"');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Logged out (browser should re-prompt on next request).\n";
    exit;
}

$htaccessPath = __DIR__ . '/.htaccess';
function html(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$htpasswdPath = admin_htpasswd_path();
$authNameDefault = 'Domain Memory Admin';
$recommendedHtaccess = admin_htaccess_recommended_template($authNameDefault, $htpasswdPath);

$messages = [];
$errors = [];
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

// Rewrite viewer: default to Public API (/v1), allow toggling to Admin (/admin)
$view = strtolower(trim((string)($_GET['view'] ?? 'v1')));
$viewTitle = 'Public API /v1';
$viewHtaccessPath = '';
if ($view === 'admin') {
    $viewTitle = 'Admin /admin';
    $viewHtaccessPath = $htaccessPath;
} else {
    $v1Ht = detect_v1_htaccess_path();
    if ($v1Ht !== null) {
        $viewHtaccessPath = $v1Ht;
    }
}

// --- Current auth status ---
$authUserNow = $_SERVER['PHP_AUTH_USER'] ?? ($_SERVER['REMOTE_USER'] ?? '');
$authAuthed = ($authUserNow !== '');

// --- Real AuthType Basic test ---
$authResults = null;
$authError = null;
$authTarget = $_POST['auth_target'] ?? '/admin/admin_htaccess.php?__auth_probe=1';
$authUser = $_POST['auth_user'] ?? '';
$authPass = $_POST['auth_pass'] ?? '';
$authFollow = isset($_POST['auth_follow']) && $_POST['auth_follow'] === '1';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'write_admin_htaccess') {
        $res = admin_htaccess_write_template($htaccessPath, $recommendedHtaccess);
        if (!empty($res['ok'])) {
            $messages[] = 'Wrote /admin/.htaccess template (backup created if file existed).';
        } else {
            $errors[] = 'Failed to write .htaccess: ' . html((string)($res['error'] ?? 'unknown')) . ' ' . html((string)($res['detail'] ?? ''));
        }
    }
    if ($action === 'set_htpasswd_user') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');
        if (!admin_htpasswd_username_ok($username)) {
            $errors[] = 'Invalid username (allowed: letters/numbers/._-; must start with letter/number).';
        } elseif (strlen($password) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        } elseif ($password !== $password2) {
            $errors[] = 'Passwords do not match.';
        } else {
            $res = admin_htpasswd_set_user($htpasswdPath, $username, $password);
            if (!empty($res['ok'])) {
                $messages[] = (!empty($res['created']) ? 'Created' : 'Updated') . ' htpasswd user: ' . html($username);
            } else {
                $errors[] = 'Failed to write htpasswd: ' . html((string)($res['error'] ?? 'unknown')) . ' ' . html((string)($res['detail'] ?? ''));
            }
        }
    }
}

// Compute rewrite rules for the selected view target.
if ($viewHtaccessPath === '') {
    $rulesData = [
        'rewrite_base' => null,
        'rules' => [],
        'errors' => [
            'Cannot locate the /v1 directory to read its .htaccess. If your install is nonstandard, set env CW_V1_DIR to the filesystem path (example: /home/jjf1995/public_html/v1).'
        ],
    ];
} else {
    $rulesData = getRewriteRules($viewHtaccessPath);
}

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
    <title>.htaccess Password & API Rewrites</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #f4f4f4; }
        .error { color: red; }
        .muted { color: #666; }

        details.tutor { margin-top: 18px; border: 1px solid #ddd; border-radius: 10px; background: #fafafa; }
        details.tutor > summary { cursor: pointer; padding: 10px 12px; font-weight: bold; }
        details.tutor pre { margin: 0; padding: 12px; white-space: pre-wrap; background: #fff; border-top: 1px solid #eee; border-radius: 0 0 10px 10px; }
    </style>
</head>
<body>
    <h1>.htaccess Password & API Rewrites</h1>

    <?php foreach ($messages as $m): ?>
        <div style="background:#eef9f0; border:1px solid #cde9d3; padding:10px 12px; border-radius:8px; margin:10px 0;"><?php echo $m; ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $m): ?>
        <div style="background:#fff4f4; border:1px solid #ffd1d1; padding:10px 12px; border-radius:8px; margin:10px 0;"><?php echo $m; ?></div>
    <?php endforeach; ?>

    <h2>Admin Basic Auth (.htaccess + htpasswd)</h2>
    <div style="background:#f7f7f7; border:1px solid #ddd; border-radius:8px; padding:12px; margin-bottom:14px;">
        <div><strong>.htaccess:</strong> <code><?php echo html($htaccessPath); ?></code> <?php echo is_file($htaccessPath) ? '<span style="color:#0a7a30;">(exists)</span>' : '<span class="muted">(missing)</span>'; ?></div>
        <div><strong>AuthUserFile:</strong> <code><?php echo html($htpasswdPath); ?></code> <?php echo is_file($htpasswdPath) ? '<span style="color:#0a7a30;">(exists)</span>' : '<span class="muted">(missing)</span>'; ?></div>
        <div><strong>Current .htaccess content:</strong></div>
        <pre style="white-space:pre-wrap; background:#fff; border:1px solid #ddd; border-radius:8px; padding:10px; margin-top:10px;"><?php
            if (is_file($htaccessPath)) {
                $content = @file_get_contents($htaccessPath);
                echo html((string)$content);
            } else {
                echo '<span class="muted">(file not found)</span>';
            }
        ?></pre>
        <div class="muted" style="margin-top:8px;">This is Apache Basic Auth for <code>/admin</code>. It is separate from the app-level device/session auth.</div>

        <h3 style="margin:12px 0 6px 0;">Write default <code>/admin/.htaccess</code></h3>
        <div class="muted">Overwrites <code>/admin/.htaccess</code> with the recommended template (creates a timestamped backup if the file already exists).</div>
        <pre style="white-space:pre-wrap; background:#fff; border:1px solid #ddd; border-radius:8px; padding:10px; margin-top:10px;"><?php echo html($recommendedHtaccess); ?></pre>
        <form method="post" style="margin-top:10px;" onsubmit="return confirm('Overwrite /admin/.htaccess with the template? A backup will be created.');">
            <input type="hidden" name="csrf_token" value="<?php echo html(csrf_token()); ?>" />
            <input type="hidden" name="action" value="write_admin_htaccess" />
            <button type="submit" style="padding:8px 12px;">Write Template</button>
        </form>

        <h3 style="margin:14px 0 6px 0;">Create / update Apache Basic Auth user</h3>
        <div class="muted">Adds or updates a user entry in <code><?php echo html($htpasswdPath); ?></code>. Passwords are never displayed back.</div>
        <form method="post" style="margin-top:10px; padding:12px; border:1px solid #ddd; border-radius:8px; background:#fff;">
            <input type="hidden" name="csrf_token" value="<?php echo html(csrf_token()); ?>" />
            <input type="hidden" name="action" value="set_htpasswd_user" />
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <div style="flex:1; min-width:220px;">
                    <label><strong>Username</strong></label><br>
                    <input type="text" name="username" value="" style="width:100%; padding:8px;" autocomplete="username" />
                </div>
                <div style="flex:1; min-width:220px;">
                    <label><strong>Password</strong> (min 10 chars)</label><br>
                    <input type="password" name="password" value="" style="width:100%; padding:8px;" autocomplete="new-password" />
                </div>
                <div style="flex:1; min-width:220px;">
                    <label><strong>Repeat password</strong></label><br>
                    <input type="password" name="password2" value="" style="width:100%; padding:8px;" autocomplete="new-password" />
                </div>
            </div>
            <div style="margin-top:10px;">
                <button type="submit" style="padding:8px 12px;">Create/Update User</button>
            </div>
        </form>
    </div>

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

    <h2>Notes</h2>
    <ul class="muted">
        <li>Browsers cache Basic Auth credentials; there is no perfect server-side logout.</li>
        <li>If Apache can’t read <code><?php echo html($htpasswdPath); ?></code>, fix permissions/ownership under <code>/web/private/.passwords</code>.</li>
    </ul>

    <h2>Rewrite Rules Viewer</h2>
    <div class="muted" style="margin:6px 0 10px 0;">
        Viewing: <strong><?php echo html($viewTitle); ?></strong>
        <?php if ($viewHtaccessPath !== ''): ?>
            · file: <code><?php echo html($viewHtaccessPath); ?></code>
        <?php endif; ?>
        · <a href="?view=v1">Public API</a>
        | <a href="?view=admin">Admin</a>
    </div>
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
            <div class="muted">Example: <code>/admin/admin_htaccess.php?__auth_probe=1</code></div>
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

<?php
    $tutorText = "✅ The goal (perfect setup)\n\n"
        . "You want two layers:\n\n"
        . "Apache Basic Auth (.htaccess + .htpasswd)\n"
        . "→ stops random drive-by access to /admin/ (and blocks bots)\n\n"
        . "Your app auth (device cookie/session)\n"
        . "→ handles actual app-level security + pairing logic\n\n"
        . "So even if the Basic Auth password leaks, you’re still protected.\n\n"
        . "Generate the htpasswd file (CLI example)\n"
        . "sudo apt-get install apache2-utils -y\n"
        . "sudo htpasswd -c /web/private/.passwords/.htpassword admin\n\n"
        . "Add more users later (no -c):\n"
        . "sudo htpasswd /web/private/.passwords/.htpassword tom\n\n"
        . "Apache config gotcha (AllowOverride)\n\n"
        . "Make sure Apache allows .htaccess overrides in your vhost config:\n\n"
        . "<Directory /path/to/your/docroot/admin>\n"
        . "    AllowOverride All\n"
        . "</Directory>\n\n"
        . "or (less strict):\n\n"
        . "<Directory /path/to/your/docroot>\n"
        . "    AllowOverride All\n"
        . "</Directory>\n\n"
        . "Reload Apache:\n"
        . "sudo systemctl reload apache2\n\n"
        . "Quick verification tests\n\n"
        . "curl -I http://server/admin/\n"
        . "# Expect: 401 + WWW-Authenticate: Basic realm=...\n\n"
        . "curl -u admin:password -I http://server/admin/\n"
        . "# Expect: 200\n\n"
        . "Extra-hardening ideas\n"
        . "- Force HTTPS for /admin (when you have TLS)\n"
        . "- Restrict /admin to LAN / Tailscale subnets\n";
?>

<details class="tutor" open>
    <summary>"Tutor" (keep this around)</summary>
    <pre><?php echo html($tutorText); ?></pre>
</details>









</body>
</html><?php
// ------------------------------------------------------------
// Client identity globals (set by api_guard in bootstrap.php)
// ------------------------------------------------------------