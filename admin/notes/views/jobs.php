<?php
// Expects: $db (SQLite3|null)

$rows = [];
if ($db instanceof SQLite3) {
	try {
		$res = $db->query("SELECT job, last_start, last_ok, last_status, last_message, last_duration_ms FROM job_runs ORDER BY job ASC");
		while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
			$rows[] = $r;
		}
	} catch (Throwable $e) {
		echo '<div class="card"><div class="muted">Failed to load job_runs: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div></div>';
		return;
	}
}

?>
<div class="card">
	<h2 style="margin:0 0 10px 0;">Jobs</h2>
	<div class="muted" style="margin-bottom:10px;">Cron heartbeat (start/ok/error + duration).</div>

	<?php if (!($db instanceof SQLite3)): ?>
		<div class="muted">Notes DB not available.</div>
	<?php elseif (empty($rows)): ?>
		<div class="muted">No job heartbeat rows yet. Once cron runs, they will appear here.</div>
	<?php else: ?>
		<div style="display:grid; grid-template-columns: 1.3fr 0.8fr 1fr 2fr; gap:10px; font-size:0.95rem;">
			<div class="muted" style="font-weight:700;">Job</div>
			<div class="muted" style="font-weight:700;">Status</div>
			<div class="muted" style="font-weight:700;">Last OK</div>
			<div class="muted" style="font-weight:700;">Details</div>

			<?php foreach ($rows as $r):
				$job = (string)($r['job'] ?? '');
				$status = (string)($r['last_status'] ?? '');
				$lastOk = (string)($r['last_ok'] ?? '');
				$lastStart = (string)($r['last_start'] ?? '');
				$dur = $r['last_duration_ms'] ?? null;
				$msg = (string)($r['last_message'] ?? '');

				$badgeBg = 'rgba(255,255,255,0.06)';
				$badgeColor = 'var(--text)';
				if ($status === 'ok') { $badgeBg = 'rgba(113, 255, 199, 0.12)'; $badgeColor = '#72ffd8'; }
				if ($status === 'error') { $badgeBg = 'rgba(255, 107, 107, 0.14)'; $badgeColor = '#ff8787'; }
				if ($status === 'running') { $badgeBg = 'rgba(255, 214, 102, 0.14)'; $badgeColor = '#ffd666'; }

				$detail = '';
				if ($lastStart !== '') $detail .= 'start: ' . $lastStart;
				if ($dur !== null && $dur !== '') $detail .= ($detail !== '' ? ' | ' : '') . 'dur: ' . (int)$dur . 'ms';
				if ($msg !== '') $detail .= ($detail !== '' ? ' | ' : '') . $msg;
			?>
				<div><strong><?= htmlspecialchars($job, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></div>
				<div><span style="padding:4px 8px; border-radius:999px; background:<?= $badgeBg ?>; color:<?= $badgeColor ?>; font-weight:700;"><?= htmlspecialchars($status !== '' ? $status : 'unknown', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></div>
				<div class="muted"><?= htmlspecialchars($lastOk !== '' ? $lastOk : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
				<div class="muted" style="white-space:pre-wrap;"><?= htmlspecialchars($detail !== '' ? $detail : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
