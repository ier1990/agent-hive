<?php
// /web/html/admin/AI/index.php
require_once __DIR__ . '/../../lib/bootstrap.php';
 /*
#5 * * * * /usr/bin/python3 /web/private/scripts/ingest_bash_history_to_kb.py samekhi  >> /web/private/logs/ingest_bash_history_to_kb.log 2>&1
#6 * * * * /usr/bin/python3 /web/private/scripts/ingest_bash_history_to_kb.py root  >> /web/private/logs/ingest_bash_history_to_kb.log 2>&1


*15 * * * * /usr/bin/python3 /web/html/admin/AI/scripts/enqueue.py --queue bash --nameh ingest_bash_history '{"user":"samekhi"}' >> /web/private/logs/mq_enqueue.log 2>&1
*16 * * * * /usr/bin/python3 /web/html/admin/AI/scripts/enqueue.py --queue bash --name ingest_bash_history '{"user":"root"}'   >> /web/private/logs/mq_enqueue.log 2>&1

/*
    if name == "ingest_bash_history":
        user = payload.get("user")
        if user not in ("samekhi", "root"):
            raise RuntimeError(f"Bad user: {user}")

        import subprocess
        subprocess.check_call([
            sys.executable,
            "/web/private/scripts/ingest_bash_history_to_kb.py",
            user
        ])
        return


 */
// Keep it safe
http_response_code(200);
header('Content-Type: text/html; charset=utf-8');

$year = date('Y');

// Paths you mentioned
$compiledDir = __DIR__ . '/../AI_Templates/compiled';
$compiledDirExists = is_dir($compiledDir);

//DB = os.environ.get("MOTHER_QUEUE_DB", "/web/private/db/memory/mother_queue.db")
$MOTHER_QUEUE_DB = '/web/private/db/memory/mother_queue.db';

// Handle queue actions
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$message = '';

if ($action === 'add_job' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $queue = $_POST['queue'] ?? 'default';
    $name = $_POST['name'] ?? '';
    $payload = $_POST['payload'] ?? '{}';
    $priority = (int)($_POST['priority'] ?? 100);
    
    if ($name) {
        $cmd = sprintf(
            'python3 %s/admin/AI/scripts/enqueue.py --queue %s --name %s --payload %s --priority %d 2>&1',
            escapeshellarg('/web/html'),
            escapeshellarg($queue),
            escapeshellarg($name),
            escapeshellarg($payload),
            $priority
        );
        exec($cmd, $out, $ret);
        $message = $ret === 0 ? 'Job added successfully' : 'Error adding job: ' . implode("\n", $out);
    }
}

if ($action === 'delete_job' && isset($_POST['job_id'])) {
    $jobId = $_POST['job_id'];
    if (file_exists($MOTHER_QUEUE_DB)) {
        try {
            $db = new PDO('sqlite:' . $MOTHER_QUEUE_DB);
            $stmt = $db->prepare('DELETE FROM jobs WHERE id = ?');
            $stmt->execute([$jobId]);
            $message = 'Job deleted';
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
        }
    }
}

if ($action === 'retry_job' && isset($_POST['job_id'])) {
    $jobId = $_POST['job_id'];
    if (file_exists($MOTHER_QUEUE_DB)) {
        try {
            $db = new PDO('sqlite:' . $MOTHER_QUEUE_DB);
            $stmt = $db->prepare("UPDATE jobs SET status='queued', attempts=0, locked_by=NULL, locked_until=NULL WHERE id = ?");
            $stmt->execute([$jobId]);
            $message = 'Job reset to queued';
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
        }
    }
}

// Check if worker.py is running
$workerRunning = false;
$workerPid = null;
exec("pgrep -f 'worker.py' 2>/dev/null", $output, $ret);
if ($ret === 0 && !empty($output)) {
    $workerRunning = true;
    $workerPid = implode(', ', $output);
}

// Check if mother_queue.db exists
$queueDbExists = file_exists($MOTHER_QUEUE_DB);
$queueDbSize = $queueDbExists ? filesize($MOTHER_QUEUE_DB) : 0;

// Get queue stats and jobs
$queueStats = ['queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'dead' => 0];
$recentJobs = [];

if ($queueDbExists) {
    try {
        $db = new PDO('sqlite:' . $MOTHER_QUEUE_DB);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Get stats by status
        $result = $db->query("SELECT status, COUNT(*) as cnt FROM jobs GROUP BY status");
        foreach ($result as $row) {
            $queueStats[$row['status']] = $row['cnt'];
        }
        
        // Get recent jobs (limit 20)
        $stmt = $db->query("SELECT * FROM jobs ORDER BY created_at DESC LIMIT 20");
        $recentJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $message = 'DB Error: ' . $e->getMessage();
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function dir_count_files($dir) {
    if (!is_dir($dir)) return 0;
    $c = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if ($f->isFile()) $c++; }
    return $c;
}

$compiledCount = $compiledDirExists ? dir_count_files($compiledDir) : 0;

// Optional: show basic “last modified” info for compiled headers
$compiledMtime = null;
if ($compiledDirExists) {
    $latest = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($compiledDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if ($f->isFile()) $latest = max($latest, $f->getMTime()); }
    if ($latest > 0) $compiledMtime = gmdate('c', $latest);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>AI Control Panel · admin/AI</title>
  <meta name="robots" content="noindex,nofollow" />
  <style>
    :root{
      --bg:#0b0d10; --ink:#e6e6e6; --mut:#9aa3ad; --edge:#1c222b;
      --card:#0f1318; --acc:#7c5cff; --ok:#45d483; --bad:#ff5e66;
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Arial}
    a{color:var(--acc);text-decoration:none}
    a:hover{text-decoration:underline}
    .wrap{max-width:1100px;margin:0 auto;padding:18px}
    .top{display:flex;gap:12px;align-items:flex-end;justify-content:space-between;margin-bottom:14px}
    .title{font-size:20px;font-weight:800}
    .sub{color:var(--mut)}
    .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:12px}
    .card{grid-column:span 6;background:var(--card);border:1px solid var(--edge);border-radius:14px;padding:14px}
    @media (max-width:900px){.card{grid-column:span 12}}
    .card h2{margin:0 0 8px 0;font-size:15px}
    .k{color:var(--mut);display:inline-block;min-width:170px}
    .row{padding:6px 0;border-bottom:1px solid rgba(255,255,255,.06)}
    .row:last-child{border-bottom:0}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid var(--edge);color:var(--mut);font-size:12px}
    .ok{color:var(--ok)} .bad{color:var(--bad)}
    .links a{display:inline-block;margin:6px 10px 0 0}
    .footer{margin-top:14px;color:var(--mut);font-size:12px}
    .full{grid-column:span 12}
    .msg{padding:10px;margin:10px 0;background:var(--card);border:1px solid var(--acc);border-radius:8px;color:var(--acc)}
    .form-row{margin:8px 0}
    .form-row label{display:block;margin-bottom:4px;color:var(--mut);font-size:12px}
    .form-row input,.form-row textarea,.form-row select{width:100%;padding:8px;background:var(--bg);border:1px solid var(--edge);border-radius:6px;color:var(--ink);font:13px/1.4 system-ui}
    .btn{padding:8px 16px;background:var(--acc);border:0;border-radius:6px;color:#fff;cursor:pointer;font:13px system-ui;font-weight:600}
    .btn:hover{opacity:0.9}
    .btn-sm{padding:4px 10px;font-size:11px}
    .btn-danger{background:var(--bad)}
    .btn-success{background:var(--ok)}
    table{width:100%;border-collapse:collapse;font-size:12px;margin-top:10px}
    table th{text-align:left;padding:8px;border-bottom:2px solid var(--edge);color:var(--mut);font-weight:600}
    table td{padding:8px;border-bottom:1px solid rgba(255,255,255,.04)}
    table tr:hover{background:rgba(255,255,255,.02)}
    .status-queued{color:#ffa500}
    .status-running{color:#4a9eff}
    .status-done{color:var(--ok)}
    .status-failed{color:#ff9800}
    .status-dead{color:var(--bad)}
    .truncate{max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .mono{font-family:monospace;font-size:11px}
  </style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <div class="title">Scripts AI Control Panel <span class="pill">Skynet (local)</span></div>
      <div class="sub">Landing page for bringing AI to live use. Scripts are the heartbeat.</div>
    </div>
    <div class="sub">© <?=h($year)?></div>
  </div>

<?php if ($message): ?>
  <div class="msg"><?=h($message)?></div>
<?php endif; ?>

<div>
    We Need to be the Scripts AI Control Panel.<br>
    Scripts are TheHeartBeat of the AI Functionality.<br>
    It needs to be a landing page for bringing the AI to Live Use.<br>
    It should link to all the other Local AI's like a Skynet.<br>
   Current Editors/Apps for Local AI Functionality:<br>
    <a href="http://192.168.0.142/admin/codew_config.php">CodeW Config</a> - 
    <a href="http://192.168.0.142/admin/admin_AI_Setup.php">AI Setup</a>
</div>
  
<div class="grid" style="margin-top:16px">
    <?php
    // Render various cards here
    ?>
   </div>

  <div class="grid">
    <div class="card">
      <h2>AI Sub-Apps</h2>
      <div class="links">
        <a href="/admin/AI_Templates/index.php">AI Templates</a>
        <a href="/admin/codewalker.php">CodeWalker</a>
        <a href="/admin/notes.php">Notes</a>
        <a href="/v1/notes/">/v1/notes (legacy)</a>
      </div>
      <div class="sub" style="margin-top:8px">
        Eventually: move <code>/v1/notes/</code> → <code>/admin/AI/notes/</code>.
      </div>
    </div>

    <div class="card">
      <h2>Facts / Status</h2>

      <div class="row"><span class="k">CodeWalker queue</span>
        <span class="ok">Active</span> <span class="pill">every 15 mins</span>
      </div>

      <div class="row"><span class="k">Purpose</span>
        Rewrite/summarize every file in configured scan paths (<code>$scan_path</code>).
      </div>

      <div class="row"><span class="k">Next integration</span>
        CodeWalker should use <span class="pill">AI_Template</span> to compile prompts/templates.
      </div>

      <div class="row"><span class="k">Compiled headers dir</span>
        <code><?=h($compiledDir)?></code>
        <?php if ($compiledDirExists): ?>
          <span class="ok">exists</span>
        <?php else: ?>
          <span class="bad">missing</span>
        <?php endif; ?>
      </div>

      <div class="row"><span class="k">Compiled headers count</span>
        <?=h($compiledCount)?>
      </div>

      <div class="row"><span class="k">Latest compiled mtime</span>
        <?= $compiledMtime ? h($compiledMtime) : '<span class="sub">n/a</span>' ?>
      </div>
    </div>

    <div class="card">
      <h2>Bring AI Live</h2>
      <div class="links">
        <a href="/admin/AI_Templates/test_ai_template.php">Test compile (AI Template)</a>
        <a href="/admin/AI_Templates/index.php">Edit templates</a>
        <a href="/admin/codewalker_cli.php">Run CodeWalker (CLI UI)</a>
      </div>
      <div class="sub" style="margin-top:8px">
        Next: add “tail logs” links and a compiled-json browser.
      </div>
    </div>

    <div class="card">
      <h2>Mother Queue System</h2>
      
      <div class="row"><span class="k">Worker Status</span>
        <?php if ($workerRunning): ?>
          <span class="ok">Running</span> <span class="pill">PID: <?=h($workerPid)?></span>
        <?php else: ?>
          <span class="bad">Not Running</span>
        <?php endif; ?>
      </div>

      <div class="row"><span class="k">Queue Database</span>
        <code><?=h($MOTHER_QUEUE_DB)?></code>
        <?php if ($queueDbExists): ?>
          <span class="ok">exists</span> <span class="pill"><?=h(number_format($queueDbSize))?> bytes</span>
        <?php else: ?>
          <span class="bad">missing</span>
        <?php endif; ?>
      </div>

      <div class="row"><span class="k">Queue Stats</span>
        <span class="status-queued">Queued: <?=$queueStats['queued']?></span> |
        <span class="status-running">Running: <?=$queueStats['running']?></span> |
        <span class="status-done">Done: <?=$queueStats['done']?></span> |
        <span class="status-failed">Failed: <?=$queueStats['failed']?></span> |
        <span class="status-dead">Dead: <?=$queueStats['dead']?></span>
      </div>

      <div class="row"><span class="k">Scripts Location</span>
        <code>/web/html/admin/AI/scripts/</code>
      </div>

      <div class="row"><span class="k">Migration Path</span>
        → <code>/web/private/scripts/ai/</code> <span class="pill">future</span>
      </div>

      <div class="sub" style="margin-top:8px">
        <strong>Components:</strong><br>
        • <code>mq.py</code> - queue library (creates DB if missing)<br>
        • <code>worker.py</code> - job processor (reads from queue)<br>
        • <code>enqueue.py</code> - job submitter (writes to queue)
      </div>
    </div>

    <div class="card full">
      <h2>Add New Job to Queue</h2>
      <form method="post" action="">
        <input type="hidden" name="action" value="add_job">
        <div style="display:grid;grid-template-columns:1fr 2fr 1fr auto;gap:10px;align-items:end">
          <div class="form-row" style="margin:0">
            <label>Queue Name</label>
            <input type="text" name="queue" value="default" placeholder="default">
          </div>
          <div class="form-row" style="margin:0">
            <label>Job Name</label>
            <input type="text" name="name" placeholder="my-task" required>
          </div>
          <div class="form-row" style="margin:0">
            <label>Priority</label>
            <input type="number" name="priority" value="100" placeholder="100">
          </div>
          <div>
            <button type="submit" class="btn">Enqueue Job</button>
          </div>
        </div>
        <div class="form-row">
          <label>Payload (JSON)</label>
          <textarea name="payload" rows="2" placeholder='{"key": "value"}'>{}</textarea>
        </div>
      </form>
    </div>

    <div class="card full">
      <h2>Worker Logs</h2>
      <?php
        $logFiles = [
            '/var/www/private/logs/mq_worker.log',
            '/web/private/logs/mq_worker.log'
        ];
        $logFound = false;
        foreach ($logFiles as $logPath) {
            if (file_exists($logPath)) {
                $logFound = true;
                echo '<div class="sub">Log: <code>' . h($logPath) . '</code></div>';
                echo '<pre style="background:var(--bg);padding:10px;border:1px solid var(--edge);border-radius:6px;max-height:300px;overflow-y:auto;font-size:11px;line-height:1.4">';
                echo h(shell_exec("tail -n 50 " . escapeshellarg($logPath) . " 2>&1"));
                echo '</pre>';
                break;
            }
        }
        if (!$logFound) {
            echo '<div class="sub">No worker logs found. Check: ';
            foreach ($logFiles as $lf) echo '<code>' . h($lf) . '</code> ';
            echo '</div>';
            echo '<div class="sub" style="margin-top:8px">To create logs, run worker with: <code>python3 worker.py default 2>&1 | tee /tmp/mother_queue_worker.log</code></div>';
        }
      ?>
    </div>

    <div class="card full">
      <h2>Recent Jobs (last 20)</h2>
      <?php if (empty($recentJobs)): ?>
        <div class="sub">No jobs in queue</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Queue</th>
              <th>Name</th>
              <th>Status</th>
              <th>Priority</th>
              <th>Attempts</th>
              <th>Created</th>
              <th>Payload</th>
              <th>Error</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentJobs as $job): ?>
              <tr>
                <td class="mono truncate" title="<?=h($job['id'])?>"><?=h(substr($job['id'], 0, 8))?></td>
                <td><?=h($job['queue'])?></td>
                <td><?=h($job['name'])?></td>
                <td class="status-<?=h($job['status'])?>"><?=h($job['status'])?></td>
                <td><?=h($job['priority'])?></td>
                <td><?=h($job['attempts'])?>/<?=h($job['max_attempts'])?></td>
                <td class="mono"><?=h(substr($job['created_at'], 0, 19))?></td>
                <td class="truncate mono" title="<?=h($job['payload_json'])?>"><?=h(substr($job['payload_json'], 0, 40))?></td>
                <td style="max-width:300px">
                  <?php if ($job['last_error']): ?>
                    <details style="cursor:pointer">
                      <summary style="color:var(--bad)"><?=h(substr($job['last_error'], 0, 50))?><?=strlen($job['last_error']) > 50 ? '...' : ''?></summary>
                      <pre style="margin:4px 0;padding:6px;background:var(--bg);border:1px solid var(--edge);border-radius:4px;font-size:10px;white-space:pre-wrap;word-break:break-word"><?=h($job['last_error'])?></pre>
                    </details>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (in_array($job['status'], ['failed', 'dead'])): ?>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="action" value="retry_job">
                      <input type="hidden" name="job_id" value="<?=h($job['id'])?>">
                      <button type="submit" class="btn btn-sm btn-success">Retry</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('Delete this job?')">
                    <input type="hidden" name="action" value="delete_job">
                    <input type="hidden" name="job_id" value="<?=h($job['id'])?>">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Roadmap (drain the old files)</h2>
      <div class="row"><span class="k">Drain CodeWalker.php</span> Move logic into <code>/admin/AI/</code> and shared <code>/lib</code>.</div>
      <div class="row"><span class="k">Centralize templates</span> All prompt content comes from AI Templates.</div>
      <div class="row"><span class="k">Save compiled JSON</span> CodeWalker writes compiled headers to <code>/admin/AI_Templates/compiled/</code>.</div>
    </div>
  </div>

  <div class="footer">
    Tip: keep this panel non-indexed and behind whatever guard you use for admin.
  </div>
</div>
</body>
</html>


<pre>
    We Need to be the Scripts AI Control Panel.
    Scripts are TheHeartBeat of the AI Functionality.
    It needs to be a landing page for bringing the AI to Live Use.
    It should link to all the other Local AI's like a Skynet.
    
    Current Editors/Apps for Local AI Functionality:
    http://192.168.0.142/admin/codew_config.php
    http://192.168.0.142/v1/notes/?view=ai_setup
    

</pre>

<pre>
We need to finish draining the functionality out of CodeWalker.php and into admin/AI/.

We need a clean “AI Control Panel” landing page at admin/AI/index.php that links to 
the various AI sub-apps (Headers, CodeWalker runner, Notes pipeline, etc).

/v1/notes/ should be moved to admin/AI/notes/ eventually too.

FATCS:
1) codewalker alreadt has a Queue running every 15 mins.
2) Its purpose is to rewrite/summarize every file in set of folders (see $scan_path).
3) It shall next use AI_Template to compile prompts/templates.
4) The compiled headers will be saved as JSON files in admin/AI_Templates/compiled/


</pre>

<a href="http://192.168.0.142/admin/AI/codewalker.png">
<img src ="codewalker.png" alt="Samekhi AI Logo" width="1000" />
</a>

<pre>
    Here’s the current tree under admin/:    
    
root@samekhi3090:/web/html/admin# tree.
├── admin_notes.php
├── AI
│   ├── index.php
│   └── README.md
├── AI_Template
│   ├── AI_Template.php
│   ├── CLASS.md
│   ├── index.php
│   ├── README.md
│   └── test_ai_template.php
├── codewalker_cli.php
├── codewalker.php
├── codew_backup.php
├── codew_config.php
├── codew_prompts.php
├── cron.daily
│   ├── save_bash_history_threaded.py
│   └── save_bash_history_threaded.sh
├── cron.hourly
├── htaccess_tester.php
├── index.php
├── lib
│   ├── codewalker_helpers.php
│   ├── codewalker_runner.php
│   └── codewalker_settings.php
├── notes.php
└── scripts
    ├── ai_search_summ.py
    ├── download-ytdlp-mp3.sh
    ├── mysql_test.py
    ├── __pycache__
    │   └── ai_search_summ.cpython-312.pyc
    └── vllm_ctl.py
    </pre>


    <hr>
    <pre>
        That tree actually reads way nicer with admin/AI/ as the “landing zone.” Good move.

A couple suggestions so it stays clean as you grow (and so your AI Template work doesn’t get “stranded” in admin/AI_Templates/ forever):

1) Make admin/AI/ the hub, and keep features as sub-apps

I’d treat admin/AI/ like the “AI Control Panel”:

admin/AI/index.php = links/status

admin/AI/README.md = what this section is

Then move/house AI sub-apps under it over time:

admin/AI/headers/ (your AI Templates app)

admin/AI/codewalker/

admin/AI/notes/

admin/AI/scripts/ (if you want admin-visible script runners)

Right now you have:

AI_Template/ (fine for now)

codewalker* scattered at root

No need to reorganize today, but the “end shape” will be calmer.

2) Add one shared include root for admin AI apps

Since you’re about to reuse the compiler everywhere, aim for:

/web/html/lib/ai_header/AI_Template.php (shared lib)

admin/AI/headers/index.php uses the lib

That prevents “admin app owns the library” (which always becomes painful).

3) Security Handles by bootstrap


4) Put a “map” in admin/AI/index.php

Even a simple list makes it feel intentional:

AI Templates (Templates → compiled → model-ready)

CodeWalker (refactor runner)

Notes pipeline (search digests, bash history KB)

5) Tiny naming tweak that will help later

Rename folder from AI_Template → headers eventually (short, consistent, easier URLs):

/admin/AI/headers/

Not urgent, but it keeps paths sane.

Quick check (since you’re on PHP 7.3 in Jville)

Just make sure AI_Template.php in this tree no longer has typed properties and your test_ai_template.php runs with:

php /web/html/admin/AI_Templates/test_ai_template.php


If that passes locally, you’re safe to deploy the same to Jville.

If you want, paste your admin/AI/index.php (even if tiny) and I’ll make it a clean “AI Control Panel” page that links to Headers/CodeWalker/Notes with your dark-field styling preferences.
</pre>
