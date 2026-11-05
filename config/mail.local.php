<?php

return [
    'driver' => getenv('MAIL_DRIVER') ?: 'smtp',
    'host' => 'smtp.gmail.com',
    'port' => 587, // Authenticated STARTTLS.
    'username' => getenv('GMAIL_SMTP_USERNAME') ?: 'julieneutronmalik04@gmail.com', 
    'password' => getenv('GMAIL_SMTP_APP_PASSWORD') ?: 'danq ckbn cgsi bwtl', 
    'from_name' => 'GCST-RMS',
    'reply_to' => getenv('MAIL_REPLY_TO') ?: '', 
];
