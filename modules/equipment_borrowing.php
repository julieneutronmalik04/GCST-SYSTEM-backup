<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/equipment_predictions.php';
require_once __DIR__ . '/../services/overdue.php';
require_once __DIR__ . '/../services/damage_disposal.php';
require_once __DIR__ . '/../services/borrowing.php';

requireLogin(['admin', 'maintenance']);
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDB();
refreshOverdue($pdo);

if ($method === 'GET') {
    $status = get('status');
    $search = get('search');
    
    $sql    = "SELECT b.*, (b.penalty_amount + b.damage_penalty_amount) AS total_penalty_amount, e.equip_code, e.name as equip_name,
                      CASE
                        WHEN b.status = 'Borrowed' AND " . overdueDeadlineSql('b') . " < CURRENT_TIMESTAMP
                        THEN 'Overdue'
                        ELSE b.status
                      END AS current_status
               FROM borrowed b 
               JOIN equipment e ON b.equipment_id = e.id 
               WHERE 1=1";
    $params = [];
    
    if ($status !== '') {
        $sql .= " AND b.status = ?";
        $params[] = $status;
    }
    if ($search !== '') {
        $sql .= " AND (e.equip_code LIKE ? OR e.name LIKE ? OR b.borrower_name LIKE ? OR b.department LIKE ?)";
        $like = "%$search%";
        array_push($params, $like, $like, $like, $like);
    }
    
    $sql .= " ORDER BY b.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $borrowings = $stmt->fetchAll();
    
    $raw = $pdo->query(
        "SELECT
            CASE
              WHEN status = 'Borrowed' AND " . overdueDeadlineSql() . " < CURRENT_TIMESTAMP
              THEN 'Overdue'
              ELSE status
            END AS current_status,
            COUNT(*) c
         FROM borrowed
         GROUP BY current_status"
    )->fetchAll();
    $stats = ['total' => 0, 'Borrowed' => 0, 'Overdue' => 0, 'Returned' => 0, 'Returned (Damaged)' => 0];
    foreach ($raw as $r) {
        $stats[$r['current_status']] = (int)$r['c'];
        $stats['total']     += (int)$r['c'];
    }
    
    jsonResponse(true, 'OK', ['borrowings' => $borrowings, 'stats' => $stats]);
}

if ($method === 'POST') {
    try {
        $newId = createBorrowing($pdo, $_POST);
    } catch (InvalidArgumentException $error) {
        jsonResponse(false, $error->getMessage(), [], 422);
    } catch (DomainException $error) {
        jsonResponse(false, $error->getMessage(), [], 409);
    } catch (Throwable $error) {
        error_log('Borrowing creation: ' . $error->getMessage());
        jsonResponse(false, 'Unable to create borrowing. Please try again.', [], 500);
    }
    // A prediction failure must not disguise a committed checkout as a failure.
    try { updateEquipmentPredictions($pdo); } catch (Throwable $error) { error_log($error->getMessage()); }
    logActivity($pdo, 'CREATE_BORROWING', "borrow:$newId", 'Registered borrower checkout');
    jsonResponse(true, 'Equipment borrowing recorded successfully.', ['borrowing_id' => $newId]);
}

if ($method === 'PATCH') {
    $body = bodyJson();
    foreach (['return_condition','damage_level','reason','assessed_cost','student_faculty_id'] as $field) if (isset($body[$field]) && !is_scalar($body[$field])) jsonResponse(false, 'Invalid return field: ' . $field, [], 422);
    $id = (int) ($body['id'] ?? 0);
    if (!$id || ($body['action'] ?? '') !== 'return') jsonResponse(false, 'A valid return transaction is required.', [], 422);
    try {
        $result = processEquipmentReturn($pdo, $id, $body['return_condition'] ?? 'good', $body, getUserId());
    } catch (InvalidArgumentException $e) {
        jsonResponse(false, $e->getMessage(), [], 422);
    } catch (Throwable $e) {
        error_log('Equipment return failed: ' . $e->getMessage());
        jsonResponse(false, 'Unable to process the equipment return.', [], 500);
    }
    // Prediction refresh must not turn a committed return into an apparent failure.
    try { updateEquipmentPredictions($pdo, $result['equipment']['id']); } catch (Throwable $e) { error_log($e->getMessage()); }
    logActivity($pdo, 'RETURN_EQUIPMENT', 'borrow:' . $id, $result['borrowing_status'] . '; damage fee: ' . $result['damage_penalty_amount']);
    jsonResponse(true, 'Return recorded. Damage penalty: ' . $result['damage_penalty_amount'] . '.', $result);
}

if ($method === 'DELETE') {
    requireLogin('admin');
    parse_str(file_get_contents('php://input'), $b);
    $id = (int)($b['id'] ?? 0);
    
    if (!$id) jsonResponse(false, 'ID is required.');

    $active = $pdo->prepare("SELECT COUNT(*) FROM borrowed WHERE id = ? AND status IN ('Borrowed','Overdue')");
    $active->execute([$id]);
    if ($active->fetchColumn()) jsonResponse(false, 'Return active equipment before deleting a transaction.', [], 409);
    $history = $pdo->prepare('SELECT COUNT(*) FROM damage_assessments WHERE borrowed_id = ?');
    $history->execute([$id]);
    if ($history->fetchColumn()) jsonResponse(false, 'Borrowings with damage assessments and fees must be retained for reports.', [], 409);
    
    try { $pdo->prepare('DELETE FROM borrowed WHERE id = ?')->execute([$id]); }
    catch (PDOException $error) { jsonResponse(false, 'This transaction is retained by its history or migration audit trail.', [], 409); }
    logActivity($pdo, 'DELETE_BORROWING', "borrow:$id");
    jsonResponse(true, 'Borrowing record deleted.');
}

jsonResponse(false, 'Method not allowed.', [], 405);
