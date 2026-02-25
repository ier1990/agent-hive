<?php
// /web/html/admin/AI_Templates/test/run.php
// Simple CLI smoke tests for template rendering and payload compilation.

require_once dirname(__DIR__, 3) . '/lib/templates_class.php';
require_once dirname(__DIR__, 3) . '/lib/ai_templates_class.php';

$failures = [];
$passes = 0;

function t_assert($condition, $message, &$failures, &$passes)
{
    if ($condition) {
        $passes++;
        echo "[PASS] " . $message . "\n";
        return;
    }
    $failures[] = $message;
    echo "[FAIL] " . $message . "\n";
}

function t_load_json($path)
{
    if (!is_file($path)) {
        return [false, null, 'missing file: ' . $path];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [false, null, 'empty file: ' . $path];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [false, null, 'invalid json: ' . $path];
    }
    return [true, $decoded, ''];
}

$tpl = new templates_class();

$renderA = $tpl->render("Hello {{ user.name }}", ['user' => ['name' => 'Sam']]);
t_assert($renderA === 'Hello Sam', 'templates_class variable + dot notation', $failures, $passes);

$renderB = $tpl->render("{{ if is_admin }}A{{ else }}B{{ endif }}", ['is_admin' => true]);
t_assert($renderB === 'A', 'templates_class if/else true branch', $failures, $passes);

$renderC = $tpl->render("{{ if is_admin }}A{{ else }}B{{ endif }}", ['is_admin' => false]);
t_assert($renderC === 'B', 'templates_class if/else false branch', $failures, $passes);

$renderD = $tpl->render("{{ if is_admin }}A{{ endif }}", ['is_admin' => false]);
t_assert($renderD === '', 'templates_class if without else false branch', $failures, $passes);

$renderE = $tpl->render("{{ if not is_guest }}ok{{ endif }}", ['is_guest' => false]);
t_assert($renderE === 'ok', 'templates_class not condition', $failures, $passes);

$ai = new AI_Template(['missing_policy' => 'ignore']);

$aiTpl = "system: |\n  You are helpful\nuser: |\n  {{ if user.is_admin }}admin{{ else }}user{{ endif }}\n";
$payload = $ai->compilePayload($aiTpl, ['user' => ['is_admin' => true]]);

$systemOk = is_array($payload) && isset($payload['system']) && trim((string)$payload['system']) === 'You are helpful';
$userOk = is_array($payload) && isset($payload['user']) && trim((string)$payload['user']) === 'admin';
t_assert($systemOk, 'AI_Template compilePayload system field', $failures, $passes);
t_assert($userOk, 'AI_Template compilePayload conditional user field', $failures, $passes);

t_assert(class_exists('AI_Header'), 'backward class alias AI_Header exists', $failures, $passes);

$defaultsPath = dirname(__DIR__, 2) . '/defaults/templates_ai_templates.json';
list($okDefaults, $defaults, $errDefaults) = t_load_json($defaultsPath);
t_assert($okDefaults, 'defaults JSON readable: templates_ai_templates.json', $failures, $passes);
if ($okDefaults) {
    $schema = (string)($defaults['schema'] ?? '');
    t_assert($schema === 'ai_template_templates', 'defaults schema is ai_template_templates', $failures, $passes);

    $templates = (isset($defaults['templates']) && is_array($defaults['templates'])) ? $defaults['templates'] : [];
    t_assert(count($templates) > 0, 'defaults includes templates array', $failures, $passes);

    $bad = 0;
    foreach ($templates as $item) {
        if (!is_array($item)) {
            $bad++;
            continue;
        }
        $name = trim((string)($item['name'] ?? ''));
        $type = trim((string)($item['type'] ?? ''));
        $text = (string)($item['template_text'] ?? '');
        if ($name === '' || $type === '' || trim($text) === '') {
            $bad++;
        }
    }
    t_assert($bad === 0, 'defaults template records are complete', $failures, $passes);
}

$storyImportPath = dirname(__DIR__, 2) . '/AI_Story/story_templates_ai_templates_import.json';
list($okStory, $storyDefaults, $errStory) = t_load_json($storyImportPath);
t_assert($okStory, 'story template import JSON readable', $failures, $passes);
if ($okStory) {
    $schemaStory = (string)($storyDefaults['schema'] ?? '');
    t_assert($schemaStory === 'ai_template_templates', 'story import schema is ai_template_templates', $failures, $passes);

    $storyTemplates = (isset($storyDefaults['templates']) && is_array($storyDefaults['templates'])) ? $storyDefaults['templates'] : [];
    t_assert(count($storyTemplates) > 0, 'story import includes templates array', $failures, $passes);
}

echo "\n";
echo 'Passes: ' . $passes . "\n";
echo 'Failures: ' . count($failures) . "\n";

if (!empty($failures)) {
    exit(1);
}
exit(0);
