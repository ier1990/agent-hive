# AI_Header (PHP class)

This doc describes the `AI_Header` class in `AI_Header.php` and shows how to compile “AI Header templates” (`.tpl` text with `{{ vars }}`) into a deterministic payload.

Compatibility target: **PHP 7.3+**.

## What the class does

Given a template string and one or more **binding arrays**, `AI_Header`:

- Extracts `{{ variables }}` from the template
- Merges your binding inputs into a single `bindings` map
- Replaces `{{ var }}` placeholders (supports dot notation like `{{ user.name }}`)
- Produces a `compiled` array containing:
	- `payload_text` (the rendered template)
	- `payload` (a parsed array, JSON-ready)
	- `unbound_variables` (vars found in template but not bound)

It’s designed to be dependency-free: no YAML extension required.

## Include / instantiate

```php
require_once __DIR__ . '/AI_Header.php';

$ai = new AI_Header([
		'missing_policy' => 'ignore',
		'debug' => false,
]);
```

### Config options

The constructor accepts an array of options:

- `missing_policy` (string): currently treated as informational; missing vars render as empty string.
	- Allowed values documented in code: `ignore` | `empty`
- `cache_days` (int): not used by the class yet (reserved)
- `allow_tools` (bool): not used by the class yet (reserved)
- `debug` (bool): not used by the class yet (reserved)

## Template syntax

### Variables

- Placeholders look like: `{{ var }}`
- Variable names may include: letters, digits, underscore, dot, dash: `a-zA-Z0-9_.-`

Examples:

```text
system: |
	Hello {{ user.name }}

prompt: |
	Summarize: {{ text }}
```

### Dot notation

`{{ user.name }}` traverses nested arrays/objects:

- If `user` is an array and has key `name`, it’s used
- If `user` is an object and has property `name`, it’s used
- If anything is missing, the variable is considered unbound and renders as `""`

### Multi-line substitutions keep indentation

If a placeholder is replaced with a multi-line string, subsequent lines are automatically indented to match the placeholder line’s leading whitespace.

This is important for block scalars (`key: |`) where indentation must be consistent.

## Binding inputs (how values get provided)

`compile()` is variadic: you can pass any number of inputs.

Rules:

- Only **associative arrays** are merged into `bindings`
- Numeric/indexed arrays are ignored (ambiguous)
- Strings / scalars passed as inputs are ignored

Values are sanitized:

- Strings have ASCII control chars removed (except `\n`, `\r`, `\t`)

## Output shape

`compile()` returns:

```php
[
	'meta' => [
		'stage' => 'compiled',
		'created_at' => '2026-01-16T12:34:56Z',
		'checksum' => 'sha256...',
		'policy' => ['missing' => 'ignore'],
	],
	'bindings' => [ ... ],
	'unbound_variables' => [ ... ],
	'payload_text' => "...rendered template...",
	'payload' => [ ...parsed array... ],
]
```

Notes:

- `unbound_variables` helps you see what you forgot to provide.
- `payload_text` is always available (useful for debugging/logging).

## Payload parsing rules (YAML-ish / JSON)

After rendering, the class parses `payload_text` into `payload`:

1) If the full rendered text starts with `{` or `[` it attempts `json_decode()` and returns that array.

2) Otherwise it parses a small “YAML-ish” mapping format:

- `key: value` (scalars)
- `key:` followed by indented lines (nested maps)
- `key: |` followed by indented lines (block scalar string)

This keeps the format readable while staying dependency-free.

## Public methods

### `compile(string $templateText, ...$inputs): array`

Compiles the template into a structured array (see “Output shape”).

### `compilePayload(string $templateText, ...$inputs): array`

Shortcut: returns only the parsed payload array.

### `compilePayloadJson(string $templateText, ...$inputs): string`

Shortcut: returns the payload array as pretty JSON.

### `precompile(string $templateText, ...$inputs): array`

Same as `compile()`, but marks `meta.stage = 'precompiled'`.

This is useful if you later add processing steps outside this class.

### `ai_header_check_block_indentation(string $tpl): array`

Validates block-scalar indentation for lines like:

```text
prompt: |
	content
```

Returns:

- `ok` (bool)
- `errors` (array of issues with line numbers and required indentation)

### `ai_header_fix_block_indentation(string $tpl): array`

Auto-fixes under-indented block-scalar content by adding spaces.

Returns:

- `changed` (bool)
- `changes` (int)
- `text` (string) fixed template

## Examples

### 1) Small “payload” template (YAML-ish)

```php
$tpl = "system: |\n  You are helpful.\n\nprompt: |\n  Summarize: {{ text }}\n";

$compiled = $ai->compile($tpl, ['text' => "Line 1\nLine 2"]);

// Debug unbound vars
// print_r($compiled['unbound_variables']);

// Parsed payload array
// print_r($compiled['payload']);
```

Expected payload keys:

- `system` (string)
- `prompt` (string)

### 2) Dot-notation with nested arrays

```php
$tpl = "system: |\n  Hello {{ user.name }} ({{ user.id }})\n";

$bindings = [
		'user' => [
				'id' => 123,
				'name' => 'Ada',
		],
];

$payload = $ai->compilePayload($tpl, $bindings);
// $payload['system'] === "Hello Ada (123)"
```

### 3) Dot-notation with objects

```php
$user = (object)['name' => 'Grace'];
$tpl  = "system: |\n  Hello {{ user.name }}\n";

$payload = $ai->compilePayload($tpl, ['user' => $user]);
```

### 4) Missing variables: how it behaves

Missing variables are rendered as empty string and also reported:

```php
$tpl = "prompt: |\n  {{ missing_var }} / {{ present }}\n";
$compiled = $ai->compile($tpl, ['present' => 'OK']);

// $compiled['payload']['prompt'] begins with " / OK"
// $compiled['unbound_variables'] contains ['missing_var']
```

### 5) If you want the payload to be JSON

If your template renders to valid JSON, the parser will return it directly:

```php
$tpl = "{\n  \"model\": \"{{ model }}\",\n  \"stream\": false,\n  \"messages\": [\n    {\"role\": \"system\", \"content\": \"{{ system }}\"},\n    {\"role\": \"user\", \"content\": \"{{ prompt }}\"}\n  ]\n}\n";

$payload = $ai->compilePayload($tpl, [
		'model' => 'gpt-4o-mini',
		'system' => 'Be concise',
		'prompt' => 'Explain caching',
]);
```

### 6) Embedding arrays/objects into a single line

When a placeholder is replaced with an array/object, it renders as JSON:

```php
$tpl = "meta_json: {{ meta }}\n";
$payload = $ai->compilePayload($tpl, ['meta' => ['a' => 1, 'b' => 2]]);

// $payload['meta_json'] === '{"a":1,"b":2}'
```

### 7) Validate/fix indentation before saving a template

```php
$bad = "prompt: |\n x\n"; // content indented by 1 space instead of 2

$check = $ai->ai_header_check_block_indentation($bad);
if (!$check['ok']) {
		$fixed = $ai->ai_header_fix_block_indentation($bad);
		$bad = $fixed['text'];
}
```

## Practical usage patterns

### Pattern A: compile for dispatch

```php
$compiled = $ai->compile($templateText, $inputs);

// Log the exact payload we produced
// file_put_contents('/web/private/logs/ai_headers.log', json_encode($compiled) . "\n", FILE_APPEND);

// Send $compiled['payload'] to a dispatcher
```

### Pattern B: compile JSON for debugging or curl

```php
header('Content-Type: application/json; charset=utf-8');
echo $ai->compilePayloadJson($templateText, $inputs);
```

## Gotchas / tips

- Inputs must be associative arrays to be merged.
- If you use `key: |` blocks, indentation matters; use the check/fix helpers.
- `unbound_variables` is your first stop when a template doesn’t render as expected.