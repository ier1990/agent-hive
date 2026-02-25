<?php
// /web/html/lib/ai_templates_class.php
require_once __DIR__ . '/templates_class.php';

class AI_Template
{
    private $config = [];
    private $templateEngine = null;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            // Policy: if {{ var }} is missing → ignore (render as empty string)
            // Keep 'empty' as an alias for readability/back-compat.
            'missing_policy' => 'ignore',   // ignore | empty
            'cache_days'     => 7,
            'allow_tools'    => false,      // compile() should not use tools
            'debug'          => false,     // verbose logging    

        ], $config);

        if (class_exists('templates_class')) {
            $this->templateEngine = new templates_class([
                'missing_policy' => (string)$this->config['missing_policy'],
            ]);
        }
    }

    // Variadic: any number of inputs
    public function compile(string $templateText, ...$inputs): array
    {
        $bindings = $this->normalizeBindings(...$inputs);
        $vars     = $this->extractVariables($templateText);

        // Missing vars are ignored, but we still report unbound vars for debugging.
        $unbound = [];
        foreach ($vars as $v) {
            if ($this->lookupBinding($bindings, $v) === self::UNBOUND) {
                $unbound[] = $v;
            }
        }

        $rendered = $this->render($templateText, $bindings);

        $payload = $this->parsePayloadText($rendered);

        // You can keep rendered as YAML-ish text OR parse it to an array.
        // For v1: store rendered text as payload to keep parser complexity out.
        $compiled = [
            'meta' => [
                'stage'     => 'compiled',
                'created_at'=> gmdate('c'),
                'checksum'  => hash('sha256', $rendered),
                'policy'    => [
                    'missing' => $this->config['missing_policy'],
                ],
            ],
            'bindings' => $bindings,
            'unbound_variables' => $unbound,
            'payload_text' => $rendered,
            'payload' => $payload,
        ];

        return $compiled;
    }

    // Convenience: return the parsed payload array (JSON-ready)
    public function compilePayload(string $templateText, ...$inputs): array
    {
        $compiled = $this->compile($templateText, ...$inputs);
        return is_array($compiled['payload'] ?? null) ? $compiled['payload'] : [];
    }

    // Convenience: return the parsed payload as JSON
    public function compilePayloadJson(string $templateText, ...$inputs): string
    {
        $payload = $this->compilePayload($templateText, ...$inputs);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return ($json === false) ? '{}' : $json;
    }

    public function precompile(string $templateText, ...$inputs): array
    {
        // Same normalization + render, but allowed to run processors later.
        $compiled = $this->compile($templateText, ...$inputs);
        $compiled['meta']['stage'] = 'precompiled';
        return $compiled;
    }

    public function ai_template_check_block_indentation(string $tpl): array
    {
        $tpl = str_replace("\r\n", "\n", $tpl);
        $lines = explode("\n", $tpl);

        $errors = [];
        $n = count($lines);

        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];
            if (trim($line) === '') continue;

            // Match: <indent><key>:\s*|
            if (!preg_match('/^(\s*)([A-Za-z0-9_.-]+)\s*:\s*\|\s*$/', $line, $m)) {
                continue;
            }

            $indent = strlen($m[1]);          // key line indent
            $minIndent = $indent + 2;         // required block indent

            $j = $i + 1;
            $sawContent = false;

            while ($j < $n) {
                $next = $lines[$j];

                // Allow blank lines inside the block (they’re content)
                if ($next === '') { $sawContent = true; $j++; continue; }

                $nextIndent = strspn($next, ' ');
                if ($nextIndent <= $indent) {
                    // Block ended
                    break;
                }

                $sawContent = true;

                if ($nextIndent < $minIndent) {
                    $errors[] = [
                        'line' => $j + 1, // 1-based
                        'key_line' => $i + 1,
                        'key' => $m[2],
                        'required_indent' => $minIndent,
                        'found_indent' => $nextIndent,
                        'sample' => $next,
                    ];
                    // Continue scanning to list all issues in this block
                }

                $j++;
            }

            // No content lines under a block scalar isn’t an error, but you can enforce if you want.
            if (!$sawContent) {
                // optional warning
            }
        }

        return [
            'ok' => (count($errors) === 0),
            'errors' => $errors,
        ];
    }

    public function ai_template_fix_block_indentation(string $tpl): array
    {
        $tpl = str_replace("\r\n", "\n", $tpl);
        $lines = explode("\n", $tpl);

        $n = count($lines);
        $changes = 0;

        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];
            if (trim($line) === '') continue;

            if (!preg_match('/^(\s*)([A-Za-z0-9_.-]+)\s*:\s*\|\s*$/', $line, $m)) {
                continue;
            }

            $indent = strlen($m[1]);
            $minIndent = $indent + 2;

            $j = $i + 1;
            while ($j < $n) {
                $next = $lines[$j];

                // Blank lines are allowed; leave them as-is
                if ($next === '') { $j++; continue; }

                $nextIndent = strspn($next, ' ');

                // Block ends when indentation returns to <= key indent
                if ($nextIndent <= $indent) break;

                if ($nextIndent < $minIndent) {
                    // Add spaces to reach required indent
                    $need = $minIndent - $nextIndent;
                    $lines[$j] = str_repeat(' ', $need) . $next;
                    $changes++;
                }

                $j++;
            }
        }

        $fixed = implode("\n", $lines);
        return [
            'changed' => ($changes > 0),
            'changes' => $changes,
            'text' => $fixed,
        ];
    }

    // Backward-compatible aliases for existing callers.
    public function ai_header_check_block_indentation(string $tpl): array
    {
        return $this->ai_template_check_block_indentation($tpl);
    }

    public function ai_header_fix_block_indentation(string $tpl): array
    {
        return $this->ai_template_fix_block_indentation($tpl);
    }


    private function normalizeBindings(...$inputs): array
    {
        $out = [];
        foreach ($inputs as $in) {
            if ($in === null) continue;

            if (is_array($in)) {
                // Only merge associative arrays
                if ($this->isAssoc($in)) {
                    foreach ($in as $k => $v) {
                        $out[(string)$k] = $this->sanitizeValue($v);
                    }
                }
                continue;
            }

            // strings with no key are ignored (or log later)
        }
        return $out;
    }

    private function extractVariables(string $tpl): array
    {
        if ($this->templateEngine instanceof templates_class) {
            return $this->templateEngine->extractVariables($tpl);
        }

        preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $tpl, $m);
        $vars = $m[1] ?? [];
        $vars = array_values(array_unique($vars));
        sort($vars);
        return $vars;
    }

    private function render(string $tpl, array $bindings): string
    {
        if ($this->templateEngine instanceof templates_class) {
            return $this->templateEngine->render($tpl, $bindings);
        }

        // Render line-by-line so multi-line substitutions keep indentation.
        $tpl = str_replace("\r\n", "\n", $tpl);
        $lines = explode("\n", $tpl);

        foreach ($lines as &$line) {
            if (strpos($line, '{{') === false) continue;

            $matches = [];
            preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $line, $matches, PREG_OFFSET_CAPTURE);
            if (empty($matches[1])) continue;

            // Replace from end to start to preserve offsets.
            for ($idx = count($matches[1]) - 1; $idx >= 0; $idx--) {
                $key = (string)$matches[1][$idx][0];
                $pos = (int)$matches[0][$idx][1];
                $len = strlen((string)$matches[0][$idx][0]);

                $val = $this->lookupBinding($bindings, $key);
                if ($val === self::UNBOUND) {
                    $rep = '';
                } elseif (is_array($val) || is_object($val)) {
                    $rep = (string)json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } else {
                    $rep = (string)$val;
                }

                // If replacement spans multiple lines, indent subsequent lines to match
                // the placeholder line's leading whitespace.
                if (strpos($rep, "\n") !== false) {
                    $leading = '';
                    if (preg_match('/^\s*/', $line, $m2)) {
                        $leading = (string)($m2[0] ?? '');
                    }
                    $rep = rtrim($rep, "\n");
                    $rep = str_replace("\n", "\n" . $leading, $rep);
                }

                $line = substr($line, 0, $pos) . $rep . substr($line, $pos + $len);
            }
        }
        unset($line);

        return implode("\n", $lines);
    }

    private const UNBOUND = "__AI_HEADER_UNBOUND__";

    /**
     * Supports dot-notation lookups: {{ customer.name }} traverses nested arrays/objects.
     * If a key is not found, returns self::UNBOUND.
     */
    private function lookupBinding(array $bindings, string $key)
    {
        if (array_key_exists($key, $bindings)) {
            return $bindings[$key];
        }

        if (strpos($key, '.') === false) {
            return self::UNBOUND;
        }

        $parts = explode('.', $key);
        $rootKey = array_shift($parts);
        if (!array_key_exists($rootKey, $bindings)) {
            return self::UNBOUND;
        }

        $cur = $bindings[$rootKey];
        foreach ($parts as $part) {
            if (is_array($cur) && array_key_exists($part, $cur)) {
                $cur = $cur[$part];
                continue;
            }
            if (is_object($cur) && isset($cur->{$part})) {
                $cur = $cur->{$part};
                continue;
            }
            return self::UNBOUND;
        }
        return $cur;
    }

    private function sanitizeValue($v)
    {
        if (is_string($v)) {
            // remove control chars except \n\r\t
            $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
            return $v;
        }
        return $v;
    }

    private function isAssoc(array $arr): bool
    {
        $keys = array_keys($arr);
        return array_keys($keys) !== $keys;
    }

    /**
     * Parse a minimal YAML-ish format into an associative array.
     * Supported:
     *  - top-level keys: `key: value`
     *  - nested keys one+ levels via indentation (2+ spaces)
     *  - block scalars: `key: |` followed by indented lines
     * This keeps templates dependency-free (no ext-yaml required).
     */
    private function parsePayloadText(string $text): array
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($text === '') return [];

        // If the whole template is JSON, accept it directly.
        if ($text[0] === '{' || $text[0] === '[') {
            $data = json_decode($text, true);
            if (is_array($data)) return $data;
        }

        $lines = explode("\n", $text);
        $i = 0;
        return $this->parseYamlishLines($lines, $i, 0);
    }

    private function parseYamlishLines(array $lines, int &$i, int $baseIndent): array
    {
        $out = [];
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];
            if (trim($line) === '') {
                $i++;
                continue;
            }

            $indent = $this->countIndent($line);
            if ($indent < $baseIndent) {
                break;
            }

            $trim = ltrim($line);

            // Must contain a ':' to be a mapping entry.
            $pos = strpos($trim, ':');
            if ($pos === false) {
                $i++;
                continue;
            }

            $key = trim(substr($trim, 0, $pos));
            $rest = ltrim(substr($trim, $pos + 1));
            $i++;

            if ($key === '') continue;

            // Block scalar
            if ($rest === '|') {
                $block = [];
                while ($i < $n) {
                    $next = $lines[$i];
                    if (trim($next) === '') {
                        $block[] = '';
                        $i++;
                        continue;
                    }
                    $nextIndent = $this->countIndent($next);
                    if ($nextIndent <= $indent) break;
                    // Remove one indentation level beyond current key indent.
                    $block[] = substr($next, min(strlen($next), $indent + 2));
                    $i++;
                }
                $out[$key] = rtrim(implode("\n", $block));
                continue;
            }

            // Nested map
            if ($rest === '') {
                $out[$key] = $this->parseYamlishLines($lines, $i, $indent + 2);
                continue;
            }

            // Inline scalar
            $out[$key] = $rest;
        }

        return $out;
    }

    private function countIndent(string $line): int
    {
        $c = 0;
        $len = strlen($line);
        while ($c < $len && $line[$c] === ' ') $c++;
        return $c;
    }
}

// Backward-compatible class alias while callers migrate.
if (!class_exists('AI_Header') && class_exists('AI_Template')) {
    class_alias('AI_Template', 'AI_Header');
}
