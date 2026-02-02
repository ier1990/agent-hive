<?php
// Upload + install an imported admin module into PRIVATE_ROOT/admin_modules.

require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$modulesDir = rtrim((string)PRIVATE_ROOT, '/') . '/admin_modules';

$err = '';
$ok = '';
$lintOut = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['module'])) {
        $err = 'No file uploaded.';
    } else {
        $f = $_FILES['module'];
        $uploadErr = isset($f['error']) ? (int)$f['error'] : UPLOAD_ERR_NO_FILE;
        if ($uploadErr !== UPLOAD_ERR_OK) {
            $err = 'Upload failed (code ' . $uploadErr . ').';
        } else {
            $name = basename((string)($f['name'] ?? ''));
            if ($name === '' || !preg_match('/^admin_[A-Za-z0-9_.-]+\.php$/', $name)) {
                $err = 'Filename must look like admin_something.php (letters/numbers/._- only).';
            } else {
                $size = isset($f['size']) ? (int)$f['size'] : 0;
                if ($size <= 0) {
                    $err = 'Uploaded file was empty.';
                } elseif ($size > 2 * 1024 * 1024) {
                    $err = 'File too large (max 2MB).';
                } else {
                    if (!is_dir($modulesDir)) {
                        // Keep private dirs not world-readable.
                        @mkdir($modulesDir, 0750, true);
                    }
                    if (!is_dir($modulesDir) || !is_writable($modulesDir)) {
                        $err = 'Modules directory not writable: ' . $modulesDir;
                    } else {
                        $finalPath = $modulesDir . '/' . $name;
                        if (is_file($finalPath)) {
                            $err = 'A module with this name already exists: ' . $name;
                        } else {
                            $tmpPath = tempnam($modulesDir, 'upload_');
                            if (!$tmpPath) {
                                $err = 'Could not allocate temp file.';
                            } else {
                                if (!move_uploaded_file($f['tmp_name'], $tmpPath)) {
                                    @unlink($tmpPath);
                                    $err = 'Could not move uploaded file.';
                                } else {
                                    // Lint check: require PHP CLI available.
                                    $phpBin = defined('PHP_BINARY') ? (string)PHP_BINARY : '';
                                    if ($phpBin !== '' && is_file($phpBin) && is_executable($phpBin)) {
                                        $cmd = escapeshellarg($phpBin) . ' -l ' . escapeshellarg($tmpPath) . ' 2>&1';
                                    } else {
                                        $cmd = 'php -l ' . escapeshellarg($tmpPath) . ' 2>&1';
                                    }

                                    $outLines = [];
                                    $exit = 0;
                                    @exec($cmd, $outLines, $exit);
                                    $lintOut = trim(implode("\n", $outLines));

                                    if ($exit !== 0) {
                                        @unlink($tmpPath);
                                        $err = 'PHP lint failed. Module was not installed.';
                                    } else {
                                        if (!@rename($tmpPath, $finalPath)) {
                                            @unlink($tmpPath);
                                            $err = 'Could not install module into ' . $finalPath;
                                        } else {
                                            @chmod($finalPath, 0644);
                                            $ok = 'Installed: ' . $name;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Import Admin Module</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
  <div class="max-w-2xl mx-auto p-6">
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
      <h1 class="text-xl font-semibold">Import Admin Module</h1>
      <p class="text-sm text-gray-600 mt-1">Upload a single <span class="font-mono">admin_*.php</span> file. It will be linted and stored under <span class="font-mono"><?php echo h($modulesDir); ?></span>.</p>

      <?php if ($err !== ''): ?>
        <div class="mt-4 p-3 rounded border border-red-200 bg-red-50 text-red-800 text-sm">
          <div class="font-semibold">Error</div>
          <div class="mt-1"><?php echo h($err); ?></div>
          <?php if ($lintOut !== ''): ?>
            <pre class="mt-2 p-2 bg-white border border-red-200 rounded text-xs overflow-auto"><?php echo h($lintOut); ?></pre>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($ok !== ''): ?>
        <div class="mt-4 p-3 rounded border border-green-200 bg-green-50 text-green-800 text-sm">
          <div class="font-semibold">Success</div>
          <div class="mt-1"><?php echo h($ok); ?></div>
          <div class="mt-3 flex gap-2">
            <a class="inline-block px-3 py-2 rounded bg-blue-600 text-white text-sm hover:bg-blue-700" href="index.php" target="_top">Back to Admin</a>
            <?php if (preg_match('/^Installed: (admin_[A-Za-z0-9_.-]+\.php)$/', $ok, $m)): ?>
              <a class="inline-block px-3 py-2 rounded bg-gray-900 text-white text-sm hover:bg-gray-800" href="module_import.php?m=<?php echo rawurlencode($m[1]); ?>" target="_top">Open Module</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <form class="mt-5" method="post" enctype="multipart/form-data">
        <label class="block text-sm font-medium text-gray-700">Module file</label>
        <input class="mt-2 block w-full text-sm" type="file" name="module" accept=".php" required>
        <button class="mt-4 px-4 py-2 rounded bg-gray-900 text-white text-sm hover:bg-gray-800" type="submit">Upload + Install</button>
      </form>

      <div class="mt-5 text-xs text-gray-600">
        <div class="font-semibold">Rules</div>
        <ul class="list-disc ml-5 mt-1">
          <li>Filename must start with <span class="font-mono">admin_</span> and end with <span class="font-mono">.php</span>.</li>
          <li>Max size: 2MB.</li>
          <li>Must pass <span class="font-mono">php -l</span> on the server.</li>
        </ul>
      </div>
    </div>
  </div>
</body>
</html>
