<?php
session_start();

/*
_metadata.


#perfectcronjobs for bash history ingestion and AI classification/search queuing
* * * * * find /web/private/db -type f ! -perm 0660 -exec chmod 0660 {} \;
* * * * * chown  -R -c /web/private/db samekhi:www-data

# turned back on 1/1/2026, for testing purposes, noisy notes posts
# 1st is an example of .sh
# 2nd is an example of .py
# 3rd is an example of .php not written yet
#1 * * * * /web/private/scripts/save_bash_history_threaded.sh root  >> /web/private/logs/test1.cron.log 2>&1
2 * * * * /usr/bin/python3 /web/private/scripts/save_bash_history_threaded.py root >> /web/private/logs/test2.cron.log 2>&1


# Check url: /admin/notes/?view=jobs
# gui button Jobs to check Cron heartbeat (start/ok/error + duration).
# this log could be read ingest_bash_history_to_kb.log for hourly tail -f updates
# we need a new /private/db/memory/logger.db which auto purges after 7 days lol
# /private/logs -> /private/db/memory/logger.db
# but first, make sure the ingest_bash_history_to_kb.py script works fine from cron and not just manually.
5 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/ingest_bash_history_to_kb.py samekhi  >> /web/private/logs/ingest_bash_history_to_kb.log 2>&1
7 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/ingest_bash_history_to_kb.py root  >> /web/private/logs/ingest_bash_history_to_kb.log 2>&1

# /web/private/logs/ingest_bash_history_to_kb.log
# created what db:
# /web/private/db/memory/bash_history.db
# /web/private/db/memory/human_notes.db
# next: classify bash commands with AI and store metadata in notes_ai_metadata.db
# then: queue bash searches to search_cache.db  
# the code in /web/html/admin/notes/scripts/ingest_bash_history_to_kb.py
# is unreadable, needs refactoring and comments.
# Notes Scripts: AI Notes Metadata population and Bash History ingestion/processing


10 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/ai_notes.py >> /web/private/logs/ai_notes.cron.log 2>&1

15 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/classify_bash_commands.py >> /web/private/logs/classify_bash_commands.log 2>&1

25 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/queue_bash_searches.py >> /web/private/logs/queue_bash_searches.log 2>&1

# search_cache.db -> ai_notes (summary) -> notes_type=ai_generated
35 * * * * /usr/bin/python3 /web/html/admin/notes/scripts/ai_search_summ.py >> /web/private/logs/ai_search_summ.log 2>&1

*/


//show all errors for debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);



//
$webRoot = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '';
define('APP_LIB', rtrim($webRoot, "/\\") . '/lib');
require_once APP_LIB . '/bootstrap.php';
require_once APP_LIB . '/schema_builder.php';
require_once APP_LIB . '/prompts_lib.php';

require_once __DIR__ . '/notes_core.php';
require_once __DIR__ . '/notes_actions.php';

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
 
// Notes app (target PHP 7.3+). Markdown-ish input, dark UI, SQLite persistence, and threaded replies.
const DB_MEMORY_DIR = '/web/private/db/memory';
// human_notes.db: main notes storage human input and edits/deletes searches.
const DB_PATH = '/web/private/db/memory/human_notes.db';

// for this to work, notes_core.php must be included after DB_PATH is defined.
//require_once __DIR__ . '/notes_core.php';
const AI_DB_PATH = '/web/private/db/memory/notes_ai_metadata.db';

// 
const BASH_DB_PATH = '/web/private/db/memory/bash_history.db';
const SEARCH_CACHE_DB_PATH = '/web/private/db/memory/search_cache.db';
const UPLOAD_DIR = '/web/private/uploads/memory/';

// UI config: default nav comes from this hardcoded config, but can be overridden
// by JSON stored in the notes DB settings table (key: ui.nav.json).
// This avoids relying on .env or file scanning for configuration.
const NAV_SETTINGS_KEY = 'ui.nav.json';
const AI_OLLAMA_URL_KEY = 'ai.ollama.url';
const AI_OLLAMA_MODEL_KEY = 'ai.ollama.model';
const SEARCH_API_BASE_KEY = 'search.api.base';
const NOTES_DEFAULT_JSON_PATH = '/web/private/notes_default.json';
require_once __DIR__ . '/notes_ui.php';

// Preflight: avoid hard exits on fresh installs; collect actionable errors.
ensureWritableDir(dirname(DB_PATH), $errors, 'Notes DB parent');
ensureWritableDir(UPLOAD_DIR, $errors, 'Uploads');



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


try {
	$db = ensureDb();
} catch (Throwable $e) {
	$errors[] = 'DB init failed: ' . $e->getMessage();
	$db = null;
}

$navItems = loadNavConfig($db, $errors);
$allowedViews = getAllowedViewsFromNav($navItems);

notesHandlePost($db, $errors, $success);
notesHandleGetJson($db, $errors, $success);

$search = $_GET['q'] ?? null;
$view = $_GET['view'] ?? 'human';
// DB browser params (read-only)
$dbName = $_GET['db'] ?? null;
$tableName = $_GET['table'] ?? null;

$ai_setup_html = null;
$db_browser_html = null;
$search_cache_rows = null;
$search_cache_edit = null;
$bash_rows = null;
$tree = null;
$db_for_render = null;
$prompts = null;

if (!in_array($view, $allowedViews, true)) {
	$view = 'human';
}

if ($db === null && $view !== 'ai' && $view !== 'bash' && $view !== 'search_cache' && $view !== 'prompts') {
	$view = 'human';
}

if ($view === 'ai') {
	try {
		$ai_db = ensureAiDb();
		$notes = fetchAiNotes($ai_db, $search);
		$tree = buildAiTree($notes);
		$db_for_render = $ai_db;
	} catch (Throwable $e) {
		$errors[] = 'AI Metadata: ' . $e->getMessage();
		$tree = [];
		$db_for_render = null;
	}
} elseif ($view === 'prompts') {
	$pdb = ensurePromptsDb();
	$kind = $_GET['kind'] ?? 'all';
	$prompts = promptsFetch($pdb, $search, $kind, 250);
} elseif ($view === 'bash') {
	try {
		if (!file_exists(BASH_DB_PATH)) {
			throw new Exception('Bash history DB missing: ' . BASH_DB_PATH);
		}
		$bash_db = new SQLite3(BASH_DB_PATH);
		$bash_rows = fetchBashCommands($bash_db, $search, 250);
		$db_for_render = $bash_db;
	} catch (Throwable $e) {
		$errors[] = 'Bash history: ' . $e->getMessage();
		$bash_rows = [];
		$db_for_render = null;
	}
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
	} catch (Throwable $e) {
		$errors[] = 'Search cache: ' . $e->getMessage();
		$search_cache_rows = [];
		$search_cache_edit = null;
		$db_for_render = null;
	}
} elseif ($view === 'dbs') {
	$db_browser_html = renderDbBrowser($errors, is_string($dbName) ? $dbName : null, is_string($tableName) ? $tableName : null, $search);
} elseif ($view === 'ai_setup') {
	$ai_setup_html = renderAiSetup($errors, $db);
} else {
	if ($db === null) {
		$tree = [];
		$db_for_render = null;
	} else {
		$notes = fetchNotes($db, $search);
		$tree = buildTree($notes);
		$db_for_render = $db;
	}
}

$ctx = [
	'view' => $view,
	'search' => $search,
	'errors' => $errors,
	'success' => $success,
	'navItems' => $navItems,
	'allowedViews' => $allowedViews,
	'dbName' => $dbName,
	'tableName' => $tableName,
	'db' => $db,
	'ai_setup_html' => $ai_setup_html,
	'db_browser_html' => $db_browser_html,
	'bash_rows' => $bash_rows,
	'search_cache_rows' => $search_cache_rows,
	'search_cache_edit' => $search_cache_edit,
	'tree' => $tree,
	'db_for_render' => $db_for_render,
	'prompts' => $prompts ?? null,
];

$ctx['ctx'] = $ctx;
require __DIR__ . '/views/layout.php';
//display array $GLOBALS['APP_BOOTSTRAP_CONFIG']
//var_dump($GLOBALS['APP_BOOTSTRAP_CONFIG']);
//convert to json
$json = json_encode($GLOBALS['APP_BOOTSTRAP_CONFIG'], JSON_PRETTY_PRINT);
echo "<pre>$json</pre>";

//$GLOBALS['APP_BOOTSTRAP_ENV']
$json = json_encode($GLOBALS['APP_BOOTSTRAP_ENV'], JSON_PRETTY_PRINT);
echo "<pre>$json</pre>";

//all   $GLOBALS
foreach ($GLOBALS as $key => $value) {
	//skip large arrays and objects
	if (is_array($value) && count($value) > 10) {
		echo "<br>$key: [array with " . count($value) . " elements]\n";
	} elseif (is_object($value)) {
		echo "<br>$key: [object of class " . get_class($value) . "]\n";
	} else {
		echo "<br>$key: ";
		//var_dump($value);
		//$json = json_encode($value, JSON_PRETTY_PRINT);
		echo htmlspecialchars(var_export($value, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}

exit;
