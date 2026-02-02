<?php
// Loads admin modules stored outside docroot under PRIVATE_ROOT.

require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

$module = isset($_GET['m']) ? (string)$_GET['m'] : '';
$module = basename($module);

// Only allow admin_*.php style module names
if ($module === '' || !preg_match('/^admin_[A-Za-z0-9_.-]+\.php$/', $module)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Invalid module name";
    exit;
}

$modulesDir = rtrim((string)PRIVATE_ROOT, '/').'/admin_modules';
$baseReal = realpath($modulesDir);
if ($baseReal === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Modules directory not found";
    exit;
}

$path = $modulesDir . '/' . $module;
$real = realpath($path);
if ($real === false || strpos($real, $baseReal . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Module not found";
    exit;
}

// Execute the module in this request context.
require $real;
