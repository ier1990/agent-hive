<?php
// Shared AI template loader/compiler (PHP 7.3+)

if (!function_exists('ai_templates_db_path')) {
	function ai_templates_db_path(): string
	{
		$root = defined('PRIVATE_ROOT') ? (string)PRIVATE_ROOT : '/web/private';
		return rtrim($root, "/\\") . '/db/memory/ai_header.db';
	}
}

if (!function_exists('ai_templates_list_names')) {
	function ai_templates_list_names(string $type = 'payload'): array
	{
		$path = ai_templates_db_path();
		if (!is_file($path)) return [];
		$out = [];
		try {
			$db = new SQLite3($path);
			$stmt = $db->prepare('SELECT name FROM ai_header_templates WHERE type = :type ORDER BY name ASC');
			$stmt->bindValue(':type', $type, SQLITE3_TEXT);
			$res = $stmt->execute();
			while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
				$name = (string)($row['name'] ?? '');
				if ($name !== '') $out[] = $name;
			}
			$db->close();
		} catch (Throwable $t) {
			return [];
		}
		return $out;
	}
}

if (!function_exists('ai_templates_get_text_by_name')) {
	function ai_templates_get_text_by_name(string $name, string $type = ''): string
	{
		$path = ai_templates_db_path();
		if (!is_file($path) || trim($name) === '') return '';
		try {
			$db = new SQLite3($path);
			if ($type !== '') {
				$stmt = $db->prepare('SELECT template_text FROM ai_header_templates WHERE name = :name AND type = :type LIMIT 1');
				$stmt->bindValue(':name', $name, SQLITE3_TEXT);
				$stmt->bindValue(':type', $type, SQLITE3_TEXT);
			} else {
				$stmt = $db->prepare('SELECT template_text FROM ai_header_templates WHERE name = :name LIMIT 1');
				$stmt->bindValue(':name', $name, SQLITE3_TEXT);
			}
			$res = $stmt->execute();
			$row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
			$db->close();
			return is_array($row) ? (string)($row['template_text'] ?? '') : '';
		} catch (Throwable $t) {
			return '';
		}
	}
}

if (!function_exists('ai_templates_compile_payload')) {
	function ai_templates_compile_payload(string $templateText, array $bindings = []): array
	{
		$classFile = dirname(__DIR__) . '/lib/ai_templates_class.php';
		if (!is_file($classFile)) {
			return [];
		}
		require_once $classFile;
		if (!class_exists('AI_Template')) {
			return [];
		}
		$ai = new AI_Template([
			'missing_policy' => 'ignore',
			'debug' => false,
		]);
		$payload = $ai->compilePayload($templateText, $bindings);
		return is_array($payload) ? $payload : [];
	}
}

if (!function_exists('ai_templates_compile_payload_by_name')) {
	function ai_templates_compile_payload_by_name(string $name, array $bindings = [], string $type = 'payload'): array
	{
		$tpl = ai_templates_get_text_by_name($name, $type);
		if ($tpl === '' && $type !== '') {
			$tpl = ai_templates_get_text_by_name($name, '');
		}
		if ($tpl === '') return [];
		return ai_templates_compile_payload($tpl, $bindings);
	}
}
