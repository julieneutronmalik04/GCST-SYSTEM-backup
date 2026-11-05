<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/services/overdue.php';
try {
    $pdo = getDB();
    $result = refreshOverdue($pdo, !in_array('--no-notify', $argv, true));
    if (!in_array('--no-notify', $argv, true)) $result['borrowing_emails'] = dispatchBorrowingEmails($pdo);
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Overdue check failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

