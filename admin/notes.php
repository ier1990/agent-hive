<?php
session_start();

//if not local lan, exit with error message  
if (strpos($_SERVER['REMOTE_ADDR'], '192.168.') !== 0 && strpos($_SERVER['REMOTE_ADDR'], '10.') !== 0 && strpos($_SERVER['REMOTE_ADDR'], '172.16.') !== 0) {
    echo "Access denied: not on local LAN.";
    exit;
}

//show all errors for debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once '/web/html/lib/bootstrap.php';
require_once '/web/html/lib/prompts_lib.php'; // adjust path to where you put it
require_once '/web/html/lib/notes_core.php';

$view = $_GET['view'] ?? 'human';


$debug = false; // Set to true to debug upload settings
// Override debug via query or cookie
$debug = isset($_GET['debug']) ? true : (isset($_COOKIE['debug']) ? true : $debug);


$errors = $_SESSION['errors'] ?? [];
$success = $_SESSION['successes'] ?? [];
// Clear session errors after reading
unset($_SESSION['errors'], $_SESSION['successes']);

// Check PHP upload configuration
if ($debug) { // Set to true to debug upload settings
	$errors[] = "upload_max_filesize: " . ini_get('upload_max_filesize');
	$errors[] = "post_max_size: " . ini_get('post_max_size');
	$errors[] = "max_file_uploads: " . ini_get('max_file_uploads');
}

// Simple single-file notes app (PHP 7.3+). Markdown-ish input, dark UI, SQLite persistence, and threaded replies.
const DB_PATH = '/web/private/db/memory/human_notes.db';
const AI_DB_PATH = '/web/private/db/memory/ai_notes.db';
const BASH_DB_PATH = '/web/private/db/memory/bash_history.db';
const SEARCH_CACHE_DB_PATH = '/web/private/db/memory/search_cache.db';
const UPLOAD_DIR = '/web/private/uploads/memory/';
// check and create upload dir if not exists

if (!is_dir(dirname(UPLOAD_DIR))) {
    echo "Upload directory parent does not exist: " . dirname(UPLOAD_DIR);    
    exit;
}
if (!is_dir(UPLOAD_DIR)) {
    echo "Directory does not exist: " . UPLOAD_DIR;
    if (!mkdir(UPLOAD_DIR, 0775, true)) {
        echo "Failed to create upload directory: " . UPLOAD_DIR;
        exit;
    } else {
        echo "Created upload directory: " . UPLOAD_DIR;
    }
}



const MAX_UPLOAD_SIZE = 52428800; // 50MB
const NOTES_TYPES = [
	'human',
    'passwords',
    'code_snippet',
	'system_generated',
	'ai_generated',
	'tags',
	'images',
	'links',
	'files',
	'reminders',
	'logs',
];

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

$db = ensureDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
		// Save errors to session so they survive redirect
		$_SESSION['errors'] = $errors;
	} else {
		try {
			if ($action === 'delete') {
				handleDelete($db);
			} elseif ($action === 'create') {
				handleCreate($db);
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
		// Save errors/successes to session before redirect
		$_SESSION['errors'] = $errors;
		$_SESSION['successes'] = $success;
		// Redirect to same page (preserve view/q in query string)
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
}

$search = $_GET['q'] ?? null;
$view = $_GET['view'] ?? 'human';

if ($view === 'ai') {
	$ai_db = new SQLite3(AI_DB_PATH);
	$notes = fetchAiNotes($ai_db, $search);
	$tree = buildAiTree($notes);
	$db_for_render = $ai_db;
	$bash_rows = null;
	$search_cache_rows = null;
	$search_cache_edit = null;
} else if ($view === 'prompts') {
    $pdb = ensurePromptsDb();
    $search = $_GET['q'] ?? null;
    $kind = $_GET['kind'] ?? 'all';
    $prompts = promptsFetch($pdb, $search, $kind, 250);
	$bash_rows = null;
	$search_cache_rows = null;
	$search_cache_edit = null;

	$db_for_render = null;
	$tree = null;
} else {
	if ($view === 'bash') {
		try {
			if (!file_exists(BASH_DB_PATH)) {
				throw new Exception('Bash history DB missing: ' . BASH_DB_PATH);
			}
			$bash_db = new SQLite3(BASH_DB_PATH);
			$bash_rows = fetchBashCommands($bash_db, $search, 250);
			$db_for_render = $bash_db;
			$tree = null;
		} catch (Throwable $e) {
			$errors[] = 'Bash history: ' . $e->getMessage();
			$bash_rows = [];
			$db_for_render = null;
			$tree = null;
		}
		$search_cache_rows = null;
		$search_cache_edit = null;
	} elseif ($view === 'search_cache') {
		try {
			if (!file_exists(SEARCH_CACHE_DB_PATH)) {
				throw new Exception('Search cache DB missing: ' . SEARCH_CACHE_DB_PATH);
			}
			$search_db = new SQLite3(SEARCH_CACHE_DB_PATH);
			$editId = (int)($_GET['edit_id'] ?? 0);
			$search_cache_edit = $editId > 0 ? fetchSearchCacheEntryById($search_db, $editId) : null;
			$search_cache_rows = fetchSearchCacheEntries($search_db, $search, 250);
			$db_for_render = $search_db;
			$tree = null;
			$bash_rows = null;
		} catch (Throwable $e) {
			$errors[] = 'Search cache: ' . $e->getMessage();
			$search_cache_rows = [];
			$search_cache_edit = null;
			$db_for_render = null;
			$tree = null;
			$bash_rows = null;
		}
	} else {
		$notes = fetchNotes($db, $search);
		$tree = buildTree($notes);
		$db_for_render = $db;
		$bash_rows = null;
		$search_cache_rows = null;
		$search_cache_edit = null;
	}
}

?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php
		if ($view === 'ai') { echo 'AI Notes'; }
		elseif ($view === 'bash') { echo 'Bash History'; }
		elseif ($view === 'search_cache') { echo 'Search Cache'; }
		else { echo 'Notes'; }
	?></title>
	<style>
		:root {
			--bg: #0b1018;
			--panel: #121a26;
			--muted: #7b8ba5;
			--text: #e8f0ff;
			--accent: #5c9dff;
			--border: #1f2a3b;
			--shadow: 0 18px 60px rgba(0, 0, 0, 0.35);
			--radius: 12px;
			--mono: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
		}
		* { box-sizing: border-box; }
		body {
			margin: 0;
			background: radial-gradient(circle at 20% 20%, rgba(92, 157, 255, 0.08), transparent 32%),
						radial-gradient(circle at 80% 0%, rgba(113, 255, 199, 0.06), transparent 28%),
						var(--bg);
			color: var(--text);
			font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
			min-height: 100vh;
			padding: 32px;
		}
		.layout { max-width: 1100px; margin: 0 auto; display: grid; gap: 18px; }
		.card {
			background: var(--panel);
			border: 1px solid var(--border);
			border-radius: var(--radius);
			box-shadow: var(--shadow);
			padding: 18px 20px;
		}
		h1 { margin: 0 0 12px 0; letter-spacing: -0.02em; }
		form { display: grid; gap: 12px; }
		label { font-size: 0.95rem; color: var(--muted); }
		textarea, select, input[type="text"] {
			width: 100%;
			background: #0e1520;
			border: 1px solid var(--border);
			color: var(--text);
			border-radius: var(--radius);
			padding: 12px;
			font-size: 1rem;
		}
		textarea { min-height: 140px; resize: vertical; }
		button {
			background: linear-gradient(120deg, #5c9dff, #72ffd8);
			border: none;
			color: #0b1018;
			font-weight: 700;
			border-radius: var(--radius);
			padding: 12px 14px;
			cursor: pointer;
			box-shadow: 0 10px 30px rgba(114, 255, 216, 0.25);
		}
		button:hover { filter: brightness(1.05); }
		.note-list { list-style: none; margin: 0; padding-left: 0; }
		.note-list .note-list { padding-left: 18px; border-left: 1px solid var(--border); margin-left: 6px; }
		.note-item { padding: 12px 10px; border-bottom: 1px solid var(--border); }
		.note-meta { display: flex; gap: 10px; align-items: center; font-size: 0.9rem; color: var(--muted); margin-bottom: 6px; }
		.note-type { padding: 4px 8px; border-radius: 999px; background: rgba(92, 157, 255, 0.14); color: #9fc5ff; text-transform: lowercase; font-weight: 600; }
		.note-topic { padding: 4px 8px; border-radius: 999px; background: rgba(113, 255, 199, 0.12); color: #72ffd8; text-transform: lowercase; font-weight: 600; }
		.note-body p { margin: 0 0 8px 0; line-height: 1.55; }
		.note-files { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border); font-size: 0.9rem; }
		.note-files ul { list-style: none; margin: 6px 0 0 0; padding-left: 16px; }
		.note-files li { margin: 4px 0; }
		.note-files a { color: var(--accent); text-decoration: none; }
		.note-files a:hover { text-decoration: underline; }
		.file-hint { font-size: 0.85rem; color: var(--muted); margin: 6px 0 0 0; }
		.parent-note { background: rgba(255, 255, 255, 0.05); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px; margin-bottom: 18px; }
		.parent-note h3 { margin: 0 0 8px 0; color: var(--accent); font-size: 1rem; }
		.note-body code { background: #0b1a2a; padding: 2px 6px; border-radius: 6px; font-family: var(--mono); }
		.note-body strong { color: #f0f4ff; }
		.note-body em { color: #b3c7ff; }
		.search { display: flex; gap: 10px; }
		.search input { flex: 1; }
		.muted { color: var(--muted); font-size: 0.9rem; }
		@media (max-width: 720px) {
			body { padding: 18px; }
			.layout { gap: 12px; }
		}
	</style>
</head>
<body>
	<div class="layout">
		<div class="card">
			<h1><?php
				if ($view === 'ai') { echo 'AI Notes'; }
				elseif ($view === 'bash') { echo 'Bash History'; }
				elseif ($view === 'search_cache') { echo 'Search Cache'; }
				else { echo 'Notes'; }
			?></h1>
			<div style="margin-bottom: 12px;">
				<a href="?view=human" style="margin-right: 10px; padding: 8px 12px; background: <?php echo $view === 'human' ? 'var(--accent)' : 'var(--panel)'; ?>; color: var(--text); text-decoration: none; border-radius: var(--radius);">Human Notes</a>
				<a href="?view=ai" style="padding: 8px 12px; background: <?php echo $view === 'ai' ? 'var(--accent)' : 'var(--panel)'; ?>; color: var(--text); text-decoration: none; border-radius: var(--radius);">AI Metadata</a>
				<a href="?view=bash" style="margin-left: 10px; padding: 8px 12px; background: <?php echo $view === 'bash' ? 'var(--accent)' : 'var(--panel)'; ?>; color: var(--text); text-decoration: none; border-radius: var(--radius);">Bash History</a>
				<a href="?view=search_cache" style="margin-left: 10px; padding: 8px 12px; background: <?php echo $view === 'search_cache' ? 'var(--accent)' : 'var(--panel)'; ?>; color: var(--text); text-decoration: none; border-radius: var(--radius);">Search Cache</a>
				<a href="/admin/" style="margin-left: 10px; padding: 8px 12px; background: var(--panel); color: var(--text); text-decoration: none; border-radius: var(--radius);">Admin</a>	
				<a href="http://192.168.0.142/v1/routes_tester" style="margin-left: 10px; padding: 8px 12px; background: var(--panel); color: var(--text); text-decoration: none; border-radius: var(--radius);">Routes Tester</a>

				<a href="inbox.php" style="margin-left: 10px; padding: 8px 12px; background: var(--panel); color: var(--text); text-decoration: none; border-radius: var(--radius);">Inbox</a>
				<!-- Add more navigation links as needed -->
				 <!-- need a edit prompt page? -->
				<a href="?view=prompts" style="margin-left: 10px; padding: 8px 12px; background: var(--panel); color: var(--text); text-decoration: none; border-radius: var(--radius);">Prompts</a>  


			</div>
            <?php // Display errors if any
            if (!empty($errors)): ?>
                <div class="error-box">
                    <strong style="color: #ff6b6b;">Issues:</strong>
                    <ul style="margin: 6px 0 0 0; padding-left: 18px;">
                        <?php foreach ($errors as $error): ?>
                            <li style="color: #ff8787; margin: 3px 0; font-size: 0.9rem;"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; 

            if (!empty($success)): ?>
                <div class="success-box">
                    <strong style="color: #6bff6b;">Success:</strong>
                    <ul style="margin: 6px 0 0 0; padding-left: 18px;">
                        <?php foreach ($success as $msg): ?>
                            <li style="color: #87ff87; margin: 3px 0; font-size: 0.9rem;"><?= htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>


			<form method="get" class="search">
				<input type="hidden" name="view" value="<?php echo htmlspecialchars($view, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
				<input type="text" name="q" value="<?= htmlspecialchars($search ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="Search <?php echo $view === 'ai' ? 'AI metadata' : ($view === 'bash' ? 'bash history' : ($view === 'search_cache' ? 'search cache' : 'notes')); ?>..." />
				<button type="submit">Search</button>
			</form>
			
		</div>


<?php if ($view === 'prompts'): ?>
<div class="card" id="form">
  <h2 style="margin:0 0 12px 0;">Prompts</h2>

  <form method="post">
    <input type="hidden" name="action" value="prompt_create" />
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
      <div>
        <label>Name</label>
        <input type="text" name="p_name" required placeholder="Herald: Digest News" />
      </div>
      <div>
        <label>Kind</label>
        <select name="p_kind">
          <option value="prompt">prompt</option>
          <option value="system">system</option>
          <option value="persona">persona</option>
          <option value="tool">tool</option>
          <option value="chain">chain</option>
        </select>
      </div>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
      <div>
        <label>Tags (csv)</label>
        <input type="text" name="p_tags" placeholder="php, api, inbox, sqlite" />
      </div>
      <div>
        <label>Model hint</label>
        <input type="text" name="p_model" placeholder="gpt-oss:latest / gemma3:4b" />
      </div>
    </div>

    <div>
      <label>Version</label>
      <input type="text" name="p_version" placeholder="2025-12-27.1" />
    </div>

    <div>
      <label>Prompt body</label>
      <textarea name="p_body" required placeholder="Write the prompt..."></textarea>
    </div>

    <button type="submit">Save Prompt</button>
  </form>
</div>
<?php endif; ?>



		<?php if ($view !== 'ai' && $view !== 'bash' && $view !== 'search_cache'): ?>
		<div class="card" id="form">
			<?php
			$parentId = (int)($_GET['parent_id'] ?? 0);
			if ($parentId > 0) {
				$parentNote = fetchNoteById($db, $parentId);
				if ($parentNote) {
					echo '<div class="parent-note">';
					echo '<h3>Replying to:</h3>';
					echo '<div class="note-meta">';
					echo '<span class="note-type">' . htmlspecialchars($parentNote['notes_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
					if ($parentNote['topic']) {
						echo '<span class="note-topic">' . htmlspecialchars($parentNote['topic'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
					}
					echo '<span class="note-date">' . htmlspecialchars($parentNote['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
					echo '<a class="note-link" href="#note-' . (int)$parentNote['id'] . '">#' . (int)$parentNote['id'] . '</a>';
					echo '</div>';
					echo '<div class="note-body">' . renderMarkdown($parentNote['note']) . '</div>';
					echo '</div>';
				}
			}
			?>
			<form method="post" enctype="multipart/form-data">
				<input type="hidden" name="action" value="create">
                <div>
					<label for="note">Note</label>
					<textarea id="note" name="note" required placeholder="Write markdown-enabled note..."></textarea>
				</div>
                <div>
					<label for="topic">Topic (optional)</label>
					<input type="text" id="topic" name="topic" placeholder="Group notes by topic..." />
				</div>
				<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
					<div>
						<label for="node">Node (optional)</label>
						<input type="text" id="node" name="node" placeholder="lan-142 / do1 / jville" />
					</div>
					<div>
						<label for="path">Path (optional)</label>
						<input type="text" id="path" name="path" value="/web/html/v1/inbox.php" />
					</div>
					<div>
						<label for="version">Version (optional)</label>
						<input type="text" id="version" name="version" placeholder="2025-12-26.1" />
					</div>
				</div>
				<div>
					<label for="notes_type">Type</label>
					<select id="notes_type" name="notes_type">
						<?php foreach (NOTES_TYPES as $t): ?>
							<option value="<?= $t ?>"><?= $t ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label for="parent_id">Parent ID (optional)</label>
					<input type="text" id="parent_id" name="parent_id" value="<?= $parentId ?: '' ?>" inputmode="numeric" pattern="[0-9]*" placeholder="Reply to note id" />
				</div>
				<div>
					<label for="files">Upload Files (optional)</label>
					<input type="file" id="files" name="files[]" multiple accept="*/*" />
					<p class="file-hint">Up to 50MB per file. Multiple files supported.</p>
				</div>
				<button type="submit">Save Note</button>
			</form>
		</div>
		<?php endif; ?>

		<div class="card">
			<?php if ($view === 'prompts'): ?>
			  <div class="card">
				<div class="muted" style="margin-bottom:10px;">
				  Showing <?=count($prompts ?? [])?> prompt(s)
				</div>
				<ul class="note-list">
				  <?php foreach (($prompts ?? []) as $p): ?>
					<li class="note-item" id="prompt-<?= (int)$p['id'] ?>">
					  <div class="note-meta">
						<span class="note-type"><?= htmlspecialchars($p['kind'] ?? 'prompt') ?></span>
						<span class="note-topic"><?= htmlspecialchars($p['name'] ?? '') ?></span>
						<?php if (!empty($p['version'])): ?>
						  <span class="note-topic">v <?= htmlspecialchars($p['version']) ?></span>
						<?php endif; ?>
						<span class="note-date"><?= htmlspecialchars($p['updated_at'] ?? '') ?></span>
						<a class="note-link" href="#prompt-<?= (int)$p['id'] ?>">#P<?= (int)$p['id'] ?></a>

						<form method="post" style="display:inline;">
						  <input type="hidden" name="action" value="prompt_delete" />
						  <input type="hidden" name="prompt_id" value="<?= (int)$p['id'] ?>" />
						  <button type="submit" class="delete-btn" onclick="return confirm('Delete this prompt?')">Delete</button>
						</form>
					  </div>

					  <div class="muted" style="margin:0 0 8px 0;">
						<?php if (!empty($p['tags'])): ?>tags: <?= htmlspecialchars($p['tags']) ?><?php endif; ?>
						<?php if (!empty($p['model_hint'])): ?> | model: <?= htmlspecialchars($p['model_hint']) ?><?php endif; ?>
					  </div>

					  <pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;"><?= htmlspecialchars($p['body'] ?? '') ?></pre>
					</li>
				  <?php endforeach; ?>
				</ul>
			  </div>
			<?php elseif ($view === 'ai'): ?>
				<?= renderAiTree($tree, $db_for_render); ?>
			<?php elseif ($view === 'bash'): ?>
				<?= renderBashCommands($bash_rows ?? []); ?>
			<?php elseif ($view === 'search_cache'): ?>
				<?= renderSearchCacheEntries($search_cache_rows ?? [], $search_cache_edit ?? null, $search ?? null); ?>
			<?php else: ?>
				<?= renderTree($tree, $db_for_render); ?>
			<?php endif; ?>
		</div>
	</div>

<?php
// Debug info - set to false to hide
if (true) {
	echo '<div style="background: #1a1a1a; color: #0f0; padding: 12px; margin: 20px; border: 2px solid #0f0; font-family: monospace; font-size: 0.85rem;">';
	echo '<strong>Debug Info:</strong><br>';
	echo "REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD'] . "<br>";
	echo "CONTENT_LENGTH: " . ($_SERVER['CONTENT_LENGTH'] ?? 'not set') . "<br>";
	echo "POST count: " . count($_POST) . " | FILES count: " . (isset($_FILES['files']) ? count($_FILES['files']['name'] ?? []) : 0) . "<br>";
	echo "post_max_size: " . ini_get('post_max_size') . " | upload_max_filesize: " . ini_get('upload_max_filesize') . "<br>";
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST)) {
		echo '<span style="color: #ff4444; font-weight: bold;">⚠ POST is empty - likely exceeds post_max_size!</span>';
	}
	echo '</div>';
}
?>
</body>
</html>
