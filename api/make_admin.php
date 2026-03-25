<?php
/**
 * SomaBazar — Make Admin
 * URL: /api/make_admin.php?email=EMAIL&secret=SECRET
 */
require_once __DIR__ . '/config.php';

$secret = $_GET['secret'] ?? '';
$email  = trim($_GET['email'] ?? '');

$validSecret = getenv('MIGRATION_TOKEN');
if (!$validSecret || $secret !== $validSecret) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Forbidden']));
}

if (!$email) {
    die(json_encode(['success' => false, 'error' => 'email required']));
}

$db = getDB();

// Kullaniciyi bul
$st = $db->prepare("SELECT id, email, role, is_admin FROM users WHERE email = ?");
$st->execute([$email]);
$user = $st->fetch();

if (!$user) {
    die(json_encode(['success' => false, 'error' => 'User not found: ' . $email]));
}

// is_admin ve role guncelle
try {
    $db->prepare("UPDATE users SET role = 'admin', is_admin = 1 WHERE email = ?")
       ->execute([$email]);
    die(json_encode([
        'success' => true,
        'message' => 'User is now admin',
        'user_id' => $user['id'],
        'email'   => $email,
        'before'  => ['role' => $user['role'], 'is_admin' => $user['is_admin']],
        'after'   => ['role' => 'admin', 'is_admin' => 1]
    ]));
} catch (Exception $e) {
    die(json_encode(['success' => false, 'error' => $e->getMessage()]));
}
