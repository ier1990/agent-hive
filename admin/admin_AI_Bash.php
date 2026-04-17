<?php

declare(strict_types=0);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once APP_LIB . '/auth/auth.php';
require_once __DIR__ . '/lib/agent_bash.php';

auth_require_admin();

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (empty($_SESSION['csrf_ai_bash'])) {
    $_SESSION['csrf_ai_bash'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_ai_bash'];

$pdo = agent_bash_open_db();
$message = '';
$error = '';
$currentUser = (string)($_SERVER['PHP_AUTH_USER'] ?? $_SERVER['REMOTE_USER'] ?? 'admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['proposal_id'], $_POST['op'])) {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = 'Invalid CSRF token.';
    } else {
        $proposalId = (int)$_POST['proposal_id'];
        $op = (string)$_POST['op'];
        if ($op === 'approve') {
            agent_bash_set_status($pdo, $proposalId, 'approved', $currentUser);
            $message = 'Proposal #' . $proposalId . ' approved.';
        } elseif ($op === 'cancel') {
            agent_bash_set_status($pdo, $proposalId, 'canceled', $currentUser);
            $message = 'Proposal #' . $proposalId . ' canceled.';
        } elseif ($op === 'delete') {
            agent_bash_delete($pdo, $proposalId);
            $message = 'Proposal #' . $proposalId . ' deleted.';
        } elseif ($op === 'execute') {
            $result = agent_bash_execute($pdo, $proposalId, $currentUser);
            if (!empty($result['ok'])) {
                $message = 'Proposal #' . $proposalId . ' executed.';
            } else {
                $error = 'Execution failed: ' . (string)($result['error'] ?? 'unknown_error');
            }
        }
    }
}

$rows = agent_bash_list($pdo, 150);
$allowedRoots = agent_bash_allowed_roots();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>AI Bash Proposals</title>
  <link rel="stylesheet" href="lib/admin_dark.css">
  <style>
    body { margin: 0; font-family: system-ui, sans-serif; background: #0f1318; color: #d7e0ea; }
    .wrap { max-width: 1200px; margin: 0 auto; padding: 20px; }
    .card { background: #151b22; border: 1px solid #28313b; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
    .muted { color: #8a96a6; }
    .msg { padding: 10px 12px; border-radius: 10px; margin-bottom: 12px; }
    .msg.ok { background: #17311f; border: 1px solid #29593a; color: #b8efc7; }
    .msg.err { background: #331818; border: 1px solid #673030; color: #ffcccc; }
    .pill { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 12px; border: 1px solid #334150; }
    .risk-low { background: #183222; color: #b7f2c8; }
    .risk-medium { background: #3a2e16; color: #f8dda7; }
    .risk-high { background: #411b1b; color: #ffc0c0; }
    code, pre { font-family: Consolas, Monaco, monospace; }
    pre { background: #0b0f14; border: 1px solid #28313b; border-radius: 10px; padding: 12px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
    .row { display: grid; grid-template-columns: 180px 1fr; gap: 8px; margin-top: 8px; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
    button { padding: 8px 12px; border-radius: 8px; border: 1px solid #3d4b5a; background: #1f6fd1; color: #fff; cursor: pointer; }
    button.secondary { background: #1e2731; }
    button.warn { background: #7d4b12; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1 style="margin-top:0">AI Bash Proposals</h1>
      <div class="muted">Review, approve, cancel, and execute human-reviewed bash commands proposed by the Python agent.</div>
      <div class="row">
        <div class="muted">Allowed roots</div>
        <div><?php echo h(implode(', ', $allowedRoots)); ?></div>
      </div>
      <div class="row">
        <div class="muted">Workflow</div>
        <div>
          Proposed commands land here first. Approve a proposal if you want it to become runnable, then use <strong>Execute</strong> to run it.
          If the agent needs to check what happened later, it should use the <code>bash_proposal_status</code> tool with the proposal ID.
          Use <strong>Delete</strong> to permanently remove junk or test proposals from the queue.
        </div>
      </div>
    </div>

    <?php if ($message !== ''): ?>
      <div class="msg ok"><?php echo h($message); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div class="msg err"><?php echo h($error); ?></div>
    <?php endif; ?>

    <?php if (!$rows): ?>
      <div class="card">No bash proposals yet.</div>
    <?php endif; ?>

    <?php foreach ($rows as $row): ?>
      <?php
        $risk = (string)($row['risk_level'] ?? 'medium');
        $status = (string)($row['status'] ?? 'proposed');
        $tutorial = json_decode((string)($row['tutorial_summary'] ?? '{}'), true);
        if (!is_array($tutorial)) $tutorial = [];
        $meta = json_decode((string)($row['metadata_json'] ?? '{}'), true);
        if (!is_array($meta)) $meta = [];
        $result = json_decode((string)($row['result_json'] ?? '{}'), true);
        if (!is_array($result)) $result = [];
      ?>
      <div class="card">
        <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; flex-wrap:wrap;">
          <div>
            <strong>#<?php echo (int)$row['id']; ?></strong>
            <span class="pill risk-<?php echo h($risk); ?>"><?php echo h($risk); ?></span>
            <span class="pill"><?php echo h($status); ?></span>
          </div>
          <div class="muted"><?php echo h((string)($row['proposed_at'] ?? '')); ?></div>
        </div>

        <div class="row">
          <div class="muted">Command</div>
          <div><pre><?php echo h((string)($row['command_text'] ?? '')); ?></pre></div>
        </div>
        <div class="row">
          <div class="muted">Working dir</div>
          <div><?php echo h((string)($row['cwd'] ?? '')); ?></div>
        </div>
        <div class="row">
          <div class="muted">Operator summary</div>
          <div><?php echo h((string)($row['operator_summary'] ?? '')); ?></div>
        </div>
        <div class="row">
          <div class="muted">Tutorial purpose</div>
          <div><?php echo h((string)($tutorial['purpose'] ?? '')); ?></div>
        </div>
        <?php if (!empty($tutorial['tokens']) && is_array($tutorial['tokens'])): ?>
          <div class="row">
            <div class="muted">Token guide</div>
            <div>
              <?php foreach ($tutorial['tokens'] as $token): ?>
                <div><code><?php echo h((string)($token['part'] ?? '')); ?></code> — <?php echo h((string)($token['meaning'] ?? '')); ?></div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <?php if (!empty($meta)): ?>
          <div class="row">
            <div class="muted">Metadata</div>
            <div><pre><?php echo h(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre></div>
          </div>
        <?php endif; ?>
        <?php if (!empty($result)): ?>
          <div class="row">
            <div class="muted">Execution result</div>
            <div><pre><?php echo h(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre></div>
          </div>
        <?php endif; ?>

        <form method="post" class="actions">
          <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="proposal_id" value="<?php echo (int)$row['id']; ?>">
          <?php if ($status === 'proposed'): ?>
            <button type="submit" name="op" value="approve">Approve</button>
            <button type="submit" name="op" value="cancel" class="secondary">Cancel</button>
          <?php elseif ($status === 'approved'): ?>
            <button type="submit" name="op" value="execute" class="warn">Execute</button>
            <button type="submit" name="op" value="cancel" class="secondary">Cancel</button>
          <?php endif; ?>
          <button type="submit" name="op" value="delete" class="secondary" onclick="return confirm('Delete proposal #<?php echo (int)$row['id']; ?> permanently?');">Delete</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</body>
</html>
