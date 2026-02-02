<?php
function db(): PDO {
  static $pdo;
  if ($pdo) return $pdo;
  if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('pdo_sqlite not loaded. Install php-sqlite3 and enable pdo_sqlite.');
  }  
  $pdo = new PDO('sqlite:/web/private/db/api.sqlite', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT            => 5,  // busy timeout seconds
  ]);
  $pdo->exec("PRAGMA journal_mode=WAL");
  $pdo->exec("PRAGMA synchronous=NORMAL");
  $pdo->exec("PRAGMA busy_timeout=5000");
  return $pdo;
}
function ulid(): string {
  // simple sortable id; good enough
  $t = (int)(microtime(true)*1000);
  $r = bin2hex(random_bytes(8));
  return strtoupper(dechex($t)) . $r;
}
