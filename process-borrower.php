<?php
require_once __DIR__ . '/config/db.php';

function redirectWithMessage(string $status, string $message): void {
    $redirect = '../frontend/register-borrower.html?status=' . urlencode($status) . '&message=' . urlencode($message);
    header('Location: ' . $redirect);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithMessage('error', 'Invalid request method.');
}

$requiredFields = [
    'student_faculty_id',
    'first_name',
    'last_name',
    'department_course',
    'email',
    'contact_number',
];

$data = [];
foreach ($requiredFields as $field) {
    if (!isset($_POST[$field]) || !is_string($_POST[$field])) redirectWithMessage('error', 'Please complete all required fields.');
    $value = trim($_POST[$field]);
    if ($value === '') {
        redirectWithMessage('error', 'Please complete all required fields.');
    }
    $data[$field] = $value;
}

$data['email'] = strtolower($data['email']);
if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    redirectWithMessage('error', 'Please enter a valid email address.');
}

foreach (['student_faculty_id'=>50,'first_name'=>100,'last_name'=>100,'department_course'=>150,'email'=>150,'contact_number'=>50] as $field=>$limit) {
    if (mb_strlen($data[$field], 'UTF-8') > $limit) redirectWithMessage('error', 'One or more fields exceed the allowed length.');
}
$data['contact_number'] = preg_replace('/[\s()\-]+/', '', $data['contact_number']);
if (!preg_match('/^\+?[0-9]{10,15}$/D', $data['contact_number'])) redirectWithMessage('error', 'Please enter a valid contact number with 10 to 15 digits.');
$studentFacultyId = $data['student_faculty_id'];
$pdo = getDB();

try {
    $checkStmt = $pdo->prepare('SELECT id FROM borrowers WHERE student_faculty_id = ? LIMIT 1');
    $checkStmt->execute([$studentFacultyId]);

    if ($checkStmt->fetch()) {
        redirectWithMessage('error', 'A borrower with this Student/Faculty ID already exists.');
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO borrowers (student_faculty_id, first_name, last_name, department_course, email, contact_number, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );

    $insertStmt->execute([
        $data['student_faculty_id'],
        $data['first_name'],
        $data['last_name'],
        $data['department_course'],
        $data['email'],
        $data['contact_number'],
    ]);

    redirectWithMessage('success', 'Borrower registration submitted successfully.');
} catch (PDOException $e) {
    error_log('Borrower registration error: ' . $e->getMessage());
    redirectWithMessage('error', $e->getCode() === '23000' ? 'A borrower with this Student/Faculty ID already exists.' : 'Unable to complete registration at the moment. Please try again later.');
}
