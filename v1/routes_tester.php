<?php
// /web/html/v1/routes_tester.php
// Simple RewriteRule link generator for testing routes.
// Access via: /v1/routes_tester (not /v1/routes_tester.php)

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../lib/bootstrap.php';
$htaccessPath = __DIR__ . '/.htaccess';

function html(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function detect_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // If this script is /v1/routes_tester.php, dirname is /v1
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/v1'), '/');
    if ($base === '') $base = '/';

    return $scheme . '://' . $host . $base;
}

function parse_htaccess(string $path): array {
    $out = [
        'rewrite_base' => null,
        'rules' => [],
        'errors' => [],
    ];

    if (!is_readable($path)) {
        $out['errors'][] = "Cannot read: $path";
        return $out;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        $out['errors'][] = "Failed to read: $path";
        return $out;
    }

    foreach ($lines as $i => $rawLine) {
        $line = trim($rawLine);
        if ($line === '' || strpos($line, '#') === 0) continue;

        if (preg_match('#^RewriteBase\s+(\S+)#i', $line, $m)) {
            $out['rewrite_base'] = $m[1];
            continue;
        }

        if (preg_match('#^RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[([^\]]+)\])?#i', $line, $m)) {
            $pattern = $m[1];
            $target = $m[2];
            $flags = isset($m[3]) ? $m[3] : '';
            $out['rules'][] = [
                'line' => $i + 1,
                'raw' => $rawLine,
                'pattern' => $pattern,
                'target' => $target,
                'flags' => $flags,
            ];
        }
    }

    return $out;
}

function sample_paths_from_pattern(string $pattern): array {
    // Ignore canonicalization rules like ^(.+)/$
    if ($pattern === '^(.+)/$' || $pattern === '^(.+)/$') return [];

    $optSlash = false;
    if (preg_match('#/\?\$$#', $pattern)) {
        $optSlash = true;
        $pattern = preg_replace('#/\?\$$#', '$', $pattern);
    }

    $p = trim($pattern);
    $p = trim($p, '^$');

    // Common placeholders
    $p = str_replace('([A-Fa-f0-9]{32})', '0123456789abcdef0123456789abcdef', $p);
    $p = str_replace('([A-Fa-f0-9]{16})', '0123456789abcdef', $p);
    $p = str_replace('([0-9]{32})', '00000000000000000000000000000000', $p);
    $p = str_replace('(.*)', 'test', $p);
    $p = str_replace('(.+)', 'test', $p);

    // Very small regex-to-path cleanup for common patterns.
    $p = str_replace('\/', '/', $p);
    $p = preg_replace('#\(\?:#', '(', $p);
    $p = str_replace(['?', '+', '*', '(', ')', '[', ']', '{', '}', '|'], '', $p);
    $p = str_replace('\\', '', $p);
    $p = trim($p, '/');

    if ($p === '') return [];

    $paths = [$p];
    if ($optSlash) {
        $paths[] = $p . '/';
    }

    // Also provide a trailing-slash variant for easy canonicalization testing.
    if (!$optSlash && substr($p, -1) !== '/') {
        $paths[] = $p . '/';
    }

    // Unique
    $uniq = [];
    foreach ($paths as $x) {
        if (!isset($uniq[$x])) $uniq[$x] = true;
    }
    return array_keys($uniq);
}

$baseUrl = detect_base_url();
$parsed = parse_htaccess($htaccessPath);

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>v1 Route Tester</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; padding: 18px; }
    code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .muted { color: #666; }
    table { border-collapse: collapse; width: 100%; margin-top: 12px; }
    th, td { border: 1px solid #ddd; padding: 8px; vertical-align: top; }
    th { background: #f5f5f5; text-align: left; }
    .links a { display: inline-block; margin-right: 10px; margin-bottom: 6px; }
    .small { font-size: 0.9rem; }
  </style>
</head>
<body>
  <h1>v1 Route Tester</h1>
  <div class="muted small">
    Reads: <code><?php echo html($htaccessPath); ?></code><br>
    Base URL (detected): <code><?php echo html($baseUrl); ?></code>
  </div>

  <?php if (!empty($parsed['errors'])): ?>
    <p style="color:#b00020;"><strong>Errors:</strong> <?php echo html(implode(' | ', $parsed['errors'])); ?></p>
  <?php endif; ?>

  <h2>Quick Links</h2>
  <div class="links">
    <a href="<?php echo html($baseUrl . '/health'); ?>" target="_blank" rel="noopener">/health</a>
    <a href="<?php echo html($baseUrl . '/ping'); ?>" target="_blank" rel="noopener">/ping</a>
    <a href="<?php echo html($baseUrl . '/models'); ?>" target="_blank" rel="noopener">/models</a>
    <a href="<?php echo html($baseUrl . '/inbox'); ?>" target="_blank" rel="noopener">/inbox</a>
  </div>

  <h2>.htaccess RewriteRule Table</h2>
  <table>
    <thead>
      <tr>
        <th>Line</th>
        <th>Pattern</th>
        <th>Target</th>
        <th>Flags</th>
        <th>Test Links</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($parsed['rules'] as $r): ?>
        <?php
          $pattern = (string)$r['pattern'];
          $target  = (string)$r['target'];
          $flags   = (string)$r['flags'];
          $samples = sample_paths_from_pattern($pattern);
        ?>
        <tr>
          <td class="small"><?php echo (int)$r['line']; ?></td>
          <td><code><?php echo html($pattern); ?></code></td>
          <td><code><?php echo html($target); ?></code></td>
          <td class="small"><?php echo html($flags); ?></td>
          <td class="small">
            <?php if (empty($samples)): ?>
              <span class="muted">(no sample)</span>
            <?php else: ?>
              <?php foreach ($samples as $p): ?>
                <?php
                  $u = $baseUrl . '/' . ltrim($p, '/');
                ?>
                <a href="<?php echo html($u); ?>" target="_blank" rel="noopener"><?php echo html('/' . ltrim($p, '/')); ?></a>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h2>All routes</h2>
  <div class="links">
    <?php 
      //get all dirs and files in v1
      $v1Dir = __DIR__;
      $items = scandir($v1Dir);
      foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === 'routes_tester.php') {
          continue;
        }
        $fullPath = $v1Dir . '/' . $item;
        if (is_dir($fullPath)) {
          //scan dir for php files
          $subItems = scandir($fullPath);
          foreach ($subItems as $subItem) {
            if (substr($subItem, -4) === '.php') {
              $routePath = '/' . $item . '/' . $subItem;
              $routePath = str_replace('.php', '', $routePath);
              $u = $baseUrl . $routePath;
              echo '<a href="' . html($u) . '" target="_blank" rel="noopener">' . html($routePath) . '</a>';
            }
          }
        } elseif (substr($item, -4) === '.php') {
          $routePath = '/' . $item;
          $routePath = str_replace('.php', '', $routePath);
          $u = $baseUrl . $routePath;
          echo '<a href="' . html($u) . '" target="_blank" rel="noopener">' . html($routePath) . '</a>';
        }
      }
    ?>
  </div>

  <h2>Raw .htaccess</h2>
  <pre><?php echo html(@file_get_contents($htaccessPath) ?: ''); ?></pre>

<h2>API tree view</h2>
<?php
//display tree of v1 directory
function build_tree(string $dir): array {
    $result = [];
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $fullPath = $dir . '/' . $item;
        if (is_dir($fullPath)) {
            $result[$item] = build_tree($fullPath);
        } else {
            $result[] = $item;
        }
    }
    return $result;
}

$tree = build_tree(__DIR__);
function render_tree(array $tree, string $prefix = ''): void {
    $count = count($tree);
    $i = 0;
    foreach ($tree as $key => $value) {
        $i++;
        $isLast = ($i === $count);
        $connector = $isLast ? '└── ' : '├── ';
        if (is_array($value)) {
            echo $prefix . $connector . html($key) . "\n";
            $newPrefix = $prefix . ($isLast ? '    ' : '│   ');
            render_tree($value, $newPrefix);
        } else {
            echo $prefix . $connector . html($value) . "\n";
        }
    }
}

echo "<pre>\n";
render_tree($tree);
echo "</pre>\n";  
?>



</body>
</html>
