<?php

declare(strict_types=0);

// Expects: $prompts
?>
<div class="card">
	<div class="muted" style="margin-bottom:10px;">
		Showing <?=count($prompts ?? [])?> prompt(s)
	</div>
	<ul class="note-list">
		<?php foreach (($prompts ?? []) as $p): ?>
			<li class="note-item" id="prompt-<?= (int)$p['id'] ?>">
				<div class="note-meta">
					<span class="note-type"><?= htmlspecialchars($p['kind'] ?? 'prompt') ?></span>
					<span class="note-topic"><?= htmlspecialchars($p['name'] ?? '') ?></span>
					<?php if (!empty($p['version'])): ?>
						<span class="note-topic">v <?= htmlspecialchars($p['version']) ?></span>
					<?php endif; ?>
					<span class="note-date"><?= htmlspecialchars($p['updated_at'] ?? '') ?></span>
					<a class="note-link" href="#prompt-<?= (int)$p['id'] ?>">#P<?= (int)$p['id'] ?></a>

					<form method="post" style="display:inline;">
						<input type="hidden" name="action" value="prompt_delete" />
						<input type="hidden" name="prompt_id" value="<?= (int)$p['id'] ?>" />
						<button type="submit" class="delete-btn" onclick="return confirm('Delete this prompt?')">Delete</button>
					</form>
				</div>

				<div class="muted" style="margin:0 0 8px 0;">
					<?php if (!empty($p['tags'])): ?>tags: <?= htmlspecialchars($p['tags']) ?><?php endif; ?>
					<?php if (!empty($p['model_hint'])): ?> | model: <?= htmlspecialchars($p['model_hint']) ?><?php endif; ?>
				</div>

				<pre style="white-space:pre-wrap; border-radius:12px; border:1px solid var(--border); padding:12px; background:#0e1520; margin:0;"><?= htmlspecialchars($p['body'] ?? '') ?></pre>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
