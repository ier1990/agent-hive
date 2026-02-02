<?php
declare(strict_types=1);

const PROMPTS_DB_PATH = '/web/private/db/memory/prompts.db';

function ensurePromptsDb(): SQLite3 {
    $dir = dirname(PROMPTS_DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $db = new SQLite3(PROMPTS_DB_PATH);
    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec('PRAGMA busy_timeout=5000;');

    $db->exec('CREATE TABLE IF NOT EXISTS prompts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        kind TEXT DEFAULT "prompt",            -- prompt | system | tool | persona | etc
        tags TEXT,                              -- csv tags
        model_hint TEXT,                        -- optional
        version TEXT,                           -- like 2025-12-26.1
        body TEXT NOT NULL,                     -- the prompt text
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    $db->exec('CREATE INDEX IF NOT EXISTS idx_prompts_name ON prompts(name)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_prompts_kind ON prompts(kind)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_prompts_tags ON prompts(tags)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_prompts_updated ON prompts(updated_at DESC)');

    return $db;
}

function promptsCreate(SQLite3 $db, array $in): int {
    $name = trim((string)($in['name'] ?? ''));
    $body = trim((string)($in['body'] ?? ''));
    if ($name === '' || $body === '') throw new RuntimeException('name/body required');

    $stmt = $db->prepare('INSERT INTO prompts (name, kind, tags, model_hint, version, body)
                          VALUES (:name, :kind, :tags, :model, :version, :body)');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':kind', trim((string)($in['kind'] ?? 'prompt')) ?: 'prompt', SQLITE3_TEXT);
    $stmt->bindValue(':tags', trim((string)($in['tags'] ?? '')) ?: null, SQLITE3_TEXT);
    $stmt->bindValue(':model', trim((string)($in['model_hint'] ?? '')) ?: null, SQLITE3_TEXT);
    $stmt->bindValue(':version', trim((string)($in['version'] ?? '')) ?: null, SQLITE3_TEXT);
    $stmt->bindValue(':body', $body, SQLITE3_TEXT);
    $stmt->execute();

    return (int)$db->lastInsertRowID();
}

function promptsDelete(SQLite3 $db, int $id): void {
    $stmt = $db->prepare('DELETE FROM prompts WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
}

function promptsFetch(SQLite3 $db, ?string $q = null, ?string $kind = null, int $limit = 200): array {
    $sql = 'SELECT id, name, kind, tags, model_hint, version, body, created_at, updated_at FROM prompts';
    $where = [];
    $params = [];

    if ($kind && $kind !== 'all') {
        $where[] = 'kind = :kind';
        $params[':kind'] = $kind;
    }
    if ($q !== null && trim($q) !== '') {
        $where[] = '(name LIKE :q OR body LIKE :q OR tags LIKE :q OR model_hint LIKE :q OR version LIKE :q)';
        $params[':q'] = '%' . trim($q) . '%';
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY updated_at DESC LIMIT :lim';

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v, SQLITE3_TEXT);
    $stmt->bindValue(':lim', max(1, min($limit, 1000)), SQLITE3_INTEGER);

    $res = $stmt->execute();
    $rows = [];
    while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $rows[] = $row;
    return $rows;
}
