<?php
// /web/html/lib/templates_class.php
// Minimal template engine for PHP 7.3+
// Supports:
// - {{ variable }}
// - {{ if variable }} ... {{ else }} ... {{ endif }}
// - dot notation: {{ user.name }}

class templates_class
{
    private const UNBOUND = '__TEMPLATE_UNBOUND__';

    private $config = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'missing_policy' => 'ignore', // ignore|empty
        ], $config);
    }

    public function render(string $templateText, array $bindings = []): string
    {
        $templateText = str_replace("\r\n", "\n", $templateText);
        $lines = explode("\n", $templateText);

        $conditionalText = $this->renderConditionals($lines, $bindings);
        $conditionalText = $this->renderInlineConditionals($conditionalText, $bindings);
        return $this->renderPlaceholders($conditionalText, $bindings);
    }

    public function extractVariables(string $templateText): array
    {
        $vars = [];

        if (preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $templateText, $m1)) {
            $vars = array_merge($vars, $m1[1]);
        }

        if (preg_match_all('/\{\{\s*if\s+(!?[a-zA-Z0-9_.-]+|not\s+[a-zA-Z0-9_.-]+)\s*\}\}/i', $templateText, $m2)) {
            foreach ($m2[1] as $raw) {
                $key = trim((string)$raw);
                if (stripos($key, 'not ') === 0) {
                    $key = trim(substr($key, 4));
                }
                if (strpos($key, '!') === 0) {
                    $key = ltrim($key, '!');
                }
                if ($key !== '') {
                    $vars[] = $key;
                }
            }
        }

        $vars = array_values(array_unique($vars));
        sort($vars);
        return $vars;
    }

    private function renderConditionals(array $lines, array $bindings): string
    {
        $out = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];
            $cond = $this->parseIfDirective($line);
            if ($cond === null) {
                $out[] = $line;
                $i++;
                continue;
            }

            $scan = $this->scanIfBlock($lines, $i);
            if (!$scan['ok']) {
                // Invalid/missing endif; keep original line to avoid destructive render.
                $out[] = $line;
                $i++;
                continue;
            }

            $value = $this->lookupBinding($bindings, $scan['condition']);
            $isTrue = $this->toBool($value);
            if ($scan['negate']) {
                $isTrue = !$isTrue;
            }

            $trueLines = array_slice($lines, $scan['true_start'], $scan['true_len']);
            $falseLines = array_slice($lines, $scan['false_start'], $scan['false_len']);
            $chosen = $isTrue ? $trueLines : $falseLines;

            $renderedChosen = $this->renderConditionals($chosen, $bindings);
            if ($renderedChosen !== '') {
                $out[] = $renderedChosen;
            }

            $i = $scan['endif_index'] + 1;
        }

        return implode("\n", $out);
    }

    private function renderInlineConditionals(string $text, array $bindings): string
    {
        $guard = 0;
        while ($guard < 200) {
            $m = [];
            $ok = preg_match(
                '/\{\{\s*if\s+(!?[a-zA-Z0-9_.-]+|not\s+[a-zA-Z0-9_.-]+)\s*\}\}(.*?)\{\{\s*endif\s*\}\}/is',
                $text,
                $m,
                PREG_OFFSET_CAPTURE
            );
            if ($ok !== 1 || empty($m)) {
                break;
            }

            $full = (string)$m[0][0];
            $fullPos = (int)$m[0][1];
            $condRaw = (string)$m[1][0];
            $bodyRaw = (string)$m[2][0];

            $parts = preg_split('/\{\{\s*else\s*\}\}/i', $bodyRaw, 2);
            $trueBody = isset($parts[0]) ? (string)$parts[0] : '';
            $falseBody = isset($parts[1]) ? (string)$parts[1] : '';

            $cond = $this->parseConditionToken($condRaw);
            $val = $this->lookupBinding($bindings, $cond['condition']);
            $isTrue = $this->toBool($val);
            if ($cond['negate']) {
                $isTrue = !$isTrue;
            }

            $replacement = $isTrue ? $trueBody : $falseBody;
            $text = substr($text, 0, $fullPos) . $replacement . substr($text, $fullPos + strlen($full));
            $guard++;
        }

        return $text;
    }

    private function scanIfBlock(array $lines, int $ifIndex): array
    {
        $first = $this->parseIfDirective($lines[$ifIndex]);
        if ($first === null) {
            return ['ok' => false];
        }

        $depth = 1;
        $elseIndex = -1;
        $endifIndex = -1;
        $i = $ifIndex + 1;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];
            if ($this->parseIfDirective($line) !== null) {
                $depth++;
                $i++;
                continue;
            }

            if ($this->isEndifDirective($line)) {
                $depth--;
                if ($depth === 0) {
                    $endifIndex = $i;
                    break;
                }
                $i++;
                continue;
            }

            if ($this->isElseDirective($line) && $depth === 1 && $elseIndex === -1) {
                $elseIndex = $i;
                $i++;
                continue;
            }

            $i++;
        }

        if ($endifIndex === -1) {
            return ['ok' => false];
        }

        $trueStart = $ifIndex + 1;
        $trueEnd = ($elseIndex !== -1) ? $elseIndex : $endifIndex;
        $falseStart = ($elseIndex !== -1) ? ($elseIndex + 1) : $endifIndex;
        $falseEnd = $endifIndex;

        return [
            'ok' => true,
            'condition' => $first['condition'],
            'negate' => $first['negate'],
            'true_start' => $trueStart,
            'true_len' => max(0, $trueEnd - $trueStart),
            'false_start' => $falseStart,
            'false_len' => max(0, $falseEnd - $falseStart),
            'endif_index' => $endifIndex,
        ];
    }

    private function parseIfDirective(string $line)
    {
        $trim = trim($line);
        if (!preg_match('/^\{\{\s*if\s+(!?[a-zA-Z0-9_.-]+|not\s+[a-zA-Z0-9_.-]+)\s*\}\}$/i', $trim, $m)) {
            return null;
        }

        return $this->parseConditionToken((string)$m[1]);
    }

    private function parseConditionToken(string $token): array
    {
        $token = trim($token);
        $negate = false;

        if (stripos($token, 'not ') === 0) {
            $negate = true;
            $token = trim(substr($token, 4));
        }
        if (strpos($token, '!') === 0) {
            $negate = true;
            $token = ltrim($token, '!');
        }

        return [
            'condition' => $token,
            'negate' => $negate,
        ];
    }

    private function isElseDirective(string $line): bool
    {
        return preg_match('/^\{\{\s*else\s*\}\}$/i', trim($line)) === 1;
    }

    private function isEndifDirective(string $line): bool
    {
        return preg_match('/^\{\{\s*endif\s*\}\}$/i', trim($line)) === 1;
    }

    private function renderPlaceholders(string $text, array $bindings): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $lines = explode("\n", $text);

        foreach ($lines as &$line) {
            if (strpos($line, '{{') === false) {
                continue;
            }

            $matches = [];
            preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $line, $matches, PREG_OFFSET_CAPTURE);
            if (empty($matches[1])) {
                continue;
            }

            for ($idx = count($matches[1]) - 1; $idx >= 0; $idx--) {
                $key = (string)$matches[1][$idx][0];
                $pos = (int)$matches[0][$idx][1];
                $len = strlen((string)$matches[0][$idx][0]);

                $val = $this->lookupBinding($bindings, $key);
                if ($val === self::UNBOUND) {
                    $rep = '';
                } elseif (is_array($val) || is_object($val)) {
                    $json = json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $rep = $json === false ? '' : $json;
                } else {
                    $rep = (string)$val;
                }

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

    private function toBool($value): bool
    {
        if ($value === self::UNBOUND) return false;
        if ($value === null) return false;
        if ($value === false) return false;
        if ($value === 0 || $value === 0.0) return false;
        if ($value === '0') return false;
        if ($value === '') return false;
        if (is_array($value) && count($value) === 0) return false;
        return true;
    }
}
