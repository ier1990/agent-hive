<?php
declare(strict_types=1);

static $reserved = [
  'table','index','view','trigger','virtual','with','group','select','from','where',
  'insert','update','delete','into','values','create','drop','alter',
  'order','limit','offset'
];

function getExistingColumns(PDO $pdo, string $table): array {
    $stmt = $pdo->prepare("PRAGMA table_info(`$table`)");
    $stmt->execute();
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cols[strtolower($row['name'])] = true;
    }
    return $cols;
}

/** Add any missing columns. Returns array of columns added. */
function ensureTableColumns(PDO $pdo, string $table, array $schema): array {
    $existing = getExistingColumns($pdo, $table);
    $added = [];
    foreach ($schema as $name => $rules) {
        $lname = strtolower($name);
        if (isset($existing[$lname])) continue;

        // For ALTER TABLE, keep it simple (strip DEFAULTs / constraints)
        $type = $rules['type'] ?? 'TEXT';
        $type = preg_replace('/\s+DEFAULT\b.*$/i', '', $type); // strip DEFAULT exprs
        if ($name === 'id') { continue; } // don't try to add PK retroactively

        $sql = "ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$type}";
        $pdo->exec($sql);
        $added[] = $name;
    }
    return $added;
}

/** Create table if missing, otherwise add missing columns. Returns bool: table was created. */
function ensureTableAndColumns(PDO $pdo, string $table, array $schema): bool {
    $created = false;
    if (!tableExists($pdo, $table)) {
        // fresh create with full schema
        $cols = [];
        foreach ($schema as $name => $rules) {
            $type = $rules['type'] ?? 'TEXT';
            $cols[] = '`'.$name.'` '.$type;
        }
        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (".implode(", ", $cols).")";
        $pdo->exec($sql);
        $created = true;
    } else {
        // migrate in place
        ensureTableColumns($pdo, $table, $schema);
    }
    return $created;
}





/**
 * Map incoming JSON to a DB path.
 * Priority: explicit 'db' -> 'service' -> 'generic_input'
 */
function getDatabasePath(array $data): string {
    // db groups the database file; service becomes the table
    $dbName = $data['db'] ?? 'inbox';
    $dbSafe = sanitize_identifier((string)$dbName);
    if ($dbSafe === '') $dbSafe = 'inbox';

    // Optional: isolate by node (prevents collisions across senders)
    // Example node: lan-191, do1, jville, etc.
    $node = $data['node'] ?? '';
    $nodeSafe = $node !== '' ? sanitize_identifier((string)$node) : '';

    $baseDir = '/web/private/db/inbox';
    $file = $dbSafe;

    // If node is present, append it (recommended for fleet)
    if ($nodeSafe !== '') {
        $file .= '__' . $nodeSafe;
    }

    return $baseDir . '/' . $file . '.db';
}


/**
 * Sanitize any identifier (db/table/column) to [a-z0-9_], no leading digit.
 * Collapse repeats, trim underscores, lowercase.
 */
function sanitize_identifier(string $name): string {
    $name = strtolower($name);
    $name = preg_replace('/[^a-z0-9_]+/', '_', $name) ?? '';
    $name = trim($name, '_');
    if ($name === '') return '';
    if (ctype_digit($name[0])) $name = 'f_' . $name;
    // Avoid SQLite reserved words in simplest way
    //static $reserved = ['table','index','view','trigger','virtual','with','group','select','from','where','insert','update','delete','into','values','create','drop','alter'];
    static $reserved = ['table','index','view','trigger','virtual','with','group','select','from','where', 'insert','update','delete','into','values','create','drop','alter', 'order','limit','offset' ];
    if (in_array($name, $reserved, true)) $name = $name . '_x';
    return $name;
}

function inferSqliteType($value): string {
    if (is_bool($value)) return 'INTEGER';  // 0/1
    if (is_int($value))  return 'INTEGER';
    if (is_float($value))return 'REAL';
    if (is_string($value)) return 'TEXT';
    return 'TEXT'; // arrays/objects become JSON-encoded TEXT on insert
}

/**
 * Build a column schema array: ['col' => ['type' => 'TEXT' | 'INTEGER' | ...]]
 * NOTE: We DO NOT include id/received_at/meta here; caller can add them.
 */
function buildSchemaFromJson(array $data): array {
    $schema = [];
    foreach ($data as $key => $value) {
        $col = sanitize_identifier((string)$key);
        if ($col === '' || $col === 'id') continue; // skip invalid or PK collision
        $schema[$col] = ['type' => inferSqliteType($value)];
    }
    return $schema;
}

/**
 * CREATE TABLE IF NOT EXISTS with quoted identifiers.
 * Returns true if table was created in this call (best-effort).
 */
function createTableIfMissing(PDO $pdo, string $table, array $schema): bool {
    // Build "colname TYPE" parts
    $cols = [];
    foreach ($schema as $name => $rules) {
        $type = $rules['type'] ?? 'TEXT';
        $cols[] = '`'.$name.'` ' . $type;
    }
    $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (" . implode(", ", $cols) . ")";
    $before = tableExists($pdo, $table);
    $pdo->exec($sql);
    $after  = tableExists($pdo, $table);
    return (!$before && $after);
}

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}
