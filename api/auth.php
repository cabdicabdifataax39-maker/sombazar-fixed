<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Catch fatal errors and return JSON
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'PHP Error: ' . $err['message'] . ' on line ' . $err['line']]);
    }
});

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'register':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        handleRegister();
        break;
    case 'login':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        handleLogin();
        break;
    case 'me':
        handleMe();
        break;
    case 'update':
        if ($method !== 'POST' && $method !== 'PUT') jsonError('Method not allowed', 405);
        handleUpdate();
        break;
    case 'change_password':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        handleChangePassword();
        break;
    case 'forgot_password':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        handleForgotPassword();
        break;
    case 'reset_password':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        handleResetPassword();
        break;
    case 'google':
    handleGoogleAuth();
    break;
  case 'logout':          handleLogout();         break;
    case 'delete_account':
        if ($method !== 'POST' && $method !== 'DELETE') jsonError('Method not allowed', 405);
        handleDeleteAccount();
        break;
    case 'recover_account':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        handleRecoverAccount();
        break;
    case 'check_deletion':
        handleCheckDeletion();
        break;
    default:
        jsonError('Unknown action', 404);
}

function handleRegister(): void {
    $data  = json_decode(file_get_contents('php://input'), true);
    $name  = trim($data['displayName'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $pass  = $data['password'] ?? '';

    if (!$name || !$email || !$pass) jsonError('All fields are required');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Invalid email address');
    if (strlen($pass) < 6) jsonError('Password must be at least 6 characters');
    if (strlen($name) < 2) jsonError('Name must be at least 2 characters');

    $db = getDB();
    $st = $db->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch()) jsonError('This email is already registered');

    // Blacklist check (phone provided at registration if available)
    $regPhone = trim($data['phone'] ?? '');
    if ($regPhone) {
        $bl = $db->prepare('SELECT id FROM blacklist WHERE phone = ?');
        $bl->execute([$regPhone]);
        if ($bl->fetch()) jsonError('This phone number cannot be used to register.');
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $st   = $db->prepare('INSERT INTO users (display_name, email, password_hash) VALUES (?,?,?)');
    $st->execute([$name, $email, $hash]);
    $uid   = (int) $db->lastInsertId();
    $token = createToken($uid);

    // Send welcome email
    try { Mailer::sendWelcome($email, $name); } catch(\Throwable $e) {}

    jsonSuccess(['token' => $token, 'user' => getUserData($uid)], 201);
}

function handleLogin(): void {
    $data  = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim($data['email'] ?? ''));
    $pass  = $data['password'] ?? '';

    if (!$email || !$pass) jsonError('Email and password are required');

    $db = getDB();

    // Rate Limiting: Aynı IP'den 5 dakikada 5 başarısız deneme → hata
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            email VARCHAR(200),
            success TINYINT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_time (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $chk = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND success=0 AND created_at > NOW() - INTERVAL 5 MINUTE");
        $chk->execute([$ip]);
        if ((int)$chk->fetchColumn() >= 5) {
            jsonError('Too many failed attempts. Please wait 5 minutes.', 429);
        }
    } catch(\Throwable $e) {}

    $st = $db->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch();

    if (!$user || !password_verify($pass, $user['password_hash'])) {
        try { $db->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,0)")->execute([$ip, $email]); } catch(\Throwable $e) {}
        jsonError('Incorrect email or password', 401);
    }

    // Soft delete kontrolü
    if (!empty($user['deleted_at'])) {
        try {
            $daysLeft = max(0, ceil((strtotime($user['deletion_scheduled_at'] ?? 'now') - time()) / 86400));
        } catch(\Throwable $e) { $daysLeft = 0; }
        if ($daysLeft > 0) {
            http_response_code(403);
            echo json_encode([
                'success'        => false,
                'error'          => "Account scheduled for deletion in {$daysLeft} day(s).",
                'account_status' => 'pending_deletion',
                'days_left'      => $daysLeft,
                'can_recover'    => true,
            ]);
            exit();
        } else {
            // Süre dolmuş - kalıcı sil
            $uid2 = (int)$user['id'];
            $db->prepare('DELETE FROM listings WHERE user_id=?')->execute([$uid2]);
            $db->prepare('DELETE FROM favorites WHERE user_id=?')->execute([$uid2]);
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$uid2]);
            jsonError('Account permanently deleted.', 410);
        }
    }

    // Check if account is banned
    if (!empty($user['is_banned'])) {
        jsonError('Your account has been suspended. Contact support.', 403);
    }

    // Update last seen
    $db->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?')->execute([$user['id']]);

    $token = createToken($user['id']);
    jsonSuccess(['token' => $token, 'user' => formatUser($user)]);
}

function handleMe(): void {
    $uid = requireAuth();
    jsonSuccess(getUserData($uid));
}

function handleUpdate(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);

    $fields = [];
    $params = [];

    $map = [
        'displayName' => 'display_name',
        'phone'       => 'phone',
        'city'        => 'city',
        'bio'         => 'bio',
    ];

    // notifications kolonları varsa ekle (yoksa hata vermesin)
    try {
        $db = getDB();
        $testCols = $db->query("SHOW COLUMNS FROM users LIKE 'notifications_email'")->fetch();
        if ($testCols) {
            $map['notifications_email'] = 'notifications_email';
            $map['notifications_push']  = 'notifications_push';
            $map['notifications_sms']   = 'notifications_sms';
        }
    } catch (Exception $e) { /* kolonlar yok, skip */ }

    if (isset($data['notifications']) && is_array($data['notifications'])) {
        $n = $data['notifications'];
        $data['notifications_email'] = $n['email'] ? 1 : 0;
        $data['notifications_push']  = $n['push']  ? 1 : 0;
        $data['notifications_sms']   = $n['sms']   ? 1 : 0;
    }

    foreach ($map as $key => $col) {
        if (array_key_exists($key, $data) || array_key_exists($col, $data)) {
            $val      = $data[$key] ?? $data[$col];
            $fields[] = "$col = ?";
            $params[]  = $val;
        }
    }

    if (empty($fields)) jsonError('No fields to update');

    $params[] = $uid;
    getDB()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    jsonSuccess(getUserData($uid));
}

function handleChangePassword(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);

    $current = $data['currentPassword'] ?? '';
    $new     = $data['newPassword']     ?? '';
    $confirm = $data['confirmPassword'] ?? '';

    if (!$current || !$new || !$confirm) jsonError('All password fields are required');
    if (strlen($new) < 6) jsonError('New password must be at least 6 characters');
    if ($new !== $confirm) jsonError('New passwords do not match');

    $db = getDB();
    $st = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$uid]);
    $user = $st->fetch();

    if (!password_verify($current, $user['password_hash'])) {
        jsonError('Current password is incorrect', 401);
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $uid]);

    jsonSuccess(['message' => 'Password changed successfully']);
}

function handleForgotPassword(): void {
    // Auto-create reset tokens table if not exists
    try { getDB()->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(\Throwable $e) {}
    $data  = json_decode(file_get_contents('php://input'), true);
    $email = strtolower(trim($data['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Invalid email address');

    $db = getDB();
    $st = $db->prepare('SELECT id, display_name FROM users WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch();

    // Always return success (don't reveal if email exists)
    if ($user) {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // Store reset token
        $db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$user['id']]);
        $db->prepare('INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?,?,?)')
           ->execute([$user['id'], $token, $expires]);

        // In production: send email with token
        // For now: return token in response (dev mode)
        $resetUrl = SITE_URL . '/reset-password.html?token=' . $token;

        // Send password reset email
        $emailSent = Mailer::sendPasswordReset($email, $user['display_name'], $resetUrl);

        $response = ['message' => 'Reset instructions sent to your email'];
        // Only expose token in local dev
        if (strpos(SITE_URL, 'localhost') !== false) {
            $response['dev_token'] = $token;
            $response['dev_url']   = $resetUrl;
        }
        jsonSuccess($response);
    } else {
        jsonSuccess(['message' => 'If this email is registered, reset instructions have been sent']);
    }
}

function handleResetPassword(): void {
    $data    = json_decode(file_get_contents('php://input'), true);
    $token   = trim($data['token']   ?? '');
    $new     = $data['newPassword']  ?? '';
    $confirm = $data['confirmPassword'] ?? '';

    if (!$token || !$new || !$confirm) jsonError('All fields are required');
    if (strlen($new) < 6) jsonError('Password must be at least 6 characters');
    if ($new !== $confirm) jsonError('Passwords do not match');

    $db = getDB();
    $st = $db->prepare('SELECT * FROM password_reset_tokens WHERE token = ? AND expires_at > NOW()');
    $st->execute([$token]);
    $row = $st->fetch();

    if (!$row) jsonError('Invalid or expired reset link. Please request a new one.');

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $row['user_id']]);
    $db->prepare('DELETE FROM password_reset_tokens WHERE token = ?')->execute([$token]);

    jsonSuccess(['message' => 'Password reset successfully. You can now sign in.']);
}

function handleDeleteAccount(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    $pass = $data['password'] ?? '';

    if (!$pass) jsonError('Password is required to delete account');

    $db = getDB();

    // Kolon ekle (eski kurulumlar için)
    try { $db->exec("ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL"); } catch(\Throwable $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN deletion_scheduled_at DATETIME NULL"); } catch(\Throwable $e) {}

    $st = $db->prepare('SELECT password, deleted_at FROM users WHERE id = ?');
    $st->execute([$uid]);
    $user = $st->fetch();

    if (!$user || !password_verify($pass, $user['password_hash'])) {
        jsonError('Incorrect password', 401);
    }

    if (!empty($user['deleted_at'])) {
        jsonError('Account is already scheduled for deletion');
    }

    $deleteAt = date('Y-m-d H:i:s', strtotime('+30 days'));

    // Soft delete: 30 gün bekle
    $db->prepare(
        "UPDATE users SET deleted_at = NOW(), deletion_scheduled_at = ? WHERE id = ?"
    )->execute([$deleteAt, $uid]);

    // İlanları gizle
    $db->prepare("UPDATE listings SET status='deleted' WHERE user_id=?")->execute([$uid]);

    jsonSuccess([
        'message'         => 'Account scheduled for deletion',
        'deletion_date'   => $deleteAt,
        'recovery_window' => '30 days',
    ]);
}

function handleRecoverAccount(): void {
    $data        = json_decode(file_get_contents('php://input'), true);
    $fromProfile = !empty($data['from_profile']);

    // Profil içinden kurtarma - zaten giriş yapılmış
    if ($fromProfile) {
        $uid = requireAuth();
        $db  = getDB();
        try { $db->exec("ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL"); } catch(\Throwable $e) {}
        try { $db->exec("ALTER TABLE users ADD COLUMN deletion_scheduled_at DATETIME NULL"); } catch(\Throwable $e) {}
        $db->prepare("UPDATE users SET deleted_at=NULL, deletion_scheduled_at=NULL WHERE id=?")->execute([$uid]);
        $db->prepare("UPDATE listings SET status='active' WHERE user_id=? AND status='deleted'")->execute([$uid]);
        jsonSuccess(['message' => 'Account recovered!']);
        return;
    }

    $email = trim($data['email']    ?? '');
    $pass  = trim($data['password'] ?? '');

    if (!$email || !$pass) jsonError('Email and password required');

    $db = getDB();
    try { $db->exec("ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL"); } catch(\Throwable $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN deletion_scheduled_at DATETIME NULL"); } catch(\Throwable $e) {}

    $st = $db->prepare('SELECT * FROM users WHERE email=?');
    $st->execute([$email]);
    $user = $st->fetch();

    if (!$user || !password_verify($pass, $user['password_hash'])) {
        jsonError('Incorrect email or password', 401);
    }

    if (empty($user['deleted_at'])) {
        jsonError('This account is not scheduled for deletion');
    }

    // 30 gün geçmiş mi?
    if (strtotime($user['deletion_scheduled_at']) < time()) {
        // Kalıcı sil
        $uid = (int)$user['id'];
        $db->prepare('DELETE FROM listings WHERE user_id=?')->execute([$uid]);
        $db->prepare('DELETE FROM favorites WHERE user_id=?')->execute([$uid]);
        $db->prepare('DELETE FROM messages WHERE sender_id=?')->execute([$uid]);
        $db->prepare('DELETE FROM notifications WHERE user_id=?')->execute([$uid]);
        $db->prepare('DELETE FROM offers WHERE buyer_id=? OR seller_id=?')->execute([$uid,$uid]);
        $db->prepare('DELETE FROM reviews WHERE reviewer_id=? OR seller_id=?')->execute([$uid,$uid]);
        $db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
        jsonError('Recovery period has expired. Account has been permanently deleted.', 410);
    }

    // Kurtarma: soft delete geri al, ilanları aktif et
    $db->prepare(
        "UPDATE users SET deleted_at=NULL, deletion_scheduled_at=NULL WHERE id=?"
    )->execute([(int)$user['id']]);
    $db->prepare(
        "UPDATE listings SET status='active' WHERE user_id=? AND status='deleted'"
    )->execute([(int)$user['id']]);

    // Oturum oluştur
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try { $db->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (?,?,1)")->execute([$ip, $email]); } catch(\Throwable $e) {}
    $token = createToken((int)$user['id']);
    jsonSuccess([
        'message' => 'Account successfully recovered! Welcome back.',
        'token'   => $token,
        'user'    => formatUser($user),
    ]);
}

function handleCheckDeletion(): void {
    $uid = requireAuth();
    $db  = getDB();
    try { $db->exec("ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL"); } catch(\Throwable $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN deletion_scheduled_at DATETIME NULL"); } catch(\Throwable $e) {}
    $st = $db->prepare('SELECT deleted_at, deletion_scheduled_at FROM users WHERE id=?');
    $st->execute([$uid]);
    $row = $st->fetch();
    if (!$row || empty($row['deleted_at'])) {
        jsonSuccess(['scheduled' => false]);
        return;
    }
    $daysLeft = max(0, ceil((strtotime($row['deletion_scheduled_at']) - time()) / 86400));
    jsonSuccess([
        'scheduled'     => true,
        'deletion_date' => $row['deletion_scheduled_at'],
        'days_left'     => $daysLeft,
    ]);
}

function getUserData(int $uid): array {
    $db = getDB();
    $st = $db->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u) jsonError('User not found', 404);
    return formatUser($u);
}

function formatUser(array $u): array {
    return [
        'id'          => $u['id'],
        'displayName' => $u['display_name'],
        'email'       => $u['email'],
        'phone'       => $u['phone'],
        'city'        => $u['city'],
        'bio'         => $u['bio'],
        'photoURL'    => (function() use ($u) {
            $p = $u['avatar_url'] ?? $u['photo_url'] ?? null;
            if (!$p) return null;
            return str_starts_with($p, 'http') ? $p : UPLOAD_URL . $p;
        })(),
        'verified'    => (bool) ($u['is_verified'] ?? $u['verified'] ?? false),
        'isAdmin'     => (bool) ($u['is_admin'] ?? false),
        'banned'      => (bool) ($u['is_banned'] ?? false),
        'rating'      => (float) $u['rating'],
        'reviewCount' => (int) $u['review_count'],
        'memberSince' => date('Y', strtotime($u['created_at'])),
        'lastSeen'    => $u['last_seen'] ?? null,
        'notifications' => [
            'email' => (bool) $u['notifications_email'],
            'push'  => (bool) $u['notifications_push'],
            'sms'   => (bool) $u['notifications_sms'],
        ],
        'createdAt' => $u['created_at'],
    ];
}
function handleLogout(): void {
    $uid = requireAuth();
    $db  = getDB();
    // Token invalidation: bu tarihten önce üretilen tokenlar artık geçersiz
    $db->prepare('UPDATE users SET token_invalidated_at = NOW() WHERE id = ?')
       ->execute([$uid]);
    jsonSuccess(['message' => 'Logged out successfully']);
}


function handleGoogleAuth(): void {
    $data     = json_decode(file_get_contents('php://input'), true);
    $token    = $data['credential'] ?? $data['token'] ?? '';
    $clientId = '918952161998-m9equ5ehlmq1cdsjicq26icvid3b4shp.apps.googleusercontent.com';

    if (!$token) jsonError('No credential provided');

    // Google token doğrula
    $url  = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($token);
    $resp = @file_get_contents($url);
    if (!$resp) jsonError('Failed to verify Google token');

    $info = json_decode($resp, true);
    if (!$info || ($info['aud'] ?? '') !== $clientId) jsonError('Invalid Google token');
    if (($info['email_verified'] ?? '') !== 'true') jsonError('Email not verified');

    $email  = $info['email'];
    $name   = $info['name']    ?? explode('@', $email)[0];
    $avatar = $info['picture'] ?? null;

    $db = getDB();

    // Kullanıcı var mı?
    $st = $db->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch();

    if (!$user) {
        // Yeni kullanıcı oluştur
        $db->prepare(
            'INSERT INTO users (display_name, email, password_hash, avatar_url, is_verified) VALUES (?,?,?,?,1)'
        )->execute([$name, $email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $avatar]);
        $uid = (int)$db->lastInsertId();
        $st->execute([$email]);
        $user = $st->fetch();
    } else {
        // Avatar güncelle (Google'dan her seferinde güncel gelir)
        if ($avatar && !$user['avatar_url']) {
            $db->prepare('UPDATE users SET avatar_url = ?, photo_url = ? WHERE id = ?')
               ->execute([$avatar, $avatar, $user['id']]);
        }
    }

    if ($user['is_banned'] ?? false) jsonError('Account banned', 403);

    $token = createToken((int)$user['id']);
    jsonSuccess(['token' => $token, 'user' => formatUser($user)]);
}
