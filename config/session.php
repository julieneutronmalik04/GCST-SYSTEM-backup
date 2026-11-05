<?php

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Auth 

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function getRole(): string {
    return $_SESSION['role'] ?? '';
}

function getUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function getUserName(): string {
    return $_SESSION['full_name'] ?? 'User';
}

/**
 * Enforce login + role 
 * @param string|string[] $roles 
 */
function requireLogin($roles = []): void {
    if (!isLoggedIn()) {
        jsonResponse(false, 'Unauthenticated. Please log in.', [], 401);
    }
    static $validatedUserId = null;
    if ($validatedUserId !== getUserId()) {
        require_once __DIR__ . '/db.php';
        $stmt = getDB()->prepare('SELECT username,full_name,role,is_active FROM users WHERE id=?');
        $stmt->execute([getUserId()]);
        $account = $stmt->fetch();
        if (!$account || !$account['is_active']) {
            $_SESSION = [];
            jsonResponse(false, 'Your account is unavailable. Please sign in again.', [], 401);
        }
        foreach (['username','full_name','role'] as $field) $_SESSION[$field] = $account[$field];
        $validatedUserId = getUserId();
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD','OPTIONS'], true)) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if ($origin !== $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')) jsonResponse(false, 'Cross-origin changes are not allowed.', [], 403);
    }
    if (!empty($roles)) {
        $allowed = (array)$roles;
        if (!in_array(getRole(), $allowed, true)) {
            jsonResponse(false, 'Access denied.', [], 403);
        }
    }
}

// Response
function jsonResponse(bool $ok, string $msg, array $data = [], int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store');
    
    $payload = array_merge([
        'success' => $ok,
        'status'  => $ok ? 'success' : 'error',
        'message' => $msg
    ], $data);
    
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Input helpers 

function clean(string $v): string {
    return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
}

function post(string $k, string $default = ''): string {
    $value = $_POST[$k] ?? $default;
    if (!is_scalar($value)) jsonResponse(false, 'Invalid input field: ' . $k, [], 422);
    return clean((string) $value);
}

function get(string $k, string $default = ''): string {
    $value = $_GET[$k] ?? $default;
    if (!is_scalar($value)) jsonResponse(false, 'Invalid query field: ' . $k, [], 422);
    return clean((string) $value);
}

function bodyJson(): array {
    static $body = null;
    if ($body === null) {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE) jsonResponse(false, 'A valid JSON object is required.', [], 422);
        foreach (['id','schedule_id','equipment_id','borrowed_id','disposal_request_id','user_id'] as $key) {
            if (isset($body[$key]) && (filter_var($body[$key], FILTER_VALIDATE_INT) === false || (int)$body[$key] < 1)) jsonResponse(false, 'Invalid record ID: ' . $key, [], 422);
        }
    }
    return $body;
}

function bodyForm(): array {
    $raw = file_get_contents('php://input');
    parse_str($raw, $out);
    foreach ($out as $key=>$value) if (!is_scalar($value)) jsonResponse(false, 'Invalid input field: ' . $key, [], 422);
    return array_map(fn($v) => clean((string)$v), $out);
}

// ── Activity logger ──

function logActivity(PDO $pdo, string $action, string $target = '', string $details = ''): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO activity_log (user_id, action, target, details, ip_address)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            getUserId() ?: null,
            $action,
            $target ?: null,
            $details ?: null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (PDOException) {
        // Non-critical; swallow
    }
}

// ── CORS / JSON header ──

header('Content-Type: application/json');
