<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

require_once dirname(__DIR__, 3) . '/lib/bootstrap.php';

api_guard_once('search', false);

$scopes = isset($GLOBALS['APP_SCOPES']) && is_array($GLOBALS['APP_SCOPES']) ? $GLOBALS['APP_SCOPES'] : [];
$clientKey = isset($GLOBALS['APP_CLIENT_KEY']) ? (string)$GLOBALS['APP_CLIENT_KEY'] : '';
if ($clientKey !== '' && !in_array('search', $scopes, true) && !in_array('tools', $scopes, true)) {
  http_json(403, ['ok' => false, 'error' => 'forbidden', 'reason' => 'missing_search_scope']);
}

function cache_search_bad($msg, $extra = []) {
  http_json(400, ['ok' => false, 'error' => $msg] + $extra);
}

function cache_search_trim($value, $maxLen) {
  $value = trim((string)$value);
  if ($maxLen > 0 && strlen($value) > $maxLen) {
    $value = substr($value, 0, $maxLen);
  }
  return $value;
}

$dbPath = rtrim((string)PRIVATE_ROOT, '/\\') . '/db/memory/search_cache.db';
if (!is_file($dbPath) || !is_readable($dbPath)) {
  http_json(503, ['ok' => false, 'error' => 'cache_unavailable']);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$qLike = cache_search_trim(isset($_GET['q']) ? $_GET['q'] : '', 500);
$bodyLike = cache_search_trim(isset($_GET['body_like']) ? $_GET['body_like'] : '', 1000);
$providerType = cache_search_trim(isset($_GET['provider_type']) ? $_GET['provider_type'] : '', 64);
$providerSlot = cache_search_trim(isset($_GET['provider_slot']) ? $_GET['provider_slot'] : '', 32);
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$includeBody = isset($_GET['include_body']) ? (string)$_GET['include_body'] !== '0' : true;

if ($limit < 1) $limit = 1;
if ($limit > 100) $limit = 100;
if ($offset < 0) $offset = 0;

if ($id < 1 && $qLike === '' && $bodyLike === '' && $providerType === '' && $providerSlot === '') {
  cache_search_bad('at_least_one_filter_required', ['allowed' => ['id', 'q', 'body_like', 'provider_type', 'provider_slot']]);
}

try {
  $db = new PDO('sqlite:' . $dbPath);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

  $cols = [];
  $pragma = $db->query('PRAGMA table_info(search_cache_history)');
  if ($pragma) {
    while (($row = $pragma->fetch(PDO::FETCH_ASSOC)) !== false) {
      if (isset($row['name'])) $cols[(string)$row['name']] = true;
    }
  }
  $hasProviderType = isset($cols['provider_type']);
  $hasProviderSlot = isset($cols['provider_slot']);

  $select = 'SELECT id, q, top_urls, ai_notes, cached_at';
  if ($includeBody) $select .= ', body';
  if ($hasProviderType) $select .= ', provider_type';
  if ($hasProviderSlot) $select .= ', provider_slot';
  $sql = $select . ' FROM search_cache_history WHERE 1=1';
  $params = [];

  if ($id > 0) {
    $sql .= ' AND id = :id';
    $params[':id'] = [$id, PDO::PARAM_INT];
  }
  if ($qLike !== '') {
    $sql .= ' AND q LIKE :q_like';
    $params[':q_like'] = ['%' . $qLike . '%', PDO::PARAM_STR];
  }
  if ($bodyLike !== '') {
    $sql .= ' AND body LIKE :body_like';
    $params[':body_like'] = ['%' . $bodyLike . '%', PDO::PARAM_STR];
  }
  if ($providerType !== '' && $hasProviderType) {
    $sql .= ' AND provider_type = :provider_type';
    $params[':provider_type'] = [$providerType, PDO::PARAM_STR];
  }
  if ($providerSlot !== '' && $hasProviderSlot) {
    $sql .= ' AND provider_slot = :provider_slot';
    $params[':provider_slot'] = [$providerSlot, PDO::PARAM_STR];
  }

  $sql .= ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
  $stmt = $db->prepare($sql);
  foreach ($params as $name => $pair) {
    $stmt->bindValue($name, $pair[0], $pair[1]);
  }
  $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
  $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (!is_array($rows)) $rows = [];

  foreach ($rows as &$row) {
    $row['top_urls_json'] = json_decode((string)($row['top_urls'] ?? '[]'), true);
    if (!is_array($row['top_urls_json'])) $row['top_urls_json'] = [];
    if ($includeBody && isset($row['body'])) {
      $decoded = json_decode((string)$row['body'], true);
      if (is_array($decoded)) $row['body_json'] = $decoded;
    }
  }
  unset($row);

  http_json(200, [
    'ok' => true,
    'meta' => [
      'count' => count($rows),
      'limit' => $limit,
      'offset' => $offset,
      'filters' => [
        'id' => $id > 0 ? $id : null,
        'q' => $qLike !== '' ? $qLike : null,
        'body_like' => $bodyLike !== '' ? $bodyLike : null,
        'provider_type' => $providerType !== '' ? $providerType : null,
        'provider_slot' => $providerSlot !== '' ? $providerSlot : null,
        'include_body' => $includeBody,
      ],
    ],
    'items' => $rows,
  ]);
} catch (Throwable $e) {
  http_json(500, ['ok' => false, 'error' => 'cache_query_failed', 'message' => $e->getMessage()]);
}
