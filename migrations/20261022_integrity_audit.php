<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/db.php';
$pdo = getDB();
function auditIndex(PDO $pdo, string $table, string $name, string $columns, bool $unique = false): void {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
    $stmt->execute([$table, $name]);
    if (!$stmt->fetchColumn()) $pdo->exec("ALTER TABLE $table ADD " . ($unique ? 'UNIQUE ' : '') . "INDEX $name ($columns)");
}
function auditForeignKey(PDO $pdo, string $table, string $name, string $columns, string $parent, string $parentColumns, string $update = 'RESTRICT'): void {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
    $stmt->execute([$table, $name]);
    if (!$stmt->fetchColumn()) $pdo->exec("ALTER TABLE $table ADD CONSTRAINT $name FOREIGN KEY ($columns) REFERENCES $parent ($parentColumns) ON UPDATE $update ON DELETE RESTRICT");
}
$unresolved = (int) $pdo->query('SELECT COUNT(*) FROM reservations r LEFT JOIN rooms x ON x.room_code=r.room_code WHERE x.id IS NULL')->fetchColumn();
if ($unresolved) throw new RuntimeException('Resolve unknown reservation room codes before migration. No records deleted.');
$repairs = $pdo->query('SELECT r.id, r.room_id AS previous_room_id, x.id AS corrected_room_id FROM reservations r JOIN rooms x ON x.room_code=r.room_code WHERE r.room_id<>x.id')->fetchAll();
if ($repairs) {
    file_put_contents(__DIR__ . '/../tests/reservation_room_repair_before.json', json_encode($repairs, JSON_PRETTY_PRINT));
    $pdo->exec('UPDATE reservations r JOIN rooms x ON x.room_code=r.room_code SET r.room_id=x.id WHERE r.room_id<>x.id');
}
$columns = $pdo->query('SHOW COLUMNS FROM equipment')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('room_id', $columns, true)) $pdo->exec('ALTER TABLE equipment ADD room_id INT NULL AFTER assigned_room');
$pdo->exec('UPDATE equipment e JOIN rooms r ON r.room_code=e.assigned_room SET e.room_id=r.id WHERE e.room_id IS NULL OR e.room_id<>r.id');
auditIndex($pdo, 'rooms', 'uq_rooms_id_code', 'id,room_code', true);
auditForeignKey($pdo, 'reservations', 'fk_reservation_room_pair', 'room_id,room_code', 'rooms', 'id,room_code', 'CASCADE');
auditForeignKey($pdo, 'equipment', 'fk_equipment_room', 'room_id', 'rooms', 'id');
auditIndex($pdo, 'reservations', 'idx_reservation_conflict', 'room_code,res_date,status,time_start,time_end');
auditIndex($pdo, 'borrowed', 'idx_borrowed_equipment_status', 'equipment_id,status');
auditIndex($pdo, 'borrowers', 'idx_borrower_names', 'last_name,first_name');
// Preserve auditable borrowing, condition and reservation history on parent deletion.
foreach ([
    ['borrowed', 'fk_borrowed_equipment', 'equipment_id', 'equipment', 'id', 'RESTRICT'],
    ['borrowed', 'fk_borrowed_registered_borrower', 'student_faculty_id', 'borrowers', 'student_faculty_id', 'CASCADE'],
    ['condition_reports', 'condition_reports_ibfk_1', 'equipment_id', 'equipment', 'id', 'RESTRICT'],
    ['condition_reports', 'condition_reports_ibfk_2', 'reported_by', 'users', 'id', 'RESTRICT'],
    ['reservations', 'reservations_ibfk_1', 'faculty_id', 'users', 'id', 'RESTRICT'],
] as [$table,$name,$column,$parent,$parentColumn,$update]) {
    $stmt = $pdo->prepare('SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
    $stmt->execute([$table,$name]);
    $rule = $stmt->fetchColumn();
    if ($rule && $rule !== 'RESTRICT') {
        $retainedName = $name . '_retain';
        $pdo->exec("ALTER TABLE $table DROP FOREIGN KEY $name, ADD CONSTRAINT $retainedName FOREIGN KEY ($column) REFERENCES $parent ($parentColumn) ON UPDATE $update ON DELETE RESTRICT");
    }
}
$borrowerKey = $pdo->query("SHOW FULL COLUMNS FROM borrowers LIKE 'student_faculty_id'")->fetch();
$damageKey = $pdo->query("SHOW FULL COLUMNS FROM damage_assessments LIKE 'student_faculty_id'")->fetch();
$collation = $borrowerKey['Collation'];
if (!preg_match('/^[a-z0-9_]+$/D', $collation)) throw new RuntimeException('Unexpected borrower key collation.');
if ($damageKey['Collation'] !== $collation) $pdo->exec("ALTER TABLE damage_assessments MODIFY student_faculty_id VARCHAR(50) CHARACTER SET utf8mb4 COLLATE $collation NULL");
auditForeignKey($pdo, 'damage_assessments', 'fk_damage_borrower', 'student_faculty_id', 'borrowers', 'student_faculty_id', 'CASCADE');
// An archive flag preserves the Approved status instead of writing an invalid enum.
$columns = $pdo->query('SHOW COLUMNS FROM reservations')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('archived', $columns, true)) $pdo->exec('ALTER TABLE reservations ADD archived TINYINT(1) NOT NULL DEFAULT 0');
$roomCollation = $pdo->query("SHOW FULL COLUMNS FROM rooms LIKE 'room_code'")->fetch()['Collation'];
if (!preg_match('/^[a-z0-9_]+$/D', $roomCollation)) throw new RuntimeException('Unexpected room collation.');
$classOrphans = $pdo->query("SELECT COUNT(*) FROM class_schedules c LEFT JOIN rooms r ON c.room_code COLLATE $roomCollation=r.room_code WHERE r.id IS NULL")->fetchColumn();
if ($classOrphans) throw new RuntimeException('Class schedules refer to unknown rooms. Resolve these references before continuing.');
$classKey = $pdo->query("SHOW FULL COLUMNS FROM class_schedules LIKE 'room_code'")->fetch();
if ($classKey['Type'] !== 'varchar(20)' || $classKey['Collation'] !== $roomCollation) $pdo->exec("ALTER TABLE class_schedules MODIFY room_code VARCHAR(20) CHARACTER SET utf8mb4 COLLATE $roomCollation NOT NULL");
auditForeignKey($pdo, 'class_schedules', 'fk_class_room_code', 'room_code', 'rooms', 'room_code', 'CASCADE');
echo 'Integrity migration complete. Exact-code room links repaired: ' . count($repairs) . ". History preserved.\n";
