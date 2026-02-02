<?php

declare(strict_types=0);

// Expects: $db, $view

$parentId = (int)($_GET['parent_id'] ?? 0);
if ($parentId > 0 && isset($db) && $db instanceof SQLite3) {
	$parentNote = fetchNoteById($db, $parentId);
	if ($parentNote) {
		echo '<div class="card" id="form">';
		echo '<div class="parent-note">';
		echo '<h3>Replying to:</h3>';
		echo '<div class="note-meta">';
		echo '<span class="note-type">' . htmlspecialchars((string)($parentNote['notes_type'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		if (!empty($parentNote['topic'])) {
			echo '<span class="note-topic">' . htmlspecialchars((string)$parentNote['topic'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		}
		echo '<span class="note-date">' . htmlspecialchars((string)($parentNote['created_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
		echo '<a class="note-link" href="#note-' . (int)($parentNote['id'] ?? 0) . '">#' . (int)($parentNote['id'] ?? 0) . '</a>';
		echo '</div>';
		echo '<div class="note-body">' . renderMarkdown((string)($parentNote['note'] ?? '')) . '</div>';
		echo '</div>';
	} else {
		echo '<div class="card" id="form">';
	}
} else {
	echo '<div class="card" id="form">';
}
?>
	<form method="post" enctype="multipart/form-data">
		<input type="hidden" name="action" value="create">
		<div>
			<label for="note">Note</label>
			<textarea id="note" name="note" required placeholder="Write markdown-enabled note..."></textarea>
		</div>
		<div>
			<label for="topic">Topic (optional)</label>
			<input type="text" id="topic" name="topic" placeholder="Group notes by topic..." />
		</div>
		<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
			<div>
				<label for="node">Node (optional)</label>
				<input type="text" id="node" name="node" placeholder="lan-142 / do1 / jville" />
			</div>
			<div>
				<label for="path">Path (optional)</label>
				<input type="text" id="path" name="path" value="/web/html/v1/inbox.php" />
			</div>
			<div>
				<label for="version">Version (optional)</label>
				<input type="text" id="version" name="version" placeholder="2025-12-26.1" />
			</div>
		</div>
		<div>
			<label for="notes_type">Type</label>
			<select id="notes_type" name="notes_type">
				<?php foreach (NOTES_TYPES as $t): ?>
					<option value="<?= $t ?>"><?= $t ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label for="parent_id">Parent ID (optional)</label>
			<input type="text" id="parent_id" name="parent_id" value="<?= $parentId ?: '' ?>" inputmode="numeric" pattern="[0-9]*" placeholder="Reply to note id" />
		</div>
		<div>
			<label for="files">Upload Files (optional)</label>
			<input type="file" id="files" name="files[]" multiple accept="*/*" />
			<p class="file-hint">Up to 50MB per file. Multiple files supported.</p>
		</div>
		<button type="submit">Save Note</button>
	</form>
</div>
