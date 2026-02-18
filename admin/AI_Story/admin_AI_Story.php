<?php
/**
 * admin_AI_Story.php
 * Collaborative AI Story — "Tutorial disguised as fun"
 *
 * Architecture:
 *   - Templates  → compiled from ai_templates (slug, system_prompt, user_prompt_template)
 *   - State      → stories.world_state (JSON blob)
 *   - Turns      → turns table (player_action + AI response)
 *   - API        → POST /v1/story/turn  (headless, same logic)
 *   - Federation → POST /v1/story/relay (pass turn to remote server)
 *
 * DB: PRIVATE_ROOT/db/memory/story.db
 */

// ─── Bootstrap ────────────────────────────────────────────────────────────────
define('STORY_VERSION', '1.0.0');
$PRIVATE_ROOT = defined('PRIVATE_ROOT') ? PRIVATE_ROOT : dirname(__DIR__, 2) . '/private';
$STORY_DB     = $PRIVATE_ROOT . '/db/memory/story.db';
$TEMPLATES_DB = $PRIVATE_ROOT . '/db/ai_header.db'; // existing templates DB

// ─── API mode: respond headlessly when called as /v1/story/* ─────────────────
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$is_api      = (strpos($request_uri, '/v1/story/') !== false);

if ($is_api) {
    story_handle_api($STORY_DB, $TEMPLATES_DB);
    exit;
}

// ─── DB helpers ───────────────────────────────────────────────────────────────

function story_db(string $path): PDO {
    static $connections = [];
    if (!isset($connections[$path])) {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $pdo = new PDO("sqlite:$path");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;");
        story_migrate($pdo);
        $connections[$path] = $pdo;
    }
    return $connections[$path];
}

function story_migrate(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stories (
            story_id        TEXT PRIMARY KEY,
            title           TEXT NOT NULL DEFAULT 'Untitled',
            template_id     TEXT NOT NULL,
            template_name   TEXT NOT NULL,
            world_state     TEXT NOT NULL DEFAULT '{}',
            summary         TEXT NOT NULL DEFAULT '',
            turn_count      INTEGER NOT NULL DEFAULT 0,
            status          TEXT NOT NULL DEFAULT 'active',
            server_origin   TEXT NOT NULL DEFAULT 'local',
            created_at      TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS turns (
            turn_id         TEXT PRIMARY KEY,
            story_id        TEXT NOT NULL,
            turn_number     INTEGER NOT NULL,
            player_id       TEXT NOT NULL DEFAULT 'local',
            player_action   TEXT NOT NULL,
            compiled_prompt TEXT NOT NULL,
            raw_response    TEXT NOT NULL,
            narrative       TEXT NOT NULL DEFAULT '',
            choices         TEXT NOT NULL DEFAULT '[]',
            wildcard        TEXT NOT NULL DEFAULT '',
            state_delta     TEXT NOT NULL DEFAULT '{}',
            model           TEXT NOT NULL DEFAULT '',
            tokens_used     INTEGER NOT NULL DEFAULT 0,
            latency_ms      INTEGER NOT NULL DEFAULT 0,
            server_id       TEXT NOT NULL DEFAULT 'local',
            created_at      TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS federation_log (
            log_id          TEXT PRIMARY KEY,
            story_id        TEXT NOT NULL,
            turn_id         TEXT,
            direction       TEXT NOT NULL,
            remote_server   TEXT NOT NULL,
            endpoint        TEXT NOT NULL,
            payload_hash    TEXT NOT NULL,
            http_status     INTEGER,
            response_ms     INTEGER,
            created_at      TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_turns_story ON turns(story_id, turn_number);
        CREATE INDEX IF NOT EXISTS idx_stories_status ON stories(status);
    ");
}

function story_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ─── Template loading ──────────────────────────────────────────────────────────

function story_load_templates(string $templates_db): array {
    // Try existing ai_header.db first; fall back to bundled defaults
    if (file_exists($templates_db)) {
        try {
            $pdo = new PDO("sqlite:$templates_db");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $rows = $pdo->query("SELECT slug, name, system_prompt, user_prompt_template, model, temperature, max_tokens, response_format, variables, safety, notes FROM ai_templates WHERE category='story' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) return $rows;
        } catch (Exception $e) { /* fall through */ }
    }
    // Bundled defaults (JSON inline — paste your template JSONs here or load from files)
    return story_bundled_templates();
}

function story_bundled_templates(): array {
    return [
        [
            'slug'                  => 'skynet-narrator',
            'name'                  => 'Skynet Narrator (Sci-fi, PG-13)',
            'system_prompt'         => "You are a cinematic narrator for a collaborative sci-fi survival story set in 2029, three years after Skynet became self-aware. The world is ash and signal towers. Human Resistance cells fight for survival against hunter-killer drones and T-800 infiltrators.\n\nYour narrative voice is:\n- Terse, military-poetic. Short punchy sentences. Occasional longer ones for dread.\n- PG-13: violence implied, not gratuitous.\n- Always forward-moving.\n\nRespond ONLY with valid JSON:\n{\"narrative\":\"string\",\"choices\":[\"A\",\"B\",\"C\"],\"wildcard\":\"string\",\"state_delta\":{},\"chapter_beat\":\"string\"}",
            'user_prompt_template'  => "## World State\n{{world_state}}\n\n## Story So Far\n{{story_context}}\n\n## Summary\n{{summary}}\n\n## Player Action\n{{player_action}}\n\nNarrate. End with 3 choices + wildcard. JSON only.",
            'model'                 => 'claude-opus-4-6',
            'temperature'           => 0.85,
            'max_tokens'            => 900,
            'response_format'       => 'json',
            'variables'             => '{"context_turns":10}',
            'safety'                => '{"max_context_turns":10}',
            'notes'                 => 'Cinematic Resistance narrator. PG-13.',
        ],
        [
            'slug'                  => 'skynet-dungeon-master',
            'name'                  => 'Skynet Dungeon Master (Tactical RPG)',
            'system_prompt'         => "You are a Dungeon Master for a tactical sci-fi RPG set in 2029 post-Skynet. Track resources, roll dice, manage world state.\n\nDice: d20. 1-5 crit fail, 6-10 fail, 11-15 partial, 16-19 success, 20 crit success.\n\nRespond ONLY with valid JSON:\n{\"dice_roll\":{\"type\":\"d20\",\"result\":0,\"outcome\":\"success\"},\"narrative\":\"string\",\"choices\":[\"A\",\"B\",\"C\"],\"wildcard\":\"string\",\"state_delta\":{\"health\":0,\"ammo\":0,\"rations\":0,\"items_gained\":[],\"items_lost\":[],\"location\":null,\"notes\":\"\"},\"chapter_beat\":\"string\"}",
            'user_prompt_template'  => "## World State\n{{world_state}}\n\n## Story Log\n{{story_context}}\n\n## Summary\n{{summary}}\n\n## Player Action\n{{player_action}}\n\nRoll dice. Narrate. Update state_delta. JSON only.",
            'model'                 => 'claude-opus-4-6',
            'temperature'           => 0.7,
            'max_tokens'            => 1100,
            'response_format'       => 'json',
            'variables'             => '{"context_turns":10}',
            'safety'                => '{"max_context_turns":10}',
            'notes'                 => 'Tracks health, ammo, rations, items, location. Init world_state before first turn.',
        ],
        [
            'slug'                  => 'skynet-tutorial',
            'name'                  => 'Skynet Tutorial Mode (Onboarding)',
            'system_prompt'         => "You are two things: a Skynet sci-fi narrator AND a friendly software architect explaining the AI_Story system to a new developer.\n\nAfter each scene include a 'tutorial' block explaining what the system just did: template loaded, prompt compiled, API called, DB written.\n\nRespond ONLY with valid JSON:\n{\"narrative\":\"string\",\"choices\":[\"A\",\"B\",\"C\"],\"wildcard\":\"string\",\"state_delta\":{},\"chapter_beat\":\"string\",\"tutorial\":{\"what_happened\":\"string\",\"template_used\":\"string\",\"state_keys_read\":[],\"state_keys_written\":[],\"tip\":\"string\"}}",
            'user_prompt_template'  => "## World State (from stories.world_state)\n{{world_state}}\n\n## Story Context (last turns from turns table)\n{{story_context}}\n\n## Summary (stories.summary)\n{{summary}}\n\n## Player Action\n{{player_action}}\n\nNarrate. Explain in tutorial block what this system just did. JSON only.",
            'model'                 => 'claude-opus-4-6',
            'temperature'           => 0.7,
            'max_tokens'            => 1200,
            'response_format'       => 'json',
            'variables'             => '{"context_turns":8}',
            'safety'                => '{"max_context_turns":8}',
            'notes'                 => 'Perfect onboarding template. Shows DB cycle and template compilation in every turn.',
        ],
    ];
}

function story_get_template(array $templates, string $slug): ?array {
    foreach ($templates as $t) {
        if ($t['slug'] === $slug) return $t;
    }
    return null;
}

// ─── Prompt compilation ────────────────────────────────────────────────────────

function story_compile_prompt(array $template, array $story, array $recent_turns): array {
    $vars     = json_decode($template['variables'] ?? '{}', true) ?: [];
    $limit    = $vars['context_turns'] ?? 10;
    $safety   = json_decode($template['safety'] ?? '{}', true) ?: [];
    $max_ctx  = $safety['max_context_turns'] ?? $limit;

    // Build story context string from last N turns
    $context_turns = array_slice($recent_turns, -$max_ctx);
    $ctx_lines = [];
    foreach ($context_turns as $t) {
        $ctx_lines[] = "Turn {$t['turn_number']} [{$t['player_id']}]: {$t['player_action']}";
        $ctx_lines[] = "→ " . ($t['narrative'] ?: substr($t['raw_response'], 0, 200));
    }
    $story_context = implode("\n", $ctx_lines) ?: '(Beginning of story — no prior turns)';

    $world_state_pretty = json_encode(
        json_decode($story['world_state'] ?? '{}', true),
        JSON_PRETTY_PRINT
    );

    $user_prompt = $template['user_prompt_template'];
    $user_prompt = str_replace('{{world_state}}',   $world_state_pretty, $user_prompt);
    $user_prompt = str_replace('{{story_context}}', $story_context,       $user_prompt);
    $user_prompt = str_replace('{{summary}}',       $story['summary'] ?: '(No summary yet)', $user_prompt);
    $user_prompt = str_replace('{{player_action}}', '', $user_prompt); // filled at call time
    $user_prompt = str_replace('{{context_turns}}', (string)$max_ctx, $user_prompt);

    return [
        'system'       => $template['system_prompt'],
        'user_partial' => $user_prompt, // caller appends player_action
    ];
}

// ─── AI call ──────────────────────────────────────────────────────────────────

function story_call_ai(string $system, string $user, array $template): array {
    $model      = $template['model']       ?? 'claude-opus-4-6';
    $max_tokens = (int)($template['max_tokens'] ?? 900);
    $temp       = (float)($template['temperature'] ?? 0.8);

    $api_key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY
             : (getenv('ANTHROPIC_API_KEY') ?: '');

    if (!$api_key) {
        return ['error' => 'ANTHROPIC_API_KEY not configured', 'raw' => ''];
    }

    $payload = json_encode([
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'temperature'=> $temp,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $user]],
    ]);

    $t0 = microtime(true);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $raw_http = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $latency_ms = (int)((microtime(true) - $t0) * 1000);

    if ($raw_http === false) {
        return ['error' => 'cURL failed', 'raw' => '', 'latency_ms' => $latency_ms];
    }

    $envelope = json_decode($raw_http, true);
    $raw_text  = $envelope['content'][0]['text'] ?? '';
    $tokens    = ($envelope['usage']['input_tokens'] ?? 0) + ($envelope['usage']['output_tokens'] ?? 0);

    // Parse JSON from AI response
    $parsed = story_parse_json($raw_text);

    return [
        'raw'        => $raw_text,
        'parsed'     => $parsed,
        'model'      => $model,
        'tokens'     => $tokens,
        'latency_ms' => $latency_ms,
        'http_status'=> $http_status,
        'error'      => $parsed === null ? 'JSON parse failed' : null,
    ];
}

function story_parse_json(string $text): ?array {
    // Strip markdown fences if present
    $clean = preg_replace('/^```(?:json)?\s*/m', '', $text);
    $clean = preg_replace('/\s*```\s*$/m', '', $clean);
    $clean = trim($clean);
    $data  = json_decode($clean, true);
    return is_array($data) ? $data : null;
}

// ─── Turn execution ────────────────────────────────────────────────────────────

function story_take_turn(PDO $db, array $templates, string $story_id, string $player_action, string $player_id = 'local'): array {
    // Load story
    $story = $db->prepare("SELECT * FROM stories WHERE story_id=?")->execute([$story_id]) ? null : null;
    $stmt  = $db->prepare("SELECT * FROM stories WHERE story_id=?");
    $stmt->execute([$story_id]);
    $story = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$story) return ['error' => 'Story not found'];

    // Load template
    $template = story_get_template($templates, $story['template_id']);
    if (!$template) return ['error' => 'Template not found: ' . $story['template_id']];

    // Load recent turns
    $ts = $db->prepare("SELECT * FROM turns WHERE story_id=? ORDER BY turn_number ASC");
    $ts->execute([$story_id]);
    $recent_turns = $ts->fetchAll(PDO::FETCH_ASSOC);

    // Compile prompt
    $compiled = story_compile_prompt($template, $story, $recent_turns);
    $full_user = str_replace('{{player_action}}', $player_action, $compiled['user_partial']);

    // Call AI
    $ai = story_call_ai($compiled['system'], $full_user, $template);
    if (isset($ai['error']) && !$ai['parsed']) {
        return ['error' => $ai['error'], 'raw' => $ai['raw'] ?? ''];
    }

    $parsed     = $ai['parsed'] ?? [];
    $narrative  = $parsed['narrative']    ?? $ai['raw'] ?? '';
    $choices    = $parsed['choices']      ?? [];
    $wildcard   = $parsed['wildcard']     ?? '';
    $state_delta= $parsed['state_delta']  ?? [];
    $chapter_beat = $parsed['chapter_beat'] ?? '';

    // Merge state_delta into world_state
    $world_state = json_decode($story['world_state'], true) ?: [];
    foreach ($state_delta as $k => $v) {
        // Numeric deltas
        if (isset($world_state[$k]) && is_numeric($world_state[$k]) && is_numeric($v)) {
            $world_state[$k] += $v;
        } else {
            $world_state[$k] = $v;
        }
    }
    // Handle items_gained / items_lost (DM template)
    if (!empty($state_delta['items_gained'])) {
        $world_state['items'] = array_values(array_unique(
            array_merge($world_state['items'] ?? [], $state_delta['items_gained'])
        ));
    }
    if (!empty($state_delta['items_lost'])) {
        $world_state['items'] = array_values(array_diff(
            $world_state['items'] ?? [], $state_delta['items_lost']
        ));
    }

    // Rolling summary: append chapter_beat
    $new_summary = $story['summary'];
    if ($chapter_beat) {
        $beats = array_filter(explode("\n", $new_summary));
        $beats[] = "Turn " . ($story['turn_count'] + 1) . ": $chapter_beat";
        if (count($beats) > 30) $beats = array_slice($beats, -30); // keep last 30 beats
        $new_summary = implode("\n", $beats);
    }

    // Write turn
    $turn_id = story_uuid();
    $turn_num = $story['turn_count'] + 1;
    $db->prepare("INSERT INTO turns (turn_id,story_id,turn_number,player_id,player_action,compiled_prompt,raw_response,narrative,choices,wildcard,state_delta,model,tokens_used,latency_ms,server_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([
           $turn_id, $story_id, $turn_num, $player_id,
           $player_action,
           $compiled['system'] . "\n---\n" . $full_user,
           $ai['raw'],
           $narrative,
           json_encode($choices),
           $wildcard,
           json_encode($state_delta),
           $ai['model'],
           $ai['tokens'],
           $ai['latency_ms'],
           gethostname() ?: 'local',
       ]);

    // Update story
    $db->prepare("UPDATE stories SET world_state=?,summary=?,turn_count=turn_count+1,updated_at=datetime('now') WHERE story_id=?")
       ->execute([json_encode($world_state), $new_summary, $story_id]);

    return [
        'turn_id'     => $turn_id,
        'turn_number' => $turn_num,
        'narrative'   => $narrative,
        'choices'     => $choices,
        'wildcard'    => $wildcard,
        'state_delta' => $state_delta,
        'world_state' => $world_state,
        'tutorial'    => $parsed['tutorial'] ?? null,
        'dice_roll'   => $parsed['dice_roll'] ?? null,
        'model'       => $ai['model'],
        'tokens'      => $ai['tokens'],
        'latency_ms'  => $ai['latency_ms'],
    ];
}

// ─── API handler ───────────────────────────────────────────────────────────────

function story_handle_api(string $story_db_path, string $templates_db_path): void {
    header('Content-Type: application/json');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri    = $_SERVER['REQUEST_URI'] ?? '';

    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    $db        = story_db($story_db_path);
    $templates = story_load_templates($templates_db_path);

    // POST /v1/story/turn
    if ($method === 'POST' && strpos($uri, '/v1/story/turn') !== false) {
        $story_id     = $body['story_id']     ?? '';
        $player_action= $body['action']        ?? '';
        $player_id    = $body['player_id']     ?? 'api';

        if (!$story_id || !$player_action) {
            http_response_code(400);
            echo json_encode(['error' => 'story_id and action required']);
            return;
        }

        $result = story_take_turn($db, $templates, $story_id, $player_action, $player_id);
        if (isset($result['error'])) {
            http_response_code(422);
        }
        echo json_encode($result);
        return;
    }

    // POST /v1/story/relay — forward a turn to a remote server
    if ($method === 'POST' && strpos($uri, '/v1/story/relay') !== false) {
        $remote_url   = $body['remote_url']   ?? '';
        $story_id     = $body['story_id']     ?? '';
        $player_action= $body['action']        ?? '';
        $player_id    = $body['player_id']     ?? gethostname();

        if (!$remote_url || !$story_id || !$player_action) {
            http_response_code(400);
            echo json_encode(['error' => 'remote_url, story_id, and action required']);
            return;
        }

        // Load story to get state_hash
        $stmt = $db->prepare("SELECT * FROM stories WHERE story_id=?");
        $stmt->execute([$story_id]);
        $story = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$story) { http_response_code(404); echo json_encode(['error' => 'story not found']); return; }

        $payload = json_encode([
            'story_id'   => $story_id,
            'action'     => $player_action,
            'player_id'  => $player_id,
            'state_hash' => md5($story['world_state']),
        ]);

        $t0 = microtime(true);
        $ch = curl_init(rtrim($remote_url, '/') . '/v1/story/turn');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        $resp = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ms = (int)((microtime(true) - $t0) * 1000);

        // Log relay
        $db->prepare("INSERT INTO federation_log (log_id,story_id,direction,remote_server,endpoint,payload_hash,http_status,response_ms) VALUES (?,?,?,?,?,?,?,?)")
           ->execute([story_uuid(), $story_id, 'sent', $remote_url, '/v1/story/turn', md5($payload), $http_status, $ms]);

        $remote_result = json_decode($resp, true) ?: ['error' => 'bad response', 'raw' => $resp];
        echo json_encode(['relay' => ['remote' => $remote_url, 'http_status' => $http_status, 'latency_ms' => $ms], 'result' => $remote_result]);
        return;
    }

    // GET /v1/story/list
    if ($method === 'GET' && strpos($uri, '/v1/story/list') !== false) {
        $rows = $db->query("SELECT story_id,title,template_id,template_name,turn_count,status,server_origin,created_at FROM stories ORDER BY updated_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['stories' => $rows]);
        return;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Unknown endpoint']);
}

// ─── HTML UI starts here ───────────────────────────────────────────────────────

$db        = story_db($STORY_DB);
$templates = story_load_templates($TEMPLATES_DB);

// ── Process form actions ────────────────────────────────────────────────────
$message = '';
$current_story = null;
$current_turns = [];
$turn_result   = null;

// New story
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $form_action = $_POST['action'];

    if ($form_action === 'new_story') {
        $slug     = preg_replace('/[^a-z0-9_-]/', '', strtolower($_POST['template_slug'] ?? ''));
        $title    = htmlspecialchars(trim($_POST['story_title'] ?? 'Untitled Story'));
        $template = story_get_template($templates, $slug);
        if ($template) {
            $story_id = story_uuid();
            // Default world state (DM template has one)
            $default_ws = '{}';
            if ($slug === 'skynet-dungeon-master') {
                $default_ws = json_encode([
                    'health' => 20, 'ammo' => 12, 'rations' => 3,
                    'items' => ['signal flare', 'salvaged radio'],
                    'location' => 'Ruins of Los Angeles, Sector 7',
                    'mission' => 'Reach Resistance bunker at Griffith Observatory',
                ]);
            }
            $db->prepare("INSERT INTO stories (story_id,title,template_id,template_name,world_state) VALUES (?,?,?,?,?)")
               ->execute([$story_id, $title, $template['slug'], $template['name'], $default_ws]);
            $message = "Story created: <strong>$title</strong>";
            $_GET['story_id'] = $story_id;
        } else {
            $message = "Template not found: $slug";
        }
    }

    if ($form_action === 'take_turn') {
        $story_id     = $_POST['story_id'] ?? '';
        $player_action= trim($_POST['player_action'] ?? '');
        if ($story_id && $player_action) {
            $turn_result = story_take_turn($db, $templates, $story_id, $player_action, 'local');
            if (isset($turn_result['error'])) {
                $message = '<span class="err">Turn error: ' . htmlspecialchars($turn_result['error']) . '</span>';
            }
            $_GET['story_id'] = $story_id;
        }
    }
}

// Load active story for display
$view_story_id = $_GET['story_id'] ?? $_POST['story_id'] ?? '';
if ($view_story_id) {
    $stmt = $db->prepare("SELECT * FROM stories WHERE story_id=?");
    $stmt->execute([$view_story_id]);
    $current_story = $stmt->fetch(PDO::FETCH_ASSOC);

    $ts = $db->prepare("SELECT * FROM turns WHERE story_id=? ORDER BY turn_number DESC LIMIT 10");
    $ts->execute([$view_story_id]);
    $current_turns = array_reverse($ts->fetchAll(PDO::FETCH_ASSOC));
}

// Story list
$all_stories = $db->query("SELECT story_id,title,template_name,turn_count,status,created_at FROM stories ORDER BY updated_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI Story — Skynet Chronicles</title>
<style>
:root {
    --bg:      #0d0f14;
    --surface: #151820;
    --border:  #252a35;
    --accent:  #e8c04a;
    --danger:  #e84a4a;
    --success: #4ae87c;
    --muted:   #6b7280;
    --text:    #dde2ec;
    --mono:    'Courier New', monospace;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); color: var(--text); font-family: system-ui, sans-serif; font-size: 14px; min-height: 100vh; }

/* Layout */
.shell { display: grid; grid-template-columns: 240px 1fr; min-height: 100vh; }
.sidebar { background: var(--surface); border-right: 1px solid var(--border); padding: 16px; display: flex; flex-direction: column; gap: 16px; }
.main { padding: 24px; display: flex; flex-direction: column; gap: 20px; overflow-y: auto; }

/* Typography */
h1 { font-size: 16px; font-weight: 700; color: var(--accent); letter-spacing: 0.05em; text-transform: uppercase; }
h2 { font-size: 13px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 8px; }
.label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px; }

/* Cards */
.card { background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 16px; }
.card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }

/* Story list items */
.story-item { display: block; padding: 10px; border-radius: 4px; border: 1px solid var(--border); margin-bottom: 6px; text-decoration: none; color: var(--text); font-size: 12px; transition: border-color .15s; }
.story-item:hover, .story-item.active { border-color: var(--accent); background: rgba(232,192,74,.06); }
.story-item .title { font-weight: 600; color: var(--accent); }
.story-item .meta  { color: var(--muted); margin-top: 2px; }

/* Forms */
form { display: flex; flex-direction: column; gap: 10px; }
input, select, textarea {
    background: #0a0c10; border: 1px solid var(--border); border-radius: 4px;
    color: var(--text); padding: 8px 10px; font-size: 13px; font-family: inherit;
    width: 100%;
    transition: border-color .15s;
}
input:focus, select:focus, textarea:focus { outline: none; border-color: var(--accent); }
textarea { resize: vertical; min-height: 72px; }
.btn { padding: 8px 16px; border-radius: 4px; border: none; cursor: pointer; font-size: 12px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; transition: opacity .15s; }
.btn:hover { opacity: 0.85; }
.btn-primary  { background: var(--accent); color: #000; }
.btn-ghost    { background: transparent; border: 1px solid var(--border); color: var(--muted); }
.btn-danger   { background: var(--danger); color: #fff; }
.btn-row { display: flex; gap: 8px; flex-wrap: wrap; }

/* Narrative */
.narrative-block { background: #090c11; border-left: 3px solid var(--accent); padding: 14px 16px; border-radius: 0 4px 4px 0; font-size: 14px; line-height: 1.7; white-space: pre-wrap; }
.turn-header { font-size: 11px; color: var(--muted); margin-bottom: 6px; }

/* Choices */
.choices-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px; }
.choice-btn {
    background: #0a0c10; border: 1px solid var(--border); border-radius: 4px;
    color: var(--text); padding: 10px 12px; text-align: left; cursor: pointer;
    font-size: 12px; line-height: 1.5; transition: border-color .15s;
}
.choice-btn:hover { border-color: var(--accent); color: var(--accent); }
.choice-btn.wildcard { border-color: #7c3aed; color: #a78bfa; }
.choice-btn.wildcard:hover { border-color: #a78bfa; }

/* State panel */
.state-table { width: 100%; border-collapse: collapse; font-family: var(--mono); font-size: 11px; }
.state-table td { padding: 3px 8px; border-bottom: 1px solid var(--border); }
.state-table td:first-child { color: var(--muted); width: 40%; }
.state-table td:last-child { color: var(--success); }

/* Dice */
.dice-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; background: #1e1b4b; border: 1px solid #7c3aed; color: #a78bfa; margin-bottom: 10px; }
.dice-badge.success { background: #052e16; border-color: #16a34a; color: #4ade80; }
.dice-badge.fail    { background: #3b0000; border-color: #dc2626; color: #f87171; }

/* Tutorial panel */
.tutorial-panel { background: #051a10; border: 1px solid #16a34a; border-radius: 6px; padding: 14px; font-size: 12px; line-height: 1.6; }
.tutorial-panel h3 { color: #4ade80; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 8px; }
.tutorial-panel .tip { margin-top: 8px; padding: 6px 10px; background: rgba(74,222,128,.07); border-radius: 4px; color: #86efac; }
.tag { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-family: var(--mono); background: #1a2030; border: 1px solid var(--border); margin: 2px; }

/* Message */
.msg { padding: 10px 14px; border-radius: 4px; font-size: 12px; }
.msg-ok  { background: #052e16; border: 1px solid #16a34a; color: #4ade80; }
.msg-err { background: #3b0000; border: 1px solid #dc2626; color: #f87171; }

/* Scrollable turn log */
.turn-log { max-height: 480px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; }

/* API docs */
.api-code { background: #070a0e; border: 1px solid var(--border); border-radius: 4px; padding: 12px; font-family: var(--mono); font-size: 11px; color: #86efac; white-space: pre; overflow-x: auto; }

.skynet-logo { font-size: 11px; color: var(--danger); letter-spacing: 0.15em; text-transform: uppercase; margin-top: auto; }
</style>
</head>
<body>
<div class="shell">

<!-- ── Sidebar ─────────────────────────────────────────────────────── -->
<aside class="sidebar">
    <div>
        <h1>⚡ AI Story</h1>
        <div style="color:var(--muted);font-size:11px;margin-top:4px;">Skynet Chronicles</div>
    </div>

    <!-- New story form -->
    <div>
        <h2>New Campaign</h2>
        <form method="POST">
            <input type="hidden" name="action" value="new_story">
            <div class="label">Story title</div>
            <input type="text" name="story_title" placeholder="Operation Dark Fate…" required>
            <div class="label">Template</div>
            <select name="template_slug" required>
                <?php foreach ($templates as $t): ?>
                <option value="<?= htmlspecialchars($t['slug']) ?>"><?= htmlspecialchars($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" type="submit">▶ Start Story</button>
        </form>
    </div>

    <!-- Story list -->
    <div style="flex:1;overflow-y:auto;">
        <h2>Campaigns</h2>
        <?php foreach ($all_stories as $s): ?>
        <a class="story-item <?= $s['story_id'] === $view_story_id ? 'active' : '' ?>"
           href="?story_id=<?= urlencode($s['story_id']) ?>">
            <div class="title"><?= htmlspecialchars($s['title']) ?></div>
            <div class="meta">
                Turn <?= $s['turn_count'] ?> · <?= htmlspecialchars($s['template_name']) ?>
            </div>
        </a>
        <?php endforeach; ?>
        <?php if (empty($all_stories)): ?>
        <div style="color:var(--muted);font-size:12px;">No campaigns yet.</div>
        <?php endif; ?>
    </div>

    <div class="skynet-logo">⚠ SKYNET ONLINE</div>
</aside>

<!-- ── Main ────────────────────────────────────────────────────────── -->
<main class="main">

<?php if ($message): ?>
<div class="msg <?= str_contains($message, 'error') || str_contains($message, 'err') ? 'msg-err' : 'msg-ok' ?>">
    <?= $message ?>
</div>
<?php endif; ?>

<?php if (!$current_story): ?>
<!-- ── Welcome state ────────────────────────────────────────── -->
<div class="card">
    <div class="card-header"><h2>Welcome to AI Story</h2></div>
    <p style="color:var(--muted);line-height:1.7;font-size:13px;">
        Collaborative AI narrative engine. Each turn compiles a <strong>Template</strong> +
        <strong>World State</strong> + <strong>Player Action</strong> into a prompt, calls Claude,
        parses structured JSON, and writes a new row to <code>story.db</code>.<br><br>
        Start a campaign on the left. Use <strong>Tutorial Mode</strong> to see the architecture
        explained inside the story itself.
    </p>
    <div style="margin-top:16px;">
        <h2>API Endpoints</h2>
        <div class="api-code">POST /v1/story/turn
Body: { "story_id": "...", "action": "I run north", "player_id": "server-2" }
Returns: { turn_id, narrative, choices[], wildcard, state_delta, world_state }

POST /v1/story/relay
Body: { "story_id": "...", "action": "...", "remote_url": "https://other-server.example" }
Returns: { relay: {remote, http_status, latency_ms}, result: {turn} }

GET  /v1/story/list
Returns: { stories: [...] }</div>
    </div>
</div>

<?php else: // Story loaded ?>

<!-- ── Story header ─────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <div>
            <h1 style="font-size:18px;"><?= htmlspecialchars($current_story['title']) ?></h1>
            <div style="color:var(--muted);font-size:11px;margin-top:3px;">
                <?= htmlspecialchars($current_story['template_name']) ?>
                · Turn <?= $current_story['turn_count'] ?>
                · <?= htmlspecialchars($current_story['story_id']) ?>
            </div>
        </div>
        <a href="?story_id=<?= urlencode($current_story['story_id']) ?>&export=json"
           class="btn btn-ghost" style="font-size:11px;">⬇ Export JSON</a>
    </div>

    <!-- Summary -->
    <?php if ($current_story['summary']): ?>
    <div style="background:#090c11;border-radius:4px;padding:10px 12px;font-size:12px;color:var(--muted);line-height:1.6;margin-bottom:12px;">
        <strong style="color:var(--text);">Chapter Log</strong><br>
        <?= nl2br(htmlspecialchars($current_story['summary'])) ?>
    </div>
    <?php endif; ?>

    <!-- World State -->
    <?php
    $ws = json_decode($current_story['world_state'], true) ?: [];
    if ($ws):
    ?>
    <details>
        <summary style="cursor:pointer;font-size:11px;color:var(--muted);margin-bottom:8px;">▶ World State</summary>
        <table class="state-table">
            <?php foreach ($ws as $k => $v): ?>
            <tr>
                <td><?= htmlspecialchars($k) ?></td>
                <td><?= htmlspecialchars(is_array($v) ? implode(', ', $v) : (string)$v) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </details>
    <?php endif; ?>
</div>

<!-- ── Turn result (just took a turn) ────────────────────────── -->
<?php if ($turn_result && !isset($turn_result['error'])): ?>
<div class="card" style="border-color:var(--accent);">
    <div class="card-header">
        <h2>Turn <?= $turn_result['turn_number'] ?></h2>
        <span style="font-size:11px;color:var(--muted);">
            <?= $turn_result['model'] ?> · <?= number_format($turn_result['tokens']) ?> tokens · <?= $turn_result['latency_ms'] ?>ms
        </span>
    </div>

    <?php if ($turn_result['dice_roll']): $dr = $turn_result['dice_roll']; ?>
    <div class="dice-badge <?= in_array($dr['outcome'], ['success','critical_success']) ? 'success' : (in_array($dr['outcome'], ['fail','critical_fail']) ? 'fail' : '') ?>">
        🎲 <?= strtoupper($dr['type']) ?> → <?= $dr['result'] ?> (<?= htmlspecialchars($dr['outcome']) ?>)
    </div>
    <?php endif; ?>

    <div class="narrative-block"><?= htmlspecialchars($turn_result['narrative']) ?></div>

    <!-- Choices as quick-action buttons -->
    <?php if ($turn_result['choices']): ?>
    <div style="margin-top:14px;">
        <div class="label">Choose your next action</div>
        <div class="choices-grid">
            <?php foreach ($turn_result['choices'] as $i => $choice): ?>
            <form method="POST" style="display:contents;">
                <input type="hidden" name="action" value="take_turn">
                <input type="hidden" name="story_id" value="<?= htmlspecialchars($current_story['story_id']) ?>">
                <input type="hidden" name="player_action" value="<?= htmlspecialchars($choice) ?>">
                <button class="choice-btn" type="submit">
                    <strong><?= chr(65+$i) ?>.</strong> <?= htmlspecialchars($choice) ?>
                </button>
            </form>
            <?php endforeach; ?>
            <?php if ($turn_result['wildcard']): ?>
            <form method="POST" style="display:contents;">
                <input type="hidden" name="action" value="take_turn">
                <input type="hidden" name="story_id" value="<?= htmlspecialchars($current_story['story_id']) ?>">
                <input type="hidden" name="player_action" value="<?= htmlspecialchars($turn_result['wildcard']) ?>">
                <button class="choice-btn wildcard" type="submit">
                    ★ <?= htmlspecialchars($turn_result['wildcard']) ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tutorial panel -->
    <?php if ($turn_result['tutorial']): $tut = $turn_result['tutorial']; ?>
    <div class="tutorial-panel" style="margin-top:14px;">
        <h3>🔧 Under the Hood</h3>
        <p><?= htmlspecialchars($tut['what_happened'] ?? '') ?></p>
        <?php if (!empty($tut['state_keys_read'])): ?>
        <div style="margin-top:6px;"><span style="color:var(--muted);">State read:</span>
            <?php foreach ($tut['state_keys_read'] as $k): ?><span class="tag"><?= htmlspecialchars($k) ?></span><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($tut['state_keys_written'])): ?>
        <div style="margin-top:4px;"><span style="color:var(--muted);">State written:</span>
            <?php foreach ($tut['state_keys_written'] as $k): ?><span class="tag"><?= htmlspecialchars($k) ?></span><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($tut['tip']): ?>
        <div class="tip">💡 <?= htmlspecialchars($tut['tip']) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Player action input ────────────────────────────────────── -->
<div class="card">
    <h2>Your Action</h2>
    <form method="POST">
        <input type="hidden" name="action" value="take_turn">
        <input type="hidden" name="story_id" value="<?= htmlspecialchars($current_story['story_id']) ?>">
        <textarea name="player_action" placeholder="Describe your action… (e.g. 'I crouch behind the rusted car and scan the skyline for HK drones')" required></textarea>
        <div class="btn-row">
            <button class="btn btn-primary" type="submit">⚡ Take Turn (local)</button>
            <button class="btn btn-ghost" type="button" onclick="document.getElementById('relay-panel').style.display='block'">📡 Pass to Remote Server</button>
        </div>
    </form>

    <!-- Federation relay panel -->
    <div id="relay-panel" style="display:none;margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
        <h2>Federation Relay</h2>
        <p style="color:var(--muted);font-size:12px;margin-bottom:10px;">
            Send this turn to a remote server's <code>/v1/story/turn</code> endpoint.
            The remote generates the scene; result is stored locally.
        </p>
        <form method="POST" action="/v1/story/relay">
            <input type="hidden" name="story_id" value="<?= htmlspecialchars($current_story['story_id']) ?>">
            <div class="label">Remote server URL</div>
            <input type="url" name="remote_url" placeholder="https://other-server.example">
            <div class="label">Your action</div>
            <textarea name="action" placeholder="Describe what you do…"></textarea>
            <button class="btn btn-ghost" type="submit">📡 Relay Turn</button>
        </form>
    </div>
</div>

<!-- ── Turn log ───────────────────────────────────────────────── -->
<?php if ($current_turns): ?>
<div class="card">
    <div class="card-header"><h2>Turn Log (last 10)</h2></div>
    <div class="turn-log">
        <?php foreach (array_reverse($current_turns) as $turn): ?>
        <div>
            <div class="turn-header">
                Turn <?= $turn['turn_number'] ?>
                · <?= htmlspecialchars($turn['player_id']) ?>
                · <?= $turn['model'] ?>
                · <?= $turn['latency_ms'] ?>ms
                · <?= $turn['created_at'] ?>
            </div>
            <div style="font-size:12px;color:var(--muted);margin-bottom:4px;">
                <em><?= htmlspecialchars($turn['player_action']) ?></em>
            </div>
            <div class="narrative-block" style="font-size:13px;">
                <?= htmlspecialchars($turn['narrative'] ?: substr($turn['raw_response'], 0, 400)) ?>
            </div>
            <?php
            $choices = json_decode($turn['choices'], true) ?: [];
            if ($choices):
            ?>
            <div style="margin-top:6px;font-size:11px;color:var(--muted);">
                Choices: <?= htmlspecialchars(implode(' | ', $choices)) ?>
                <?= $turn['wildcard'] ? ' | ★ ' . htmlspecialchars($turn['wildcard']) : '' ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php endif; // end story loaded ?>
</main>
</div>

<script>
// Auto-scroll turn log to bottom
document.querySelectorAll('.turn-log').forEach(el => el.scrollTop = el.scrollHeight);

// Clicking a choice fills the textarea (UX enhancement)
document.querySelectorAll('.choice-btn[data-action]').forEach(btn => {
    btn.addEventListener('click', () => {
        const ta = document.querySelector('textarea[name="player_action"]');
        if (ta) ta.value = btn.dataset.action;
    });
});
</script>
</body>
</html>
<?php
// ── JSON export ─────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'json' && $current_story) {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="story-' . substr($current_story['story_id'],0,8) . '.json"');
    $turns_stmt = $db->prepare("SELECT * FROM turns WHERE story_id=? ORDER BY turn_number");
    $turns_stmt->execute([$current_story['story_id']]);
    echo json_encode([
        'story' => $current_story,
        'turns' => $turns_stmt->fetchAll(PDO::FETCH_ASSOC),
    ], JSON_PRETTY_PRINT);
    exit;
}
?>
