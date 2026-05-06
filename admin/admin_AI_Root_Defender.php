<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
auth_require_admin();

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$baseDir = __DIR__ . '/AI_Root_Defender';
$readmePath = $baseDir . '/README.md';
$quickstartPath = $baseDir . '/QUICKSTART.md';
$interpreterPlanPath = $baseDir . '/Interpreter/plan.md';
$interpreterIdealsPath = $baseDir . '/Interpreter/ideals.md';

$hasReadme = is_file($readmePath);
$hasQuickstart = is_file($quickstartPath);
$hasInterpreterPlan = is_file($interpreterPlanPath);
$hasInterpreterIdeals = is_file($interpreterIdealsPath);

$cssVersion = @filemtime(__DIR__ . '/lib/admin_dark.css');
if (!$cssVersion) $cssVersion = time();
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AI Root Defender</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="lib/admin_dark.css?v=<?php echo h((string)$cssVersion); ?>">
</head>
<body class="bg-gray-50 min-h-screen">
  <div class="bg-gradient-to-r from-slate-800 to-indigo-900 text-white py-5 mb-6">
    <div class="container mx-auto px-4 flex items-start justify-between gap-4 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">🛡️ AI Root Defender</h1>
        <p class="text-sm opacity-90">Local-first guarded terminal AI with Interpreter planning notes</p>
      </div>
      <div class="flex gap-2 flex-wrap">
        <a href="index.php" class="bg-white/15 hover:bg-white/25 text-white px-4 py-2 rounded-md text-sm">Admin Home</a>
      </div>
    </div>
  </div>

  <div class="container mx-auto px-4 max-w-6xl space-y-6">
    <div class="bg-white rounded-lg shadow p-5">
      <h2 class="text-lg font-semibold text-gray-800 mb-2">What It Is</h2>
      <p class="text-sm text-gray-600">
        AI Root Defender is the guarded terminal harness under
        <code>/web/html/admin/AI_Root_Defender</code>. It is meant for local, human-approved shell diagnosis work,
        and the new <code>Interpreter/</code> docs capture the next design direction for a reusable gatekeeper core.
      </p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="bg-white rounded-lg shadow p-5">
        <h2 class="text-lg font-semibold text-gray-800 mb-3">Terminal Quick Start</h2>
        <div class="text-xs text-gray-500 mb-3">Run from a shell on the server:</div>
        <pre class="rounded-md p-4 overflow-x-auto"><code>cd /web/html/admin/AI_Root_Defender
./bin/install.sh
source ./activate.sh
python3 agent_bash.py</code></pre>

        <div class="text-xs text-gray-500 mt-4 mb-2">One-shot non-interactive example:</div>
        <pre class="rounded-md p-4 overflow-x-auto"><code>cd /web/html/admin/AI_Root_Defender
source ./activate.sh
python3 agent_bash.py --non-interactive \
  --provider 0 \
  --prompt "Check recent Apache and MySQL errors and summarize the likely issue" \
  --json</code></pre>
      </div>

      <div class="bg-white rounded-lg shadow p-5">
        <h2 class="text-lg font-semibold text-gray-800 mb-3">Useful Shell Commands</h2>
        <ul class="space-y-2 text-sm text-gray-600">
          <li><code>/help</code> shows built-in commands.</li>
          <li><code>/status</code> shows current provider and session state.</li>
          <li><code>/provider</code> switches provider profiles.</li>
          <li><code>/monitor-mode</code> manages telemetry visibility.</li>
          <li><code>/compose</code> builds editor-driven context.</li>
          <li><code>/memory</code> and <code>/notes</code> inspect saved working context.</li>
          <li><code>/bh pending</code> and <code>/bh recent</code> inspect command-governance history.</li>
        </ul>

        <div class="mt-4 text-xs text-gray-500">
          Working directory:
          <code>/web/html/admin/AI_Root_Defender</code>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="bg-white rounded-lg shadow p-5">
        <h2 class="text-base font-semibold text-gray-800 mb-2">Main Docs</h2>
        <ul class="space-y-2 text-sm">
          <?php if ($hasReadme): ?>
            <li><a class="text-indigo-600 hover:text-indigo-700" href="AI_Root_Defender/README.md">README.md</a></li>
          <?php endif; ?>
          <?php if ($hasQuickstart): ?>
            <li><a class="text-indigo-600 hover:text-indigo-700" href="AI_Root_Defender/QUICKSTART.md">QUICKSTART.md</a></li>
          <?php endif; ?>
          <li><a class="text-indigo-600 hover:text-indigo-700" href="AI_Root_Defender/agent_bash.py">agent_bash.py</a></li>
          <li><a class="text-indigo-600 hover:text-indigo-700" href="AI_Root_Defender/bin/install.sh">bin/install.sh</a></li>
        </ul>
      </div>

      <div class="bg-white rounded-lg shadow p-5">
        <h2 class="text-base font-semibold text-gray-800 mb-2">Interpreter Planning</h2>
        <ul class="space-y-2 text-sm">
          <?php if ($hasInterpreterPlan): ?>
            <li><a class="text-indigo-600 hover:text-indigo-700" href="AI_Root_Defender/Interpreter/plan.md">Interpreter/plan.md</a></li>
          <?php endif; ?>
          <?php if ($hasInterpreterIdeals): ?>
            <li><a class="text-indigo-600 hover:text-indigo-700" href="AI_Root_Defender/Interpreter/ideals.md">Interpreter/ideals.md</a></li>
          <?php endif; ?>
        </ul>
        <p class="text-xs text-gray-500 mt-3">
          These are design docs, not a separate runtime yet.
        </p>
      </div>

      <div class="bg-white rounded-lg shadow p-5">
        <h2 class="text-base font-semibold text-gray-800 mb-2">Current Direction</h2>
        <ul class="space-y-2 text-sm text-gray-600">
          <li>Keep one guarded terminal harness.</li>
          <li>Move policy enforcement into a smaller Interpreter core.</li>
          <li>Let modules and boot prompts specialize behavior.</li>
          <li>Keep dangerous shell capability behind review gates.</li>
        </ul>
      </div>
    </div>

    <div class="bg-white rounded-lg shadow p-5">
      <h2 class="text-base font-semibold text-gray-800 mb-2">Why This Page Exists</h2>
      <p class="text-sm text-gray-600">
        The admin console only auto-discovers top-level <code>admin_*.php</code> pages. Without this page, AI Root Defender
        and the Interpreter planning docs are easy to miss even though they are already in the repo.
      </p>
    </div>
  </div>
</body>
</html>
