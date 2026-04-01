<?php
// Admin Installer - Step-by-step setup wizard
session_start();

// Step tracker
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$action = $_POST['action'] ?? null;
$results = $_SESSION['install_results'] ?? [];

// Required configuration
const REQUIRED_PHP = '7.3';
const REQUIRED_EXTS = ['sqlite3', 'curl', 'json', 'mbstring'];
const REQUIRED_DIRS = [
        '/web/private/db/inbox',
        '/web/private/db/memory',
        '/web/private/logs',
        '/web/private/uploads/memory',
        '/web/private/cache',
        '/web/private/storage',
];
const MIN_DISK_MB = 100;

// Default .env variables
const ENV_DEFAULTS = [
        'IER_ROLE' => 'ai-24g',
        'IER_NODE' => 'lan-default',
        'IER_VERSION' => '2025-12-25.1',
        'SECURITY_MODE' => 'lan',
        'ALLOW_IPS_WITHOUT_KEY' => '127.0.0.1/32,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
        'REQUIRE_KEY_FOR_ALL' => '0',
        'OPENAI_API_KEY' => '',
        'OPENAI_BASE_URL' => 'https://api.openai.com/v1',
        'OPENAI_MODEL' => 'gpt-3.5-turbo',
        'LLM_BASE_URL' => 'http://127.0.0.1:1234/v1',
        'LLM_API_KEY' => '',
        'SYSINFO_API_URL' => 'http://127.0.0.1/v1/inbox/',
        'SYSINFO_API_KEY' => '',
        'IER_API_KEY' => '',
        'OLLAMA_HOST' => 'http://127.0.0.1:11434',
        'SEARX_URL' => '',
];

// Process actions
if ($action === 'check_env') {
        $results['env_check'] = checkEnvironment();
        $_SESSION['install_results'] = $results;
    $step = 3;
} elseif ($action === 'setup_env') {
        $results['env_saved'] = setupEnvFile($_POST);
        $_SESSION['install_results'] = $results;
        $step = 4;
}

// Load .env file into associative array
function loadEnvFile($path = '/web/private/.env') {
        $out = [];
        if (!is_file($path)) return $out;
        
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '#') === 0) continue;
                
                if (strpos($line, '=') !== false) {
                        $parts = explode('=', $line, 2);
                        $out[trim($parts[0])] = trim($parts[1]);
                }
        }
        return $out;
}

function installerGenerateApiKey() {
    try {
        return 'sk-' . bin2hex(random_bytes(20));
    } catch (Throwable $e) {
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes(20);
            if ($bytes !== false) {
                return 'sk-' . bin2hex($bytes);
            }
        }
        return 'sk-' . sha1(uniqid('', true) . mt_rand());
    }
}

function installerReadApiKeys($path = '/web/private/api_keys.json') {
    if (!is_file($path) || !is_readable($path)) return [];
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function installerWriteApiKeys(array $keys, $path = '/web/private/api_keys.json') {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
        return false;
    }

    $json = json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) return false;

    $ok = @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
    if ($ok) {
        @chmod($path, 0600);
    }
    return $ok;
}

function installerEnsureSysinfoApiKey(array &$config) {
    $result = [
        'ok' => false,
        'path' => '/web/private/api_keys.json',
        'sysinfo_key_created' => false,
        'ier_key_created' => false,
        'key_updated' => false,
        'error' => null,
    ];

    $sysinfoKey = trim((string)($config['SYSINFO_API_KEY'] ?? ''));
    $ierKey = trim((string)($config['IER_API_KEY'] ?? ''));

    if ($sysinfoKey === '') {
        $sysinfoKey = installerGenerateApiKey();
        $result['sysinfo_key_created'] = true;
        $config['SYSINFO_API_KEY'] = $sysinfoKey;
    }

    if ($ierKey === '') {
        $ierKey = installerGenerateApiKey();
        $result['ier_key_created'] = true;
        $config['IER_API_KEY'] = $ierKey;
    }

    $scopes = ['inbox', 'receiving', 'incoming', 'health', 'ping'];
    $keys = installerReadApiKeys($result['path']);

    $targetKeys = [
        $sysinfoKey => 'installer_sysinfo',
        $ierKey => 'installer_ier',
    ];

    foreach ($targetKeys as $keyValue => $defaultName) {
        $entry = $keys[$keyValue] ?? [];
        if (!is_array($entry)) {
            $entry = [];
        }

        $existingScopes = [];
        if (isset($entry['scopes']) && is_array($entry['scopes'])) {
            $existingScopes = $entry['scopes'];
        } elseif (array_values($entry) === $entry) {
            $existingScopes = $entry;
        }

        $mergedScopes = array_values(array_unique(array_merge($existingScopes, $scopes)));
        sort($mergedScopes);

        $entry['scopes'] = $mergedScopes;
        $entry['active'] = true;
        if (!isset($entry['name']) || $entry['name'] === '') {
            $entry['name'] = $defaultName;
        }
        $keys[$keyValue] = $entry;
    }

    if (!installerWriteApiKeys($keys, $result['path'])) {
        $result['error'] = 'Failed to write ' . $result['path'] . ' (check permissions)';
        return $result;
    }

    $result['ok'] = true;
    $result['key_updated'] = true;
    return $result;
}

// Save .env file from POST data
function setupEnvFile($postData) {
        $envPath = '/web/private/.env';
        $envDir = dirname($envPath);
        
        $result = [
                'path' => $envPath,
                'saved' => false,
                'error' => null,
                'updated_keys' => []
        ];
        
        if (!is_dir($envDir)) {
                $result['error'] = 'Directory ' . $envDir . ' does not exist';
                return $result;
        }
        
        // Load existing .env if it exists, merge with defaults
        $existing = loadEnvFile($envPath);
        $config = array_merge(ENV_DEFAULTS, $existing);
        
        // Update from POST data
        foreach (ENV_DEFAULTS as $key => $default) {
                if (isset($postData[$key])) {
                        $newVal = trim($postData[$key]);
                        if ($newVal !== $config[$key]) {
                                $config[$key] = $newVal;
                                $result['updated_keys'][] = $key;
                        }
                }
        }

            // Ensure sysinfo sender has stable URL + key defaults for first-run installs.
            if (trim((string)($config['SYSINFO_API_URL'] ?? '')) === '') {
                $config['SYSINFO_API_URL'] = 'http://127.0.0.1/v1/inbox/';
                $result['updated_keys'][] = 'SYSINFO_API_URL';
            }

            $beforeSysinfo = trim((string)($config['SYSINFO_API_KEY'] ?? ''));
            $beforeIer = trim((string)($config['IER_API_KEY'] ?? ''));
            $apiKeyProvision = installerEnsureSysinfoApiKey($config);
            $result['api_key_provision'] = $apiKeyProvision;

            if (trim((string)($config['SYSINFO_API_KEY'] ?? '')) !== $beforeSysinfo) {
                $result['updated_keys'][] = 'SYSINFO_API_KEY';
            }
            if (trim((string)($config['IER_API_KEY'] ?? '')) !== $beforeIer) {
                $result['updated_keys'][] = 'IER_API_KEY';
            }

            if (!$apiKeyProvision['ok']) {
                $result['error'] = $apiKeyProvision['error'];
                return $result;
            }

            $result['updated_keys'] = array_values(array_unique($result['updated_keys']));
        
        // Build new .env content - preserve structure when possible
        $lines = [];
        $processed = [];
        
        // If file exists, preserve comments and update values
        if (is_file($envPath)) {
                $existing_lines = file($envPath, FILE_IGNORE_NEW_LINES);
                foreach ($existing_lines as $line) {
                        $trimmed = trim($line);
                        
                        // Preserve comments and blank lines
                        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
                                $lines[] = $line;
                                continue;
                        }
                        
                        // Update config values
                        if (strpos($trimmed, '=') !== false) {
                                $parts = explode('=', $trimmed, 2);
                                $key = trim($parts[0]);
                                if (isset($config[$key])) {
                                        $lines[] = $key . '=' . $config[$key];
                                        $processed[$key] = true;
                                        continue;
                                }
                        }
                        $lines[] = $line;
                }
        }
        
        // Append any new keys not in existing file
        foreach (ENV_DEFAULTS as $key => $val) {
                if (!isset($processed[$key])) {
                        $lines[] = $key . '=' . $config[$key];
                }
        }
        
        // Write file
        $content = implode("\n", $lines) . "\n";
        if (@file_put_contents($envPath, $content)) {
                @chmod($envPath, 0600);  // Secure permissions (read-write owner only)
                $result['saved'] = true;
        } else {
                $result['error'] = 'Failed to write ' . $envPath . ' (check permissions)';
        }
        
        return $result;
}

// Helper: Generate fix command for missing PHP extensions
function extFixCommand(string $ext): ?string {
        $isDebian = is_file('/etc/debian_version');
        if (!$isDebian) return null;

        // Detect PHP major.minor version like "8.2"
        $phpMM = implode('.', array_slice(explode('.', PHP_VERSION), 0, 2));

        $pkgGeneric = "php-$ext";
        $pkgVersion = "php{$phpMM}-$ext";

        $reload = "sudo systemctl reload apache2";
        $verify = "php -m | grep -i " . escapeshellarg($ext);

        // Warn if PHP too old
        if (version_compare(PHP_VERSION, '7.3', '<')) {
            return "⚠️ PHP " . PHP_VERSION . " is too old. Recommended: upgrade OS/PHP or use a supported PHP package repo.\nThen: sudo apt install -y $pkgGeneric || sudo apt install -y $pkgVersion\n$reload\n$verify";
        }

        return "sudo apt update && sudo apt install -y $pkgGeneric || sudo apt install -y $pkgVersion\n$reload\n$verify";
}

// A) Environment Check Function
function checkEnvironment() {
        $checks = [];
    
        // PHP Version
        $phpOk = version_compare(PHP_VERSION, REQUIRED_PHP, '>=');
        $checks['php_version'] = [
                'label' => 'PHP Version',
                'required' => REQUIRED_PHP . '+',
                'actual' => PHP_VERSION,
                'ok' => $phpOk,
                'critical' => true
        ];
    
        // Extensions
        foreach (REQUIRED_EXTS as $ext) {
                $loaded = extension_loaded($ext);
                $checks['ext_' . $ext] = [
                        'label' => "Extension: $ext",
                        'required' => 'Loaded',
                        'actual' => $loaded ? 'Yes' : 'MISSING',
                        'ok' => $loaded,
                        'critical' => true,
                        'fix' => $loaded ? null : extFixCommand($ext),
                ];
        }
    
        // Writable required directories
        foreach (REQUIRED_DIRS as $dir) {
            $exists = is_dir($dir);
            $writable = $exists && is_writable($dir);
            $ok = $exists && $writable;
                $checks['path_' . md5($dir)] = [
                        'label' => "Path: $dir",
                'required' => 'Exists + writable',
                'actual' => $ok ? 'OK' : ($exists ? 'NOT WRITABLE' : 'MISSING'),
                'ok' => $ok,
                        'critical' => true,
                'fix' => $ok ? null : "sudo mkdir -p $dir && sudo chown www-data:www-data $dir && sudo chmod 775 $dir"
                ];
        }
    
        // Disk space
        $freeBytes = @disk_free_space('/web/private');
        $freeMB = $freeBytes ? round($freeBytes / 1024 / 1024) : 0;
        $diskOk = $freeMB >= MIN_DISK_MB;
        $checks['disk_space'] = [
                'label' => 'Disk Space',
                'required' => MIN_DISK_MB . 'MB+',
                'actual' => $freeMB . 'MB free',
                'ok' => $diskOk,
                'critical' => false
        ];
    
        // Confirm paths
        $checks['web_root'] = [
                'label' => 'Web Root',
                'required' => 'Detected',
                'actual' => dirname(__DIR__),
                'ok' => is_dir(dirname(__DIR__)),
                'critical' => false
        ];
    
        $checks['admin_dir'] = [
                'label' => 'Admin Directory',
                'required' => 'Detected',
                'actual' => __DIR__,
                'ok' => is_dir(__DIR__),
                'critical' => false
        ];
    
        return $checks;
}

// B) Create Directories Function
function createDirectories() {
        $results = [];
    
        foreach (REQUIRED_DIRS as $dir) {
                $result = [
                        'path' => $dir,
                        'existed' => is_dir($dir),
                        'created' => false,
                        'writable' => false,
                        'error' => null
                ];
        
                if (!is_dir($dir)) {
                        if (@mkdir($dir, 0775, true)) {
                                $result['created'] = true;
                                $result['writable'] = is_writable($dir);
                        } else {
                                $result['error'] = 'Failed - check permissions';
                                $result['fix'] = "sudo mkdir -p $dir && sudo chown www-data:www-data $dir && sudo chmod 775 $dir";
                        }
                } else {
                        $result['writable'] = is_writable($dir);
                }
        
                $results[] = $result;
        }
    
        return $results;
}

// C) Load Story Templates Function
function loadStoryTemplates() {
        $templateFile = __DIR__ . '/AI_Story/story_templates_ai_templates_import.json';
        $result = [
                'attempted' => true,
                'file_exists' => is_file($templateFile),
                'loaded' => 0,
                'errors' => [],
                'message' => ''
        ];
        
        if (!$result['file_exists']) {
                $result['message'] = 'Template file not found at ' . $templateFile;
                return $result;
        }
        
        $raw = @file_get_contents($templateFile);
        if (!is_string($raw) || $raw === '') {
                $result['message'] = 'Cannot read template file';
                return $result;
        }
        
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
                $result['message'] = 'Invalid JSON: ' . json_last_error_msg();
                return $result;
        }
        
        // Handle both {templates:[]} and {items:[]} formats
        $templates = $data['templates'] ?? $data['items'] ?? $data;
        if (!is_array($templates)) {
                $result['message'] = 'JSON must contain templates array';
                return $result;
        }
        
        try {
                $dbPath = '/web/private/db/memory/ai_header.db';
                $dir = dirname($dbPath);
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                
                $db = new SQLite3($dbPath);
                $db->exec('CREATE TABLE IF NOT EXISTS ai_header_templates (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT UNIQUE NOT NULL,
                        type TEXT DEFAULT "payload",
                        template_text TEXT,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )');
                
                foreach ($templates as $tpl) {
                        $name = trim($tpl['name'] ?? '');
                        $type = trim($tpl['type'] ?? 'payload');
                        $text = $tpl['template_text'] ?? $tpl['text'] ?? '';
                        
                        if ($name === '' || $text === '') {
                                $result['errors'][] = 'Skipped invalid template (missing name or text)';
                                continue;
                        }
                        
                        try {
                                $db->exec("INSERT OR REPLACE INTO ai_header_templates (name, type, template_text, created_at) VALUES ('" . $db->escapeString($name) . "', '" . $db->escapeString($type) . "', '" . $db->escapeString($text) . "', CURRENT_TIMESTAMP)");
                                $result['loaded']++;
                        } catch (Throwable $e) {
                                $result['errors'][] = $name . ': ' . $e->getMessage();
                        }
                }
                
                $db->close();
                $result['message'] = 'Loaded ' . $result['loaded'] . ' templates';
        } catch (Throwable $e) {
                $result['message'] = 'Error: ' . $e->getMessage();
        }
        
        return $result;
}

// Step 1 bootstrap: ensure required directories exist before environment checks.
$bootstrapAllReady = false;
$bootstrapAllExisted = false;
if ($step === 1 && $action === null) {
    $bootstrapResults = createDirectories();
    $results['bootstrap_dirs'] = $bootstrapResults;
    $_SESSION['install_results'] = $results;

    $bootstrapAllReady = true;
    $bootstrapAllExisted = true;
    foreach ($bootstrapResults as $row) {
        $ready = (($row['existed'] || $row['created']) && $row['writable']);
        if (!$ready) {
            $bootstrapAllReady = false;
        }
        if (!$row['existed']) {
            $bootstrapAllExisted = false;
        }
    }

    if ($bootstrapAllReady && $bootstrapAllExisted) {
        header('Location: ?step=2');
        exit;
    }
}
?>
<!DOCTYPE html>
<html><head>
<title>Installer</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-white p-8">
<div class="max-w-4xl mx-auto">
<h1 class="text-3xl mb-8">🚀 Admin Installer - Step <?= $step ?>/4</h1>

<?php if ($step === 1): ?>
    <h2 class="text-xl mb-6">📁 Required Directory Bootstrap</h2>
    <p class="text-gray-400 mb-6">Checking and creating required runtime directories before environment checks.</p>

    <?php
    $bootstrapRows = isset($results['bootstrap_dirs']) && is_array($results['bootstrap_dirs']) ? $results['bootstrap_dirs'] : [];
    $bootstrapOk = true;
    foreach ($bootstrapRows as $row) {
        if (!(($row['existed'] || $row['created']) && $row['writable'])) {
            $bootstrapOk = false;
            break;
        }
    }
    ?>

    <div class="space-y-2 mb-6">
        <?php foreach ($bootstrapRows as $row): ?>
            <div class="bg-gray-800 p-4 rounded flex items-center justify-between">
                <div>
                    <div class="font-bold font-mono text-sm"><?= $row['path'] ?></div>
                    <div class="text-sm text-gray-400">
                        <?= $row['existed'] ? 'Already existed' : ($row['created'] ? 'Created now' : 'Failed') ?>
                        <?= $row['writable'] ? ' • Writable ✓' : ' • Not writable ✗' ?>
                    </div>
                    <?php if (!empty($row['error'])): ?>
                        <div class="text-xs text-red-400 mt-1"><?= $row['error'] ?></div>
                        <div class="text-xs text-yellow-400 mt-1 font-mono"><?= $row['fix'] ?></div>
                    <?php endif; ?>
                </div>
                <div class="text-4xl">
                    <?= (($row['existed'] || $row['created']) && $row['writable']) ? '✅' : '❌' ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($bootstrapOk): ?>
        <a href="?step=2" class="inline-block bg-green-600 hover:bg-green-700 px-6 py-3 rounded font-bold">
            Continue to Environment Check →
        </a>
    <?php else: ?>
        <div class="bg-red-900 border border-red-500 p-4 rounded">
            <div class="font-bold">❌ Directory Setup Issues Found</div>
            <p class="text-sm mt-2">Fix directory permission issues above, then reload this page.</p>
        </div>
    <?php endif; ?>

<?php elseif ($step === 2): ?>
    <h2 class="text-xl mb-6">🔍 Environment Check</h2>
    <p class="text-gray-400 mb-6">Checking what normally breaks when installing...</p>

    <?php
    $checks = checkEnvironment();
    $allOk = array_reduce($checks, function ($carry, $c) {
        return $carry && ($c['ok'] || !$c['critical']);
    }, true);
    ?>

    <div class="space-y-2 mb-6">
        <?php foreach ($checks as $check): ?>
            <div class="bg-gray-800 p-4 rounded flex items-center justify-between">
                <div>
                    <div class="font-bold"><?= $check['label'] ?></div>
                    <div class="text-sm text-gray-400">
                        Required: <?= $check['required'] ?> |
                        Actual: <span class="<?= $check['ok'] ? 'text-green-400' : 'text-red-400' ?>"><?= $check['actual'] ?></span>
                    </div>
                    <?php if (!$check['ok'] && isset($check['fix'])): ?>
                        <div class="text-xs text-yellow-400 mt-2 font-mono"><?= $check['fix'] ?></div>
                    <?php endif; ?>
                </div>
                <div class="text-4xl">
                    <?= $check['ok'] ? '✅' : ($check['critical'] ? '❌' : '⚠️') ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($allOk): ?>
        <form method="POST">
            <input type="hidden" name="action" value="check_env">
            <button type="submit" class="bg-green-600 hover:bg-green-700 px-6 py-3 rounded font-bold">
                ✅ All Checks Passed - Continue →
            </button>
        </form>
    <?php else: ?>
        <div class="bg-red-900 border border-red-500 p-4 rounded">
            <div class="font-bold">❌ Critical Issues Found</div>
            <p class="text-sm mt-2">Fix the issues above before continuing. Use the provided commands if needed.</p>
        </div>
    <?php endif; ?>

<?php elseif ($step === 3): ?>
    <h2 class="text-xl mb-6">⚙️ Environment Configuration (.env)</h2>
    <p class="text-gray-400 mb-6">Configure environment variables for API keys, URLs, and security settings.</p>

    <?php
    // Load current .env values
    $currentEnv = loadEnvFile('/web/private/.env');
    $envConfig = array_merge(ENV_DEFAULTS, $currentEnv);
    $isUpgrade = !empty($currentEnv);
    ?>

    <div class="bg-blue-900 border border-blue-500 p-4 rounded mb-6">
        <div class="text-sm text-blue-200">
            <?= $isUpgrade ? '📝 Upgrading existing .env file' : '✨ Creating new .env file' ?>
        </div>
    </div>

    <form method="POST" class="space-y-4">
        <input type="hidden" name="action" value="setup_env">

        <!-- IER Configuration -->
        <div class="bg-gray-800 p-4 rounded">
            <div class="font-bold text-yellow-400 mb-4">🏗️ IER Configuration</div>
            <div class="space-y-3">
                <div>
                    <label class="text-sm text-gray-300">IER_ROLE</label>
                    <input type="text" name="IER_ROLE" value="<?= htmlspecialchars($envConfig['IER_ROLE']) ?>" class="w-full bg-gray-700 text-white px-3 py-2 rounded mt-1">
                    <div class="text-xs text-gray-500 mt-1">e.g: ai-24g, ai-8g, inference-node</div>
                </div>
                <div>
                    <label class="text-sm text-gray-300">IER_NODE</label>
                    <input type="text" name="IER_NODE" value="<?= htmlspecialchars($envConfig['IER_NODE']) ?>" class="w-full bg-gray-700 text-white px-3 py-2 rounded mt-1">
                    <div class="text-xs text-gray-500 mt-1">e.g: lan-142, production-01</div>
                </div>
                <div>
                    <label class="text-sm text-gray-300">IER_VERSION</label>
                    <input type="text" name="IER_VERSION" value="<?= htmlspecialchars($envConfig['IER_VERSION']) ?>" class="w-full bg-gray-700 text-white px-3 py-2 rounded mt-1">
                    <div class="text-xs text-gray-500 mt-1">Format: YYYY-MM-DD.N</div>
                </div>
            </div>
        </div>

        <!-- Security -->
        <div class="bg-gray-800 p-4 rounded">
            <div class="font-bold text-yellow-400 mb-4">🔐 Security</div>
            <div class="space-y-3">
                <div>
                    <label class="text-sm text-gray-300">SECURITY_MODE</label>
                    <select name="SECURITY_MODE" class="w-full bg-gray-700 text-white px-3 py-2 rounded mt-1">
                        <option value="lan" <?= $envConfig['SECURITY_MODE'] === 'lan' ? 'selected' : '' ?>>LAN (trust RFC1918 IPs)</option>
                        <option value="public" <?= $envConfig['SECURITY_MODE'] === 'public' ? 'selected' : '' ?>>Public (require API keys)</option>
                    </select>
                    <div class="text-xs text-gray-500 mt-1">LAN mode allows local IPs without keys</div>
                </div>
                <div>
                    <label class="text-sm text-gray-300">ALLOW_IPS_WITHOUT_KEY</label>
                    <textarea name="ALLOW_IPS_WITHOUT_KEY" rows="2" class="w-full bg-gray-700 text-white px-3 py-2 rounded mt-1 font-mono text-xs"><?= htmlspecialchars($envConfig['ALLOW_IPS_WITHOUT_KEY']) ?></textarea>
                    <div class="text-xs text-gray-500 mt-1">Comma-separated CIDR blocks (only used in LAN mode)</div>
                </div>
                <div>
                    <label class="text-sm text-gray-300">REQUIRE_KEY_FOR_ALL</label>
                    <select name="REQUIRE_KEY_FOR_ALL" class="w-full bg-gray-700 text-white px-3 py-2 rounded mt-1">
                        <option value="0" <?= $envConfig['REQUIRE_KEY_FOR_ALL'] === '0' ? 'selected' : '' ?>>No (allow LAN IPs)</option>
                        <option value="1" <?= $envConfig['REQUIRE_KEY_FOR_ALL'] === '1' ? 'selected' : '' ?>>Yes (require keys always)</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- API Keys -->
        <div class="bg-gray-800 p-4 rounded">
            <div class="font-bold text-yellow-400 mb-4">🔑 API Keys</div>
            <div class="space-y-3">
                <div>
                    <label class="text-sm text-gray-300">IER_API_KEY</label>
                    <input type="text" name="IER_API_KEY" value="<?= htmlspecialchars($envConfig['IER_API_KEY']) ?>" placeholder="Leave empty to auto-generate sysinfo key" class="w-full bg-gray-700 text-white px-3 py-2 rounded mt-1 font-mono text-sm">
                    <div class="text-xs text-gray-500 mt-1">If blank, installer generates one key, writes it to api_keys.json, and stores it in both SYSINFO_API_KEY and IER_API_KEY.</div>
                </div>
                <div>
                    <label class="text-sm text-gray-300">SYSINFO_API_URL</label>
                    <input type="text" name="SYSINFO_API_URL" value="<?= htmlspecialchars($envConfig['SYSINFO_API_URL']) ?>" placeholder="http://127.0.0.1/v1/inbox/" class="w-full bg-gray-700 text-white px-3 py-2 rounded mt-1 font-mono text-sm">
                    <div class="text-xs text-gray-500 mt-1">Sysinfo sender endpoint used by /web/private/scripts/root_sysinfo_local.sh</div>
                </div>
                <div>
                    <label class="text-sm text-gray-300">OPENAI_API_KEY</label>
                    <input type="password" name="OPENAI_API_KEY" value="<?= htmlspecialchars($envConfig['OPENAI_API_KEY']) ?>" placeholder="Leave empty to skip" class="w-full bg-gray-700 text-white px-3 py-2 rounded mt-1 font-mono text-sm">
                    <div class="text-xs text-gray-500 mt-1">sk-proj-... from OpenAI</div>
                </div>
            </div>
        </div>

        <!-- Model URLs -->
        <div class="bg-gray-800 p-4 rounded">
            <div class="font-bold text-yellow-400 mb-4">🤖 Model Services</div>
            <div class="space-y-3">
                <div>
                    <label class="text-sm text-gray-300">LLM_BASE_URL</label>
                    <input type="text" name="LLM_BASE_URL" value="<?= htmlspecialchars($envConfig['LLM_BASE_URL']) ?>" class="w-full bg-gray-700 text-white px-3 py-2 rounded mt-1">
                    <div class="text-xs text-gray-500 mt-1">e.g: http://127.0.0.1:1234/v1 (LM Studio)</div>
                </div>
                <div>
                    <label class="text-sm text-gray-300">LLM_API_KEY</label>
                    <input type="text" name="LLM_API_KEY" value="<?= htmlspecialchars($envConfig['LLM_API_KEY']) ?>" placeholder="e.g: lm-studio" class="w-full bg-gray-700 text-white px-3 py-2 rounded mt-1">
                </div>
                <div>
                    <label class="text-sm text-gray-300">OPENAI_BASE_URL</label>
                    <input type="text" name="OPENAI_BASE_URL" value="<?= htmlspecialchars($envConfig['OPENAI_BASE_URL']) ?>" class="w-full bg-gray-700 text-white px-3 py-2 rounded mt-1">
                </div>
                <div>
                    <label class="text-sm text-gray-300">OPENAI_MODEL</label>
                    <input type="text" name="OPENAI_MODEL" value="<?= htmlspecialchars($envConfig['OPENAI_MODEL']) ?>" class="w-full bg-gray-700 text-white px-3 py-2 rounded mt-1">
                </div>
                <div>
                    <label class="text-sm text-gray-300">OLLAMA_HOST</label>
                    <input type="text" name="OLLAMA_HOST" value="<?= htmlspecialchars($envConfig['OLLAMA_HOST']) ?>" placeholder="Leave empty if not using Ollama" class="w-full bg-gray-700 text-white px-3 py-2 rounded mt-1">
                </div>
            </div>
        </div>

        <!-- Optional Search -->
        <div class="bg-gray-800 p-4 rounded">
            <div class="font-bold text-yellow-400 mb-4">🔍 Optional Services</div>
            <div>
                <label class="text-sm text-gray-300">SEARX_URL</label>
                <input type="text" name="SEARX_URL" value="<?= htmlspecialchars($envConfig['SEARX_URL']) ?>" placeholder="Leave empty if not using Searx" class="w-full bg-gray-700 text-white px-3 py-2 rounded mt-1">
                <div class="text-xs text-gray-500 mt-1">e.g: http://192.168.0.142:3000</div>
            </div>
        </div>

        <div class="flex gap-4 mt-8">
            <button type="submit" class="bg-green-600 hover:bg-green-700 px-6 py-3 rounded font-bold flex-1">
                ✅ Save Configuration
            </button>
            <a href="?step=2" class="inline-block bg-gray-600 hover:bg-gray-700 px-6 py-3 rounded font-bold">
                ← Back
            </a>
        </div>
    </form>

<?php elseif ($step === 4): ?>
    <h2 class="text-xl mb-6">✅ Installation Complete</h2>
    <div class="bg-green-900 border border-green-500 p-6 rounded mb-6">
        <div class="text-2xl mb-2">🎉 Success!</div>
        <p>Environment checks passed and directories created.</p>
    </div>
    
    <?php
    // Show .env save result if applicable
    if (!empty($results['env_saved'])) {
        $envResult = $results['env_saved'];
    ?>
        <div class="mb-6 p-4 rounded border <?= $envResult['saved'] ? 'bg-green-900 border-green-500' : 'bg-red-900 border-red-500' ?>">
            <div class="font-bold mb-2">⚙️ Environment Configuration</div>
            <div class="text-sm <?= $envResult['saved'] ? 'text-green-200' : 'text-red-200' ?>">
                <?php if ($envResult['saved']): ?>
                    <div>✓ Configuration saved to: <span class="font-mono text-xs"><?= $envResult['path'] ?></span></div>
                    <?php if (!empty($envResult['updated_keys'])): ?>
                        <div class="text-xs text-green-300 mt-2">Updated keys: <?= implode(', ', $envResult['updated_keys']) ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <div>✗ Error: <?= $envResult['error'] ?></div>
                <?php endif; ?>
            </div>
            <?php if (!empty($envResult['api_key_provision'])):
                $prov = $envResult['api_key_provision'];
            ?>
                <div class="text-xs mt-3 <?= !empty($prov['ok']) ? 'text-blue-200' : 'text-red-200' ?>">
                    <?php if (!empty($prov['ok'])): ?>
                        <div>✓ Sysinfo API key ensured in <span class="font-mono"><?= htmlspecialchars($prov['path']) ?></span></div>
                        <?php if (!empty($prov['key_created'])): ?>
                            <div>• Generated a new key for first-run sysinfo ingestion</div>
                        <?php else: ?>
                            <div>• Reused existing key and ensured required scopes</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div>✗ API key provisioning error: <?= htmlspecialchars((string)$prov['error']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php } ?>
    
    <?php
    // Try to load story templates automatically
    $templateResult = loadStoryTemplates();
    ?>
    
    <?php if ($templateResult['attempted']): ?>
        <div class="mb-6 p-4 rounded border <?= $templateResult['loaded'] > 0 ? 'bg-blue-900 border-blue-500' : 'bg-yellow-900 border-yellow-600' ?>">
            <div class="font-bold mb-2">📖 AI Story Templates</div>
            <?php if ($templateResult['file_exists']): ?>
                <div class="text-sm <?= $templateResult['loaded'] > 0 ? 'text-blue-200' : 'text-yellow-200' ?>">
                    <?= $templateResult['message'] ?>
                    <?php if ($templateResult['loaded'] > 0): ?>
                        <div class="text-xs mt-2 text-green-300">✓ Templates ready for AI Story feature</div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($templateResult['errors'])): ?>
                    <div class="text-xs text-red-300 mt-2">
                        <?php foreach ($templateResult['errors'] as $err): ?>
                            <div>• <?= $err ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-sm text-yellow-200">
                    No template file found. You can load templates later via <a href="db_loader.php" class="text-blue-300 underline">/admin/db_loader.php</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h3 class="text-lg font-bold mb-4 mt-8">📋 Required Setup Scripts (Run in Order)</h3>
    <p class="text-gray-400 mb-6">These scripts must be executed as root/sudo to set up wrapper scripts and permissions:</p>

    <div class="space-y-4 mb-8">
        <!-- Script 1 -->
        <div class="bg-gray-800 p-4 rounded border border-gray-700">
            <div class="flex items-start justify-between">
                <div>
                    <div class="font-bold text-yellow-400">1. Create Wrapper Scripts</div>
                    <div class="text-sm text-gray-400 mt-1">Generates executable wrappers in /web/private/scripts/ for all source scripts</div>
                    <div class="font-mono bg-gray-900 p-2 rounded mt-2 text-xs text-green-400">
                        sudo -i<br>
                        cd /web/html/src/scripts<br>
                        bash ./root_update_scripts.sh<br>
                    </div>
                    <div class="text-xs text-gray-500 mt-2">
                        Location: <span class="font-mono">/web/html/src/scripts/root_update_scripts.sh</span><br/>
                        Creates: Wrapper scripts in <span class="font-mono">/web/private/scripts/</span> with proper owner (samekhi:www-data)
                    </div>
                </div>
                <div class="text-3xl">📝</div>
            </div>
        </div>

        <!-- Script 2 -->
        <div class="bg-gray-800 p-4 rounded border border-gray-700">
            <div class="flex items-start justify-between">
                <div>
                    <div class="font-bold text-yellow-400">2. Fix Permissions</div>
                    <div class="text-sm text-gray-400 mt-1">Sets correct ownership and permissions on source files and directories</div>
                    <div class="font-mono bg-gray-900 p-2 rounded mt-2 text-xs text-green-400">
                        sudo /web/html/src/scripts/root_dirperm.sh
                    </div>
                    <div class="text-xs text-gray-500 mt-2">
                        Location: <span class="font-mono">/web/html/src/scripts/root_dirperm.sh</span><br/>
                        Effects: Source scripts (0644), www-data ownership, secure permissions
                    </div>
                </div>
                <div class="text-3xl">🔐</div>
            </div>
        </div>

        <!-- Script 3 -->
        <div class="bg-gray-800 p-4 rounded border border-gray-700">
            <div class="flex items-start justify-between">
                <div>
                    <div class="font-bold text-yellow-400">3. Initialize Cron Dispatcher (Manual)</div>
                    <div class="text-sm text-gray-400 mt-1">Add the cron dispatcher task to the system crontab</div>
                    <div class="font-mono bg-gray-900 p-2 rounded mt-2 text-xs text-green-400">
                        sudo crontab -e
                    </div>
                    <div class="text-xs text-gray-500 mt-2">
                        Add this line to <span class="font-mono">/etc/crontab</span>:<br/>
                        <span class="font-mono">* * * * * www-data /web/private/scripts/cron_dispatcher.php</span><br/>
                        Or use the <strong>Admin Crontab UI</strong> (<a href="admin_Crontab.php" target="_blank" class="text-blue-400">admin_Crontab.php</a>) for future task scheduling
                    </div>
                </div>
                <div class="text-3xl">⏰</div>
            </div>
        </div>

        <!-- Script 4 -->
        <div class="bg-gray-800 p-4 rounded border border-gray-700">
            <div class="flex items-start justify-between">
                <div>
                    <div class="font-bold text-yellow-400">4. Initialize Bash History Ingestion</div>
                    <div class="text-sm text-gray-400 mt-1">Set up hourly bash history ingestion using the Admin Crontab UI</div>
                    <div class="font-mono bg-gray-900 p-2 rounded mt-2 text-xs text-green-400">
                        Open <a href="admin_Crontab.php" target="_blank" class="text-blue-400 underline">admin_Crontab.php</a> and add:
                    </div>
                    <div class="text-xs text-gray-500 mt-2">
                        Add this <strong>@hourly</strong> cron job via the Web UI:<br/>
                        <div class="font-mono bg-gray-900 p-2 rounded mt-1">
                            @hourly /web/private/scripts/root_process_bash_history.py
                        </div>
                        Navigate to <strong>Admin Crontab</strong> → Add one root_ job with @hourly frequency
                    </div>
                </div>
                <div class="text-3xl">📜</div>
            </div>
        </div>
    </div>

    <h3 class="text-lg font-bold mb-4 mt-8">🌐 Apache Configuration (Critical)</h3>
    <p class="text-gray-400 mb-6">Add these Apache configuration blocks for correct API routing and admin access:</p>
    
    <div class="space-y-4 mb-8">
        <!-- Apache Config 1: /v1 API -->
        <div class="bg-gray-800 p-4 rounded border border-red-700">
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <div class="font-bold text-red-400 mb-2">⚠️ Required: /v1 API Directory Configuration</div>
                    <div class="text-sm text-gray-400 mb-3">
                        <strong>DirectorySlash Off</strong> is required for /v1 API rewrite rules to work correctly
                    </div>
                    <div class="font-mono bg-gray-900 p-3 rounded text-xs text-green-400 overflow-x-auto">
&lt;Directory /web/html/v1&gt;<br/>
&nbsp;&nbsp;Options -Indexes -MultiViews +FollowSymLinks<br/>
&nbsp;&nbsp;AllowOverride All<br/>
&nbsp;&nbsp;Require all granted<br/>
&nbsp;&nbsp;<strong class="text-yellow-300">DirectorySlash Off</strong><br/>
&lt;/Directory&gt;
                    </div>
                    <div class="text-xs text-gray-400 mt-3">
                        <strong>Where to add:</strong> 
                        <ul class="list-disc ml-5 mt-1 space-y-1">
                            <li>Debian/Ubuntu: <span class="font-mono">/etc/apache2/sites-available/000-default.conf</span></li>
                            <li>RHEL/CentOS: <span class="font-mono">/etc/httpd/conf/httpd.conf</span> or <span class="font-mono">/etc/httpd/conf.d/vhost.conf</span></li>
                        </ul>
                    </div>
                    <div class="text-xs text-yellow-300 mt-2">
                        After adding: <span class="font-mono">sudo systemctl reload apache2</span> (or <span class="font-mono">httpd</span>)
                    </div>
                </div>
                <div class="text-3xl">🔧</div>
            </div>
        </div>

        <!-- Apache Config 2: /admin -->
        <div class="bg-gray-800 p-4 rounded border border-gray-700">
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <div class="font-bold text-yellow-400 mb-2">Admin Directory Configuration</div>
                    <div class="text-sm text-gray-400 mb-3">
                        Allows .htaccess auth configuration in admin directory
                    </div>
                    <div class="font-mono bg-gray-900 p-3 rounded text-xs text-green-400 overflow-x-auto">
&lt;Directory /web/html/admin&gt;<br/>
&nbsp;&nbsp;Options -Indexes -MultiViews +FollowSymLinks<br/>
&nbsp;&nbsp;AllowOverride AuthConfig<br/>
&nbsp;&nbsp;Require all granted<br/>
&lt;/Directory&gt;
                    </div>
                    <div class="text-xs text-gray-400 mt-3">
                        Add to the same Apache configuration file as above
                    </div>
                </div>
                <div class="text-3xl">🔐</div>
            </div>
        </div>

        <!-- Verification -->
        <div class="bg-blue-900 border border-blue-500 p-4 rounded">
            <div class="font-bold text-blue-200 mb-2">✓ Verify Configuration</div>
            <div class="text-sm text-blue-200 mb-2">After adding the configuration blocks:</div>
            <div class="font-mono bg-gray-900 p-3 rounded text-xs text-green-400 space-y-1">
                <div>sudo apachectl configtest</div>
                <div>sudo systemctl reload apache2  # or httpd on RHEL</div>
                <div>curl -s http://localhost/v1/health | jq</div>
            </div>
            <div class="text-xs text-blue-300 mt-2">
                Health check should return JSON without 404/redirect errors
            </div>
        </div>
    </div>

    <h3 class="text-lg font-bold mb-4 mt-8">⚙️ Post-Installation Configuration</h3>
    <div class="space-y-3 text-sm">
        <div class="text-gray-400">
            <strong>• Configure .env file</strong> at <span class="font-mono">/web/private/.env</span> with API keys and settings
        </div>
        <div class="text-gray-400">
            <strong>• Set up API keys:</strong> IER_API_KEY, OLLAMA_MODEL, etc.
        </div>
        <div class="text-gray-400">
            <strong>• Manage cron tasks:</strong> Use <a href="admin_Crontab.php" class="text-blue-400">Admin Crontab</a> UI to add/edit recurring jobs
        </div>
        <div class="text-gray-400">
            <strong>• Check health:</strong> Visit <a href="/v1/health" class="text-blue-400">/v1/health</a> to verify API is running
        </div>
    </div>

    <div class="mt-8">
        <a href="index.php" class="inline-block bg-blue-600 hover:bg-blue-700 px-6 py-3 rounded font-bold">
            Go to Admin Console
        </a>
    </div>
<?php endif; ?>

<div class="mt-6">
    <?php if ($step > 1 && $step < 4): ?><a href="?step=<?= $step-1 ?>" class="text-blue-400">← Back</a><?php endif; ?>
</div>
</div>
</body></html>
