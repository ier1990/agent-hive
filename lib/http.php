<?php 
//Call to undefined function curl_init() in /web/html/v1/lib/http.php


function http_get_json($url, $headers = [], $assoc = 1, $timeout = 5) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL error: " . $error_msg);
    }
    curl_close($ch);
    return [
        'status' => $httpCode,
        'body' => json_decode($response, $assoc)
    ];
}
function http_post_json($url, $data, $headers = [], $assoc = 1, $timeout = 5) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $allHeaders = array_merge($headers, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL error: " . $error_msg);
    }
    curl_close($ch);
    return [
        'status' => $httpCode,
        'body' => json_decode($response, $assoc)
    ];
}

?>