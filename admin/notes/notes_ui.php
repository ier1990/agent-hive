<?php

declare(strict_types=0);

// UI helpers and renderers for the Notes app.
// Expects constants like DB_MEMORY_DIR/DB_PATH/etc to be defined by the caller.

function defaultNavConfig(): array {
	return [
		['type' => 'view', 'label' => 'Human Notes', 'view' => 'human'],
		['type' => 'view', 'label' => 'Bash History', 'view' => 'bash', 'requires' => 'bash_db'],
		['type' => 'view', 'label' => 'AI Metadata', 'view' => 'ai'],
		['type' => 'view', 'label' => 'Jobs', 'view' => 'jobs'],

		['type' => 'view', 'label' => 'AI Setup', 'view' => 'ai_setup'],

		['type' => 'view', 'label' => 'DBs', 'view' => 'dbs'],

		['type' => 'view', 'label' => 'Search Cache', 'view' => 'search_cache', 'requires' => 'search_cache_db'],

	];
}

function navRequirementMet(array $item): bool {
	$requires = (string)($item['requires'] ?? '');
	if ($requires === '') {
		return true;
	}
	if ($requires === 'bash_db') {
		return file_exists(BASH_DB_PATH);
	}
	if ($requires === 'search_cache_db') {
		return file_exists(SEARCH_CACHE_DB_PATH);
	}
	return true;
}

function normalizeNavConfig($decoded): array {
	if (!is_array($decoded)) {
		return [];
	}
	$out = [];
	foreach ($decoded as $raw) {
		if (!is_array($raw)) {
			continue;
		}
		$type = (string)($raw['type'] ?? '');
		$label = trim((string)($raw['label'] ?? ''));
		$view = (string)($raw['view'] ?? '');
		$href = (string)($raw['href'] ?? '');
		$requires = (string)($raw['requires'] ?? '');

		if ($label === '') {
			continue;
		}

		if ($type === 'view') {
			if ($view === '') {
				continue;
			}
			$out[] = ['type' => 'view', 'label' => $label, 'view' => $view, 'requires' => $requires];
			continue;
		}

		if ($type === 'link') {
			// Avoid accidental external URLs in UI config.
			if ($href === '' || preg_match('#^https?://#i', $href)) {
				continue;
			}
			$out[] = ['type' => 'link', 'label' => $label, 'href' => $href, 'requires' => $requires];
			continue;
		}
	}
	return $out;
}

function loadNavConfig(?SQLite3 $db, array &$errors): array {
	$default = defaultNavConfig();
	if ($db === null || !function_exists('notesGetAppSetting')) {
		return $default;
	}
	try {
		$json = notesGetAppSetting($db, NAV_SETTINGS_KEY);
		if ($json === null || trim($json) === '') {
			return $default;
		}
		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			$errors[] = 'Invalid UI nav JSON in DB setting ' . NAV_SETTINGS_KEY;
			return $default;
		}
		$normalized = normalizeNavConfig($decoded);
		return !empty($normalized) ? $normalized : $default;
	} catch (Throwable $e) {
		$errors[] = 'UI nav settings load failed: ' . $e->getMessage();
		return $default;
	}
}

function getAllowedViewsFromNav(array $navItems): array {
	$views = [];
	foreach ($navItems as $it) {
		if (($it['type'] ?? '') === 'view') {
			$v = (string)($it['view'] ?? '');
			if ($v !== '') {
				$views[$v] = true;
			}
		}
	}
	// Always allow "human" as a safe fallback.
	$views['human'] = true;
	// Always allow DB browser.
	$views['dbs'] = true;
	return array_keys($views);
}

function renderNotesView(string $view, array $ctx): string {
	$viewPartials = [
		'prompts' => 'prompts.php',
		'ai' => 'ai.php',
		'dbs' => 'dbs.php',
		'ai_setup' => 'ai_setup.php',
		'bash' => 'bash.php',
		'search_cache' => 'search_cache.php',
		'jobs' => 'jobs.php',
		'human' => 'human.php',
	];
	$partial = $viewPartials[$view] ?? $viewPartials['human'];
	$path = __DIR__ . '/views/' . $partial;
	if (!is_file($path)) {
		return '<div class="muted">Missing view template: ' . htmlspecialchars($partial, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
	}
	ob_start();
	extract($ctx, EXTR_SKIP);
	require $path;
	$out = ob_get_clean();
	return is_string($out) ? $out : '';
}

function _notesQuoteIdent(string $ident): string {
	return '"' . str_replace('"', '""', $ident) . '"';
}

function listMemoryDbFiles(array &$errors): array {
	$dir = DB_MEMORY_DIR;
	if (!is_dir($dir)) {
		$errors[] = 'DB directory missing: ' . $dir;
		return [];
	}
	$items = @scandir($dir);
	if (!is_array($items)) {
		$errors[] = 'Failed to list DB directory: ' . $dir;
		return [];
	}
	$out = [];
	foreach ($items as $name) {
		if (!is_string($name) || $name === '.' || $name === '..') {
			continue;
		}
		if (substr($name, -3) !== '.db') {
			continue;
		}
		$full = $dir . '/' . $name;
		if (is_file($full)) {
			$out[] = $name;
		}
	}
	sort($out);
	return $out;
}

function resolveMemoryDbPath(?string $dbName, array &$errors): ?string {
	if ($dbName === null) {
		return null;
	}
	$dbName = trim($dbName);
	if ($dbName === '') {
		return null;
	}
	// Basename-only allowlist; prevents path traversal.
	if (strpos($dbName, '/') !== false || strpos($dbName, '\\') !== false || strpos($dbName, '..') !== false) {
		$errors[] = 'Invalid db name';
		return null;
	}
	if (substr($dbName, -3) !== '.db') {
		$errors[] = 'Invalid db name (must end with .db)';
		return null;
	}
	$dirReal = realpath(DB_MEMORY_DIR);
	if ($dirReal === false) {
		$errors[] = 'DB directory missing: ' . DB_MEMORY_DIR;
		return null;
	}
	$full = DB_MEMORY_DIR . '/' . $dbName;
	$fullReal = realpath($full);
	if ($fullReal === false || !is_file($fullReal)) {
		$errors[] = 'DB not found: ' . htmlspecialchars($dbName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		return null;
	}
	$dirPrefix = rtrim($dirReal, '/') . '/';
	if (strpos($fullReal, $dirPrefix) !== 0) {
		$errors[] = 'Refusing DB outside memory dir';
		return null;
	}
	return $fullReal;
}

function openSqliteReadOnly(string $path): ?SQLite3 {
	try {
		return new SQLite3($path, SQLITE3_OPEN_READONLY);
	} catch (Throwable $e) {
		return null;
	}
}

function fetchSqliteTables(SQLite3 $db): array {
	$tables = [];
	$res = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
	while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
		$name = (string)($row['name'] ?? '');
		if ($name !== '') {
			$tables[] = $name;
		}
	}
	return $tables;
}

function fetchSqliteTableColumns(SQLite3 $db, string $table): array {
	$cols = [];
	$res = $db->query('PRAGMA table_info(' . _notesQuoteIdent($table) . ')');
	while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
		$n = (string)($row['name'] ?? '');
		$t = (string)($row['type'] ?? '');
		if ($n !== '') {
			$cols[] = ['name' => $n, 'type' => $t];
		}
	}
	return $cols;
}

function fetchSqliteRows(SQLite3 $db, string $table, ?string $search, int $limit = 100): array {
	$limit = max(1, min(500, $limit));
	$search = ($search !== null) ? trim($search) : '';

	$sql = 'SELECT * FROM ' . _notesQuoteIdent($table);
	$params = [];
	if ($search !== '') {
		$cols = fetchSqliteTableColumns($db, $table);
		$where = [];
		$colCount = 0;
		foreach ($cols as $c) {
			$cn = (string)($c['name'] ?? '');
			if ($cn === '') {
				continue;
			}
			$where[] = 'CAST(' . _notesQuoteIdent($cn) . ' AS TEXT) LIKE :q';
			$colCount++;
			if ($colCount >= 12) {
				break;
			}
		}
		if (!empty($where)) {
			$sql .= ' WHERE ' . implode(' OR ', $where);
			$params[':q'] = '%' . $search . '%';
		}
	}
	$sql .= ' LIMIT :limit';

	$stmt = $db->prepare($sql);
	foreach ($params as $k => $v) {
		$stmt->bindValue($k, $v, SQLITE3_TEXT);
	}
	$stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
	$res = $stmt->execute();
	$out = [];
	while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
		$out[] = $row;
	}
	return $out;
}

function renderDbBrowser(array &$errors, ?string $dbName, ?string $tableName, ?string $search): string {
	$dbs = listMemoryDbFiles($errors);
	$h = '';

	$h .= '<div class="card" style="margin-bottom:14px;">';
	$h .= '<h2 style="margin:0 0 10px 0;">DB Browser</h2>';
	$h .= '<div class="muted" style="margin-bottom:10px;">Directory: ' . htmlspecialchars(DB_MEMORY_DIR, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
	if (empty($dbs)) {
		$h .= '<div class="muted">No .db files found.</div>';
		$h .= '</div>';
		return $h;
	}
	$h .= '<div style="display:flex; gap:10px; flex-wrap:wrap;">';
	foreach ($dbs as $name) {
		$active = ($dbName !== null && $dbName === $name);
		$bg = $active ? 'var(--accent)' : 'var(--panel)';
		$h .= '<a href="?view=dbs&db=' . urlencode($name) . '" style="padding: 8px 12px; background: ' . $bg . '; color: var(--text); text-decoration: none; border-radius: var(--radius);">'
			. htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
			. '</a>';
	}
	$h .= '</div>';
	$h .= '</div>';

	$path = resolveMemoryDbPath($dbName, $errors);
	if ($path === null) {
		return $h;
	}
	$db = openSqliteReadOnly($path);
	if ($db === null) {
		$errors[] = 'Failed to open DB read-only: ' . htmlspecialchars((string)$dbName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		return $h;
	}

	$tables = fetchSqliteTables($db);
	$h .= '<div class="card" style="margin-bottom:14px;">';
	$h .= '<div class="muted" style="margin-bottom:10px;">Selected DB: <strong>' . htmlspecialchars((string)$dbName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></div>';
	if (empty($tables)) {
		$h .= '<div class="muted">No tables found.</div>';
		$h .= '</div>';
		return $h;
	}
	$h .= '<div style="display:flex; gap:10px; flex-wrap:wrap;">';
	foreach ($tables as $t) {
		$active = ($tableName !== null && $tableName === $t);
		$bg = $active ? 'var(--accent)' : 'var(--panel)';
		$h .= '<a href="?view=dbs&db=' . urlencode((string)$dbName) . '&table=' . urlencode($t) . '" style="padding: 8px 12px; background: ' . $bg . '; color: var(--text); text-decoration: none; border-radius: var(--radius);">'
			. htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
			. '</a>';
	}
	$h .= '</div>';
	$h .= '</div>';

	if ($tableName === null || $tableName === '') {
		return $h;
	}
	if (!in_array($tableName, $tables, true)) {
		$errors[] = 'Unknown table: ' . htmlspecialchars($tableName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		return $h;
	}

	$rows = [];
	try {
		$rows = fetchSqliteRows($db, $tableName, $search, 120);
	} catch (Throwable $e) {
		$errors[] = 'Query failed: ' . $e->getMessage();
		$rows = [];
	}

	$h .= '<div class="card">';
	$h .= '<div class="muted" style="margin-bottom:10px;">Table: <strong>' . htmlspecialchars($tableName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong> | showing ' . count($rows) . ' row(s)</div>';
	if (($search ?? '') !== '') {
		$h .= '<div class="muted" style="margin-bottom:10px;">Search: <strong>' . htmlspecialchars((string)$search, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></div>';
	}
	if (empty($rows)) {
		$h .= '<div class="muted">No rows found.</div>';
		$h .= '</div>';
		return $h;
	}
	foreach ($rows as $r) {
		$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0 0 10px 0;">'
			. htmlspecialchars(print_r($r, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
			. '</pre>';
	}
	$h .= '</div>';

	return $h;
}

function _aiSetupStatusRow(string $label, bool $ok, string $detail = ''): string {
	$badgeBg = $ok ? 'rgba(113, 255, 199, 0.12)' : 'rgba(255, 107, 107, 0.14)';
	$badgeColor = $ok ? '#72ffd8' : '#ff8787';
	$badgeText = $ok ? 'OK' : 'MISSING';
	$h = '<div style="display:flex; gap:10px; align-items:center; margin:6px 0;">';
	$h .= '<span style="padding:4px 8px; border-radius:999px; background:' . $badgeBg . '; color:' . $badgeColor . '; font-weight:700; font-size:0.85rem;">' . $badgeText . '</span>';
	$h .= '<div><strong>' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>';
	if ($detail !== '') {
		$h .= '<div class="muted">' . htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
	}
	$h .= '</div>';
	$h .= '</div>';
	return $h;
}

function renderAiSetup(array &$errors, ?SQLite3 $notesDb): string {
	$h = '';
	$h .= '<div class="card" style="margin-bottom:14px;">';
	$h .= '<h2 style="margin:0 0 10px 0;">AI Setup</h2>';
	$h .= '<div class="muted" style="margin-bottom:10px;">This page is the quick setup/health hub for the other parts (DBs, directories, scripts).</div>';

	$cfg = notesResolveConfig($notesDb, $errors);
	$ollamaUrl = (string)($cfg[AI_OLLAMA_URL_KEY] ?? 'http://192.168.0.142:11434');
	$model = (string)($cfg[AI_OLLAMA_MODEL_KEY] ?? 'gpt-oss:latest');
	$searchApiBase = (string)($cfg[SEARCH_API_BASE_KEY] ?? 'http://192.168.0.142/v1/search?q=');

	$h .= '<h3 style="margin:14px 0 8px 0;">AI Settings</h3>';
	$h .= '<div class="muted" style="margin-bottom:10px;">Set the Ollama URL/model and the Search API base here. Scripts and example commands below will use these values.</div>';
	$h .= '<form method="post" style="margin-bottom:14px;">';
	$h .= '<input type="hidden" name="action" value="ai_setup_save" />';
	$h .= '<label>Ollama URL</label>';
	$h .= '<input type="text" name="ollama_url" value="' . htmlspecialchars($ollamaUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" placeholder="http://192.168.0.142:11434" />';
	$h .= '<label>Model</label>';
	$h .= '<input type="text" name="ollama_model" value="' . htmlspecialchars($model, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" placeholder="gpt-oss:latest" />';
	$h .= '<label>Search API Base</label>';
	$h .= '<input type="text" name="search_api_base" value="' . htmlspecialchars($searchApiBase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" placeholder="http://192.168.0.142/v1/search?q=" />';
	$h .= '<button type="submit">Save Settings</button>';
	$h .= '</form>';

	$h .= '<h3 style="margin:14px 0 8px 0;">Filesystem</h3>';
	$h .= _aiSetupStatusRow('DB memory dir', is_dir(DB_MEMORY_DIR), DB_MEMORY_DIR);
	$h .= _aiSetupStatusRow('Uploads dir', is_dir(UPLOAD_DIR), UPLOAD_DIR);
	$h .= _aiSetupStatusRow('Uploads dir writable', is_dir(UPLOAD_DIR) && is_writable(UPLOAD_DIR), UPLOAD_DIR);

	$h .= '<h3 style="margin:14px 0 8px 0;">Databases</h3>';
	$h .= _aiSetupStatusRow('Human Notes DB path', file_exists(DB_PATH), DB_PATH);
	$h .= _aiSetupStatusRow('Human Notes DB opened', $notesDb !== null, DB_PATH);
	$h .= _aiSetupStatusRow('AI Metadata DB path', file_exists(AI_DB_PATH), AI_DB_PATH);
	$h .= _aiSetupStatusRow('Bash History DB path', file_exists(BASH_DB_PATH), BASH_DB_PATH);
	$h .= _aiSetupStatusRow('Search Cache DB path', file_exists(SEARCH_CACHE_DB_PATH), SEARCH_CACHE_DB_PATH);

	if (file_exists(BASH_DB_PATH)) {
		try {
			$check = new SQLite3(BASH_DB_PATH, SQLITE3_OPEN_READONLY);
			$hasCommands = false;
			$hasCommandAi = false;
			$hasCommandSearch = false;
			$res = $check->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('commands','command_ai')");
			while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
				$n = (string)($row['name'] ?? '');
				if ($n === 'commands') $hasCommands = true;
				if ($n === 'command_ai') $hasCommandAi = true;
			}
			$res = $check->query("SELECT name FROM sqlite_master WHERE type='table' AND name='command_search' LIMIT 1");
			if ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
				$hasCommandSearch = ((string)($row['name'] ?? '') === 'command_search');
			}
			$check->close();
			$h .= _aiSetupStatusRow('bash_history.db.commands table', $hasCommands, 'Unique commands (full_cmd/base_cmd/seen_count)');
			$h .= _aiSetupStatusRow('bash_history.db.command_ai table', $hasCommandAi, 'AI classification status/results per command');
			$h .= _aiSetupStatusRow('bash_history.db.command_search table', $hasCommandSearch, 'Search queue status per command');
		} catch (Throwable $e) {
			$errors[] = 'AI Setup: failed to inspect bash_history.db: ' . $e->getMessage();
		}
	}

	if (file_exists(DB_PATH)) {
		try {
			$check = new SQLite3(DB_PATH, SQLITE3_OPEN_READONLY);
			$hasHistoryState = false;
			$res = $check->query("SELECT name FROM sqlite_master WHERE type='table' AND name='history_state' LIMIT 1");
			if ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
				$hasHistoryState = ((string)($row['name'] ?? '') === 'history_state');
			}
			$check->close();
			$h .= _aiSetupStatusRow('human_notes.db.history_state table', $hasHistoryState, 'Ingest progress table (host/path/inode/last_line)');
		} catch (Throwable $e) {
			$errors[] = 'AI Setup: failed to inspect human_notes.db: ' . $e->getMessage();
		}
	}

	$h .= '<h3 style="margin:14px 0 8px 0;">Scripts</h3>';
	$h .= '<div class="muted" style="margin-bottom:8px;">Hourly bash history ingest:</div>';
	$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;">'
		. htmlspecialchars('/web/html/admin/notes/scripts/ingest_bash_history_to_kb.py', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
		. '</pre>';
	$h .= '<div class="muted" style="margin:10px 0 8px 0;">Example cron (run for each user you care about):</div>';
	$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;">'
		. htmlspecialchars("5 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/ingest_bash_history_to_kb.py samekhi >> /web/private/logs/ingest_bash_history_to_kb.log 2>&1\n7 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/ingest_bash_history_to_kb.py root  >> /web/private/logs/ingest_bash_history_to_kb.log 2>&1", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
		. '</pre>';

	$classifyScript = '/web/html/admin/notes/scripts/classify_bash_commands.py';
	$h .= '<div style="margin-top:14px;">';
	$h .= '<div class="muted" style="margin-bottom:8px;">Bash command classification (commands → command_ai via Ollama HTTP):</div>';
	$h .= _aiSetupStatusRow('classify_bash_commands.py present', is_file($classifyScript), $classifyScript);
	$h .= '<div class="muted" style="margin:6px 0 8px 0;"><a href="/admin/notes/scripts/classify_bash_commands.md" style="color: var(--accent); text-decoration: none;">README: classify_bash_commands.md</a></div>';
	$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;">'
		. htmlspecialchars($classifyScript, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
		. '</pre>';
	$h .= '<div class="muted" style="margin:10px 0 8px 0;">Example cron:</div>';
	$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;">'
		. htmlspecialchars("15 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/classify_bash_commands.py >> /web/private/logs/classify_bash_commands.log 2>&1", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
		. '</pre>';
	$h .= '<div class="muted" style="margin:10px 0 8px 0;">Common env vars:</div>';
	$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;">'
		. htmlspecialchars("OLLAMA_URL=\"" . $ollamaUrl . "\"\nBASH_AI_BATCH=20", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
		. '</pre>';
	$h .= '</div>';

	$queueScript = '/web/html/admin/notes/scripts/queue_bash_searches.py';
	$h .= '<div style="margin-top:14px;">';
	$h .= '<div class="muted" style="margin-bottom:8px;">Queue/search known commands (command_ai.search_query → /v1/search):</div>';
	$h .= _aiSetupStatusRow('queue_bash_searches.py present', is_file($queueScript), $queueScript);
	$h .= '<div class="muted" style="margin:6px 0 8px 0;"><a href="/admin/notes/scripts/queue_bash_searches.md" style="color: var(--accent); text-decoration: none;">README: queue_bash_searches.md</a></div>';
	$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;">'
		. htmlspecialchars($queueScript, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
		. '</pre>';
	$h .= '<div class="muted" style="margin:10px 0 8px 0;">Example cron:</div>';
	$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;">'
		. htmlspecialchars("25 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/queue_bash_searches.py >> /web/private/logs/queue_bash_searches.log 2>&1", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
		. '</pre>';
	$h .= '<div class="muted" style="margin:10px 0 8px 0;">Common env vars:</div>';
	$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;">'
		. htmlspecialchars("IER_SEARCH_API=\"" . $searchApiBase . "\"\nBASH_SEARCH_BATCH=5\nBASH_SEARCH_SLEEP=1", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
		. '</pre>';
	$h .= '</div>';

	$aiNotesScript = '/web/html/admin/notes/scripts/ai_notes.py';
	$h .= '<div style="margin-top:14px;">';
	$h .= '<div class="muted" style="margin-bottom:8px;">AI metadata pass (notes → ai_note_meta via Ollama HTTP):</div>';
	$h .= _aiSetupStatusRow('ai_notes.py present', is_file($aiNotesScript), $aiNotesScript);
	$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;">'
		. htmlspecialchars($aiNotesScript, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
		. '</pre>';
	$h .= '<div class="muted" style="margin:10px 0 8px 0;">Recommended run (writes to the Notes UI AI DB so the “AI Metadata” tab can read it):</div>';
	$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;">'
		. htmlspecialchars(
			"/usr/bin/python3 /web/html/admin/notes/scripts/ai_notes.py " .
			"--human-db /web/private/db/memory/human_notes.db " .
			"--ai-db /web/private/db/memory/notes_ai_metadata.db " .
			"--ollama-url " . $ollamaUrl . " " .
			"--model " . $model,
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		)
		. '</pre>';
	$h .= '<div class="muted" style="margin:10px 0 8px 0;">Dependency check:</div>';
	$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;">'
		. htmlspecialchars("python3 -c \"import requests; print('requests OK')\"", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
		. '</pre>';
	$h .= '</div>';

	$h .= '<h3 style="margin:14px 0 8px 0;">AI Communications</h3>';
	$ollamaOk = false;
	try {
		$pu = @parse_url($ollamaUrl);
		$host = is_array($pu) && isset($pu['host']) ? (string)$pu['host'] : '127.0.0.1';
		$port = is_array($pu) && isset($pu['port']) ? (int)$pu['port'] : 11434;
		$fp = @fsockopen($host, $port, $errno, $errstr, 0.25);
		if (is_resource($fp)) {
			$ollamaOk = true;
			@fclose($fp);
		}
	} catch (Throwable $e) {
		$ollamaOk = false;
	}
	$h .= _aiSetupStatusRow('Ollama reachable', $ollamaOk, $ollamaUrl . ' (TCP check)');
	if (!$ollamaOk) {
		$h .= '<div class="muted" style="margin:8px 0 0 0;">If this is missing, start Ollama and ensure it is reachable at the configured URL above.</div>';
	}

	$h .= '<div class="muted" style="margin:10px 0 8px 0;">Recommended directory setup:</div>';
	$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;">'
		. htmlspecialchars("sudo mkdir -p /web/private/db/memory /web/private/uploads/memory /web/private/logs\n" .
			"sudo chown -R www-data:www-data /web/private/db/memory /web/private/uploads/memory /web/private/logs\n" .
			"sudo chmod 775 /web/private/db/memory /web/private/uploads/memory /web/private/logs", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
		. '</pre>';

	$h .= '</div>';
	return $h;
}

function renderNavBar(array $navItems, string $activeView): string {
	$h = '<div style="margin-bottom: 12px;">';
	foreach ($navItems as $item) {
		if (!is_array($item) || !navRequirementMet($item)) {
			continue;
		}
		$type = (string)($item['type'] ?? '');
		$label = (string)($item['label'] ?? '');
		$styleBase = 'margin-right: 10px; padding: 8px 12px; background: var(--panel); color: var(--text); text-decoration: none; border-radius: var(--radius);';

		if ($type === 'view') {
			$view = (string)($item['view'] ?? 'human');
			$isActive = ($view === $activeView);
			$bg = $isActive ? 'var(--accent)' : 'var(--panel)';
			$h .= '<a href="?view=' . urlencode($view) . '" style="margin-right: 10px; padding: 8px 12px; background: ' . $bg . '; color: var(--text); text-decoration: none; border-radius: var(--radius);">'
				. htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
				. '</a>';
			continue;
		}
		if ($type === 'link') {
			$href = (string)($item['href'] ?? '');
			if ($href === '') {
				continue;
			}
			$h .= '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="' . $styleBase . '">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
			continue;
		}
	}
	$h .= '</div>';
	return $h;
}

function ensureWritableDir(string $dir, array &$errors, string $label): bool {
	$dir = rtrim($dir, '/');
	if ($dir === '') {
		$errors[] = "$label dir is empty";
		return false;
	}
	if (file_exists($dir) && !is_dir($dir)) {
		$errors[] = "$label path exists but is not a directory: $dir";
		return false;
	}
	if (!is_dir($dir)) {
		if (!@mkdir($dir, 0775, true)) {
			$errors[] = "$label directory is missing and could not be created: $dir";
			$errors[] = "Try: sudo mkdir -p $dir && sudo chown -R www-data:www-data $dir && sudo chmod 775 $dir";
			return false;
		}
	}
	if (!is_writable($dir)) {
		$errors[] = "$label directory is not writable by PHP: $dir";
		$errors[] = "Try: sudo chown -R www-data:www-data $dir && sudo chmod 775 $dir";
		return false;
	}
	return true;
}

// Render recent notes as a readable threaded “comments” view.
// The tree nodes come from buildTree(): each node is a note row with a `children` array.
function renderTreeAsCommentsThread(array $tree, int $depth = 0): string {
	$h = '';
	foreach ($tree as $node) {
		$id = (int)($node['id'] ?? 0);
		$createdAt = (string)($node['created_at'] ?? '');
		$noteType = (string)($node['notes_type'] ?? 'note');
		$topic = (string)($node['topic'] ?? '');
		$nodeName = (string)($node['node'] ?? '');
		$path = (string)($node['path'] ?? '');
		$version = (string)($node['version'] ?? '');
		$ts = (string)($node['ts'] ?? '');
		$noteBody = (string)($node['note'] ?? '');
		$noteShort = (strlen($noteBody) > 220) ? (substr($noteBody, 0, 220) . '...') : $noteBody;

		$indent = $depth > 0 ? ('margin-left:' . (18 * $depth) . 'px; padding-left:12px; border-left:1px solid var(--border);') : '';
		$h .= '<div class="note-item" id="note-' . $id . '" style="' . $indent . '">';
		$h .= '<div class="note-meta">';
		$h .= '<span class="note-type">' . htmlspecialchars($noteType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		if ($topic !== '') {
			$h .= '<span class="note-topic">' . htmlspecialchars($topic, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		}
		if ($nodeName !== '') {
			$h .= '<span class="note-topic">' . htmlspecialchars($nodeName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		}
		if ($version !== '') {
			$h .= '<span class="note-topic">v ' . htmlspecialchars($version, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		}
		$h .= '<span class="note-date">' . htmlspecialchars($createdAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		$h .= '<a class="note-link" href="#note-' . $id . '">#' . $id . '</a>';
		$h .= '</div>';

		if ($path !== '' || $ts !== '') {
			$h .= '<div class="muted" style="margin:0 0 8px 0;">';
			if ($path !== '') {
				$h .= 'path: ' . htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			}
			if ($ts !== '') {
				$h .= ($path !== '' ? ' | ' : '') . 'ts: ' . htmlspecialchars($ts, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			}
			$h .= '</div>';
		}

		$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;">'
			. htmlspecialchars($noteShort, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
			. '</pre>';

		if (strlen($noteBody) > 220) {
			$h .= '<details style="margin-top:10px;">';
			$h .= '<summary class="muted" style="cursor:pointer;">Show full note</summary>';
			$h .= '<div class="note-body" style="margin-top:10px;">' . renderMarkdown($noteBody) . '</div>';
			$h .= '</details>';
		}

		$h .= '<details style="margin-top:10px;">';
		$h .= '<summary class="muted" style="cursor:pointer;">Show DB row</summary>';
		$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#1a1a1a; margin:10px 0 0 0;">'
			. htmlspecialchars(print_r($node, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
			. '</pre>';
		$h .= '</details>';

		$h .= '</div>';

		if (!empty($node['children']) && is_array($node['children'])) {
			$h .= renderTreeAsCommentsThread($node['children'], $depth + 1);
		}
	}
	return $h;
}

function renderAiTree(array $tree, $dbUnused = null, int $depth = 0): string {
	if (empty($tree)) {
		return '<div class="muted">No AI metadata rows found. Run the metadata script from AI Setup.</div>';
	}
	$h = '';
	foreach ($tree as $node) {
		$id = (int)($node['id'] ?? 0);
		$noteId = (int)($node['note_id'] ?? 0);
		$createdAt = (string)($node['created_at'] ?? '');
		$updatedAt = (string)($node['updated_at'] ?? '');
		$noteType = (string)($node['notes_type'] ?? '');
		$topic = (string)($node['topic'] ?? '');
		$model = (string)($node['model_name'] ?? '');
		$summary = (string)($node['summary'] ?? '');
		$tagsCsv = (string)($node['tags_csv'] ?? '');
		$metaJson = (string)($node['meta_json'] ?? '');

		$indent = $depth > 0 ? ('margin-left:' . (18 * $depth) . 'px; padding-left:12px; border-left:1px solid var(--border);') : '';
		$h .= '<div class="note-item" id="ai-' . $id . '" style="' . $indent . '">';
		$h .= '<div class="note-meta">';
		$h .= '<span class="note-type">ai</span>';
		if ($noteType !== '') {
			$h .= '<span class="note-type">' . htmlspecialchars($noteType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		}
		if ($topic !== '') {
			$h .= '<span class="note-topic">' . htmlspecialchars($topic, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		}
		if ($model !== '') {
			$h .= '<span class="note-topic">' . htmlspecialchars($model, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		}
		if ($tagsCsv !== '') {
			$h .= '<span class="note-topic">' . htmlspecialchars($tagsCsv, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		}
		$h .= '<span class="note-date">' . htmlspecialchars($createdAt !== '' ? $createdAt : $updatedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		$h .= '<a class="note-link" href="#ai-' . $id . '">#A' . $id . '</a>';
		if ($noteId > 0) {
			$h .= '<a class="note-link" href="?view=human#note-' . $noteId . '">note#' . $noteId . '</a>';
		}
		$h .= '</div>';

		if ($summary !== '') {
			$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;">'
				. htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
				. '</pre>';
		} else {
			$h .= '<div class="muted">No summary available.</div>';
		}

		if ($metaJson !== '') {
			$h .= '<details style="margin-top:10px;">';
			$h .= '<summary class="muted" style="cursor:pointer;">Show meta_json</summary>';
			$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#1a1a1a; margin:10px 0 0 0;">'
				. htmlspecialchars($metaJson, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
				. '</pre>';
			$h .= '</details>';
		}

		$h .= '</div>';

		if (!empty($node['children']) && is_array($node['children'])) {
			$h .= renderAiTree($node['children'], $dbUnused, $depth + 1);
		}
	}
	return $h;
}

function fetchBashCommands(SQLite3 $db, ?string $search, int $limit = 250): array {
	$limit = max(1, min(1000, $limit));
	if ($search !== null && trim($search) !== '') {
		$q = '%' . trim($search) . '%';
		$stmt = $db->prepare(
			"SELECT c.id, c.base_cmd, c.full_cmd, c.first_seen, c.last_seen, c.seen_count, " .
			"a.status AS ai_status, a.known AS ai_known, a.summary AS ai_summary, a.search_query AS ai_search_query, a.updated_at AS ai_updated_at " .
			"FROM commands c " .
			"LEFT JOIN command_ai a ON a.cmd_id = c.id " .
			"WHERE c.full_cmd LIKE :q OR c.base_cmd LIKE :q " .
			"ORDER BY c.last_seen DESC, c.id DESC " .
			"LIMIT :limit"
		);
		$stmt->bindValue(':q', $q, SQLITE3_TEXT);
		$stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
	} else {
		$stmt = $db->prepare(
			"SELECT c.id, c.base_cmd, c.full_cmd, c.first_seen, c.last_seen, c.seen_count, " .
			"a.status AS ai_status, a.known AS ai_known, a.summary AS ai_summary, a.search_query AS ai_search_query, a.updated_at AS ai_updated_at " .
			"FROM commands c " .
			"LEFT JOIN command_ai a ON a.cmd_id = c.id " .
			"ORDER BY c.last_seen DESC, c.id DESC " .
			"LIMIT :limit"
		);
		$stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
	}

	$res = $stmt->execute();
	$out = [];
	while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
		$out[] = $row;
	}
	return $out;
}

function renderBashCommands(array $rows): string {
	if (empty($rows)) {
		return '<div class="muted">No commands found.</div>';
	}

	$h = '';
	$h .= '<div class="muted" style="margin-bottom:10px;">Showing ' . count($rows) . ' command(s)</div>';
	$h .= '<ul class="note-list">';
	foreach ($rows as $r) {
		$id = (int)($r['id'] ?? 0);
		$base = (string)($r['base_cmd'] ?? '');
		$full = (string)($r['full_cmd'] ?? '');
		$last = (string)($r['last_seen'] ?? '');
		$seen = (int)($r['seen_count'] ?? 0);
		$aiStatus = (string)($r['ai_status'] ?? '');
		$aiKnown = (string)($r['ai_known'] ?? '');
		$aiSummary = (string)($r['ai_summary'] ?? '');
		$aiSearchQuery = (string)($r['ai_search_query'] ?? '');

		$h .= '<li class="note-item" id="cmd-' . $id . '">';
		$h .= '<div class="note-meta">';
		$h .= '<span class="note-type">bash</span>';
		if ($base !== '') {
			$h .= '<span class="note-topic">' . htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		}
		$h .= '<span class="note-date">' . htmlspecialchars($last, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		$h .= '<span class="note-date">seen ' . (int)$seen . '</span>';
		$h .= '<a class="note-link" href="#cmd-' . $id . '">#C' . $id . '</a>';
		if ($aiStatus !== '') {
			$h .= '<span class="note-type">ai:' . htmlspecialchars($aiStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
			$h .= '<span class="note-type">known:' . ((int)$aiKnown ? '1' : '0') . '</span>';
		}
		$h .= '</div>';

		$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;">'
			. htmlspecialchars($full, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
			. '</pre>';

		if ($aiSummary !== '' || $aiSearchQuery !== '') {
			$h .= '<div class="muted" style="margin-top:10px;">';
			if ($aiSummary !== '') {
				$h .= '<div><strong>AI:</strong> ' . htmlspecialchars($aiSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
			}
			if ($aiSearchQuery !== '') {
				$h .= '<div><strong>Search query:</strong> ' . htmlspecialchars($aiSearchQuery, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
			}
			$h .= '</div>';
		}
		//display entire $r
		$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#1a1a1a; margin:10px 0 0 0;">'
			. htmlspecialchars(print_r($r, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
			. '</pre>';
		$h .= '</li>';
	}
	$h .= '</ul>';
	return $h;
}

function fetchSearchCacheEntries(SQLite3 $db, ?string $search, int $limit = 250): array {
	$limit = max(1, min(1000, $limit));
	$search = ($search !== null) ? trim($search) : null;

	if ($search !== null && $search !== '') {
		if (ctype_digit($search)) {
			$stmt = $db->prepare(
				"SELECT id, key_hash, q, body, top_urls, ai_notes, cached_at " .
				"FROM search_cache_history WHERE id = :id ORDER BY id DESC LIMIT :limit"
			);
			$stmt->bindValue(':id', (int)$search, SQLITE3_INTEGER);
			$stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
		} else {
			$q = '%' . $search . '%';
			$stmt = $db->prepare(
				"SELECT id, key_hash, q, body, top_urls, ai_notes, cached_at " .
				"FROM search_cache_history " .
				"WHERE q LIKE :q OR ai_notes LIKE :q OR top_urls LIKE :q OR body LIKE :q OR key_hash LIKE :q " .
				"ORDER BY cached_at DESC, id DESC LIMIT :limit"
			);
			$stmt->bindValue(':q', $q, SQLITE3_TEXT);
			$stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
		}
	} else {
		$stmt = $db->prepare(
			"SELECT id, key_hash, q, body, top_urls, ai_notes, cached_at " .
			"FROM search_cache_history ORDER BY cached_at DESC, id DESC LIMIT :limit"
		);
		$stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
	}

	$res = $stmt->execute();
	$out = [];
	while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
		$out[] = $row;
	}
	return $out;
}

function fetchSearchCacheEntryById(SQLite3 $db, int $id): ?array {
	$stmt = $db->prepare(
		"SELECT id, key_hash, q, body, top_urls, ai_notes, cached_at FROM search_cache_history WHERE id = :id LIMIT 1"
	);
	$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
	$res = $stmt->execute();
	$row = $res->fetchArray(SQLITE3_ASSOC);
	return $row ?: null;
}

function updateSearchCacheEntry(SQLite3 $db, int $id, string $ai_notes, string $top_urls): void {
	$stmt = $db->prepare(
		"UPDATE search_cache_history SET ai_notes = :ai_notes, top_urls = :top_urls WHERE id = :id"
	);
	$stmt->bindValue(':ai_notes', $ai_notes, SQLITE3_TEXT);
	$stmt->bindValue(':top_urls', $top_urls, SQLITE3_TEXT);
	$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
	$stmt->execute();
}

function deleteSearchCacheEntry(SQLite3 $db, int $id): void {
	$stmt = $db->prepare("DELETE FROM search_cache_history WHERE id = :id");
	$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
	$stmt->execute();
}

function deleteSearchCacheEntriesBySearch(SQLite3 $db, string $search): int {
	$search = trim($search);
	if ($search === '') {
		throw new Exception('Refusing bulk delete: empty search');
	}

	if (ctype_digit($search)) {
		$stmt = $db->prepare("DELETE FROM search_cache_history WHERE id = :id");
		$stmt->bindValue(':id', (int)$search, SQLITE3_INTEGER);
		$stmt->execute();
		return (int)$db->changes();
	}

	$q = '%' . $search . '%';
	$stmt = $db->prepare(
		"DELETE FROM search_cache_history " .
		"WHERE q LIKE :q OR ai_notes LIKE :q OR top_urls LIKE :q OR body LIKE :q OR key_hash LIKE :q"
	);
	$stmt->bindValue(':q', $q, SQLITE3_TEXT);
	$stmt->execute();
	return (int)$db->changes();
}

function renderSearchCacheEntries(array $rows, ?array $editRow, ?string $search): string {
	$h = '';

	if ($editRow) {
		$id = (int)($editRow['id'] ?? 0);
		$h .= '<div class="card" style="margin-bottom:14px;">';
		$h .= '<h2 style="margin:0 0 10px 0;">Edit Search Cache #' . $id . '</h2>';
		$h .= '<div class="muted" style="margin-bottom:10px;">cached_at: ' . htmlspecialchars((string)($editRow['cached_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
		$h .= '<div class="muted" style="margin-bottom:10px;">key_hash: ' . htmlspecialchars((string)($editRow['key_hash'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
		$h .= '<div class="muted" style="margin-bottom:10px;">q: ' . htmlspecialchars((string)($editRow['q'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';

		$h .= '<form method="post">';
		$h .= '<input type="hidden" name="action" value="search_cache_update" />';
		$h .= '<input type="hidden" name="cache_id" value="' . $id . '" />';
		$h .= '<label>AI Notes</label>';
		$h .= '<textarea name="ai_notes" style="min-height:140px;">' . htmlspecialchars((string)($editRow['ai_notes'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</textarea>';
		$h .= '<label>Top URLs (text)</label>';
		$h .= '<textarea name="top_urls" style="min-height:100px;">' . htmlspecialchars((string)($editRow['top_urls'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</textarea>';
		$h .= '<button type="submit">Save Search Cache</button>';
		$h .= '</form>';
		$h .= '<div class="muted" style="margin-top:10px;"><a href="?view=search_cache" style="color: var(--accent); text-decoration:none;">Back to list</a></div>';

		$h .= '<details style="margin-top:12px;">';
		$h .= '<summary class="muted" style="cursor:pointer;">Show cached body</summary>';
		$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:10px 0 0 0;">'
			. htmlspecialchars((string)($editRow['body'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
			. '</pre>';
		$h .= '</details>';
		$h .= '</div>';
	}

	$search = ($search !== null) ? trim($search) : '';
	if ($search !== '' && !$editRow) {
		$h .= '<div class="card" style="margin-bottom:14px;">';
		$h .= '<div class="muted" style="margin-bottom:10px;">Bulk actions for current search</div>';
		$h .= '<form method="post" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">';
		$h .= '<input type="hidden" name="action" value="search_cache_bulk_delete" />';
		$h .= '<input type="hidden" name="bulk_q" value="' . htmlspecialchars($search, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
		$h .= '<label style="display:flex; gap:8px; align-items:center; margin:0;">';
		$h .= '<input type="checkbox" name="confirm" value="1" />';
		$h .= '<span class="muted">Confirm delete all matches for: <strong>' . htmlspecialchars($search, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></span>';
		$h .= '</label>';
		$h .= '<button type="submit" class="delete-btn" onclick="return confirm(\'Delete ALL search_cache_history rows matching this search?\')">Delete matching entries</button>';
		$h .= '</form>';
		$h .= '</div>';
	}

	if (empty($rows)) {
		$h .= '<div class="muted">No search cache entries found.</div>';
		return $h;
	}

	$h .= '<div class="muted" style="margin-bottom:10px;">Showing ' . count($rows) . ' cache entr(y/ies)</div>';
	$h .= '<ul class="note-list">';
	foreach ($rows as $r) {
		$id = (int)($r['id'] ?? 0);
		$q = (string)($r['q'] ?? '');
		$cachedAt = (string)($r['cached_at'] ?? '');
		$keyHash = (string)($r['key_hash'] ?? '');
		$aiNotes = (string)($r['ai_notes'] ?? '');
		$aiNotesShort = (strlen($aiNotes) > 240) ? substr($aiNotes, 0, 240) . '...' : $aiNotes;

		$h .= '<li class="note-item" id="cache-' . $id . '">';
		$h .= '<div class="note-meta">';
		$h .= '<span class="note-type">cache</span>';
		$h .= '<span class="note-date">' . htmlspecialchars($cachedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		$h .= '<a class="note-link" href="#cache-' . $id . '">#S' . $id . '</a>';
		$h .= '<a href="?view=search_cache&edit_id=' . $id . '" style="margin-left:10px; color: var(--accent); text-decoration:none;">Edit</a>';
		$h .= '</div>';

		if ($q !== '') {
			$h .= '<div class="muted" style="margin:0 0 8px 0;">q: ' . htmlspecialchars($q, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
		}
		if ($keyHash !== '') {
			$h .= '<div class="muted" style="margin:0 0 8px 0;">key_hash: ' . htmlspecialchars($keyHash, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
		}

		if ($aiNotesShort !== '') {
			$h .= '<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;">'
				. htmlspecialchars($aiNotesShort, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
				. '</pre>';
		}

		$h .= '<form method="post" style="margin-top:10px;">';
		$h .= '<input type="hidden" name="action" value="search_cache_delete" />';
		$h .= '<input type="hidden" name="cache_id" value="' . $id . '" />';
		$h .= '<button type="submit" class="delete-btn" onclick="return confirm(\'Delete cache entry #' . $id . '?\')">Delete</button>';
		$h .= '</form>';

		$h .= '</li>';
	}
	$h .= '</ul>';
	return $h;
}
