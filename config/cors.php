<?php

function setCors() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $expected = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if ($origin !== '' && $origin !== $expected) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Cross-origin requests are not allowed.']);
        exit;
    }
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}
