<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/auth/auth.php';

auth_require_admin();

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$files = [
    ['name' => 'agent.py', 'desc' => 'CLI entrypoint and launcher wiring'],
    ['name' => 'agent_boot.md', 'desc' => 'Boot prompt and tool guidance'],
    ['name' => 'agent_common.py', 'desc' => 'Shared paths, JSON helpers, TTY helpers'],
    ['name' => 'agent_config.py', 'desc' => 'PHP-shared AI config and tool settings loader'],
    ['name' => 'agent_runtime.py', 'desc' => 'AliveAgent runtime, model calls, and tool execution'],
    ['name' => 'agent_shell.py', 'desc' => 'Interactive shell banner and slash commands'],
    ['name' => 'README.md', 'desc' => 'Project notes for this agent area'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AgentHive AI Agent</title>
  <style>
    :root {
      color-scheme: light;
      --bg: #f4f7fb;
      --card: #ffffff;
      --ink: #132033;
      --muted: #5b6b7f;
      --line: #d8e0ea;
      --accent: #0f766e;
      --accent-2: #0b5fff;
      --shadow: 0 14px 40px rgba(15, 23, 42, 0.08);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
      background: radial-gradient(circle at top left, #e0f2fe 0, #f4f7fb 40%, #eef4ff 100%);
      color: var(--ink);
    }
    .wrap {
      max-width: 980px;
      margin: 0 auto;
      padding: 32px 20px 48px;
    }
    .hero, .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 18px;
      box-shadow: var(--shadow);
    }
    .hero {
      padding: 28px;
      margin-bottom: 20px;
    }
    .eyebrow {
      color: var(--accent);
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      margin-bottom: 10px;
    }
    h1 {
      margin: 0 0 12px;
      font-size: 34px;
      line-height: 1.1;
    }
    p {
      margin: 0;
      color: var(--muted);
      line-height: 1.6;
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 16px;
      margin-top: 18px;
    }
    .pill {
      display: inline-block;
      padding: 8px 12px;
      border-radius: 999px;
      background: #ecfeff;
      color: var(--accent);
      font-weight: 600;
      margin: 6px 8px 0 0;
    }
    .card {
      padding: 22px;
      margin-top: 18px;
    }
    h2 {
      margin: 0 0 12px;
      font-size: 18px;
    }
    ul {
      margin: 0;
      padding-left: 20px;
      color: var(--muted);
    }
    li { margin: 10px 0; }
    code {
      background: #eff4ff;
      border-radius: 6px;
      padding: 2px 6px;
      color: var(--accent-2);
    }
    .file-list {
      display: grid;
      gap: 12px;
    }
    .file-row {
      padding: 14px 16px;
      border: 1px solid var(--line);
      border-radius: 14px;
      background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    }
    .file-row strong {
      display: block;
      margin-bottom: 4px;
    }
    .muted {
      color: var(--muted);
      font-size: 14px;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <section class="hero">
      <div class="eyebrow">Admin / AI</div>
      <h1>AgentHive AI Agent</h1>
      <p>
        This directory now holds the new split Python agent stack: shared config loading,
        runtime tools, shell UX, and the boot prompt. It follows the active PHP AI setup
        when CLI overrides are not provided, and search tooling is configured through
        <code>/web/private/agent_tools.json</code> with <code>SEARX_URL</code> support.
      </p>
      <div class="grid">
        <div class="pill">Shared PHP AI settings</div>
        <div class="pill">SearXNG search tool</div>
        <div class="pill">TTY shell banner + slash commands</div>
        <div class="pill">Same-directory Python modules</div>
      </div>
    </section>

    <section class="card">
      <h2>Directory Files</h2>
      <div class="file-list">
        <?php foreach ($files as $file): ?>
          <div class="file-row">
            <strong><?= h($file['name']) ?></strong>
            <div class="muted"><?= h($file['desc']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card">
      <h2>Quick Notes</h2>
      <ul>
        <li>Boot prompt lives in <code>admin/AI/agent_boot.md</code>.</li>
        <li>Runtime tool settings live in <code>/web/private/agent_tools.json</code>.</li>
        <li>Search defaults come from <code>SEARX_URL</code> when not overridden.</li>
        <li>The interactive shell supports <code>/help</code>, <code>/status</code>, <code>/debug</code>, <code>/models</code>, and <code>/search</code>.</li>
      </ul>
    </section>
  </div>
</body>
</html>
