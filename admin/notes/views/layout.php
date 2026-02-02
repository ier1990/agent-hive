<?php

declare(strict_types=0);

// Expects: $ctx (array)

$ctx = is_array($ctx ?? null) ? $ctx : [];
extract($ctx, EXTR_SKIP);

$view = (string)($view ?? 'human');
$search = $search ?? null;
$errors = is_array($errors ?? null) ? $errors : [];
$success = is_array($success ?? null) ? $success : [];
$navItems = is_array($navItems ?? null) ? $navItems : [];

$pageTitle = 'Notes';
if ($view === 'ai') { $pageTitle = 'AI Notes'; }
elseif ($view === 'ai_setup') { $pageTitle = 'AI Setup'; }
elseif ($view === 'dbs') { $pageTitle = 'DB Browser'; }
elseif ($view === 'bash') { $pageTitle = 'Bash History'; }
elseif ($view === 'search_cache') { $pageTitle = 'Search Cache'; }
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></title>
	<style>
		:root {
			--bg: #0b1018;
			--panel: #121a26;
			--muted: #7b8ba5;
			--text: #e8f0ff;
			--accent: #5c9dff;
			--border: #1f2a3b;
			--shadow: 0 18px 60px rgba(0, 0, 0, 0.35);
			--radius: 12px;
			--mono: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
		}
		* { box-sizing: border-box; }
		body {
			margin: 0;
			background: radial-gradient(circle at 20% 20%, rgba(92, 157, 255, 0.08), transparent 32%),
						radial-gradient(circle at 80% 0%, rgba(113, 255, 199, 0.06), transparent 28%),
						var(--bg);
			color: var(--text);
			font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
			min-height: 100vh;
			padding: 32px;
		}
		.layout { max-width: 1100px; margin: 0 auto; display: grid; gap: 18px; }
		.card {
			background: var(--panel);
			border: 1px solid var(--border);
			border-radius: var(--radius);
			box-shadow: var(--shadow);
			padding: 18px 20px;
		}
		h1 { margin: 0 0 12px 0; letter-spacing: -0.02em; }
		form { display: grid; gap: 12px; }
		label { font-size: 0.95rem; color: var(--muted); }
		textarea, select, input[type="text"] {
			width: 100%;
			background: #0e1520;
			border: 1px solid var(--border);
			color: var(--text);
			border-radius: var(--radius);
			padding: 12px;
			font-size: 1rem;
		}
		textarea { min-height: 140px; resize: vertical; }
		button {
			background: linear-gradient(120deg, #5c9dff, #72ffd8);
			border: none;
			color: #0b1018;
			font-weight: 700;
			border-radius: var(--radius);
			padding: 12px 14px;
			cursor: pointer;
			box-shadow: 0 10px 30px rgba(114, 255, 216, 0.25);
		}
		button:hover { filter: brightness(1.05); }
		.note-list { list-style: none; margin: 0; padding-left: 0; }
		.note-list .note-list { padding-left: 18px; border-left: 1px solid var(--border); margin-left: 6px; }
		.note-item { padding: 12px 10px; border-bottom: 1px solid var(--border); }
		.note-meta { display: flex; gap: 10px; align-items: center; font-size: 0.9rem; color: var(--muted); margin-bottom: 6px; }
		.note-type { padding: 4px 8px; border-radius: 999px; background: rgba(92, 157, 255, 0.14); color: #9fc5ff; text-transform: lowercase; font-weight: 600; }
		.note-topic { padding: 4px 8px; border-radius: 999px; background: rgba(113, 255, 199, 0.12); color: #72ffd8; text-transform: lowercase; font-weight: 600; }
		.note-body p { margin: 0 0 8px 0; line-height: 1.55; }
		.note-files { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border); font-size: 0.9rem; }
		.note-files ul { list-style: none; margin: 6px 0 0 0; padding-left: 16px; }
		.note-files li { margin: 4px 0; }
		.note-files a { color: var(--accent); text-decoration: none; }
		.note-files a:hover { text-decoration: underline; }
		.file-hint { font-size: 0.85rem; color: var(--muted); margin: 6px 0 0 0; }
		.parent-note { background: rgba(255, 255, 255, 0.05); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px; margin-bottom: 18px; }
		.parent-note h3 { margin: 0 0 8px 0; color: var(--accent); font-size: 1rem; }
		.note-body code { background: #0b1a2a; padding: 2px 6px; border-radius: 6px; font-family: var(--mono); }
		.note-body strong { color: #f0f4ff; }
		.note-body em { color: #b3c7ff; }
		.search { display: flex; gap: 10px; }
		.search input { flex: 1; }
		.muted { color: var(--muted); font-size: 0.9rem; }
		@media (max-width: 720px) {
			body { padding: 18px; }
			.layout { gap: 12px; }
		}
	</style>
</head>
<body>
	<div class="layout">
		<div class="card">
			<h1><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h1>
			<?= renderNavBar($navItems, (string)$view); ?>
			<?php if (!empty($errors)): ?>
				<div class="error-box">
					<strong style="color: #ff6b6b;">Issues:</strong>
					<ul style="margin: 6px 0 0 0; padding-left: 18px;">
						<?php foreach ($errors as $error): ?>
							<li style="color: #ff8787; margin: 3px 0; font-size: 0.9rem;"><?= htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if (!empty($success)): ?>
				<div class="success-box">
					<strong style="color: #6bff6b;">Success:</strong>
					<ul style="margin: 6px 0 0 0; padding-left: 18px;">
						<?php foreach ($success as $msg): ?>
							<li style="color: #87ff87; margin: 3px 0; font-size: 0.9rem;"><?= htmlspecialchars((string)$msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<form method="get" class="search">
				<input type="hidden" name="view" value="<?= htmlspecialchars($view, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
				<input type="text" name="q" value="<?= htmlspecialchars((string)($search ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="Search <?php
					echo $view === 'ai' ? 'AI metadata' : ($view === 'bash' ? 'bash history' : ($view === 'search_cache' ? 'search cache' : 'notes'));
				?>..." />
				<button type="submit">Search</button>
			</form>
		</div>

		<?php if ($view === 'prompts') {
			require __DIR__ . '/prompts_form.php';
		} ?>

		<?php if ($view !== 'ai' && $view !== 'bash' && $view !== 'search_cache' && $view !== 'ai_setup'): ?>
			<?php require __DIR__ . '/note_form.php'; ?>
		<?php endif; ?>

		<div class="card">
			<?php echo renderNotesView($view, $ctx); ?>
		</div>
	</div>

	<?php require __DIR__ . '/debug.php'; ?>
</body>
</html>
