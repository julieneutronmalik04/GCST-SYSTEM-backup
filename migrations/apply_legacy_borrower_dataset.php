<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$connection->set_charset('utf8mb4');
try {
    $connection->multi_query(file_get_contents(__DIR__ . '/20261022_legacy_borrower_dataset.sql'));
    do {
        if ($result = $connection->store_result()) {
            foreach ($result as $row) echo json_encode($row) . PHP_EOL;
            $result->free();
        }
    } while ($connection->more_results() && $connection->next_result());
    echo "Legacy Borrower Migration Dataset applied; historical snapshots retained.\n";
} catch (Throwable $error) {
    $connection->rollback();
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
