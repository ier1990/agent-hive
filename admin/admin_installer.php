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
];
const MIN_DISK_MB = 100;

// Process actions
if ($action === 'check_env') {
        $results['env_check'] = checkEnvironment();
        $_SESSION['install_results'] = $results;
        $step = 2;
} elseif ($action === 'create_dirs') {
        $results['dirs_created'] = createDirectories();
        $_SESSION['install_results'] = $results;
        $step = 3;
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
    
        // Writable paths - check parent dirs
        foreach (REQUIRED_DIRS as $dir) {
                $parent = dirname($dir);
                $writable = is_dir($parent) && is_writable($parent);
                $checks['path_' . md5($dir)] = [
                        'label' => "Path: $dir",
                        'required' => 'Parent writable',
                        'actual' => $writable ? 'OK' : 'NOT WRITABLE',
                        'ok' => $writable,
                        'critical' => true,
                        'fix' => $writable ? null : "sudo mkdir -p $parent && sudo chown www-data:www-data $parent && sudo chmod 775 $parent"
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
?>
<!DOCTYPE html>
<html><head>
<title>Installer</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-white p-8">
<div class="max-w-4xl mx-auto">
<h1 class="text-3xl mb-8">🚀 Admin Installer - Step <?= $step ?>/3</h1>

<?php if ($step === 1): ?>
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

<?php elseif ($step === 2): ?>
    <h2 class="text-xl mb-6">📁 Create Required Directories</h2>
  
    <?php if (!empty($results['dirs_created'])): ?>
        <div class="space-y-2 mb-6">
            <?php foreach ($results['dirs_created'] as $result): ?>
                <div class="bg-gray-800 p-4 rounded flex items-center justify-between">
                    <div>
                        <div class="font-mono text-sm"><?= $result['path'] ?></div>
                        <div class="text-xs text-gray-400">
                            <?= $result['existed'] ? 'Already existed' : ($result['created'] ? 'Created' : 'Failed') ?>
                            <?= $result['writable'] ? ' • Writable ✓' : ' • Not writable ✗' ?>
                        </div>
                        <?php if ($result['error']): ?>
                            <div class="text-xs text-red-400 mt-1"><?= $result['error'] ?></div>
                            <div class="text-xs text-yellow-400 mt-1 font-mono"><?= $result['fix'] ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="text-2xl">
                        <?= ($result['existed'] || $result['created']) && $result['writable'] ? '✅' : '❌' ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <a href="?step=3" class="inline-block bg-blue-600 hover:bg-blue-700 px-6 py-3 rounded font-bold">
            Continue to Setup →
        </a>
    <?php else: ?>
        <p class="text-gray-400 mb-6">The following directories will be created:</p>
        <div class="bg-gray-800 p-4 rounded mb-6 space-y-1 font-mono text-sm">
            <?php foreach (REQUIRED_DIRS as $dir): ?>
                <div><?= $dir ?></div>
            <?php endforeach; ?>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_dirs">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 px-6 py-3 rounded font-bold">
                📁 Create Directories
            </button>
        </form>
    <?php endif; ?>

<?php elseif ($step === 3): ?>
    <h2 class="text-xl mb-6">✅ Installation Complete</h2>
    <div class="bg-green-900 border border-green-500 p-6 rounded mb-6">
        <div class="text-2xl mb-2">🎉 Success!</div>
        <p>Environment checks passed and directories created.</p>
        <p class="mt-4 text-sm">Next steps:</p>
        <ul class="list-disc ml-6 mt-2 text-sm">
            <li>Configure .env file at /web/private/.env</li>
            <li>Set up API keys (IER_API_KEY, OLLAMA_MODEL)</li>
            <li>Run database migrations if needed</li>
        </ul>
    </div>
    <a href="index.php" class="inline-block bg-blue-600 hover:bg-blue-700 px-6 py-3 rounded font-bold">
        Go to Admin Console
    </a>
<?php endif; ?>

<div class="mt-6">
    <?php if ($step > 1 && $step < 3): ?><a href="?step=<?= $step-1 ?>" class="text-blue-400">← Back</a><?php endif; ?>
</div>
</div>
</body></html>
