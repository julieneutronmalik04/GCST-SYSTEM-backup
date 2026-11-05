<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/db.php';
$pdo = getDB();
$path = __DIR__ . '/20261022_legacy_borrower_dataset.sql';
if (is_file($path)) { echo "Existing migration dataset retained: $path\n"; exit; }
$rows = $pdo->query("SELECT id,borrower_name,COALESCE(department,'') AS department FROM borrowed WHERE student_faculty_id IS NULL ORDER BY borrower_name,department,id")->fetchAll();
$groups = [];
foreach ($rows as $row) {
    $name = preg_replace('/\s+/u', ' ', trim($row['borrower_name']));
    if ($name === '') throw new RuntimeException('A historical loan has no borrower label; manual identification is required.');
    $department = trim($row['department']);
    $key = mb_strtolower($name . '|' . $department, 'UTF-8');
    if (!isset($groups[$key])) $groups[$key] = ['name'=>$name,'department'=>$department,'loans'=>[]];
    $groups[$key]['loans'][] = $row;
}
$collation = $pdo->query("SHOW FULL COLUMNS FROM borrowers LIKE 'student_faculty_id'")->fetch()['Collation'];
if (!preg_match('/^[a-z0-9_]+$/D', $collation)) throw new RuntimeException('Unexpected database collation.');
$sql = "-- Legacy Borrower Migration Dataset\n-- Derived from historical transaction labels; not an independently verified roster.\n-- Names shared across departments remain distinct without verified identity evidence.\n-- Unknown contacts use a reserved .invalid address and an empty phone number.\n-- Existing transaction names and department snapshots are preserved.\n\n";
$sql .= "CREATE TABLE IF NOT EXISTS legacy_borrower_mappings (\n  borrowed_id INT PRIMARY KEY,\n  student_faculty_id VARCHAR(50) COLLATE $collation NOT NULL,\n  original_name VARCHAR(201) NOT NULL,\n  original_department VARCHAR(150) NOT NULL,\n  migrated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n  CONSTRAINT fk_legacy_loan FOREIGN KEY (borrowed_id) REFERENCES borrowed(id) ON DELETE RESTRICT,\n  CONSTRAINT fk_legacy_borrower FOREIGN KEY (student_faculty_id) REFERENCES borrowers(student_faculty_id) ON UPDATE CASCADE ON DELETE RESTRICT\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=$collation;\n\nSTART TRANSACTION;\n";
$next = (int) $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(student_faculty_id,8) AS UNSIGNED)),0) FROM borrowers WHERE student_faculty_id REGEXP '^LEGACY-[0-9]+$'")->fetchColumn();
$people = [];
$links = [];
foreach ($groups as $group) {
    $id = 'LEGACY-' . str_pad((string) ++$next, 4, '0', STR_PAD_LEFT);
    $parts = explode(' ', $group['name']);
    $last = count($parts) > 1 ? array_pop($parts) : '';
    $first = implode(' ', $parts);
    $values = [$id,$first,$last,$group['department'] ?: 'Unspecified (legacy record)',strtolower($id).'@example.invalid',''];
    $people[] = '(' . implode(', ', array_map(fn($value) => $pdo->quote($value), $values)) . ')';
    foreach ($group['loans'] as $row) $links[] = '(' . (int)$row['id'] . ', ' . $pdo->quote($id) . ', ' . $pdo->quote($row['borrower_name']) . ', ' . $pdo->quote($row['department']) . ')';
}
if ($people) $sql .= "\nINSERT INTO borrowers (student_faculty_id,first_name,last_name,department_course,email,contact_number) VALUES\n" . implode(",\n", $people) . "\nON DUPLICATE KEY UPDATE student_faculty_id=VALUES(student_faculty_id);\n";
if ($links) $sql .= "\nINSERT INTO legacy_borrower_mappings (borrowed_id,student_faculty_id,original_name,original_department) VALUES\n" . implode(",\n", $links) . "\nON DUPLICATE KEY UPDATE borrowed_id=VALUES(borrowed_id);\n";
$sql .= "\nUPDATE borrowed b JOIN legacy_borrower_mappings m ON m.borrowed_id=b.id\nSET b.student_faculty_id=m.student_faculty_id\nWHERE b.student_faculty_id IS NULL\n  AND BINARY b.borrower_name=BINARY m.original_name\n  AND BINARY COALESCE(b.department,'')=BINARY m.original_department;\n\nCOMMIT;\n\n-- Verification: expected count is 0 after successful migration.\nSELECT COUNT(*) AS historical_loans_without_borrower FROM borrowed WHERE student_faculty_id IS NULL;\n";
file_put_contents($path, $sql);
echo 'Created dataset for ' . count($groups) . ' legacy entries and ' . count($rows) . " historical loans.\n";
