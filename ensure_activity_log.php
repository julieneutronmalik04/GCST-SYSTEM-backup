<?php
require 'config/db.php';
$pdo = getDB();

$sql = "CREATE TABLE IF NOT EXISTS activity_log (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NULL,
    action VARCHAR(50) NOT NULL,
    target VARCHAR(100) NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);";

try {
    $pdo->exec($sql);
    echo "<h1>Success</h1><p>Activity log table ensured.</p>";
} catch (PDOException $e) {
    echo "<h1>Error</h1><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}