<?php

echo "<pre>

1) chat_shrinker (the most valuable tool)
Purpose

Keep chat threads short + high-signal, and feed the summary back into memory.


2) choice.php (router / selector)
Purpose

Take a set of options and pick the best next action (or ask a clarifying question).
Either for AI or Human to answer.

That gives you the human router immediately (HTML form), and an obvious spot to wire AI routing using your AI_Template + conversation class.

Where it plugs into chat

When your chat flow reaches a fork:

enqueue a job pick.choice with return_to=/admin/chat.php?thread_id=...

in the chat UI, show a link:

“Decision needed → open choice form”

/admin/AI/tools/choice.php?job=<id>&view=form

Once you submit, you’re redirected back to the chat thread.

Next step (so it becomes “AI or human”)

To wire AI mode, I need one tiny thing from you:

the method call to your conversation class (example)

e.g. Conversation::run($payloadArray) returns ['text'=>..., 'raw'=>...]

Once you paste that, I’ll fill in the AI section so mode=auto:

tries AI router first

validates output strictly

on failure, renders the human form

And we’ll keep it operationally boring.



3) Make “tpl tools” first-class in the UI

Since you already show recent jobs, add a tiny “Templates / Tools” page that lists:

tool name

/web/html/admin/AI_Template
AI_Template it uses

required payload keys

a “Run test” button that enqueues a job with sample payload

That becomes your internal “command palette”.

Implementation: keep it boring
One runner: /admin/AI/worker_run.php

claim next pending job

switch on $ job['name']

call a handler function

mark done/failed

Example routing table (conceptually):

bash.ingest_bash_history → existing handler

code.catalog → runs catalog.php or calls its function

code.summarize → uses AI_Template + conversation class

chat.shrink → uses AI_Template + conversation class

pick.choice → uses AI_Template + conversation class

No new framework.

The immediate next two files I’d create

/admin/AI/tools/chat_shrinker.php
Contains handler: load messages → compile AI_Template → call conversation class → store summary.

/admin/AI/tools/choice.php
Contains handler: compile AI_Template with the options → call model → validate response → enqueue next job.

These give you the “kewl tools” you’re imagining, fast.

Tiny detail that makes everything smooth

Make every tool return a standard result object like:

{
  'ok': true,
  'artifacts': {
    'summary_id': 55,
    'memory_updates': 3
  },
  'next_jobs': [
    {'queue':'default','name':'chat.reply','payload':{...}}
  ]
}
 


Then your conductor can automatically enqueue follow-up jobs.

If you paste the jobs table schema (or the MotherQueue class methods you already have), I’ll write you the exact two handlers:

chat_shrinker.php (with safe limits + target token sizing)

choice.php (with strict output validation so it never “wanders”)

</pre>";

?>

