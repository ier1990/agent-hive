<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();


// Admin File Browser — single file, safer and friendlier
// ---------------------------------------------------
// Improvements made:
//  - safer path handling (avoids traversal and resolves relative paths)
//  - breadcrumb navigation, sorting (dirs first), file sizes & mod time
//  - inline viewing for text files, raw download endpoint for all files
//  - nicer HTML/CSS, better escaping, and safer URL encoding
//  - small performance/safety checks (readable, file size limits)


// Development: show errors
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// Sanitize output (defined early for error messages)
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ----------------------------------- */
// Configuration
/* ----------------------------------- */
$errors = [];
$max_file_size = 1024 * 1024 * 50; // 50 MB max file size for uploads (if implemented in future)
// Default CodeWalker DB path (will be overridden by settings if present)
$codewalker_db_path = "/web/private/db/inbox/codewalker.db";
// Writeable directory for config/menu storage
$write_dir = "/web/private";
$db_dir = $write_dir . "/db/inbox";
if (!is_dir($write_dir) || !is_writable($write_dir)) {
    $errors[] = 'Write directory not found or not writable: ' . e($write_dir);
}
if (!is_dir($db_dir) || !is_writable($db_dir)) {
    $errors[] = 'Database directory not found or not writable: ' . e($db_dir);
}

// Database file path - connect early so we can load scan_path setting
$db_file = $db_dir . "/file_browser.db";
$db = null;
try {
    $db = new PDO('sqlite:' . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Ensure settings table exists
    $db->exec("CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
} catch (Exception $e) {
    $errors[] = 'Unable to open/create database: ' . e($e->getMessage());
}

// Early settings loader function
function loadSettingDB($dbConn, $name) {
    if (!$dbConn) return null;
    try {
        $stmt = $dbConn->prepare('SELECT value FROM settings WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['value'] : null;
    } catch (Exception $e) {
        return null;
    }
}

// Base directory
// Prefer this file browser's own setting, but fall back to CodeWalker's settings DB
// so the browser automatically tracks the same scan_path you configured in CodeWalker.
$scan_path = loadSettingDB($db, 'scan_path');
if (!$scan_path || !is_dir($scan_path)) {
    $cwSettingsLib = __DIR__ . '/lib/codewalker_settings.php';
    if (is_file($cwSettingsLib)) {
        require_once $cwSettingsLib;
        if (function_exists('cw_settings_get_all')) {
            try {
                $cwCfg = cw_settings_get_all();
                if (is_array($cwCfg)) {
                    if ((!$scan_path || !is_dir($scan_path)) && isset($cwCfg['scan_path']) && is_string($cwCfg['scan_path']) && is_dir($cwCfg['scan_path'])) {
                        $scan_path = $cwCfg['scan_path'];
                    }
                    // Also pick up CodeWalker db_path if we don't have one in the file browser settings.
                    $cwDbPath = $cwCfg['db_path'] ?? null;
                    if (is_string($cwDbPath) && $cwDbPath !== '' && is_file($cwDbPath)) {
                        $codewalker_db_path = $cwDbPath;
                    }
                }
            } catch (Throwable $t) {
                // Ignore: file browser still works with fallback to __DIR__.
            }
        }
    }
}

if ($scan_path && is_dir($scan_path)) {
    $BASE_DIR = realpath($scan_path);
} else {
    // Final fallback: keep the browser usable even if settings are missing.
    $BASE_DIR = realpath(__DIR__);
}
if ($BASE_DIR === false) {
    exit('Unable to resolve base directory.');
}

// Connect to CodeWalker database for checking existing summaries/rewrites
$cwDb = null;
// Prefer explicit file-browser setting, otherwise keep whatever we derived above.
$codewalker_db_path_from_browser = loadSettingDB($db, 'codewalker_db_path');
if ($codewalker_db_path_from_browser && is_file($codewalker_db_path_from_browser)) {
    $codewalker_db_path = $codewalker_db_path_from_browser;
}

if ($codewalker_db_path && is_file($codewalker_db_path)) {
    try {
        $cwDb = new PDO('sqlite:' . $codewalker_db_path);
        $cwDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Exception $ex) {
        // silently fail - codewalker features just won't be available
        $cwDb = null;
    }
}

// Helper: get latest action info for a file path from codewalker db
function getFileActions($cwDb, $filePath) {
    if (!$cwDb) return null;
    try {
        // Check if file exists in codewalker db and has actions
        $stmt = $cwDb->prepare("
            SELECT a.id, a.action, a.status, a.created_at, 
                   (SELECT COUNT(*) FROM summaries s WHERE s.action_id = a.id) as has_summary,
                   (SELECT COUNT(*) FROM rewrites r WHERE r.action_id = a.id) as has_rewrite
            FROM actions a 
            JOIN files f ON f.id = a.file_id 
            WHERE f.path = :path 
            ORDER BY a.id DESC 
            LIMIT 5
        ");
        $stmt->execute([':path' => $filePath]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

/* ----------------------------------- */
// Settings
$settings = [];
/* ----------------------------------- */


/* ----------------------------------- */
//messages
$messages = [];

// Default menu (JSON format)
$json_menu = json_decode('[
  { "label": "Admin", "url": "/admin/index.php" },
  { "label": "Home",  "url": "/" },
  { "label": "Notes", "url": "/admin/admin_notes.php" },  
    { "label": "Codewalker", "url": "/admin/codewalker.php" },
    { "label": "AI Header", "url": "/admin/AI_Header/index.php" }
]', true); // <-- true = associative array
// URL encoding (to avoid double-encoding)
function url_encode($str) {
    global $json_menu;
    $default_menu = json_decode($json_menu, true);
    return rawurlencode($str);
}
// Menu file handling
$default_menu = $json_menu;

// If a writable menu file exists under PRIVATE_ROOT, treat it as authoritative.
// This allows per-install customization without editing repo files.
$default_menu_file = $write_dir . '/admin_menu.json';
$menuWasUpdated = false;

// Ensure required items exist (by URL)
function menu_ensure_item(array &$menu, string $label, string $url): void {
    foreach ($menu as $it) {
        if (is_array($it) && (string)($it['url'] ?? '') === $url) return;
    }
    $menu[] = ['label' => $label, 'url' => $url];
}

if (is_file($default_menu_file) && is_readable($default_menu_file)) {
    $json = file_get_contents($default_menu_file);
    $data = json_decode((string)$json, true);
    if (is_array($data)) {
        $default_menu = $data;
    } else {
        $errors[] = 'Invalid admin_menu.json; using defaults.';
        @file_put_contents($default_menu_file . '.bak', (string)$json);
        $default_menu = $json_menu;
        $menuWasUpdated = true;
    }
} else {
    // First run: create the menu file from defaults.
    $default_menu = $json_menu;
    $menuWasUpdated = true;
}

// Always ensure core entries exist.
menu_ensure_item($default_menu, 'Admin', '/admin/index.php');
menu_ensure_item($default_menu, 'Home', '/');
menu_ensure_item($default_menu, 'Notes', '/admin/admin_notes.php');
menu_ensure_item($default_menu, 'Codewalker', '/admin/codewalker.php');
menu_ensure_item($default_menu, 'AI Header', '/admin/AI_Header/index.php');

// Persist if we created/normalized/augmented the menu.
if ($menuWasUpdated || !is_file($default_menu_file)) {
    $payload = json_encode($default_menu, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($default_menu_file, $payload, LOCK_EX);
}

/* ----------------------------------- */
// Helpers
/* ----------------------------------- */
// Join two paths safely
function join_path(string $base, string $append): string {
    return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($append, DIRECTORY_SEPARATOR);
}

// Resolve a requested directory (relative to BASE_DIR) safely
function resolve_dir(string $requested, string $baseDir): string {
    // If requested looks absolute, strip leading slashes to treat as relative
    $candidate = ($requested === '') ? $baseDir : join_path($baseDir, ltrim($requested, "/\\"));
    $real = realpath($candidate);
    if ($real === false) {
        return $baseDir;
    }
    // Ensure the resolved path is inside baseDir
    if (strpos($real, $baseDir) !== 0) {
        return $baseDir;
    }
    return $real;
}

// Build URL for directory parameter
function dir_url_param(string $relPath): string {
    return '?dir=' . rawurlencode($relPath);
}

// Build URL for raw file download/view (optionally force download with &dl=1)
function raw_url(string $relDir, string $file, bool $download = false): string {
    return '?raw=1&dir=' . rawurlencode($relDir) . '&file=' . rawurlencode($file) . ($download ? '&dl=1' : '');
}

// Small helper to build a URL overriding theme param
function url_with_theme(string $themeValue): string {
    $params = $_GET;
    $params['theme'] = $themeValue;
    return '?' . http_build_query($params);
}

// Get requested directory (relative dir passed in URL)
$reqDir = isset($_GET['dir']) ? (string)$_GET['dir'] : '';
$current = resolve_dir($reqDir, $BASE_DIR);
$relative = ltrim(substr($current, strlen($BASE_DIR)), DIRECTORY_SEPARATOR);
if ($relative === false) $relative = '';

// Settings DB reference (reuse $db which is already connected)
$settingsDb = $db;
function saveSettingDB($dbConn, $name, $value) {
    if (!$dbConn) return false;
    $stmt = $dbConn->prepare("INSERT OR REPLACE INTO settings (name, value, updated_at) VALUES (:name, :value, CURRENT_TIMESTAMP)");
    return $stmt->execute([':name' => $name, ':value' => $value]);
}

// Load saved settings from DB (if present)
$dbTheme = loadSettingDB($settingsDb, 'theme'); // possible values: 'light','dark','auto' or null
$dbShowHidden = loadSettingDB($settingsDb, 'show_hidden'); // '1' or '0'
$dbFontSize = loadSettingDB($settingsDb, 'font_size'); // 'small','normal','large' or null

// helper: delete setting
function deleteSettingDB($dbConn, $name) {
    if (!$dbConn) return false;
    $stmt = $dbConn->prepare('DELETE FROM settings WHERE name = :name');
    return $stmt->execute([':name' => $name]);
}

// Management UI: handle add/edit/delete for arbitrary settings
if (isset($_GET['manage_settings']) && $settingsDb) {
    // Process POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];
        if ($action === 'add') {
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $value = isset($_POST['value']) ? $_POST['value'] : '';
            if ($name !== '' && preg_match('/^[A-Za-z0-9_\-]{1,190}$/', $name)) {
                saveSettingDB($settingsDb, $name, $value);
            }
        } elseif ($action === 'edit') {
            $name_old = isset($_POST['name_old']) ? trim($_POST['name_old']) : '';
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $value = isset($_POST['value']) ? $_POST['value'] : '';
            if ($name !== '' && preg_match('/^[A-Za-z0-9_\-]{1,190}$/', $name)) {
                // if name changed, insert new and delete old
                saveSettingDB($settingsDb, $name, $value);
                if ($name_old !== '' && $name_old !== $name) {
                    deleteSettingDB($settingsDb, $name_old);
                }
            }
        } elseif ($action === 'delete') {
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            if ($name !== '') {
                deleteSettingDB($settingsDb, $name);
            }
        }
        // redirect to avoid resubmit
        header('Location: ' . $_SERVER['PHP_SELF'] . '?manage_settings=1');
        exit;
    }

    // if edit param present, render single-edit form
    if (isset($_GET['edit'])) {
        $editName = (string)$_GET['edit'];
        $val = loadSettingDB($settingsDb, $editName);
        ?><!doctype html>
        <html><head><meta charset="utf-8"><title>Edit Setting</title></head><body>
        <h1>Edit Setting: <?php echo e($editName); ?></h1>
        <p><a href="<?php echo e($_SERVER['PHP_SELF'] . '?manage_settings=1'); ?>">&larr; Back to settings</a></p>
        <form method="post">
          <input type="hidden" name="action" value="edit">
          <input type="hidden" name="name_old" value="<?php echo e($editName); ?>">
          <div><label>Name: <input name="name" value="<?php echo e($editName); ?>" required pattern="[A-Za-z0-9_\-]{1,190}"></label></div>
          <div><label>Value:<br><textarea name="value" rows="6" cols="80"><?php echo e($val); ?></textarea></label></div>
          <div style="margin-top:8px;"><button type="submit">Save</button> <a href="<?php echo e($_SERVER['PHP_SELF'] . '?manage_settings=1'); ?>">Cancel</a></div>
        </form>
        </body></html><?php
        exit;
    }

    // render management UI
    $all = $settingsDb->query("SELECT name, value, updated_at FROM settings ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    ?><!doctype html>
    <html><head><meta charset="utf-8"><title>Manage Settings</title></head><body>
    <h1>Manage Settings</h1>
    <p><a href="<?php echo e(strtok($_SERVER['REQUEST_URI'], '?')); ?>">&larr; Back</a></p>
    <h2>Existing Settings</h2>
    <table border="1" cellpadding="6" cellspacing="0">
      <tr><th>Name</th><th>Value</th><th>Updated</th><th>Actions</th></tr>
      <?php foreach ($all as $row): ?>
        <tr>
          <td><?php echo e($row['name']); ?></td>
          <td><?php echo e($row['value']); ?></td>
          <td><?php echo e($row['updated_at']); ?></td>
          <td>
            <a href="<?php echo e($_SERVER['PHP_SELF'] . '?manage_settings=1&edit=' . rawurlencode($row['name'])); ?>">Edit</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete <?php echo addslashes($row['name']); ?>?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="name" value="<?php echo e($row['name']); ?>">
              <button type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>

    <h2>Add Setting</h2>
    <form method="post">
      <input type="hidden" name="action" value="add">
      <label>Name: <input name="name" required pattern="[A-Za-z0-9_\-]{1,190}"></label><br>
      <label>Value:<br><textarea name="value" rows="4" cols="60"></textarea></label><br>
      <button type="submit">Add</button>
    </form>

    <p>Tip: names should be short alpha-numeric strings (underscores/dashes allowed). Examples: ai_model, ai_model_url, ai_model_api_key</p>
    </body></html><?php
    exit;
}

// Handle settings save POST (Save button)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {


// Handle settings save POST (Save button)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    // validate inputs
    $postTheme = isset($_POST['theme']) ? $_POST['theme'] : 'auto';
    $postTheme = in_array($postTheme, ['auto','light','dark'], true) ? $postTheme : 'auto';

    $postShowHidden = isset($_POST['show_hidden']) && $_POST['show_hidden'] === '1' ? '1' : '0';

    $postFont = isset($_POST['font_size']) ? $_POST['font_size'] : 'normal';
    $postFont = in_array($postFont, ['small','normal','large'], true) ? $postFont : 'normal';

    // persist to DB if available
    if ($settingsDb) {
        saveSettingDB($settingsDb, 'theme', $postTheme);
        saveSettingDB($settingsDb, 'show_hidden', $postShowHidden);
        saveSettingDB($settingsDb, 'font_size', $postFont);
    }

    // update cookies for immediate effect
    if ($postTheme === 'auto') {
        setcookie('theme', '', time() - 3600, '/');
    } else {
        setcookie('theme', $postTheme, time() + 30*24*3600, '/');
    }
    setcookie('show_hidden', $postShowHidden, time() + 30*24*3600, '/');
    setcookie('font_size', $postFont, time() + 30*24*3600, '/');

    // PRG: redirect to avoid form resubmit
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

}

// Determine effective settings (priority: GET override -> DB -> COOKIE -> default/auto)
// Theme: support 'auto' meaning client detection
$theme = null; // null => auto
if (isset($_GET['theme']) && in_array($_GET['theme'], ['auto','light','dark'], true)) {
    $t = $_GET['theme'];
    if ($t === 'auto') {
        setcookie('theme', '', time() - 3600, '/');
        $theme = null;
    } else {
        setcookie('theme', $t, time() + 30*24*3600, '/');
        $theme = $t;
    }
} elseif ($dbTheme !== null) {
    // dbTheme may be 'auto' too
    if ($dbTheme === 'auto') $theme = null; else $theme = $dbTheme;
} elseif (isset($_COOKIE['theme']) && in_array($_COOKIE['theme'], ['light','dark'], true)) {
    $theme = $_COOKIE['theme'];
}
$themeClass = ($theme === 'dark') ? 'dark' : (($theme === 'light') ? 'light' : '');

// show_hidden
if (isset($_GET['show_hidden']) && in_array($_GET['show_hidden'], ['0','1'], true)) {
    $showHidden = ($_GET['show_hidden'] === '1');
    setcookie('show_hidden', $showHidden ? '1' : '0', time() + 30*24*3600, '/');
} elseif ($dbShowHidden !== null) {
    $showHidden = ($dbShowHidden === '1');
} elseif (isset($_COOKIE['show_hidden'])) {
    $showHidden = ($_COOKIE['show_hidden'] === '1');
} else {
    $showHidden = false;
}

// font size: small|normal|large
if (isset($_GET['font_size']) && in_array($_GET['font_size'], ['small','normal','large'], true)) {
    $fontSize = $_GET['font_size'];
    setcookie('font_size', $fontSize, time() + 30*24*3600, '/');
} elseif ($dbFontSize !== null) {
    $fontSize = $dbFontSize;
} elseif (isset($_COOKIE['font_size'])) {
    $fontSize = $_COOKIE['font_size'];
} else {
    $fontSize = 'normal';
}

// Raw file endpoint: streams file with appropriate headers
if (isset($_GET['raw']) && $_GET['raw'] == '1' && isset($_GET['file'])) {
    $file = (string)$_GET['file'];
    // Prevent traversal via file name
    $file = basename($file);
    $full = join_path($current, $file);
    $real = realpath($full);
    if ($real === false || strpos($real, $BASE_DIR) !== 0 || !is_file($real) || !is_readable($real)) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
        echo 'File not found or inaccessible.';
        exit;
    }

    // Determine mime type
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $real);
        finfo_close($finfo);
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($real);
    } else {
        $mime = 'application/octet-stream';
    }

    // Allow forcing download
    $forceDownload = isset($_GET['dl']) && $_GET['dl'] == '1';

    // Serve file (inline for known text/image types unless forced to download)
    $inline = !$forceDownload && (strpos($mime, 'text/') === 0 || strpos($mime, 'image/') === 0);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($real));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . basename($real) . '"');
    // Send file
    readfile($real);
    exit;
}

// Inline view request for small text files
$inlineContent = '';
$inlineTitle = '';
if (isset($_GET['view'])) {
    $file = basename((string)$_GET['view']);
    $full = join_path($current, $file);
    $real = realpath($full);
    if ($real && strpos($real, $BASE_DIR) === 0 && is_file($real) && is_readable($real)) {
        $maxShow = 1024 * 200; // 200 KB
        $size = filesize($real);
        $isText = true;
        // try detect binary by checking for NUL bytes in first 512 bytes
        $handle = fopen($real, 'rb');
        $chunk = fread($handle, 512);
        fclose($handle);
        if (strpos($chunk, "\0") !== false) $isText = false;

        if ($isText && $size <= $maxShow) {
            $inlineContent = file_get_contents($real);
            $inlineTitle = $file;
        } else {
            $inlineContent = "";
            $inlineTitle = '';
        }
    }
}

// Read directory items
$items = @scandir($current);
if ($items === false) {
    $items = [];
}

// Prepare rows with metadata
$rows = [];
foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    // skip hidden files unless requested
    if (!$showHidden && $item[0] === '.') continue;
    $full = join_path($current, $item);
    $isDir = is_dir($full);
    $rows[] = [
        'name' => $item,
        'is_dir' => $isDir,
        'size' => $isDir ? null : (is_file($full) ? filesize($full) : null),
        'mtime' => filemtime($full),
        'readable' => is_readable($full),
    ];
}

// Sort: directories first, then by name
usort($rows, function($a, $b) {
    if ($a['is_dir'] && !$b['is_dir']) return -1;
    if (!$a['is_dir'] && $b['is_dir']) return 1;
    return strcasecmp($a['name'], $b['name']);
});

// HTML output
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin File Browser</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root {
  --bg-light: #ffffff;
  --text-light: #222222;
  --muted-light: #666666;
  --th-light: #f4f4f4;
  --border-light: #eee;

  --bg-dark: #0f1113;
  --text-dark: #e6eef6;
  --muted-dark: #aab3bd;
  --th-dark: #16181a;
  --border-dark: #222529;
}

body { font-family: Arial, Helvetica, sans-serif; margin: 20px; }
body.light { background: var(--bg-light); color: var(--text-light); }
body.dark  { background: var(--bg-dark); color: var(--text-dark); }

/* font size options */
body.font-small { font-size: 13px; }
body.font-normal { font-size: 16px; }
body.font-large { font-size: 18px; }

h1 { margin-bottom: 0.2em; }
.table { width: 100%; border-collapse: collapse; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th, td { text-align: left; padding: 8px; border-bottom: 1px solid; font-size: 13px; }
body.light th, body.light td { border-bottom-color: var(--border-light); }
body.dark th, body.dark td { border-bottom-color: var(--border-dark); }

body.light th { background: var(--th-light); }
body.dark th  { background: var(--th-dark); }

a { color: #66b0ff; text-decoration: none; }
a:hover { text-decoration: underline; }
.breadcrumb { margin: 8px 0; font-size: 14px; }
.actions { white-space: nowrap; }
.actions a, .actions .action-btn { 
    display: inline-block;
    padding: 2px 6px;
    margin-right: 2px;
    font-size: 11px;
    border-radius: 3px;
    text-decoration: none;
    background: rgba(100,150,255,0.15);
    border: 1px solid rgba(100,150,255,0.3);
}
.actions a:hover, .actions .action-btn:hover { background: rgba(100,150,255,0.3); text-decoration: none; }
.actions .ai-dropdown { position: relative; display: inline-block; }
.actions .ai-toggle { cursor: pointer; background: rgba(147,51,234,0.15); border-color: rgba(147,51,234,0.3); }
.actions .ai-toggle:hover { background: rgba(147,51,234,0.3); }
.actions .ai-menu { 
    display: none; position: absolute; right: 0; top: 100%; z-index: 100;
    background: #1a1a2e; border: 1px solid #333; border-radius: 4px; min-width: 100px; box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}
.actions .ai-dropdown:hover .ai-menu { display: block; }
.actions .ai-menu a { display: block; padding: 6px 10px; margin: 0; border: none; border-radius: 0; background: none; }
.actions .ai-menu a:hover { background: rgba(147,51,234,0.3); }
.actions .ai-has-results { background: rgba(34,197,94,0.2); border-color: rgba(34,197,94,0.4); }
.actions .ai-menu-section { padding: 4px 10px; font-size: 10px; color: #888; text-transform: uppercase; }
.actions .ai-menu-divider { border-top: 1px solid #333; margin: 4px 0; }
.preview { padding:10px; border:1px solid; overflow:auto; max-height:60vh; white-space:pre-wrap; }
body.light .preview { background:#f8f8f8; border-color: #ddd; }
body.dark .preview  { background:#0b0d0e; border-color: var(--border-dark); }
.small { color: inherit; opacity: 0.8; font-size:12px; }

/* toggle link */
.theme-toggle { float: right; font-size: 13px; }
</style>
</head>
<body class="<?php echo e($themeClass); ?>">
<!-- Display Menu (with controls on the right) -->
<nav style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
  <div class="menu-left">
  <?php foreach ($default_menu as $menuItem):
      $label = isset($menuItem['label']) ? $menuItem['label'] : 'Menu';
      $url = isset($menuItem['url']) ? $menuItem['url'] : '#';
      echo '<a href="' . e($url) . '" style="margin-right:12px;">' . e($label) . '</a>';
  endforeach; ?>
  </div>
  <div class="menu-right" style="display:flex;align-items:center;gap:12px;">
    <a class="theme-toggle" href="<?php echo e(url_with_theme(($theme === 'dark') ? 'light' : 'dark')); ?>"><?php echo ($theme === 'dark' ? '🌞 Light' : '🌙 Dark'); ?></a>
    <div style="position:relative;">
      <a href="#" id="settingsToggle">⚙️</a>      
      <div id="settingsPanel" style="display:none;position:absolute;right:0;top:22px;background:#4d4b4bff;padding:8px;border:1px solid rgba(0,0,0,0.08);min-width:260px;z-index:999;">
        <form method="post" id="settingsForm">
          <div style="margin-bottom:8px;">
            <label for="themeSelect">Theme:</label>
            <select name="theme" id="themeSelect">
              <option value="auto" <?php echo ($theme === null ? 'selected' : ''); ?>>Auto</option>
              <option value="light" <?php echo ($theme === 'light' ? 'selected' : ''); ?>>Light</option>
              <option value="dark" <?php echo ($theme === 'dark' ? 'selected' : ''); ?>>Dark</option>
            </select>
          </div>
          <div style="margin-bottom:8px;">
            <label><input type="checkbox" name="show_hidden" value="1" <?php echo ($showHidden ? 'checked' : ''); ?>> Show hidden files</label>
          </div>
          <div style="margin-bottom:8px;">
            <label for="fontSize">Font size:</label>
            <select id="fontSize" name="font_size">
              <option value="small" <?php echo ($fontSize === 'small' ? 'selected' : ''); ?>>Small</option>
              <option value="normal" <?php echo ($fontSize === 'normal' ? 'selected' : ''); ?>>Normal</option>
              <option value="large" <?php echo ($fontSize === 'large' ? 'selected' : ''); ?>>Large</option>
            </select>
          </div>
          <div style="text-align:right;margin-top:6px;">
            <button type="button" id="settingsCancel">Cancel</button>
            <button type="submit" name="save_settings" value="1">Save</button>
          </div>
          <div style="margin-top:8px;text-align:left;">
            <a href="?manage_settings=1">Manage all settings</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</nav>
<!-- Display any errors -->
<?php if (!empty($errors)): ?>
    <div style="border:1px solid red; background:#ffe6e6; padding:10px; margin-bottom:10px;">
        <strong>Errors:</strong>
        <ul>
        <?php foreach ($errors as $err): ?>
            <li><?php echo e($err); ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<!-- Display any messages -->
<?php if (!empty($messages)): ?>
    <div style="border:1px solid green; background:#e6ffe6; padding:10px; margin-bottom:10px;">
        <strong>Messages:</strong>
        <ul>
        <?php foreach ($messages as $msg): ?>
            <li><?php echo e($msg); ?></li>
        <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>



<?php
// Breadcrumbs - show scan_path root name or basename of BASE_DIR
$parts = $relative === '' ? [] : explode(DIRECTORY_SEPARATOR, $relative);
$accum = '';
$rootLabel = $scan_path ? basename($BASE_DIR) : '/admin';
echo '<div class="breadcrumb">';
echo '<a href="' . dir_url_param('') . '">' . e($rootLabel) . '</a>';
foreach ($parts as $i => $p) {
    if ($p === '') continue;
    $accum = $accum === '' ? $p : ($accum . DIRECTORY_SEPARATOR . $p);
    echo ' / <a href="' . dir_url_param($accum) . '">' . e($p) . '</a>';
}
echo '</div>';

// Parent link
if ($current !== $BASE_DIR) {
    $parent = dirname($current);
    $parentRel = ltrim(substr($parent, strlen($BASE_DIR)), DIRECTORY_SEPARATOR);
    echo '<div><a href="' . dir_url_param($parentRel) . '">.. (Parent)</a></div>';
}

// Table
?>
<table>
<thead>
<tr><th>Name</th><th>Type</th><th>Size</th><th>Owner:Group (perms)</th><th>Actions</th></tr>
</thead>
<tbody>
<?php foreach ($rows as $r):
    $name = $r['name'];
    $isDir = $r['is_dir'];
    $size = $r['size'];
    $mtime = $r['mtime'];
    $readable = $r['readable'];
    $relPath = ($relative === '' ? $name : $relative . DIRECTORY_SEPARATOR . $name);

    // owner/group and permissions (short)
    $owner = '?';
    $group = '?';
    $perms = '----';
    $fullPath = join_path($current, $name);
    // fileowner/filegroup may return false; use @ to suppress warnings
    $uid = @fileowner($fullPath);
    if ($uid !== false) {
        if (function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid($uid);
            $owner = ($pw && isset($pw['name'])) ? $pw['name'] : $uid;
        } else {
            $owner = $uid;
        }
    }
    $gid = @filegroup($fullPath);
    if ($gid !== false) {
        if (function_exists('posix_getgrgid')) {
            $gr = @posix_getgrgid($gid);
            $group = ($gr && isset($gr['name'])) ? $gr['name'] : $gid;
        } else {
            $group = $gid;
        }
    }
    $mode = @fileperms($fullPath);
    if ($mode !== false) {
        // show last 4 digits (include special bits) e.g. 0755
        $perms = substr(sprintf('%o', $mode), -4);
    }

    $ownerInfo = $owner . ':' . $group . ' ' . $perms;

    echo '<tr>';
    echo '<td>' . ($isDir ? '<a href="' . dir_url_param($relPath) . '">' . e($name) . '</a>' : e($name)) . '</td>';
    echo '<td>' . ($isDir ? 'Directory' : 'File') . '</td>';
    echo '<td>' . ($size === null ? '-' : number_format($size) . ' bytes') . '</td>';
    echo '<td>' . e($ownerInfo) . '</td>';
    echo '<td class="actions">';
    if ($isDir) {
        echo '<a href="' . dir_url_param($relPath) . '" title="Enter">📂</a>';
    } else {
        if ($readable) {
            echo '<a href="' . raw_url($relative, $name) . '" target="_blank" title="Open raw">👁</a>';
            echo '<a href="' . raw_url($relative, $name, true) . '" title="Download">⬇</a>';
            // CodeWalker / AI actions (Summarize / Rewrite) via CodeWalker Admin
            // Note: run actions are handled inside codewalker.php (auth + CSRF)
            $codewalkerUrl = 'codewalker.php';
            $fileActions = getFileActions($cwDb, $fullPath);
            $hasExisting = $fileActions && count($fileActions) > 0;
            
            // View file in CodeWalker (preferred) or internal view
            if ($codewalkerUrl) {
                echo '<a href="' . e($codewalkerUrl) . '?view=file&path=' . rawurlencode($fullPath) . '" target="_blank" title="Open in CodeWalker">📄</a>';
            } else {
                echo '<a href="' . dir_url_param($relative) . '&view=' . rawurlencode($name) . '" title="View">📄</a>';
            }
            
            if ($codewalkerUrl) {
                $cwPath = rawurlencode($fullPath);
                echo '<span class="ai-dropdown">';
                // Show different icon if file has existing actions
                if ($hasExisting) {
                    $latestAction = $fileActions[0];
                    $actionCount = count($fileActions);
                    echo '<span class="action-btn ai-toggle ai-has-results" title="AI Actions (' . $actionCount . ' existing)">🤖✓</span>';
                } else {
                    echo '<span class="action-btn ai-toggle" title="AI Actions">🤖▾</span>';
                }
                echo '<div class="ai-menu">';
                // Show existing actions first if available
                if ($hasExisting) {
                    echo '<div class="ai-menu-section">Existing:</div>';
                    foreach ($fileActions as $fa) {
                        $actionLabel = $fa['action'] === 'summarize' ? '📝' : ($fa['action'] === 'rewrite' ? '✏️' : '📋');
                        $statusIcon = $fa['status'] === 'ok' ? '✓' : ($fa['status'] === 'error' ? '✗' : '⋯');
                        echo '<a href="' . e($codewalkerUrl) . '?view=action&id=' . (int)$fa['id'] . '" target="_blank">' . $actionLabel . ' #' . (int)$fa['id'] . ' ' . $statusIcon . '</a>';
                    }
                    echo '<div class="ai-menu-divider"></div>';
                    echo '<div class="ai-menu-section">New:</div>';
                }
                // Run buttons live on CodeWalker file view (auth + CSRF protected)
                echo '<a href="' . e($codewalkerUrl) . '?view=file&path=' . $cwPath . '#run" target="_blank">📝 Summarize</a>';
                echo '<a href="' . e($codewalkerUrl) . '?view=file&path=' . $cwPath . '#run" target="_blank">✏️ Rewrite</a>';
                echo '</div></span>';
            }
        } else {
            echo '<span class="small">-</span>';
        }
    }
    echo '</td>';
    echo '</tr>';
endforeach;
?>
</tbody>
</table>

<?php if ($inlineContent !== ''): ?>
    <h2>Viewing: <?php echo e($inlineTitle); ?></h2>
    <div class="preview"><?php echo e($inlineContent); ?></div>
<?php endif; ?>

<h2>Server Specs</h2>
<ul>
    <li><strong>PHP Version:</strong> <?php echo e(PHP_VERSION); ?></li>
    <li><strong>OS:</strong> <?php echo e(PHP_OS); ?></li>
    <li><strong>Upload Max Filesize:</strong> <?php echo e(ini_get('upload_max_filesize')); ?></li>
    <li><strong>Memory Limit:</strong> <?php echo e(ini_get('memory_limit')); ?></li>
</ul>

<footer class="small">Generated by Admin File Browser — Keep this file secure (don't expose in production)</footer>

<script>
(function(){
  // cookie helpers
  function setCookie(name, value, days){
    var d = new Date(); d.setTime(d.getTime() + (days*24*60*60*1000));
    document.cookie = name + '=' + encodeURIComponent(value) + ';path=/;expires=' + d.toUTCString();
  }
  function getCookie(name){
    var m = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : null;
  }

  // Auto-detect theme if server didn't set one
  var serverTheme = '<?php echo $theme === null ? '' : $theme; ?>';
  if (!serverTheme) {
    var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    var applied = prefersDark ? 'dark' : 'light';
    document.body.classList.remove('light','dark');
    document.body.classList.add(applied);
    // persist detected preference so page isn't flip-flopped next load
    setCookie('theme', applied, 30);
  }

  // Font size: read cookie and apply
  var font = getCookie('font_size') || 'normal';
  document.body.classList.remove('font-small','font-normal','font-large');
  document.body.classList.add('font-' + font);
  // set select value
  var sel = document.getElementById('fontSize');
  if (sel) sel.value = font;

  // settings toggle
  var toggle = document.getElementById('settingsToggle');
  var panel = document.getElementById('settingsPanel');
  if (toggle && panel) {
    toggle.addEventListener('click', function(e){
      e.preventDefault();
      panel.style.display = (panel.style.display === 'none') ? 'block' : 'none';
    });
  }

  // settings cancel button
  var cancel = document.getElementById('settingsCancel');
  if (cancel && panel) {
    cancel.addEventListener('click', function(e){
      e.preventDefault();
      panel.style.display = 'none';
    });
  }

  // font size select (applies immediately but Save will persist to DB)
  if (sel) {
    sel.addEventListener('change', function(){
      var v = this.value || 'normal';
      setCookie('font_size', v, 30);
      document.body.classList.remove('font-small','font-normal','font-large');
      document.body.classList.add('font-' + v);
    });
  }
})();
</script>

</body>
</html>


