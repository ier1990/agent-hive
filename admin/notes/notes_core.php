<?php

// Notes app helpers for admin pages./notes.
// This is a local copy to avoid impacting older pages that may include /web/html/lib/notes_core.php.
// Intentionally no constants here; index.php defines DB_PATH, AI_DB_PATH, UPLOAD_DIR, etc.

function notesDefaultConfig(): array
{
	return [
		'ai.ollama.url' => 'http://192.168.0.142:11434',
		'ai.ollama.model' => 'gpt-oss:latest',
		'search.api.base' => 'http://192.168.0.142/v1/search?q=',
	];
}

function notesDefaultJsonPath(): string
{
	if (defined('NOTES_DEFAULT_JSON_PATH')) {
		$val = (string)constant('NOTES_DEFAULT_JSON_PATH');
		if ($val !== '') {
			return $val;
		}
	}
	return '/web/private/notes_default.json';
}

function notesLoadDefaultConfig(array &$errors): array
{
	$path = notesDefaultJsonPath();
	if (!is_file($path)) {
		return [];
	}
	$raw = @file_get_contents($path);
	if (!is_string($raw) || trim($raw) === '') {
		return [];
	}
	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		$errors[] = 'Invalid JSON in ' . $path;
		return [];
	}
	$out = [];
	foreach ($decoded as $k => $v) {
		if (!is_string($k)) continue;
		if (is_string($v) || is_int($v) || is_float($v) || is_bool($v) || $v === null) {
			$out[$k] = $v;
		}
	}
	return $out;
}

function notesSaveDefaultConfig(array $config, array &$errors): bool
{
	$path = notesDefaultJsonPath();
	$dir = dirname($path);
	if (!is_dir($dir)) {
		if (!@mkdir($dir, 0775, true)) {
			$errors[] = 'Failed to create directory for ' . $path;
			return false;
		}
	}

	$existing = notesLoadDefaultConfig($errors);
	$merged = $existing;
	foreach ($config as $k => $v) {
		if (!is_string($k) || $k === '') continue;
		$merged[$k] = $v;
	}

	// Ensure defaults always exist.
	foreach (notesDefaultConfig() as $k => $v) {
		if (!array_key_exists($k, $merged) || $merged[$k] === null || (is_string($merged[$k]) && trim((string)$merged[$k]) === '')) {
			$merged[$k] = $v;
		}
	}

	if ($existing === $merged && is_file($path)) {
		return true;
	}

	$payload = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if (!is_string($payload) || $payload === '') {
		$errors[] = 'Failed to encode default config JSON';
		return false;
	}
	$payload .= "\n";
	if (@file_put_contents($path, $payload) === false) {
		$errors[] = 'Failed to write ' . $path;
		return false;
	}
	return true;
}

function notesResolveConfig(?SQLite3 $db, array &$errors): array
{
	$defaults = notesDefaultConfig();
	$fileCfg = notesLoadDefaultConfig($errors);
	$cfg = $defaults;
	foreach ($fileCfg as $k => $v) {
		$cfg[$k] = $v;
	}

	if ($db !== null && function_exists('notesGetAppSetting')) {
		try {
			foreach (array_keys($defaults) as $k) {
				$val = notesGetAppSetting($db, $k);
				if ($val !== null && trim($val) !== '') {
					$cfg[$k] = trim($val);
				}
			}
		} catch (Throwable $e) {
			$errors[] = 'Failed to load app settings: ' . $e->getMessage();
		}
	}

	// Always ensure the default JSON exists and is up to date.
	notesSaveDefaultConfig($cfg, $errors);
	return $cfg;
}

function ensureDb(): SQLite3
{
	$dir = dirname(DB_PATH);
	if (!is_dir($dir)) {
		if (!@mkdir($dir, 0775, true)) {
			throw new RuntimeException(
				"Notes DB directory is missing and could not be created: $dir\n" .
				"Fix permissions (example): sudo mkdir -p $dir && sudo chown -R www-data:www-data $dir && sudo chmod 775 $dir"
			);
		}
	}
	if (!is_writable($dir)) {
		throw new RuntimeException(
			"Notes DB directory is not writable by PHP: $dir\n" .
			"Fix permissions (example): sudo chown -R www-data:www-data $dir && sudo chmod 775 $dir"
		);
	}

	try {
		$db = new SQLite3(DB_PATH);
	} catch (Throwable $e) {
		throw new RuntimeException('Failed to open SQLite DB at ' . DB_PATH . ': ' . $e->getMessage());
	}

	$db->exec(
		'CREATE TABLE IF NOT EXISTS notes (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			notes_type TEXT NOT NULL,
			topic TEXT,
			node TEXT,
			path TEXT,
			version TEXT,
			ts TEXT,
			note TEXT NOT NULL,
			parent_id INTEGER DEFAULT 0,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			FOREIGN KEY(parent_id) REFERENCES notes(id)
		)'
	);

	// Migrate older DBs to include tracing fields.
	$existingCols = [];
	$colRes = $db->query('PRAGMA table_info(notes)');
	while ($colRes && ($r = $colRes->fetchArray(SQLITE3_ASSOC))) {
		$existingCols[strtolower((string)$r['name'])] = true;
	}
	$add = [
		'node' => 'TEXT',
		'path' => 'TEXT',
		'version' => 'TEXT',
		'ts' => 'TEXT',
	];
	foreach ($add as $name => $type) {
		if (!isset($existingCols[$name])) {
			$db->exec('ALTER TABLE notes ADD COLUMN ' . $name . ' ' . $type);
		}
	}

	// Indexes help search and threaded lookups stay fast as the file grows.
	$db->exec('CREATE INDEX IF NOT EXISTS idx_notes_parent ON notes(parent_id)');
	$db->exec('CREATE INDEX IF NOT EXISTS idx_notes_created ON notes(created_at DESC)');
	$db->exec('CREATE INDEX IF NOT EXISTS idx_notes_search ON notes(note)');

	// Files table for attachments
	$db->exec(
		'CREATE TABLE IF NOT EXISTS files (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			note_id INTEGER NOT NULL,
			original_name TEXT NOT NULL,
			file_path TEXT NOT NULL,
			file_size INTEGER,
			mime_type TEXT,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			FOREIGN KEY(note_id) REFERENCES notes(id) ON DELETE CASCADE
		)'
	);
	$db->exec('CREATE INDEX IF NOT EXISTS idx_files_note ON files(note_id)');

	// App settings (JSON-friendly): used for things like UI configuration.
	$db->exec(
		'CREATE TABLE IF NOT EXISTS app_settings (
			k TEXT PRIMARY KEY,
			v TEXT NOT NULL,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
		)'
	);

	// Shared state table (used by hourly bash-history ingest scripts).
	$db->exec(
		'CREATE TABLE IF NOT EXISTS history_state (
			host TEXT NOT NULL,
			path TEXT NOT NULL,
			inode TEXT,
			last_line INTEGER DEFAULT 0,
			updated_at TEXT,
			PRIMARY KEY (host, path)
		)'
	);

	// Cron/script heartbeat table.
	$db->exec(
		'CREATE TABLE IF NOT EXISTS job_runs (
			job TEXT PRIMARY KEY,
			last_start TEXT,
			last_ok TEXT,
			last_status TEXT,
			last_message TEXT,
			last_duration_ms INTEGER
		)'
	);

	return $db;
}

function ensureAiDb(): SQLite3
{
	$dir = dirname(AI_DB_PATH);
	if (!is_dir($dir)) {
		if (!@mkdir($dir, 0775, true)) {
			throw new RuntimeException(
				"AI DB directory is missing and could not be created: $dir\n" .
				"Fix permissions (example): sudo mkdir -p $dir && sudo chown -R www-data:www-data $dir && sudo chmod 775 $dir"
			);
		}
	}
	if (!is_writable($dir)) {
		throw new RuntimeException(
			"AI DB directory is not writable by PHP: $dir\n" .
			"Fix permissions (example): sudo chown -R www-data:www-data $dir && sudo chmod 775 $dir"
		);
	}

	try {
		$db = new SQLite3(AI_DB_PATH);
	} catch (Throwable $e) {
		throw new RuntimeException('Failed to open AI SQLite DB at ' . AI_DB_PATH . ': ' . $e->getMessage());
	}

	// AI metadata table written by scripts/ai_notes.py
	$db->exec(
		'CREATE TABLE IF NOT EXISTS ai_note_meta (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			note_id INTEGER NOT NULL,
			parent_id INTEGER DEFAULT 0,
			notes_type TEXT,
			topic TEXT,
			source_hash TEXT NOT NULL,
			model_name TEXT NOT NULL,
			meta_json TEXT NOT NULL,
			summary TEXT,
			tags_csv TEXT,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			UNIQUE(note_id, source_hash)
		)'
	);
	$db->exec('CREATE INDEX IF NOT EXISTS idx_ai_note_id ON ai_note_meta(note_id)');
	$db->exec('CREATE INDEX IF NOT EXISTS idx_ai_topic ON ai_note_meta(topic)');
	$db->exec('CREATE INDEX IF NOT EXISTS idx_ai_notes_type ON ai_note_meta(notes_type)');
	$db->exec('CREATE INDEX IF NOT EXISTS idx_ai_updated ON ai_note_meta(updated_at)');

	return $db;
}

function notesGetAppSetting(SQLite3 $db, string $key): ?string
{
	$stmt = $db->prepare('SELECT v FROM app_settings WHERE k = :k LIMIT 1');
	if (!$stmt) {
		return null;
	}
	$stmt->bindValue(':k', $key, SQLITE3_TEXT);
	$res = $stmt->execute();
	$row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
	if (!$row || !isset($row['v'])) {
		return null;
	}
	return (string)$row['v'];
}

function notesSetAppSetting(SQLite3 $db, string $key, string $value): void
{
	$stmt = $db->prepare('INSERT OR REPLACE INTO app_settings(k, v, updated_at) VALUES (:k, :v, CURRENT_TIMESTAMP)');
	if (!$stmt) {
		throw new RuntimeException('Failed to save app setting (prepare failed)');
	}
	$stmt->bindValue(':k', $key, SQLITE3_TEXT);
	$stmt->bindValue(':v', $value, SQLITE3_TEXT);
	$res = $stmt->execute();
	if ($res === false) {
		throw new RuntimeException('Failed to save app setting: ' . $db->lastErrorMsg());
	}
}

function nextHumanApiInboxVersion(SQLite3 $db, string $dateYmd): string
{
	$max = 0;
	$stmt = $db->prepare("SELECT version FROM notes WHERE topic = 'humanAPI-inbox' AND version LIKE :p");
	if ($stmt) {
		$stmt->bindValue(':p', $dateYmd . '.%', SQLITE3_TEXT);
		$res = $stmt->execute();
		while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
			$v = isset($row['version']) ? (string)$row['version'] : '';
			if ($v === '') continue;
			$parts = explode('.', $v);
			$suffix = (int)($parts[count($parts) - 1] ?? 0);
			if ($suffix > $max) $max = $suffix;
		}
	}
	return $dateYmd . '.' . (string)($max + 1);
}

function return_bytes(string $size_str): int
{
	$size_str = trim($size_str);
	$unit = strtolower($size_str[strlen($size_str) - 1] ?? '');
	$value = (int)$size_str;
	$result = $value;
	switch ($unit) {
		case 'g':
			$result = $value * 1024 * 1024 * 1024;
			break;
		case 'm':
			$result = $value * 1024 * 1024;
			break;
		case 'k':
			$result = $value * 1024;
			break;
		default:
			$result = $value;
			break;
	}
	return $result;
}

function renderMarkdown(string $text): string
{
	// Very small, safe Markdown-ish renderer: escapes HTML, then applies a handful of patterns.
	$escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

	$escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
	$escaped = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $escaped);
	$escaped = preg_replace('/`(.+?)`/s', '<code>$1</code>', $escaped);
	$escaped = preg_replace('/\n{2,}/', "</p><p>", nl2br($escaped));

	return '<p>' . $escaped . '</p>';
}

function fetchNoteById(SQLite3 $db, int $id): ?array
{
	$stmt = $db->prepare('SELECT id, notes_type, note, parent_id, topic, node, path, version, ts, created_at, updated_at FROM notes WHERE id = :id');
	$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
	$result = $stmt->execute();
	return $result->fetchArray(SQLITE3_ASSOC) ?: null;
}

function fetchNotes(SQLite3 $db, ?string $query): array
{
	$params = [];
	$sql = 'SELECT id, notes_type, note, parent_id, topic, node, path, version, ts, created_at, updated_at FROM notes';

	if ($query !== null && trim($query) !== '') {
		$sql .= ' WHERE note LIKE :q OR notes_type LIKE :q OR topic LIKE :q OR node LIKE :q OR path LIKE :q OR version LIKE :q OR ts LIKE :q';
		$params[':q'] = '%' . trim($query) . '%';
	}

	$sql .= ' ORDER BY created_at DESC';

	$stmt = $db->prepare($sql);
	foreach ($params as $k => $v) {
		$stmt->bindValue($k, $v, SQLITE3_TEXT);
	}

	$result = $stmt->execute();
	$rows = [];
	while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
		$rows[] = $row;
	}

	return $rows;
}

function fetchAiNotes(SQLite3 $db, ?string $query): array
{
	$params = [];
	$sql = 'SELECT id, note_id, parent_id, notes_type, topic, source_hash, model_name, meta_json, summary, tags_csv, created_at, updated_at FROM ai_note_meta';

	if ($query !== null && trim($query) !== '') {
		$sql .= ' WHERE summary LIKE :q OR tags_csv LIKE :q OR notes_type LIKE :q OR topic LIKE :q';
		$params[':q'] = '%' . trim($query) . '%';
	}

	$sql .= ' ORDER BY created_at DESC';

	$stmt = $db->prepare($sql);
	if ($stmt === false) {
		throw new RuntimeException('AI DB query prepare failed: ' . $db->lastErrorMsg());
	}
	foreach ($params as $k => $v) {
		$stmt->bindValue($k, $v, SQLITE3_TEXT);
	}

	$result = $stmt->execute();
	if ($result === false) {
		throw new RuntimeException('AI DB query execute failed: ' . $db->lastErrorMsg());
	}
	$rows = [];
	while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
		$rows[] = $row;
	}

	return $rows;
}

function buildAiTree(array $rows): array
{
	// ai_note_meta rows refer back to the human notes table.
	// parent_id is the parent note_id (not the ai_note_meta.id), so we key by note_id.
	$byNoteId = [];
	foreach ($rows as $row) {
		$noteId = (int)($row['note_id'] ?? 0);
		if ($noteId <= 0) {
			continue;
		}
		// Keep the newest meta row per note_id (fetchAiNotes orders by created_at DESC).
		if (isset($byNoteId[$noteId])) {
			continue;
		}
		$row['children'] = [];
		$byNoteId[$noteId] = $row;
	}

	$roots = [];
	foreach ($byNoteId as $noteId => &$row) {
		$parentNoteId = (int)($row['parent_id'] ?? 0);
		if ($parentNoteId > 0 && isset($byNoteId[$parentNoteId])) {
			$byNoteId[$parentNoteId]['children'][] = &$row;
		} else {
			$roots[] = &$row;
		}
	}
	unset($row);

	usort($roots, function ($a, $b) {
		return strcmp($b['created_at'], $a['created_at']);
	});
	return array_slice($roots, 0, 30);
}

function buildTree(array $rows): array
{
	$byId = [];
	foreach ($rows as $row) {
		$row['children'] = [];
		$byId[$row['id']] = $row;
	}

	$roots = [];
	foreach ($byId as $id => &$row) {
		$parentId = ($row['parent_id'] ?? 0) ?: null; // treat 0/null as root
		if ($parentId !== null && isset($byId[$parentId])) {
			$byId[$parentId]['children'][] = &$row;
		} else {
			$roots[] = &$row;
		}
	}
	unset($row);

	usort($roots, function ($a, $b) {
		return strcmp($b['created_at'], $a['created_at']);
	});
	return array_slice($roots, 0, 30);
}

function fetchFiles(SQLite3 $db, int $noteId): array
{
	$stmt = $db->prepare('SELECT id, original_name, file_path, file_size, mime_type, created_at FROM files WHERE note_id = :note_id');
	$stmt->bindValue(':note_id', $noteId, SQLITE3_INTEGER);
	$result = $stmt->execute();
	$files = [];
	while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
		$files[] = $row;
	}
	return $files;
}

function handleFileUpload(SQLite3 $db, int $noteId): void
{
	global $errors;
	global $success;

	if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
		return;
	}

	if (!is_dir(UPLOAD_DIR)) {
		if (!@mkdir(UPLOAD_DIR, 0775, true)) {
			$errors[] = 'Failed to create upload directory: ' . UPLOAD_DIR;
			return;
		}
	}

	if (!is_writable(UPLOAD_DIR)) {
		$errors[] = 'Upload directory not writable: ' . UPLOAD_DIR;
		return;
	}

	$fileCount = count($_FILES['files']['name']);
	for ($i = 0; $i < $fileCount; $i++) {
		if (empty($_FILES['files']['name'][$i])) {
			continue;
		}

		$uploadErr = (int)($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
		if ($uploadErr !== UPLOAD_ERR_OK) {
			$errors[] = "File '{$_FILES['files']['name'][$i]}': upload error ($uploadErr)";
			continue;
		}

		$fileName = (string)($_FILES['files']['name'][$i] ?? '');
		$fileSize = (int)($_FILES['files']['size'][$i] ?? 0);
		$fileTmp = (string)($_FILES['files']['tmp_name'][$i] ?? '');
		$mimeType = (string)($_FILES['files']['type'][$i] ?? '');

		if ($fileName === '' || $fileTmp === '') {
			continue;
		}

		if (defined('MAX_UPLOAD_SIZE') && $fileSize > (int)MAX_UPLOAD_SIZE) {
			$errors[] = "File '$fileName' too large";
			continue;
		}

		$ext = pathinfo($fileName, PATHINFO_EXTENSION);
		$baseName = pathinfo($fileName, PATHINFO_FILENAME);
		$safeBase = preg_replace('/[^a-z0-9_-]/i', '_', (string)$baseName);
		$rand = bin2hex(random_bytes(4));
		$safeFileName = $safeBase . '_' . $rand . ($ext !== '' ? ('.' . $ext) : '');
		$filePath = UPLOAD_DIR . $safeFileName;

		if (@move_uploaded_file($fileTmp, $filePath)) {
			$stmt = $db->prepare('INSERT INTO files (note_id, original_name, file_path, file_size, mime_type) VALUES (:note_id, :name, :path, :size, :mime)');
			if ($stmt) {
				$stmt->bindValue(':note_id', $noteId, SQLITE3_INTEGER);
				$stmt->bindValue(':name', $fileName, SQLITE3_TEXT);
				$stmt->bindValue(':path', $filePath, SQLITE3_TEXT);
				$stmt->bindValue(':size', $fileSize, SQLITE3_INTEGER);
				$stmt->bindValue(':mime', $mimeType, SQLITE3_TEXT);
				$stmt->execute();
				$success[] = "Uploaded file '$fileName'";
			}
		} else {
			$errors[] = "Failed to move '$fileName' to upload directory";
		}
	}
}

function handleDelete(SQLite3 $db): void
{
	global $errors;
	global $success;

	$id = (int)($_POST['delete_id'] ?? 0);
	if ($id <= 0) {
		$errors[] = 'Invalid note ID for deletion';
		return;
	}

	$note = fetchNoteById($db, $id);
	if (!$note) {
		$errors[] = 'Note not found';
		return;
	}

	$stmt = $db->prepare('DELETE FROM notes WHERE id = :id');
	if (!$stmt) {
		$errors[] = 'Failed to delete note: ' . $db->lastErrorMsg();
		return;
	}
	$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
	if ($stmt->execute()) {
		$success[] = "Deleted note #$id";
	} else {
		$errors[] = 'Failed to delete note: ' . $db->lastErrorMsg();
	}
}

function handleCreate(SQLite3 $db): void
{
	global $errors;
	global $success;

	$note = trim((string)($_POST['note'] ?? ''));
	$type = (string)($_POST['notes_type'] ?? 'human');
	$parent = (string)($_POST['parent_id'] ?? '');
	$topic = trim((string)($_POST['topic'] ?? ''));

	$node = trim((string)($_POST['node'] ?? ''));
	$path = trim((string)($_POST['path'] ?? ''));
	$version = trim((string)($_POST['version'] ?? ''));
	$ts = trim((string)($_POST['ts'] ?? ''));

	if ($topic !== '' && strpos($topic, 'humanAPI-inbox') === 0) {
		if (preg_match('/^humanAPI-inbox\s*(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}:\d{2}))?/i', $topic, $m)) {
			$topic = 'humanAPI-inbox';
			if ($ts === '') {
				$date = $m[1];
				$time = isset($m[2]) && $m[2] !== '' ? $m[2] : '00:00:00';
				$ts = $date . 'T' . $time . 'Z';
			}
		}
	}

	if ($topic === 'humanAPI-inbox') {
		if ($ts === '') $ts = gmdate('Y-m-d\TH:i:s\Z');
		if ($path === '') $path = '/web/html/v1/inbox.php';
		if ($version === '') {
			$dateYmd = substr($ts, 0, 10);
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
				$version = nextHumanApiInboxVersion($db, $dateYmd);
			}
		}
	}

	if ($note === '' || !defined('NOTES_TYPES') || !in_array($type, NOTES_TYPES, true)) {
		$errors[] = 'Note content required and type must be valid';
		return;
	}

	$stmt = $db->prepare('INSERT INTO notes (notes_type, note, parent_id, topic, node, path, version, ts) VALUES (:type, :note, :parent, :topic, :node, :path, :version, :ts)');
	if (!$stmt) {
		$errors[] = 'Database prepare error: ' . $db->lastErrorMsg();
		return;
	}

	$stmt->bindValue(':type', $type, SQLITE3_TEXT);
	$stmt->bindValue(':note', $note, SQLITE3_TEXT);
	$stmt->bindValue(':parent', $parent === '' ? 0 : (int)$parent, SQLITE3_INTEGER);
	$stmt->bindValue(':topic', $topic === '' ? null : $topic, SQLITE3_TEXT);
	$stmt->bindValue(':node', $node === '' ? null : $node, SQLITE3_TEXT);
	$stmt->bindValue(':path', $path === '' ? null : $path, SQLITE3_TEXT);
	$stmt->bindValue(':version', $version === '' ? null : $version, SQLITE3_TEXT);
	$stmt->bindValue(':ts', $ts === '' ? null : $ts, SQLITE3_TEXT);

	if (!$stmt->execute()) {
		$errors[] = 'Database insert error: ' . $db->lastErrorMsg();
		return;
	}

	$noteId = (int)$db->lastInsertRowID();
	if ($noteId > 0) {
		$success[] = "Created note ID $noteId";
		handleFileUpload($db, $noteId);
	} else {
		$errors[] = 'Failed to get note ID after insert';
	}
}

// NOTE: Rendering + handlers remain in index.php; this file mirrors the shared helpers only.
