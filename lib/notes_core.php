<?php

// Notes app helpers extracted from notes.php
// Intentionally no constants here; notes.php defines DB_PATH, AI_DB_PATH, UPLOAD_DIR, etc.

function ensureDb(): SQLite3
{
	$dir = dirname(DB_PATH);
	if (!is_dir($dir)) {
		mkdir($dir, 0775, true);
	}

	$db = new SQLite3(DB_PATH);
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

	return $db;
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

function buildAiTree(array $rows): array
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

	// Sort roots newest first and cap to last 30 threads
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

	// Sort roots newest first and cap to last 30 threads
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

function renderTree(array $tree, SQLite3 $db): string
{
	$html = '<ul class="note-list">';
	foreach ($tree as $node) {
		$html .= '<li class="note-item" id="note-' . (int)$node['id'] . '">';
		$html .= '<div class="note-meta">'
			. '<span class="note-type">' . htmlspecialchars($node['notes_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		if ($node['topic']) {
			$html .= '<span class="note-topic">' . htmlspecialchars($node['topic'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		}
		if (!empty($node['ts'])) {
			$html .= '<span class="note-date">' . htmlspecialchars((string)$node['ts'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		}
		$html .= '<span class="note-date">' . htmlspecialchars($node['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
			. '<a class="note-link" href="#note-' . (int)$node['id'] . '">#' . (int)$node['id'] . '</a>'
			. '<a href="?parent_id=' . (int)$node['id'] . '#form" class="reply-btn">Reply</a>'
			. '<form method="post" style="display: inline;"><input type="hidden" name="action" value="delete"><input type="hidden" name="delete_id" value="' . (int)$node['id'] . '"><button type="submit" class="delete-btn" onclick="return confirm(\'Delete this note?\')">Delete</button></form>'
			. '</div>';
		if (($node['topic'] ?? '') === 'humanAPI-inbox') {
			$parts = [];
			if (!empty($node['node'])) $parts[] = 'node: ' . htmlspecialchars((string)$node['node'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			if (!empty($node['path'])) $parts[] = 'path: ' . htmlspecialchars((string)$node['path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			if (!empty($node['version'])) $parts[] = 'version: ' . htmlspecialchars((string)$node['version'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			if ($parts) {
				$html .= '<div class="muted" style="margin: 0 0 6px 0;">' . implode(' | ', $parts) . '</div>';
			}
		}
		$html .= '<div class="note-body">' . renderMarkdown($node['note']) . '</div>';

		// Render attached files
		$files = fetchFiles($db, $node['id']);
		if (!empty($files)) {
			$html .= '<div class="note-files"><strong>Attachments:</strong> <ul>';
			foreach ($files as $file) {
				$fileSize = $file['file_size'] ? number_format($file['file_size'] / 1024, 1) . ' KB' : '?';
				$fileName = htmlspecialchars($file['original_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
				$html .= '<li>
				<a href="?filename=' . $fileName . '" target="_blank" rel="noopener">' . $fileName . '</a> (' . $fileSize . ')</li>';
			}
			$html .= '</ul></div>';
		}

		if (!empty($node['children'])) {
			$html .= renderTree($node['children'], $db);
		}
		$html .= '</li>';
	}
	$html .= '</ul>';
	return $html;
}

function renderAiTree(array $tree, SQLite3 $db): string
{
	$html = '<ul class="note-list">';
	foreach ($tree as $node) {
		$meta = json_decode($node['meta_json'], true) ?? [];
		$tags = explode(',', $node['tags_csv']);
		$tags = array_filter(array_map('trim', $tags));
		$html .= '<li class="note-item" id="ai-note-' . (int)$node['id'] . '">';
		$html .= '<div class="note-meta">'
			. '<span class="note-type">' . htmlspecialchars($node['notes_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		if ($node['topic']) {
			$html .= '<span class="note-topic">' . htmlspecialchars($node['topic'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		}
		$html .= '<span class="note-date">' . htmlspecialchars($node['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
			. '<a class="note-link" href="#ai-note-' . (int)$node['id'] . '">#AI' . (int)$node['id'] . '</a>'
			. '<a href="?view=human&parent_id=' . (int)$node['note_id'] . '#form" class="reply-btn">View Original</a>'
			. '</div>';
		$html .= '<div class="note-body">';
		if ($node['summary']) {
			$html .= '<p><strong>Summary:</strong> ' . htmlspecialchars($node['summary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
		}
		if (!empty($tags)) {
			$html .= '<p><strong>Tags:</strong> ' . implode(', ', array_map(function ($t) {
				return '<code>' . htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
			}, $tags)) . '</p>';
		}
		if (!empty($meta['entities'])) {
			$html .= '<p><strong>Entities:</strong> ' . implode(', ', array_map(function ($e) {
				return '<code>' . htmlspecialchars($e, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
			}, $meta['entities'])) . '</p>';
		}
		if (!empty($meta['commands'])) {
			$html .= '<p><strong>Commands:</strong> ' . implode(', ', array_map(function ($c) {
				return '<code>' . htmlspecialchars($c, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
			}, $meta['commands'])) . '</p>';
		}
		$html .= '<p><em>Model:</em> ' . htmlspecialchars($node['model_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' | <em>Doc Kind:</em> ' . htmlspecialchars($meta['doc_kind'] ?? 'unknown', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
		$html .= '</div>';

		if (!empty($node['children'])) {
			$html .= renderAiTree($node['children'], $db);
		}
		$html .= '</li>';
	}
	$html .= '</ul>';
	return $html;
}

function handleFileUpload(SQLite3 $db, int $noteId): void
{
	global $errors;
	global $success;

	if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
		$errors[] = 'No files uploaded.';
		return;
	} else {
		$success[] = count($_FILES['files']['name']) . ' file(s) to upload.';
	}

	if (!is_dir(UPLOAD_DIR)) {
		if (!mkdir(UPLOAD_DIR, 0775, true)) {
			$errors[] = 'Failed to create upload directory: ' . UPLOAD_DIR;
		}
	}

	// Check directory permissions
	if (!is_writable(UPLOAD_DIR)) {
		$errors[] = 'Upload directory not writable: ' . UPLOAD_DIR . ' (perms: ' . substr(sprintf('%o', fileperms(UPLOAD_DIR)), -4) . ')';
		return;
	}

	$fileCount = count($_FILES['files']['name']);
	for ($i = 0; $i < $fileCount; $i++) {
		// Skip empty file slots
		if (empty($_FILES['files']['name'][$i])) {
			continue;
		}

		$uploadErr = $_FILES['files']['error'][$i];
		if ($uploadErr !== UPLOAD_ERR_OK) {
			switch ($uploadErr) {
				case UPLOAD_ERR_INI_SIZE:
					$errMsg = 'exceeds php.ini upload_max_filesize';
					break;
				case UPLOAD_ERR_FORM_SIZE:
					$errMsg = 'exceeds form MAX_FILE_SIZE';
					break;
				case UPLOAD_ERR_PARTIAL:
					$errMsg = 'partial upload';
					break;
				case UPLOAD_ERR_NO_FILE:
					$errMsg = 'no file';
					break;
				case UPLOAD_ERR_NO_TMP_DIR:
					$errMsg = 'no temp dir';
					break;
				case UPLOAD_ERR_CANT_WRITE:
					$errMsg = 'cannot write';
					break;
				case UPLOAD_ERR_EXTENSION:
					$errMsg = 'blocked by extension';
					break;
				default:
					$errMsg = "unknown error ($uploadErr)";
					break;
			}
			$errors[] = "File '{$_FILES['files']['name'][$i]}': $errMsg";
			continue;
		}

		$fileName = $_FILES['files']['name'][$i];
		$fileSize = $_FILES['files']['size'][$i];
		$fileTmp = $_FILES['files']['tmp_name'][$i];
		$mimeType = $_FILES['files']['type'][$i];

		if ($fileSize > MAX_UPLOAD_SIZE) {
			$errors[] = "File '$fileName' too large (" . number_format($fileSize / 1024 / 1024, 1) . 'MB > 50MB)';
			continue;
		}

		// Generate safe filename
		$ext = pathinfo($fileName, PATHINFO_EXTENSION);
		$baseName = pathinfo($fileName, PATHINFO_FILENAME);
		$safeFileName = preg_replace('/[^a-z0-9_-]/i', '_', $baseName) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
		$filePath = UPLOAD_DIR . $safeFileName;

		if (move_uploaded_file($fileTmp, $filePath)) {
			$stmt = $db->prepare(
				'INSERT INTO files (note_id, original_name, file_path, file_size, mime_type) VALUES (:note_id, :name, :path, :size, :mime)'
			);
			$stmt->bindValue(':note_id', $noteId, SQLITE3_INTEGER);
			$stmt->bindValue(':name', $fileName, SQLITE3_TEXT);
			$stmt->bindValue(':path', $filePath, SQLITE3_TEXT);
			$stmt->bindValue(':size', $fileSize, SQLITE3_INTEGER);
			$stmt->bindValue(':mime', $mimeType, SQLITE3_TEXT);
			$stmt->execute();
			$success[] = "Uploaded file '$fileName' ({$fileSize} bytes)";
		} else {
			$errors[] = "Failed to move '$fileName' to upload directory";
		}
	}
}

function handleDelete(SQLite3 $db): void
{
	global $errors;

	$id = (int)($_POST['delete_id'] ?? 0);
	if ($id <= 0) {
		$errors[] = 'Invalid note ID for deletion';
		return;
	}

	// Check if note exists
	$note = fetchNoteById($db, $id);
	if (!$note) {
		$errors[] = 'Note not found';
		return;
	}

	// Delete the note (files will be deleted via CASCADE)
	$stmt = $db->prepare('DELETE FROM notes WHERE id = :id');
	$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
	if ($stmt->execute()) {
		$errors[] = "Note #$id deleted successfully";
	} else {
		$errors[] = 'Failed to delete note: ' . $db->lastErrorMsg();
	}
}

function handleCreate(SQLite3 $db): void
{
	global $errors;
	global $success;

	$note = trim($_POST['note'] ?? '');
	$type = $_POST['notes_type'] ?? 'human';
	$parent = $_POST['parent_id'] ?? '';
	$topic = trim($_POST['topic'] ?? '');

	// Optional tracing fields (used for topic=humanAPI-inbox)
	$node = trim($_POST['node'] ?? '');
	$path = trim($_POST['path'] ?? '');
	$version = trim($_POST['version'] ?? '');
	$ts = trim($_POST['ts'] ?? '');

	// Normalize legacy combined topic+timestamp (e.g., "humanAPI-inbox2025-12-26 05:01:07")
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

	if ($note === '' || !in_array($type, NOTES_TYPES, true)) {
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

	// Handle file uploads for the created note
	$noteId = $db->lastInsertRowID();
	if ($noteId) {
		$success[] = "Created note ID $noteId";
		handleFileUpload($db, $noteId);
	} else {
		$errors[] = 'Failed to get note ID after insert';
	}
}
