<?php
// /web/api.iernc.net/public_html/v1/lib/request_logger.php
// logger requires require __DIR__.'/db.php';

function log_request(string $endpoint, array $data): void {
  $db = new PDO('sqlite:' . __DIR__ . '/../data/requests.db');
  $stmt = $db->prepare("INSERT INTO requests (timestamp, endpoint, data) VALUES (:timestamp, :endpoint, :data)");
  $stmt->execute([
    ':timestamp' => time(),
    ':endpoint' => $endpoint,
    ':data' => json_encode($data)
  ]);
}
// Example usage:
// log_request('example_endpoint', ['key' => 'value']);
// log_request($path, $body);
?>