<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/auth/auth.php';

auth_require_admin();

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$files = [
    ['name' => 'agent.py', 'desc' => 'CLI entrypoint that loads config, builds AliveAgent, and runs shell or single-shot mode'],
    ['name' => 'agent_boot.md', 'desc' => 'System prompt with the strict JSON contract and allowed built-in tools'],
    ['name' => 'agent_common.py', 'desc' => 'Shared paths, JSON helpers, boot prompt loader, and TTY helpers'],
    ['name' => 'agent_config.py', 'desc' => 'Resolves defaults, PHP-shared AI settings, private overrides, and tool settings'],
    ['name' => 'agent_runtime.py', 'desc' => 'AliveAgent runtime, preloaded context, tools, and think -> tool -> think loop'],
    ['name' => 'agent_shell.py', 'desc' => 'Interactive shell banner, slash commands, history, and startup greeting support'],
    ['name' => 'default_agent.json', 'desc' => 'Versioned default profile template including startup greeting defaults'],
    ['name' => 'README.md', 'desc' => 'Up-to-date reference notes for this agent area'],
    ['name' => 'AI_tools.md', 'desc' => 'Toolsmith contract and tool lifecycle notes'],
    ['name' => 'AI_Hive_concept.md', 'desc' => 'Layered AgentHive architecture and worker/reviewer concept'],
    ['name' => 'mc.md', 'desc' => 'Draft design notes for a future /mc file browser'],
];

$doc_links = [
    ['name' => 'README.md', 'path' => '/admin/AI/README.md', 'desc' => 'Main agent reference and config/profile docs'],
    ['name' => 'AI_tools.md', 'path' => '/admin/AI/AI_tools.md', 'desc' => 'Toolsmith contract, lifecycle, and safety rules'],
    ['name' => 'AI_Hive_concept.md', 'path' => '/admin/AI/AI_Hive_concept.md', 'desc' => 'High-level architecture, worker pattern, and step budgets'],
    ['name' => 'agent_boot.md', 'path' => '/admin/AI/agent_boot.md', 'desc' => 'Current boot/system prompt contract'],
    ['name' => 'mc.md', 'path' => '/admin/AI/mc.md', 'desc' => 'Midnight Commander style browser draft'],
];

$profile_examples = [
    ['name' => 'interactive_shell.example.json', 'path' => '/admin/AI/profiles/interactive_shell.example.json', 'desc' => 'Starter interactive shell profile'],
    ['name' => 'apache_log_worker.example.json', 'path' => '/admin/AI/profiles/apache_log_worker.example.json', 'desc' => 'Cron-safe Apache worker example'],
    ['name' => 'reviewer_agent.example.json', 'path' => '/admin/AI/profiles/reviewer_agent.example.json', 'desc' => 'Reviewer/supervisor profile example'],
    ['name' => 'toolsmith_agent.example.json', 'path' => '/admin/AI/profiles/toolsmith_agent.example.json', 'desc' => 'Toolsmith review and drafting example'],
    ['name' => 'openai_hosted.example.json', 'path' => '/admin/AI/profiles/openai_hosted.example.json', 'desc' => 'Hosted OpenAI profile using `OPENAI_API_KEY`'],
    ['name' => 'lmstudio_local.example.json', 'path' => '/admin/AI/profiles/lmstudio_local.example.json', 'desc' => 'Local LM Studio profile using `LLM_API_KEY`'],
];

$config_precedence = [
    'Built-in defaults from agent_config.py',
    'Versioned defaults from admin/AI/default_agent.json',
    'Shared PHP AI settings from /web/private/db/codewalker_settings.db',
    'Optional private overrides from /web/private/agent.json',
    'Optional profile override from --config-file',
    'Direct CLI overrides like --model, --base-url, and --boot-prompt-path',
];

$runtime_files = [
    'Agent profile template' => 'admin/AI/default_agent.json',
    'Private agent override' => '/web/private/agent.json',
    'Example profiles' => 'admin/AI/profiles/',
    'Tool settings' => '/web/private/agent_tools.json',
    'Shared AI settings DB' => '/web/private/db/codewalker_settings.db',
    'Approved admin tools DB' => '/web/private/db/agent_tools.db',
    'Agent memory DB' => '/web/private/db/memory/agent_ai_memory.db',
    'Default notes DB' => '/web/private/db/memory/human_notes.db',
    'Shell history file' => '/web/private/logs/agent_shell_history.log',
    'Composer/editor prompt archive' => '/web/private/logs/agent_composer/',
    'Temp execution directory' => '/web/private/tmp',
];

$built_in_tools = [
    'memory_search',
    'memory_write',
    'notes_search',
    'code_search',
    'search',
    'agent_tool_list',
    'agent_tool_run',
    'read_code',
];

$shell_commands = [
    '/help',
    '/hello',
    '/paste',
    '/compose',
    '/edit-paste',
    '/edit-paste on',
    '/edit-paste off',
    '/read PATH',
    '/load PATH',
    '/session',
    '/sessions-history',
    '/sessions-history on',
    '/sessions-history off',
    '/status',
    '/debug',
    '/debug on',
    '/debug off',
    '/models',
    '/search',
    '/memory',
    '/mem list',
    '/memory list',
    '/tools',
    '/tools list',
    '/clear',
    '/exit',
    '/quit',
];

$cli_flags = [
    '--config-file',
    '--query',
    '--list-models',
    '--model',
    '--base-url',
    '--api-key',
    '--notes-db',
    '--code-root',
    '--boot-prompt-path',
    '--tool-settings-path',
    '--max-steps',
    '--temperature',
    '--output-mode',
    '--interactive',
    '--no-interactive',
    '--debug',
    '--no-debug',
];

$request_flow = [
    'agent.py loads the resolved profile and tool settings, then constructs AliveAgent.',
    'agent_runtime.py preloads notes and code context, and optionally memory context, before the first model call.',
    'agent_boot.md is loaded fresh as the system prompt for each run.',
    'The model must reply with strict JSON: either a tool call or a final response.',
    'Built-in tools and approved DB-backed tools run through the Python bridge, then their results are fed back into the next model step.',
    'The loop ends on a final response, max-step limit, or repeated tool-call loop detection.',
];

$quick_start = [
    'Check the active backend and model with /status.',
    'Use /models if you need to confirm which models the current backend exposes.',
    'Ask a normal request and let the agent use notes, code search, and read_code before web search.',
    'Use /read PATH or /load PATH when you want to load a markdown or code file into one prompt instead of pasting it.',
    'Use /compose when you already know you want to build or clean up a longer prompt in your editor.',
    'Turn on /edit-paste if you want large multiline pastes to open in your editor before they are sent.',
    'Use /hello to test the startup greeting path without entering the full tool loop.',
    'Edit agent_boot.md first when behavior tuning is mostly prompt-related rather than runtime-related.',
];

$profile_fields = [
    'profile_name',
    'task_name',
    'description',
    'mode',
    'model',
    'base_url',
    'api_key',
    'api_key_env',
    'max_steps',
    'step_budget',
    'temperature',
    'startup_greeting_enabled',
    'interactive',
    'output_mode',
    'write_report',
    'report_type',
    'report_target',
    'notes_db',
    'code_root',
    'boot_prompt_path',
    'tool_settings_path',
    'memory_enabled',
    'allowed_tools',
    'default_query',
    'task_prompt',
    'timeout_seconds',
    'edit_paste_enabled',
    'edit_paste_min_lines',
    'editor_command',
    'editor_timeout_seconds',
    'edit_paste_strip_comment_lines',
];
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AgentHive AI Agent</title>
  <style>
    :root {
      color-scheme: dark;
      --bg: #07111f;
      --card: #0f172a;
      --ink: #e5eefb;
      --muted: #94a3b8;
      --line: #243247;
      --accent: #22c55e;
      --accent-2: #60a5fa;
      --soft: #0d1b2a;
      --soft-2: #0c1629;
      --gold: #f59e0b;
      --shadow: 0 20px 48px rgba(0, 0, 0, 0.4);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
      background: radial-gradient(circle at top left, #12304f 0, #07111f 40%, #0b1630 100%);
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
      position: relative;
      overflow: hidden;
    }
    .hero:before {
      content: "";
      position: absolute;
      inset: auto -60px -80px auto;
      width: 220px;
      height: 220px;
      border-radius: 999px;
      background: radial-gradient(circle, rgba(11, 95, 255, 0.18) 0%, rgba(11, 95, 255, 0) 70%);
      pointer-events: none;
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
    .lead {
      max-width: 780px;
      font-size: 17px;
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
      background: #0c2130;
      color: var(--accent);
      font-weight: 600;
      margin: 6px 8px 0 0;
      border: 1px solid #1e3a4a;
    }
    .card {
      padding: 22px;
      margin-top: 18px;
    }
    .split {
      display: grid;
      grid-template-columns: 1.2fr 0.8fr;
      gap: 18px;
      align-items: start;
    }
    h2 {
      margin: 0 0 12px;
      font-size: 18px;
    }
    h3 {
      margin: 0 0 10px;
      font-size: 15px;
      color: var(--ink);
    }
    ul {
      margin: 0;
      padding-left: 20px;
      color: var(--muted);
    }
    li { margin: 10px 0; }
    code {
      background: rgba(96, 165, 250, 0.12);
      border-radius: 6px;
      padding: 2px 6px;
      color: #bfdbfe;
    }
    pre {
      margin: 0;
      white-space: pre-wrap;
      word-break: break-word;
      background: #020617;
      color: #dbeafe;
      border-radius: 14px;
      padding: 16px;
      font-size: 13px;
      line-height: 1.55;
      overflow: auto;
    }
    .file-list {
      display: grid;
      gap: 12px;
    }
    .file-row {
      padding: 14px 16px;
      border: 1px solid var(--line);
      border-radius: 14px;
      background: linear-gradient(180deg, #0f172a 0%, #101a2f 100%);
    }
    .file-row strong {
      display: block;
      margin-bottom: 4px;
    }
    .muted {
      color: var(--muted);
      font-size: 14px;
    }
    .mini-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 12px;
    }
    .mini-card {
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 16px;
      background: linear-gradient(180deg, var(--soft) 0%, #111827 100%);
      color: var(--ink);
    }
    .mini-card.alt {
      background: linear-gradient(180deg, var(--soft-2) 0%, #111827 100%);
    }
    .mini-card.warn {
      background: linear-gradient(180deg, #24180a 0%, #111827 100%);
      border-color: #7c5a1a;
    }
    .mini-card .muted,
    .mini-card .subtext {
      color: var(--muted);
    }
    .mini-card code {
      background: rgba(11, 95, 255, 0.08);
      color: #1447d1;
    }
    .kicker {
      margin-bottom: 8px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--accent);
    }
    .step-list {
      counter-reset: step;
      display: grid;
      gap: 12px;
    }
    .step {
      display: grid;
      grid-template-columns: 42px 1fr;
      gap: 12px;
      align-items: start;
      padding: 14px;
      border: 1px solid var(--line);
      border-radius: 14px;
      background: linear-gradient(180deg, #0f172a 0%, #111827 100%);
    }
    .step-num {
      width: 42px;
      height: 42px;
      border-radius: 12px;
      background: linear-gradient(135deg, var(--accent-2), var(--accent));
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      box-shadow: 0 10px 20px rgba(15, 118, 110, 0.18);
    }
    .chips {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    .chip {
      padding: 7px 10px;
      border-radius: 999px;
      border: 1px solid var(--line);
      background: #0f172a;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.2;
    }
    .subtext {
      margin-top: 10px;
      font-size: 14px;
      color: var(--muted);
    }
    @media (max-width: 760px) {
      .split {
        grid-template-columns: 1fr;
      }
      h1 {
        font-size: 28px;
      }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <section class="hero">
      <div class="eyebrow">Admin / AI</div>
      <h1>AgentHive AI Agent</h1>
      <p class="lead">
        This page is the operator guide for the split Python agent stack behind the admin AI shell.
        It now mirrors the more up-to-date README and adds a practical walkthrough for how requests
        flow from shell input to tool calls and final answers.
      </p>
      <div class="grid">
        <div class="pill">Shared PHP AI settings</div>
        <div class="pill">Preloaded notes + code context</div>
        <div class="pill">SearXNG search tool</div>
        <div class="pill">TTY shell banner + slash commands</div>
        <div class="pill">Startup greeting warmup</div>
      </div>
    </section>

    <section class="card">
      <h2>Quick Start</h2>
      <div class="split">
        <div>
          <div class="step-list">
            <?php foreach ($quick_start as $index => $step): ?>
              <div class="step">
                <div class="step-num"><?= h((string)($index + 1)) ?></div>
                <div class="muted"><?= h($step) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="mini-grid">
          <div class="mini-card">
            <div class="kicker">Best First Check</div>
            <div><code>/status</code></div>
            <div class="subtext">Shows backend, provider, model, base URL, code root, notes DB, boot prompt, and startup greeting state.</div>
          </div>
          <div class="mini-card alt">
            <div class="kicker">Behavior Tuning</div>
            <div><code>admin/AI/agent_boot.md</code></div>
            <div class="subtext">Use this first when you want to improve prompt behavior before touching runtime code.</div>
          </div>
          <div class="mini-card warn">
            <div class="kicker">Tool Rule</div>
            <div><code>agent_tool_run</code></div>
            <div class="subtext">Approved DB-backed tools must go through the wrapper; the model should never call them directly.</div>
          </div>
        </div>
      </div>
    </section>

    <section class="card">
      <h2>How It Works</h2>
      <div class="step-list">
        <?php foreach ($request_flow as $index => $step): ?>
          <div class="step">
            <div class="step-num"><?= h((string)($index + 1)) ?></div>
            <div class="muted"><?= h($step) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="subtext">
        Normal runs start with preloaded <code>notes_preview</code> and <code>code_preview</code>,
        plus optional <code>memory_preview</code> when memory autoload is enabled.
      </div>
    </section>

    <section class="card">
      <h2>Config Precedence</h2>
      <div class="mini-grid">
        <?php foreach ($config_precedence as $index => $item): ?>
          <div class="mini-card<?= ($index % 2 === 1) ? ' alt' : '' ?>">
            <div class="kicker">Layer <?= h((string)($index + 1)) ?></div>
            <div class="muted"><?= h($item) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="subtext">
        Empty strings in <code>/web/private/agent.json</code> do not replace existing values, and shared PHP settings are normalized to an OpenAI-compatible <code>/v1</code> base URL.
      </div>
    </section>

    <section class="card">
      <h2>Runtime Files</h2>
      <div class="file-list">
        <?php foreach ($runtime_files as $label => $path): ?>
          <div class="file-row">
            <strong><?= h($label) ?></strong>
            <div class="muted"><code><?= h($path) ?></code></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card">
      <h2>Docs in This Directory</h2>
      <div class="file-list">
        <?php foreach ($doc_links as $doc): ?>
          <div class="file-row">
            <strong><a href="<?= h($doc['path']) ?>" target="_blank" rel="noopener noreferrer"><?= h($doc['name']) ?></a></strong>
            <div class="muted"><?= h($doc['desc']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card">
      <h2>Profile Examples</h2>
      <div class="file-list">
        <?php foreach ($profile_examples as $profile): ?>
          <div class="file-row">
            <strong><a href="<?= h($profile['path']) ?>" target="_blank" rel="noopener noreferrer"><?= h($profile['name']) ?></a></strong>
            <div class="muted"><?= h($profile['desc']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="subtext">
        Hosted OpenAI-style profiles usually use <code>api_key_env: OPENAI_API_KEY</code>.
        Local OpenAI-compatible backends like LM Studio usually use <code>api_key_env: LLM_API_KEY</code>.
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
      <h2>Built-in Tools</h2>
      <div class="chips">
        <?php foreach ($built_in_tools as $tool): ?>
          <div class="chip"><code><?= h($tool) ?></code></div>
        <?php endforeach; ?>
      </div>
      <ul style="margin-top: 14px;">
        <li><code>read_code</code> is restricted to paths under the configured <code>code_root</code>.</li>
        <li><code>search</code> uses the configured Searx endpoint and can inherit <code>SEARX_URL</code> from <code>/web/private/.env</code>.</li>
        <li>Memory schema is auto-created on first use if the memory DB is missing.</li>
        <li>Repeated identical tool calls are loop-guarded and the runtime stops if the same tool plus args repeats too many times.</li>
      </ul>
    </section>

    <section class="card">
      <h2>Shell Commands and CLI Flags</h2>
      <div class="split">
        <div>
          <h3>Shell Commands</h3>
          <div class="chips">
            <?php foreach ($shell_commands as $command): ?>
              <div class="chip"><code><?= h($command) ?></code></div>
            <?php endforeach; ?>
          </div>
          <div class="subtext">Typing <code>exit</code> or <code>quit</code> without a slash also leaves the shell.</div>
        </div>
        <div>
          <h3>CLI Flags</h3>
          <div class="chips">
            <?php foreach ($cli_flags as $flag): ?>
              <div class="chip"><code><?= h($flag) ?></code></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="subtext">
        Normal rapid multiline paste is merged into one prompt. Use <code>/paste</code> for explicit multiline entry,
        <code>/compose</code> to open your editor immediately,
        <code>/read PATH</code> to load a file into the next prompt, and <code>/edit-paste on</code> when you want large pasted blocks
        to open in your editor for review before they are sent. Editor-reviewed prompts are archived under
        <code>/web/private/logs/agent_composer/</code>.
      </div>
    </section>

    <section class="card">
      <h2>Profile Fields</h2>
      <div class="chips">
        <?php foreach ($profile_fields as $field): ?>
          <div class="chip"><code><?= h($field) ?></code></div>
        <?php endforeach; ?>
      </div>
      <div class="subtext">
        The shell now supports config-driven editor review for large pasted prompts.
        Set <code>edit_paste_enabled</code>, <code>edit_paste_min_lines</code>, <code>editor_command</code>,
        <code>editor_timeout_seconds</code>, and <code>edit_paste_strip_comment_lines</code> in
        <code>admin/AI/default_agent.json</code>, <code>/web/private/agent.json</code>, or a <code>--config-file</code> profile.
        <code>editor_command</code> supports multi-word commands and an optional <code>{file_path}</code> placeholder, for example
        <code>nano -w -l {file_path}</code>.
      </div>
    </section>

    <section class="card">
      <h2>Tutorial Snippets</h2>
      <div class="mini-grid">
        <div class="mini-card alt">
          <div class="kicker">Inspect the Current Setup</div>
          <pre>python3 admin/AI/agent.py

agent&gt; /status
agent&gt; /models</pre>
        </div>
        <div class="mini-card">
          <div class="kicker">Ask a Normal Project Question</div>
          <pre>agent&gt; Where is the search tool configured?

The agent should prefer notes/code context and
read_code before falling back to web search.</pre>
        </div>
        <div class="mini-card">
          <div class="kicker">Load a File or Review a Large Paste</div>
          <pre>agent&gt; /read admin/AI/AI_tools.md

agent&gt; /compose

agent&gt; /edit-paste on
agent&gt; paste a longer draft here...</pre>
        </div>
        <div class="mini-card warn">
          <div class="kicker">Single-Shot Mode</div>
          <pre>python3 admin/AI/agent.py \
  --query "Summarize how config precedence works"</pre>
        </div>
      </div>
    </section>
  </div>
</body>
</html>
