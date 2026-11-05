<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';
requireLogin(['admin', 'maintenance']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') jsonResponse(false, 'Method not allowed.', [], 405);

try {
    $pdo = getDB();

    $query = $_GET['query'] ?? $_GET['search'] ?? '';
    if (!is_string($query)) {
        http_response_code(400);
        echo json_encode(['message' => 'The query must be a string.']);
        exit;
    }
    $search = trim($query);
    $sql = 'SELECT student_faculty_id, first_name, last_name, department_course
            FROM borrowers';
    $params = [];

    if ($search !== '') {
        $sql .= " WHERE student_faculty_id LIKE ?
                     OR first_name LIKE ?
                     OR last_name LIKE ?
                     OR CONCAT_WS(' ', first_name, last_name) LIKE ?";
        $params = array_fill(0, 4, '%' . $search . '%');
    }

    $sql .= ' ORDER BY last_name ASC, first_name ASC, student_faculty_id ASC LIMIT 50';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $borrowers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($borrowers);
    exit;
} catch (Throwable $e) {
    error_log('get-borrowers.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load borrower records.',
        'borrowers' => [],
    ]);
    exit;
}
