# AI Templates Class Guide (PHP 7.3+)

This document describes the current AI Templates architecture.

## Class locations

- Core reusable renderer: `/web/html/lib/templates_class.php`
- Canonical AI payload compiler: `/web/html/lib/ai_templates_class.php`
- Admin compatibility wrapper: `/web/html/admin/AI_Templates/AI_Template.php`

Use `templates_class` for general site templates.
Use `AI_Template` when you need AI payload parsing (`payload_text` + parsed `payload` array).

## templates_class (shared engine)

`templates_class` is the base template engine and supports:

- Variable interpolation: `{{ variable }}`
- Dot notation: `{{ user.name }}`
- Conditionals:
  - `{{ if variable }}`
  - `{{ else }}`
  - `{{ endif }}`
  - Negation: `{{ if !variable }}` or `{{ if not variable }}`
- Nested condition blocks

Example:

```php
require_once __DIR__ . '/../../lib/templates_class.php';

$tpl = new templates_class();

$out = $tpl->render(
"Hello {{ user.name }}\n{{ if user.is_admin }}Admin{{ else }}User{{ endif }}",
[
  'user' => ['name' => 'Sam', 'is_admin' => true],
]
);
```

## AI_Template (AI-oriented wrapper)

`AI_Template` uses `templates_class` internally, then parses rendered text into a payload array.

Main methods:

- `compile(string $templateText, ...$inputs): array`
- `compilePayload(string $templateText, ...$inputs): array`
- `compilePayloadJson(string $templateText, ...$inputs): string`
- `precompile(string $templateText, ...$inputs): array`
- `ai_template_check_block_indentation(string $tpl): array`
- `ai_template_fix_block_indentation(string $tpl): array`

Example:

```php
require_once __DIR__ . '/AI_Template.php';

$ai = new AI_Template(['missing_policy' => 'ignore']);

$template = "system: |\n  You are helpful\nuser: |\n  {{ user_message }}\n";

$payload = $ai->compilePayload($template, [
  'user_message' => 'Summarize this file',
]);
```

## Backward compatibility

`AI_Template.php` provides compatibility for old callers:

- Class alias: `AI_Header` -> `AI_Template`
- Method aliases:
  - `ai_header_check_block_indentation()` -> `ai_template_check_block_indentation()`
  - `ai_header_fix_block_indentation()` -> `ai_template_fix_block_indentation()`

This keeps existing integrations working while migrating fully to AI Templates naming.
