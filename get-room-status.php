<?php

// Deliberately public and read-only; do not include authenticated dashboard APIs.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET requests are supported.']);
    exit;
}
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/public_room_status.php';
try {
    echo json_encode(['success' => true] + publicRoomStatus(getDB()), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    error_log('[GCST] Public room status: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load room availability. Please try again shortly.']);
}
