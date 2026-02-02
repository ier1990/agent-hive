<?php
// CodeWalker backup/restore (settings + prompt templates)

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

$backupPath = '/web/private/codewalker.json';

if (isset($_GET['download']) && (string)$_GET['download'] === '1') {
    // On-the-fly download (does not require writing to disk)
    $payload = cw_backup_export_array();
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        http_response_code(500);
        echo 'Failed to encode backup JSON';
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="codewalker-backup-' . gmdate('Ymd-His') . '.json"');
    echo $json;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

    if ($action === 'write') {
        try {
            cw_backup_write_json($backupPath);
            $flash = 'Wrote backup to ' . $backupPath;
        } catch (Throwable $e) {
            $errors[] = 'Backup write failed: ' . $e->getMessage();
        }
    } elseif ($action === 'restore') {
        if (!isset($_FILES['backup']) || !is_array($_FILES['backup'])) {
            $errors[] = 'Missing upload.';
        } else {
            $tmp = $_FILES['backup']['tmp_name'] ?? '';
            if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
                $errors[] = 'Upload failed.';
            } else {
                $raw = @file_get_contents($tmp);
                if (!is_string($raw) || $raw === '') {
                    $errors[] = 'Could not read uploaded file.';
                } else {
                    $decoded = json_decode($raw, true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                        $errors[] = 'Invalid JSON.';
                    } else {
                        try {
                            cw_backup_import_array($decoded);
                            $flash = 'Restored settings + prompt templates from upload.';
                        } catch (Throwable $e) {
                            $errors[] = 'Restore failed: ' . $e->getMessage();
                        }
                    }
                }
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
input,textarea,select{width:100%;padding:.5rem;border-radius:8px;border:1px solid #344;color:#eef;background:#0e1330}
.btn{display:inline-block;padding:.5rem .8rem;border-radius:8px;border:1px solid #456;background:#101a44;color:#fff;text-decoration:none;cursor:pointer}
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

cw_page_header('CodeWalker Backup');

echo '<div class="card"><div style="display:flex;justify-content:space-between;align-items:center;gap:1rem">';

echo '<div><h2 style="margin:0">CodeWalker Backup</h2><div class="small">Exports settings + prompt templates</div></div>';

echo '<div style="display:flex;gap:.5rem;align-items:center">';

echo '<a class="btn" href="codew_config.php">Back</a>';

echo '<a class="btn" href="?download=1">Download JSON</a>';

echo '</div>';

echo '</div></div>';

if ($flash) echo '<div class="flash">' . h($flash) . '</div>';
if ($errors) echo '<div class="err"><strong>Errors</strong><ul><li>' . implode('</li><li>', array_map('h', $errors)) . '</li></ul></div>';

echo '<form method="post" class="card" style="margin-top:1rem">';
echo '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
echo '<input type="hidden" name="action" value="write">';
echo '<div><strong>Write backup file</strong><div class="small">Writes a snapshot to <code>' . h($backupPath) . '</code></div></div>';
echo '<div style="margin-top:.75rem"><button class="btn" type="submit">Write /web/private/codewalker.json</button></div>';
echo '</form>';

echo '<form method="post" enctype="multipart/form-data" class="card" style="margin-top:1rem">';
echo '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
echo '<input type="hidden" name="action" value="restore">';
echo '<div><strong>Restore from JSON</strong><div class="small">Overwrites settings and upserts prompt templates. Defaults <code>rewrite</code>/<code>summarize</code> are always kept.</div></div>';
echo '<div style="margin-top:.75rem"><input type="file" name="backup" accept="application/json"></div>';
echo '<div style="margin-top:.75rem"><button class="btn" type="submit" onclick="return confirm(\'Restore settings + prompts from this JSON?\')">Restore</button></div>';
echo '</form>';

cw_page_footer();
