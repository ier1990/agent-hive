<?php

require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
require_once __DIR__ . '/lib/codewalker_settings.php';
require_once __DIR__ . '/lib/codewalker_runner.php';

auth_require_admin();

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function mc_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mc_json($data, $status)
{
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function mc_ensure_csrf_token()
{
    if (empty($_SESSION['mc_viewer_csrf'])) {
        $_SESSION['mc_viewer_csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['mc_viewer_csrf'];
}

function mc_require_csrf_token()
{
    $expected = isset($_SESSION['mc_viewer_csrf']) ? (string)$_SESSION['mc_viewer_csrf'] : '';
    $actual = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
        mc_json(['ok' => false, 'error' => 'invalid_csrf'], 400);
    }
}

function mc_normalize_input_path($path)
{
    $path = str_replace('\\', '/', (string)$path);
    $path = preg_replace('#/+#', '/', $path);
    if ($path === null) {
        $path = '';
    }
    $path = trim($path);
    if ($path === '') {
        return __DIR__;
    }
    if ($path[0] !== '/') {
        $path = __DIR__ . '/' . $path;
    }
    return $path;
}

function mc_resolve_path($path)
{
    $candidate = mc_normalize_input_path($path);
    $real = @realpath($candidate);
    if (!is_string($real) || $real === '') {
        return false;
    }
    return str_replace('\\', '/', $real);
}

function mc_parent_path($path)
{
    $parent = dirname($path);
    if (!is_string($parent) || $parent === '') {
        $parent = '/';
    }
    return str_replace('\\', '/', $parent);
}

function mc_guess_lang($path)
{
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'php' => 'php',
        'phtml' => 'php',
        'py' => 'python',
        'js' => 'javascript',
        'ts' => 'typescript',
        'css' => 'css',
        'html' => 'html',
        'htm' => 'html',
        'json' => 'json',
        'md' => 'markdown',
        'sh' => 'bash',
        'log' => 'text',
        'txt' => 'text',
        'sql' => 'sql',
        'xml' => 'xml',
        'yml' => 'yaml',
        'yaml' => 'yaml',
        'ini' => 'ini',
        'conf' => 'ini',
    ];
    return isset($map[$ext]) ? $map[$ext] : 'text';
}

function mc_is_code_file($path)
{
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    $allowed = ['php', 'phtml', 'py', 'js', 'ts', 'css', 'html', 'htm', 'json', 'md', 'sh', 'sql', 'xml', 'yml', 'yaml', 'ini', 'conf', 'txt', 'log'];
    return in_array($ext, $allowed, true);
}

function mc_is_text_file($path)
{
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }
    if (mc_is_code_file($path)) {
        return true;
    }
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return false;
    }
    $chunk = fread($fh, 2048);
    fclose($fh);
    if ($chunk === false) {
        return false;
    }
    return strpos($chunk, "\0") === false;
}

function mc_media_kind($path)
{
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true)) {
        return 'image';
    }
    if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac'], true)) {
        return 'audio';
    }
    if (in_array($ext, ['mp4', 'webm', 'mov', 'mkv', 'avi', 'm4v'], true)) {
        return 'video';
    }
    if ($ext === 'pdf') {
        return 'pdf';
    }
    if (mc_is_text_file($path)) {
        return 'text';
    }
    return 'other';
}

function mc_filesize_label($size)
{
    $size = (float)$size;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $idx = 0;
    while ($size >= 1024 && $idx < count($units) - 1) {
        $size = $size / 1024;
        $idx++;
    }
    if ($idx === 0) {
        return (string)((int)$size) . ' ' . $units[$idx];
    }
    return number_format($size, 1) . ' ' . $units[$idx];
}

function mc_mime_type($path)
{
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)@finfo_file($finfo, $path);
            @finfo_close($finfo);
        }
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string)@mime_content_type($path);
    }
    if ($mime === '') {
        $mime = 'application/octet-stream';
    }
    return $mime;
}

function mc_list_dir($dir)
{
    $items = @scandir($dir);
    if (!is_array($items)) {
        return [];
    }
    $rows = [];
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $full = $dir . '/' . $name;
        $isDir = is_dir($full);
        $rows[] = [
            'name' => $name,
            'path' => str_replace('\\', '/', $full),
            'type' => $isDir ? 'dir' : 'file',
            'size' => $isDir ? null : (@filesize($full) ?: 0),
            'mtime' => @filemtime($full) ?: 0,
            'kind' => $isDir ? 'dir' : mc_media_kind($full),
            'readable' => is_readable($full),
        ];
    }
    usort($rows, function ($a, $b) {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'dir' ? -1 : 1;
        }
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });
    return $rows;
}

function mc_codewalker_db_path()
{
    $cfg = cw_settings_get_all();
    $path = isset($cfg['db_path']) ? (string)$cfg['db_path'] : '';
    return $path;
}

function mc_codewalker_pdo()
{
    $dbPath = mc_codewalker_db_path();
    if ($dbPath === '' || !is_file($dbPath)) {
        return null;
    }
    try {
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

function mc_latest_summary($path)
{
    $pdo = mc_codewalker_pdo();
    if (!$pdo) {
        return ['ok' => false, 'error' => 'codewalker_db_unavailable'];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT a.id, a.status, a.error, a.created_at, s.summary
             FROM actions a
             JOIN files f ON f.id = a.file_id
             LEFT JOIN summaries s ON s.action_id = a.id
             WHERE f.path = ? AND a.action = ?
             ORDER BY a.id DESC
             LIMIT 1'
        );
        $stmt->execute([$path, 'summarize']);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => true, 'found' => false];
        }
        return [
            'ok' => true,
            'found' => true,
            'action_id' => (int)$row['id'],
            'status' => (string)$row['status'],
            'error' => (string)($row['error'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'summary' => (string)($row['summary'] ?? ''),
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function mc_codewalker_scan_root()
{
    $cfg = cw_settings_get_all();
    $root = isset($cfg['scan_path']) ? (string)$cfg['scan_path'] : '';
    $real = $root !== '' ? @realpath($root) : false;
    return $real ? str_replace('\\', '/', $real) : '';
}

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';

if ($action === 'browse') {
    $path = mc_resolve_path(isset($_GET['path']) ? $_GET['path'] : __DIR__);
    if ($path === false || !is_dir($path) || !is_readable($path)) {
        mc_json(['ok' => false, 'error' => 'directory_not_found'], 404);
    }
    mc_json([
        'ok' => true,
        'path' => $path,
        'parent' => mc_parent_path($path),
        'entries' => mc_list_dir($path),
    ], 200);
}

if ($action === 'preview') {
    $path = mc_resolve_path(isset($_GET['path']) ? $_GET['path'] : '');
    if ($path === false || !is_file($path) || !is_readable($path)) {
        mc_json(['ok' => false, 'error' => 'file_not_found'], 404);
    }
    $kind = mc_media_kind($path);
    $payload = [
        'ok' => true,
        'path' => $path,
        'name' => basename($path),
        'kind' => $kind,
        'size' => @filesize($path) ?: 0,
        'mtime' => @filemtime($path) ?: 0,
        'mime' => mc_mime_type($path),
        'lang' => mc_guess_lang($path),
        'raw_url' => '?action=raw&path=' . rawurlencode($path),
        'is_code' => mc_is_code_file($path),
    ];
    if ($kind === 'text') {
        $size = @filesize($path) ?: 0;
        if ($size > 1024 * 1024) {
            $payload['content_error'] = 'File too large to preview inline (> 1 MB).';
        } else {
            $content = @file_get_contents($path);
            if ($content === false) {
                $payload['content_error'] = 'Unable to read file.';
            } else {
                $payload['content'] = $content;
            }
        }
    }
    $payload['summary'] = mc_latest_summary($path);
    $payload['scan_root'] = mc_codewalker_scan_root();
    mc_json($payload, 200);
}

if ($action === 'raw') {
    $path = mc_resolve_path(isset($_GET['path']) ? $_GET['path'] : '');
    if ($path === false || !is_file($path) || !is_readable($path)) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
    $mime = mc_mime_type($path);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)(@filesize($path) ?: 0));
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    readfile($path);
    exit;
}

if ($action === 'run_summary') {
    mc_require_csrf_token();
    $path = mc_resolve_path(isset($_POST['path']) ? $_POST['path'] : '');
    if ($path === false || !is_file($path) || !is_readable($path)) {
        mc_json(['ok' => false, 'error' => 'file_not_found'], 404);
    }
    if (!mc_is_code_file($path)) {
        mc_json(['ok' => false, 'error' => 'summary_not_supported_for_file_type'], 400);
    }
    try {
        $result = cw_run_on_file($path, 'summarize');
        $summary = mc_latest_summary($path);
        mc_json([
            'ok' => true,
            'result' => $result,
            'summary' => $summary,
            'scan_root' => mc_codewalker_scan_root(),
        ], 200);
    } catch (Throwable $e) {
        mc_json([
            'ok' => false,
            'error' => $e->getMessage(),
            'scan_root' => mc_codewalker_scan_root(),
        ], 500);
    }
}

$initialPath = mc_resolve_path(isset($_GET['path']) ? $_GET['path'] : __DIR__);
if ($initialPath === false || !is_dir($initialPath)) {
    $initialPath = str_replace('\\', '/', __DIR__);
}
$csrf = mc_ensure_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MC Viewer</title>
<link rel="stylesheet" href="lib/admin_dark.css">
<style>
* { box-sizing: border-box; }
body { margin: 0; background: #0f1319; color: #d7dde6; font-family: "Segoe UI", Tahoma, sans-serif; }
a { color: #7cc7ff; }
button, input { font: inherit; }
.shell { display: grid; grid-template-columns: 360px 1fr; height: 100vh; }
.sidebar { border-right: 1px solid #273241; background: #141b23; display: flex; flex-direction: column; min-width: 0; }
.main { display: flex; flex-direction: column; min-width: 0; min-height: 0; }
.toolbar { padding: 12px; border-bottom: 1px solid #273241; background: #10161d; }
.toolbar h1 { margin: 0 0 8px 0; font-size: 18px; }
.toolbar .sub { color: #8a96a7; font-size: 12px; }
.pathbar { display: flex; gap: 8px; margin-top: 10px; }
.pathbar input { flex: 1; min-width: 0; background: #0c1016; color: #e6edf5; border: 1px solid #334255; border-radius: 8px; padding: 8px 10px; }
.btn { background: #1c6fdc; color: #fff; border: 1px solid #2a7be5; border-radius: 8px; padding: 8px 12px; cursor: pointer; }
.btn.secondary { background: #1a2230; border-color: #334255; color: #d7dde6; }
.btn.warn { background: #7a3f10; border-color: #a75a1c; }
.quick { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.quick a { display: inline-block; text-decoration: none; padding: 6px 10px; border-radius: 999px; border: 1px solid #334255; background: #18212b; color: #b8c8da; font-size: 12px; }
.list { overflow: auto; flex: 1; padding: 8px; }
.entry { display: grid; grid-template-columns: 20px 1fr auto; gap: 8px; padding: 8px 10px; border-radius: 8px; color: inherit; text-decoration: none; cursor: pointer; }
.entry:hover, .entry.active { background: #213145; }
.entry .meta { color: #8290a2; font-size: 11px; }
.entry .badge { color: #9fd2ff; font-size: 11px; }
.pane-head { padding: 12px; border-bottom: 1px solid #273241; background: #111821; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.pane-head .title { font-weight: 600; }
.pane-head .meta { color: #8a96a7; font-size: 12px; }
.pane-head .actions { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }
.viewer { flex: 1; min-height: 0; display: grid; grid-template-columns: 1.3fr .9fr; }
.preview, .summary { min-width: 0; min-height: 0; overflow: auto; }
.preview { padding: 16px; background: #0d1218; }
.summary { padding: 16px; border-left: 1px solid #273241; background: #10161d; }
.empty { color: #7f8b9a; font-style: italic; padding: 18px; }
pre.code { margin: 0; white-space: pre-wrap; word-break: break-word; background: #0a0f14; border: 1px solid #273241; border-radius: 12px; padding: 14px; color: #d8e1ea; font-family: Consolas, Monaco, monospace; font-size: 13px; line-height: 1.45; }
.summary pre { margin: 0; white-space: pre-wrap; word-break: break-word; background: #0a0f14; border: 1px solid #273241; border-radius: 12px; padding: 14px; color: #d8e1ea; font-family: Consolas, Monaco, monospace; font-size: 12px; line-height: 1.45; }
.media-wrap img, .media-wrap video, .media-wrap iframe, .media-wrap audio { width: 100%; max-width: 100%; border: 1px solid #273241; border-radius: 12px; background: #0a0f14; }
.media-wrap audio { padding: 12px; }
.kv { display: grid; grid-template-columns: 120px 1fr; gap: 8px; font-size: 12px; margin-bottom: 12px; }
.kv .k { color: #8a96a7; }
.notice { margin-top: 12px; padding: 10px 12px; border: 1px solid #5e4732; background: #2d2118; color: #f4d6b5; border-radius: 10px; font-size: 12px; }
.ok { border-color: #284f34; background: #16261b; color: #bce7c7; }
@media (max-width: 1000px) {
  .shell { grid-template-columns: 1fr; grid-template-rows: 320px 1fr; }
  .viewer { grid-template-columns: 1fr; grid-template-rows: 1fr auto; }
  .summary { border-left: 0; border-top: 1px solid #273241; }
}
</style>
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="toolbar">
      <h1>MC Viewer</h1>
      <div class="sub">Starts in <code><?php echo mc_h(__DIR__); ?></code>. Browse anywhere readable, preview media, inspect source, and use CodeWalker summaries.</div>
      <div class="pathbar">
        <input id="pathInput" type="text" value="<?php echo mc_h($initialPath); ?>" spellcheck="false">
        <button id="goBtn" class="btn" type="button">Go</button>
        <button id="upBtn" class="btn secondary" type="button">Up</button>
      </div>
      <div class="quick">
        <a href="#" data-jump="<?php echo mc_h(__DIR__); ?>">admin</a>
        <a href="#" data-jump="<?php echo mc_h(APP_ROOT); ?>">app root</a>
        <a href="#" data-jump="/web/html">/web/html</a>
        <a href="#" data-jump="<?php echo mc_h(PRIVATE_ROOT); ?>">private</a>
        <a href="#" data-jump="/">/</a>
      </div>
    </div>
    <div class="list" id="entryList"></div>
  </aside>
  <main class="main">
    <div class="pane-head">
      <div>
        <div class="title" id="selectedTitle">Select a file or folder</div>
        <div class="meta" id="selectedMeta">Directory listing will appear on the left.</div>
      </div>
      <div class="actions">
        <a id="openRawBtn" class="btn secondary" href="#" target="_blank" style="display:none">Open Raw</a>
        <button id="refreshSummaryBtn" class="btn secondary" type="button" style="display:none">Pull Summary</button>
        <button id="runSummaryBtn" class="btn warn" type="button" style="display:none">Create AI Summary</button>
      </div>
    </div>
    <div class="viewer">
      <section class="preview" id="previewPane">
        <div class="empty">Choose something from the left to preview it here.</div>
      </section>
      <aside class="summary" id="summaryPane">
        <div class="empty">AI summary details will appear here for code/text files.</div>
      </aside>
    </div>
  </main>
</div>

<script>
var csrf = <?php echo json_encode($csrf); ?>;
var currentDir = <?php echo json_encode($initialPath); ?>;
var selectedPath = '';

function escHtml(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function fmtBytes(size) {
  var n = Number(size || 0);
  if (!isFinite(n)) return '';
  var units = ['B', 'KB', 'MB', 'GB', 'TB'];
  var idx = 0;
  while (n >= 1024 && idx < units.length - 1) {
    n = n / 1024;
    idx++;
  }
  return (idx === 0 ? String(Math.round(n)) : n.toFixed(1)) + ' ' + units[idx];
}

function fmtTime(ts) {
  if (!ts) return '';
  var d = new Date(Number(ts) * 1000);
  if (isNaN(d.getTime())) return '';
  return d.toLocaleString();
}

function setNotice(targetId, text, ok) {
  var el = document.getElementById(targetId);
  el.innerHTML = '<div class="notice' + (ok ? ' ok' : '') + '">' + escHtml(text) + '</div>';
}

function renderEntries(entries) {
  var box = document.getElementById('entryList');
  if (!entries || !entries.length) {
    box.innerHTML = '<div class="empty">Empty directory.</div>';
    return;
  }
  var html = '';
  for (var i = 0; i < entries.length; i++) {
    var item = entries[i];
    var icon = item.type === 'dir' ? '📁' : (item.kind === 'image' ? '🖼️' : (item.kind === 'audio' ? '🎵' : (item.kind === 'video' ? '🎬' : (item.kind === 'pdf' ? '📄' : '🧾'))));
    var meta = item.type === 'dir' ? 'folder' : ((item.kind || 'file') + ' • ' + fmtBytes(item.size) + ' • ' + fmtTime(item.mtime));
    html += '<div class="entry' + (selectedPath === item.path ? ' active' : '') + '" data-path="' + escHtml(item.path) + '" data-type="' + escHtml(item.type) + '">'
      + '<div>' + icon + '</div>'
      + '<div><div>' + escHtml(item.name) + '</div><div class="meta">' + escHtml(meta) + '</div></div>'
      + '<div class="badge">' + escHtml(item.type === 'dir' ? 'open' : 'view') + '</div>'
      + '</div>';
  }
  box.innerHTML = html;
  box.querySelectorAll('.entry').forEach(function(node) {
    node.addEventListener('click', function() {
      var path = this.getAttribute('data-path') || '';
      var type = this.getAttribute('data-type') || '';
      if (type === 'dir') {
        loadDir(path);
      } else {
        loadPreview(path);
      }
    });
  });
}

async function loadDir(path) {
  var url = '?action=browse&path=' + encodeURIComponent(path || currentDir);
  var res = await fetch(url);
  var data = await res.json();
  if (!data.ok) {
    document.getElementById('entryList').innerHTML = '<div class="empty">Unable to open directory.</div>';
    document.getElementById('selectedTitle').textContent = 'Directory error';
    document.getElementById('selectedMeta').textContent = data.error || 'Unable to open directory';
    return;
  }
  currentDir = data.path;
  selectedPath = '';
  document.getElementById('pathInput').value = data.path;
  document.getElementById('selectedTitle').textContent = data.path;
  document.getElementById('selectedMeta').textContent = (data.entries ? data.entries.length : 0) + ' entries';
  document.getElementById('previewPane').innerHTML = '<div class="empty">Select a file from the left to preview it.</div>';
  document.getElementById('summaryPane').innerHTML = '<div class="empty">AI summary details will appear here for code/text files.</div>';
  document.getElementById('openRawBtn').style.display = 'none';
  document.getElementById('refreshSummaryBtn').style.display = 'none';
  document.getElementById('runSummaryBtn').style.display = 'none';
  document.getElementById('upBtn').setAttribute('data-parent', data.parent || '/');
  renderEntries(data.entries || []);
  history.replaceState(null, '', '?path=' + encodeURIComponent(data.path));
}

function renderSummary(data) {
  var pane = document.getElementById('summaryPane');
  var summary = data.summary || {};
  var html = '';
  html += '<div class="kv">'
    + '<div class="k">Path</div><div>' + escHtml(data.path || '') + '</div>'
    + '<div class="k">Type</div><div>' + escHtml(data.kind || '') + (data.lang ? ' • ' + escHtml(data.lang) : '') + '</div>'
    + '<div class="k">Size</div><div>' + escHtml(fmtBytes(data.size)) + '</div>'
    + '<div class="k">Modified</div><div>' + escHtml(fmtTime(data.mtime)) + '</div>'
    + '</div>';
  if (!data.is_code) {
    html += '<div class="empty">AI summaries are only enabled for code/text-style files.</div>';
    pane.innerHTML = html;
    return;
  }
  if (!summary.ok) {
    html += '<div class="notice">Summary lookup failed: ' + escHtml(summary.error || 'unknown error') + '</div>';
  } else if (!summary.found) {
    html += '<div class="empty">No saved CodeWalker summary for this file yet.</div>';
  } else {
    html += '<div class="kv">'
      + '<div class="k">Action ID</div><div>#' + escHtml(summary.action_id) + '</div>'
      + '<div class="k">Status</div><div>' + escHtml(summary.status || '') + '</div>'
      + '<div class="k">Created</div><div>' + escHtml(summary.created_at || '') + '</div>'
      + '</div>';
    if (summary.error) {
      html += '<div class="notice">Last summary run error: ' + escHtml(summary.error) + '</div>';
    }
    if (summary.summary) {
      html += '<pre>' + escHtml(summary.summary) + '</pre>';
    }
  }
  if (data.scan_root && String(data.path || '').indexOf(String(data.scan_root)) !== 0) {
    html += '<div class="notice">This file is outside CodeWalker scan_path (' + escHtml(data.scan_root) + '), so "Create AI Summary" may fail until the scan root includes it.</div>';
  }
  pane.innerHTML = html;
}

function renderPreview(data) {
  var pane = document.getElementById('previewPane');
  if (data.kind === 'image') {
    pane.innerHTML = '<div class="media-wrap"><img src="' + escHtml(data.raw_url) + '" alt=""></div>';
  } else if (data.kind === 'audio') {
    pane.innerHTML = '<div class="media-wrap"><audio controls src="' + escHtml(data.raw_url) + '"></audio></div>';
  } else if (data.kind === 'video') {
    pane.innerHTML = '<div class="media-wrap"><video controls src="' + escHtml(data.raw_url) + '"></video></div>';
  } else if (data.kind === 'pdf') {
    pane.innerHTML = '<div class="media-wrap"><iframe src="' + escHtml(data.raw_url) + '" style="min-height:78vh"></iframe></div>';
  } else if (data.kind === 'text') {
    if (data.content_error) {
      pane.innerHTML = '<div class="notice">' + escHtml(data.content_error) + '</div>';
    } else {
      pane.innerHTML = '<pre class="code">' + escHtml(data.content || '') + '</pre>';
    }
  } else {
    pane.innerHTML = '<div class="empty">No inline preview for this file type. Use "Open Raw" if you want the browser to handle it.</div>';
  }
}

async function loadPreview(path) {
  var url = '?action=preview&path=' + encodeURIComponent(path);
  var res = await fetch(url);
  var data = await res.json();
  if (!data.ok) {
    document.getElementById('selectedTitle').textContent = 'Preview error';
    document.getElementById('selectedMeta').textContent = data.error || 'Unable to preview file';
    setNotice('previewPane', data.error || 'Unable to preview file', false);
    return;
  }
  selectedPath = data.path;
  document.getElementById('selectedTitle').textContent = data.name || data.path;
  document.getElementById('selectedMeta').textContent = (data.kind || 'file') + ' • ' + fmtBytes(data.size) + ' • ' + fmtTime(data.mtime);
  document.getElementById('openRawBtn').href = data.raw_url || '#';
  document.getElementById('openRawBtn').style.display = 'inline-block';
  document.getElementById('refreshSummaryBtn').style.display = data.is_code ? 'inline-block' : 'none';
  document.getElementById('runSummaryBtn').style.display = data.is_code ? 'inline-block' : 'none';
  renderPreview(data);
  renderSummary(data);
  document.querySelectorAll('.entry').forEach(function(node) {
    node.classList.toggle('active', node.getAttribute('data-path') === selectedPath);
  });
}

async function refreshSummary() {
  if (!selectedPath) return;
  await loadPreview(selectedPath);
}

async function runSummary() {
  if (!selectedPath) return;
  var form = new FormData();
  form.append('csrf', csrf);
  form.append('path', selectedPath);
  var res = await fetch('?action=run_summary', { method: 'POST', body: form });
  var data = await res.json();
  if (!data.ok) {
    setNotice('summaryPane', data.error || 'Summary run failed', false);
    return;
  }
  await loadPreview(selectedPath);
}

document.getElementById('goBtn').addEventListener('click', function() {
  loadDir(document.getElementById('pathInput').value);
});
document.getElementById('pathInput').addEventListener('keydown', function(ev) {
  if (ev.key === 'Enter') {
    ev.preventDefault();
    loadDir(this.value);
  }
});
document.getElementById('upBtn').addEventListener('click', function() {
  var parent = this.getAttribute('data-parent') || '/';
  loadDir(parent);
});
document.getElementById('refreshSummaryBtn').addEventListener('click', refreshSummary);
document.getElementById('runSummaryBtn').addEventListener('click', runSummary);
document.querySelectorAll('[data-jump]').forEach(function(link) {
  link.addEventListener('click', function(ev) {
    ev.preventDefault();
    loadDir(this.getAttribute('data-jump') || '');
  });
});

loadDir(currentDir);
</script>
</body>
</html>
