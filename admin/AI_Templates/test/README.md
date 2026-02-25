# AI Templates Tests

Run CLI smoke tests:

```bash
php /web/html/admin/AI_Templates/test/run.php
```

What it validates:

- `lib/templates_class.php` variable rendering and `if/else/endif` logic
- `lib/ai_templates_class.php` payload compilation (`payload_text` -> parsed payload)
- Backward alias compatibility (`AI_Header` class alias)
- Default install JSON files are readable and have expected schema/fields:
  - `admin/defaults/templates_ai_templates.json`
  - `admin/AI_Story/story_templates_ai_templates_import.json`

Use this after refactors to catch naming and parser regressions early.
