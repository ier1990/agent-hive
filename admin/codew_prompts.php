<?php
// CodeWalker prompt templates editor (SQLite settings DB)

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

$action = isset($_GET['action']) ? (string)$_GET['action'] : 'list';
$name = isset($_GET['name']) ? (string)$_GET['name'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $postAction = isset($_POST['action']) ? (string)$_POST['action'] : '';

    if ($postAction === 'save') {
        $nm = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
        $desc = isset($_POST['description']) ? (string)$_POST['description'] : '';
        $content = isset($_POST['content']) ? (string)$_POST['content'] : '';

        if ($nm === '') $errors[] = 'Name is required.';
        if ($content === '') $errors[] = 'Content is required.';
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $nm)) {
            $errors[] = 'Name must be 1-64 chars of letters/numbers/._-';
        }

        if (!$errors) {
            try {
                cw_prompt_template_upsert($nm, $desc, $content);
                $flash = 'Saved template.';
                $action = 'edit';
                $name = $nm;
            } catch (Throwable $e) {
                $errors[] = 'Save failed: ' . $e->getMessage();
            }
        }
    } elseif ($postAction === 'delete') {
        $nm = isset($_POST['name']) ? (string)$_POST['name'] : '';
        if ($nm === '') {
            $errors[] = 'Missing template name.';
        } else {
            $ok = cw_prompt_template_delete($nm);
            if ($ok) {
                $flash = 'Deleted template.';
                $action = 'list';
                $name = '';
            } else {
                $errors[] = 'Cannot delete that template (it may be a protected default).';
            }
        }
    }
}

$csrf = ensure_csrf();

function cw_page_header(string $title): void {
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<style>
:root{--bg:#0b1020;--card:#141a33;--ink:#eef;--mut:#9fb0d0;--pri:#37f;--bad:#ff6b6b}
*{box-sizing:border-box} body{margin:0;font-family:system-ui,Segoe UI,Arial;background:var(--bg);color:var(--ink)}
.container{max-width:1100px;margin:1rem auto;padding:0 1rem}
.card{background:var(--card);border:1px solid #263056;border-radius:12px;padding:1rem}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:.5rem;border-bottom:1px solid #263056;vertical-align:top}
input,textarea,select{width:100%;padding:.5rem;border-radius:8px;border:1px solid #344;color:#eef;background:#0e1330}
.btn{display:inline-block;padding:.5rem .8rem;border-radius:8px;border:1px solid #456;background:#101a44;color:#fff;text-decoration:none;cursor:pointer}
.btn2{display:inline-block;padding:.45rem .7rem;border-radius:8px;border:1px solid #456;background:#0e1330;color:#fff;text-decoration:none;cursor:pointer}
.flash{margin:1rem 0;padding:.6rem;border-radius:8px;background:#112045;border:1px solid #2e67ff}
.err{margin:1rem 0;padding:.6rem;border-radius:8px;background:#3a1010;border:1px solid var(--bad)}
.small{color:var(--mut);font-size:.9rem}
code{background:#0e1330;padding:.15rem .3rem;border-radius:6px}
</style>';
    echo '</head><body><div class="container">';
}

function cw_page_footer(): void {
    echo '</div></body></html>';
}

cw_page_header('CodeWalker Prompts');

echo '<div class="card"><div style="display:flex;justify-content:space-between;align-items:center;gap:1rem">';

echo '<div><h2 style="margin:0">CodeWalker Prompt Templates</h2><div class="small">Stored in ' . h(cw_settings_db_path()) . '</div></div>';

echo '<div style="display:flex;gap:.5rem;align-items:center">';

echo '<a class="btn" href="codewalker.php">Back</a>';

echo '<a class="btn" href="?action=new">New Template</a>';

echo '</div>';

echo '</div></div>';

if ($flash) echo '<div class="flash">' . h($flash) . '</div>';
if ($errors) echo '<div class="err"><strong>Errors</strong><ul><li>' . implode('</li><li>', array_map('h', $errors)) . '</li></ul></div>';

if ($action === 'new' || $action === 'edit') {
    $tpl = $action === 'edit' ? cw_prompt_template_get($name) : null;
    if ($action === 'edit' && !$tpl) {
        echo '<div class="err">Template not found.</div>';
    } else {
        $nm = $tpl ? (string)$tpl['name'] : '';
        $desc = $tpl ? (string)($tpl['description'] ?? '') : '';
        $content = $tpl ? (string)($tpl['content'] ?? '') : '';
        $isDefault = $tpl ? ((int)($tpl['is_default'] ?? 0) === 1) : false;

        echo '<form method="post" class="card" style="margin-top:1rem">';
        echo '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
        echo '<input type="hidden" name="action" value="save">';

        echo '<div style="display:grid;grid-template-columns:1fr;gap:.75rem">';

        echo '<div><div class="small">Name</div>';
        if ($isDefault) {
            echo '<input type="text" name="name" value="' . h($nm) . '" readonly>';
            echo '<div class="small">Default templates are protected from deletion and renaming.</div>';
        } else {
            echo '<input type="text" name="name" value="' . h($nm) . '" placeholder="e.g. pseudocode">';
            echo '<div class="small">Allowed: letters/numbers/._- (max 64)</div>';
        }
        echo '</div>';

        echo '<div><div class="small">Description</div>';
        echo '<input type="text" name="description" value="' . h($desc) . '" placeholder="What is this template for?">';
        echo '</div>';

        echo '<div><div class="small">Content (system prompt)</div>';
        echo '<textarea name="content" rows="10" spellcheck="false">' . h($content) . '</textarea>';
        echo '</div>';

        echo '<div style="display:flex;gap:.5rem;align-items:center">';
        echo '<button class="btn" type="submit">Save</button>';
        echo '<a class="btn2" href="?action=list">Cancel</a>';
        echo '</div>';

        echo '</div>';
        echo '</form>';

        if ($tpl && !$isDefault) {
            echo '<form method="post" class="card" style="margin-top:1rem">';
            echo '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
            echo '<input type="hidden" name="action" value="delete">';
            echo '<input type="hidden" name="name" value="' . h($nm) . '">';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:1rem">';
            echo '<div><strong>Delete</strong><div class="small">This cannot be undone.</div></div>';
            echo '<button class="btn" type="submit" onclick="return confirm(\'Delete this template?\')">Delete</button>';
            echo '</div>';
            echo '</form>';
        }
    }

} else {
    $list = cw_prompt_templates_list();
    echo '<div class="card" style="margin-top:1rem">';
    echo '<table class="table">';
    echo '<tr><th style="width:220px">Name</th><th>Description</th><th style="width:140px">Default</th><th style="width:180px">Updated</th><th style="width:140px">Actions</th></tr>';

    foreach ($list as $r) {
        $nm = (string)($r['name'] ?? '');
        $desc = (string)($r['description'] ?? '');
        $isDefault = ((int)($r['is_default'] ?? 0) === 1);
        $updated = (string)($r['updated_at'] ?? '');

        echo '<tr>';
        echo '<td><code>' . h($nm) . '</code></td>';
        echo '<td class="small">' . h($desc) . '</td>';
        echo '<td class="small">' . ($isDefault ? 'yes' : 'no') . '</td>';
        echo '<td class="small">' . h($updated) . '</td>';
        echo '<td>';
        echo '<a class="btn2" href="?action=edit&amp;name=' . rawurlencode($nm) . '">Edit</a>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</table>';
    echo '<div class="small" style="margin-top:.75rem">Defaults <code>rewrite</code> and <code>summarize</code> are always kept.</div>';
    echo '</div>';
}

cw_page_footer();
