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
    if (!$id) {
        jsonResponse(false, 'Schedule ID required.');
    }

    deleteClassSchedule($pdo, $id);

    logActivity($pdo, 'DELETE_CLASS_SCHEDULE', "schedule:$id");
    jsonResponse(true, 'Schedule deleted.');
} catch (Throwable $e) {
    error_log('[GCST Delete Schedule Error] ' . $e->getMessage());
    jsonResponse(false, $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to delete the schedule.', [], $e instanceof InvalidArgumentException ? 422 : 500);
}
