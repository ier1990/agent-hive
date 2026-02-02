<?php

declare(strict_types=0);

function notesWantsJsonResponse(): bool
{
	$format = (string)($_GET['format'] ?? '');
	if ($format === 'json') {
		return true;
	}
	$accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
	$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
	if (strpos($accept, 'application/json') !== false) {
		return true;
	}
	if (strpos($contentType, 'application/json') !== false) {
		return true;
	}
	return false;
}

function notesSendJson(int $statusCode, array $payload): void
{
	http_response_code($statusCode);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function notesReadJsonBody(array &$errors): ?array
{
	$raw = file_get_contents('php://input');
	if (!is_string($raw) || trim($raw) === '') {
		$errors[] = 'Empty JSON body';
		return null;
	}
	$data = json_decode($raw, true);
	if (!is_array($data)) {
		$errors[] = 'Invalid JSON body';
		return null;
	}
	return $data;
}

function notesFetchHumanNotesJson(SQLite3 $db, ?string $query, int $limit): array
{
	$limit = max(1, min(500, $limit));
	$params = [];
	$sql = 'SELECT id, notes_type, note, parent_id, topic, node, path, version, ts, created_at, updated_at FROM notes';
	if ($query !== null && trim($query) !== '') {
		$sql .= ' WHERE note LIKE :q OR notes_type LIKE :q OR topic LIKE :q OR node LIKE :q OR path LIKE :q OR version LIKE :q OR ts LIKE :q';
		$params[':q'] = '%' . trim($query) . '%';
	}
	$sql .= ' ORDER BY created_at DESC LIMIT :lim';
	$stmt = $db->prepare($sql);
	if (!$stmt) {
		return [];
	}
	foreach ($params as $k => $v) {
		$stmt->bindValue($k, $v, SQLITE3_TEXT);
	}
	$stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
	$res = $stmt->execute();
	$rows = [];
	while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
		$rows[] = $row;
	}
	return $rows;
}

function notesHandleGetJson(?SQLite3 $db, array &$errors, array &$success): bool
{
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
		return false;
	}
	if (!notesWantsJsonResponse()) {
		return false;
	}

	$view = (string)($_GET['view'] ?? 'human');
	$q = $_GET['q'] ?? null;
	$limit = (int)($_GET['limit'] ?? 250);

	if ($view !== 'human') {
		notesSendJson(400, [
			'ok' => false,
			'errors' => ['Only view=human is supported for GET JSON right now'],
		]);
		exit;
	}

	if ($db === null) {
		notesSendJson(500, [
			'ok' => false,
			'errors' => ['Notes DB is not available'],
		]);
		exit;
	}

	$rows = notesFetchHumanNotesJson($db, is_string($q) ? $q : null, $limit);
	notesSendJson(200, [
		'ok' => true,
		'view' => $view,
		'q' => is_string($q) ? $q : null,
		'limit' => max(1, min(500, $limit)),
		'count' => count($rows),
		'notes' => $rows,
	]);
	exit;
}

/**
 * Handles POST actions.
 * - For HTML form posts: keeps the existing redirect behavior.
 * - For JSON posts (Accept/Content-Type/format=json): returns JSON and does not redirect.
 *
 * Returns true if it handled the request (and likely exited), false otherwise.
 */
function notesHandlePost(?SQLite3 $db, array &$errors, array &$success): bool
{
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
		return false;
	}

	// Some handler functions are legacy and use globals; bind them to the live arrays.
	$GLOBALS['errors'] =& $errors;
	$GLOBALS['success'] =& $success;

	$wantsJson = notesWantsJsonResponse();
	if ($wantsJson) {
		$data = notesReadJsonBody($errors);
		if ($data === null) {
			notesSendJson(400, ['ok' => false, 'errors' => $errors]);
			exit;
		}

		$action = (string)($data['action'] ?? 'create');

		$errStart = count($errors);
		$succStart = count($success);
		$ok = true;
		$noteId = null;

		try {
			if ($action === 'create') {
				if ($db === null) {
					throw new Exception('Notes DB is not available');
				}
				// Reuse existing form handler logic by populating $_POST.
				$_POST = array_merge(['action' => 'create'], $data);
				$_FILES = [];
				handleCreate($db);
				$noteId = (int)$db->lastInsertRowID();
			} elseif ($action === 'delete') {
				if ($db === null) {
					throw new Exception('Notes DB is not available');
				}
				$_POST = array_merge(['action' => 'delete'], $data);
				handleDelete($db);
			} else {
				throw new Exception('Unknown action: ' . $action);
			}
		} catch (Throwable $e) {
			$errors[] = $e->getMessage();
			$ok = false;
		}

		$newErrors = array_slice($errors, $errStart);
		$newSuccess = array_slice($success, $succStart);
		if (!empty($newErrors)) {
			$ok = false;
		}

		notesSendJson($ok ? 200 : 400, [
			'ok' => $ok,
			'action' => $action,
			'note_id' => $noteId,
			'errors' => $newErrors,
			'success' => $newSuccess,
		]);
		exit;
	}

	// HTML form POST behavior (existing): redirect after processing.
	$action = $_POST['action'] ?? 'create';

	// Debug: Check if POST data exists
	if (empty($_POST)) {
		$contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;
		$postMaxSize = ini_get('post_max_size');
		$postMaxBytes = return_bytes($postMaxSize);
		$filesInfo = isset($_FILES['files']) ? 'YES - ' . count($_FILES['files']['name']) . ' files detected' : 'NO';
		$errors[] = "POST data is empty! Content-Length: $contentLength bytes (" . number_format($contentLength/1024/1024, 2) . "MB), post_max_size: $postMaxSize (" . number_format($postMaxBytes/1024/1024, 2) . "MB). FILES array present: $filesInfo";
		if ($contentLength > $postMaxBytes) {
			$errors[] = "SOLUTION: Increase post_max_size in php.ini to at least " . ceil($contentLength/1024/1024) . "M";
		}
		$_SESSION['errors'] = $errors;
		return true;
	}

	try {
		if ($action === 'delete') {
			handleDelete($db);
		} elseif ($action === 'create') {
			handleCreate($db);
		} elseif ($action === 'ai_setup_save') {
			$ollamaUrl = trim((string)($_POST['ollama_url'] ?? ''));
			$model = trim((string)($_POST['ollama_model'] ?? ''));
			$searchApiBase = trim((string)($_POST['search_api_base'] ?? ''));
			if ($ollamaUrl === '') {
				throw new Exception('Ollama URL is required');
			}
			if (!preg_match('#^https?://#i', $ollamaUrl)) {
				throw new Exception('Ollama URL must start with http:// or https://');
			}
			if (strlen($ollamaUrl) > 220) {
				throw new Exception('Ollama URL is too long');
			}
			if ($model === '') {
				$model = 'gpt-oss:latest';
			}
			if (strlen($model) > 120) {
				throw new Exception('Model name is too long');
			}
			if ($searchApiBase === '') {
				$searchApiBase = 'http://192.168.0.142/admin/search?q=';
			}
			if (!preg_match('#^https?://#i', $searchApiBase)) {
				throw new Exception('Search API Base must start with http:// or https://');
			}
			if (strlen($searchApiBase) > 260) {
				throw new Exception('Search API Base is too long');
			}
			if (strpos($searchApiBase, '/admin/search') === false) {
				throw new Exception('Search API Base must include /admin/search');
			}
			if (strpos($searchApiBase, '?q=') === false) {
				throw new Exception('Search API Base must include ?q=');
			}

			if ($db !== null && function_exists('notesSetAppSetting')) {
				notesSetAppSetting($db, AI_OLLAMA_URL_KEY, $ollamaUrl);
				notesSetAppSetting($db, AI_OLLAMA_MODEL_KEY, $model);
				notesSetAppSetting($db, SEARCH_API_BASE_KEY, $searchApiBase);
			}

			$cfgErrors = [];
			notesSaveDefaultConfig([
				AI_OLLAMA_URL_KEY => $ollamaUrl,
				AI_OLLAMA_MODEL_KEY => $model,
				SEARCH_API_BASE_KEY => $searchApiBase,
			], $cfgErrors);
			foreach ($cfgErrors as $ce) {
				$errors[] = $ce;
			}
			if (empty($cfgErrors)) {
				$success[] = 'Saved settings';
			}
		} elseif ($action === 'prompt_create' || $action === 'prompt_delete') {
			$pdb = ensurePromptsDb();
			if ($action === 'prompt_create') {
				$newId = promptsCreate($pdb, [
					'name' => $_POST['p_name'] ?? '',
					'kind' => $_POST['p_kind'] ?? 'prompt',
					'tags' => $_POST['p_tags'] ?? '',
					'model_hint' => $_POST['p_model'] ?? '',
					'version' => $_POST['p_version'] ?? '',
					'body' => $_POST['p_body'] ?? '',
				]);
				$success[] = "Created prompt #$newId";
			} else {
				$id = (int)($_POST['prompt_id'] ?? 0);
				if ($id > 0) {
					promptsDelete($pdb, $id);
					$success[] = "Deleted prompt #$id";
				}
			}
		} elseif ($action === 'search_cache_update' || $action === 'search_cache_delete') {
			if (!file_exists(SEARCH_CACHE_DB_PATH)) {
				throw new Exception('Search cache DB missing: ' . SEARCH_CACHE_DB_PATH);
			}
			$sdb = new SQLite3(SEARCH_CACHE_DB_PATH);
			$cacheId = (int)($_POST['cache_id'] ?? 0);
			if ($cacheId <= 0) {
				throw new Exception('Invalid cache_id');
			}
			if ($action === 'search_cache_delete') {
				deleteSearchCacheEntry($sdb, $cacheId);
				$success[] = "Deleted search cache entry #$cacheId";
			} else {
				$aiNotes = (string)($_POST['ai_notes'] ?? '');
				$topUrls = (string)($_POST['top_urls'] ?? '');
				updateSearchCacheEntry($sdb, $cacheId, $aiNotes, $topUrls);
				$success[] = "Updated search cache entry #$cacheId";
			}
		} elseif ($action === 'search_cache_bulk_delete') {
			if (!file_exists(SEARCH_CACHE_DB_PATH)) {
				throw new Exception('Search cache DB missing: ' . SEARCH_CACHE_DB_PATH);
			}
			if ((int)($_POST['confirm'] ?? 0) !== 1) {
				throw new Exception('Bulk delete requires confirm checkbox');
			}
			$bulkQ = (string)($_POST['bulk_q'] ?? '');
			$sdb = new SQLite3(SEARCH_CACHE_DB_PATH);
			$deleted = deleteSearchCacheEntriesBySearch($sdb, $bulkQ);
			$success[] = "Deleted $deleted search cache entr(y/ies) matching: $bulkQ";
		} else {
			$errors[] = "Unknown action: $action";
		}
	} catch (Throwable $e) {
		$errors[] = $e->getMessage();
	}

	$_SESSION['errors'] = $errors;
	$_SESSION['successes'] = $success;

	$redirect = strtok($_SERVER['REQUEST_URI'], '?');
	$q = $_GET['q'] ?? null;
	$view = $_GET['view'] ?? null;
	$qs = [];
	if ($view) { $qs[] = 'view=' . urlencode($view); }
	if ($q) { $qs[] = 'q=' . urlencode($q); }
	$redirect .= $qs ? ('?' . implode('&', $qs)) : '';
	header('Location: ' . $redirect);
	exit;
}
