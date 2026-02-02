<?php

function logRequest(array $data): void {
    $logFile = '/web/private/logs/requests.log';
    $logEntry = date('Y-m-d H:i:s') . ' ' . json_encode($data) . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}
// Example usage:
// logRequest(['endpoint' => 'example', 'status' => 'success']);

// Note: This is a simple logger. For production use, consider log rotation and error handling.