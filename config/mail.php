<?php
// Shared by Apache and CLI. Never put credentials in JavaScript or commit them.
$config = [
    'driver' => getenv('MAIL_DRIVER') ?: 'smtp', // 'log' records previews without sending.
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'username' => getenv('GMAIL_SMTP_USERNAME') ?: '',
    'password' => getenv('GMAIL_SMTP_APP_PASSWORD') ?: '',
    'from_name' => 'GCST Equipment Office',
    'reply_to' => getenv('MAIL_REPLY_TO') ?: '',
    'instructions' => 'Return the equipment to the GCST Equipment Office by the due date. If it is overdue, return it as soon as possible. Reply to this email or contact the office to clarify the borrowing record or any assessed fees.',
];
$local = __DIR__ . '/mail.local.php';
if (is_file($local)) $config = array_replace($config, require $local);
return $config;
