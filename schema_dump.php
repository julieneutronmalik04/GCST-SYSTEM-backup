<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require 'config/db.php';
$pdo = getDB();
$tables = ['rooms', 'equipment', 'users', 'reservations', 'notifications', 'borrowed'];
$schema = [];
foreach ($tables as $t) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM $t")->fetchAll(PDO::FETCH_ASSOC);
        $schema[$t] = $cols;
    } catch (Exception $e) {}
}
file_put_contents('../schema.json', json_encode($schema, JSON_PRETTY_PRINT));
echo "Done.";
