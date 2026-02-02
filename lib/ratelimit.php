<?php
// File+flock sliding-window limiter with trusted-proxy-aware client IP.
// Backwards compatible with your existing: rl_check(), rl_headers(), rl_client_ip()

/* =========================
 * Storage (no DB required)
 * ========================= */
function rl_dir(): string {
  $base = null;
  if (defined('PRIVATE_ROOT')) {
    $base = (string)PRIVATE_ROOT;
  } else {
    $envPrivate = getenv('PRIVATE_ROOT');
    if ($envPrivate !== false && $envPrivate !== '') {
      $base = (string)$envPrivate;
    }
  }

  if (!$base) {
    $appRoot = dirname(__DIR__);
    $candidates = [
      dirname($appRoot) . '/private',
      $appRoot . '/../private',
      $appRoot . '/private',
    ];
    foreach ($candidates as $cand) {
      if (is_dir($cand) && is_readable($cand)) {
        $base = $cand;
        break;
      }
    }
  }

  if (!$base) {
    $base = rtrim(sys_get_temp_dir(), '/\\') . '/private';
  }

  $dir = rtrim(str_replace('\\', '/', $base), '/') . '/ratelimit';
  if (!is_dir($dir)) @mkdir($dir, 0770, true);
  return $dir;
}
function rl_key(string $id): string {
  // id examples: "ip:/v1/receiving", "key:abc123"
  return hash('sha256', $id);
}

/* ==========================================
 * Sliding-window check (timestamps in JSON)
 * ========================================== */
function rl_check(string $id, int $limit, int $windowSec): array {
  $dir  = rl_dir();
  $file = $dir . '/' . rl_key($id) . '.json';
  $now  = microtime(true);

  // Create/open atomically
  $fp = @fopen($file, 'c+');
  if (!$fp) {
    // fail-open rather than DOS the caller
    return [true, $limit, time() + $windowSec];
  }

  // Lock and read current hits
  flock($fp, LOCK_EX);
  $raw  = stream_get_contents($fp);
  $hits = [];
  if ($raw !== false && $raw !== '') {
    $hits = json_decode($raw, true);
    if (!is_array($hits)) $hits = [];
  }

  // Prune to the active window
  $threshold = $now - $windowSec;
  $keep = [];
  foreach ($hits as $t) {
    if (is_numeric($t) && $t > $threshold) $keep[] = (float)$t;
  }
  $hits = $keep;

  // Bound array length a bit to avoid pathological growth
  if (count($hits) > ($limit + 8)) {
    $hits = array_slice($hits, -($limit + 8));
  }

  $count = count($hits);
  if ($count >= $limit) {
    // Deny: figure out when a slot frees (oldest still in window)
    $first    = $hits[0] ?? $now;
    $resetTs  = (int)ceil($first + $windowSec);
    $remaining = 0;
    rl_write_and_unlock($fp, $hits);
    return [false, $remaining, $resetTs];
  }

  // Allow: append this hit and persist
  $hits[] = $now;
  rl_write_and_unlock($fp, $hits);

  $remaining = $limit - count($hits);
  $first     = $hits[0] ?? $now;
  $resetTs   = (int)ceil($first + $windowSec);
  return [true, $remaining, $resetTs];
}

function rl_write_and_unlock($fp, array $hits): void {
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($hits, JSON_UNESCAPED_SLASHES));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
}

/* =======================
 * Rate-limit headers
 * ======================= */
function rl_headers(int $limit, int $remaining, int $resetTs): void {
  header('X-RateLimit-Limit: ' . $limit);
  header('X-RateLimit-Remaining: ' . $remaining);
  header('X-RateLimit-Reset: ' . $resetTs);
  if ($remaining <= 0) {
    $retry = max(1, $resetTs - time());
    header('Retry-After: ' . $retry);
  }
}

/* =======================================================
 * Trusted-proxy–aware client IP (fixes old XFF behavior)
 * ======================================================= */

/** Match IP against a list of exact IPs or CIDRs (IPv4). */
function rl_ip_in_list(string $ip, array $list): bool {
  foreach ($list as $cidr) {
    if (strpos($cidr, '/') === false) {
      if ($ip === $cidr) return true;
      continue;
    }
    [$net, $mask] = explode('/', $cidr, 2);
    $mask = (int)$mask;
    if ($mask < 0 || $mask > 32) continue;

    if (!filter_var($ip,  FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
    if (!filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;

    $ipn = ip2long($ip);
    $nn  = ip2long($net);
    $mn  = ($mask === 0) ? 0 : (-1 << (32 - $mask));
    if (($ipn & $mn) === ($nn & $mn)) return true;
  }
  return false;
}


/**
 * Determine real client IP.
 * - If REMOTE_ADDR is NOT a trusted proxy → use REMOTE_ADDR.
 * - If it IS trusted, walk X-Forwarded-For chain from right→left and
 *   return the first hop that is NOT trusted. If all are trusted → REMOTE_ADDR.
 *
 * You can pass $trustedProxies explicitly, or define a global:
 *   $TRUSTED_PROXIES = ['127.0.0.1','167.172.26.150','134.199.240.99'];
 */
function rl_client_ip(?array $trustedProxies = null): string {
  if ($trustedProxies === null && isset($GLOBALS['TRUSTED_PROXIES']) && is_array($GLOBALS['TRUSTED_PROXIES'])) {
    $trustedProxies = $GLOBALS['TRUSTED_PROXIES'];
  }
  $trustedProxies = $trustedProxies ?? [];

  $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  $xff    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

  // Not behind a trusted proxy → REMOTE_ADDR is the client
  if (!rl_ip_in_list($remote, $trustedProxies)) return $remote;

  // Behind a trusted proxy
  if ($xff) {
    $chain = array_map('trim', explode(',', $xff));
    // scan from right (closest) to left (farthest)
    for ($i = count($chain) - 1; $i >= 0; $i--) {
      $ip = $chain[$i];
      if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;
      if (!rl_ip_in_list($ip, $trustedProxies)) return $ip;
    }
  }
  // All hops trusted or no XFF
  return $remote;
}
