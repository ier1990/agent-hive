<?php
// Shared Story engine (admin + API), PHP 7.3+

if (!function_exists('story_db_path')) {
  function story_db_path(): string {
    $root = defined('PRIVATE_ROOT') ? (string)PRIVATE_ROOT : '/web/private';
    return rtrim($root, "/\\") . '/db/memory/story.db';
  }
}

if (!function_exists('story_agent_db_path')) {
  function story_agent_db_path(): string {
    $root = defined('PRIVATE_ROOT') ? (string)PRIVATE_ROOT : '/web/private';
    return rtrim($root, "/\\") . '/db/agent_tools.db';
  }
}

if (!function_exists('story_new_id')) {
  function story_new_id(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
  }
}

if (!function_exists('story_db')) {
  function story_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $path = story_db_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys=ON;');
    $pdo->exec('PRAGMA journal_mode=WAL;');

    story_migrate($pdo);
    return $pdo;
  }
}

if (!function_exists('story_migrate')) {
  function story_migrate(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS stories (
      story_id TEXT PRIMARY KEY,
      title TEXT NOT NULL,
      template_name TEXT NOT NULL,
      world_state TEXT NOT NULL DEFAULT "{}",
      summary TEXT NOT NULL DEFAULT "",
      turn_count INTEGER NOT NULL DEFAULT 0,
      last_narrative TEXT NOT NULL DEFAULT "",
      last_choices TEXT NOT NULL DEFAULT "[]",
      last_wildcard TEXT NOT NULL DEFAULT "",
      status TEXT NOT NULL DEFAULT "active",
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS turns (
      turn_id TEXT PRIMARY KEY,
      story_id TEXT NOT NULL,
      turn_number INTEGER NOT NULL,
      player_id TEXT NOT NULL DEFAULT "local",
      input_mode TEXT NOT NULL DEFAULT "choice",
      selected_key TEXT NOT NULL DEFAULT "",
      player_action TEXT NOT NULL,
      narrative TEXT NOT NULL DEFAULT "",
      choices TEXT NOT NULL DEFAULT "[]",
      wildcard TEXT NOT NULL DEFAULT "",
      state_delta TEXT NOT NULL DEFAULT "{}",
      tool_results TEXT NOT NULL DEFAULT "[]",
      model TEXT NOT NULL DEFAULT "",
      tokens_used INTEGER NOT NULL DEFAULT 0,
      latency_ms INTEGER NOT NULL DEFAULT 0,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY(story_id) REFERENCES stories(story_id) ON DELETE CASCADE
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS federation_log (
      log_id TEXT PRIMARY KEY,
      story_id TEXT NOT NULL,
      remote_server TEXT NOT NULL,
      endpoint TEXT NOT NULL,
      request_body TEXT NOT NULL,
      http_status INTEGER,
      response_ms INTEGER,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $db->exec('CREATE INDEX IF NOT EXISTS idx_story_updated ON stories(updated_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_turn_story_turn ON turns(story_id, turn_number DESC)');
  }
}

if (!function_exists('story_ejson')) {
  function story_ejson($value): string {
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : '{}';
  }
}

if (!function_exists('story_default_world_state')) {
  function story_default_world_state(string $templateName): array {
    $tn = strtolower(trim($templateName));

    // Stronger default schema for Skynet-style stories.
    if (strpos($tn, 'skynet') !== false) {
      $base = [
        'resources' => 0,
        'health' => 10,
        'danger_level' => 2,
        'location' => 'ruined_street',
        'duct_route_mapped' => 0,
        'drones_alerted' => 0,
        'ammo' => 6,
      ];
      if (strpos($tn, 'dm') !== false || strpos($tn, 'dungeon') !== false) {
        $base['health'] = 20;
        $base['ammo'] = 12;
        $base['rations'] = 3;
      }
      return $base;
    }

    return [
      'resources' => 0,
      'health' => 10,
      'danger_level' => 1,
      'location' => 'starting_point',
    ];
  }
}

if (!function_exists('story_merge_missing_defaults')) {
  function story_merge_missing_defaults(array $worldState, string $templateName): array {
    $defaults = story_default_world_state($templateName);
    foreach ($defaults as $k => $v) {
      if (!array_key_exists($k, $worldState)) {
        $worldState[$k] = $v;
      }
    }
    return $worldState;
  }
}

if (!function_exists('story_table_columns')) {
  function story_table_columns(PDO $db, string $table): array {
    static $cache = [];
    $k = strtolower($table);
    if (isset($cache[$k]) && is_array($cache[$k])) return $cache[$k];
    $out = [];
    try {
      $rows = $db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
      if (is_array($rows)) {
        foreach ($rows as $r) {
          $name = isset($r['name']) ? (string)$r['name'] : '';
          if ($name !== '') $out[$name] = true;
        }
      }
    } catch (Throwable $t) {
      $out = [];
    }
    $cache[$k] = $out;
    return $out;
  }
}

if (!function_exists('story_col_exists')) {
  function story_col_exists(PDO $db, string $table, string $col): bool {
    $cols = story_table_columns($db, $table);
    return isset($cols[$col]);
  }
}

if (!function_exists('story_templates_list')) {
  function story_templates_list(): array {
    if (!function_exists('ai_templates_list_names')) return [];
    $names = ai_templates_list_names('payload');
    $prefixed = [];
    $out = [];
    foreach ($names as $name) {
      $n = (string)$name;
      $ln = strtolower($n);
      if (strpos($ln, 'story_') === 0) {
        $prefixed[] = $n;
        continue;
      }
      if (strpos($ln, 'story') !== false || strpos($ln, 'narrator') !== false || strpos($ln, 'dungeon') !== false || strpos($ln, 'dm') !== false) {
        $out[] = $n;
      }
    }
    if (!empty($prefixed)) return $prefixed;
    if (empty($out)) return $names;
    return $out;
  }
}

if (!function_exists('story_default_payload')) {
  function story_default_payload(): array {
    return [
      'system' => 'You are a cooperative story narrator. Keep the pace moving and stay PG-13.',
      'prompt' => "World state:\n{{world_state}}\n\nStory context:\n{{story_context}}\n\nSummary:\n{{summary}}\n\nPlayer action:\n{{player_action}}\n\nReturn JSON only with keys narrative, choices (3 items), wildcard, state_delta, chapter_beat.",
      'options' => [
        'temperature' => 0.7,
        'max_tokens' => 900,
      ],
    ];
  }
}

if (!function_exists('story_template_payload')) {
  function story_template_payload(string $templateName, array $bindings): array {
    if (!function_exists('ai_templates_compile_payload_by_name')) {
      return story_default_payload();
    }
    $payload = ai_templates_compile_payload_by_name($templateName, $bindings, 'payload');
    if (!is_array($payload) || empty($payload)) {
      return story_default_payload();
    }
    if (empty($payload['prompt']) && !empty($payload['user'])) {
      $payload['prompt'] = (string)$payload['user'];
    }
    if (empty($payload['system'])) {
      $payload['system'] = story_default_payload()['system'];
    }
    if (empty($payload['prompt'])) {
      $payload['prompt'] = story_default_payload()['prompt'];
    }
    if (!isset($payload['options']) || !is_array($payload['options'])) {
      $payload['options'] = [];
    }
    return $payload;
  }
}

if (!function_exists('story_template_exists')) {
  function story_template_exists(string $templateName): bool {
    if ($templateName === '') return false;
    if (!function_exists('ai_templates_get_text_by_name')) return false;
    $txt = ai_templates_get_text_by_name($templateName, 'payload');
    if (trim((string)$txt) !== '') return true;
    $txt2 = ai_templates_get_text_by_name($templateName, '');
    return trim((string)$txt2) !== '';
  }
}

if (!function_exists('story_format_context')) {
  function story_format_context(array $turns): string {
    if (empty($turns)) return '(Start of story)';
    $lines = [];
    foreach ($turns as $t) {
      $lines[] = 'Turn ' . (int)($t['turn_number'] ?? 0) . ' [' . (string)($t['selected_key'] ?? '-') . ']: ' . (string)($t['player_action'] ?? '');
      $lines[] = 'Narrative: ' . (string)($t['narrative'] ?? '');
    }
    return implode("\n", $lines);
  }
}

if (!function_exists('story_turns_block')) {
  function story_turns_block(array $turns, int $limit = 10): string {
    if (count($turns) > $limit) {
      $turns = array_slice($turns, -$limit);
    }
    $lines = [];
    foreach ($turns as $t) {
      $num = isset($t['turn_number']) ? (int)$t['turn_number'] : 0;
      $act = isset($t['player_action']) ? (string)$t['player_action'] : '';
      $nar = isset($t['narrative']) ? (string)$t['narrative'] : '';
      $lines[] = 'Turn ' . $num . ' Action: ' . $act;
      $lines[] = 'Turn ' . $num . ' Result: ' . $nar;
    }
    return implode("\n", $lines);
  }
}

if (!function_exists('story_maybe_compress_summary')) {
  function story_maybe_compress_summary(string $storyType, array $worldState, array $turns, string $currentSummary): string {
    if (!story_template_exists('story_summarize')) {
      return $currentSummary;
    }

    $bindings = [
      'story_type' => $storyType,
      'world_state' => json_encode($worldState, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      'turns_block' => story_turns_block($turns, 10),
      'summary' => $currentSummary,
    ];

    $payload = story_template_payload('story_summarize', $bindings);
    $system = isset($payload['system']) ? (string)$payload['system'] : '';
    $prompt = isset($payload['prompt']) ? (string)$payload['prompt'] : '';
    $options = isset($payload['options']) && is_array($payload['options']) ? $payload['options'] : [];

    $ai = story_ai_chat($system, $prompt, $options);
    if (empty($ai['ok'])) {
      return $currentSummary;
    }

    $parsed = story_parse_json_block((string)($ai['text'] ?? ''));
    if (!is_array($parsed)) {
      return $currentSummary;
    }

    $newSummary = trim((string)($parsed['summary'] ?? ''));
    if ($newSummary === '') {
      return $currentSummary;
    }

    return $newSummary;
  }
}

if (!function_exists('story_parse_json_block')) {
  function story_parse_json_block(string $raw): ?array {
    $txt = trim($raw);
    if ($txt === '') return null;

    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $txt, $m)) {
      $txt = trim((string)$m[1]);
    }

    $arr = json_decode($txt, true);
    if (is_array($arr)) return $arr;

    // Retry with lightweight repairs for common model mistakes (e.g. trailing commas).
    $fixed = preg_replace('/,\s*([}\]])/', '$1', $txt);
    if (is_string($fixed)) {
      $arrFixed = json_decode(trim($fixed), true);
      if (is_array($arrFixed)) return $arrFixed;
    }

    if (preg_match('/\{[\s\S]*\}/', $txt, $m2)) {
      $candidate = (string)$m2[0];
      $arr2 = json_decode($candidate, true);
      if (is_array($arr2)) return $arr2;

      $candidateFixed = preg_replace('/,\s*([}\]])/', '$1', $candidate);
      if (is_string($candidateFixed)) {
        $arr3 = json_decode(trim($candidateFixed), true);
        if (is_array($arr3)) return $arr3;
      }
    }
    return null;
  }
}

if (!function_exists('story_ai_chat')) {
  function story_ai_chat(string $system, string $prompt, array $options = []): array {
    $s = function_exists('ai_settings_get') ? ai_settings_get() : [];
    $provider = strtolower((string)($s['provider'] ?? 'local'));
    $base = rtrim((string)($s['base_url'] ?? ''), '/');
    $model = (string)($s['model'] ?? 'openai/gpt-oss-20b');
    $apiKey = (string)($s['api_key'] ?? '');
    $isOllamaLike = ($provider === 'ollama') || (bool)preg_match('~:11434(?:/|$)~', $base);

    $temperature = isset($options['temperature']) ? (float)$options['temperature'] : 0.7;
    $maxTokens = isset($options['max_tokens']) ? (int)$options['max_tokens'] : 900;
    $timeout = isset($s['timeout_seconds']) ? (int)$s['timeout_seconds'] : 120;
    if ($timeout < 1) $timeout = 120;

    if ($isOllamaLike) {
      $url = preg_match('~/v1$~', $base) ? substr($base, 0, -3) . '/api/chat' : $base . '/api/chat';
      $payload = [
        'model' => $model,
        'messages' => [
          ['role' => 'system', 'content' => $system],
          ['role' => 'user', 'content' => $prompt],
        ],
        'stream' => false,
        'options' => ['num_predict' => $maxTokens],
      ];
    } else {
      if ($base === '') {
        return ['ok' => false, 'error' => 'AI base_url is not configured'];
      }
      if (!preg_match('~/v1$~', $base)) {
        $base .= '/v1';
      }
      $url = $base . '/chat/completions';
      $payload = [
        'model' => $model,
        'messages' => [
          ['role' => 'system', 'content' => $system],
          ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => $temperature,
        'max_tokens' => $maxTokens,
      ];
    }

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($apiKey !== '') {
      $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      CURLOPT_SSL_VERIFYPEER => 0,
      CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $latency = (int)((microtime(true) - $t0) * 1000);

    if (!is_string($body)) {
      return ['ok' => false, 'error' => 'HTTP request failed: ' . $err, 'latency_ms' => $latency];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
      return ['ok' => false, 'error' => 'Invalid JSON from AI provider', 'raw' => substr($body, 0, 800), 'latency_ms' => $latency];
    }

    if ($http >= 400) {
      $msg = 'HTTP ' . $http;
      if (isset($decoded['error']['message'])) $msg .= ': ' . (string)$decoded['error']['message'];
      return ['ok' => false, 'error' => $msg, 'raw' => $decoded, 'latency_ms' => $latency];
    }

    $text = '';
    if (isset($decoded['choices'][0]['message']['content'])) {
      $text = (string)$decoded['choices'][0]['message']['content'];
    }
    if ($text === '' && isset($decoded['message']['content'])) {
      $text = (string)$decoded['message']['content'];
    }

    $tokens = 0;
    if (isset($decoded['usage']['total_tokens'])) {
      $tokens = (int)$decoded['usage']['total_tokens'];
    } elseif (isset($decoded['usage']['prompt_tokens']) || isset($decoded['usage']['completion_tokens'])) {
      $tokens = (int)($decoded['usage']['prompt_tokens'] ?? 0) + (int)($decoded['usage']['completion_tokens'] ?? 0);
    }

    return [
      'ok' => true,
      'text' => $text,
      'model' => $model,
      'tokens' => $tokens,
      'latency_ms' => $latency,
      'http_status' => $http,
      'raw' => $decoded,
    ];
  }
}

if (!function_exists('story_run_agent_tool')) {
  function story_run_agent_tool(string $toolName, array $params): array {
    if (strpos($toolName, 'story_') !== 0) {
      return ['ok' => false, 'error' => 'Only story_* tools are allowed'];
    }

    $dbPath = story_agent_db_path();
    if (!is_file($dbPath)) {
      return ['ok' => false, 'error' => 'agent_tools.db not found'];
    }

    try {
      $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
      $stmt = $db->prepare('SELECT id,name,code,language,is_approved FROM tools WHERE name = ? LIMIT 1');
      $stmt->execute([$toolName]);
      $tool = $stmt->fetch();
      if (!$tool) return ['ok' => false, 'error' => 'Tool not found'];
      if ((int)$tool['is_approved'] !== 1) return ['ok' => false, 'error' => 'Tool is not approved'];

      $lang = strtolower((string)($tool['language'] ?? 'php'));
      if ($lang !== 'php') {
        return ['ok' => false, 'error' => 'Only PHP story_* tools are supported here'];
      }

      $code = (string)($tool['code'] ?? '');
      if ($code === '') return ['ok' => false, 'error' => 'Tool code is empty'];

      $__params = $params;
      $wrapped = 'return (function($params) { ' . $code . ' })($__params);';
      $result = eval($wrapped);

      // lightweight run stats (best effort)
      $db->prepare('UPDATE tools SET run_count = run_count + 1, last_run_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$tool['id']]);

      return ['ok' => true, 'result' => $result];
    } catch (Throwable $t) {
      return ['ok' => false, 'error' => $t->getMessage()];
    }
  }
}

if (!function_exists('story_list_agent_tools')) {
  function story_list_agent_tools(): array {
    $dbPath = story_agent_db_path();
    if (!is_file($dbPath)) return [];

    try {
      $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
      $rows = $db->query("SELECT name,description FROM tools WHERE is_approved = 1 AND name LIKE 'story_%' ORDER BY name ASC")->fetchAll();
      return is_array($rows) ? $rows : [];
    } catch (Throwable $t) {
      return [];
    }
  }
}

if (!function_exists('story_create')) {
  function story_create(PDO $db, string $title, string $templateName): array {
    $storyId = story_new_id();
    $cols = ['story_id', 'title'];
    $vals = [$storyId, $title];

    if (story_col_exists($db, 'stories', 'template_name')) {
      $cols[] = 'template_name';
      $vals[] = $templateName;
    }
    if (story_col_exists($db, 'stories', 'template_id')) {
      $cols[] = 'template_id';
      $vals[] = $templateName;
    }
    if (story_col_exists($db, 'stories', 'world_state')) {
      $cols[] = 'world_state';
      $vals[] = story_ejson(story_default_world_state($templateName));
    }

    $ph = implode(',', array_fill(0, count($cols), '?'));
    $sql = 'INSERT INTO stories (' . implode(',', $cols) . ') VALUES (' . $ph . ')';
    $stmt = $db->prepare($sql);
    $stmt->execute($vals);
    return ['story_id' => $storyId];
  }
}

if (!function_exists('story_get')) {
  function story_get(PDO $db, string $storyId): ?array {
    $stmt = $db->prepare('SELECT * FROM stories WHERE story_id = ? LIMIT 1');
    $stmt->execute([$storyId]);
    $row = $stmt->fetch();
    return $row ?: null;
  }
}

if (!function_exists('story_list')) {
  function story_list(PDO $db, int $limit = 30): array {
    $selTemplate = '"" AS template_name';
    $hasTemplateName = story_col_exists($db, 'stories', 'template_name');
    $hasTemplateId = story_col_exists($db, 'stories', 'template_id');
    if ($hasTemplateName && $hasTemplateId) {
      $selTemplate = 'COALESCE(template_name, template_id) AS template_name';
    } elseif ($hasTemplateName) {
      $selTemplate = 'template_name';
    } elseif ($hasTemplateId) {
      $selTemplate = 'template_id AS template_name';
    }
    $stmt = $db->prepare('SELECT story_id,title,' . $selTemplate . ',turn_count,status,updated_at,created_at FROM stories ORDER BY updated_at DESC LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
  }
}

if (!function_exists('story_turns')) {
  function story_turns(PDO $db, string $storyId, int $limit = 12): array {
    $stmt = $db->prepare('SELECT * FROM turns WHERE story_id = ? ORDER BY turn_number DESC LIMIT ?');
    $stmt->bindValue(1, $storyId, PDO::PARAM_STR);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (!is_array($rows)) return [];
    return array_reverse($rows);
  }
}

if (!function_exists('story_pick_action_from_choice')) {
  function story_pick_action_from_choice(array $story, string $choiceKey): string {
    $choices = json_decode((string)($story['last_choices'] ?? '[]'), true);
    if (!is_array($choices)) $choices = [];
    $key = strtoupper(trim($choiceKey));

    if ($key === 'W') {
      return (string)($story['last_wildcard'] ?? '');
    }

    $map = ['A' => 0, 'B' => 1, 'C' => 2];
    if (!isset($map[$key])) return '';
    $idx = $map[$key];
    return isset($choices[$idx]) ? (string)$choices[$idx] : '';
  }
}

if (!function_exists('story_take_turn')) {
  function story_take_turn(PDO $db, string $storyId, string $inputMode, string $selectedKey, string $noteText, string $playerId = 'local'): array {
    $story = story_get($db, $storyId);
    if (!$story) return ['ok' => false, 'error' => 'story_not_found'];

    $action = '';
    if ($inputMode === 'note') {
      $action = trim($noteText);
      if ($action === '') return ['ok' => false, 'error' => 'note_required'];
    } else {
      $action = story_pick_action_from_choice($story, $selectedKey);
      if ($action === '') {
        $current = story_current_choices($db, $story);
        $k = strtoupper(trim($selectedKey));
        if ($k === 'W') {
          $action = (string)($current['wildcard'] ?? '');
        } else {
          $map = ['A' => 0, 'B' => 1, 'C' => 2];
          if (isset($map[$k]) && isset($current['choices'][$map[$k]])) {
            $action = (string)$current['choices'][$map[$k]];
          }
        }
      }
      if ($action === '') return ['ok' => false, 'error' => 'choice_not_available'];
    }

    $recent = story_turns($db, $storyId, 10);
    $worldState = json_decode((string)$story['world_state'], true);
    if (!is_array($worldState)) $worldState = [];

    $bindings = [
      'story_id' => (string)$story['story_id'],
      'title' => (string)$story['title'],
      'world_state' => json_encode($worldState, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      'story_context' => story_format_context($recent),
      'summary' => (string)($story['summary'] !== '' ? $story['summary'] : '(none)'),
      'player_action' => $action,
      'selected_key' => strtoupper((string)$selectedKey),
    ];

    $templateName = '';
    if (isset($story['template_name']) && trim((string)$story['template_name']) !== '') {
      $templateName = (string)$story['template_name'];
    } elseif (isset($story['template_id']) && trim((string)$story['template_id']) !== '') {
      $templateName = (string)$story['template_id'];
    }

    // Ensure required keys always exist so state deltas have ground truth to mutate.
    $worldState = story_merge_missing_defaults($worldState, $templateName);
    $bindings['world_state'] = json_encode($worldState, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $payload = story_template_payload($templateName, $bindings);
    $system = (string)($payload['system'] ?? '');
    if (strpos(strtolower($templateName), 'skynet') !== false) {
      $system .= "\n\nState rules:\n- You MUST update danger_level, health, and location in every state_delta.\n- health and danger_level must be JSON numbers (no plus sign).\n- location must be a concrete place string.";
    }
    $prompt = (string)($payload['prompt'] ?? '');
    $options = isset($payload['options']) && is_array($payload['options']) ? $payload['options'] : [];

    $ai = story_ai_chat($system, $prompt, $options);
    if (empty($ai['ok'])) {
      return ['ok' => false, 'error' => (string)($ai['error'] ?? 'ai_call_failed')];
    }

    $parsed = story_parse_json_block((string)$ai['text']);
    if (!is_array($parsed)) {
      $parsed = [
        'narrative' => (string)$ai['text'],
        'choices' => [
          'Move forward carefully.',
          'Take a defensive posture and observe.',
          'Try a high-risk shortcut.',
        ],
        'wildcard' => 'Attempt something unconventional and risky.',
        'state_delta' => [],
      ];
    }

    $narrative = trim((string)($parsed['narrative'] ?? ''));
    if ($narrative === '') {
      $narrative = 'The world goes quiet for a second. Then the next decision is yours.';
    }

    $choices = isset($parsed['choices']) && is_array($parsed['choices']) ? array_values($parsed['choices']) : [];
    $choices = array_values(array_filter(array_map(function ($c) {
      return trim((string)$c);
    }, $choices), function ($c) {
      return $c !== '';
    }));
    while (count($choices) < 3) {
      $choices[] = 'Continue with caution.';
    }
    if (count($choices) > 3) {
      $choices = array_slice($choices, 0, 3);
    }

    $wildcard = trim((string)($parsed['wildcard'] ?? ''));
    if ($wildcard === '') {
      $wildcard = 'Take an unpredictable action.';
    }

    $stateDelta = isset($parsed['state_delta']) && is_array($parsed['state_delta']) ? $parsed['state_delta'] : [];
    if (strpos(strtolower($templateName), 'skynet') !== false) {
      if (!array_key_exists('danger_level', $stateDelta)) $stateDelta['danger_level'] = 0;
      if (!array_key_exists('health', $stateDelta)) $stateDelta['health'] = 0;
      if (!array_key_exists('location', $stateDelta)) $stateDelta['location'] = (string)($worldState['location'] ?? 'unknown');

      // Normalize numeric deltas if model returned numeric strings such as \"+1\".
      if (isset($stateDelta['danger_level']) && is_string($stateDelta['danger_level']) && preg_match('/^[+-]?[0-9]+$/', $stateDelta['danger_level'])) {
        $stateDelta['danger_level'] = (int)$stateDelta['danger_level'];
      }
      if (isset($stateDelta['health']) && is_string($stateDelta['health']) && preg_match('/^[+-]?[0-9]+$/', $stateDelta['health'])) {
        $stateDelta['health'] = (int)$stateDelta['health'];
      }
    }
    foreach ($stateDelta as $k => $v) {
      if (isset($worldState[$k]) && is_numeric($worldState[$k]) && is_numeric($v)) {
        $worldState[$k] += (0 + $v);
      } else {
        $worldState[$k] = $v;
      }
    }

    $toolResults = [];
    $toolRequests = isset($parsed['tool_requests']) && is_array($parsed['tool_requests']) ? $parsed['tool_requests'] : [];
    $toolRequests = array_slice($toolRequests, 0, 3);
    foreach ($toolRequests as $req) {
      if (!is_array($req)) continue;
      $name = isset($req['name']) ? (string)$req['name'] : '';
      if ($name === '') continue;
      $args = isset($req['args']) && is_array($req['args']) ? $req['args'] : [];
      $toolResults[] = [
        'name' => $name,
        'run' => story_run_agent_tool($name, $args),
      ];
    }

    $turnNumber = (int)$story['turn_count'] + 1;
    $chapterBeat = trim((string)($parsed['chapter_beat'] ?? ''));
    $summary = (string)$story['summary'];
    if ($chapterBeat !== '') {
      $beats = array_filter(explode("\n", $summary));
      $beats[] = 'Turn ' . $turnNumber . ': ' . $chapterBeat;
      if (count($beats) > 30) {
        $beats = array_slice($beats, -30);
      }
      $summary = implode("\n", $beats);
    }
    if ($turnNumber % 10 === 0) {
      $summaryTurns = $recent;
      $summaryTurns[] = [
        'turn_number' => $turnNumber,
        'player_action' => $action,
        'narrative' => $narrative,
      ];
      $summary = story_maybe_compress_summary($templateName, $worldState, $summaryTurns, $summary);
    }

    $turnId = story_new_id();

    $turnCols = ['turn_id','story_id','turn_number','player_id','player_action','narrative','choices','wildcard','state_delta','model','tokens_used','latency_ms'];
    $turnVals = [$turnId,$storyId,$turnNumber,$playerId,$action,$narrative,story_ejson($choices),$wildcard,story_ejson($stateDelta),(string)($ai['model'] ?? ''),(int)($ai['tokens'] ?? 0),(int)($ai['latency_ms'] ?? 0)];
    if (story_col_exists($db, 'turns', 'input_mode')) { $turnCols[] = 'input_mode'; $turnVals[] = $inputMode; }
    if (story_col_exists($db, 'turns', 'selected_key')) { $turnCols[] = 'selected_key'; $turnVals[] = strtoupper($selectedKey); }
    if (story_col_exists($db, 'turns', 'tool_results')) { $turnCols[] = 'tool_results'; $turnVals[] = story_ejson($toolResults); }
    if (story_col_exists($db, 'turns', 'compiled_prompt')) { $turnCols[] = 'compiled_prompt'; $turnVals[] = $system . "\n---\n" . $prompt; }
    if (story_col_exists($db, 'turns', 'raw_response')) { $turnCols[] = 'raw_response'; $turnVals[] = (string)($ai['text'] ?? $narrative); }
    if (story_col_exists($db, 'turns', 'server_id')) { $turnCols[] = 'server_id'; $turnVals[] = (string)(gethostname() ?: 'local'); }
    $turnPh = implode(',', array_fill(0, count($turnCols), '?'));
    $ins = $db->prepare('INSERT INTO turns (' . implode(',', $turnCols) . ') VALUES (' . $turnPh . ')');
    $ins->execute($turnVals);

    $set = [];
    $vals = [];
    if (story_col_exists($db, 'stories', 'world_state')) { $set[] = 'world_state = ?'; $vals[] = story_ejson($worldState); }
    if (story_col_exists($db, 'stories', 'summary')) { $set[] = 'summary = ?'; $vals[] = $summary; }
    if (story_col_exists($db, 'stories', 'turn_count')) { $set[] = 'turn_count = turn_count + 1'; }
    if (story_col_exists($db, 'stories', 'last_narrative')) { $set[] = 'last_narrative = ?'; $vals[] = $narrative; }
    if (story_col_exists($db, 'stories', 'last_choices')) { $set[] = 'last_choices = ?'; $vals[] = story_ejson($choices); }
    if (story_col_exists($db, 'stories', 'last_wildcard')) { $set[] = 'last_wildcard = ?'; $vals[] = $wildcard; }
    if (story_col_exists($db, 'stories', 'updated_at')) { $set[] = 'updated_at = CURRENT_TIMESTAMP'; }
    if (empty($set)) {
      return ['ok' => false, 'error' => 'stories_table_not_compatible'];
    }
    $vals[] = $storyId;
    $upd = $db->prepare('UPDATE stories SET ' . implode(', ', $set) . ' WHERE story_id = ?');
    $upd->execute($vals);

    return [
      'ok' => true,
      'story_id' => $storyId,
      'turn_id' => $turnId,
      'turn_number' => $turnNumber,
      'selected_key' => strtoupper($selectedKey),
      'player_action' => $action,
      'narrative' => $narrative,
      'choices' => $choices,
      'wildcard' => $wildcard,
      'state_delta' => $stateDelta,
      'world_state' => $worldState,
      'tool_results' => $toolResults,
      'model' => (string)($ai['model'] ?? ''),
      'tokens' => (int)($ai['tokens'] ?? 0),
      'latency_ms' => (int)($ai['latency_ms'] ?? 0),
    ];
  }
}

if (!function_exists('story_current_choices')) {
  function story_current_choices(PDO $db, array $story): array {
    $choices = [];
    $wildcard = '';

    if (isset($story['last_choices'])) {
      $decoded = json_decode((string)$story['last_choices'], true);
      if (is_array($decoded)) $choices = $decoded;
    }
    if (isset($story['last_wildcard'])) {
      $wildcard = (string)$story['last_wildcard'];
    }

    if (empty($choices) || $wildcard === '') {
      try {
        $stmt = $db->prepare('SELECT choices,wildcard FROM turns WHERE story_id = ? ORDER BY turn_number DESC LIMIT 1');
        $stmt->execute([(string)$story['story_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
          if (empty($choices)) {
            $d = json_decode((string)($row['choices'] ?? '[]'), true);
            if (is_array($d)) $choices = $d;
          }
          if ($wildcard === '') $wildcard = (string)($row['wildcard'] ?? '');
        }
      } catch (Throwable $t) {
      }
    }

    return [
      'choices' => is_array($choices) ? $choices : [],
      'wildcard' => $wildcard,
    ];
  }
}

if (!function_exists('story_start')) {
  function story_start(PDO $db, string $storyId, string $playerId = 'system'): array {
    // Seed first turn as note to create opening + A/B/C choices.
    return story_take_turn(
      $db,
      $storyId,
      'note',
      '',
      'Open the story now. Present exactly three clear options (A/B/C) and one wildcard option.',
      $playerId
    );
  }
}

if (!function_exists('story_export')) {
  function story_export(PDO $db, string $storyId): ?array {
    $story = story_get($db, $storyId);
    if (!$story) return null;
    $stmt = $db->prepare('SELECT * FROM turns WHERE story_id = ? ORDER BY turn_number ASC');
    $stmt->execute([$storyId]);
    return [
      'story' => $story,
      'turns' => $stmt->fetchAll(),
    ];
  }
}

if (!function_exists('story_relay_turn')) {
  function story_relay_turn(PDO $db, string $storyId, string $remoteUrl, string $selectedKey, string $noteText, string $playerId): array {
    $url = trim($remoteUrl);
    if ($url === '' || !preg_match('~^https?://~i', $url)) {
      return ['ok' => false, 'error' => 'invalid_remote_url'];
    }

    $payload = [
      'story_id' => $storyId,
      'input_mode' => ($noteText !== '' ? 'note' : 'choice'),
      'selected_key' => $selectedKey,
      'note' => $noteText,
      'player_id' => $playerId,
    ];

    $t0 = microtime(true);
    $ch = curl_init(rtrim($url, '/') . '/v1/story/turn');
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 120,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $ms = (int)((microtime(true) - $t0) * 1000);

    $reqBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $db->prepare('INSERT INTO federation_log (log_id,story_id,remote_server,endpoint,request_body,http_status,response_ms) VALUES (?,?,?,?,?,?,?)')
      ->execute([story_new_id(), $storyId, $url, '/v1/story/turn', (string)$reqBody, $http, $ms]);

    if (!is_string($resp)) {
      return ['ok' => false, 'error' => 'relay_failed:' . $err, 'http_status' => $http];
    }

    $decoded = json_decode($resp, true);
    if (!is_array($decoded)) {
      return ['ok' => false, 'error' => 'relay_invalid_json', 'http_status' => $http, 'raw' => substr($resp, 0, 800)];
    }

    return ['ok' => true, 'http_status' => $http, 'latency_ms' => $ms, 'result' => $decoded];
  }
}
