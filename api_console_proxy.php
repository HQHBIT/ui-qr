<?php
/**
 * api_console_proxy.php
 * Server-side proxy for the API Live Console.
 * Adds the real API key to outbound requests so it is never exposed in the browser.
 * Requires an active admin session — not accessible to unauthenticated users.
 */
require 'config.php';
require_auth();
verify_csrf();

header('Content-Type: application/json');
header('X-Robots-Tag: noindex, nofollow');

$method  = strtoupper($_POST['method'] ?? 'GET');
$uuid    = trim($_POST['uuid'] ?? '');
$payload = trim($_POST['payload'] ?? '');

$apiUrl = BASE_URL . '/api.php';
if ($method === 'GET' && $uuid !== '') {
    $apiUrl .= '?uuid=' . urlencode($uuid);
}

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-Api-Key: ' . API_KEY,
    'Content-Type: application/json',
]);

if ($method === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($httpCode);
echo $response ?: json_encode(['error' => 'No response from API']);
