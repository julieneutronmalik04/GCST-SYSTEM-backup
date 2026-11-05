<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/db.php';
function migrateEmailNotifications(PDO $pdo): void {
    // Independent email audit table; existing historical notification data is retained.
    $pdo->exec("CREATE TABLE IF NOT EXISTS borrower_email_log (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        borrowed_id INT NOT NULL,
        notification_type ENUM('overdue','checkout','return') NOT NULL,
        notification_date DATE NOT NULL,
        recipient_email VARCHAR(254) NULL,
        subject VARCHAR(255) NOT NULL DEFAULT '',
        html_body MEDIUMTEXT NOT NULL,
        message TEXT NOT NULL,
        status ENUM('Pending','Processing','Simulated','Accepted','Failed','Skipped','Unknown') NOT NULL DEFAULT 'Pending',
        provider_reference VARCHAR(255) NULL,
        error_message VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL,
        UNIQUE KEY uq_borrower_email_day (borrowed_id, notification_type, notification_date),
        CONSTRAINT fk_email_borrowed FOREIGN KEY (borrowed_id) REFERENCES borrowed(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    migrateEmailNotifications(getDB());
    echo "Email notification migration completed. Existing history retained.\n";
}
