<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../services/borrower_management.php';
require_once __DIR__ . '/../services/overdue.php';
requireLogin('admin');
$pdo = getDB();
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
            if (!$id || $id < 1) throw new InvalidArgumentException('A valid borrower ID is required.');
            $borrower = managedBorrower($pdo, $id);
            if (($_GET['action'] ?? '') === 'history') {
                $stmt = $pdo->prepare("SELECT b.id,b.borrower_name,b.department,b.borrow_date,b.time_out,b.expected_return_date,
                    b.due_date,b.expected_return_time,b.actual_time_in,b.status,
                    CASE WHEN b.status='Borrowed' AND " . overdueDeadlineSql('b') . "<CURRENT_TIMESTAMP THEN 'Overdue' ELSE b.status END AS current_status,
                    b.penalty_amount,b.damage_penalty_amount,(b.penalty_amount+b.damage_penalty_amount) AS total_penalty_amount,
                    e.equip_code,e.name AS equipment_name
                    FROM borrowed b JOIN equipment e ON e.id=b.equipment_id WHERE b.student_faculty_id=? ORDER BY b.borrow_date DESC,b.id DESC");
                $stmt->execute([$borrower['student_faculty_id']]);
                jsonResponse(true, 'OK', ['borrower'=>$borrower,'history'=>$stmt->fetchAll()]);
            }
            jsonResponse(true, 'OK', ['borrower'=>$borrower]);
        }
        $search = $_GET['search'] ?? '';
        if (!is_string($search) || mb_strlen($search) > 150) throw new InvalidArgumentException('Search must be text of at most 150 characters.');
        $sql = 'SELECT id,student_faculty_id,first_name,last_name,department_course,contact_number,email,created_at FROM borrowers';
        $params = [];
        if (trim($search) !== '') {
            $sql .= " WHERE student_faculty_id LIKE ? OR CONCAT_WS(' ',first_name,last_name) LIKE ? OR department_course LIKE ? OR contact_number LIKE ? OR email LIKE ?";
            $params = array_fill(0, 5, '%' . trim($search) . '%');
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY last_name,first_name,student_faculty_id,id');
        $stmt->execute($params);
        jsonResponse(true, 'OK', ['borrowers'=>$stmt->fetchAll()]);
    }
    if (in_array($method, ['POST','PUT','DELETE'], true)) {
        $body = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') ? bodyJson() : ($method === 'POST' ? $_POST : bodyForm());
        $id = null;
        if ($method !== 'POST') {
            $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) throw new InvalidArgumentException('A valid borrower ID is required.');
        }
        if ($method === 'DELETE') {
            deleteManagedBorrower($pdo, $id);
            logActivity($pdo, 'DELETE_BORROWER', 'borrower:' . $id);
            jsonResponse(true, 'Borrower deleted.');
        }
        $row = saveManagedBorrower($pdo, $body, $id);
        logActivity($pdo, $id ? 'UPDATE_BORROWER' : 'CREATE_BORROWER', 'borrower:' . $row['id']);
        jsonResponse(true, $id ? 'Borrower updated.' : 'Borrower registered.', ['borrower'=>$row], $id ? 200 : 201);
    }
    jsonResponse(false, 'Method not allowed.', [], 405);
} catch (InvalidArgumentException $error) { jsonResponse(false, $error->getMessage(), [], 422);
} catch (OutOfBoundsException $error) { jsonResponse(false, $error->getMessage(), [], 404);
} catch (DomainException $error) { jsonResponse(false, $error->getMessage(), [], 409);
} catch (Throwable $error) {
    error_log('Borrower management: ' . $error->getMessage());
    $constraint = $error instanceof PDOException && $error->getCode() === '23000';
    jsonResponse(false, $constraint ? 'This change conflicts with an existing ID or retained borrowing history.' : 'Unable to process borrower records. Please try again.', [], $constraint ? 409 : 500);
}
