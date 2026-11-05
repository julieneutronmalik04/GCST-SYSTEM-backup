<?php
function managedBorrower(PDO $pdo, int $id, bool $lock = false): array {
    $stmt = $pdo->prepare('SELECT id,student_faculty_id,first_name,last_name,department_course,contact_number,email,created_at FROM borrowers WHERE id=?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new OutOfBoundsException('Borrower not found.');
    return $row;
}

function validateManagedBorrower(array $input, ?array $existing = null): array {
    $data = [];
    foreach (['student_faculty_id'=>50,'first_name'=>100,'last_name'=>100,'department_course'=>150,'contact_number'=>50,'email'=>150] as $key=>$limit) {
        if (!isset($input[$key]) || !is_string($input[$key])) throw new InvalidArgumentException('Invalid or missing borrower field: ' . $key);
        $data[$key] = trim($input[$key]);
        if (mb_strlen($data[$key], 'UTF-8') > $limit || preg_match('/[\x00-\x1F\x7F]/', $data[$key])) throw new InvalidArgumentException('Invalid or overlong borrower field: ' . $key);
    }
    foreach (['student_faculty_id','first_name','department_course'] as $key) if ($data[$key] === '') throw new InvalidArgumentException('ID, first name and department/course are required.');
    // Preserve an explicitly unresolved legacy surname rather than inventing one.
    $legacySurname = $existing && str_starts_with($existing['student_faculty_id'], 'LEGACY-') && $existing['last_name'] === '';
    if ($data['last_name'] === '' && !$legacySurname) throw new InvalidArgumentException('Last name is required.');
    if (preg_match('/\s/u', $data['student_faculty_id'])) throw new InvalidArgumentException('Student/Faculty ID must not contain spaces.');
    $data['email'] = strtolower($data['email']);
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address, or leave it blank if unknown.');
    $data['contact_number'] = preg_replace('/[\s()\-]+/', '', $data['contact_number']);
    if ($data['contact_number'] !== '' && !preg_match('/^\+?[0-9]{10,15}$/D', $data['contact_number'])) throw new InvalidArgumentException('Contact number must contain 10 to 15 digits, or be blank if unknown.');
    return $data;
}

function saveManagedBorrower(PDO $pdo, array $input, ?int $id = null): array {
    $pdo->beginTransaction();
    try {
        $existing = $id ? managedBorrower($pdo, $id, true) : null;
        $data = validateManagedBorrower($input, $existing);
        $duplicate = $pdo->prepare('SELECT id FROM borrowers WHERE student_faculty_id=? AND id<>?');
        $duplicate->execute([$data['student_faculty_id'], $id ?? 0]);
        if ($duplicate->fetchColumn()) throw new DomainException('That Student/Faculty ID is already registered.');
        $values = array_values($data);
        if ($id) {
            // Existing ON UPDATE CASCADE relationships retain loan/assessment/migration links.
            $pdo->prepare('UPDATE borrowers SET student_faculty_id=?,first_name=?,last_name=?,department_course=?,contact_number=?,email=? WHERE id=?')->execute([...$values, $id]);
        } else {
            $pdo->prepare('INSERT INTO borrowers(student_faculty_id,first_name,last_name,department_course,contact_number,email) VALUES(?,?,?,?,?,?)')->execute($values);
            $id = (int)$pdo->lastInsertId();
        }
        $row = managedBorrower($pdo, $id);
        $pdo->commit();
        return $row;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function deleteManagedBorrower(PDO $pdo, int $id): void {
    $pdo->beginTransaction();
    try {
        $row = managedBorrower($pdo, $id, true);
        foreach (['borrowed','damage_assessments','legacy_borrower_mappings'] as $table) {
            $stmt = $pdo->prepare("SELECT 1 FROM $table WHERE student_faculty_id=? LIMIT 1");
            $stmt->execute([$row['student_faculty_id']]);
            if ($stmt->fetchColumn()) throw new DomainException('This borrower has borrowing or audit history and cannot be deleted. You can edit their details or view their history.');
        }
        $pdo->prepare('DELETE FROM borrowers WHERE id=?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
