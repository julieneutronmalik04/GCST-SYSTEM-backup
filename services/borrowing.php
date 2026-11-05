<?php
require_once __DIR__ . '/input_validation.php';
require_once __DIR__ . '/borrower_notifications.php';
function createBorrowing(PDO $pdo, array $input): int {
    foreach (['student_faculty_id', 'equip_code', 'borrow_date', 'time_out', 'expected_return_date', 'expected_return_time'] as $key) {
        if (!isset($input[$key]) || !is_string($input[$key]) || trim($input[$key]) === '') throw new InvalidArgumentException('Equipment, registered borrower, dates and times are required.');
        $input[$key] = trim($input[$key]);
    }
    if (isset($input['purpose']) && !is_scalar($input['purpose'])) throw new InvalidArgumentException('Enter a valid purpose.');
    $date = strictDate($input['borrow_date'], 'Borrow date');
    $due = strictDate($input['expected_return_date'], 'Expected return date');
    $out = strictTime($input['time_out'], 'Time out');
    $in = strictTime($input['expected_return_time'], 'Expected return time');
    if ("$date $out" >= "$due $in") throw new InvalidArgumentException('Expected Return must be AFTER Time Out.');
    $pdo->beginTransaction();
    try {
        $borrower = $pdo->prepare('SELECT * FROM borrowers WHERE student_faculty_id = ?');
        $borrower->execute([$input['student_faculty_id']]);
        $person = $borrower->fetch();
        if (!$person) throw new InvalidArgumentException('Select a registered borrower by ID or name.');
        $stmt = $pdo->prepare('SELECT * FROM equipment WHERE equip_code = ? FOR UPDATE');
        $stmt->execute([$input['equip_code']]);
        $equipment = $stmt->fetch();
        if (!$equipment) throw new InvalidArgumentException('Equipment code not found.');
        $active = $pdo->prepare("SELECT id FROM borrowed WHERE equipment_id = ? AND status IN ('Borrowed','Overdue') LIMIT 1 FOR UPDATE");
        $active->execute([$equipment['id']]);
        if ($active->fetchColumn()) throw new DomainException('This equipment has an outstanding loan. Process its return before issuing another loan.');
        if ($equipment['status'] !== 'Available' || $equipment['damage_level'] !== 'None') throw new DomainException('Cannot borrow equipment. Current status: ' . $equipment['status']);
        $stmt = $pdo->prepare('INSERT INTO borrowed (equipment_id,borrower_name,student_faculty_id,department,borrow_date,time_out,expected_return_date,due_date,expected_return_time,purpose,status) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$equipment['id'], trim($person['first_name'] . ' ' . $person['last_name']), $person['student_faculty_id'], $person['department_course'],
            $date, $out, $due, $due, $in, trim((string) ($input['purpose'] ?? '')) ?: null, 'Borrowed']);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE equipment SET status = 'In Use' WHERE id = ?")->execute([$equipment['id']]);
        snapshotBorrowingEmail($pdo, $id, 'checkout');
        $pdo->commit();
        tryDispatchBorrowingEmails($pdo);
        return $id;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
