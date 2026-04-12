Right now you’re doing reactive debugging by hand. An Apache/PHP error log scanner would turn that into a repeatable loop:

read new log lines
detect real PHP/app problems
group duplicates
attach nearby code context
hand a compact report to a local model
save a suggested fix report for you

That is exactly the kind of job small local models can still do well, because the hard part is collecting and structuring the evidence, not raw intelligence.

What it should do

For your setup, I’d make it focus on real actionable PHP failures, not noise.

Priority patterns:

PHP Fatal error:
PHP Parse error:
PHP Warning:
PHP Notice: if repeated a lot
Uncaught Exception
PDOException
require_once(...): Failed opening
include(...): Failed opening
Undefined array key
Undefined variable
Call to undefined function
Call to a member function ... on null
Cannot modify header information
Allowed memory size exhausted

Ignore or down-rank:

bots probing junk URLs
favicon noise
SSL handshake chatter
404s unless tied to your own scripts
deprecated noise unless you’re in PHP 8 migration mode
Best design

You already have the right instinct: don’t just dump raw logs into AI.

Build a scanner that produces a structured record like:

{
  "timestamp": "2026-04-10 14:22:01",
  "type": "PHP Fatal error",
  "message": "Call to undefined function pdo_connect()",
  "file": "/web/iernc.com/public_html/search.php",
  "line": 42,
  "count": 17,
  "first_seen": "...",
  "last_seen": "...",
  "sample_log": "...",
  "code_context": {
    "file": "/web/iernc.com/public_html/search.php",
    "start_line": 32,
    "focus_line": 42,
    "end_line": 52
  },
  "related_include_guess": [
    "/web/iernc.com/private_code/master_config.php"
  ]
}

That gives the model something clean to reason over.

The 4 parts you want
1. log tail / state tracker

Store inode + byte offset so you only process new lines, like your bash-history ingester.

Files to scan:

/var/log/apache2/error.log
/var/log/apache2/error*.log
maybe vhost-specific logs if you have them
2. parser / grouper

Normalize duplicates so these become one issue group:

same error type
same file
same line
same core message

Then count frequency.

3. code context fetcher

When a PHP file/line is found:

open that file
grab maybe 10 lines before and after
maybe also inspect nearest include/config file if obvious
4. reporter

Output:

reports/errors_YYYYMMDD.json
reports/errors_YYYYMMDD.html
optional Slack summary
optional AI summary per cluster
Local AI workflow

Don’t send the entire log to the model.

Send one grouped issue at a time with:

normalized error
repeat count
sample lines
source code context
maybe the include chain if known

Prompt shape:

You are reviewing a PHP/Apache production error.

Classify:
1. root cause
2. likely fix
3. confidence
4. whether this is safe to ignore
5. exact file/line to inspect first

Error:
[structured error block]

Code context:
[lines around the failure]

Return short practical guidance for a legacy PHP 7.3/7.4 codebase migrating toward PHP 8.

That keeps local models from wandering.

What language to build it in

Python is the right choice.

Why:

easy regex parsing
easy JSON/HTML output
easy SQLite state
easy model API calls later
easy cron use
Recommended file layout

Given your structure, something like:

/web/AI/bin/apache_error_scan.py
/web/AI/bin/help/apache_error_scan.html
/web/AI/state/apache_error_scan_state.json
/web/AI/reports/apache_errors/

Or under /home/industri/bin/ if you want active scripts outside rsync paths.

Minimal first version

Version 1 does not need AI yet.

Just build:

scan new Apache error lines
extract PHP-related errors
group by signature
save HTML report

That alone will already help.

Example HTML sections:

New fatal errors
Repeated warnings
Missing include files
Top noisy scripts
Errors by site:
iernc.com
board-repair.com
samekhi/web-ministry/etc.
Smart extras later

After basic scanner works, add:

severity scoring
site detection from path
“new since yesterday”
“resolved since yesterday”
direct links to source files
auto-suggest likely owner/config file
AI-generated short fix note
“top 10 recurring issues this week”
The biggest win

For your environment, the most valuable thing is not “AI fixes code.”

It is:

turning ugly Apache logs into a sorted repair queue

Example queue:

Fatal errors first
Then missing include/config
Then undefined function/class
Then warnings repeated over 100 times
Then notices

That makes the work manageable.

My recommendation

Build it in this order:

scanner
grouping
HTML report
SQLite/state tracking
AI summarizer
Slack notification

That way even if the model is dumb or offline, the scanner is still useful.

Repo-native version for this codebase

Given your current stack, I would not start with a standalone Python + agent runtime design.

You already have:

`src/scripts/cron_dispatcher.php`
root-only task support via `root_*`
SQLite everywhere
existing admin UI patterns
existing private runtime paths under `/web/private`

So the most boring version is:

1. root collector job reads Apache logs hourly
2. collector writes normalized findings into SQLite under `/web/private/db/`
3. admin UI reads that DB and shows grouped errors
4. optional AI summary runs against grouped issues, not raw logs

That fits this repo much better than inventing a separate agent worker first.

Suggested architecture

Collector:

`/web/private/scripts/root_apache_log_scan.php`

Why root:

Apache logs often live under `/var/log/apache2/` or `/var/log/httpd/`
you already support root-only scheduled tasks via the `root_` prefix
it can run safely from `cron_dispatcher.php --run-as=root`

Suggested DB:

`/web/private/db/apache_logs.db`

Suggested tables:

`log_sources`
- id
- path
- inode
- last_offset
- last_scan_at
- last_size
- status

`apache_error_events`
- id
- source_id
- happened_at
- log_level
- error_type
- message
- file_path
- file_line
- vhost
- uri
- raw_line
- signature
- created_at

`apache_error_groups`
- id
- signature
- first_seen
- last_seen
- hit_count
- severity
- status
- sample_event_id
- likely_app
- ai_summary
- ai_confidence
- ai_updated_at

`apache_error_group_events`
- group_id
- event_id

`apache_scan_runs`
- id
- started_at
- finished_at
- sources_scanned
- lines_read
- php_events_found
- groups_changed
- status
- error

This gives you offset tracking, dedupe, history, and a clean UI surface.

Suggested UI

`/web/html/admin/admin_Apache_Logs.php`

What the first UI should show:

new groups since last 24h
top recurring fatals
missing include/require failures
undefined function/class errors
warnings/notices by count
errors grouped by app path
last scan status
links to raw sample lines
code snippet around file+line when local source exists

Useful filters:

severity
site/app prefix
error family
first seen / last seen
resolved / ignored / active

Small but high-value UI actions:

mark ignored
mark resolved
copy grouped prompt payload
re-run AI summary for one group
open file path + line reference

How it should fit cron_dispatcher

This is a very natural fit for your existing dispatcher model.

Example task:

script path:
`/web/private/scripts/root_apache_log_scan.php`

schedule:
`5 * * * *`

run-as:
root automatically because of `root_`

Optional second task later:

`/web/private/scripts/apache_log_ai_summarize.php`

That one does not need root if it only reads the SQLite DB and writes summaries back.

That split is good:

root job touches system logs
non-root job touches app DB/UI summaries

Do you need agent.py?

Probably not for v1.

I would treat `agent.py` as optional, not foundational.

Why you probably do not need it:

the hard part is log parsing, grouping, and state tracking
your UI wants deterministic records more than free-form reasoning
hourly cron jobs should stay operationally boring
PHP can already own the DB schema and admin UI cleanly
you can call an LLM later with one grouped record at a time without a general agent loop

When `agent.py` might help later:

if you want multi-step triage across many files
if you want the worker to inspect related includes/config/routes automatically
if you want tool-using repair suggestions with richer context gathering
if you want one-click “investigate this issue cluster” workflows

Even then, I would keep it as a secondary helper, not the primary scanner.

Better first implementation order for this repo

Phase 1:

`root_apache_log_scan.php`
parse only Apache/PHP error logs
track inode + byte offsets
store normalized events/groups in SQLite

Phase 2:

`admin_Apache_Logs.php`
show grouped failures
show counts and timestamps
show nearby code context

Phase 3:

`apache_log_ai_summarize.php`
read unresolved groups
generate short fix notes
store back into `apache_error_groups.ai_summary`

Phase 4:

optional notifications
daily digest
weekly recurring issues report

Practical parsing targets

I would explicitly support both common layouts:

`/var/log/apache2/error.log`
`/var/log/apache2/error*.log`
`/var/log/httpd/error_log`
vhost-specific logs if configured

And extract when available:

timestamp
apache severity
pid/tid
client ip
vhost
request path
PHP error family
app file path
line number

Good first-pass signatures

These signatures are usually enough to group repair work:

`fatal|file_path|line|normalized_message`
`warning|file_path|line|normalized_message`
`uncaught_exception|exception_class|file_path|line`
`require_failed|target_path|caller_file|caller_line`

Normalize volatile values out:

IPs
request IDs
full query strings
hex object ids
absolute temp paths

Nice connection to your existing notes/AI tooling

Once the scanner is producing good groups, AI becomes easy:

one unresolved group in
one compact operator summary out

That can be done by:

plain PHP HTTP call to your configured LLM backend
or a tiny purpose-built summarizer script
or `agent.py` later if you truly want tool-using investigation

But I would not make the scanner depend on agent runtime availability.

Concrete recommendation

Yes, build `admin_Apache_Logs.php`.

And for the worker side, prefer:

`/web/private/scripts/root_apache_log_scan.php`

plus later:

`/web/private/scripts/apache_log_ai_summarize.php`

That gives you:

root where root is needed
PHP where your admin stack already lives
SQLite reports the UI can consume directly
AI as an optional enrichment layer instead of a mandatory dependency
