<?php

declare(strict_types=1);

// Shared helpers for CodeWalker Admin pages.

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function getp(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

function postp(string $key, $default = null)
{
    return $_POST[$key] ?? $default;
}

function now_iso(): string
{
    return date('Y-m-d\TH:i:s');
}

function sha256_file_s(string $path): ?string
{
    if (!is_file($path)) return null;
    $ctx = hash_init('sha256');
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    while (!feof($fh)) {
        $buf = fread($fh, 8192);
        if ($buf === false) {
            fclose($fh);
            return null;
        }
        hash_update($ctx, $buf);
    }
    fclose($fh);
    return hash_final($ctx);
}

function require_csrf(): void
{
    if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
        http_response_code(400);
        echo 'CSRF token invalid.';
        exit;
    }
}

function ensure_csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['csrf'];
}

function login_required(string $ADMIN_PASS): void
{
    if ($ADMIN_PASS === '') return; // disabled

    // Ensure session is started
    if (function_exists('auth_session_start')) {
        auth_session_start();
    } elseif (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_pass'])) {
            if (hash_equals($ADMIN_PASS, (string)$_POST['admin_pass'])) {
                $_SESSION['logged_in'] = true;
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }
            $err = 'Invalid password';
        }
        $csrf = ensure_csrf();
        echo '<!doctype html><meta charset="utf-8"><title>CodeWalker Admin Login</title>';
        echo '<style>body{font-family:system-ui,Segoe UI,Arial;margin:2rem;background:#0b1020;color:#eef} .card{max-width:420px;margin:10vh auto;padding:1.5rem;background:#141a33;border:1px solid #263056;border-radius:10px} input{width:100%;padding:.6rem;border-radius:8px;border:1px solid #344;color:#eef;background:#0e1330} button{padding:.6rem 1rem;border-radius:8px;border:1px solid #37f;background:#1a2246;color:#fff;cursor:pointer} .err{color:#f77;margin:.5rem 0}</style>';
        echo '<div class="card"><h2>CodeWalker Admin</h2>';
        if (!empty($err)) echo '<div class="err">' . h($err) . '</div>';
        echo '<form method="post"><input type="password" name="admin_pass" placeholder="Admin password" autofocus><input type="hidden" name="csrf" value="' . h($csrf) . '"><div style="margin-top:1rem"><button type="submit">Sign in</button></div></form></div>';
        exit;
    }
}

if (!function_exists('cw_starts_with')) {
    function cw_starts_with(string $haystack, string $needle): bool
    {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}
