<?php
// Jobs Status Dashboard - Cron Heartbeat Monitor
// Self-contained view of job_runs from the notes database

const DB_PATH = '/web/private/db/memory/human_notes.db';

// Fetch job runs from the database
function fetchJobRuns() {
    $rows = [];
    
    if (!file_exists(DB_PATH)) {
        return ['error' => 'Database not found: ' . DB_PATH, 'rows' => []];
    }
    
    try {
        $db = new SQLite3(DB_PATH, SQLITE3_OPEN_READONLY);
        $res = $db->query("SELECT job, last_start, last_ok, last_status, last_message, last_duration_ms FROM job_runs ORDER BY job ASC");
        
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
            $rows[] = $r;
        }
        
        $db->close();
        return ['error' => null, 'rows' => $rows];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage(), 'rows' => []];
    }
}

$data = fetchJobRuns();
$error = $data['error'];
$rows = $data['rows'];
$autoRefresh = isset($_GET['refresh']) ? (int)$_GET['refresh'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jobs Status - Admin Console</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background: #0a0e17;
            color: #e4e8f0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            padding: 20px;
        }
        .job-grid {
            display: grid;
            grid-template-columns: 1.3fr 0.8fr 1fr 2fr;
            gap: 10px;
            font-size: 0.95rem;
        }
        .badge {
            padding: 4px 12px;
            border-radius: 999px;
            font-weight: 700;
            display: inline-block;
            font-size: 0.875rem;
        }
        .badge-ok {
            background: rgba(113, 255, 199, 0.12);
            color: #72ffd8;
        }
        .badge-error {
            background: rgba(255, 107, 107, 0.14);
            color: #ff8787;
        }
        .badge-running {
            background: rgba(255, 214, 102, 0.14);
            color: #ffd666;
        }
        .badge-unknown {
            background: rgba(255, 255, 255, 0.06);
            color: #e4e8f0;
        }
        .muted {
            color: rgba(228, 232, 240, 0.5);
        }
        .header-cell {
            font-weight: 700;
            color: rgba(228, 232, 240, 0.7);
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            margin-bottom: 10px;
        }
        .error-msg {
            background: rgba(255, 107, 107, 0.1);
            border: 1px solid rgba(255, 107, 107, 0.3);
            color: #ff8787;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 15px;
        }
        .info-msg {
            background: rgba(113, 255, 199, 0.08);
            border: 1px solid rgba(113, 255, 199, 0.2);
            color: #72ffd8;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 15px;
        }
        .refresh-controls {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }
    </style>
    <?php if ($autoRefresh > 0): ?>
    <meta http-equiv="refresh" content="<?= $autoRefresh ?>">
    <?php endif; ?>
</head>
<body class="p-6">
    <div class="max-w-7xl mx-auto">
        <div class="mb-6">
            <h1 class="text-3xl font-bold mb-2">🔄 Jobs Status</h1>
            <p class="muted">Cron heartbeat monitoring - tracks start/ok/error status and execution duration</p>
        </div>

        <div class="card">
            <?php if ($error): ?>
                <div class="error-msg">
                    <strong>Error loading job data:</strong> <?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if (empty($rows)): ?>
                <div class="info-msg">
                    No job heartbeat data available yet. Jobs will appear here once cron scripts start reporting their status.
                </div>
                <div class="muted mt-4">
                    <strong>Expected cron jobs:</strong>
                    <ul class="list-disc ml-6 mt-2 space-y-1">
                        <li>root_process_bash_history.py (hourly, consolidated)</li>
                        <li>process_bash_history (job_runs heartbeat)</li>
                        <li>ingest_bash_history_to_kb:* (subjobs per user)</li>
                        <li>classify_bash_commands / queue_bash_searches / ai_search_summ / ai_notes (subjobs)</li>
                    </ul>
                </div>
            <?php else: ?>
                <h2 class="text-xl font-semibold mb-4">Active Jobs (<?= count($rows) ?>)</h2>
                
                <div class="job-grid">
                    <div class="header-cell">Job</div>
                    <div class="header-cell">Status</div>
                    <div class="header-cell">Last OK</div>
                    <div class="header-cell">Details</div>

                    <?php foreach ($rows as $r):
                        $job = (string)($r['job'] ?? '');
                        $status = (string)($r['last_status'] ?? '');
                        $lastOk = (string)($r['last_ok'] ?? '');
                        $lastStart = (string)($r['last_start'] ?? '');
                        $dur = $r['last_duration_ms'] ?? null;
                        $msg = (string)($r['last_message'] ?? '');

                        $badgeClass = 'badge-unknown';
                        if ($status === 'ok') $badgeClass = 'badge-ok';
                        if ($status === 'error') $badgeClass = 'badge-error';
                        if ($status === 'running') $badgeClass = 'badge-running';

                        $detail = '';
                        if ($lastStart !== '') $detail .= 'start: ' . $lastStart;
                        if ($dur !== null && $dur !== '') {
                            $durDisplay = $dur < 1000 ? (int)$dur . 'ms' : round($dur / 1000, 2) . 's';
                            $detail .= ($detail !== '' ? ' | ' : '') . 'duration: ' . $durDisplay;
                        }
                        if ($msg !== '') $detail .= ($detail !== '' ? ' | ' : '') . $msg;
                    ?>
                        <div class="py-2">
                            <strong><?= htmlspecialchars($job, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                        </div>
                        <div class="py-2">
                            <span class="badge <?= $badgeClass ?>">
                                <?= htmlspecialchars($status !== '' ? $status : 'unknown', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </span>
                        </div>
                        <div class="py-2 muted">
                            <?= htmlspecialchars($lastOk !== '' ? $lastOk : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </div>
                        <div class="py-2 muted" style="white-space: pre-wrap;">
                            <?= htmlspecialchars($detail !== '' ? $detail : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="refresh-controls">
                <div class="flex items-center gap-4">
                    <button onclick="location.reload()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded transition">
                        🔄 Refresh Now
                    </button>
                    
                    <?php if ($autoRefresh > 0): ?>
                        <a href="?" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded transition inline-block">
                            ⏸️ Stop Auto-Refresh
                        </a>
                        <span class="muted">Auto-refreshing every <?= $autoRefresh ?> seconds</span>
                    <?php else: ?>
                        <a href="?refresh=30" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded transition inline-block">
                            ▶️ Auto-Refresh (30s)
                        </a>
                        <a href="?refresh=60" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded transition inline-block">
                            ▶️ Auto-Refresh (60s)
                        </a>
                    <?php endif; ?>
                    
                    <span class="muted ml-auto">Last updated: <?= date('Y-m-d H:i:s') ?></span>
                </div>
            </div>
        </div>

        <div class="mt-6 card">
            <h3 class="text-lg font-semibold mb-2">About Job Status</h3>
            <div class="muted space-y-2 text-sm">
                <p>This dashboard monitors cron job execution via heartbeat entries in the <code>job_runs</code> table.</p>
                <p><strong>Status meanings:</strong></p>
                <ul class="list-disc ml-6 space-y-1">
                    <li><span class="badge badge-ok">ok</span> - Job completed successfully</li>
                    <li><span class="badge badge-running">running</span> - Job is currently executing</li>
                    <li><span class="badge badge-error">error</span> - Job encountered an error</li>
                    <li><span class="badge badge-unknown">unknown</span> - Status not reported</li>
                </ul>
                <p class="mt-4"><strong>Database:</strong> <?= DB_PATH ?></p>
            </div>
        </div>
    </div>
</body>
</html>
