<?php
require_once __DIR__ . '/borrower_notifications.php';

function overdueConfig(): array {
    return require __DIR__ . '/../config/overdue.php';
}

function overdueDeadlineSql(string $alias = ''): string {
    $prefix = $alias === '' ? '' : $alias . '.';
    return "TIMESTAMP(COALESCE({$prefix}due_date, {$prefix}expected_return_date), COALESCE({$prefix}expected_return_time, '23:59:59'))";
}

function overdueDaysSql(string $alias = ''): string {
    return 'GREATEST(CEIL(TIMESTAMPDIFF(SECOND, ' . overdueDeadlineSql($alias) . ', CURRENT_TIMESTAMP) / 86400), 0)';
}

/** Mark due-time overdue loans, accrue fees, then dispatch outside the transaction. */
function refreshOverdue(PDO $pdo, bool $notify = true, ?callable $sender = null): array {
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $new = $pdo->query("SELECT b.id, b.borrower_name, b.equipment_id
            FROM borrowed b
            WHERE b.status = 'Borrowed' AND " . overdueDeadlineSql('b') . " < CURRENT_TIMESTAMP FOR UPDATE")->fetchAll();
        $pdo->exec("UPDATE borrowed SET status = 'Overdue'
            WHERE status = 'Borrowed' AND " . overdueDeadlineSql() . ' < CURRENT_TIMESTAMP');
        $rate = overdueConfig()['daily_penalty'];
        $penalties = $pdo->prepare("UPDATE borrowed
            SET penalty_amount = ROUND(" . overdueDaysSql() . " * ?, 2)
            WHERE status = 'Overdue'");
        $penalties->execute([$rate]);
        foreach ($new as $loan) {
            $equipment = $pdo->prepare('SELECT equip_code FROM equipment WHERE id=?');
            $equipment->execute([$loan['equipment_id']]);
            $loan['equip_code'] = $equipment->fetchColumn();
            foreach (['maintenance_notifications', 'admin_notifications'] as $table) {
                $pdo->prepare("INSERT INTO $table (type, title, body, link_ref)
                    VALUES ('overdue', 'Overdue equipment', ?, ?)")->execute([
                    $loan['borrower_name'] . ' has overdue equipment: ' . $loan['equip_code'] . '.',
                    'borrow:' . $loan['id'],
                ]);
            }
        }
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $result = ['newly_overdue' => count($new), 'daily_penalty' => $rate];
    // A caller-owned transaction must commit before it explicitly dispatches notifications.
    if ($notify && $ownTransaction) {
        try { $result['notifications'] = dispatchOverdueNotifications($pdo, 50, $sender); }
        catch (Throwable $error) {
            error_log('Automatic overdue dispatch: ' . $error->getMessage());
            $result['notification_error'] = 'Overdue records were updated, but notification dispatch needs retry.';
        }
    }
    return $result;
}

function getOverdueRecords(PDO $pdo): array {
    return $pdo->query("SELECT b.id, b.student_faculty_id, b.borrower_name, b.department,
            b.due_date, b.expected_return_time, b.penalty_amount, b.status, e.equip_code, e.name AS equipment_name,
            r.email, " . overdueDaysSql('b') . " AS days_overdue,
            n.id AS email_log_id, n.status AS email_status, n.notification_date AS email_notification_date,
            CURRENT_DATE AS database_today, n.processed_at AS email_processed_at, n.error_message AS email_error
        FROM borrowed b
        JOIN equipment e ON e.id = b.equipment_id
        LEFT JOIN borrowers r ON r.student_faculty_id = b.student_faculty_id
        LEFT JOIN borrower_email_log n ON n.id = (SELECT MAX(n2.id) FROM borrower_email_log n2 WHERE n2.borrowed_id = b.id AND n2.notification_type = 'overdue')
        WHERE b.status = 'Overdue' ORDER BY b.due_date, b.id")->fetchAll();
}

/** Read the exact latest saved message. Preview never queues or sends email. */
function getOverdueEmailPreview(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT n.id AS log_id,n.borrowed_id,n.message,n.html_body,n.subject,n.recipient_email,n.status,n.notification_date,n.processed_at,e.equip_code,b.borrower_name
        FROM borrower_email_log n JOIN borrowed b ON b.id=n.borrowed_id JOIN equipment e ON e.id=b.equipment_id
        WHERE n.borrowed_id=? AND n.notification_type='overdue' ORDER BY n.id DESC LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** At most one accepted email per loan/day, even with concurrent requests. */
function notifyOverdueBorrower(PDO $pdo, int $id, bool $retry = false, ?callable $sender = null): array {
    return notifyBorrowerEmail($pdo, $id, 'overdue', $retry, $sender);
}

function dispatchOverdueNotifications(PDO $pdo, int $limit = 50, ?callable $sender = null): array {
    $promotePreview = mailMode() === 'live';
    $stmt = $pdo->prepare("SELECT b.id FROM borrowed b
        LEFT JOIN borrower_email_log n ON n.borrowed_id=b.id AND n.notification_type='overdue' AND n.notification_date=CURRENT_DATE
        WHERE b.status='Overdue' AND (n.id IS NULL OR n.status='Pending'" . ($promotePreview ? " OR n.status='Simulated'" : '') . ") ORDER BY b.due_date,b.id LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try {
            $result = notifyOverdueBorrower($pdo, (int)$id, $promotePreview, $sender);
            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
        } catch (InvalidArgumentException $error) {
            // The equipment may have been returned since the queue was read.
        }
    }
    return $counts;
}
