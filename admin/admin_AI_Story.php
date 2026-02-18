<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
require_once APP_LIB . '/ai_templates.php';
require_once APP_LIB . '/story_engine.php';

auth_require_admin();
auth_session_start();

function story_h($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function story_csrf_token(): string {
  if (empty($_SESSION['csrf_story'])) {
    $_SESSION['csrf_story'] = bin2hex(random_bytes(16));
  }
  return (string)$_SESSION['csrf_story'];
}

function story_csrf_check(): bool {
  return isset($_POST['csrf_token'], $_SESSION['csrf_story'])
    && hash_equals((string)$_SESSION['csrf_story'], (string)$_POST['csrf_token']);
}

$db = story_db();
$message = '';
$error = '';
$turnResult = null;

if (isset($_GET['export']) && $_GET['export'] === 'json' && isset($_GET['story_id'])) {
  $export = story_export($db, (string)$_GET['story_id']);
  if (!$export) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'story not found';
    exit;
  }
  header('Content-Type: application/json; charset=utf-8');
  header('Content-Disposition: attachment; filename="story-' . substr((string)$_GET['story_id'], 0, 8) . '.json"');
  echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  if (!story_csrf_check()) {
    $error = 'CSRF check failed.';
  } else {
    $formAction = (string)($_POST['action'] ?? '');

    if ($formAction === 'new_story') {
      $title = trim((string)($_POST['story_title'] ?? ''));
      $templateName = trim((string)($_POST['template_name'] ?? ''));
      if ($title === '') $title = 'Untitled Story';

      if ($templateName === '') {
        $error = 'Pick a template.';
      } else {
        $created = story_create($db, $title, $templateName);
        $storyId = (string)$created['story_id'];
        $start = story_start($db, $storyId);
        if (!empty($start['ok'])) {
          $message = 'Story created.';
          $_GET['story_id'] = $storyId;
          $turnResult = $start;
        } else {
          $error = 'Story created, but initial turn failed: ' . (string)($start['error'] ?? 'unknown');
          $_GET['story_id'] = $storyId;
        }
      }
    }

    if ($formAction === 'choose') {
      $storyId = trim((string)($_POST['story_id'] ?? ''));
      $choiceKey = strtoupper(trim((string)($_POST['choice_key'] ?? '')));
      $result = story_take_turn($db, $storyId, 'choice', $choiceKey, '', 'admin');
      if (!empty($result['ok'])) {
        $turnResult = $result;
        $_GET['story_id'] = $storyId;
      } else {
        $error = 'Turn failed: ' . (string)($result['error'] ?? 'unknown');
        $_GET['story_id'] = $storyId;
      }
    }

    if ($formAction === 'note_turn') {
      $storyId = trim((string)($_POST['story_id'] ?? ''));
      $note = trim((string)($_POST['note_text'] ?? ''));
      $result = story_take_turn($db, $storyId, 'note', '', $note, 'admin');
      if (!empty($result['ok'])) {
        $turnResult = $result;
        $_GET['story_id'] = $storyId;
      } else {
        $error = 'Turn failed: ' . (string)($result['error'] ?? 'unknown');
        $_GET['story_id'] = $storyId;
      }
    }

    if ($formAction === 'relay_turn') {
      $storyId = trim((string)($_POST['story_id'] ?? ''));
      $remoteUrl = trim((string)($_POST['remote_url'] ?? ''));
      $choiceKey = strtoupper(trim((string)($_POST['choice_key'] ?? '')));
      $note = trim((string)($_POST['note_text'] ?? ''));
      $relay = story_relay_turn($db, $storyId, $remoteUrl, $choiceKey, $note, 'admin-relay');
      if (!empty($relay['ok'])) {
        $message = 'Relay sent. HTTP ' . (int)($relay['http_status'] ?? 0) . '.';
      } else {
        $error = 'Relay failed: ' . (string)($relay['error'] ?? 'unknown');
      }
      $_GET['story_id'] = $storyId;
    }
  }
}

$storyId = '';
if (isset($_GET['story_id'])) $storyId = (string)$_GET['story_id'];
if ($storyId === '' && isset($_POST['story_id'])) $storyId = (string)$_POST['story_id'];

$currentStory = null;
$currentTurns = [];
if ($storyId !== '') {
  $currentStory = story_get($db, $storyId);
  if ($currentStory) {
    $currentTurns = story_turns($db, $storyId, 12);
  }
}

$stories = story_list($db, 25);
$templates = story_templates_list();
$storyTools = story_list_agent_tools();
$lastChoices = [];
$lastWildcard = '';
if ($currentStory) {
  $currentOpts = story_current_choices($db, $currentStory);
  $lastChoices = isset($currentOpts['choices']) && is_array($currentOpts['choices']) ? $currentOpts['choices'] : [];
  $lastWildcard = isset($currentOpts['wildcard']) ? (string)$currentOpts['wildcard'] : '';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI Story</title>
<style>
:root {
  --bg: #0f172a;
  --panel: #111827;
  --line: #1f2937;
  --text: #e5e7eb;
  --muted: #93a3b8;
  --accent: #22c55e;
  --danger: #ef4444;
}
* { box-sizing: border-box; }
body { margin: 0; font-family: Arial, sans-serif; background: var(--bg); color: var(--text); }
a { color: #93c5fd; text-decoration: none; }
.shell { display: grid; grid-template-columns: 280px 1fr; min-height: 100vh; }
.side { border-right: 1px solid var(--line); background: #0b1220; padding: 14px; }
.main { padding: 18px; }
.card { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 14px; margin-bottom: 12px; }
h1, h2, h3 { margin: 0 0 10px; }
h1 { font-size: 18px; }
h2 { font-size: 14px; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; }
input[type=text], select, textarea, input[type=url] {
  width: 100%; border: 1px solid var(--line); background: #0b1220; color: var(--text);
  border-radius: 8px; padding: 9px;
}
textarea { min-height: 86px; }
button {
  border: 1px solid var(--line); background: #1f2937; color: var(--text);
  padding: 10px 12px; border-radius: 8px; cursor: pointer;
}
button.primary { background: var(--accent); color: #052e16; border-color: #16a34a; font-weight: bold; }
button.choice { width: 100%; text-align: left; margin-bottom: 8px; }
.msg { padding: 10px; border-radius: 8px; margin-bottom: 10px; }
.ok { background: #052e16; border: 1px solid #166534; }
.err { background: #3f1212; border: 1px solid var(--danger); }
.story-link { display: block; border: 1px solid var(--line); border-radius: 8px; padding: 8px; margin-bottom: 7px; }
.story-link.active { border-color: var(--accent); }
.meta { color: var(--muted); font-size: 12px; }
.turn { border-top: 1px dashed var(--line); padding-top: 10px; margin-top: 10px; }
pre { background: #0b1220; padding: 10px; border-radius: 8px; overflow-x: auto; }
@media (max-width: 920px) { .shell { grid-template-columns: 1fr; } .side { border-right: 0; border-bottom: 1px solid var(--line); } }
</style>
</head>
<body>
<div class="shell">
  <aside class="side">
    <h1>AI Story</h1>
    <div class="meta" style="margin-bottom:10px;">Choice-first flow with optional note override.</div>

    <div class="card">
      <h2>New Story</h2>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo story_h(story_csrf_token()); ?>">
        <input type="hidden" name="action" value="new_story">
        <div class="meta">Title</div>
        <input type="text" name="story_title" placeholder="Operation Iron Signal" required>
        <div class="meta" style="margin-top:8px;">Template</div>
        <select name="template_name" required>
          <?php foreach ($templates as $tpl): ?>
            <option value="<?php echo story_h($tpl); ?>"><?php echo story_h($tpl); ?></option>
          <?php endforeach; ?>
        </select>
        <div style="margin-top:10px;"><button class="primary" type="submit">Create</button></div>
      </form>
    </div>

    <div class="card">
      <h2>Stories</h2>
      <?php foreach ($stories as $s): ?>
        <a class="story-link <?php echo ((string)$s['story_id'] === $storyId) ? 'active' : ''; ?>" href="?story_id=<?php echo urlencode((string)$s['story_id']); ?>">
          <div><?php echo story_h($s['title']); ?></div>
          <div class="meta">Turns: <?php echo (int)$s['turn_count']; ?></div>
        </a>
      <?php endforeach; ?>
      <?php if (empty($stories)): ?><div class="meta">No stories yet.</div><?php endif; ?>
    </div>
  </aside>

  <main class="main">
    <?php if ($message !== ''): ?><div class="msg ok"><?php echo story_h($message); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="msg err"><?php echo story_h($error); ?></div><?php endif; ?>

    <?php if (!$currentStory): ?>
      <div class="card">
        <h2>How It Works</h2>
        <p>AI generates scene + options. You click <strong>A</strong>, <strong>B</strong>, <strong>C</strong>, or <strong>Wildcard</strong>. Optional note input exists when none of the options fit.</p>
      </div>
      <div class="card">
        <h2>API</h2>
        <pre>POST /v1/story/create
{"title":"Operation Iron Signal","template_name":"story_skynet_narrator"}

POST /v1/story/turn
{"story_id":"...","input_mode":"choice","selected_key":"A"}

GET /v1/story/list

POST /v1/story/relay
{"story_id":"...","remote_url":"https://server-b.example","selected_key":"B"}</pre>
      </div>
    <?php else: ?>
      <div class="card">
        <h1><?php echo story_h($currentStory['title']); ?></h1>
        <div class="meta">Template: <?php echo story_h($currentStory['template_name']); ?> | Turns: <?php echo (int)$currentStory['turn_count']; ?> | <a href="?story_id=<?php echo urlencode((string)$currentStory['story_id']); ?>&export=json">Export JSON</a></div>
      </div>

      <div class="card">
        <h2>Current Scene</h2>
        <p style="white-space: pre-wrap;"><?php echo story_h((string)$currentStory['last_narrative']); ?></p>
        <?php if (!empty($currentStory['summary'])): ?>
          <h2 style="margin-top:12px;">Summary</h2>
          <p style="white-space: pre-wrap;" class="meta"><?php echo story_h((string)$currentStory['summary']); ?></p>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>Pick Next Option</h2>
        <form method="post" style="margin-bottom:6px;">
          <input type="hidden" name="csrf_token" value="<?php echo story_h(story_csrf_token()); ?>">
          <input type="hidden" name="action" value="choose">
          <input type="hidden" name="story_id" value="<?php echo story_h($currentStory['story_id']); ?>">
          <button class="choice" type="submit" name="choice_key" value="A">A: <?php echo story_h((string)($lastChoices[0] ?? 'Option A unavailable')); ?></button>
          <button class="choice" type="submit" name="choice_key" value="B">B: <?php echo story_h((string)($lastChoices[1] ?? 'Option B unavailable')); ?></button>
          <button class="choice" type="submit" name="choice_key" value="C">C: <?php echo story_h((string)($lastChoices[2] ?? 'Option C unavailable')); ?></button>
          <button class="choice" type="submit" name="choice_key" value="W">Wildcard: <?php echo story_h($lastWildcard !== '' ? $lastWildcard : 'No wildcard available'); ?></button>
        </form>
      </div>

      <div class="card">
        <h2>Optional Note Override</h2>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?php echo story_h(story_csrf_token()); ?>">
          <input type="hidden" name="action" value="note_turn">
          <input type="hidden" name="story_id" value="<?php echo story_h($currentStory['story_id']); ?>">
          <textarea name="note_text" placeholder="Only use when none of the options fit."></textarea>
          <div style="margin-top:8px;"><button type="submit">Submit Note Turn</button></div>
        </form>
      </div>

      <div class="card">
        <h2>Relay To Remote</h2>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?php echo story_h(story_csrf_token()); ?>">
          <input type="hidden" name="action" value="relay_turn">
          <input type="hidden" name="story_id" value="<?php echo story_h($currentStory['story_id']); ?>">
          <div class="meta">Remote base URL</div>
          <input type="url" name="remote_url" placeholder="https://server-b.example" required>
          <div class="meta" style="margin-top:8px;">Choice key for relay (A/B/C/W) or leave and use note</div>
          <input type="text" name="choice_key" placeholder="A" maxlength="1">
          <div class="meta" style="margin-top:8px;">Optional note</div>
          <textarea name="note_text" placeholder="Optional"></textarea>
          <div style="margin-top:8px;"><button type="submit">Relay</button></div>
        </form>
      </div>

      <div class="card">
        <h2>Story Tools (`story_*`)</h2>
        <?php if (!empty($storyTools)): ?>
          <?php foreach ($storyTools as $tool): ?>
            <div><strong><?php echo story_h((string)$tool['name']); ?></strong> <span class="meta"><?php echo story_h((string)$tool['description']); ?></span></div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="meta">No approved `story_*` tools yet. Import the example JSON via `admin/db_loader.php`.</div>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>Recent Turns</h2>
        <?php foreach ($currentTurns as $t): ?>
          <div class="turn">
            <div class="meta">Turn <?php echo (int)$t['turn_number']; ?> | input=<?php echo story_h((string)$t['input_mode']); ?> | key=<?php echo story_h((string)$t['selected_key']); ?> | model=<?php echo story_h((string)$t['model']); ?> | <?php echo (int)$t['latency_ms']; ?>ms</div>
            <div><strong>Action:</strong> <?php echo story_h((string)$t['player_action']); ?></div>
            <p style="white-space: pre-wrap;"><?php echo story_h((string)$t['narrative']); ?></p>
            <?php $turnTools = json_decode((string)$t['tool_results'], true); ?>
            <?php if (is_array($turnTools) && !empty($turnTools)): ?>
              <div class="meta">Tool results:</div>
              <pre><?php echo story_h(json_encode($turnTools, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (empty($currentTurns)): ?><div class="meta">No turns yet.</div><?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
