<?php
// Shared helper functions for /admin/admin_Update.php
// Assumes bootstrap.php and auth helpers are already loaded by caller.

if (!function_exists('update_version_file')) {
    function update_version_file(): string {
        return APP_ROOT . '/VERSION';
    }
}

if (!function_exists('update_read_current_version')) {
    function update_read_current_version(): string {
        $path = update_version_file();
        if (!is_readable($path)) return 'unknown';
        $v = @file_get_contents($path);
        return is_string($v) ? trim($v) : 'unknown';
    }
}

if (!function_exists('update_write_version')) {
    function update_write_version(string $version): bool {
        $path = update_version_file();
        return @file_put_contents($path, trim($version) . "\n", LOCK_EX) !== false;
    }
}

if (!function_exists('update_extract_commit_from_version')) {
    function update_extract_commit_from_version(string $version): string {
        $v = trim($version);
        if ($v === '') return '';
        if (preg_match('/-([a-f0-9]{7,40})$/i', $v, $m)) {
            return strtolower((string)$m[1]);
        }
        return '';
    }
}

if (!function_exists('update_tmp_dir')) {
    function update_tmp_dir(): string {
        $dir = PRIVATE_ROOT . '/tmp/update_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }
}

if (!function_exists('update_log_path')) {
    function update_log_path(): string {
        return PRIVATE_ROOT . '/logs/admin_update.log';
    }
}

if (!function_exists('update_log')) {
    function update_log(string $msg): void {
        $dir = dirname(update_log_path());
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $ts = date('Y-m-d H:i:s');
        $ip = auth_client_ip();
        @file_put_contents(update_log_path(), "[$ts] [$ip] $msg\n", FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('update_release_history')) {
    function update_release_history(int $limit = 10): array {
        $limit = (int)$limit;
        if ($limit < 1) $limit = 1;
        if ($limit > 50) $limit = 50;

        $dir = PRIVATE_ROOT . '/releases/' . UPDATE_APP_NAME;
        if (!is_dir($dir)) return [];

        $files = glob($dir . '/' . UPDATE_APP_NAME . '-*.tar.gz');
        if (!is_array($files) || empty($files)) return [];

        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        $files = array_slice($files, 0, $limit);

        $rows = [];
        foreach ($files as $path) {
            $base = basename((string)$path);
            $version = preg_replace('/^' . preg_quote(UPDATE_APP_NAME . '-', '/') . '/', '', $base);
            $version = preg_replace('/\.tar\.gz$/', '', (string)$version);
            $rows[] = [
                'filename' => $base,
                'version' => (string)$version,
                'bytes' => (int)@filesize($path),
                'mtime' => (int)@filemtime($path),
            ];
        }
        return $rows;
    }
}

if (!function_exists('update_fetch_latest_info')) {
    function update_fetch_latest_info(): array {
        $app = UPDATE_APP_NAME;

        $latestPath = PRIVATE_ROOT . '/releases/' . $app . '/latest.json';
        if (is_readable($latestPath)) {
            $raw = @file_get_contents($latestPath);
            if (is_string($raw) && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    return ['ok' => true, 'latest' => $data, 'source' => 'local'];
                }
            }
        }

        $baseUrl = 'http://127.0.0.1';
        $url = $baseUrl . '/v1/releases/latest?app=' . urlencode($app);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || $code !== 200 || !is_string($resp)) {
            return ['ok' => false, 'error' => $err ?: "HTTP $code", 'source' => 'http'];
        }

        $data = json_decode($resp, true);
        if (!is_array($data) || empty($data['ok'])) {
            return ['ok' => false, 'error' => 'Invalid response', 'source' => 'http'];
        }

        return ['ok' => true, 'latest' => $data['latest'] ?? [], 'source' => 'http'];
    }
}

if (!function_exists('update_download_tarball')) {
    function update_download_tarball(string $filename, string $destPath): array {
        $app = UPDATE_APP_NAME;

        $localPath = PRIVATE_ROOT . '/releases/' . $app . '/' . $filename;
        if (is_readable($localPath)) {
            if (@copy($localPath, $destPath)) {
                return ['ok' => true, 'source' => 'local', 'path' => $destPath];
            }
        }

        $baseUrl = 'http://127.0.0.1';
        $url = $baseUrl . '/v1/releases/download?app=' . urlencode($app) . '&file=' . urlencode($filename);

        $fp = @fopen($destPath, 'wb');
        if (!$fp) {
            return ['ok' => false, 'error' => 'Cannot write to ' . $destPath];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $code !== 200) {
            @unlink($destPath);
            return ['ok' => false, 'error' => $err ?: "HTTP $code"];
        }

        return ['ok' => true, 'source' => 'http', 'path' => $destPath];
    }
}

if (!function_exists('update_verify_sha256')) {
    function update_verify_sha256(string $filePath, string $expected): bool {
        if ($expected === '') return true;
        $actual = @hash_file('sha256', $filePath);
        return is_string($actual) && strcasecmp($actual, $expected) === 0;
    }
}

if (!function_exists('update_extract_tarball')) {
    function update_extract_tarball(string $tarPath, string $destDir): array {
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0700, true);
        }

        $cmd = sprintf(
            'tar -xzf %s -C %s 2>&1',
            escapeshellarg($tarPath),
            escapeshellarg($destDir)
        );

        $output = [];
        $ret = 0;
        exec($cmd, $output, $ret);

        if ($ret !== 0) {
            return ['ok' => false, 'error' => 'tar failed: ' . implode("\n", $output)];
        }

        return ['ok' => true, 'dir' => $destDir];
    }
}

if (!function_exists('update_sync_dir')) {
    function update_sync_dir(string $srcDir, string $destDir): array {
        if (!is_dir($srcDir)) {
            return ['ok' => false, 'error' => "Source not found: $srcDir"];
        }
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }

        $rsync = trim((string)shell_exec('which rsync 2>/dev/null'));
        if ($rsync !== '') {
            $cmd = sprintf(
                'rsync -a --delete %s/ %s/ 2>&1',
                escapeshellarg(rtrim($srcDir, '/')),
                escapeshellarg(rtrim($destDir, '/'))
            );
        } else {
            $cmd = sprintf(
                'rm -rf %s/* 2>/dev/null; cp -a %s/* %s/ 2>&1',
                escapeshellarg($destDir),
                escapeshellarg($srcDir),
                escapeshellarg($destDir)
            );
        }

        $output = [];
        $ret = 0;
        exec($cmd, $output, $ret);
        if ($ret !== 0) {
            return ['ok' => false, 'error' => 'Sync failed: ' . implode("\n", $output)];
        }
        return ['ok' => true];
    }
}

if (!function_exists('update_cleanup')) {
    function update_cleanup(string $dir): void {
        if ($dir === '' || !is_dir($dir)) return;
        if (strpos($dir, PRIVATE_ROOT . '/tmp') !== 0) return;
        exec('rm -rf ' . escapeshellarg($dir) . ' 2>/dev/null');
    }
}

if (!function_exists('update_target_is_writable')) {
    function update_target_is_writable(string $destDir): bool {
        if (is_dir($destDir)) {
            return is_writable($destDir);
        }
        $parent = dirname($destDir);
        return is_dir($parent) && is_writable($parent);
    }
}

if (!function_exists('update_prepare_release_tree')) {
    function update_prepare_release_tree(array $latest): array {
        $filename = (string)($latest['filename'] ?? '');
        $sha256 = (string)($latest['sha256'] ?? '');
        if ($filename === '') {
            return ['ok' => false, 'error' => 'No filename in release info'];
        }

        $tmpDir = update_tmp_dir();
        $tarPath = $tmpDir . '/' . $filename;

        $dl = update_download_tarball($filename, $tarPath);
        if (empty($dl['ok'])) {
            update_cleanup($tmpDir);
            return ['ok' => false, 'error' => (string)($dl['error'] ?? 'Download failed')];
        }

        if (!update_verify_sha256($tarPath, $sha256)) {
            update_cleanup($tmpDir);
            return ['ok' => false, 'error' => 'SHA256 checksum mismatch'];
        }

        $extractDir = $tmpDir . '/extract';
        $ext = update_extract_tarball($tarPath, $extractDir);
        if (empty($ext['ok'])) {
            update_cleanup($tmpDir);
            return ['ok' => false, 'error' => (string)($ext['error'] ?? 'Extract failed')];
        }

        $appRoot = $extractDir . '/' . UPDATE_APP_NAME;
        if (!is_dir($appRoot)) {
            $appRoot = $extractDir;
        }
        if (!is_dir($appRoot)) {
            update_cleanup($tmpDir);
            return ['ok' => false, 'error' => 'Extracted app root not found'];
        }

        return [
            'ok' => true,
            'tmp_dir' => $tmpDir,
            'app_root' => $appRoot,
            'tar_path' => $tarPath,
            'filename' => $filename,
        ];
    }
}

if (!function_exists('update_preview_target_changes')) {
    function update_preview_target_changes(string $srcDir, string $destDir): array {
        if (!is_dir($srcDir)) {
            return ['ok' => false, 'error' => 'Source missing'];
        }
        if (!is_dir($destDir)) {
            return ['ok' => true, 'changed' => 0, 'created' => 0, 'updated' => 0, 'missing_dest' => true, 'sample' => []];
        }

        $rsync = trim((string)shell_exec('which rsync 2>/dev/null'));
        if ($rsync === '') {
            return ['ok' => false, 'error' => 'rsync not found for preview'];
        }

        $cmd = sprintf(
            "rsync -ain --no-perms --no-owner --no-group --out-format='%%i %%n' %s/ %s/ 2>/dev/null",
            escapeshellarg(rtrim($srcDir, '/')),
            escapeshellarg(rtrim($destDir, '/'))
        );
        $out = [];
        $ret = 0;
        exec($cmd, $out, $ret);
        if ($ret !== 0) {
            return ['ok' => false, 'error' => 'rsync preview failed'];
        }

        $created = 0;
        $updated = 0;
        $sample = [];
        foreach ($out as $line) {
            $line = trim((string)$line);
            if ($line === '' || strpos($line, 'sending incremental file list') !== false) continue;
            if (strpos($line, 'sent ') === 0 || strpos($line, 'total size is ') === 0) continue;

            $parts = preg_split('/\s+/', $line, 2);
            $sig = isset($parts[0]) ? (string)$parts[0] : '';
            $name = isset($parts[1]) ? (string)$parts[1] : '';
            if ($name === '' || substr($name, -1) === '/') continue;
            if (strpos($sig, '>f') !== 0 && strpos($sig, 'c') !== 0) continue;

            if (strpos($sig, '+++++++++') !== false) $created++;
            else $updated++;

            if (count($sample) < 20) $sample[] = $name;
        }

        return [
            'ok' => true,
            'changed' => $created + $updated,
            'created' => $created,
            'updated' => $updated,
            'sample' => $sample,
        ];
    }
}

if (!function_exists('update_manual_rsync_commands')) {
    function update_manual_rsync_commands(string $filename, string $version, array $selected, array $targets): string {
        $lines = [];
        $lines[] = '# Manual upgrade (no --delete). Run as root/sudo on target server.';
        $lines[] = 'REL="/web/private/releases/' . UPDATE_APP_NAME . '/' . $filename . '"';
        $lines[] = 'TMP="/tmp/update_' . UPDATE_APP_NAME . '_$(date +%s)"';
        $lines[] = 'sudo mkdir -p "$TMP"';
        $lines[] = 'sudo tar -xzf "$REL" -C "$TMP"';
        $lines[] = 'if [ -d "$TMP/' . UPDATE_APP_NAME . '" ]; then SRC="$TMP/' . UPDATE_APP_NAME . '"; else SRC="$TMP"; fi';
        foreach ($selected as $key) {
            if (!isset($targets[$key])) continue;
            $cfg = $targets[$key];
            $lines[] = 'sudo rsync -av "$SRC/' . $cfg['src'] . '/" "' . $cfg['dest'] . '/"';
        }
        if ($version !== '') {
            $lines[] = 'echo "' . $version . '" | sudo tee /web/html/VERSION >/dev/null';
        }
        $lines[] = 'sudo rm -rf "$TMP"';
        return implode("\n", $lines);
    }
}

