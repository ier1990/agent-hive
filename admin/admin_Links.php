<?php
// Admin Links Manager: CRUD for title/url/target and display as nice cards

// Enable error reporting (adjust as needed in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Session for CSRF + flash
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Preserve embed behavior (so we stay within iframe)
$IS_EMBED = in_array(strtolower($_GET['embed'] ?? ''), ['1','true','yes'], true);
$EMBED_QS = $IS_EMBED ? 'embed=1' : '';

// CSRF helpers
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_input() {
    $t = $_SESSION['csrf_token'] ?? '';
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">';
}
function verify_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

// Simple esc
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Connect to SQLite and ensure schema
$dbPath = '/web/private/db/admin_links.db';
$db = new SQLite3($dbPath);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec("CREATE TABLE IF NOT EXISTS links (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL,
    url        TEXT    NOT NULL,
    target     TEXT    NOT NULL DEFAULT '_self',  -- allowed: _self, _blank
    is_active  INTEGER NOT NULL DEFAULT 1,
    position   INTEGER NOT NULL DEFAULT 0,
    created_at TEXT    NOT NULL DEFAULT (CURRENT_TIMESTAMP),
    updated_at TEXT    NOT NULL DEFAULT (CURRENT_TIMESTAMP),
    CHECK (target IN ('_self', '_blank'))
)");



/* Keep updated_at fresh on any row update */
$db->exec("CREATE TRIGGER IF NOT EXISTS trg_links_updated_at
AFTER UPDATE ON links
FOR EACH ROW
BEGIN
  UPDATE links SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;
");

// Flash messaging
function flash($type, $msg) {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
function take_flashes() {
    $out = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $out;
}

// Normalize/validate inputs
function normalize_target($t) {
    $t = strtolower(trim((string)$t));
    return in_array($t, ['_self','_blank'], true) ? $t : '_self';
}

// Handle POST actions (create/update/delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token  = $_POST['csrf_token'] ?? '';
    if (!verify_csrf($token)) {
        flash('error', 'Invalid CSRF token.');
    } else {
        if ($action === 'create' || $action === 'update') {
            $title  = trim($_POST['title'] ?? '');
            $url    = trim($_POST['url'] ?? '');
            $target = normalize_target($_POST['target'] ?? '_self');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $position  = (int)($_POST['position'] ?? 0);

            if ($title === '' || $url === '') {
                flash('error', 'Title and URL are required.');
            } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
                flash('error', 'Please provide a valid URL (include http/https).');
            } else {
                if ($action === 'create') {
                    $stmt = $db->prepare('INSERT INTO links (title, url, target, is_active, position, created_at, updated_at) VALUES (?,?,?,?,?, datetime("now"), datetime("now"))');
                    $stmt->bindValue(1, $title, SQLITE3_TEXT);
                    $stmt->bindValue(2, $url, SQLITE3_TEXT);
                    $stmt->bindValue(3, $target, SQLITE3_TEXT);
                    $stmt->bindValue(4, $is_active, SQLITE3_INTEGER);
                    $stmt->bindValue(5, $position, SQLITE3_INTEGER);
                    $ok = $stmt->execute();
                    if ($ok) { flash('success', 'Link added.'); } else { flash('error', 'Failed to add link.'); }
                } else { // update
                    $id = (int)($_POST['id'] ?? 0);
                    $stmt = $db->prepare('UPDATE links SET title=?, url=?, target=?, is_active=?, position=?, updated_at=datetime("now") WHERE id=?');
                    $stmt->bindValue(1, $title, SQLITE3_TEXT);
                    $stmt->bindValue(2, $url, SQLITE3_TEXT);
                    $stmt->bindValue(3, $target, SQLITE3_TEXT);
                    $stmt->bindValue(4, $is_active, SQLITE3_INTEGER);
                    $stmt->bindValue(5, $position, SQLITE3_INTEGER);
                    $stmt->bindValue(6, $id, SQLITE3_INTEGER);
                    $ok = $stmt->execute();
                    if ($ok) { flash('success', 'Link updated.'); } else { flash('error', 'Failed to update link.'); }
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare('DELETE FROM links WHERE id=?');
            $stmt->bindValue(1, $id, SQLITE3_INTEGER);
            $ok = $stmt->execute();
            if ($ok) { flash('success', 'Link deleted.'); } else { flash('error', 'Failed to delete link.'); }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare('UPDATE links SET is_active = CASE is_active WHEN 1 THEN 0 ELSE 1 END, updated_at=datetime("now") WHERE id=?');
            $stmt->bindValue(1, $id, SQLITE3_INTEGER);
            $ok = $stmt->execute();
            if ($ok) { flash('success', 'Link status toggled.'); } else { flash('error', 'Failed to toggle link.'); }
        }
    }
    // PRG redirect
    $qs = $EMBED_QS;
    if (!empty($_POST['return'])) { $qs = trim($qs . '&' . $_POST['return'], '&'); }
    header('Location: admin_links.php' . ($qs ? ('?' . $qs) : ''));
    exit;
}

// Fetch link for editing if requested
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit = null;
if ($edit_id > 0) {
    $stmt = $db->prepare('SELECT * FROM links WHERE id=?');
    $stmt->bindValue(1, $edit_id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $edit = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
}

// Get all links (active first, then position desc, then newest)
$links = [];
$res = $db->query('SELECT * FROM links ORDER BY is_active DESC, position DESC, created_at DESC');
while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $links[] = $row; }

// Drain flashes
$flashes = take_flashes();
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Links</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .gradient-bg { background: linear-gradient(135deg, #0ea5e9 0%, #6366f1 100%); }
        .card-hover { transition: transform .2s ease, box-shadow .2s ease; }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); }
    </style>
    <meta http-equiv="Content-Security-Policy" content="default-src 'self' 'unsafe-inline' https: data:;">
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="gradient-bg text-white py-6 mb-8">
        <div class="container mx-auto px-4">
            <h1 class="text-3xl font-bold mb-1">🔗 Admin Links</h1>
            <p class="opacity-90">Create, curate, and launch your admin tools</p>
        </div>
    </div>

    <div class="container mx-auto px-4">
        <?php if (!empty($flashes)): ?>
            <div class="space-y-2 mb-6">
                <?php foreach ($flashes as $f): ?>
                    <div class="px-4 py-3 rounded-md text-sm <?php echo $f['type']==='success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'; ?>">
                        <?php echo h($f['msg']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Add/Edit Form -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg shadow p-6 card-hover">
                    <h2 class="text-xl font-semibold mb-4"><?php echo $edit ? 'Edit Link' : 'Add New Link'; ?></h2>
                    <form method="post" class="space-y-4">
                        <?php csrf_input(); ?>
                        <?php if ($IS_EMBED): ?>
                            <input type="hidden" name="return" value="embed=1">
                        <?php endif; ?>
                        <input type="hidden" name="action" value="<?php echo $edit ? 'update' : 'create'; ?>">
                        <?php if ($edit): ?><input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>"><?php endif; ?>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                            <input type="text" name="title" value="<?php echo h($edit['title'] ?? ''); ?>" required class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">URL</label>
                            <input type="url" name="url" value="<?php echo h($edit['url'] ?? ''); ?>" placeholder="https://example.com" required class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Open In</label>
                                <select name="target" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <?php $t = strtolower($edit['target'] ?? '_self'); ?>
                                    <option value="_self" <?php echo $t === '_self' ? 'selected' : ''; ?>>In Frame</option>
                                    <option value="_blank" <?php echo $t === '_blank' ? 'selected' : ''; ?>>New Tab</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Position</label>
                                <input type="number" name="position" value="<?php echo (int)($edit['position'] ?? 0); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                        <div class="flex items-center">
                            <?php $ia = (int)($edit['is_active'] ?? 1); ?>
                            <input id="is_active" type="checkbox" name="is_active" value="1" class="mr-2" <?php echo $ia ? 'checked' : ''; ?>>
                            <label for="is_active" class="text-sm text-gray-700">Active</label>
                        </div>
                        <div class="flex gap-3">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md">
                                <?php echo $edit ? 'Save Changes' : 'Add Link'; ?>
                            </button>
                            <?php if ($edit): ?>
                                <a href="admin_links.php<?php echo $IS_EMBED ? '?embed=1' : ''; ?>" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-medium py-2 px-4 rounded-md">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Links Grid -->
            <div class="lg:col-span-2">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xl font-semibold">Your Links</h2>
                    <span class="text-sm text-gray-500"><?php echo count($links); ?> total</span>
                </div>
                <?php if (empty($links)): ?>
                    <div class="bg-yellow-50 text-yellow-800 border border-yellow-200 rounded-md p-4">
                        No links yet. Add your first link using the form on the left.
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <?php foreach ($links as $link): ?>
                            <div class="bg-white rounded-lg shadow p-5 card-hover">
                                <div class="flex items-start justify-between mb-3">
                                    <h3 class="font-semibold text-gray-800 text-lg truncate pr-2"><?php echo h($link['title']); ?></h3>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs px-2 py-1 rounded-full <?php echo $link['target']==='_blank' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'; ?>"><?php echo $link['target']==='_blank' ? 'New Tab' : 'In Frame'; ?></span>
                                        <?php if ((int)$link['is_active'] !== 1): ?>
                                            <span class="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-600">Inactive</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <p class="text-sm text-gray-600 truncate mb-4" title="<?php echo h($link['url']); ?>"><?php echo h($link['url']); ?></p>
                                <div class="flex items-center justify-between text-sm text-gray-500 mb-4">
                                    <span>Position: <?php echo (int)$link['position']; ?></span>
                                    <span>Added: <?php echo h(substr((string)$link['created_at'], 0, 16)); ?></span>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <a href="<?php echo h($link['url']); ?>" target="<?php echo h($link['target']); ?>" class="inline-flex items-center gap-1 bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-md">
                                        Open
                                        <span class="text-xs opacity-90"><?php echo $link['target']==='_blank' ? '↗' : '⤶'; ?></span>
                                    </a>
                                    <a href="admin_links.php?<?php echo trim(($IS_EMBED ? 'embed=1&' : '') . 'edit=' . (int)$link['id']); ?>" class="inline-flex items-center gap-1 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-md">Edit</a>
                                    <form method="post" onsubmit="return confirm('Delete this link?');" class="inline">
                                        <?php csrf_input(); ?>
                                        <?php if ($IS_EMBED): ?><input type="hidden" name="return" value="embed=1"><?php endif; ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$link['id']; ?>">
                                        <button type="submit" class="inline-flex items-center gap-1 bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded-md">Delete</button>
                                    </form>
                                    <form method="post" class="inline">
                                        <?php csrf_input(); ?>
                                        <?php if ($IS_EMBED): ?><input type="hidden" name="return" value="embed=1"><?php endif; ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo (int)$link['id']; ?>">
                                        <button type="submit" class="inline-flex items-center gap-1 bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-1.5 rounded-md"><?php echo (int)$link['is_active']===1 ? 'Disable' : 'Enable'; ?></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>





