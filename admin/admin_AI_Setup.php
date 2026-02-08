<?php
// Admin AI Setup - Simplified Version
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$IS_EMBED = in_array(strtolower($_GET['embed'] ?? ''), ['1','true','yes'], true);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['csrf_ai_setup'])) { $_SESSION['csrf_ai_setup'] = bin2hex(random_bytes(32)); }
function csrf_input(){ echo '<input type="hidden" name="csrf_token" value="'.h($_SESSION['csrf_ai_setup']).'">'; }
function csrf_valid($t){ return isset($_SESSION['csrf_ai_setup']) && hash_equals($_SESSION['csrf_ai_setup'], (string)$t); }

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$errors = [];
$success = '';
$editing = null;
$modelList = null;
$formData = null;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid($_POST['csrf_token'] ?? '')) {
  
  // Test connection and load models
  if ($action === 'test_models') {
    $provider = trim($_POST['provider'] ?? 'local');
    $baseUrl = rtrim(trim($_POST['base_url'] ?? ''), '/');
    $apiKey = trim($_POST['api_key'] ?? '');
    $timeout = (int)($_POST['timeout'] ?? 120);
    
    if ($baseUrl && !preg_match('~^https?://~i', $baseUrl)) {
      $errors[] = 'Invalid base URL';
    } else {
      $baseUrl = ai_base_ensure_v1($baseUrl);
      $modelList = ai_list_models($baseUrl, $provider, $apiKey);
      
      // Preserve form state
      $formData = [
        'provider' => $provider,
        'base_url' => $baseUrl,
        'api_key' => $apiKey,
        'timeout' => $timeout,
      ];
      
      if (empty($modelList['ok'])) {
        $errors[] = 'Connection test failed: ' . ($modelList['error'] ?? 'Unknown error');
      } else {
        $success = 'Connected! Found ' . count($modelList['models'] ?? []) . ' models';
      }
    }
  }
  
  // Quick switch between connections
  if ($action === 'activate') {
    $hash = trim($_POST['hash'] ?? '');
    if (ai_saved_profiles_apply_to_active($hash)) {
      $success = 'Connection activated';
    } else {
      $errors[] = 'Failed to activate connection';
    }
  }
  
  // Save new or edited connection
  if ($action === 'save') {
    $name = trim($_POST['name'] ?? '');
    $provider = trim($_POST['provider'] ?? 'local');
    $baseUrl = rtrim(trim($_POST['base_url'] ?? ''), '/');
    $model = trim($_POST['model'] ?? '');
    $apiKey = trim($_POST['api_key'] ?? '');
    $timeout = (int)($_POST['timeout'] ?? 120);
    $activate = !empty($_POST['activate']);
    
    // Basic validation
    if (!$name) $errors[] = 'Name required';
    if ($baseUrl && !preg_match('~^https?://~i', $baseUrl)) $errors[] = 'Invalid base URL';
    if (!$model) $errors[] = 'Model required';
    
    if (empty($errors)) {
      $baseUrl = ai_base_ensure_v1($baseUrl);
      $config = [
        'provider' => $provider,
        'base_url' => $baseUrl,
        'model' => $model,
        'api_key' => $apiKey,
        'timeout_seconds' => $timeout,
      ];
      
      $result = ai_saved_profiles_add($name, $config);
      if (!empty($result['ok'])) {
        if ($activate) {
          ai_saved_profiles_apply_to_active($result['hash']);
          $success = "Connection '{$name}' saved and activated";
        } else {
          $success = "Connection '{$name}' saved";
        }
        // Clear form mode
        header('Location: ?');
        exit;
      } else {
        $errors[] = 'Failed to save connection';
      }
    } else {
      // Preserve form data on validation error
      $formData = [
        'name' => $name,
        'provider' => $provider,
        'base_url' => $baseUrl,
        'model' => $model,
        'api_key' => $apiKey,
        'timeout' => $timeout,
      ];
    }
  }
  
  // Delete connection
  if ($action === 'delete') {
    $hash = trim($_POST['hash'] ?? '');
    if ($hash) {
      // Check if it's the active connection
      $active = ai_settings_get();
      $activeHash = ai_saved_profiles_hash([
        'provider' => $active['provider'] ?? '',
        'base_url' => $active['base_url'] ?? '',
        'api_key' => $active['api_key'] ?? '',
        'model' => $active['model'] ?? '',
        'timeout_seconds' => $active['timeout_seconds'] ?? 120,
      ]);
      
      if ($hash === $activeHash) {
        $errors[] = 'Cannot delete active connection. Switch to another connection first.';
      } else {
        // Delete from DB
        $dbPath = function_exists('ai_saved_profiles_db_path') ? ai_saved_profiles_db_path() : '/web/private/db/ai_saved_profiles.db';
        try {
          $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          ]);
          $stmt = $pdo->prepare('DELETE FROM saved_profiles WHERE hash = ?');
          $stmt->execute([$hash]);
          $success = 'Connection deleted';
        } catch (Throwable $e) {
          $errors[] = 'Delete failed: ' . $e->getMessage();
        }
      }
    }
  }
}

// Get edit mode
$editHash = $_GET['edit'] ?? '';
if ($editHash) {
  $profiles = ai_saved_profiles_recent(100);
  foreach ($profiles as $p) {
    if (($p['hash'] ?? '') === $editHash) {
      $editing = $p;
      break;
    }
  }
}

// Current active settings
$active = ai_settings_get();
$activeHash = ai_saved_profiles_hash([
  'provider' => $active['provider'] ?? '',
  'base_url' => $active['base_url'] ?? '',
  'api_key' => $active['api_key'] ?? '',
  'model' => $active['model'] ?? '',
  'timeout_seconds' => $active['timeout_seconds'] ?? 120,
]);

// Recent connections
$connections = ai_saved_profiles_recent(20);

// Provider presets

$presets = [
  'local' => ['url' => 'http://127.0.0.1:1234/v1', 'label' => 'Local (LM Studio)', 'icon' => '🖥️'],
  'ollama' => ['url' => 'http://127.0.0.1:11434/v1', 'label' => 'Ollama', 'icon' => '🦙'],
  'openai' => ['url' => 'https://api.openai.com/v1', 'label' => 'OpenAI', 'needs_key' => true, 'icon' => '🔑'],
  'openrouter' => ['url' => 'https://openrouter.ai/api/v1', 'label' => 'OpenRouter', 'needs_key' => true, 'icon' => '🌐', 'docs' => 'https://openrouter.ai/docs'],
  'anthropic' => ['url' => 'https://api.anthropic.com/v1', 'label' => 'Anthropic (Claude)', 'needs_key' => true, 'icon' => '🧠'],
  'custom' => ['url' => '', 'label' => 'Custom Provider', 'needs_url' => true, 'needs_key' => false, 'icon' => '⚙️'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AI Connections</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen">

<?php if(!$IS_EMBED): ?>
<div class="bg-gradient-to-r from-sky-500 to-indigo-600 text-white py-4 mb-6">
  <div class="container mx-auto px-4">
    <h1 class="text-xl font-semibold">🤖 AI Connections</h1>
    <p class="text-sm opacity-90">Manage AI provider connections</p>
  </div>
</div>
<?php endif; ?>

<div class="container mx-auto px-4 max-w-4xl">

  <?php if($success): ?>
    <div class="mb-4 bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 text-sm">
      ✓ <?php echo h($success); ?>
    </div>
  <?php endif; ?>

  <?php if($errors): ?>
    <div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 text-sm">
      <?php foreach($errors as $e): ?>
        <div>• <?php echo h($e); ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Active Connection Card -->
  <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5 mb-6">
    <div class="flex items-start justify-between">
      <div class="flex-1">
        <div class="text-xs text-gray-500 mb-1">Active Connection</div>
        <div class="text-lg font-semibold text-gray-900">
          <?php 
            $activeName = '';
            // Try to find match by hash first
            foreach($connections as $c) {
              if (($c['hash'] ?? '') === $activeHash) {
                $activeName = $c['name'] ?? '';
                break;
              }
            }
            // Fallback: try to match by provider, model, base_url without hash
            if ($activeName === '' && !empty($connections)) {
              foreach($connections as $c) {
                $cProvider = strtolower((string)($c['provider'] ?? ''));
                $cModel = (string)($c['model'] ?? '');
                $cBase = rtrim((string)($c['base_url'] ?? ''), '/');
                
                $aProvider = strtolower($active['provider'] ?? '');
                $aModel = (string)($active['model'] ?? '');
                $aBase = rtrim((string)($active['base_url'] ?? ''), '/');
                // Normalize both to exclude /v1
                $aBase = preg_replace('~/v1$~', '', $aBase);
                
                if ($cProvider === $aProvider && $cModel === $aModel && $cBase === $aBase) {
                  $activeName = $c['name'] ?? '';
                  break;
                }
              }
            }
            echo $activeName ? h($activeName) : '<span class="text-gray-400">None configured</span>';
          ?>
        </div>
        <div class="mt-1 text-sm text-gray-600">
          <?php echo h($active['provider'] ?? ''); ?> · <?php echo h($active['model'] ?? ''); ?>
        </div>
      </div>
      <a href="?add=1" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-md text-sm font-medium">
        + New Connection
      </a>
    </div>
  </div>

  <!-- Add/Edit Form -->
  <?php if (isset($_GET['add']) || $editing): ?>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-900">
          <?php echo $editing ? 'Edit Connection' : 'New Connection'; ?>
        </h2>
        <a href="?" class="text-sm text-gray-600 hover:text-gray-900">✕ Cancel</a>
      </div>

      <!-- Step 1: Test Connection -->
      <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
        <div class="flex items-center gap-2 mb-3">
          <span class="flex items-center justify-center w-6 h-6 bg-blue-600 text-white rounded-full text-xs font-bold">1</span>
          <div class="text-sm font-medium text-blue-900">Test Connection & Load Models</div>
        </div>
        <div class="text-xs text-blue-700 mb-3 pl-8">Fill in the connection details below, then click "Test" to verify it works and load available models.</div>
        
        <form method="post" id="testForm" class="space-y-3">
          <?php csrf_input(); ?>
          <input type="hidden" name="action" value="test_models">
          
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">
                Provider <span class="text-red-500">*</span>
                <span class="text-gray-500 font-normal">(auto-fills URL)</span>
              </label>
              <select 
                name="provider" 
                id="provider"
                class="w-full border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                onchange="updateProviderPreset()"
              >
                <?php foreach($presets as $key => $preset): ?>
                  <option value="<?php echo h($key); ?>" 
                    <?php echo ($formData['provider'] ?? $editing['provider'] ?? 'local') === $key ? 'selected' : ''; ?>>
                    <?php echo h($preset['label']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">
                Timeout <span class="text-gray-500 font-normal">(seconds)</span>
              </label>
              <input 
                type="number" 
                name="timeout" 
                id="timeout"
                value="<?php echo (int)($formData['timeout'] ?? $editing['timeout_seconds'] ?? 120); ?>"
                min="10"
                max="600"
                class="w-full border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
              >
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">
              Base URL <span class="text-red-500">*</span>
              <span class="text-gray-500 font-normal">(auto-fills when you select provider, can be edited)</span>
            </label>
            <input 
              type="url" 
              name="base_url" 
              id="base_url"
              value="<?php echo h($formData['base_url'] ?? $editing['base_url'] ?? ''); ?>"
              placeholder="http://127.0.0.1:1234/v1"
              class="w-full border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
              data-original="<?php echo h($formData['base_url'] ?? $editing['base_url'] ?? ''); ?>"
              required
            >
          </div>

          <div id="custom_url_section" style="<?php echo ($formData['provider'] ?? $editing['provider'] ?? 'local') === 'custom' ? '' : 'display:none'; ?>">
            <div class="p-3 bg-blue-50 border border-blue-200 rounded-md mb-3">
              <p class="text-xs text-blue-800">
                <strong>Custom Provider:</strong> Enter your provider's API URL above (e.g., https://your-provider.com/v1)
              </p>
            </div>
          </div>

          <div id="api_key_field" style="<?php echo ($formData['provider'] ?? $editing['provider'] ?? 'local') === 'openai' || ($formData['provider'] ?? $editing['provider'] ?? 'local') === 'openrouter' || ($formData['provider'] ?? $editing['provider'] ?? 'local') === 'anthropic' ? '' : 'display:none'; ?>">
            <label class="block text-xs font-medium text-gray-700 mb-1">
              <span id="api_key_label">API Key</span> <span class="text-red-500">*</span>
            </label>
            <input 
              type="password" 
              name="api_key" 
              id="api_key"
              value="<?php echo h($formData['api_key'] ?? $editing['api_key'] ?? ''); ?>"
              placeholder="sk-..."
              class="w-full border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
            >
            <p class="text-xs text-gray-600 mt-1">Your API key is stored securely and never shared.</p>
          </div>

          <button 
            type="submit" 
            class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm font-medium flex items-center justify-center gap-2"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
            </svg>
            Test Connection & Load Models
          </button>
        </form>
      </div>

      <!-- Step 2: Save Connection (only show after successful test) -->
      <?php if (is_array($modelList) && !empty($modelList['ok'])): ?>
        <div class="p-4 bg-green-50 border border-green-200 rounded-lg mb-4">
          <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div>
              <div class="text-sm font-medium text-green-900">Connection Successful!</div>
              <div class="text-xs text-green-700">Found <?php echo count($modelList['models'] ?? []); ?> available models</div>
            </div>
          </div>
        </div>

        <div class="p-4 bg-gray-50 border border-gray-200 rounded-lg">
          <div class="flex items-center gap-2 mb-3">
            <span class="flex items-center justify-center w-6 h-6 bg-gray-600 text-white rounded-full text-xs font-bold">2</span>
            <div class="text-sm font-medium text-gray-900">Save Connection</div>
          </div>
          <div class="text-xs text-gray-700 mb-3 pl-8">Choose a model and give this connection a name. The name will be auto-generated but you can change it.</div>

          <form method="post" class="space-y-4">
            <?php csrf_input(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="provider" value="<?php echo h($formData['provider'] ?? 'local'); ?>">
            <input type="hidden" name="base_url" value="<?php echo h($formData['base_url'] ?? ''); ?>">
            <input type="hidden" name="api_key" value="<?php echo h($formData['api_key'] ?? ''); ?>">
            <input type="hidden" name="timeout" value="<?php echo (int)($formData['timeout'] ?? 120); ?>">

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                Model <span class="text-red-500">*</span>
                <span class="text-gray-500 font-normal text-xs">(will auto-generate connection name)</span>
              </label>
              <?php if (!empty($modelList['models'])): ?>
                <!-- Dropdown: Models available from provider -->
                <select 
                  name="model" 
                  id="model"
                  class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                  onchange="updateConnectionName()"
                  required
                >
                  <option value="">-- Choose a model --</option>
                  <?php foreach($modelList['models'] as $m): ?>
                    <option value="<?php echo h($m); ?>"><?php echo h($m); ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <!-- Text Input: Manual entry when no models available -->
                <div class="mb-3 p-3 bg-amber-50 border border-amber-200 rounded-md">
                  <p class="text-xs text-amber-800">
                    <strong>No models found.</strong> Enter the model name manually (e.g., <code>gpt-4-turbo</code>, <code>claude-3-5-sonnet-20241022</code>)
                  </p>
                </div>
                <input 
                  type="text" 
                  name="model" 
                  id="model"
                  placeholder="e.g., openai/gpt-4-turbo or claude-3-5-sonnet-20241022"
                  class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                  onchange="updateConnectionName()"
                  required
                >
              <?php endif; ?>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                Connection Name <span class="text-red-500">*</span>
                <span class="text-gray-500 font-normal text-xs">(you can edit this)</span>
              </label>
              <input 
                type="text" 
                name="name" 
                id="name"
                value=""
                placeholder="Select a model first - name will auto-fill"
                class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                required
              >
            </div>

            <div class="flex items-center justify-between pt-4 border-t">
              <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="activate" value="1" checked class="rounded">
                <span>Activate this connection immediately</span>
              </label>
              
              <div class="flex gap-2">
                <a href="?" class="px-4 py-2 text-sm text-gray-700 hover:text-gray-900">Cancel</a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-md text-sm font-medium flex items-center gap-2">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                  </svg>
                  Save Connection
                </button>
              </div>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <script>
      const presets = <?php echo json_encode($presets); ?>;
      const isEditing = <?php echo $editing ? 'true' : 'false'; ?>;
      let userEditedUrl = false;
      
      function updateProviderPreset() {
        const provider = document.getElementById('provider').value;
        const preset = presets[provider];
        const baseUrlField = document.getElementById('base_url');
        
        if (preset) {
          // Only auto-fill URL if:
          // 1. NOT editing an existing connection
          // 2. User hasn't manually edited the URL field
          // 3. Not a custom provider
          const shouldAutoFill = !isEditing && !userEditedUrl && provider !== 'custom';
          
          if (shouldAutoFill && preset.url) {
            baseUrlField.value = preset.url;
          }
          
          // Show/hide custom URL input based on provider
          const customUrlSection = document.getElementById('custom_url_section');
          if (customUrlSection) {
            customUrlSection.style.display = provider === 'custom' ? '' : 'none';
          }
          
          // Show/hide API key field based on provider's needs_key flag
          const apiKeyField = document.getElementById('api_key_field');
          if (apiKeyField) {
            apiKeyField.style.display = preset.needs_key ? '' : 'none';
          }
          
          // Update API key placeholder and label
          const apiKeyInput = document.getElementById('api_key');
          if (apiKeyInput && preset.needs_key) {
            const placeholders = {
              'openai': 'sk-...',
              'openrouter': 'sk-or-...',
              'anthropic': 'sk-ant-...',
              'custom': 'your-api-key'
            };
            apiKeyInput.placeholder = placeholders[provider] || 'your-api-key';
          }
          
          // Update API key label
          const apiKeyLabel = document.getElementById('api_key_label');
          if (apiKeyLabel) {
            const labels = {
              'openai': 'API Key (required for OpenAI)',
              'openrouter': 'API Key (required for OpenRouter)',
              'anthropic': 'API Key (required for Anthropic)',
              'custom': 'API Key (if required)'
            };
            apiKeyLabel.textContent = labels[provider] || 'API Key';
          }
        }
      }

      function updateConnectionName() {
        const provider = document.getElementById('provider').value;
        const model = document.getElementById('model').value;
        const nameField = document.getElementById('name');
        
        if (model && nameField.value === '') {
          // Auto-generate name: Provider - Model
          const providerLabels = {
            'local': 'Local',
            'ollama': 'Ollama',
            'openai': 'OpenAI',
            'openrouter': 'OpenRouter',
            'anthropic': 'Anthropic',
            'custom': 'Custom'
          };
          const providerLabel = providerLabels[provider] || provider;
          nameField.value = providerLabel + ' - ' + model;
        }
      }

      // Track when user manually edits the URL field
      const baseUrlField = document.getElementById('base_url');
      if (baseUrlField) {
        baseUrlField.addEventListener('input', function() {
          userEditedUrl = true;
        });
      }

      // Auto-fill name when model changes (works for both select and text input)
      const modelElement = document.getElementById('model');
      if (modelElement) {
        modelElement.addEventListener('change', updateConnectionName);
        modelElement.addEventListener('input', updateConnectionName);
      }

      // Initialize form on page load
      document.addEventListener('DOMContentLoaded', function() {
        // Only auto-fill provider preset if NOT editing
        if (!isEditing) {
          updateProviderPreset();
        } else {
          // Still update UI elements (API key visibility, etc) without changing URL
          const provider = document.getElementById('provider').value;
          const preset = presets[provider];
          if (preset) {
            const customUrlSection = document.getElementById('custom_url_section');
            if (customUrlSection) {
              customUrlSection.style.display = provider === 'custom' ? '' : 'none';
            }
            const apiKeyField = document.getElementById('api_key_field');
            if (apiKeyField) {
              apiKeyField.style.display = preset.needs_key ? '' : 'none';
            }
          }
        }
      });
    </script>
  <?php endif; ?>

  <!-- Saved Connections -->
  <div class="bg-white rounded-lg shadow-sm border border-gray-200">
    <div class="px-5 py-4 border-b border-gray-200">
      <h2 class="text-sm font-semibold text-gray-900">Saved Connections</h2>
    </div>
    
    <?php if(empty($connections)): ?>
      <div class="px-5 py-8 text-center text-gray-500">
        <div class="text-sm">No connections saved yet.</div>
        <a href="?add=1" class="inline-block mt-3 text-indigo-600 hover:text-indigo-700 text-sm font-medium">
          + Create your first connection
        </a>
      </div>
    <?php else: ?>
      <div class="divide-y divide-gray-100">
        <?php foreach($connections as $conn): ?>
          <?php $isActive = ($conn['hash'] ?? '') === $activeHash; ?>
          <?php $profileHash = (string)($conn['hash'] ?? ''); ?>
          <?php $profileId = ($profileHash !== '') ? substr($profileHash, 0, 12) : ''; ?>
          <div class="px-5 py-4 hover:bg-gray-50 transition-colors <?php echo $isActive ? 'bg-green-50' : ''; ?>">
            <div class="flex items-center justify-between gap-4">
              <div class="flex items-start gap-3 flex-1 min-w-0">
                <div class="mt-1">
                  <?php if($isActive): ?>
                    <div class="w-2 h-2 bg-green-500 rounded-full" title="Active connection"></div>
                  <?php else: ?>
                    <div class="w-2 h-2 bg-gray-300 rounded-full"></div>
                  <?php endif; ?>
                </div>
                
                <div class="flex-1 min-w-0">
                  <div class="font-medium text-gray-900"><?php echo h($conn['name'] ?? ''); ?></div>
                  <div class="text-[11px] text-gray-500 mt-0.5">
                    ID:
                    <?php if ($profileId !== ''): ?>
                      <span class="font-mono text-[11px]" title="<?php echo h($profileHash); ?>"><?php echo h($profileId); ?></span>
                      <button type="button" class="ml-2 text-[11px] text-gray-600 hover:text-gray-900" onclick="try{navigator.clipboard.writeText(<?php echo json_encode($profileHash); ?>);}catch(e){}">Copy</button>
                    <?php else: ?>
                      <span class="text-gray-400">—</span>
                    <?php endif; ?>
                  </div>
                  <div class="text-sm text-gray-600 mt-0.5">
                    <?php echo h($conn['provider'] ?? ''); ?> · <?php echo h($conn['model'] ?? ''); ?> · <?php echo h($conn['timeout_seconds'] ?? 120); ?>s timeout
                  </div>
                  <div class="text-xs text-gray-500 mt-1 truncate" title="<?php echo h($conn['base_url'] ?? ''); ?>">
                    <?php echo h($conn['base_url'] ?? ''); ?>
                  </div>
                </div>
              </div>

              <div class="flex items-center gap-2 shrink-0">
                <?php if(!$isActive): ?>
                  <form method="post" class="inline">
                    <?php csrf_input(); ?>
                    <input type="hidden" name="action" value="activate">
                    <input type="hidden" name="hash" value="<?php echo h($conn['hash'] ?? ''); ?>">
                    <button 
                      type="submit" 
                      class="px-3 py-1.5 bg-gray-900 hover:bg-gray-800 text-white rounded text-xs font-medium"
                      title="Switch to this connection"
                    >
                      Activate
                    </button>
                  </form>
                  
                  <button 
                    type="button"
                    onclick="confirmDelete('<?php echo h(addslashes($conn['name'] ?? '')); ?>', '<?php echo h($conn['hash'] ?? ''); ?>')"
                    class="px-3 py-1.5 border border-red-300 hover:bg-red-50 text-red-700 rounded text-xs font-medium"
                    title="Delete this connection"
                  >
                    Delete
                  </button>
                <?php else: ?>
                  <span class="px-3 py-1.5 bg-green-100 text-green-800 rounded text-xs font-medium">Active</span>
                  <span class="px-3 py-1.5 text-gray-400 text-xs">Cannot delete</span>
                <?php endif; ?>
                
                <a 
                  href="?edit=<?php echo h($conn['hash'] ?? ''); ?>" 
                  class="px-3 py-1.5 border border-gray-300 hover:bg-gray-50 text-gray-700 rounded text-xs font-medium"
                  title="Edit this connection"
                >
                  Edit
                </a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50" style="display: none;">
  <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
    <div class="p-6">
      <div class="flex items-center gap-3 mb-4">
        <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
          <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
          </svg>
        </div>
        <div>
          <h3 class="text-lg font-semibold text-gray-900">Delete Connection?</h3>
          <p class="text-sm text-gray-600 mt-1">This action cannot be undone.</p>
        </div>
      </div>
      
      <div class="bg-gray-50 rounded p-3 mb-4">
        <div class="text-sm text-gray-700">
          <strong id="deleteConnectionName"></strong>
        </div>
      </div>

      <div class="text-sm text-gray-600 mb-6">
        Are you sure you want to delete this connection? You'll need to recreate it if you want to use it again.
      </div>

      <form method="post" id="deleteForm">
        <?php csrf_input(); ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="hash" id="deleteHash" value="">
        
        <div class="flex gap-3 justify-end">
          <button 
            type="button" 
            onclick="closeDeleteModal()"
            class="px-4 py-2 border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-md text-sm font-medium"
          >
            Cancel
          </button>
          <button 
            type="submit" 
            class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-md text-sm font-medium"
          >
            Yes, Delete Connection
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function confirmDelete(name, hash) {
  document.getElementById('deleteConnectionName').textContent = name;
  document.getElementById('deleteHash').value = hash;
  const modal = document.getElementById('deleteModal');
  modal.style.display = 'flex';
  modal.classList.remove('hidden');
}

function closeDeleteModal() {
  const modal = document.getElementById('deleteModal');
  modal.style.display = 'none';
  modal.classList.add('hidden');
}

// Close modal on background click
document.getElementById('deleteModal').addEventListener('click', function(e) {
  if (e.target === this) {
    closeDeleteModal();
  }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeDeleteModal();
  }
});
</script>

</body>
</html>