<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
echo password_hash("santos123", PASSWORD_BCRYPT);
