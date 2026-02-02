<?php
// /web/api.iernc.net/public_html/v1/whatip.php
require_once dirname(__DIR__) . '/lib/bootstrap.php';
$client = rl_client_ip($TRUSTED_PROXIES);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'client_ip'   => $client,
  'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
  'xff'         => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
  'ua'          => $_SERVER['HTTP_USER_AGENT'] ?? null,
], JSON_UNESCAPED_SLASHES);
