<?php
// CLI test script for AI_Template
// Run:
//   php /web/html/admin/AI_Templates/test_ai_template.php

require_once __DIR__ . '/AI_Template.php';

$ai = new AI_Template([
	'debug' => true,
	// Policy: missing vars ignored
	'missing_policy' => 'ignore',
]);

$template = <<<TPL
system: |
  You are Domain Memory.
  Keep responses concise.

persona: |
  You are helping {{ customer.name }}.

context:
  attachments: |
    {{ attachments }}

prompt: |
  {{ prompt }}
TPL;

$bindings = [
	'prompt' => 'Summarize the customer profile in one sentence.',
	'attachments' => "Name: Alice\nRole: Operator\n",
	'customer' => [
		'name' => 'Alice',
		'id' => 123,
	],
];

$compiled = $ai->compile($template, $bindings);

echo "<br>== compiled (keys) ==\n";
print_r(array_keys($compiled));

echo "<br>\n== unbound_variables ==\n";
print_r($compiled['unbound_variables']);

echo "<br>\n== payload_text ==\n";
echo $compiled['payload_text'] . "\n";

echo "<br>\n== payload (array) ==\n";
print_r($compiled['payload']);

echo "<br>\n== payload (json) ==\n";
echo $ai->compilePayloadJson($template, $bindings) . "\n";

// Demonstrate missing var is ignored
$template2 = "Hello {{ missing_var }} world. Customer={{ customer.name }}";
$compiled2 = $ai->compile($template2, $bindings);

echo "<br>\n== missing var ignored ==\n";
echo $compiled2['payload_text'] . "\n";
print_r($compiled2['unbound_variables']);
