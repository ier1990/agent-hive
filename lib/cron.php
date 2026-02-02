<?php

// Simple cron schedule matcher (PHP 7.3+).
// Supports:
//  - 5-field cron: "m h dom mon dow"
//  - Macros: @hourly, @daily, @weekly
// Fields support:
//  - "*"
//  - "*/N"
//  - "N" or "N,M,K" lists
// No ranges ("1-5"), no names.

function cron_expand_macros(string $expr): string
{
	$expr = trim($expr);
	if ($expr === '') return '';

	$lower = strtolower($expr);
	if ($lower === '@hourly') return '0 * * * *';
	if ($lower === '@daily') return '0 0 * * *';
	if ($lower === '@weekly') return '0 0 * * 0';
	return $expr;
}

function cron_field_matches(string $field, int $value, int $min, int $max): bool
{
	$field = trim($field);
	if ($field === '*') return true;

	// */N
	if (strpos($field, '*/') === 0) {
		$n = (int)trim(substr($field, 2));
		if ($n <= 0) return false;
		return ($value % $n) === 0;
	}

	// List: a,b,c
	if (strpos($field, ',') !== false) {
		$parts = explode(',', $field);
		foreach ($parts as $p) {
			$p = trim($p);
			if ($p === '') continue;
			if (!ctype_digit($p)) return false;
			$v = (int)$p;
			if ($v < $min || $v > $max) return false;
			if ($v === $value) return true;
		}
		return false;
	}

	// Single number
	if (!ctype_digit($field)) return false;
	$v = (int)$field;
	if ($v < $min || $v > $max) return false;
	return $v === $value;
}

function cron_matches(string $expr, int $ts = 0): bool
{
	$expr = cron_expand_macros($expr);
	$expr = trim($expr);
	if ($expr === '') return false;

	$parts = preg_split('/\s+/', $expr);
	if (!is_array($parts) || count($parts) !== 5) return false;

	if ($ts <= 0) $ts = time();

	$min = (int)date('i', $ts);
	$hour = (int)date('G', $ts);
	$dom = (int)date('j', $ts);
	$mon = (int)date('n', $ts);
	$dow = (int)date('w', $ts); // 0=Sun

	return (
		cron_field_matches((string)$parts[0], $min, 0, 59)
		&& cron_field_matches((string)$parts[1], $hour, 0, 23)
		&& cron_field_matches((string)$parts[2], $dom, 1, 31)
		&& cron_field_matches((string)$parts[3], $mon, 1, 12)
		&& cron_field_matches((string)$parts[4], $dow, 0, 6)
	);
}
