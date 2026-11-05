<?php
require 'config/db.php';
$pdo = getDB();

$sql = file_get_contents('equipment_refactor.sql');

try {
    $pdo->exec($sql);
    echo "<h1>Success</h1><p>Database refactored and seeded successfully.</p>";
} catch (PDOException $e) {
    echo "<h1>Error</h1><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}