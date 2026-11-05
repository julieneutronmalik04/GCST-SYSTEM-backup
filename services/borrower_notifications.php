<?php
require_once __DIR__ . '/email.php';

/** Queue transaction events in the same transaction as the borrowing change. */
function queueBorrowerEmail(PDO $pdo, int $id, string $type): void {
    if (!in_array($type, ['checkout', 'return', 'overdue'], true)) throw new InvalidArgumentException('Invalid email type.');
    $pdo->prepare("INSERT INTO borrower_email_log (borrowed_id,notification_type,notification_date,html_body,message)
        VALUES (?,?,CURRENT_DATE,'','') ON DUPLICATE KEY UPDATE id=id")->execute([$id, $type]);
}

/** Claim before SMTP so concurrent checks cannot send the same loan/type/day twice. */
function notifyBorrowerEmail(PDO $pdo, int $id, string $type, bool $retry = false, ?callable $sender = null): array {
    if ($pdo->inTransaction()) throw new LogicException('Email dispatch requires a committed borrowing transaction.');
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM borrowed WHERE id=? FOR UPDATE');
        $lock->execute([$id]);
        $stmt = $pdo->prepare('SELECT b.*,e.equip_code,e.name AS equipment_name,r.email,r.first_name,r.last_name
            FROM borrowed b JOIN equipment e ON e.id=b.equipment_id
            LEFT JOIN borrowers r ON r.student_faculty_id=b.student_faculty_id WHERE b.id=?');
        $stmt->execute([$id]);
        $loan = $stmt->fetch();
        if (!$loan || ($type === 'overdue' && $loan['status'] !== 'Overdue')) throw new InvalidArgumentException('Only overdue loans can receive overdue reminders.');
        $message = buildBorrowerEmail($loan, $type);
        queueBorrowerEmail($pdo, $id, $type);
        $stmt = $pdo->prepare('SELECT * FROM borrower_email_log WHERE borrowed_id=? AND notification_type=? AND notification_date=CURRENT_DATE FOR UPDATE');
        $stmt->execute([$id, $type]);
        $log = $stmt->fetch();
        if ($log['status'] !== 'Pending' && (!$retry || !in_array($log['status'], ['Failed','Skipped','Simulated'], true))) {
            $pdo->commit();
            return ['status' => $log['status'], 'duplicate' => true];
        }
        // Prepare a real preview even before Gmail credentials are entered. Never mark it sent.
        $ready = $sender !== null || mailMode() !== 'unconfigured';
        $pdo->prepare("UPDATE borrower_email_log SET status=?,recipient_email=?,subject=?,html_body=?,message=?,
            error_message=NULL,provider_reference=NULL,processed_at=NULL WHERE id=?")
            ->execute([$ready ? 'Processing' : 'Pending', $loan['email'], $message['subject'], $message['html'], $message['text'], $log['id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    if (!$ready) return ['status' => 'Pending', 'duplicate' => false];
    try {
        $result = ($sender ?? 'sendBorrowerEmail')((string)($loan['email'] ?? ''), $message);
    } catch (Throwable $error) {
        $result = ['status' => 'Unknown', 'error_message' => 'Email result is uncertain. Check the sender mailbox/server before retrying.'];
    }
    $pdo->prepare('UPDATE borrower_email_log SET status=?,provider_reference=?,error_message=?,processed_at=NOW() WHERE id=?')
        ->execute([$result['status'], $result['provider_reference'] ?? null, $result['error_message'] ?? null, $log['id']]);
    return $result + ['duplicate' => false];
}

/** Safely deliver queued checkout/return events, including events from earlier days. */
function dispatchBorrowingEmails(PDO $pdo, int $limit = 50, ?callable $sender = null): array {
    if ($pdo->inTransaction()) throw new LogicException('Email dispatch requires committed borrowing transactions.');
    if ($sender === null && mailMode() === 'unconfigured') return [];
    $promote = mailMode() === 'live';
    $stmt = $pdo->prepare("SELECT id FROM borrower_email_log WHERE notification_type IN ('checkout','return')
        AND (status='Pending'" . ($promote ? " OR status='Simulated'" : '') . ') ORDER BY id LIMIT ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $logId) {
        $result = dispatchQueuedBorrowingEmail($pdo, (int)$logId, $promote, $sender);
        $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
    }
    return $counts;
}

function dispatchQueuedBorrowingEmail(PDO $pdo, int $logId, bool $promote, ?callable $sender): array {
    // Checkout/return messages are snapshotted at commit and never rebuilt as a different event.
    $stmt = $pdo->prepare("UPDATE borrower_email_log SET status='Processing' WHERE id=?
        AND (status='Pending'" . ($promote ? " OR status='Simulated'" : '') . ')');
    $stmt->execute([$logId]);
    if (!$stmt->rowCount()) return ['status' => 'Processing', 'duplicate' => true];
    $stmt = $pdo->prepare('SELECT * FROM borrower_email_log WHERE id=?');
    $stmt->execute([$logId]);
    $log = $stmt->fetch();
    try {
        $result = ($sender ?? 'sendBorrowerEmail')((string)($log['recipient_email'] ?? ''), ['subject'=>$log['subject'], 'html'=>$log['html_body'], 'text'=>$log['message']]);
    } catch (Throwable $error) {
        $result = ['status'=>'Unknown', 'error_message'=>'Email result is uncertain. Check the sender mailbox/server before retrying.'];
    }
    $pdo->prepare('UPDATE borrower_email_log SET status=?,provider_reference=?,error_message=?,processed_at=NOW() WHERE id=?')
        ->execute([$result['status'], $result['provider_reference'] ?? null, $result['error_message'] ?? null, $logId]);
    return $result;
}

function snapshotBorrowingEmail(PDO $pdo, int $id, string $type): void {
    queueBorrowerEmail($pdo, $id, $type);
    $stmt = $pdo->prepare('SELECT b.*,e.equip_code,e.name AS equipment_name,r.email,r.first_name,r.last_name
        FROM borrowed b JOIN equipment e ON e.id=b.equipment_id
        LEFT JOIN borrowers r ON r.student_faculty_id=b.student_faculty_id WHERE b.id=?');
    $stmt->execute([$id]);
    $loan = $stmt->fetch();
    $message = buildBorrowerEmail($loan, $type);
    $pdo->prepare("UPDATE borrower_email_log SET recipient_email=?,subject=?,html_body=?,message=?
        WHERE borrowed_id=? AND notification_type=? AND notification_date=CURRENT_DATE AND status='Pending'")
        ->execute([$loan['email'], $message['subject'], $message['html'], $message['text'], $id, $type]);
}

/** SMTP errors must not make a committed checkout/return look unsuccessful. */
function tryDispatchBorrowingEmails(PDO $pdo): void {
    try { dispatchBorrowingEmails($pdo); }
    catch (Throwable $error) { error_log('Borrowing email dispatch needs review/retry.'); }
}
