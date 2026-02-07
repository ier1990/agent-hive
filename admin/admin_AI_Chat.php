<?php
// Admin AI Chat (AI-friendly UI)
// Uses shared AI settings (admin_AI_Setup.php / lib/ai_bootstrap.php)
// and AI_Header payload templates (admin_AI_Headers.php).

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once __DIR__ . '/AI_Header/AI_Header.php';

$IS_EMBED = in_array(strtolower($_GET['embed'] ?? ''), ['1','true','yes'], true);
$EMBED_QS = $IS_EMBED ? '?embed=1' : '';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

if (empty($_SESSION['csrf_ai_chat'])) {
  $_SESSION['csrf_ai_chat'] = bin2hex(random_bytes(32));
}
function csrf_input_ai_chat(){
  echo '<input type="hidden" name="csrf_token" value="' . h($_SESSION['csrf_ai_chat']) . '">';
}
function csrf_ok_ai_chat($t){
  return isset($_SESSION['csrf_ai_chat']) && hash_equals((string)$_SESSION['csrf_ai_chat'], (string)$t);
}

function ai_header_db_path(): string {
  $root = defined('PRIVATE_ROOT') ? (string)PRIVATE_ROOT : '/web/private';
  return rtrim($root, "/\\") . '/db/memory/ai_header.db';
}

function ai_header_list_template_names(string $type = 'payload'): array {
  $path = ai_header_db_path();
  if (!is_file($path)) return [];

  $out = [];
  try {
    $db = new SQLite3($path);
    $stmt = $db->prepare('SELECT name FROM ai_header_templates WHERE type = :type ORDER BY name ASC');
    $stmt->bindValue(':type', $type, SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
      $name = (string)($row['name'] ?? '');
      if ($name !== '') $out[] = $name;
    }
    $db->close();
  } catch (Throwable $t) {
    return [];
  }

  return $out;
}

function ai_header_get_template_text_by_name(string $name): string {
  $path = ai_header_db_path();
  if (!is_file($path)) return '';

  try {
    $db = new SQLite3($path);
    $stmt = $db->prepare('SELECT template_text FROM ai_header_templates WHERE name = :name LIMIT 1');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
    $db->close();
    return is_array($row) ? (string)($row['template_text'] ?? '') : '';
  } catch (Throwable $t) {
    return '';
  }
}

function ai_chat_history_to_messages(array $hist): array {
  $msgs = [];
  foreach ($hist as $m) {
    if (!is_array($m)) continue;
    $role = (string)($m['role'] ?? '');
    $content = (string)($m['content'] ?? '');
    if ($role === '' || $content === '') continue;
    if (!in_array($role, ['user','assistant','system'], true)) continue;
    $msgs[] = ['role' => $role, 'content' => $content];
  }
  return $msgs;
}

function ai_chat_history_transcript(array $hist): string {
  $lines = [];
  foreach ($hist as $m) {
    if (!is_array($m)) continue;
    $role = (string)($m['role'] ?? '');
    $content = (string)($m['content'] ?? '');
    if ($role === '' || $content === '') continue;
    $label = strtoupper($role);
    $lines[] = $label . ': ' . $content;
    $lines[] = '';
  }
  return rtrim(implode("\n", $lines));
}

function ai_chat_call_openai_compat(array $settings, string $model, array $messages, float $temperature, int $maxTokens, int $timeoutSeconds, bool $verifySsl, array &$debugOut): array {
  $base = rtrim((string)($settings['base_url'] ?? ''), '/');
  if ($base === '') {
    return ['ok' => false, 'error' => 'Missing base URL. Configure AI first.', 'assistant' => '', 'raw' => null];
  }

  $provider = strtolower(trim((string)($settings['provider'] ?? '')));

  // Build the correct endpoint URL based on provider
  if ($provider === 'ollama') {
    // Ollama native: base/api/chat (streaming disabled below)
    $url = $base . '/api/chat';
  } else {
    // OpenAI-compatible: ensure /v1/chat/completions
    if (!preg_match('~/v1$~', $base)) {
      $base .= '/v1';
    }
    $url = $base . '/chat/completions';
  }

  $payload = [
    'model' => $model,
    'messages' => $messages,
    'temperature' => $temperature,
  ];
  if ($maxTokens > 0 && $provider !== 'ollama') {
    $payload['max_tokens'] = $maxTokens;
  }
  // Ollama streams by default — disable it
  if ($provider === 'ollama') {
    $payload['stream'] = false;
    if ($maxTokens > 0) {
      $payload['options'] = ['num_predict' => $maxTokens];
    }
  }

  $headers = [
    'Content-Type: application/json',
    'Accept: application/json',
  ];

  $apiKey = (string)($settings['api_key'] ?? '');
  if ($apiKey !== '') {
    $headers[] = 'Authorization: Bearer ' . $apiKey;
  }

  $timeoutSeconds = (int)$timeoutSeconds;
  if ($timeoutSeconds < 1) $timeoutSeconds = 120;

  $debugOut = [
    'url' => $url,
    'request' => $payload,
    'response' => null,
    'http_code' => null,
    'curl_error' => null,
  ];

  $ch = curl_init($url);
  if ($ch === false) {
    return ['ok' => false, 'error' => 'curl_init failed', 'assistant' => '', 'raw' => null];
  }

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
    CURLOPT_TIMEOUT => $timeoutSeconds,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    CURLOPT_SSL_VERIFYPEER => $verifySsl ? 1 : 0,
    CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
  ]);

  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  $debugOut['http_code'] = $code;
  $debugOut['curl_error'] = $err;

  if (!is_string($body)) {
    return ['ok' => false, 'error' => 'HTTP request failed: ' . $err, 'assistant' => '', 'raw' => null];
  }

  $decoded = json_decode($body, true);
  if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
    // Include body prefix to help debugging without flooding UI.
    $prefix = substr($body, 0, 500);
    return ['ok' => false, 'error' => 'Invalid JSON response: ' . $prefix, 'assistant' => '', 'raw' => null];
  }

  $debugOut['response'] = $decoded;

  if ($code >= 400) {
    $msg = 'HTTP ' . $code;
    if (isset($decoded['error']['message'])) {
      $msg .= ': ' . (string)$decoded['error']['message'];
    }
    return ['ok' => false, 'error' => $msg, 'assistant' => '', 'raw' => $decoded];
  }

  $assistant = '';
  // OpenAI format: choices[0].message.content
  if (isset($decoded['choices'][0]['message']['content'])) {
    $assistant = (string)$decoded['choices'][0]['message']['content'];
  }
  // Ollama /api/chat format: message.content
  if ($assistant === '' && isset($decoded['message']['content'])) {
    $assistant = (string)$decoded['message']['content'];
  }

  if ($assistant === '') {
    return ['ok' => false, 'error' => 'Empty assistant content', 'assistant' => '', 'raw' => $decoded];
  }

  return ['ok' => true, 'error' => '', 'assistant' => $assistant, 'raw' => $decoded];
}

// Session state
if (!isset($_SESSION['ai_chat_history']) || !is_array($_SESSION['ai_chat_history'])) {
  $_SESSION['ai_chat_history'] = [];
}
if (!isset($_SESSION['ai_chat_last_debug']) || !is_array($_SESSION['ai_chat_last_debug'])) {
  $_SESSION['ai_chat_last_debug'] = [];
}

$settings = ai_settings_get();

// Load saved connections for connection dropdown
$savedConnections = ai_saved_profiles_recent(100);

$ai = new AI_Header([
  'missing_policy' => 'ignore',
  'debug' => false,
]);

$builtinTemplateName = '__builtin_simple_chat__';
$builtinTemplateText = "system: |\n  You are a helpful assistant.\nuser: |\n  {{ user_message }}\n";

$templates = ai_header_list_template_names('payload');

$preferredDefaultTemplate = 'Default Chat';

$errors = [];
$flashes = [];

// Inputs / defaults
$selectedTemplate = (string)($_POST['template'] ?? ($_GET['template'] ?? ''));
if ($selectedTemplate === '') {
  $selectedTemplate = in_array($preferredDefaultTemplate, $templates, true)
    ? $preferredDefaultTemplate
    : $builtinTemplateName;
}

// Connection selection - load full connection config
$selectedConnectionHash = (string)($_POST['connection'] ?? '');
if ($selectedConnectionHash !== '') {
  $connData = ai_saved_profiles_get($selectedConnectionHash);
  if ($connData) {
    // Override settings with selected connection
    $settings = [
      'provider' => (string)($connData['provider'] ?? ''),
      'base_url' => (string)($connData['base_url'] ?? ''),
      'model' => (string)($connData['model'] ?? ''),
      'api_key' => (string)($connData['api_key'] ?? ''),
      'timeout_seconds' => (int)($connData['timeout_seconds'] ?? 120),
    ];
  }
}

$model = (string)($settings['model'] ?? '');
$temperature = (string)($_POST['temperature'] ?? '0.2');
$maxTokens = (string)($_POST['max_tokens'] ?? '800');
$timeoutSeconds = (int)($_POST['timeout_seconds'] ?? (int)($settings['timeout_seconds'] ?? 120));
$verifySsl = isset($_POST['verify_ssl']) ? (bool)$_POST['verify_ssl'] : true;
$systemOverride = (string)($_POST['system'] ?? '');
$userMessage = (string)($_POST['user_message'] ?? '');

$action = (string)($_POST['action'] ?? ($_GET['action'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (!csrf_ok_ai_chat($_POST['csrf_token'] ?? '')) {
    $errors[] = 'Invalid CSRF token.';
  } else {
    if ($action === 'reset') {
      $_SESSION['ai_chat_history'] = [];
      $_SESSION['ai_chat_last_debug'] = [];
      header('Location: admin_AI_Chat.php' . $EMBED_QS, true, 303);
      exit;
    }

    if ($action === 'send') {
      $userMessage = trim($userMessage);
      if ($model === '') $model = (string)($settings['model'] ?? '');
      if ($model === '') $errors[] = 'Model is required (configure AI Setup).';
      if ($userMessage === '') $errors[] = 'Message cannot be empty.';

      $temp = (float)$temperature;
      if ($temp < 0) $temp = 0;
      if ($temp > 2) $temp = 2;

      $max = (int)$maxTokens;
      if ($max < 0) $max = 0;
      if ($max > 200000) $max = 200000;

      if (empty($errors)) {
        $tplText = '';
        if ($selectedTemplate === $builtinTemplateName) {
          $tplText = $builtinTemplateText;
        } else {
          $tplText = ai_header_get_template_text_by_name($selectedTemplate);
        }

        // Compile template -> system/user payload (like Scripts KB)
        $hist = is_array($_SESSION['ai_chat_history']) ? $_SESSION['ai_chat_history'] : [];
        $transcript = ai_chat_history_transcript($hist);

        $payload = [];
        if ($tplText !== '') {
          $payload = $ai->compilePayload($tplText, [
            'model' => $model,
            'base_url' => (string)($settings['base_url'] ?? ''),
            'provider' => (string)($settings['provider'] ?? ''),
            'user_message' => $userMessage,
            'chat_history' => $transcript,
          ]);
        }

        $system = trim((string)($payload['system'] ?? ''));
        $user = trim((string)($payload['user'] ?? ''));
        if ($systemOverride !== '') $system = (string)$systemOverride;
        if ($user === '') $user = $userMessage;

        $messages = [];
        if ($system !== '') {
          $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages = array_merge($messages, ai_chat_history_to_messages($hist));
        $messages[] = ['role' => 'user', 'content' => $user];

        $debug = [];
        $res = ai_chat_call_openai_compat($settings, $model, $messages, $temp, $max, $timeoutSeconds, $verifySsl, $debug);
        $_SESSION['ai_chat_last_debug'] = $debug;

        if (!$res['ok']) {
          $errors[] = (string)$res['error'];
        } else {
          // Append user + assistant to history
          $hist[] = ['role' => 'user', 'content' => $userMessage, 'ts' => time()];
          $hist[] = ['role' => 'assistant', 'content' => (string)$res['assistant'], 'ts' => time()];
          $_SESSION['ai_chat_history'] = $hist;
          $userMessage = '';
          $flashes[] = ['t' => 'success', 'm' => 'Response received.'];
        }
      }
    }
  }
}

$hist = is_array($_SESSION['ai_chat_history']) ? $_SESSION['ai_chat_history'] : [];
$lastDebug = is_array($_SESSION['ai_chat_last_debug']) ? $_SESSION['ai_chat_last_debug'] : [];

?><!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AI Chat</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
  <div class="max-w-6xl mx-auto p-4">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
      <div>
        <h1 class="text-xl font-semibold">AI Chat</h1>
        <div class="text-xs text-slate-400">Uses shared AI settings + AI_Header templates</div>
      </div>
      <div class="flex flex-wrap gap-2">
        <a class="px-3 py-2 rounded bg-slate-800 hover:bg-slate-700 text-sm" href="admin_AI_Setup.php?popup=1&amp;postmessage=1&amp;return=admin_AI_Chat.php<?= $IS_EMBED ? '%3Fembed%3D1' : '' ?>" target="_blank">AI Setup…</a>
        <a class="px-3 py-2 rounded bg-slate-800 hover:bg-slate-700 text-sm" href="admin_AI_Headers.php" target="_blank">AI Headers</a>
        <a class="px-3 py-2 rounded bg-slate-800 hover:bg-slate-700 text-sm" href="admin_API_Chat.php" target="_blank">API Chat Tester</a>
      </div>
    </div>

    <?php foreach ($flashes as $f): ?>
      <div class="mb-3 rounded border border-emerald-700 bg-emerald-950/30 px-3 py-2 text-emerald-200 text-sm"><?=h($f['m'])?></div>
    <?php endforeach; ?>

    <?php if (!empty($errors)): ?>
      <div class="mb-3 rounded border border-rose-700 bg-rose-950/30 px-3 py-2 text-rose-200 text-sm">
        <div class="font-semibold mb-1">Error</div>
        <ul class="list-disc ml-5">
          <?php foreach ($errors as $e): ?>
            <li><?=h($e)?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2">
        <div class="rounded border border-slate-800 bg-slate-900/40 p-3 mb-4">
          <div class="flex items-center justify-between mb-2">
            <div class="text-sm font-semibold">Conversation</div>
            <form method="post" class="flex gap-2">
              <?php csrf_input_ai_chat(); ?>
              <input type="hidden" name="action" value="reset">
              <?php if ($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
              <button class="px-3 py-1.5 rounded bg-slate-800 hover:bg-slate-700 text-sm" type="submit" onclick="return confirm('Clear chat history?');">Reset</button>
            </form>
          </div>

          <div class="space-y-3 max-h-[60vh] overflow-auto pr-1">
            <?php if (empty($hist)): ?>
              <div class="text-slate-400 text-sm">No messages yet.</div>
            <?php else: ?>
              <?php foreach ($hist as $m): if (!is_array($m)) continue; $role = (string)($m['role'] ?? ''); $content = (string)($m['content'] ?? ''); ?>
                <?php $isUser = ($role === 'user'); ?>
                <div class="flex <?= $isUser ? 'justify-end' : 'justify-start' ?>">
                  <div class="max-w-[85%] rounded px-3 py-2 text-sm <?= $isUser ? 'bg-blue-600/30 border border-blue-700' : 'bg-slate-800/60 border border-slate-700' ?>">
                    <div class="text-[11px] uppercase tracking-wide text-slate-300 mb-1"><?=h($role)?></div>
                    <div class="whitespace-pre-wrap break-words"><?=h($content)?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="rounded border border-slate-800 bg-slate-900/40 p-3">
          <form method="post" class="space-y-3">
            <?php csrf_input_ai_chat(); ?>
            <input type="hidden" name="action" value="send">
            <?php if ($IS_EMBED): ?><input type="hidden" name="embed" value="1"><?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs text-slate-400 mb-1">Template (AI_Header payload)</label>
                <select name="template" class="w-full rounded bg-slate-950 border border-slate-700 px-2 py-2 text-sm">
                  <option value="<?=h($builtinTemplateName)?>" <?= $selectedTemplate === $builtinTemplateName ? 'selected' : '' ?>>Built-in: Simple Chat</option>
                  <?php foreach ($templates as $name): ?>
                    <option value="<?=h($name)?>" <?= $selectedTemplate === $name ? 'selected' : '' ?>><?=h($name)?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="block text-xs text-slate-400 mb-1">Connection</label>
                <?php if (!empty($savedConnections)): ?>
                  <select name="connection" class="w-full rounded bg-slate-950 border border-slate-700 px-2 py-2 text-sm">
                    <option value="">-- Select a saved connection --</option>
                    <?php foreach ($savedConnections as $conn): ?>
                      <?php 
                        $connHash = (string)($conn['hash'] ?? '');
                        $connName = (string)($conn['name'] ?? '');
                        $connModel = (string)($conn['model'] ?? '');
                        $connProvider = (string)($conn['provider'] ?? '');
                        $displayText = $connName . ' (' . $connModel . ')';
                        if ($connProvider) $displayText .= ' - ' . $connProvider;
                      ?>
                      <option value="<?=h($connHash)?>" <?= $selectedConnectionHash === $connHash ? 'selected' : '' ?>><?=h($displayText)?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="text-xs text-slate-400 mt-1">
                    Selected: <span class="text-slate-200"><?=h($model)?></span> @ <span class="text-slate-200"><?=h((string)($settings['base_url'] ?? 'default'))?></span>
                  </div>
                <?php else: ?>
                  <div class="text-sm text-slate-400">No saved connections.</div>
                  <a href="admin_AI_Setup.php" class="text-blue-400 hover:underline text-sm">Set up connections →</a>
                <?php endif; ?>
              </div>
            </div>

            <div>
              <label class="block text-xs text-slate-400 mb-1">System (optional override)</label>
              <textarea name="system" rows="2" class="w-full rounded bg-slate-950 border border-slate-700 px-2 py-2 text-sm" placeholder="Leave blank to use template-generated system prompt."><?=h($systemOverride)?></textarea>
            </div>

            <div>
              <label class="block text-xs text-slate-400 mb-1">Message</label>
              <textarea name="user_message" rows="4" class="w-full rounded bg-slate-950 border border-slate-700 px-2 py-2 text-sm" placeholder="Type your message..." required><?=h($userMessage)?></textarea>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
              <div>
                <label class="block text-xs text-slate-400 mb-1">Temperature</label>
                <input name="temperature" value="<?=h($temperature)?>" class="w-full rounded bg-slate-950 border border-slate-700 px-2 py-2 text-sm" placeholder="0.2">
              </div>
              <div>
                <label class="block text-xs text-slate-400 mb-1">Max Tokens</label>
                <input name="max_tokens" value="<?=h($maxTokens)?>" class="w-full rounded bg-slate-950 border border-slate-700 px-2 py-2 text-sm" placeholder="800">
              </div>
              <div>
                <label class="block text-xs text-slate-400 mb-1">Timeout (sec)</label>
                <input name="timeout_seconds" value="<?=h($timeoutSeconds)?>" class="w-full rounded bg-slate-950 border border-slate-700 px-2 py-2 text-sm" placeholder="120">
              </div>
              <div class="flex items-center gap-2">
                <input id="verify_ssl" name="verify_ssl" type="checkbox" value="1" <?= $verifySsl ? 'checked' : '' ?> class="rounded">
                <label for="verify_ssl" class="text-xs text-slate-300">Verify SSL</label>
              </div>
            </div>

            <div class="flex items-center justify-between">
              <div class="text-xs text-slate-400">
                Provider: <span class="text-slate-200"><?=h((string)($settings['provider'] ?? ''))?></span> · Base: <span class="text-slate-200"><?=h((string)($settings['base_url'] ?? ''))?></span>
              </div>
              <button type="submit" class="px-4 py-2 rounded bg-blue-600 hover:bg-blue-500 text-sm font-semibold">Send</button>
            </div>
          </form>
        </div>
      </div>

      <div class="lg:col-span-1">
        <div class="rounded border border-slate-800 bg-slate-900/40 p-3 mb-4">
          <div class="text-sm font-semibold mb-2">Tips</div>
          <ul class="text-sm text-slate-300 space-y-1 list-disc ml-5">
            <li>Use AI Setup to change provider/base/model/key globally.</li>
            <li>Select an AI_Header payload template to auto-generate system/user prompts.</li>
            <li>If you use https://localhost with self-signed certs, uncheck Verify SSL.</li>
          </ul>
        </div>

        <div class="rounded border border-slate-800 bg-slate-900/40 p-3">
          <div class="flex items-center justify-between mb-2">
            <div class="text-sm font-semibold">Last Request/Response</div>
          </div>

          <?php if (!empty($lastDebug)): ?>
            <div class="text-xs text-slate-400 mb-2">URL</div>
            <div class="text-xs break-words bg-slate-950 border border-slate-700 rounded p-2 mb-3"><?=h((string)($lastDebug['url'] ?? ''))?></div>

            <div class="text-xs text-slate-400 mb-2">Request (JSON)</div>
            <pre class="text-xs whitespace-pre-wrap break-words bg-slate-950 border border-slate-700 rounded p-2 mb-3"><?php
              $reqJson = json_encode($lastDebug['request'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
              echo h($reqJson === false ? '' : $reqJson);
            ?></pre>

            <div class="text-xs text-slate-400 mb-2">Response (JSON)</div>
            <pre class="text-xs whitespace-pre-wrap break-words bg-slate-950 border border-slate-700 rounded p-2"><?php
              $respJson = json_encode($lastDebug['response'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
              echo h($respJson === false ? '' : $respJson);
            ?></pre>
          <?php else: ?>
            <div class="text-sm text-slate-400">Send a message to see debug output.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <script>
    // If AI Setup popup posts settings updates, refresh to show them.
    window.addEventListener('message', function (ev) {
      try {
        if (!ev || !ev.data) return;
        if (ev.data.type === 'ai_setup_saved') {
          window.location.reload();
        }
      } catch (e) {}
    });
  </script>
</body>
</html>
