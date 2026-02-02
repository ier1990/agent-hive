<?php
// CodeWalker settings editor (SQLite settings DB)

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

require_once __DIR__ . '/lib/codewalker_helpers.php';
require_once __DIR__ . '/lib/codewalker_settings.php';

$ADMIN_PASS = getenv('CODEWALKER_ADMIN_PASS') ?: '';
login_required($ADMIN_PASS);

$errors = [];
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = isset($_POST['action']) ? (string)$_POST['action'] : 'save_defaults';

    if ($action === 'custom_add' || $action === 'custom_update') {
        $k = isset($_POST['custom_key']) ? trim((string)$_POST['custom_key']) : '';
        $raw = isset($_POST['custom_value']) ? (string)$_POST['custom_value'] : '';

        if ($k === '') {
            $errors[] = 'Custom setting key is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $k)) {
            $errors[] = 'Custom setting key must be 1-64 chars of letters/numbers/._-';
        } elseif (array_key_exists($k, cw_config_template())) {
            $errors[] = 'That key is a core setting; edit it in the main table.';
        }

        $decoded = null;
        if (!$errors) {
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = 'Custom setting value must be valid JSON (e.g. 900, true, "text", ["a"], {"k":1}).';
            }
        }

        if (!$errors) {
            try {
                cw_settings_set($k, $decoded);
                $flash = 'Saved custom setting.';
            } catch (Throwable $e) {
                $errors[] = 'Save failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'custom_delete') {
        $k = isset($_POST['custom_key']) ? trim((string)$_POST['custom_key']) : '';
        if ($k === '') {
            $errors[] = 'Missing custom setting key.';
        } elseif (array_key_exists($k, cw_config_template())) {
            $errors[] = 'Refusing to delete core setting.';
        } else {
            try {
                $ok = cw_settings_delete_key($k);
                if ($ok) {
                    $flash = 'Deleted custom setting.';
                } else {
                    $errors[] = 'Delete failed (key not found).';
                }
            } catch (Throwable $e) {
                $errors[] = 'Delete failed: ' . $e->getMessage();
            }
        }
    } else {

        // Only expose a curated set of keys here.
        $defaults = cw_config_template();

        $next = [];
        foreach ($defaults as $key => $defaultVal) {
            if (!array_key_exists($key, $_POST)) continue;
            $raw = (string)$_POST[$key];

            // Type-aware parsing
            if (is_bool($defaultVal)) {
                $next[$key] = ($raw === '1' || strtolower($raw) === 'true' || $raw === 'on');
            } elseif (is_int($defaultVal)) {
                $next[$key] = (int)$raw;
            } elseif (is_float($defaultVal)) {
                $next[$key] = (float)$raw;
            } elseif (is_array($defaultVal)) {
                $decoded = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    $errors[] = "Invalid JSON for {$key}.";
                } else {
                    $next[$key] = $decoded;
                }
            } else {
                // string/null
                if ($raw === '' && $defaultVal === null) {
                    $next[$key] = null;
                } else {
                    $next[$key] = $raw;
                }
            }
        }

        // write_root is used for safety by the admin UI
        if (isset($next['write_root']) && is_string($next['write_root'])) {
            $wr = rtrim($next['write_root'], '/');
            if ($wr === '' || $wr[0] !== '/') {
                $errors[] = 'write_root must be an absolute path.';
            }
        }

        if (!$errors) {
            cw_settings_update_many($next);
            $flash = 'Saved settings.';
        }
    }
}

$cfg = cw_settings_get_all();
$csrf = ensure_csrf();

$models = [];
$forceModelsRefresh = isset($_GET['refresh_models']) && (string)$_GET['refresh_models'] !== '0';
$modelCacheKey = 'cw_models_cache_' . sha1(strtolower((string)($cfg['backend'] ?? '')) . '|' . (string)($cfg['base_url'] ?? ''));
$modelCache = !$forceModelsRefresh ? ($_SESSION[$modelCacheKey] ?? null) : null;
if (!$forceModelsRefresh && is_array($modelCache) && isset($modelCache['ts'], $modelCache['models']) && is_int($modelCache['ts']) && is_array($modelCache['models'])) {
    if (time() - $modelCache['ts'] < 60) {
        $models = $modelCache['models'];
    }
}
if (!$models) {
    $models = cw_discover_models($cfg, 2);
    $_SESSION[$modelCacheKey] = ['ts' => time(), 'models' => $models];
}

$promptTemplates = cw_prompt_templates_list();
$promptTemplateNames = [];
foreach ($promptTemplates as $t) {
    if (is_array($t) && isset($t['name']) && is_string($t['name']) && $t['name'] !== '') {
        $promptTemplateNames[] = $t['name'];
    }
}

function field_text(string $key, $val): string {
    return '<input type="text" name="'.h($key).'" value="'.h((string)$val).'">';
}

function field_bool(string $key, $val): string {
    $checked = $val ? ' checked' : '';
    return '<input type="checkbox" name="'.h($key).'" value="1"'.$checked.'>';
}

function field_json(string $key, $val): string {
    $pretty = json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return '<textarea name="'.h($key).'" rows="6" spellcheck="false">'.h((string)$pretty).'</textarea>';
}

function field_model_select(string $key, $val, array $models): string {
    $current = is_string($val) ? $val : (string)$val;

    // Ensure current value is always selectable.
    $options = [];
    if ($current !== '') $options[$current] = true;
    foreach ($models as $m) {
        if (!is_string($m)) continue;
        $m = trim($m);
        if ($m === '') continue;
        $options[$m] = true;
    }

    $opts = array_keys($options);
    sort($opts, SORT_STRING);

    $html = '<div style="display:flex;gap:.5rem;align-items:center">';
    $html .= '<select name="'.h($key).'">';
    foreach ($opts as $m) {
        $sel = ($m === $current) ? ' selected' : '';
        $html .= '<option value="'.h($m).'"'.$sel.'>'.h($m).'</option>';
    }
    $html .= '</select>';
    $html .= '<a class="btn" style="padding:.35rem .55rem" href="?refresh_models=1">Refresh</a>';
    $html .= '</div>';
    return $html;
}

function field_template_select(string $key, $val, array $names): string {
    $current = is_string($val) ? $val : (string)$val;
    $options = [];
    if ($current !== '') $options[$current] = true;
    foreach ($names as $n) {
        if (!is_string($n)) continue;
        $n = trim($n);
        if ($n === '') continue;
        $options[$n] = true;
    }
    $opts = array_keys($options);
    sort($opts, SORT_STRING);

    $html = '<select name="'.h($key).'">';
    foreach ($opts as $n) {
        $sel = ($n === $current) ? ' selected' : '';
        $html .= '<option value="'.h($n).'"'.$sel.'>'.h($n).'</option>';
    }
    $html .= '</select>';
    return $html;
}

echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<title>AI Config</title>';
echo '<style>
:root{--bg:#0b1020;--card:#141a33;--ink:#eef;--mut:#9fb0d0;--pri:#37f;--bad:#ff6b6b}
*{box-sizing:border-box} body{margin:0;font-family:system-ui,Segoe UI,Arial;background:var(--bg);color:var(--ink)}
.container{max-width:1100px;margin:1rem auto;padding:0 1rem}
.card{background:var(--card);border:1px solid #263056;border-radius:12px;padding:1rem}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:.5rem;border-bottom:1px solid #263056;vertical-align:top}
input,textarea,select{width:100%;padding:.5rem;border-radius:8px;border:1px solid #344;color:#eef;background:#0e1330}
.btn{display:inline-block;padding:.5rem .8rem;border-radius:8px;border:1px solid #456;background:#101a44;color:#fff;text-decoration:none;cursor:pointer}
.flash{margin:1rem 0;padding:.6rem;border-radius:8px;background:#112045;border:1px solid #2e67ff}
.err{margin:1rem 0;padding:.6rem;border-radius:8px;background:#3a1010;border:1px solid var(--bad)}
.small{color:var(--mut);font-size:.9rem}
</style>';
echo '</head><body><div class="container">';
echo '<div class="card"><div style="display:flex;justify-content:space-between;align-items:center;gap:1rem">';
echo '<div><h2 style="margin:0">AI Config</h2><div class="small">Stored in '.h(cw_settings_db_path()).'</div></div>';
echo '<div style="display:flex;gap:.5rem;align-items:center">';
echo '<a class="btn" href="codewalker.php">Back</a>';
echo '<a class="btn" href="codew_prompts.php">Prompts</a>';
echo '<a class="btn" href="codew_backup.php">Backup</a>';
echo '</div>';
echo '</div></div>';

if ($flash) echo '<div class="flash">'.h($flash).'</div>';
if ($errors) echo '<div class="err"><strong>Errors</strong><ul><li>'.implode('</li><li>', array_map('h', $errors)).'</li></ul></div>';

echo '<form method="post" class="card" style="margin-top:1rem">';
echo '<input type="hidden" name="csrf" value="'.h($csrf).'">';
echo '<input type="hidden" name="action" value="save_defaults">';
echo '<table class="table">';
echo '<tr><th style="width:220px">Key</th><th>Value</th><th style="width:320px">Notes</th></tr>';

$rows = [
    ['db_path', 'Path to codewalker.db (actions/summaries/rewrites)'],
    ['write_root', 'Safety root for applying rewrites + queue paths'],
    ['scan_path', 'Where the cron scanner walks (python)'],
    ['mode', 'cron|que'],
    ['backend', 'auto|lmstudio|ollama|openai_compat'],
    ['base_url', 'LLM base URL (Ollama: http://host:11434)'],
    ['model', 'Default model name'],
    ['model_timeout_seconds', 'LLM request timeout in seconds (e.g. 900 for 15 minutes)'],
    ['percent_rewrite', '0-100 chance for rewrite'],
    ['limit_per_run', 'Max files per run'],
    ['max_filesize_kb', 'Max KB read for code'],
    ['log_tail_lines', 'Tail lines for .log'],
    ['respect_gitignore', 'Basic .gitignore respect'],
    ['file_types', 'JSON array'],
    ['actions', 'JSON array'],
    ['exclude_dirs', 'JSON array'],

    ['prompt_rewrite_template', 'Prompt template name for rewrites (see Prompts)'],
    ['prompt_summarize_template', 'Prompt template name for summaries (see Prompts)'],
];

foreach ($rows as $row) {
    $k = $row[0];
    $note = $row[1];
    $val = $cfg[$k] ?? '';

    echo '<tr><td><code>'.h($k).'</code></td><td>';

    if ($k === 'model') {
        if ($models) {
            echo field_model_select($k, $val, $models);
        } else {
            echo field_text($k, $val);
        }
    } elseif ($k === 'prompt_rewrite_template' || $k === 'prompt_summarize_template') {
        echo field_template_select($k, $val, $promptTemplateNames);
    } elseif (is_array($val)) {
        echo field_json($k, $val);
    } elseif (is_bool($val)) {
        echo field_bool($k, $val);
    } else {
        echo field_text($k, $val);
    }

    echo '</td><td class="small">'.h($note).'</td></tr>';
}

echo '</table>';
echo '<div style="margin-top:1rem"><button class="btn" type="submit">Save</button></div>';
echo '</form>';

// Custom settings (keys not in cw_config_template)
$defaultsNow = cw_config_template();
$allRaw = cw_settings_get_raw();
$custom = [];
foreach ($allRaw as $k => $v) {
    if (!is_string($k) || $k === '') continue;
    if (array_key_exists($k, $defaultsNow)) continue;
    $custom[$k] = $v;
}
ksort($custom, SORT_STRING);

echo '<div class="card" style="margin-top:1rem">';
echo '<h3 style="margin:.2rem 0 0 0">Custom settings</h3>';
echo '<div class="small" style="margin-top:.4rem">Add future keys here (stored as JSON values). Example: key <code>timeout_seconds</code> value <code>900</code> for 15 minutes.</div>';

echo '<form method="post" style="margin-top:.75rem">';
echo '<input type="hidden" name="csrf" value="'.h($csrf).'">';
echo '<input type="hidden" name="action" value="custom_add">';
echo '<div style="display:grid;grid-template-columns:260px 1fr 140px;gap:.5rem;align-items:start">';
echo '<input type="text" name="custom_key" placeholder="e.g. timeout_seconds">';
echo '<input type="text" name="custom_value" placeholder="JSON value (e.g. 900 or \"text\" or true)">';
echo '<button class="btn" type="submit">Add</button>';
echo '</div>';
echo '</form>';

if ($custom) {
    echo '<table class="table" style="margin-top:1rem">';
    echo '<tr><th style="width:220px">Key</th><th>Value (JSON)</th><th style="width:220px">Actions</th></tr>';
    foreach ($custom as $k => $v) {
        $json = json_encode($v, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) $json = 'null';
        echo '<tr>';
        echo '<td><code>'.h($k).'</code></td>';
        echo '<td>';
        echo '<form method="post" style="margin:0">';
        echo '<input type="hidden" name="csrf" value="'.h($csrf).'">';
        echo '<input type="hidden" name="action" value="custom_update">';
        echo '<input type="hidden" name="custom_key" value="'.h($k).'">';
        echo '<input type="text" name="custom_value" value="'.h($json).'">';
        echo '</td>';
        echo '<td>';
        echo '<button class="btn" type="submit" style="padding:.35rem .55rem">Save</button> ';
        echo '</form>';

        echo '<form method="post" style="display:inline">';
        echo '<input type="hidden" name="csrf" value="'.h($csrf).'">';
        echo '<input type="hidden" name="action" value="custom_delete">';
        echo '<input type="hidden" name="custom_key" value="'.h($k).'">';
        echo '<button class="btn" type="submit" style="padding:.35rem .55rem" onclick="return confirm(\'Delete this custom setting?\')">Delete</button>';
        echo '</form>';

        echo '</td>';
        echo '</tr>';
    }
    echo '</table>';
}
echo '</div>';

echo '</div></body></html>';
