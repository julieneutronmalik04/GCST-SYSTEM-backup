<?php
use PHPMailer\PHPMailer\PHPMailer;

function mailConfig(): array {
    $config = require __DIR__ . '/../config/mail.php';
    $config['username'] = trim((string)$config['username']);
    $config['password'] = preg_replace('/\s+/', '', (string)$config['password']);
    return $config;
}

function mailMode(): string {
    $config = mailConfig();
    if ($config['driver'] === 'log') return 'preview';
    return $config['driver'] === 'smtp' && filter_var($config['username'], FILTER_VALIDATE_EMAIL)
        && $config['password'] !== '' && stripos($config['password'], 'APP_PASSWORD') === false ? 'live' : 'unconfigured';
}

/** Escape every database/config value before it is placed in HTML. */
function buildBorrowerEmail(array $loan, string $type): array {
    $title = ['overdue' => 'Overdue equipment reminder', 'checkout' => 'Equipment borrowing confirmation', 'return' => 'Equipment return and penalty summary'][$type]
        ?? throw new InvalidArgumentException('Unsupported email notification type.');
    $name = trim(($loan['first_name'] ?? '') . ' ' . ($loan['last_name'] ?? '')) ?: $loan['borrower_name'];
    $details = [
        'Borrower' => $name,
        'Equipment' => $loan['equipment_name'] . ' (' . $loan['equip_code'] . ')',
        'Due date / time' => ($loan['due_date'] ?: $loan['expected_return_date']) . ' ' . ($loan['expected_return_time'] ?: '23:59:59'),
        'Status' => $loan['status'],
        'Overdue penalty' => 'PHP ' . number_format((float)$loan['penalty_amount'], 2),
        'Damage fee' => 'PHP ' . number_format((float)$loan['damage_penalty_amount'], 2),
        'Total penalty' => 'PHP ' . number_format((float)$loan['penalty_amount'] + (float)$loan['damage_penalty_amount'], 2),
    ];
    $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $rows = ''; $plain = $title . "\n\n";
    foreach ($details as $label => $value) {
        $rows .= '<tr><th align="left" style="padding:8px;border-bottom:1px solid #ddd">' . $escape($label) . '</th><td style="padding:8px;border-bottom:1px solid #ddd">' . $escape($value) . '</td></tr>';
        $plain .= "$label: $value\n";
    }
    $instructions = mailConfig()['instructions'];
    if ($type === 'overdue') $instructions = 'This equipment is overdue. The overdue fee is PHP 10 per started 24-hour period and continues until return. ' . $instructions;
    if ($type === 'return') $instructions = 'Your return has been recorded. Contact the Equipment Office to settle or clarify the fees shown above. Reply to this email if any details are incorrect.';
    return [
        'subject' => $title . ' - ' . $loan['equip_code'],
        'html' => '<!doctype html><html><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;color:#243047"><h2>' . $escape($title) . '</h2><table style="border-collapse:collapse;width:100%;max-width:640px">' . $rows . '</table><p>' . $escape($instructions) . '</p><p>GCST Equipment Office</p></body></html>',
        'text' => $plain . "\n" . $instructions . "\n\nGCST Equipment Office",
    ];
}

/** SMTP acceptance is not confirmation of inbox delivery. */
function sendBorrowerEmail(string $email, array $message, ?callable $factory = null): array {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['status' => 'Skipped', 'error_message' => 'A valid registered borrower email address is required.'];
    $config = mailConfig();
    if ($config['driver'] === 'log') return ['status' => 'Simulated'];
    if (mailMode() !== 'live') return ['status' => 'Failed', 'error_message' => 'Gmail SMTP credentials are not configured.'];
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (is_file($autoload)) require_once $autoload;
    if ($factory === null && !class_exists(PHPMailer::class)) return ['status' => 'Failed', 'error_message' => 'PHPMailer is missing. Run composer install in the project root.'];
    $sending = false;
    try {
        $mail = $factory ? $factory() : new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->Port = (int)$config['port'];
        $mail->SMTPAuth = true;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->SMTPDebug = 0;
        $mail->Timeout = 15;
        $mail->getSMTPInstance()->Timelimit = 30;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($config['username'], $config['from_name']);
        $mail->addReplyTo($config['reply_to'] ?: $config['username'], $config['from_name']);
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = $message['subject'];
        $mail->Body = $message['html'];
        $mail->AltBody = $message['text'];
        $sending = true;
        if (!$mail->send()) throw new RuntimeException('SMTP send did not complete.');
        return ['status' => 'Accepted', 'provider_reference' => $mail->getLastMessageID()];
    } catch (Throwable $error) {
        // Do not expose credentials/SMTP transcripts in API responses or logs.
        $code = isset($mail) ? (int)($mail->getSMTPInstance()->getError()['smtp_code'] ?? 0) : 0;
        if ($code >= 400 && $code <= 599) return ['status' => 'Failed', 'error_message' => $code === 535
            ? 'Gmail rejected SMTP authentication. Check the sending address and Gmail App Password.'
            : 'Gmail SMTP rejected this email (code ' . $code . '). Check the sender account and recipient.'];
        return ['status' => $sending ? 'Unknown' : 'Failed', 'error_message' => $sending
            ? 'SMTP result is uncertain. Check the sender mailbox/server before retrying.'
            : 'Unable to initialize Gmail SMTP. Check server configuration.'];
    }
}
