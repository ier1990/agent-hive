<?php

declare(strict_types=0);

// Debug info - set to false to hide
if (true) {
	echo '<div style="background: #1a1a1a; color: #0f0; padding: 12px; margin: 20px; border: 2px solid #0f0; font-family: monospace; font-size: 0.85rem;">';
	echo '<strong>Debug Info:</strong><br>';
	echo "REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD'] . "<br>";
	echo "CONTENT_LENGTH: " . ($_SERVER['CONTENT_LENGTH'] ?? 'not set') . "<br>";
	echo "POST count: " . count($_POST) . " | FILES count: " . (isset($_FILES['files']) ? count($_FILES['files']['name'] ?? []) : 0) . "<br>";
	echo "post_max_size: " . ini_get('post_max_size') . " | upload_max_filesize: " . ini_get('upload_max_filesize') . "<br>";
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST)) {
		echo '<span style="color: #ff4444; font-weight: bold;">⚠ POST is empty - likely exceeds post_max_size!</span>';
	}
	echo '</div>';
}
