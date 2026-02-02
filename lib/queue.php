<?php
require __DIR__.'/db.php';

function q_status(string $id): ?array {
  $st = db()->prepare("SELECT id,type,status,attempts,max_attempts,not_before,created_at,updated_at,result_json,error_msg FROM jobs WHERE id=:id");
  $st->execute([':id'=>$id]);
  $row = $st->fetch();
  if (!$row) return null;
  if ($row['result_json']) $row['result'] = json_decode($row['result_json'], true);
  unset($row['result_json']);
  return $row;
}

function q_cancel(string $id): bool {
  $st = db()->prepare("UPDATE jobs SET status='canceled', updated_at=strftime('%s','now') WHERE id=:id AND status IN ('queued')");
  $st->execute([':id'=>$id]);
  return $st->rowCount() > 0;
}


//$jobId = q_enqueue('chat', ['backend'=>$backend,'model'=>$model,'messages'=>$messages], 0, 0, 3, hash('sha1', json_encode($messages)));
function q_enqueue(string $type, array $payload, int $priority=0, int $delaySec=0, int $maxAttempts=3, string $dedupeKey=''): string {
  $pdo = db();
  $now = time(); $nb = $now + max(0,$delaySec);
  if ($dedupeKey !== '') {
    // return existing queued/processing in last 60s
    $st = $pdo->prepare("SELECT id FROM jobs
       WHERE dedupe_key=:k AND status IN ('queued','processing')
         AND created_at > strftime('%s','now')-60
       ORDER BY created_at DESC LIMIT 1");
    $st->execute([':k'=>$dedupeKey]);
    if ($row = $st->fetch()) return $row['id'];
  }
  $id = ulid();
  $pdo->prepare("INSERT INTO jobs(id,type,payload_json,priority,status,attempts,max_attempts,not_before,created_at,updated_at,dedupe_key)
   VALUES(:id,:t,:p,:prio,'queued',0,:max,:nb,:now,:now,:dk)")
    ->execute([':id'=>$id, ':t'=>$type, ':p'=>json_encode($payload), ':prio'=>$priority, ':max'=>$maxAttempts, ':nb'=>$nb, ':now'=>$now, ':dk'=>$dedupeKey]);
  return $id;
}

function q_enqueue2(string $type, array $payload, int $priority=0, int $delaySec=0, int $maxAttempts=3): string {
  $id  = ulid();
  $now = time();
  $nb  = $now + max(0,$delaySec);
  $sql = "INSERT INTO jobs (id,type,payload_json,priority,status,attempts,max_attempts,not_before,created_at,updated_at)
          VALUES (:id,:type,:payload,:prio,'queued',0,:max,:nb,:now,:now)";
  db()->prepare($sql)->execute([
    ':id'=>$id, ':type'=>$type, ':payload'=>json_encode($payload, JSON_UNESCAPED_SLASHES),
    ':prio'=>$priority, ':max'=>$maxAttempts, ':nb'=>$nb, ':now'=>$now
  ]);
  return $id;
}