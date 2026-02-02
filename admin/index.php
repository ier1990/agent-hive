<?php
// Admin Console - Wrapper/Navigation
// Auto-discovers and lists all admin_*.php tools

require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

// Frames-like wrapper: render a left menu + right iframe
$IS_EMBED = in_array(strtolower($_GET['embed'] ?? ''), ['1','true','yes'], true);

if (!$IS_EMBED) {
    // Discover admin_* files in current directory
    $menu = [];
    $localMatches = glob(__DIR__ . DIRECTORY_SEPARATOR . 'admin_*.{php,html}', GLOB_BRACE);
    if ($localMatches === false) $localMatches = [];
    foreach ($localMatches as $path) {
        $bn = basename($path);
        $label = preg_replace('/^admin_/i','', $bn);
        $label = preg_replace('/\.(php|html)$/i','', $label);
        $label = ucwords(str_replace('_',' ', $label));
        $menu[] = ['file' => $bn, 'label' => $label];
    }
    usort($menu, function ($a, $b) {
        return strcmp($a['label'], $b['label']);
    });

    // Discover imported admin modules under PRIVATE_ROOT/admin_modules
    $imported = [];
    $modulesDir = rtrim((string)PRIVATE_ROOT, '/') . '/admin_modules';
    $glob = $modulesDir . DIRECTORY_SEPARATOR . 'admin_*.php';
    $importMatches = glob($glob);
    if ($importMatches === false) $importMatches = [];
    foreach ($importMatches as $path) {
        $bn = basename($path);
        $label = preg_replace('/^admin_/i','', $bn);
        $label = preg_replace('/\.(php|html)$/i','', $label);
        $label = ucwords(str_replace('_',' ', $label));
        $imported[] = [
            'file' => 'module_import.php?m=' . rawurlencode($bn),
            'label' => $label,
        ];
    }
    usort($imported, function ($a, $b) {
        return strcmp($a['label'], $b['label']);
    });

    // Render wrapper with left nav and right iframe
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Console</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <style>
            .resizer {
                width: 5px;
                cursor: col-resize;
                background: #e5e7eb;
                transition: background 0.2s;
            }
            .resizer:hover, .resizer.resizing {
                background: #3b82f6;
            }
            .iframe-overlay {
                display: none;
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 9999;
            }
            .iframe-overlay.active {
                display: block;
            }
        </style>
    </head>
    <body class="h-screen bg-gray-100">
        <div class="flex h-full">
            <!-- Sidebar -->
            <aside id="sidebar" class="bg-white border-r border-gray-200 overflow-y-auto" style="width: 256px; min-width: 150px; max-width: 600px;">
                <div class="p-4 border-b">
                    <h1 class="text-lg font-semibold">Admin Console</h1>
                    <p class="text-xs text-gray-500">Auto-discovered tools</p>
                </div>
                <nav class="p-2 space-y-1">
                    <?php foreach ($menu as $item): ?>
                        <a href="<?= htmlspecialchars($item['file']) ?>" target="contentFrame" class="block px-3 py-2 rounded-md text-sm text-gray-700 hover:bg-gray-100">
                            <?= htmlspecialchars($item['label']) ?>
                        </a>
                    <?php endforeach; ?>

                    <?php if (!empty($imported)): ?>
                        <div class="mt-3 pt-3 border-t border-gray-200">
                            <div class="px-3 pb-1 text-[11px] font-semibold tracking-wide text-gray-500 uppercase">Imported</div>
                            <?php foreach ($imported as $item): ?>
                                <a href="<?= htmlspecialchars($item['file']) ?>" target="contentFrame" class="block px-3 py-2 rounded-md text-sm text-gray-700 hover:bg-gray-100">
                                    <?= htmlspecialchars($item['label']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="mt-3 pt-3 border-t border-gray-200">
                        <a href="import_module.php" target="contentFrame" class="block w-full px-3 py-2 rounded-md text-sm text-white bg-gray-900 hover:bg-gray-800 text-center">
                            Import Module
                        </a>
                        <div class="px-3 pt-1 text-[11px] text-gray-500">Uploads to <?= htmlspecialchars(rtrim((string)PRIVATE_ROOT, '/') . '/admin_modules') ?></div>
                    </div>
                </nav>
            </aside>

            <!-- Resizer -->
            <div id="resizer" class="resizer"></div>

            <!-- Content -->
            <main class="flex-1 relative">
                <div id="iframe-overlay" class="iframe-overlay"></div>
                <iframe name="contentFrame" src="admin_sysinfo.php" class="w-full h-full border-0 bg-white"></iframe>
            </main>
        </div>

        <script>
            // Resizable sidebar
            const sidebar = document.getElementById('sidebar');
            const resizer = document.getElementById('resizer');
            const overlay = document.getElementById('iframe-overlay');
            let isResizing = false;

            resizer.addEventListener('mousedown', (e) => {
                isResizing = true;
                resizer.classList.add('resizing');
                overlay.classList.add('active'); // Prevent iframe from capturing mouse
                document.body.style.cursor = 'col-resize';
                document.body.style.userSelect = 'none';
            });

            document.addEventListener('mousemove', (e) => {
                if (!isResizing) return;
                
                const newWidth = e.clientX;
                if (newWidth >= 150 && newWidth <= 600) {
                    sidebar.style.width = newWidth + 'px';
                }
            });

            document.addEventListener('mouseup', () => {
                if (isResizing) {
                    isResizing = false;
                    resizer.classList.remove('resizing');
                    overlay.classList.remove('active');
                    document.body.style.cursor = '';
                    document.body.style.userSelect = '';
                    
                    // Save preference to localStorage
                    localStorage.setItem('adminSidebarWidth', sidebar.style.width);
                }
            });

            // Restore saved width on load
            const savedWidth = localStorage.getItem('adminSidebarWidth');
            if (savedWidth) {
                sidebar.style.width = savedWidth;
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

// Welcome page when accessed with ?embed=1 (shouldn't normally happen)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Console</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
    <div class="text-center">
        <h1 class="text-4xl font-bold text-gray-800 mb-4">🛠️ Admin Console</h1>
        <p class="text-gray-600 mb-8">Select a tool from the sidebar to get started</p>
        <a href="index.php" class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-6 rounded-md transition duration-200">
            Go to Dashboard
        </a>
    </div>
</body>
</html>
