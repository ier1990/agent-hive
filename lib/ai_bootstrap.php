<?php
// Shared AI settings + helpers (PHP 7.3 compatible)
//
// Goal: centralize provider/base/model/key selection and validation so
// admin pages + v1 endpoints don't each re-implement it differently.

if (!function_exists('ai_settings_db_path')) {
  function ai_settings_db_path(): string {
    $root = defined('PRIVATE_ROOT') ? (string)PRIVATE_ROOT : '/web/private';
    return rtrim($root, "/\\") . '/db/codewalker_settings.db';
  }
}

if (!function_exists('env')) {
    function env($key, $default = null) {
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }
}

if (!function_exists('ai_settings_get_raw')) {
  function ai_settings_get_raw(): array {
    $out = [];
    $dbPath = ai_settings_db_path();
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }

    try {
      $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);

      $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)');

      $defaults = [
        'backend' => 'lmstudio',
        'provider' => 'local',
        'base_url' => 'http://127.0.0.1:1234',
        'api_key' => null,
        'model' => 'openai/gpt-oss-20b',
        'model_timeout_seconds' => 900,

        // Optional provider-specific bases.
        'openai_base_url' => null,
        'llm_base_url' => null,
        'ollama_base_url' => null,
      ];
      $ins = $pdo->prepare('INSERT OR IGNORE INTO settings(key, value) VALUES(?, ?)');
      foreach ($defaults as $k => $v) {
        $ins->execute([(string)$k, json_encode($v)]);
      }

      $stmt = $pdo->query('SELECT key, value FROM settings');
      $rows = $stmt ? $stmt->fetchAll() : [];
      foreach ($rows as $r) {
        $k = (string)($r['key'] ?? '');
        if ($k === '') continue;
        $raw = (string)($r['value'] ?? '');
        $val = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
          $out[$k] = $val;
        } else {
          $out[$k] = $raw;
        }
      }
      return $out;
    } catch (Throwable $t) {
      return [];
    }
  }
}

if (!function_exists('ai_base_ensure_v1')) {
  function ai_base_ensure_v1(string $base): string {
    $b = rtrim($base, '/');
    if ($b === '') return '';
    if (!preg_match('~/v1$~', $b)) {
      $b .= '/v1';
    }
    return $b;
  }
}

if (!function_exists('ai_settings_get')) {
  function ai_settings_get(): array {
    // Inputs
    $raw = ai_settings_get_raw();

    $backend = strtolower((string)($raw['backend'] ?? ''));
    $model = (string)($raw['model'] ?? '');
    $timeout = (int)($raw['model_timeout_seconds'] ?? 0);
    if ($timeout < 1) $timeout = 120;

    // Provider detection: check for explicit provider field first, fall back to backend
    $provider = strtolower((string)($raw['provider'] ?? ''));
    if ($provider === '') {
      // Legacy: detect from backend field
      if ($backend === 'openai') $provider = 'openai';
      elseif ($backend === 'ollama') $provider = 'ollama';
      else $provider = 'local';
    }

    // Env defaults (bootstrap.php already loads /web/private/.env)
    $envOpenaiBase = (string)env('OPENAI_BASE_URL', '');
    $envOpenaiKey  = (string)env('OPENAI_API_KEY', '');
    $envOpenaiModel = (string)env('OPENAI_MODEL', '');

    $envLlmBase = (string)env('LLM_BASE_URL', '');
    $envLlmKey  = (string)env('LLM_API_KEY', '');

    $envOllamaBase = (string)env('OLLAMA_BASE_URL', '');
    if ($envOllamaBase === '') {
      $envOllamaBase = (string)env('OLLAMA_HOST', '');
    }

    // Provider base resolution
    $base = (string)($raw['base_url'] ?? '');
    $openaiBase = (string)($raw['openai_base_url'] ?? '');
    $llmBase = (string)($raw['llm_base_url'] ?? '');
    $ollamaBase = (string)($raw['ollama_base_url'] ?? '');

    if ($provider === 'openai') {
      $baseResolved = $envOpenaiBase ?: ($openaiBase ?: 'https://api.openai.com');
      $baseResolved = ai_base_ensure_v1($baseResolved);
      $keyResolved = $envOpenaiKey ?: (string)($raw['api_key'] ?? '');
      $modelResolved = $envOpenaiModel ?: ($model ?: 'gpt-4o-mini');
    } elseif ($provider === 'ollama') {
      $baseResolved = $envOllamaBase ?: ($ollamaBase ?: ($llmBase ?: $base));
      if ($baseResolved === '') $baseResolved = 'http://127.0.0.1:11434';
      $baseResolved = ai_base_ensure_v1($baseResolved);
      $keyResolved = $envLlmKey ?: (string)($raw['api_key'] ?? '');
      $modelResolved = $model ?: 'llama3';
    } else {
      // All other providers: local, openrouter, anthropic, custom, etc.
      // For 'local' (LM Studio), allow LLM_BASE_URL/LLM_API_KEY override
      // For openrouter, anthropic, custom: only use database values (no env override)
      
      if ($provider === 'local' || $provider === 'lmstudio') {
        $baseResolved = $envLlmBase ?: ($llmBase ?: $base);
        if ($baseResolved === '') $baseResolved = 'http://127.0.0.1:1234';
        $keyResolved = $envLlmKey ?: (string)($raw['api_key'] ?? '');
      } else {
        // openrouter, anthropic, custom: use database values only
        $baseResolved = $base;
        if ($baseResolved === '') {
          $baseResolved = $llmBase ?: '';
          if ($baseResolved === '') $baseResolved = 'http://127.0.0.1:1234';
        }
        $keyResolved = (string)($raw['api_key'] ?? '');
      }
      
      $baseResolved = ai_base_ensure_v1($baseResolved);
      $modelResolved = $model ?: 'openai/gpt-oss-20b';
    }

    return [
      'provider' => $provider,
      'backend' => $backend,
      'base_url' => $baseResolved,
      'api_key' => (string)$keyResolved,
      'model' => (string)$modelResolved,
      'timeout_seconds' => $timeout,

      // Useful for UI
      'env' => [
        'OPENAI_BASE_URL' => $envOpenaiBase,
        'OPENAI_API_KEY' => $envOpenaiKey !== '' ? 'set' : '',
        'OPENAI_MODEL' => $envOpenaiModel,
        'LLM_BASE_URL' => $envLlmBase,
        'LLM_API_KEY' => $envLlmKey !== '' ? 'set' : '',
        'OLLAMA_BASE_URL' => $envOllamaBase,
      ],
      'raw' => $raw,
    ];
  }
}

// Resolve provider defaults without requiring the DB backend to be switched.
// Intended for admin UIs that want "Defaults" to come from .env + existing settings.
if (!function_exists('ai_settings_resolve_for_provider')) {
  function ai_settings_resolve_for_provider(string $provider, array $raw = null): array {
    $provider = strtolower(trim($provider));
    // Allow any provider, but default to 'local' for unknown ones
    $knownProviders = ['openai', 'ollama', 'local', 'openrouter', 'anthropic', 'custom'];
    if (!in_array($provider, $knownProviders, true)) {
      // Treat unknown providers as custom
      if (empty($raw['base_url'] ?? '')) {
        $provider = 'local';
      }
    }

    if ($raw === null) {
      $raw = ai_settings_get_raw();
    }

    $rawModel = (string)($raw['model'] ?? '');
    $timeout = (int)($raw['model_timeout_seconds'] ?? 0);
    if ($timeout < 1) $timeout = 120;

    // Env defaults (bootstrap.php already loads /web/private/.env)
    $envOpenaiBase = (string)env('OPENAI_BASE_URL', '');
    $envOpenaiKey  = (string)env('OPENAI_API_KEY', '');
    $envOpenaiModel = (string)env('OPENAI_MODEL', '');

    $envLlmBase = (string)env('LLM_BASE_URL', '');
    $envLlmKey  = (string)env('LLM_API_KEY', '');

    $envOllamaBase = (string)env('OLLAMA_BASE_URL', '');
    if ($envOllamaBase === '') {
      $envOllamaBase = (string)env('OLLAMA_HOST', '');
    }

    $base = (string)($raw['base_url'] ?? '');
    $openaiBase = (string)($raw['openai_base_url'] ?? '');
    $llmBase = (string)($raw['llm_base_url'] ?? '');
    $ollamaBase = (string)($raw['ollama_base_url'] ?? '');

    if ($provider === 'openai') {
      $baseResolved = $envOpenaiBase ?: ($openaiBase ?: 'https://api.openai.com');
      $baseResolved = ai_base_ensure_v1($baseResolved);
      $keyResolved = $envOpenaiKey ?: (string)($raw['api_key'] ?? '');
      $modelResolved = $envOpenaiModel ?: ($rawModel ?: 'gpt-4o-mini');
    } elseif ($provider === 'anthropic') {
      // Anthropic provider
      $baseResolved = $envOpenaiBase ?: ($base ?: 'https://api.anthropic.com');
      $baseResolved = ai_base_ensure_v1($baseResolved);
      $keyResolved = $envOpenaiKey ?: (string)($raw['api_key'] ?? '');
      $modelResolved = $rawModel ?: 'claude-3-5-sonnet-20241022';
    } elseif ($provider === 'openrouter') {
      // OpenRouter provider
      $baseResolved = $base ?: 'https://openrouter.ai/api/v1';
      $baseResolved = ai_base_ensure_v1($baseResolved);
      $keyResolved = $envOpenaiKey ?: (string)($raw['api_key'] ?? '');
      $modelResolved = $rawModel ?: 'openai/gpt-4-turbo';
    } elseif ($provider === 'custom') {
      // Custom provider - requires base_url
      $baseResolved = $base ?: '';
      if ($baseResolved !== '') {
        $baseResolved = ai_base_ensure_v1($baseResolved);
      }
      $keyResolved = (string)($raw['api_key'] ?? '');
      $modelResolved = $rawModel ?: '';
    } elseif ($provider === 'ollama') {
      $baseResolved = $envOllamaBase ?: ($ollamaBase ?: ($envLlmBase ?: ($llmBase ?: $base)));
      if ($baseResolved === '') $baseResolved = 'http://127.0.0.1:11434';
      $baseResolved = ai_base_ensure_v1($baseResolved);
      $keyResolved = $envLlmKey ?: (string)($raw['api_key'] ?? '');
      $modelResolved = $rawModel ?: 'llama3';
    } else {
      // Local (LM Studio) - default
      $baseResolved = $envLlmBase ?: ($llmBase ?: $base);
      if ($baseResolved === '') $baseResolved = 'http://127.0.0.1:1234';
      $baseResolved = ai_base_ensure_v1($baseResolved);
      $keyResolved = $envLlmKey ?: (string)($raw['api_key'] ?? '');
      $modelResolved = $rawModel ?: 'openai/gpt-oss-20b';
    }

    return [
      'provider' => $provider,
      'base_url' => (string)$baseResolved,
      'api_key' => (string)$keyResolved,
      'model' => (string)$modelResolved,
      'timeout_seconds' => (int)$timeout,
      'env' => [
        'OPENAI_BASE_URL' => $envOpenaiBase,
        'OPENAI_API_KEY' => $envOpenaiKey !== '' ? 'set' : '',
        'OPENAI_MODEL' => $envOpenaiModel,
        'LLM_BASE_URL' => $envLlmBase,
        'LLM_API_KEY' => $envLlmKey !== '' ? 'set' : '',
        'OLLAMA_BASE_URL' => $envOllamaBase,
      ],
    ];
  }
}

if (!function_exists('ai_settings_validate')) {
  function ai_settings_validate(array $s): array {
    $errs = [];
    $provider = strtolower((string)($s['provider'] ?? ''));
    $base = (string)($s['base_url'] ?? '');
    $model = (string)($s['model'] ?? '');
    $key = (string)($s['api_key'] ?? '');

    $validProviders = ['openai', 'local', 'ollama', 'openrouter', 'anthropic', 'custom'];
    if ($provider === '' || !in_array($provider, $validProviders, true)) {
      $errs[] = 'Invalid provider.';
    }
    if ($base === '' || !preg_match('~^https?://~i', $base)) {
      $errs[] = 'Base URL must start with http:// or https://';
    }
    if ($model === '') {
      $errs[] = 'Model is required.';
    }
    
    // Require API key for providers that need it
    $needsKey = ['openai', 'openrouter', 'anthropic'];
    if (in_array($provider, $needsKey, true) && $key === '') {
      $errs[] = ucfirst($provider) . ' provider requires an API key.';
    }
    
    return $errs;
  }
}

if (!function_exists('ai_settings_save')) {
  function ai_settings_save(array $new): bool {
    $provider = strtolower((string)($new['provider'] ?? ''));
    $baseUrl = (string)($new['base_url'] ?? '');
    $apiKey = (string)($new['api_key'] ?? '');
    $model = (string)($new['model'] ?? '');
    $timeout = (int)($new['timeout_seconds'] ?? 0);
    if ($timeout < 1) $timeout = 120;

    // Map provider to backend (store provider directly for new ones)
    $backend = $provider;
    // Legacy: map common names
    if ($provider === 'local' || $provider === 'lmstudio') $backend = 'lmstudio';
    elseif ($provider === 'openai') $backend = 'openai';
    elseif ($provider === 'ollama') $backend = 'ollama';
    // New providers (openrouter, anthropic, custom) stored as-is with provider field

    $dbPath = ai_settings_db_path();
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }

    try {
      $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
      $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)');

      $stmt = $pdo->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');

      $stmt->execute(['backend', json_encode($backend)]);
      $stmt->execute(['provider', json_encode($provider)]);
      // Keep base_url in DB without /v1 (human-editable); derive /v1 at runtime.
      $baseNoV1 = preg_replace('~/v1$~', '', rtrim($baseUrl, '/'));
      $stmt->execute(['base_url', json_encode($baseNoV1)]);
      $stmt->execute(['api_key', json_encode($apiKey)]);
      $stmt->execute(['model', json_encode($model)]);
      $stmt->execute(['model_timeout_seconds', json_encode($timeout)]);

      // Provider-specific hints (optional)
      if ($provider === 'openai') {
        $stmt->execute(['openai_base_url', json_encode($baseNoV1)]);
      } elseif ($provider === 'ollama') {
        $stmt->execute(['ollama_base_url', json_encode($baseNoV1)]);
      } else {
        $stmt->execute(['llm_base_url', json_encode($baseNoV1)]);
      }

      return true;
    } catch (Throwable $t) {
      return false;
    }
  }
}

if (!function_exists('ai_base_without_v1')) {
  function ai_base_without_v1(string $base): string {
    $b = rtrim($base, '/');
    if (preg_match('~/v1$~', $b)) {
      $b = preg_replace('~/v1$~', '', $b);
    }
    return rtrim((string)$b, '/');
  }
}

if (!function_exists('ai_list_models')) {
  function ai_list_models(string $baseUrl, string $provider, string $apiKey = ''): array {
    $provider = strtolower(trim($provider));
    $baseUrl = rtrim($baseUrl, '/');

    // Prefer Ollama tags when not OpenAI.
    if ($provider !== 'openai') {
      $ollamaBase = ai_base_without_v1($baseUrl);
      if ($ollamaBase !== '') {
        $url = $ollamaBase . '/api/tags';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_TIMEOUT => 3,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp !== false && $code >= 200 && $code < 300) {
          $data = json_decode($resp, true);
          $models = [];
          if (is_array($data) && isset($data['models']) && is_array($data['models'])) {
            foreach ($data['models'] as $m) {
              $name = is_array($m) ? (string)($m['name'] ?? '') : '';
              if ($name !== '') $models[] = $name;
            }
          }
          $models = array_values(array_unique($models));
          sort($models);
          return ['ok' => true, 'models' => $models, 'source' => 'ollama', 'error' => ''];
        }
        // Not fatal; may still support /models
        $tagsErr = ($resp === false ? ('cURL: ' . $err) : ('HTTP ' . $code));

        // Try OpenAI-compatible models list
        $url = $baseUrl . '/models';
        $headers = [];
        if ($apiKey !== '') $headers[] = 'Authorization: Bearer ' . $apiKey;
        $ch = curl_init($url);
        $opts = [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_TIMEOUT => 4,
        ];
        if (!empty($headers)) $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp !== false && $code >= 200 && $code < 300) {
          $data = json_decode($resp, true);
          $models = [];
          if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $m) {
              $id = is_array($m) ? (string)($m['id'] ?? '') : '';
              if ($id !== '') $models[] = $id;
            }
          }
          $models = array_values(array_unique($models));
          sort($models);
          if (!empty($models)) {
            return ['ok' => true, 'models' => $models, 'source' => 'models', 'error' => ''];
          }
        }

        return ['ok' => false, 'models' => [], 'source' => 'models', 'error' => 'tags failed (' . $tagsErr . '), models failed (' . ($resp === false ? ('cURL: ' . $err) : ('HTTP ' . $code)) . ')'];
      }
    }

    // OpenAI
    if ($provider === 'openai') {
      if ($apiKey === '') {
        return ['ok' => false, 'models' => [], 'source' => 'openai', 'error' => 'Missing API key'];
      }
      $url = $baseUrl . '/models';
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 4,
      ]);
      $resp = curl_exec($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err = curl_error($ch);
      curl_close($ch);
      if ($resp === false) {
        return ['ok' => false, 'models' => [], 'source' => 'openai', 'error' => 'cURL: ' . $err];
      }
      if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'models' => [], 'source' => 'openai', 'error' => 'HTTP ' . $code];
      }
      $data = json_decode($resp, true);
      $models = [];
      if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
        foreach ($data['data'] as $m) {
          $id = is_array($m) ? (string)($m['id'] ?? '') : '';
          if ($id !== '') $models[] = $id;
        }
      }
      $models = array_values(array_unique($models));
      sort($models);
      return ['ok' => true, 'models' => $models, 'source' => 'openai', 'error' => ''];
    }

    return ['ok' => false, 'models' => [], 'source' => 'none', 'error' => 'Model list not available'];
  }
}

// --- Saved AI connection profiles (separate DB) ---

if (!function_exists('ai_saved_profiles_db_path')) {
  function ai_saved_profiles_db_path(): string {
    $root = defined('PRIVATE_ROOT') ? (string)PRIVATE_ROOT : '/web/private';
    return rtrim($root, "/\\") . '/db/ai_saved_profiles.db';
  }
}

if (!function_exists('ai_saved_profiles_hash')) {
  function ai_saved_profiles_hash(array $candidate): string {
    $provider = strtolower(trim((string)($candidate['provider'] ?? '')));
    $base = (string)($candidate['base_url'] ?? '');
    $base = ai_base_without_v1($base);
    $base = rtrim($base, '/');
    $apiKey = (string)($candidate['api_key'] ?? '');
    $model = (string)($candidate['model'] ?? '');
    $timeout = (int)($candidate['timeout_seconds'] ?? 0);

    $data = [
      'provider' => $provider,
      'base_url' => $base,
      'api_key' => $apiKey,
      'model' => $model,
      'timeout_seconds' => $timeout,
    ];

    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) $json = serialize($data);
    return hash('sha256', (string)$json);
  }
}

if (!function_exists('ai_saved_profiles_ensure_schema')) {
  function ai_saved_profiles_ensure_schema(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS ai_saved_profiles (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL,
      provider TEXT NOT NULL,
      base_url TEXT NOT NULL,
      api_key TEXT NOT NULL,
      model TEXT NOT NULL,
      timeout_seconds INTEGER NOT NULL,
      hash TEXT NOT NULL UNIQUE,
      created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_saved_profiles_created ON ai_saved_profiles(created_at DESC)');
  }
}

if (!function_exists('ai_saved_profiles_add')) {
  function ai_saved_profiles_add(string $name, array $candidate): array {
    $name = trim($name);
    if ($name === '') {
      return ['ok' => false, 'status' => 'error', 'hash' => '', 'error' => 'Profile name is required'];
    }

    $provider = strtolower(trim((string)($candidate['provider'] ?? '')));
    $base = (string)($candidate['base_url'] ?? '');
    $base = ai_base_without_v1($base);
    $base = rtrim($base, '/');
    $apiKey = (string)($candidate['api_key'] ?? '');
    $model = (string)($candidate['model'] ?? '');
    $timeout = (int)($candidate['timeout_seconds'] ?? 0);
    if ($timeout < 1) $timeout = 120;

    $hash = ai_saved_profiles_hash([
      'provider' => $provider,
      'base_url' => $base,
      'api_key' => $apiKey,
      'model' => $model,
      'timeout_seconds' => $timeout,
    ]);

    $dbPath = ai_saved_profiles_db_path();
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }

    try {
      $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
      ai_saved_profiles_ensure_schema($pdo);

      $stmt = $pdo->prepare('INSERT OR IGNORE INTO ai_saved_profiles(name, provider, base_url, api_key, model, timeout_seconds, hash) VALUES(?,?,?,?,?,?,?)');
      $stmt->execute([$name, $provider, $base, $apiKey, $model, $timeout, $hash]);

      $changes = (int)$pdo->query('SELECT changes() AS c')->fetch(PDO::FETCH_ASSOC)['c'];
      if ($changes < 1) {
        return ['ok' => true, 'status' => 'exists', 'hash' => $hash, 'error' => ''];
      }

      return ['ok' => true, 'status' => 'inserted', 'hash' => $hash, 'error' => ''];
    } catch (Throwable $t) {
      return ['ok' => false, 'status' => 'error', 'hash' => $hash, 'error' => 'Failed to save profile'];
    }
  }
}

if (!function_exists('ai_saved_profiles_recent')) {
  function ai_saved_profiles_recent(int $limit = 20): array {
    $limit = (int)$limit;
    if ($limit < 1) $limit = 1;
    if ($limit > 200) $limit = 200;

    $dbPath = ai_saved_profiles_db_path();
    if (!is_file($dbPath)) return [];

    try {
      $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
      ai_saved_profiles_ensure_schema($pdo);

      $stmt = $pdo->prepare('SELECT name, provider, base_url, api_key, model, timeout_seconds, hash, created_at FROM ai_saved_profiles ORDER BY datetime(created_at) DESC, id DESC LIMIT :n');
      $stmt->bindValue(':n', $limit, PDO::PARAM_INT);
      $stmt->execute();
      $rows = $stmt->fetchAll();
      return is_array($rows) ? $rows : [];
    } catch (Throwable $t) {
      return [];
    }
  }
}

if (!function_exists('ai_saved_profiles_get')) {
  function ai_saved_profiles_get(string $hash): ?array {
    $hash = trim($hash);
    if ($hash === '') return null;

    $dbPath = ai_saved_profiles_db_path();
    if (!is_file($dbPath)) return null;

    try {
      $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
      ai_saved_profiles_ensure_schema($pdo);
      $stmt = $pdo->prepare('SELECT name, provider, base_url, api_key, model, timeout_seconds, hash, created_at FROM ai_saved_profiles WHERE hash = :h LIMIT 1');
      $stmt->bindValue(':h', $hash, PDO::PARAM_STR);
      $stmt->execute();
      $row = $stmt->fetch();
      return is_array($row) ? $row : null;
    } catch (Throwable $t) {
      return null;
    }
  }
}

if (!function_exists('ai_saved_profiles_apply_to_active')) {
  function ai_saved_profiles_apply_to_active(string $hash): bool {
    $row = ai_saved_profiles_get($hash);
    if (!is_array($row)) return false;

    $provider = strtolower(trim((string)($row['provider'] ?? 'local')));
    $baseNoV1 = rtrim((string)($row['base_url'] ?? ''), '/');
    $base = ai_base_ensure_v1($baseNoV1);

    $candidate = [
      'provider' => $provider,
      'base_url' => $base,
      'api_key' => (string)($row['api_key'] ?? ''),
      'model' => (string)($row['model'] ?? ''),
      'timeout_seconds' => (int)($row['timeout_seconds'] ?? 120),
    ];

    $errs = ai_settings_validate($candidate);
    if (!empty($errs)) return false;
    return ai_settings_save($candidate);
  }
}
