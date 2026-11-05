<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../config/session.php';
    require_once __DIR__ . '/../services/class_schedule.php';

    requireLogin('admin');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Method not allowed.', [], 405);
    }

    $pdo = getDB();
    $body = $_POST;
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
    }

    $id = (int) ($body['schedule_id'] ?? $body['id'] ?? 0);
    if (!$id) throw new InvalidArgumentException('Schedule ID required.');
    saveClassSchedule($pdo, $body, getUserId(), $id);
    logActivity($pdo, 'UPDATE_CLASS_SCHEDULE', "schedule:$id");
    jsonResponse(true, 'Schedule updated.');
} catch (Throwable $e) {
    error_log('[GCST Update Schedule Error] ' . $e->getMessage());
    jsonResponse(false, $e instanceof InvalidArgumentException || $e instanceof DomainException ? $e->getMessage() : 'Unable to update the schedule.', [], $e instanceof InvalidArgumentException ? 422 : ($e instanceof DomainException ? 409 : 500));
}
