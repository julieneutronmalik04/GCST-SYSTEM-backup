<?php
/** Repeatable migration: preserve the explicit approval date for disposal reports. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/db.php';
$pdo = getDB();
$exists = $pdo->query("SHOW COLUMNS FROM disposal_requests LIKE 'disposal_approved_at'")->fetch();
if (!$exists) $pdo->exec('ALTER TABLE disposal_requests ADD disposal_approved_at DATETIME NULL DEFAULT NULL AFTER reviewed_at');
$count = $pdo->exec("UPDATE disposal_requests SET disposal_approved_at=reviewed_at WHERE disposal_status='Approved' AND disposal_approved_at IS NULL AND reviewed_at IS NOT NULL");
echo "Disposal tracking ready; $count existing approval dates preserved.\n";
