<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

$redirectMap = [
    'admin'       => '/gcst-system/frontend/admin-dashboard.html',
    'faculty'     => '/gcst-system/frontend/faculty-dashboard.html',
    'vip'         => '/gcst-system/frontend/faculty-dashboard.html',
    'maintenance' => '/gcst-system/frontend/maintenance-dashboard.html',
];

if (isLoggedIn()) {
    requireLogin();
    $role = getRole();
    jsonResponse(true, 'Already logged in.', [
        'role'     => $role,
        'redirect' => $redirectMap[$role] ?? '/gcst-system/frontend/login.html',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Method not allowed.', [], 405);

$username = post('username');
$password = $_POST['password'] ?? '';
if (!is_string($password)) jsonResponse(false, 'Invalid password input.', [], 422);

if (!$username || !$password) jsonResponse(false, 'Username and password are required.');

$pdo  = getDB();
$stmt = $pdo->prepare('SELECT id,username,password,full_name,role,is_active FROM users WHERE username=? LIMIT 1');
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password']))
    jsonResponse(false, 'Invalid username or password.');

if (!$user['is_active'])
    jsonResponse(false, 'Your account has been deactivated. Contact the administrator.');

if (!isset($redirectMap[$user['role']]))
    jsonResponse(false, 'Your account role is not allowed to sign in.');

session_regenerate_id(true);
$_SESSION['user_id']   = $user['id'];
$_SESSION['username']  = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role']      = $user['role'];

logActivity($pdo, 'LOGIN', "user:{$user['id']}", "Login: {$user['username']}");

jsonResponse(true, 'Login successful.', [
    'role'      => $user['role'],
    'full_name' => $user['full_name'],
    'redirect'  => $redirectMap[$user['role']] ?? '/gcst-system/frontend/login.html',
]);
