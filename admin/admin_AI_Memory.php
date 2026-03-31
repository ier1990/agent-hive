<?php
declare(strict_types=0);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';

auth_require_admin();
auth_session_start();

define('AGENT_MEMORY_DB_PATH', '/web/private/db/memory/agent_ai_memory.db');
define('AGENT_TOOL_SETTINGS_JSON', '/web/private/agent_tools.json');

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ai_memory_csrf_token(): string
{
    if (empty($_SESSION['ai_memory_csrf'])) {
        $_SESSION['ai_memory_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['ai_memory_csrf'];
}

function ai_memory_csrf_valid(string $token): bool
{
    $current = (string)($_SESSION['ai_memory_csrf'] ?? '');
    return $current !== '' && hash_equals($current, $token);
}

function ai_memory_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = dirname(AGENT_MEMORY_DB_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . AGENT_MEMORY_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS memory_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            topic TEXT DEFAULT "",
            content TEXT NOT NULL,
            tags TEXT DEFAULT "",
            source TEXT DEFAULT "",
            pinned INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_memory_entries_created ON memory_entries(created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_memory_entries_pinned ON memory_entries(pinned DESC, id DESC)');

    return $pdo;
}

function ai_memory_load_tool_settings(): array
{
    $defaults = [
        'memory' => [
            'enabled' => true,
            'db_path' => AGENT_MEMORY_DB_PATH,
            'autoload_on_start' => false,
            'autoload_limit' => 10,
            'default_search_limit' => 8,
            'max_write_length' => 4000,
        ],
    ];

    if (!is_file(AGENT_TOOL_SETTINGS_JSON)) {
        return $defaults;
    }

    $raw = @file_get_contents(AGENT_TOOL_SETTINGS_JSON);
    $parsed = json_decode((string)$raw, true);
    if (!is_array($parsed)) {
        return $defaults;
    }

    if (isset($parsed['memory']) && is_array($parsed['memory'])) {
        $defaults['memory'] = array_merge($defaults['memory'], $parsed['memory']);
    }
    return $defaults;
}

function ai_memory_save_tool_settings(array $memoryConfig): bool
{
    $settings = [];
    if (is_file(AGENT_TOOL_SETTINGS_JSON)) {
        $raw = @file_get_contents(AGENT_TOOL_SETTINGS_JSON);
        $parsed = json_decode((string)$raw, true);
        if (is_array($parsed)) {
            $settings = $parsed;
        }
    }
    $settings['memory'] = $memoryConfig;

    $dir = dirname(AGENT_TOOL_SETTINGS_JSON);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    return @file_put_contents(AGENT_TOOL_SETTINGS_JSON, $json) !== false;
}

$success = [];
$errors = [];
$toolSettings = ai_memory_load_tool_settings();
$memoryCfg = $toolSettings['memory'];
$pdo = ai_memory_db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!ai_memory_csrf_valid($token)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'memory_add') {
                $topic = trim((string)($_POST['topic'] ?? ''));
                $content = trim((string)($_POST['content'] ?? ''));
                $tags = trim((string)($_POST['tags'] ?? ''));
                $source = trim((string)($_POST['source'] ?? ''));
                $pinned = !empty($_POST['pinned']) ? 1 : 0;

                if ($content === '') {
                    $errors[] = 'Memory content is required.';
                } else {
                    $maxWrite = max(100, min((int)($memoryCfg['max_write_length'] ?? 4000), 20000));
                    if (strlen($content) > $maxWrite) {
                        $content = substr($content, 0, $maxWrite);
                    }
                    $stmt = $pdo->prepare(
                        'INSERT INTO memory_entries (topic, content, tags, source, pinned, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
                    );
                    $stmt->execute([$topic, $content, $tags, $source, $pinned]);
                    $success[] = 'Memory entry added.';
                }
            } elseif ($action === 'memory_delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id < 1) {
                    $errors[] = 'Invalid memory id.';
                } else {
                    $stmt = $pdo->prepare('DELETE FROM memory_entries WHERE id = ?');
                    $stmt->execute([$id]);
                    $success[] = 'Memory entry deleted.';
                }
            } elseif ($action === 'memory_settings') {
                $memoryCfg['enabled'] = !empty($_POST['enabled']);
                $memoryCfg['db_path'] = trim((string)($_POST['db_path'] ?? AGENT_MEMORY_DB_PATH));
                if ($memoryCfg['db_path'] === '') {
                    $memoryCfg['db_path'] = AGENT_MEMORY_DB_PATH;
                }
                $memoryCfg['autoload_on_start'] = !empty($_POST['autoload_on_start']);
                $memoryCfg['autoload_limit'] = max(1, min((int)($_POST['autoload_limit'] ?? 10), 50));
                $memoryCfg['default_search_limit'] = max(1, min((int)($_POST['default_search_limit'] ?? 8), 50));
                $memoryCfg['max_write_length'] = max(100, min((int)($_POST['max_write_length'] ?? 4000), 20000));

                if (!ai_memory_save_tool_settings($memoryCfg)) {
                    $errors[] = 'Failed to save tool settings.';
                } else {
                    $success[] = 'Memory settings saved.';
                }
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$rows = [];
if ($search === '') {
    $stmt = $pdo->prepare(
        'SELECT id, topic, content, tags, source, pinned, created_at, updated_at
         FROM memory_entries
         ORDER BY pinned DESC, id DESC
         LIMIT 100'
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();
} else {
    $like = '%' . $search . '%';
    $stmt = $pdo->prepare(
        'SELECT id, topic, content, tags, source, pinned, created_at, updated_at
         FROM memory_entries
         WHERE topic LIKE ? OR content LIKE ? OR tags LIKE ? OR source LIKE ?
         ORDER BY pinned DESC, id DESC
         LIMIT 100'
    );
    $stmt->execute([$like, $like, $like, $like]);
    $rows = $stmt->fetchAll();
}

$entryCount = (int)$pdo->query('SELECT COUNT(1) FROM memory_entries')->fetchColumn();
$csrfToken = ai_memory_csrf_token();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Memory - Agent Hive Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0d1117; color: #e6edf3; line-height: 1.6; }
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem; }
        header { border-bottom: 1px solid #30363d; padding-bottom: 2rem; margin-bottom: 2rem; }
        h1 { color: #58a6ff; margin-bottom: 0.5rem; }
        .subtitle { color: #8b949e; font-size: 0.9rem; }
        .top-grid, .architecture-grid, .comparison { display: grid; gap: 1.25rem; }
        .top-grid { grid-template-columns: 1fr 1fr; margin-bottom: 2rem; }
        .architecture-grid { grid-template-columns: 1fr 1fr; margin-bottom: 3rem; }
        .comparison { grid-template-columns: 1fr 1fr; margin: 1rem 0; }
        .section { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 1.5rem; }
        .section h2 { color: #79c0ff; font-size: 1.1rem; margin-bottom: 1rem; border-bottom: 2px solid #30363d; padding-bottom: 0.5rem; }
        .section h3 { color: #a371f7; font-size: 0.95rem; margin-top: 1rem; margin-bottom: 0.5rem; }
        ul, ol { margin-left: 1.5rem; margin-bottom: 1rem; }
        li { margin-bottom: 0.5rem; }
        .code-block { background: #0d1117; border-left: 3px solid #79c0ff; padding: 1rem; margin: 1rem 0; font-family: 'Monaco', 'Courier New', monospace; font-size: 0.85rem; overflow-x: auto; border-radius: 4px; white-space: pre-wrap; }
        .warning { background: #f85149; color: #fff; padding: 0.2rem 0.4rem; border-radius: 3px; }
        .success { background: #3fb950; color: #fff; padding: 0.2rem 0.4rem; border-radius: 3px; }
        .flow { background: #0d1117; border: 1px solid #30363d; border-radius: 6px; padding: 1.5rem; margin: 1rem 0; }
        .flow-step { display: flex; align-items: flex-start; margin-bottom: 1.5rem; }
        .flow-step:last-child { margin-bottom: 0; }
        .flow-number { background: #388bfd; color: white; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; flex-shrink: 0; margin-right: 1rem; }
        .flow-content { flex: 1; }
        .status-badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.85rem; font-weight: bold; margin-right: 0.5rem; }
        .status-todo { background: #f0883e; color: white; }
        .bad { background: #da3633; color: white; padding: 1rem; border-radius: 6px; }
        .good { background: #3fb950; color: white; padding: 1rem; border-radius: 6px; }
        .notice { padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .notice.ok { background: #1f6feb20; border: 1px solid #388bfd; }
        .notice.err { background: #f8514920; border: 1px solid #f85149; }
        label { display: block; font-size: 0.9rem; color: #c9d1d9; margin-bottom: 0.35rem; }
        input[type=text], input[type=number], textarea { width: 100%; background: #0d1117; border: 1px solid #30363d; color: #e6edf3; border-radius: 6px; padding: 0.75rem; }
        textarea { min-height: 120px; resize: vertical; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .actions { margin-top: 1rem; display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
        .btn { display: inline-block; background: #238636; color: #fff; border: none; border-radius: 6px; padding: 0.65rem 1rem; cursor: pointer; text-decoration: none; }
        .btn.secondary { background: #30363d; }
        .btn.danger { background: #da3633; }
        .toolbar { display: flex; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap; align-items: center; }
        .toolbar form { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; width: 100%; }
        .memory-list { display: grid; gap: 1rem; }
        .memory-row { border: 1px solid #30363d; border-radius: 8px; padding: 1rem; background: #0d1117; }
        .meta { color: #8b949e; font-size: 0.85rem; margin-bottom: 0.5rem; }
        .tag { display: inline-block; background: #1f6feb20; color: #79c0ff; padding: 0.15rem 0.5rem; border-radius: 999px; margin-right: 0.35rem; }
        .checkbox { display: inline-flex; align-items: center; gap: 0.5rem; margin-right: 1rem; }
        .small { color: #8b949e; font-size: 0.85rem; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🧠 AI Memory</h1>
            <p class="subtitle">Agent memory store, startup preload controls, and architecture notes</p>
        </header>

        <?php foreach ($success as $msg): ?>
            <div class="notice ok"><?= h($msg) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $msg): ?>
            <div class="notice err"><?= h($msg) ?></div>
        <?php endforeach; ?>

        <div class="top-grid">
            <div class="section">
                <h2>Agent Memory Store</h2>
                <p class="small" style="margin-bottom:1rem;">SQLite path: <code><?= h(AGENT_MEMORY_DB_PATH) ?></code></p>
                <div class="code-block">Entries: <?= h((string)$entryCount) . "\n" ?>
Enabled: <?= !empty($memoryCfg['enabled']) ? 'yes' : 'no' . "\n" ?>
Autoload on start: <?= !empty($memoryCfg['autoload_on_start']) ? 'yes' : 'no' . "\n" ?>
Autoload limit: <?= h((string)($memoryCfg['autoload_limit'] ?? 10)) . "\n" ?>
Default search limit: <?= h((string)($memoryCfg['default_search_limit'] ?? 8)) . "\n" ?>
Max write length: <?= h((string)($memoryCfg['max_write_length'] ?? 4000)) ?></div>
                <p class="small">The Python agent exposes this as <code>memory_search</code> and <code>memory_write</code>. If autoload is enabled, the latest entries are injected into the initial run context.</p>
            </div>

            <div class="section">
                <h2>Memory Settings</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="memory_settings">
                    <div class="checkbox">
                        <input type="checkbox" id="enabled" name="enabled" value="1" <?= !empty($memoryCfg['enabled']) ? 'checked' : '' ?>>
                        <label for="enabled" style="margin:0;">Enable memory tools</label>
                    </div>
                    <div class="checkbox">
                        <input type="checkbox" id="autoload_on_start" name="autoload_on_start" value="1" <?= !empty($memoryCfg['autoload_on_start']) ? 'checked' : '' ?>>
                        <label for="autoload_on_start" style="margin:0;">Autoload recent entries at start</label>
                    </div>
                    <div class="form-grid" style="margin-top:1rem;">
                        <div>
                            <label for="db_path">DB path</label>
                            <input type="text" id="db_path" name="db_path" value="<?= h((string)($memoryCfg['db_path'] ?? AGENT_MEMORY_DB_PATH)) ?>">
                        </div>
                        <div>
                            <label for="autoload_limit">Autoload limit</label>
                            <input type="number" id="autoload_limit" name="autoload_limit" min="1" max="50" value="<?= h((string)($memoryCfg['autoload_limit'] ?? 10)) ?>">
                        </div>
                        <div>
                            <label for="default_search_limit">Default search limit</label>
                            <input type="number" id="default_search_limit" name="default_search_limit" min="1" max="50" value="<?= h((string)($memoryCfg['default_search_limit'] ?? 8)) ?>">
                        </div>
                        <div>
                            <label for="max_write_length">Max write length</label>
                            <input type="number" id="max_write_length" name="max_write_length" min="100" max="20000" value="<?= h((string)($memoryCfg['max_write_length'] ?? 4000)) ?>">
                        </div>
                    </div>
                    <div class="actions">
                        <button type="submit" class="btn">Save memory settings</button>
                        <span class="small">Saved to <code><?= h(AGENT_TOOL_SETTINGS_JSON) ?></code></span>
                    </div>
                </form>
            </div>
        </div>

        <div class="section" style="margin-bottom:2rem;">
            <h2>Add Memory Entry</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="memory_add">
                <div class="form-grid">
                    <div>
                        <label for="topic">Topic</label>
                        <input type="text" id="topic" name="topic" placeholder="agent preference, deployment note, project fact">
                    </div>
                    <div>
                        <label for="source">Source</label>
                        <input type="text" id="source" name="source" placeholder="manual, shell, admin page, user request">
                    </div>
                </div>
                <div class="form-grid" style="margin-top:1rem;">
                    <div>
                        <label for="tags">Tags</label>
                        <input type="text" id="tags" name="tags" placeholder="comma,separated,tags">
                    </div>
                    <div class="checkbox" style="margin-top:2rem;">
                        <input type="checkbox" id="pinned" name="pinned" value="1">
                        <label for="pinned" style="margin:0;">Pinned</label>
                    </div>
                </div>
                <div style="margin-top:1rem;">
                    <label for="content">Content</label>
                    <textarea id="content" name="content" placeholder="Durable memory the agent should be able to search later"></textarea>
                </div>
                <div class="actions">
                    <button type="submit" class="btn">Write memory</button>
                    <span class="small">This maps to the runtime <code>memory_write</code> tool.</span>
                </div>
            </form>
        </div>

        <div class="section" style="margin-bottom:2rem;">
            <h2>Stored Memory Entries</h2>
            <div class="toolbar">
                <form method="get">
                    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search topic, content, tags, or source">
                    <button type="submit" class="btn secondary">Search</button>
                    <a href="admin_AI_Memory.php" class="btn secondary">Clear</a>
                </form>
            </div>
            <div class="memory-list">
                <?php if (empty($rows)): ?>
                    <div class="memory-row"><div class="small">No memory entries found.</div></div>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <div class="memory-row">
                        <div class="meta">
                            #<?= h((string)$row['id']) ?>
                            <?php if (!empty($row['pinned'])): ?><span class="tag">pinned</span><?php endif; ?>
                            <?php if ((string)$row['topic'] !== ''): ?><span class="tag"><?= h((string)$row['topic']) ?></span><?php endif; ?>
                            <?php if ((string)$row['tags'] !== ''): ?><span class="tag"><?= h((string)$row['tags']) ?></span><?php endif; ?>
                        </div>
                        <div style="margin-bottom:0.5rem; white-space:pre-wrap;"><?= h((string)$row['content']) ?></div>
                        <div class="meta">
                            source: <?= h((string)$row['source']) ?> |
                            created: <?= h((string)$row['created_at']) ?> |
                            updated: <?= h((string)$row['updated_at']) ?>
                        </div>
                        <form method="post" style="margin-top:0.75rem;">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="action" value="memory_delete">
                            <input type="hidden" name="id" value="<?= h((string)$row['id']) ?>">
                            <button type="submit" class="btn danger" onclick="return confirm('Delete this memory entry?');">Delete</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="section" style="background: #1f6feb20; border-color: #388bfd; margin-bottom:2rem;">
            <h2>Agent Memory Runtime Notes</h2>
            <ul>
                <li><span class="success">Tool</span> <code>memory_search</code> queries durable agent memory from <code><?= h(AGENT_MEMORY_DB_PATH) ?></code>.</li>
                <li><span class="success">Tool</span> <code>memory_write</code> inserts durable entries from the agent runtime.</li>
                <li><span class="success">Startup preload</span> can inject the latest N entries into the initial run context when enabled.</li>
                <li><span class="warning">Guidance</span> Keep entries short and durable. Preferences, workflow notes, and recurring facts work better than transient chat logs.</li>
            </ul>
        </div>

        <div class="section" style="background: #1f6feb20; border-color: #388bfd; margin-bottom:2rem;">
            <h2>Architecture Reference</h2>
            <p class="small">The original AI memory architecture notes are preserved below for planning and future RAG work.</p>
        </div>

        <div class="architecture-grid">
            <div class="section">
                <h2>🎯 Core Challenge</h2>
                <p>When analyzing large document sets (20,000+ pages), AI models hallucinate:</p>
                <ul>
                    <li>Make up information not in documents</li>
                    <li>Invent citations</li>
                    <li>Fill gaps with "likely" information</li>
                    <li>Merge similar statements incorrectly</li>
                </ul>
                <p style="margin-top: 1rem; font-style: italic;">The Problem: "Don't let the model remember. Make it retrieve with proof."</p>
            </div>

            <div class="section">
                <h2>✅ The Solution</h2>
                <p><strong>Retrieval-Augmented Generation (RAG)</strong> with forced citation:</p>
                <ol>
                    <li>Embed all documents → vector storage</li>
                    <li>Query → vector search → retrieve top-k chunks only</li>
                    <li>LLM sees ONLY retrieved chunks (not corpus)</li>
                    <li>Force every answer to cite sources</li>
                </ol>
                <p style="margin-top: 1rem; color: #3fb950;"><strong>Result:</strong> Hallucinations collapse to near-zero</p>
            </div>
        </div>

        <div class="section">
            <h2>🏗️ Production-Grade Architecture</h2>
            <h3>Step 1: Truth Layer (Chunk + Embed)</h3>
            <div class="flow">
                <div class="flow-step">
                    <div class="flow-number">1</div>
                    <div class="flow-content">
                        <strong>Break documents into chunks</strong><br>
                        Size: 500–1000 tokens | Overlap: 10–20%
                        <div class="code-block">doc_id: "audit_2018.pdf"
filename: "audit_2018.pdf"
page_number: 14
paragraph_number: 2
text: "The internal audit identified irregular vendor billing patterns"
vector_embedding: [0.234, -0.122, ...]</div>
                    </div>
                </div>
                <div class="flow-step">
                    <div class="flow-number">2</div>
                    <div class="flow-content">
                        <strong>Store with metadata</strong><br>
                        Embedding models: Qwen3, Gemma, others (consistency matters more than choice)
                    </div>
                </div>
            </div>

            <h3>Step 2: Query → Retrieval (Evidence Set)</h3>
            <div class="flow">
                <div class="flow-step">
                    <div class="flow-number">1</div>
                    <div class="flow-content"><strong>User Question:</strong> "What did the 2018 audit conclude about vendor fraud?"</div>
                </div>
                <div class="flow-step">
                    <div class="flow-number">2</div>
                    <div class="flow-content">
                        <strong>Pipeline:</strong>
                        <div class="code-block">embed(query)
  → similarity search (vector index)
  → return top 5–10 chunks (NOT 1, NOT 50)</div>
                    </div>
                </div>
                <div class="flow-step">
                    <div class="flow-number">3</div>
                    <div class="flow-content"><strong>Evidence Set:</strong> Each chunk includes source, page, and text</div>
                </div>
            </div>

            <h3>Step 3: Hard Grounded Prompt</h3>
            <p style="color: #f85149; font-weight: bold;">⚠️ This is where most systems fail</p>
            <div class="code-block">You must answer ONLY using the provided sources.
If the answer is not explicitly stated in the sources, say:
"Not found in the provided documents."

Every statement must cite its source in the format:
(doc: filename.pdf, page: X)

---
[Doc: audit_2018.pdf, Page 14]
"...The internal audit identified irregular vendor billing patterns..."

[Doc: audit_2018.pdf, Page 22]
"...Evidence of duplicate payments totaling $480,000..."
---

Question: What did the 2018 audit conclude about vendor fraud?</div>
            <p><strong>Result:</strong> Model cannot invent. Citations are verifiable.</p>

            <h3>Step 4: Why This Works</h3>
            <ul>
                <li>❌ Model CANNOT access training data</li>
                <li>❌ Model CANNOT access full corpus</li>
                <li>❌ Model CANNOT fabricate cited evidence</li>
                <li>✅ You can instantly verify each citation</li>
            </ul>
            <p style="margin-top: 1rem;"><strong>Conversion:</strong> From "generative predictor" → "evidence synthesizer"</p>
        </div>

        <div class="architecture-grid">
            <div class="section">
                <h2>❌ What Causes Hallucinations</h2>
                <ul>
                    <li>Whole corpus in context window</li>
                    <li>Summarization without retrieval</li>
                    <li>Irrelevant vector search results</li>
                    <li>Prompt allows outside knowledge</li>
                    <li>No permission to say "Not found"</li>
                </ul>
                <p style="margin-top: 1rem; font-style: italic;">LLMs always try to be helpful. Explicitly permit "I don't know."</p>
            </div>

            <div class="section">
                <h2>🔒 Critical Extra Layer</h2>
                <p><strong>Evidence Span Highlighting:</strong></p>
                <div class="code-block">"The audit identified irregular billing."
(doc: audit_2018.pdf, page 14)

→ This indicates vendor fraud.</div>
                <p style="margin-top: 1rem;">Separates source text from interpretation. Reduces legal risk dramatically.</p>
            </div>
        </div>

        <div class="section">
            <h2>🚀 Enterprise Pattern (Near-Zero Hallucination)</h2>
            <div class="flow">
                <div class="flow-step"><div class="flow-number">1</div><div class="flow-content">Retrieve top-k chunks</div></div>
                <div class="flow-step"><div class="flow-number">2</div><div class="flow-content">Second-pass verification: "Is answer fully supported?"</div></div>
                <div class="flow-step"><div class="flow-number">3</div><div class="flow-content">If insufficient evidence → return "Not found" (retrieval + verification loop)</div></div>
            </div>
        </div>

        <div class="section">
            <h2>🎓 Architectural Patterns</h2>
            <h3>Current Approach:</h3>
            <div class="comparison">
                <div class="bad">
                    <strong>❌ Naive (Hallucination-prone)</strong><br>
                    "Smart chatbot reading your case files"<br>
                    → Accesses full corpus<br>
                    → Generates from memory
                </div>
                <div class="good">
                    <strong>✅ Correct (Deterministic)</strong><br>
                    "Deterministic document retrieval + language layer"<br>
                    → Vector search only<br>
                    → Grounded synthesis
                </div>
            </div>

            <h3>Next-Level: Extraction over Interpretation</h3>
            <p>Instead of: <span class="warning">"What did the audit conclude?"</span></p>
            <p>Ask: <span class="success">"Extract all sentences mentioning vendor fraud"</span></p>
            <p style="margin-top: 1rem; color: #79c0ff;">Extraction → Interpretation (safer for legal environments)</p>
        </div>

        <div class="section" style="background: #1f6feb20; border-color: #388bfd; margin-top: 2rem;">
            <h2>📋 Implementation Roadmap</h2>
            <ul>
                <li><span class="status-badge status-todo">TODO</span> SQLite + embedding schema design</li>
                <li><span class="status-badge status-todo">TODO</span> Vector search integration</li>
                <li><span class="status-badge status-todo">TODO</span> RAG prompt framework</li>
                <li><span class="status-badge status-todo">TODO</span> Citation verification system</li>
                <li><span class="status-badge status-todo">TODO</span> Hallucination detection validator</li>
                <li><span class="status-badge status-todo">TODO</span> Evidence span highlighter</li>
            </ul>
        </div>
    </div>
</body>
</html>
