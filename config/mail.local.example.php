<?php

return [
    'driver' => getenv('MAIL_DRIVER') ?: 'smtp',
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'username' => getenv('GMAIL_SMTP_USERNAME') ?: '', 
    'password' => getenv('GMAIL_SMTP_APP_PASSWORD') ?: '', 
    'from_name' => 'GCST Equipment Office',
    'reply_to' => getenv('MAIL_REPLY_TO') ?: '', 
];
