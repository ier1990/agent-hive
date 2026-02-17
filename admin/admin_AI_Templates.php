<?php
//admin/AI_Header
require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

function e(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function help_inline_format(string $escaped): string
{
  $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
  $escaped = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $escaped);
  $escaped = preg_replace('/`(.+?)`/s', '<code>$1</code>', $escaped);
  return $escaped;
}

function render_help_markdown(string $md): string
{
  $lines = preg_split("/\r\n|\r|\n/", $md);
  if (!is_array($lines)) {
    $lines = [$md];
  }

  $out = '';
  $para = [];
  $inList = false;
  $inCode = false;
  $codeLines = [];

  $flushPara = function () use (&$out, &$para) {
    if (empty($para)) return;
    $escaped = htmlspecialchars(implode("\n", $para), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escaped = help_inline_format($escaped);
    $out .= '<p>' . nl2br($escaped) . '</p>';
    $para = [];
  };
  $closeList = function () use (&$out, &$inList) {
    if ($inList) {
      $out .= '</ul>';
      $inList = false;
    }
  };
  $flushCode = function () use (&$out, &$inCode, &$codeLines) {
    if (!$inCode) return;
    $escaped = htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $out .= '<pre><code>' . $escaped . "\n" . '</code></pre>';
    $codeLines = [];
    $inCode = false;
  };

  foreach ($lines as $line) {
    $line = (string)$line;

    if (preg_match('/^```/', $line)) {
      $flushPara();
      $closeList();
      if ($inCode) {
        $flushCode();
      } else {
        $inCode = true;
        $codeLines = [];
      }
      continue;
    }

    if ($inCode) {
      $codeLines[] = $line;
      continue;
    }

    if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
      $flushPara();
      $closeList();
      $level = strlen((string)$m[1]);
      $text = help_inline_format(htmlspecialchars((string)$m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
      $out .= '<h' . $level . '>' . $text . '</h' . $level . '>';
      continue;
    }

    if (preg_match('/^\s*-\s+(.*)$/', $line, $m)) {
      $flushPara();
      if (!$inList) {
        $out .= '<ul>';
        $inList = true;
      }
      $item = help_inline_format(htmlspecialchars((string)$m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
      $out .= '<li>' . $item . '</li>';
      continue;
    }

    if (trim($line) === '') {
      $flushPara();
      $closeList();
      continue;
    }

    $para[] = $line;
  }

  $flushPara();
  $closeList();
  if ($inCode) {
    $flushCode();
  }

  return $out;
}

function ensure_dir(string $dir): void
{
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
}

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
  $ok = isset($_POST['csrf_token'], $_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token']);
  if (!$ok) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Bad Request</h2><p>CSRF check failed.</p>';
    exit;
  }
}

function new_id(): string
{
  // short sortable id: epoch ms + random
  $t = (int)(microtime(true) * 1000);
  $r = bin2hex(random_bytes(6));
  return dechex($t) . $r;
}

function db_has_column(PDO $db, string $table, string $col): bool
{
  $stmt = $db->query('PRAGMA table_info(' . $table . ')');
  $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
  foreach ($rows as $r) {
    if ((string)($r['name'] ?? '') === $col) return true;
  }
  return false;
}

function send_json_download(string $filename, $data): void
{
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Failed to encode JSON.';
    exit;
  }
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('X-Content-Type-Options: nosniff');
  }
  echo $json . "\n";
  exit;
}

function normalize_import_templates($decoded): array
{
  if (is_array($decoded) && array_key_exists('templates', $decoded) && is_array($decoded['templates'])) {
    return $decoded['templates'];
  }
  // single template object
  if (is_array($decoded) && (isset($decoded['name']) || isset($decoded['template_text']))) {
    return [$decoded];
  }
  // array of templates
  if (is_array($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
    return $decoded;
  }
  return [];
}

function ai_header_allowed_types(): array
{
  return ['header', 'payload', 'text'];
}

function ai_header_normalize_type(string $type): string
{
  $type = strtolower(trim($type));
  if ($type === '') return 'payload';
  return in_array($type, ai_header_allowed_types(), true) ? $type : 'payload';
}

function ai_header_is_allowed_type(string $type): bool
{
  return in_array(strtolower(trim($type)), ai_header_allowed_types(), true);
}

$messages = [];
$errors = [];

function ai_header_b64_decode_text(string $b64): string
{
  $b64 = preg_replace('/\s+/', '', $b64);
  if (!is_string($b64) || $b64 === '') return '';
  $raw = base64_decode($b64, true);
  return is_string($raw) ? $raw : '';
}

function ai_header_write_backup(string $storageDir, array $tpl): void
{
  $id = (string)($tpl['id'] ?? '');
  if ($id === '') return;
  $backupPath = rtrim($storageDir, "/\\") . '/template_' . $id . '.json';
  @file_put_contents(
    $backupPath,
    json_encode($tpl, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
    LOCK_EX
  );
}

function ai_header_sync_backups(PDO $db, string $storageDir): int
{
  $storageDir = rtrim($storageDir, "/\\");
  $files = glob($storageDir . '/template_*.json') ?: [];
  $have = [];
  foreach ($files as $fp) {
    //if (preg_match('/template_([^\/\\]+)\.json$/', (string)$fp, $m)) {
    //Warning: preg_match(): Compilation failed: missing terminating ] for character class at offset 25 in /web/html/admin/AI_Header/index.php on line 142
    if (preg_match('/template_([a-f0-9]+)\.json$/', (string)$fp, $m)) {
      $have[(string)$m[1]] = (string)$fp;
    }
  }

  $rows = $db->query('SELECT id,name,type,template_text,created_at,updated_at FROM ai_header_templates')->fetchAll(PDO::FETCH_ASSOC);
  $written = 0;

  foreach ($rows as $r) {
    $id = (string)($r['id'] ?? '');
    if ($id === '') continue;
    $type = ai_header_normalize_type((string)($r['type'] ?? ''));
    $updatedAt = (string)($r['updated_at'] ?? '');
    $createdAt = (string)($r['created_at'] ?? '');

    $shouldWrite = false;
    $path = $have[$id] ?? '';
    if ($path === '' || !is_file($path)) {
      $shouldWrite = true;
    } else {
      $dbTs = strtotime($updatedAt);
      $fsTs = @filemtime($path);
      if ($dbTs !== false && is_int($fsTs) && $fsTs > 0 && $fsTs < $dbTs) {
        $shouldWrite = true;
      }
    }

    if ($shouldWrite) {
      ai_header_write_backup($storageDir, [
        'id' => $id,
        'name' => (string)($r['name'] ?? ''),
        'type' => $type,
        'template_text' => (string)($r['template_text'] ?? ''),
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
      ]);
      $written++;
    }
  }

  return $written;
}

function ai_header_restore_from_backups(PDO $db, string $storageDir): int
{
  $files = glob(rtrim($storageDir, "/\\") . '/template_*.json');
  if (!$files) return 0;

  $selById = $db->prepare('SELECT id FROM ai_header_templates WHERE id = :id');
  $selByName = $db->prepare('SELECT id FROM ai_header_templates WHERE name = :name');
  $ins = $db->prepare('INSERT INTO ai_header_templates (id,name,type,template_text,created_at,updated_at) VALUES (:id,:name,:type,:tpl,:created,:updated)');
  $upd = $db->prepare('UPDATE ai_header_templates SET name=:name, type=:type, template_text=:tpl, updated_at=:updated WHERE id=:id');

  $now = gmdate('c');
  $restored = 0;
  foreach ($files as $fp) {
    $raw = @file_get_contents($fp);
    if (!is_string($raw) || trim($raw) === '') continue;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) continue;

    $id = trim((string)($decoded['id'] ?? ''));
    $name = trim((string)($decoded['name'] ?? ''));
    $type = ai_header_normalize_type((string)($decoded['type'] ?? ''));
    $tpl = (string)($decoded['template_text'] ?? '');
    if ($name === '' || trim($tpl) === '') continue;
    if ($id === '') $id = new_id();

    $existingId = '';
    $selById->execute([':id' => $id]);
    $row = $selById->fetch(PDO::FETCH_ASSOC);
    $existingId = $row ? (string)$row['id'] : '';
    if ($existingId === '') {
      $selByName->execute([':name' => $name]);
      $row = $selByName->fetch(PDO::FETCH_ASSOC);
      $existingId = $row ? (string)$row['id'] : '';
    }

    if ($existingId !== '') {
      $upd->execute([':id' => $existingId, ':name' => $name, ':type' => $type, ':tpl' => $tpl, ':updated' => $now]);
      $restored++;
    } else {
      $ins->execute([':id' => $id, ':name' => $name, ':type' => $type, ':tpl' => $tpl, ':created' => $now, ':updated' => $now]);
      $restored++;
    }
  }
  return $restored;
}

function ai_header_ensure_template(PDO $db, string $storageDir, string $name, string $type, string $tplText): bool
{
  $name = trim($name);
  if ($name === '' || trim($tplText) === '') return false;
  $type = ai_header_normalize_type($type);

  $stmt = $db->prepare('SELECT id FROM ai_header_templates WHERE name = :name');
  $stmt->execute([':name' => $name]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($row && !empty($row['id'])) {
    return false; // exists; do not overwrite
  }

  $now = gmdate('c');
  $id = new_id();
  $ins = $db->prepare('INSERT INTO ai_header_templates (id,name,type,template_text,created_at,updated_at) VALUES (:id,:name,:type,:tpl,:created,:updated)');
  $ins->execute([
    ':id' => $id,
    ':name' => $name,
    ':type' => $type,
    ':tpl' => $tplText,
    ':created' => $now,
    ':updated' => $now,
  ]);

  ai_header_write_backup($storageDir, [
    'id' => $id,
    'name' => $name,
    'type' => $type,
    'template_text' => $tplText,
    'created_at' => $now,
    'updated_at' => $now,
  ]);

  return true;
}

$dbPath = rtrim(PRIVATE_ROOT, "/\\") . '/db/memory/ai_header.db';
$storageDir = rtrim(PRIVATE_ROOT, "/\\") . '/storage/ai_header';
ensure_dir(dirname($dbPath));
ensure_dir($storageDir);

if (!is_dir($storageDir) || !is_writable($storageDir)) {
  $errors[] = 'Backup dir is not writable: ' . $storageDir . ' (fix permissions so the web server can write JSON backups).';
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA synchronous=NORMAL');
$db->exec('PRAGMA busy_timeout=5000');

$db->exec('CREATE TABLE IF NOT EXISTS ai_header_templates (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,
  type TEXT NOT NULL DEFAULT "payload" CHECK (type IN ("header","payload","text")),
  template_text TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
  updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
)');

$db->exec('CREATE INDEX IF NOT EXISTS idx_ai_header_templates_type_name ON ai_header_templates(type, name)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_ai_header_templates_updated ON ai_header_templates(updated_at)');

// Lightweight migration (for older DBs created before `type` existed)
if (!db_has_column($db, 'ai_header_templates', 'type')) {
	$db->exec('ALTER TABLE ai_header_templates ADD COLUMN type TEXT NOT NULL DEFAULT "payload"');
}

// Normalize existing types to the supported set
$db->exec("UPDATE ai_header_templates SET type='payload' WHERE type IS NULL OR trim(type) = '' OR lower(trim(type)) NOT IN ('header','payload','text')");

// Seed defaults if empty (first run)
$count = (int)$db->query('SELECT COUNT(*) AS c FROM ai_header_templates')->fetch(PDO::FETCH_ASSOC)['c'];
if ($count === 0) {
  $now = gmdate('c');

  // Prefer restoring from portable JSON backups if they exist.
  $restored = 0;
  try {
    $restored = ai_header_restore_from_backups($db, $storageDir);
  } catch (Throwable $t) {
    $restored = 0;
  }

  if ($restored > 0) {
    $messages[] = "Restored {$restored} template(s) from JSON backups.";
  } else {
    // Hardcoded first-install defaults (captured from currently running scripts/templates)
    $defaults = [
      [
        'name' => 'Header',
        'type' => 'header',
        'tpl_b64' => 'QXV0aG9yaXphdGlvbjogQmVhcmVyIHt7IGFwaV9rZXkgfX0NClgtTW9kZWw6IHt7IG1vZGVsIH19DQpYLVNlc3Npb246IHt7IHNlc3Npb24uaWQgfX0NClgtQ2xpZW50OiB7eyBjbGllbnQgfX0NCkNvbnRlbnQtVHlwZTogYXBwbGljYXRpb24vanNvbg0K',
      ],
      [
        'name' => 'Ollama Headers',
        'type' => 'header',
        'tpl_b64' => 'Q29udGVudC1UeXBlOiBhcHBsaWNhdGlvbi9qc29uDQpYLU1vZGVsOiB7eyBtb2RlbCB9fQ0KWC1DbGllbnQ6IERvbWFpbk1lbW9yeS9TZWFyY2hTdW1tYXJ5DQpYLVRpbWVvdXQ6IHt7IHRpbWVvdXRfcyB9fQ==',
      ],
      [
        'name' => 'Search Summary',
        'type' => 'payload',
        'tpl_b64' => 'c3lzdGVtOiB8DQogIFlvdSBzdW1tYXJpemUgY2FjaGVkIHdlYiBzZWFyY2ggcmVzdWx0cyBmb3IgYW4gaW50ZXJuYWwgbm90ZXMgc3lzdGVtLg0KICBCZSBjb25jaXNlIGFuZCBhY3Rpb25hYmxlLiBPdXRwdXQgUExBSU4gVEVYVCBvbmx5Lg0KICBJbmNsdWRlOiAxLTIgc2VudGVuY2Ugb3ZlcnZpZXcsIHRoZW4gMy03IGJ1bGxldCBwb2ludHMgb2Yga2V5IGZpbmRpbmdzLg0KICBJZiBjb250ZW50IGxvb2tzIGxpa2UgYSBiYWNrZW5kIGVycm9yIHBhZ2Ugb3IgZW1wdHkgcmVzcG9uc2UsIHNheSBzbyBjbGVhcmx5Lg0KDQp1c2VyOiB8DQogIHNlYXJjaF9jYWNoZV9pZDoge3sgcm93LmlkIH19DQogIGNhY2hlZF9hdDoge3sgcm93LmNhY2hlZF9hdCB9fQ0KICBxdWVyeToge3sgcm93LnEgfX0NCg0KICBUT1BfVVJMUzoNCiAge3sgcm93LnRvcF91cmxzX2Zvcm1hdHRlZCB9fQ0KDQogIFJBV19TRUFSQ0hfSlNPTjoNCiAge3sgcm93LmJvZHkgfX0NCg0Kb3B0aW9uczoNCiAgdGVtcGVyYXR1cmU6IDAuMg0KDQpzdHJlYW06IGZhbHNl',
      ],
      [
        'name' => 'Default Chat',
        'type' => 'payload',
        'tpl_b64' => 'c3lzdGVtOiB8CiAgWW91IGFyZSBhIGhlbHBmdWwgYXNzaXN0YW50IHJ1bm5pbmcgb24gYSBwcml2YXRlIExBTi1maXJzdCBzeXN0ZW0uCgpwcm9tcHQ6IHwKICB7eyBwcm9tcHQgfX0K',
      ],
      [
        'name' => 'Summarize Notes',
        'type' => 'payload',
        'tpl_b64' => 'c3lzdGVtOiB8CiAgU3VtbWFyaXplIHRoZSBwcm92aWRlZCBub3RlcyBjbGVhcmx5IGFuZCBjb25jaXNlbHkuCgpjb250ZXh0OiB8CiAge3sgYXR0YWNobWVudHMgfX0KCnByb21wdDogfAogIHt7IHByb21wdCB9fQo=',
      ],
      [
        'name' => 'Extract Tasks',
        'type' => 'payload',
        'tpl_b64' => 'c3lzdGVtOiB8CiAgRXh0cmFjdCBhY3Rpb25hYmxlIHRhc2tzIGFzIGEgYnVsbGV0IGxpc3QuCgpjb250ZXh0OiB8CiAge3sgYXR0YWNobWVudHMgfX0KCnByb21wdDogfAogIHt7IHByb21wdCB9fQo=',
      ],
      [
        'name' => 'default',
        'type' => 'payload',
        'tpl_b64' => 'c3lzdGVtOiB8CiAge3sgc3lzdGVtIH19CgoKcGVyc29uYTogfAogIHt7IHBlcnNvbmEgfX0KCm9iamVjdGl2ZToKICB0YXNrX3R5cGU6IHt7IHRhc2tfdHlwZSB9fQogIHN1Y2Nlc3NfY3JpdGVyaWE6IHt7IHN1Y2Nlc3NfY3JpdGVyaWEgfX0KCmNvbnRleHQ6CiAgcHJlX3Byb21wdDogfAoge3sgcHJlX3Byb21wdCB9fQogIGF0dGFjaG1lbnRzOiB8CiAge3sgYXR0YWNobWVudHMgfX0KCnByb21wdDogfAogIHt7IHByb21wdCB9fQoKY29uc3RyYWludHM6CiAgdG9uZToge3sgdG9uZSB9fQogIHZlcmJvc2l0eToge3sgdmVyYm9zaXR5IH19CgplbmdpbmU6CiAgcHJvdmlkZXI6IHt7IHByb3ZpZGVyIH19CiAgbW9kZWw6IHt7IG1vZGVsIH19',
      ],
      [
        'name' => 'Plain Text (webpage compiler)',
        'type' => 'text',
        'tpl' => "{{ html }}\n",
      ],
    ];

    $ins = $db->prepare('INSERT INTO ai_header_templates (id,name,type,template_text,created_at,updated_at) VALUES (:id,:name,:type,:tpl,:created,:updated)');
    foreach ($defaults as $d) {
      $tplText = (string)($d['tpl'] ?? '');
      if ($tplText === '' && isset($d['tpl_b64'])) {
        $tplText = ai_header_b64_decode_text((string)$d['tpl_b64']);
      }
      if (trim($tplText) === '') continue;
      $id = new_id();
      $type = ai_header_normalize_type((string)($d['type'] ?? 'payload'));

      $ins->execute([
        ':id' => $id,
        ':name' => (string)$d['name'],
        ':type' => $type,
        ':tpl' => $tplText,
        ':created' => $now,
        ':updated' => $now,
      ]);

      ai_header_write_backup($storageDir, [
        'id' => $id,
        'name' => (string)$d['name'],
        'type' => $type,
        'template_text' => $tplText,
        'created_at' => $now,
        'updated_at' => $now,
      ]);
    }
  }
}

// Keep portable backups in sync with DB (missing or stale)
try {
  ai_header_sync_backups($db, $storageDir);
} catch (Throwable $t) {
  // best-effort; do not block UI
}

// Ensure templates used by other admin tools exist (do not overwrite user edits).
try {
  $added = 0;

  $tplScriptsHtml = "system: |\n  You write concise internal HTML docs for scripts.\n\nuser: |\n  You are a developer documentation assistant. Analyze the {{ lang }} script below and produce a concise, well-structured HTML help guide suitable for an internal knowledge base.\n  Include: purpose, language ({{ lang }}), how to run (with example command), required inputs/flags/env vars, outputs/artifacts, exit codes or error handling, dependencies, and security considerations.\n  If the script can be called over HTTP, note that too.\n  Return ONLY HTML.\n\n  ---\n  {{ code }}\n  ---\n\noptions:\n  temperature: 0.4\n  max_tokens: 1400\n";

  $tplScriptsMd = "system: |\n  You respond in English only and output Markdown only.\n\nuser: |\n  Respond in ENGLISH only.\n\n  Write a concise, well-structured help guide in MARKDOWN for an internal scripts knowledge base.\n  - Output MARKDOWN only (no preamble like 'Answer' or 'जवाब').\n  - Include: Purpose, Language ({{ lang }}), How to run (example command), Inputs/flags/env vars, Outputs/artifacts, Error handling/exit codes, Dependencies, Security considerations.\n\n  SCRIPT ({{ lang }}) SOURCE:\n  ---\n  {{ code }}\n  ---\n\noptions:\n  temperature: 0.4\n  max_tokens: 1400\n";

  if (ai_header_ensure_template($db, $storageDir, 'Scripts KB - HTML Help', 'payload', $tplScriptsHtml)) $added++;
  if (ai_header_ensure_template($db, $storageDir, 'Scripts KB - Markdown Help', 'payload', $tplScriptsMd)) $added++;

  $tplNotesMetadata = "system: |\n  You generate metadata for an internal LAN-only notes system.\n  Return ONLY a single JSON object. No markdown, no code fences, no extra text.\n  Schema:\n  {\n    \"doc_kind\": \"bash_history|sysinfo|manual_pdf|bios_pdf|general_note|code|reminder|passwords|links|images|files|tags|other\",\n    \"summary\": \"1-2 sentence summary\",\n    \"tags\": [\"tag1\",\"tag2\"],\n    \"entities\": [\"asus\",\"x570\",\"tpm\",\"secure boot\"],\n    \"commands\": [\"systemctl restart ollama\",\"apt-get install ...\"],\n    \"cmd_families\": [\"systemctl\",\"apt\",\"docker\",\"ufw\",\"journalctl\"],\n    \"sensitivity\": \"normal|sensitive\"\n  }\n  Rules:\n  - tags/entities/commands/cmd_families must be arrays (can be empty).\n  - If note looks like bash history or logs, extract commands.\n  - If note looks like a manual/pdf, set doc_kind accordingly.\n  - If note_type is 'passwords', set sensitivity='sensitive' and keep summary minimal.\n\nuser: |\n  note_id: {{ note.id }}\n  parent_id: {{ note.parent_id }}\n  notes_type: {{ note.notes_type }}\n  topic: {{ note.topic }}\n  created_at: {{ note.created_at }}\n  updated_at: {{ note.updated_at }}\n\n  NOTE CONTENT:\n  {{ note.note }}\n\noptions:\n  temperature: 0.2\n\nstream: false\n";

  $tplBashClassify = "system: |\n  You are a bash command classifier.\n  Return ONLY valid JSON (no markdown, no extra text).\n  Schema:\n  {\n    \"base_cmd\": string,\n    \"known\": boolean,\n    \"intent\": string,\n    \"keywords\": [string,...],\n    \"search_query\": string|null,\n    \"notes\": string\n  }\n  Rules:\n  - base_cmd should be the first real command (skip leading 'sudo' and env assignments).\n  - If you are not confident, set known=false and search_query=null.\n  - search_query should be a good web query to learn what the command does.\n\nuser: |\n  Command:\n  full_cmd: {{ full_cmd }}\n  base_cmd_guess: {{ base_cmd }}\n\noptions:\n  temperature: 0\n\nstream: false\n";

  if (ai_header_ensure_template($db, $storageDir, 'Notes Metadata', 'payload', $tplNotesMetadata)) $added++;
  if (ai_header_ensure_template($db, $storageDir, 'Bash Command Classifier', 'payload', $tplBashClassify)) $added++;
  if ($added > 0) {
    $messages[] = "Added {$added} required template(s).";
  }
} catch (Throwable $t) {
  // best-effort
}

// Export handlers (download JSON)
$action = (string)($_GET['action'] ?? 'list');
if ($action === 'export_all') {
  $templatesAll = $db->query('SELECT id,name,type,template_text,created_at,updated_at FROM ai_header_templates ORDER BY type ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
  send_json_download('ai_header_templates_export.json', [
    'schema' => 'ai_header_templates',
    'version' => 1,
    'exported_at' => gmdate('c'),
    'templates' => $templatesAll,
  ]);
}

if ($action === 'export') {
  $id = (string)($_GET['id'] ?? '');
  $stmt = $db->prepare('SELECT id,name,type,template_text,created_at,updated_at FROM ai_header_templates WHERE id = :id');
  $stmt->execute([':id' => $id]);
  $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$tpl) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
  }
  $fn = 'ai_header_' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)$tpl['name']) . '.json';
  send_json_download($fn, $tpl);
}
if (!in_array($action, ['class','list', 'new', 'edit', 'help'], true)) {
  $action = 'list';
}

// POST handlers
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  csrf_check();
  $postAction = (string)($_POST['action'] ?? '');

  if ($postAction === 'import') {
    try {
      if (!isset($_FILES['import_file']) || !is_array($_FILES['import_file'])) {
        throw new RuntimeException('No file uploaded.');
      }
      $f = $_FILES['import_file'];
      if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error.');
      }
      $tmp = (string)($f['tmp_name'] ?? '');
      $raw = @file_get_contents($tmp);
      if (!is_string($raw) || trim($raw) === '') {
        throw new RuntimeException('Empty upload.');
      }
      if (strlen($raw) > 1024 * 1024 * 5) {
        throw new RuntimeException('Import file too large (max 5MB).');
      }
      $decoded = json_decode($raw, true);
      if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON.');
      }
      $items = normalize_import_templates($decoded);
      if (empty($items)) {
        throw new RuntimeException('No templates found in JSON.');
      }

      $db->beginTransaction();
      $now = gmdate('c');
      $imported = 0;
      $updated = 0;

      $selById = $db->prepare('SELECT id FROM ai_header_templates WHERE id = :id');
      $selByName = $db->prepare('SELECT id FROM ai_header_templates WHERE name = :name');
      $ins = $db->prepare('INSERT INTO ai_header_templates (id,name,type,template_text,created_at,updated_at) VALUES (:id,:name,:type,:tpl,:created,:updated)');
      $upd = $db->prepare('UPDATE ai_header_templates SET name=:name, type=:type, template_text=:tpl, updated_at=:updated WHERE id=:id');

      foreach ($items as $it) {
        if (!is_array($it)) continue;
        $name = trim((string)($it['name'] ?? ''));
        $tpl = (string)($it['template_text'] ?? '');
        $type = ai_header_normalize_type((string)($it['type'] ?? ''));
        $id = trim((string)($it['id'] ?? ''));

        if ($name === '' || trim($tpl) === '') {
          continue;
        }
        if (strlen($name) > 200) $name = substr($name, 0, 200);
        if (strlen($type) > 20) $type = substr($type, 0, 20);

        $existingId = '';
        if ($id !== '') {
          $selById->execute([':id' => $id]);
          $row = $selById->fetch(PDO::FETCH_ASSOC);
          $existingId = $row ? (string)$row['id'] : '';
        }
        if ($existingId === '') {
          $selByName->execute([':name' => $name]);
          $row = $selByName->fetch(PDO::FETCH_ASSOC);
          $existingId = $row ? (string)$row['id'] : '';
        }

        if ($existingId !== '') {
          $upd->execute([':id' => $existingId, ':name' => $name, ':type' => $type, ':tpl' => $tpl, ':updated' => $now]);
          $updated++;
          $idToWrite = $existingId;
        } else {
          $newId = ($id !== '') ? $id : new_id();
          $ins->execute([':id' => $newId, ':name' => $name, ':type' => $type, ':tpl' => $tpl, ':created' => $now, ':updated' => $now]);
          $imported++;
          $idToWrite = $newId;
        }

        // Always refresh backup file
        $backup = [
          'id' => $idToWrite,
          'name' => $name,
          'type' => $type,
          'template_text' => $tpl,
          'updated_at' => $now,
        ];
        $backupPath = rtrim($storageDir, "/\\") . '/template_' . $idToWrite . '.json';
        @file_put_contents($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);
      }

      $db->commit();
      $messages[] = "Import complete: {$imported} created, {$updated} updated.";
    } catch (Throwable $t) {
      if ($db->inTransaction()) {
        $db->rollBack();
      }
      $errors[] = 'Import failed: ' . $t->getMessage();
    }
    $action = 'list';
  }

  if ($postAction === 'save') {
    $id = trim((string)($_POST['id'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $type = trim((string)($_POST['type'] ?? ''));
    $tpl = (string)($_POST['template_text'] ?? '');

    if ($name === '' || strlen($name) > 200) $errors[] = 'Name is required (max 200 chars).';
    if ($type === '') $type = 'payload';
    if (!ai_header_is_allowed_type($type)) $errors[] = 'Type must be one of: header, payload, text.';
    if (strlen($type) > 20) $errors[] = 'Type must be <= 20 chars.';
    if (trim($tpl) === '') $errors[] = 'Template text is required.';

    $type = ai_header_normalize_type($type);

    if (empty($errors)) {
      $now = gmdate('c');
      try {
        if ($id === '') {
          $id = new_id();
          $stmt = $db->prepare('INSERT INTO ai_header_templates (id,name,type,template_text,created_at,updated_at) VALUES (:id,:name,:type,:tpl,:created,:updated)');
          $stmt->execute([':id' => $id, ':name' => $name, ':type' => $type, ':tpl' => $tpl, ':created' => $now, ':updated' => $now]);
          $messages[] = 'Created.';
        } else {
          $stmt = $db->prepare('UPDATE ai_header_templates SET name=:name, type=:type, template_text=:tpl, updated_at=:updated WHERE id=:id');
          $stmt->execute([':id' => $id, ':name' => $name, ':type' => $type, ':tpl' => $tpl, ':updated' => $now]);
          $messages[] = 'Saved.';
        }

        // JSON backup (portable)
        $backup = [
          'id' => $id,
          'name' => $name,
          'type' => $type,
          'template_text' => $tpl,
          'updated_at' => $now,
        ];
          $backupPath = rtrim($storageDir, "/\\") . '/template_' . $id . '.json';
        @file_put_contents($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);

        header('Location: ?action=edit&id=' . rawurlencode($id));
        exit;
      } catch (Throwable $t) {
        $errors[] = 'Save failed: ' . $t->getMessage();
      }
    }
    $action = ($id !== '') ? 'edit' : 'new';
    $_GET['id'] = $id;
  }

  if ($postAction === 'delete') {
    $id = trim((string)($_POST['id'] ?? ''));
    if ($id === '') {
      $errors[] = 'Missing id.';
    } else {
      try {
        $stmt = $db->prepare('DELETE FROM ai_header_templates WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $messages[] = 'Deleted.';
      } catch (Throwable $t) {
        $errors[] = 'Delete failed: ' . $t->getMessage();
      }
    }
    $action = 'list';
  }
}

// Data fetch for views
$row = null;
if ($action === 'edit') {
  $id = (string)($_GET['id'] ?? '');
  $stmt = $db->prepare('SELECT * FROM ai_header_templates WHERE id = :id');
  $stmt->execute([':id' => $id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$row) {
    $errors[] = 'Not found.';
    $action = 'list';
  }
}

$templates = [];
if ($action === 'list') {
  $templates = $db->query('SELECT id,name,type,updated_at FROM ai_header_templates ORDER BY type ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
}

$helpHtml = '';
$classHtml = '';
if ($action === 'class') {
  $classPath = __DIR__ . '/AI_Header/CLASS.md';
    if (is_file($classPath)) {  
    $raw = @file_get_contents($classPath);
    if (is_string($raw) && $raw !== '') {
      $classHtml = render_help_markdown($raw);
    } else {
      $classHtml = '<p><em>CLASS.md is empty.</em></p>';
    }
  } else {
    $classHtml = '<p><em>Missing file:</em> ' . e($classPath) . '</p>';
  }
}


  if ($action === 'help') {
  $helpPath = __DIR__ . '/AI_Header/README.md';
  if (is_file($helpPath)) {
    $raw = @file_get_contents($helpPath);
    if (is_string($raw) && $raw !== '') {
      $helpHtml = render_help_markdown($raw);
    } else {
      $helpHtml = '<p><em>README.md is empty.</em></p>';
    }
  } else {
    $helpHtml = '<p><em>Missing file:</em> ' . e($helpPath) . '</p>';
  }
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex,nofollow" />
  <title>Admin · AI Templates</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 18px; color: #111; }
    .top { display:flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    a { color: #0645ad; text-decoration: none; }
    a:hover { text-decoration: underline; }
    .muted { color: #666; }
    .box { border: 1px solid #ddd; border-radius: 10px; padding: 14px; margin-top: 14px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px 8px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
    .actions { white-space: nowrap; }
    label { display:block; font-weight: 600; margin-top: 12px; }
    input[type=text] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 8px; }
    textarea { width: 100%; min-height: 320px; padding: 10px; border: 1px solid #ccc; border-radius: 8px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; }
    .btn { display:inline-block; padding: 9px 12px; border: 1px solid #ccc; border-radius: 8px; background: #f7f7f7; cursor: pointer; }
    .btn.primary { background: #e9f2ff; border-color: #b7d2ff; }
    .btn.danger { background: #fff1f1; border-color: #ffc1c1; }
    .msg { margin: 10px 0; padding: 10px 12px; border-radius: 8px; }
    .msg.ok { background: #eef9f0; border: 1px solid #cde9d3; }
    .msg.bad { background: #fff4f4; border: 1px solid #ffd1d1; }
    .md pre { background: #f6f8fa; border: 1px solid #e6e8eb; padding: 12px; border-radius: 10px; overflow: auto; }
    .md code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; }
    .md h1, .md h2, .md h3 { margin: 16px 0 8px; }
    .md p { margin: 10px 0; }
  </style>
</head>
<body>
  <div class="top">
    <div>
      <h1 style="margin:0">AI Templates = Sheet Music</h1>
      <div class="muted">AI Templates are sheet music.<BR>
The Conductor turns them into a performance.<BR>
”Templates compiled into model-ready headers</div>
    </div>
    <div>
      <a class="btn" href="?action=class">Class</a>
      <a class="btn primary" href="?action=new">New Template</a>
      <a class="btn primary" href="?action=help">Help</a>
		<a class="btn" href="?action=export_all">Export All</a>
    </div>
  </div>

  <?php foreach ($messages as $m): ?>
    <div class="msg ok"><?php echo e($m); ?></div>
  <?php endforeach; ?>
  <?php foreach ($errors as $m): ?>
    <div class="msg bad"><?php echo e($m); ?></div>
  <?php endforeach; ?>

  
  <?php if ($action === 'class'): ?>
    <div class="box">
      <div style="display:flex; justify-content:space-between; align-items:baseline; gap:12px; flex-wrap:wrap;">
        <h2 style="margin:0">Help</h2>
        <a class="btn" href="?">Back</a>
      </div>
      <div class="md" style="margin-top:12px;">
        <?php echo $classHtml; ?>
      </div>
    </div>
  <?php endif; ?>  


  <?php if ($action === 'help'): ?>
    <div class="box">
      <div style="display:flex; justify-content:space-between; align-items:baseline; gap:12px; flex-wrap:wrap;">
        <h2 style="margin:0">Help</h2>
        <a class="btn" href="?">Back</a>
      </div>
      <div class="md" style="margin-top:12px;">
        <?php echo $helpHtml; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($action === 'list'): ?>
    <div class="box">
    <form method="post" action="?" enctype="multipart/form-data" style="margin: 0 0 12px 0; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
      <input type="hidden" name="action" value="import" />
      <label style="margin:0; font-weight:600;">Import JSON</label>
      <input type="file" name="import_file" accept="application/json" />
      <button class="btn" type="submit">Import</button>
      <span class="muted">Accepts a single template JSON, an array, or {"templates": [...]}</span>
    </form>

      <table>
        <thead>
          <tr><th>Name</th><th>Type</th><th>Updated</th><th class="actions">Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($templates as $t): ?>
          <tr>
            <td><?php echo e((string)$t['name']); ?></td>
            <td class="muted"><?php echo e((string)($t['type'] ?? '')); ?></td>
            <td class="muted"><?php echo e((string)$t['updated_at']); ?></td>
            <td class="actions">
              <a class="btn" href="?action=edit&id=<?php echo rawurlencode((string)$t['id']); ?>">Edit</a>
				  <a class="btn" href="?action=export&id=<?php echo rawurlencode((string)$t['id']); ?>">Export</a>
              <form method="post" action="?" style="display:inline" onsubmit="return confirm('Delete this header?');">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
                <input type="hidden" name="action" value="delete" />
                <input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>" />
                <button class="btn danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($action === 'new' || $action === 'edit'): ?>
    <div class="box">
      <form method="post" action="?">
        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>" />
        <input type="hidden" name="action" value="save" />
        <input type="hidden" name="id" value="<?php echo e($row ? (string)$row['id'] : ''); ?>" />

        <label>Name</label>
        <input type="text" name="name" value="<?php echo e($row ? (string)$row['name'] : ''); ?>" placeholder="e.g. Default Chat" />

        <label>Type</label>
        <select name="type" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:8px;">
          <?php
            $currentType = ai_header_normalize_type($row ? (string)($row['type'] ?? 'payload') : 'payload');
            foreach (ai_header_allowed_types() as $opt) {
              $sel = ($opt === $currentType) ? ' selected' : '';
              echo '<option value="' . e($opt) . '"' . $sel . '>' . e($opt) . '</option>';
            }
          ?>
        </select>

        <label>Template (.tpl text)</label>
        <textarea name="template_text" placeholder="system: |\n  ...\n\nprompt: |\n  {{ prompt }}\n"><?php echo e($row ? (string)$row['template_text'] : ''); ?></textarea>

        <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn primary" type="submit">Save</button>
          <a class="btn" href="?">Back</a>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <div class="muted" style="margin-top:14px;">
    DB: <?php echo e($dbPath); ?> · Backup dir: <?php echo e($storageDir); ?>
  </div>
</body>
</html>
