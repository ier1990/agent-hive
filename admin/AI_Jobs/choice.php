<?php

/* 

2) choice.php (router / selector)
Purpose

Take a set of options and pick the best next action (or ask a clarifying question).

Job name

pick.choice

Payload
{
  "question": "Which worker should handle this user request?",
  "choices": [
    {"id":"code.search", "desc":"Search code_base.db and return relevant files"},
    {"id":"chat.reply", "desc":"Generate reply using memory + summaries"},
    {"id":"notes.ingest", "desc":"Ingest notes into memory"}
  ],
  "output": "single_choice"
}

What it does

Renders AI_Header Choice Router v1

Returns one choice id + short reason

Conductor enqueues the next job based on that choice

This is your “autopilot” without hardcoding everything.

Human one would be html <form within chat back to a  

Yep. Treat choice.php as a router tool that can return either:

AI choice (model picks an option), or

Human choice (render an HTML <form> so you pick), or

Clarifying question (AI asks a question, or human is prompted to answer)

Below is a “boringly implementable” design that plugs into your existing job system and your chat UI.

Contract: what choice.php outputs

Always produce one of these outcomes:

A) decision
{
  "type": "decision",
  "choice_id": "code.search",
  "reason": "Need to find relevant files first."
}

B) clarify
{
  "type": "clarify",
  "question": "Which site are we working on: web-ministry or domain-memory?",
  "choices": [{"id":"web-ministry","label":"Web-Ministry"},{"id":"domain-memory","label":"Domain Memory"}]
}

C) human_required (same as clarify, but explicitly human)
{
  "type": "human_required",
  "question": "Pick the next action:",
  "choices": [...]
}

Payload schema for the job

Job name: pick.choice

Payload:

{
  "mode": "auto|ai|human",
  "question": "What should we do next?",
  "choices": [
    {"id":"code.search","label":"Search code base","desc":"Search code_base.db for relevant files"},
    {"id":"chat.reply","label":"Reply to user","desc":"Use memory + summaries to reply"},
    {"id":"code.summarize","label":"Summarize file(s)","desc":"Summarize selected files"},
    {"id":"noop","label":"Do nothing","desc":"Stop here"}
  ],
  "default_choice_id": "code.search",
  "require_reason": true,
  "thread_id": 123,
  "return_to": "/admin/chat.php?thread_id=123"
}


mode=auto means: try AI; if AI fails validation → fall back to human form.

thread_id/return_to lets you bounce back into your chat UI.

How Human mode works (HTML form inside chat)

You’ll expose a URL like:

/admin/AI/tools/choice.php?job=<JOB_ID>&view=form

It renders a form with radio options and a “Submit” button.

On POST, it:

validates choice

writes the result to the job record (or a job_results table)

optionally enqueues the next job

redirects back to return_to

choice.php implementation skeleton

This is intentionally “drop in” style. You’ll need to swap in your existing queue/db helpers (MotherQueue, etc.).
*/


// /admin/AI/tools/choice.php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/lib/bootstrap.php';
// require_once __DIR__ . '/../mq.php'; // your queue class
require_once dirname(__DIR__, 2) . '/AI_Header/AI_Header.php';
// require_once __DIR__ . '/../conversation_class.php'; // your existing convo runner

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function json_out($data, int $code=200): void {
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
  exit;
}

/**
 * Validate AI decision response strictly.
 */
function validate_choice_result(array $result, array $choices): array {
  $ids = array_map(function ($c) {
    return (string)($c['id'] ?? '');
  }, $choices);
  $ids = array_values(array_filter($ids));

  $type = (string)($result['type'] ?? '');
  if (!in_array($type, ['decision','clarify','human_required'], true)) {
    return [false, "Invalid type"];
  }

  if ($type === 'decision') {
    $cid = (string)($result['choice_id'] ?? '');
    if ($cid === '' || !in_array($cid, $ids, true)) return [false, "Invalid choice_id"];
    return [true, ""];
  }

  if ($type === 'clarify' || $type === 'human_required') {
    $q = trim((string)($result['question'] ?? ''));
    if ($q === '') return [false, "Missing question"];
    $ch = $result['choices'] ?? null;
    if (!is_array($ch) || count($ch) < 1) return [false, "Missing clarify choices"];
    return [true, ""];
  }

  return [false, "Unknown"];
}

/**
 * Build a human form from job payload.
 */
function render_human_form(array $job, array $payload): void {
  $question = (string)($payload['question'] ?? 'Pick one:');
  $choices  = is_array($payload['choices'] ?? null) ? $payload['choices'] : [];
  $returnTo = (string)($payload['return_to'] ?? '/admin/AI/');
  $jobId    = (string)($job['id'] ?? '');

  echo "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
  echo "<title>Choice</title>";
  echo "<style>
    body{background:#0b0d10;color:#e6e6e6;font-family:system-ui;margin:0;padding:16px}
    .card{max-width:860px;margin:0 auto;background:#111823;border:1px solid #1c222b;border-radius:16px;padding:16px}
    .opt{padding:10px;border:1px solid #1c222b;border-radius:12px;margin:10px 0}
    .desc{color:#9aa3ad;font-size:13px;margin-top:6px}
    button{background:#7c5cff;border:0;color:white;padding:10px 14px;border-radius:12px;font-weight:700;cursor:pointer}
    a{color:#11c3ff}
  </style></head><body>";

  echo "<div class='card'>";
  echo "<h2 style='margin:0 0 10px 0'>".h($question)."</h2>";
  echo "<form method='post' action='".h($_SERVER['PHP_SELF'])."'>";
  echo "<input type='hidden' name='job_id' value='".h($jobId)."'>";
  echo "<input type='hidden' name='return_to' value='".h($returnTo)."'>";

  foreach ($choices as $idx => $c) {
    $id = (string)($c['id'] ?? '');
    $label = (string)($c['label'] ?? $id);
    $desc = (string)($c['desc'] ?? '');
    if ($id === '') continue;

    echo "<label class='opt'>";
    echo "<div><input type='radio' name='choice_id' value='".h($id)."' ".($idx===0?'checked':'')."> ";
    echo "<strong>".h($label)."</strong></div>";
    if ($desc !== '') echo "<div class='desc'>".h($desc)."</div>";
    echo "</label>";
  }

  echo "<div style='margin-top:12px'>";
  echo "<div style='margin:6px 0;color:#9aa3ad;font-size:13px'>Optional reason:</div>";
  echo "<textarea name='reason' rows='3' style='width:100%;background:#0b0d10;color:#e6e6e6;border:1px solid #1c222b;border-radius:12px;padding:10px'></textarea>";
  echo "</div>";

  echo "<div style='display:flex;gap:10px;margin-top:14px;align-items:center'>";
  echo "<button type='submit'>Submit</button>";
  echo "<a href='".h($returnTo)."'>Cancel</a>";
  echo "</div>";

  echo "</form></div></body></html>";
  exit;
}

/**
 * Store result somewhere.
 * You can:
 * - update the job payload with result
 * - write a job_results table
 * - mark job done with result JSON
 */
function store_choice_result(/* MotherQueue $mq, */ array $job, array $result): void {
  // TODO: implement with your queue class
  // Example:
  // $mq->complete($job['id'], $result);
}

/**
 * If you want automatic chaining, enqueue next job here.
 */
function maybe_enqueue_next(/* MotherQueue $mq, */ array $payload, array $result): void {
  if (($result['type'] ?? '') !== 'decision') return;
  $next = (string)$result['choice_id'];

  // Optional: mapping table from choice_id -> job name/queue/payload builder
  // TODO: implement if desired.
}

//
// MAIN
//

// TODO: replace with your real job fetch
function load_job_stub(string $id): array {
  // Replace with real: $mq->get($id)
  return ['id'=>$id, 'payload_json'=>'{}'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $jobId = (string)($_POST['job_id'] ?? '');
  $choiceId = (string)($_POST['choice_id'] ?? '');
  $reason = trim((string)($_POST['reason'] ?? ''));
  $returnTo = (string)($_POST['return_to'] ?? '/admin/AI/');

  // Load job and validate choice against payload choices
  $job = load_job_stub($jobId);
  $payload = json_decode((string)($job['payload_json'] ?? '{}'), true);
  if (!is_array($payload)) $payload = [];

  $choices = is_array($payload['choices'] ?? null) ? $payload['choices'] : [];
  $ids = array_map(function ($c) {
    return (string)($c['id'] ?? '');
  }, $choices);

  if ($choiceId === '' || !in_array($choiceId, $ids, true)) {
    json_out(['ok'=>false,'error'=>'Invalid choice'], 400);
  }

  $result = [
    'type' => 'decision',
    'choice_id' => $choiceId,
    'reason' => $reason,
    'by' => 'human',
    'at' => gmdate('c'),
  ];

  store_choice_result($job, $result);
  maybe_enqueue_next($payload, $result);

  header('Location: '.$returnTo);
  exit;
}

// GET: render form or run AI
$jobId = (string)($_GET['job'] ?? '');
$view = (string)($_GET['view'] ?? '');

$job = load_job_stub($jobId);
$payload = json_decode((string)($job['payload_json'] ?? '{}'), true);
if (!is_array($payload)) $payload = [];

$mode = (string)($payload['mode'] ?? 'auto');
$choices = is_array($payload['choices'] ?? null) ? $payload['choices'] : [];

if ($view === 'form' || $mode === 'human') {
  render_human_form($job, $payload);
}

// AI mode (or auto)
try {
  // Build bindings + AI_Header template here
  // $headerText = load AI_Header template "Choice Router v1"
  // $bindings = [... question, choices, etc ...]
  // $payloadArr = (new AI_Header())->compilePayload($headerText, $bindings)
  // $resultText = $conversation->run($payloadArr)
  // $result = json_decode($resultText,true) ...

  // For now, fall back to human
  if ($mode === 'auto') {
    render_human_form($job, $payload);
  }
  json_out(['ok'=>false,'error'=>'AI mode not wired yet'], 501);

} catch (Throwable $e) {
  // auto -> human fallback
  if ($mode === 'auto') {
    render_human_form($job, $payload);
  }
  json_out(['ok'=>false,'error'=>$e->getMessage()], 500);
}
?>