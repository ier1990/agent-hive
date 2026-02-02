<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Root browsing base and known scripts directory used by admin_Scripts.php
$BASE_ROOT    = '/web';
$SCRIPTS_ROOT = '/web/private/scripts';
$DB_PATH      = '/web/private/db/scripts_knowledge.db';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function json_out($data, $status=200){
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

// Resolve a user-supplied path (relative to BASE_ROOT) to an absolute path within BASE_ROOT.
function resolve_path_within($baseRoot, $reqPath){
  $base = rtrim($baseRoot, '/');
  if ($reqPath === '' || $reqPath === null) { $reqPath = '/'; }
  // Allow either absolute within base or relative
  if ($reqPath[0] !== '/') { $reqPath = '/'.$reqPath; }
  $candidate = $base . $reqPath; // e.g., /web + /private
  $realBase = realpath($base);
  $real = $candidate !== '' ? realpath($candidate) : false;
  if ($real === false || $realBase === false) { return [false, 'Path not found']; }
  // Ensure $real is inside $realBase
  if (strpos($real, $realBase) !== 0) { return [false, 'Path outside base']; }
  return [$real, null];
}

// Lightweight file type guessing for syntax highlight
function guess_lang_by_ext($path){
  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  $map = [
    'php'=>'php','py'=>'python','js'=>'javascript','ts'=>'typescript','json'=>'json','yml'=>'yaml','yaml'=>'yaml','md'=>'markdown',
    'html'=>'html','htm'=>'html','css'=>'css','sh'=>'bash','conf'=>'ini','ini'=>'ini','txt'=>'plaintext','log'=>'plaintext','xml'=>'xml','sql'=>'sql'
  ];
  return $map[$ext] ?? 'plaintext';
}

// Serve JSON endpoints for tree/listing and file content
$action = $_GET['action'] ?? '';
if ($action === 'ls') {
  $req = (string)($_GET['path'] ?? '/');
  list($abs, $err) = resolve_path_within($BASE_ROOT, $req);
  if ($abs === false) json_out(['error'=>$err], 400);
  if (!is_dir($abs)) json_out(['error'=>'Not a directory'], 400);
  $items = @scandir($abs);
  if ($items === false) json_out(['error'=>'Cannot read directory'], 500);
  $out = [];
  $count = 0; $max = 1000; // safety cap
  foreach ($items as $name) {
    if ($name === '.' || $name === '..') continue;
    if ($name[0] === '.') continue; // hide dotfiles by default
    $path = $abs . DIRECTORY_SEPARATOR . $name;
    $isDir = is_dir($path);
    $stat = @stat($path) ?: [];
    $rel = substr($path, strlen(realpath($BASE_ROOT)));
    if ($rel === false) { $rel = '/'; }
    $out[] = [
      'name' => $name,
      'path' => str_replace('\\','/',$rel),
      'type' => $isDir ? 'dir' : 'file',
      'size' => $isDir ? null : ($stat['size'] ?? null),
      'mtime'=> isset($stat['mtime']) ? gmdate('c', $stat['mtime']) : null,
      'lang' => $isDir ? null : guess_lang_by_ext($path),
      'hasChildren' => $isDir ? (count(array_diff(@scandir($path) ?: [], ['.','..']))>0) : false,
    ];
    $count++; if ($count >= $max) break;
  }
  // Natural-ish order: folders first, then files by name
  usort($out, function($a,$b){
    if ($a['type'] !== $b['type']) return $a['type']==='dir' ? -1 : 1;
    return strcasecmp($a['name'],$b['name']);
  });
  json_out(['path'=>str_replace('\\','/', substr($abs, strlen(realpath($BASE_ROOT)))), 'entries'=>$out]);
}
if ($action === 'read') {
  $req = (string)($_GET['path'] ?? '/');
  list($abs, $err) = resolve_path_within($BASE_ROOT, $req);
  if ($abs === false) json_out(['error'=>$err], 400);
  if (!is_file($abs)) json_out(['error'=>'Not a file'], 400);
  $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
  $allowed = ['php','py','js','ts','json','yml','yaml','md','html','htm','css','sh','conf','ini','txt','log','xml','sql'];
  if (!in_array($ext, $allowed, true)) json_out(['error'=>'Preview not allowed for this file type'], 400);
  $size = @filesize($abs);
  if ($size !== false && $size > 1024*1024) json_out(['error'=>'File too large to preview (>1 MiB)','size'=>$size], 400);
  $content = @file_get_contents($abs);
  if ($content === false) json_out(['error'=>'Cannot read file'], 500);
  // Basic binary guard: if it contains many NUL bytes, treat as binary
  if (strpos($content, "\0") !== false) json_out(['error'=>'Binary file not previewable'], 400);
  json_out([
    'path' => str_replace('\\','/',$abs),
    'rel'  => str_replace('\\','/', substr($abs, strlen(realpath($BASE_ROOT)))),
    'lang' => guess_lang_by_ext($abs),
    'size' => $size,
    'content' => $content,
  ]);
}
if ($action === 'kbid') {
  $req = (string)($_GET['path'] ?? '/');
  list($abs, $err) = resolve_path_within($BASE_ROOT, $req);
  if ($abs === false) json_out(['id'=>null]);
  $realScripts = realpath($SCRIPTS_ROOT);
  if ($realScripts === false) json_out(['id'=>null]);
  if (strpos($abs, $realScripts) !== 0) json_out(['id'=>null]);
  $rel = ltrim(str_replace('\\','/', substr($abs, strlen($realScripts))), '/');
  // Lookup in DB
  $id = null;
  if (is_readable($DB_PATH)){
    $db = new SQLite3($DB_PATH);
    $stmt = $db->prepare('SELECT id FROM endpoint WHERE name = :n LIMIT 1');
    $stmt->bindValue(':n', $rel, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
    if ($row && isset($row['id'])) $id = (int)$row['id'];
    $db->close();
  }
  json_out(['id'=>$id, 'rel'=>$rel]);
}

$IS_EMBED = in_array(strtolower($_GET['embed'] ?? ''), ['1','true','yes'], true);

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>MC Admin - File Tree + KB</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css" />
  <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/php.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/python.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/javascript.min.js"></script>
  <style>
    .scroll-thin::-webkit-scrollbar{width:6px;height:6px}
    .scroll-thin::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:3px}
  </style>
  <script>
    const BASE_QS_EMBED = '<?php echo $IS_EMBED ? '?embed=1' : ''; ?>';
  </script>
</head>
<body class="bg-slate-50 h-screen">
  <div class="h-full flex flex-col">
    <div class="bg-gradient-to-r from-indigo-600 to-sky-500 text-white px-4 py-3 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="text-xl">🗂️</div>
        <div>
          <div class="font-semibold">Admin MC</div>
          <div class="text-xs/relaxed opacity-80">Browse /web and open Scripts KB or view source</div>
        </div>
      </div>
      <div class="text-xs">
        <a class="underline/40 hover:underline" href="admin_Scripts.php<?php echo $IS_EMBED?'?embed=1':''; ?>" target="kb_iframe">Open KB Home</a>
      </div>
    </div>
    <div class="flex-1 flex min-h-0">
      <!-- Left tree -->
      <aside class="w-80 bg-white border-r overflow-auto scroll-thin">
        <div class="p-3 border-b">
          <div class="text-xs text-slate-500">Root: <span class="font-mono text-slate-700">/web</span></div>
          <input id="filterInput" class="mt-2 w-full text-sm border rounded px-2 py-1" placeholder="Filter names..." />
        </div>
        <div id="tree" class="p-2 text-sm"></div>
      </aside>
      <!-- Right content -->
      <main class="flex-1 flex flex-col min-w-0">
        <div class="border-b bg-white">
          <nav class="flex gap-2 px-3 pt-2">
            <button id="tabKB" class="px-3 py-2 text-sm rounded-t border-b-0 border border-transparent hover:bg-slate-50 data-[active=true]:border-slate-300 data-[active=true]:bg-white" data-active="true">KB</button>
            <button id="tabSrc" class="px-3 py-2 text-sm rounded-t border-b-0 border border-transparent hover:bg-slate-50">Source</button>
          </nav>
        </div>
        <section id="paneKB" class="flex-1 min-h-0">
          <iframe name="kb_iframe" id="kb_iframe" class="w-full h-full bg-white" src="admin_Scripts.php<?php echo $IS_EMBED?'?embed=1':''; ?>" referrerpolicy="no-referrer" sandbox="allow-same-origin allow-forms allow-scripts allow-popups allow-top-navigation-by-user-activation"></iframe>
        </section>
        <section id="paneSrc" class="flex-1 min-h-0 hidden">
          <div class="h-full flex flex-col">
            <div class="p-2 border-b bg-slate-50 text-xs flex items-center gap-2">
              <span id="srcMeta" class="font-mono text-slate-600">Select a text file to preview…</span>
              <span class="flex-1"></span>
              <a id="openRaw" href="#" target="_blank" class="text-indigo-600 hover:underline hidden">Open raw</a>
            </div>
            <pre id="srcPre" class="flex-1 overflow-auto m-0 p-3 text-sm bg-[#0b1021] text-slate-100"><code id="srcCode" class="language-plaintext"></code></pre>
          </div>
        </section>
      </main>
    </div>
  </div>

  <script>
    // Tabs
    const tabKB = document.getElementById('tabKB');
    const tabSrc = document.getElementById('tabSrc');
    const paneKB = document.getElementById('paneKB');
    const paneSrc = document.getElementById('paneSrc');
    function activate(tab){
      const isKB = tab==='kb';
      paneKB.classList.toggle('hidden', !isKB);
      paneSrc.classList.toggle('hidden', isKB);
      tabKB.dataset.active = isKB ? 'true' : 'false';
      tabSrc.dataset.active = !isKB ? 'true' : 'false';
    }
    tabKB.addEventListener('click', ()=>activate('kb'));
    tabSrc.addEventListener('click', ()=>activate('src'));

    // Tree rendering with lazy folders
    const treeRoot = document.getElementById('tree');
    const filterInput = document.getElementById('filterInput');

    async function apiLs(path){
      const url = new URL(window.location.href);
      url.search = '';
      url.pathname = location.pathname;
      url.searchParams.set('action','ls');
      url.searchParams.set('path', path || '/');
      const resp = await fetch(url.toString());
      return resp.json();
    }
    async function apiRead(path){
      const url = new URL(window.location.href); url.search='';
      url.searchParams.set('action','read'); url.searchParams.set('path', path);
      const resp = await fetch(url.toString()); return resp.json();
    }
    async function apiKBId(path){
      const url = new URL(window.location.href); url.search='';
      url.searchParams.set('action','kbid'); url.searchParams.set('path', path);
      const resp = await fetch(url.toString()); return resp.json();
    }

    function el(tag, attrs={}, ...children){
      const e = document.createElement(tag);
      for (const [k,v] of Object.entries(attrs)){
        if (k === 'class') e.className = v; else if (k.startsWith('on') && typeof v === 'function') e.addEventListener(k.slice(2), v); else if (k==='dataset') Object.assign(e.dataset, v); else e.setAttribute(k, v);
      }
      for (const c of children){ if (c==null) continue; if (typeof c === 'string') e.appendChild(document.createTextNode(c)); else e.appendChild(c); }
      return e;
    }

    function makeNode(item){
      const isDir = item.type === 'dir';
      const row = el('div', {class:'group flex items-center gap-1 px-1 py-0.5 rounded hover:bg-slate-50 cursor-pointer'});
      const caret = el('span', {class:'w-4 text-slate-500'}, isDir ? '▸' : '');
      const icon = el('span', {class:'w-4'}, isDir ? '📁' : '📄');
      const name = el('span', {class:'truncate flex-1'}, item.name);
      row.append(caret, icon, name);
      const container = el('div', {}, row);
      if (isDir){
        const childrenWrap = el('div', {class:'ml-4 hidden'});
        container.appendChild(childrenWrap);
        row.addEventListener('click', async ()=>{
          const open = childrenWrap.classList.toggle('hidden');
          caret.textContent = open ? '▾' : '▸';
          if (open && !childrenWrap.dataset.loaded){
            const data = await apiLs(item.path);
            childrenWrap.textContent = '';
            for (const ent of (data.entries||[])){
              const child = makeNode(ent);
              childrenWrap.appendChild(child);
            }
            childrenWrap.dataset.loaded = '1';
          }
        });
      } else {
        row.addEventListener('click', ()=> onSelectFile(item));
      }
      return container;
    }

    async function loadRoot(){
      const data = await apiLs('/');
      treeRoot.textContent='';
      for (const ent of (data.entries||[])){
        treeRoot.appendChild(makeNode(ent));
      }
    }

    // Source preview + KB integration
    const kbIframe = document.getElementById('kb_iframe');
    const srcCode = document.getElementById('srcCode');
    const srcPre  = document.getElementById('srcPre');
    const srcMeta = document.getElementById('srcMeta');
    const openRaw = document.getElementById('openRaw');

    async function onSelectFile(item){
      // Try KB first if under scripts dir
      apiKBId(item.path).then(({id})=>{
        if (id){
          kbIframe.src = 'admin_Scripts.php' + (BASE_QS_EMBED ? BASE_QS_EMBED + '&' : '?') + 'id=' + encodeURIComponent(id);
          activate('kb');
        }
      }).catch(()=>{});

      // Load source preview
      const res = await apiRead(item.path);
      if (res && !res.error){
        const lang = res.lang || 'plaintext';
        srcCode.className = 'language-' + lang;
        srcCode.textContent = res.content || '';
        srcMeta.textContent = `${item.path} • ${lang} • ${res.size ?? ''}`;
        openRaw.href = res.path ? ('/\u200b' + res.path.replace(/^\//,'')) : '#';
        openRaw.classList.toggle('hidden', !res.path);
        activate('src');
        requestAnimationFrame(()=>{ try{ hljs.highlightElement(srcCode); }catch(e){} });
      } else {
        srcCode.className = 'language-plaintext';
        srcCode.textContent = res.error ? ('Error: ' + res.error) : 'No preview';
        srcMeta.textContent = item.path;
        openRaw.classList.add('hidden');
        activate('src');
      }
    }

    // Simple filter: hide non-matching names
    filterInput.addEventListener('input', ()=>{
      const q = filterInput.value.trim().toLowerCase();
      const rows = treeRoot.querySelectorAll('div > div.group');
      rows.forEach(r=>{
        const name = r.querySelector('span.truncate')?.textContent.toLowerCase() || '';
        r.parentElement.style.display = q && !name.includes(q) ? 'none' : '';
      });
    });

    loadRoot();
  </script>
</body>
</html>
