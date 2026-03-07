<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'PHP Error: ' . $err['message'] . ' on line ' . $err['line']]);
    }
});
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
autoMigrateNotifications();

switch ($action) {
    case 'list':         handleList();        break;
    case 'unread_count': handleUnreadCount(); break;
    case 'mark_read':    handleMarkRead();    break;
    case 'mark_all_read':handleMarkAllRead(); break;
    case 'push_subscribe': handlePushSubscribe(); break;
    case 'vapid_key':    handleVapidKey();    break;
    default: jsonError('Unknown action');
}

function autoMigrateNotifications(): void {
    $db = getDB();
    try { $db->exec("ALTER TABLE conversations ADD COLUMN unread_count_1 INT DEFAULT 0"); } catch(\Throwable $e) {}
    try { $db->exec("ALTER TABLE conversations ADD COLUMN unread_count_2 INT DEFAULT 0"); } catch(\Throwable $e) {}
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS notifications (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            type       VARCHAR(30) NOT NULL,
            title      VARCHAR(200) NOT NULL,
            body       TEXT NULL,
            link       VARCHAR(300) NULL,
            icon       VARCHAR(10) DEFAULT '🔔',
            is_read    TINYINT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_read (user_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(\Throwable $e) {}
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            endpoint   TEXT NOT NULL,
            p256dh     TEXT NOT NULL,
            auth       VARCHAR(100) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_endpoint (user_id, endpoint(100))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(\Throwable $e) {}
}

function handleList(): void {
    $uid   = requireAuth();
    $limit = min((int)($_GET['limit'] ?? 30), 100);
    $db    = getDB();
    try {
        $st = $db->prepare(
            "SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ?"
        );
        $st->execute([$uid, $limit]);
        $rows = $st->fetchAll();
        jsonSuccess(['notifications' => array_map('fmtNotif', $rows)]);
    } catch(\Throwable $e) {
        jsonSuccess(['notifications' => []]);
    }
}

function handleUnreadCount(): void {
    $uid = getAuthUser(); // Soft auth - token yoksa 0 döndür
    if (!$uid) {
        jsonSuccess(['total'=>0,'messages'=>0,'offers'=>0,'reviews'=>0,'unread'=>0]);
        return;
    }
    $db  = getDB();
    try {
        $st = $db->prepare(
            "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN type='message' THEN 1 ELSE 0 END) AS messages,
                SUM(CASE WHEN type LIKE 'offer%' THEN 1 ELSE 0 END) AS offers,
                SUM(CASE WHEN type='review' THEN 1 ELSE 0 END) AS reviews
             FROM notifications WHERE user_id=? AND is_read=0"
        );
        $st->execute([$uid]);
        $row = $st->fetch();

        // Mesaj unread count da ekle
        $msgSt = $db->prepare(
            "SELECT COALESCE(SUM(CASE WHEN user1_id=? THEN unread_count_1 ELSE unread_count_2 END),0) AS unread
             FROM conversations WHERE user1_id=? OR user2_id=?"
        );
        $msgSt->execute([$uid, $uid, $uid]);
        $msgRow = $msgSt->fetch();

        jsonSuccess([
            'total'    => (int)$row['total'],
            'messages' => (int)($msgRow['unread'] ?? 0),
            'offers'   => (int)$row['offers'],
            'reviews'  => (int)$row['reviews'],
            'unread'   => (int)$row['total'],
        ]);
    } catch(\Throwable $e) {
        jsonSuccess(['total'=>0,'messages'=>0,'offers'=>0,'reviews'=>0,'unread'=>0]);
    }
}

function handleMarkRead(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $nid  = (int)($data['notification_id'] ?? 0);
    $db   = getDB();
    if ($nid) {
        $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$nid, $uid]);
    }
    jsonSuccess(['message' => 'Marked as read']);
}

function handleMarkAllRead(): void {
    $uid = requireAuth();
    $db  = getDB();
    $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);
    jsonSuccess(['message' => 'All marked as read']);
}

function handleVapidKey(): void {
    $key = getenv('VAPID_PUBLIC_KEY') ?: '';
    if (!$key) {
        jsonSuccess(['publicKey' => null, 'message' => 'Push not configured']);
        return;
    }
    jsonSuccess(['publicKey' => $key]);
}

function handlePushSubscribe(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $endpoint = $data['endpoint'] ?? '';
    $p256dh   = $data['keys']['p256dh'] ?? '';
    $auth     = $data['keys']['auth']   ?? '';
    if (!$endpoint || !$p256dh || !$auth) jsonError('Invalid subscription data');
    $db = getDB();
    try {
        $db->prepare(
            "INSERT INTO push_subscriptions (user_id,endpoint,p256dh,auth)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE p256dh=VALUES(p256dh), auth=VALUES(auth)"
        )->execute([$uid, $endpoint, $p256dh, $auth]);
        jsonSuccess(['message' => 'Push subscription saved']);
    } catch(\Throwable $e) {
        jsonError('Failed to save subscription');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Bildirim oluştur (diğer PHP dosyalarından çağrılır)
// ─────────────────────────────────────────────────────────────────────────────
function createNotification(int $userId, string $type, string $title, string $body = '', string $link = '', string $icon = '🔔'): void {
    try {
        $db = getDB();
        $db->prepare(
            "INSERT INTO notifications (user_id,type,title,body,link,icon) VALUES (?,?,?,?,?,?)"
        )->execute([$userId, $type, $title, $body, $link, $icon]);
        // Web Push gönder — hata olursa sessizce geç
        sendWebPushToUser($userId, $title, $body ?: $title, $link);
    } catch(\Throwable $e) {}
}

// ─────────────────────────────────────────────────────────────────────────────
// Web Push — RFC 8291 (aes128gcm) + VAPID (RFC 8292)
// Gereksinimler: PHP 8.1+, openssl extension, curl extension
// ─────────────────────────────────────────────────────────────────────────────
function sendWebPushToUser(int $userId, string $title, string $body, string $link = ''): void {
    $vapidPublic  = getenv('VAPID_PUBLIC_KEY')  ?: '';
    $vapidPrivate = getenv('VAPID_PRIVATE_KEY') ?: '';
    $vapidSubject = getenv('VAPID_SUBJECT')     ?: ('mailto:' . (getenv('SMTP_USER') ?: 'admin@sombazar.com'));

    // VAPID anahtarları yoksa push atla
    if (!$vapidPublic || !$vapidPrivate) return;
    // openssl_pkey_derive PHP 8.1+ gerektiriyor
    if (!function_exists('openssl_pkey_derive')) return;

    try {
        $db = getDB();
        $st = $db->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?");
        $st->execute([$userId]);
        $subs = $st->fetchAll(\PDO::FETCH_ASSOC);
        if (!$subs) return;

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'icon'  => '/assets/icon-192.png',
            'data'  => ['url' => $link ?: '/'],
            'tag'   => 'sombazar',
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subs as $sub) {
            try {
                wp_send($sub['endpoint'], $sub['p256dh'], $sub['auth'],
                        $payload, $vapidPublic, $vapidPrivate, $vapidSubject);
            } catch(\Throwable $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, '410') || str_contains($msg, '404') || str_contains($msg, 'expired')) {
                    try { $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")->execute([$sub['endpoint']]); } catch(\Throwable $e2) {}
                }
                error_log("WebPush error uid=$userId: $msg");
            }
        }
    } catch(\Throwable $e) {
        error_log("sendWebPushToUser error: " . $e->getMessage());
    }
}

/**
 * RFC 8291 + RFC 8292 — tam implementasyon
 */
function wp_send(string $endpoint, string $p256dh, string $authToken,
                 string $payload, string $vapidPub, string $vapidPriv, string $subject): void {

    // ── 1. VAPID JWT (ES256) ──────────────────────────────────────────────
    $parts   = parse_url($endpoint);
    $audience = $parts['scheme'] . '://' . $parts['host'];

    $jwtHeader  = wp_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $jwtPayload = wp_b64u(json_encode(['aud' => $audience, 'exp' => time() + 43200, 'sub' => $subject]));
    $sigInput   = $jwtHeader . '.' . $jwtPayload;

    $pemKey = wp_vapid_pem($vapidPriv, $vapidPub);
    if (!openssl_sign($sigInput, $rawSig, $pemKey, 'SHA256')) {
        throw new \RuntimeException('openssl_sign failed');
    }
    // DER → raw r||s (64 bytes) for ES256
    $rawSig = wp_der_to_rs($rawSig);
    $jwt    = $sigInput . '.' . wp_b64u($rawSig);

    // ── 2. Payload şifreleme (RFC 8291 / aes128gcm) ───────────────────────
    $recipientPub = wp_b64u_dec($p256dh);   // 65-byte uncompressed EC point
    $authSecret   = wp_b64u_dec($authToken); // 16-byte auth secret
    $salt         = random_bytes(16);

    // Ephemeral ECDH key pair
    $ephKey     = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $ephDetails = openssl_pkey_get_details($ephKey);
    // x ve y 32 byte olmalı — sol-pad gerekebilir
    $ephPubRaw = "\x04"
        . str_pad($ephDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT)
        . str_pad($ephDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    // ECDH: shared secret
    $recipientPem = wp_raw_pub_to_pem($recipientPub);
    $recipientKey = openssl_pkey_get_public($recipientPem);
    if (!$recipientKey) throw new \RuntimeException('Cannot parse recipient public key');
    $sharedSecret = openssl_pkey_derive($ephKey, $recipientKey);
    if ($sharedSecret === false) throw new \RuntimeException('ECDH failed');

    // HKDF (RFC 8291 §3.3)
    // ikm = HKDF-Extract(auth_secret, ecdh_secret || sender_pub || receiver_pub)
    $ikmInfo = "WebPush: info\x00" . $recipientPub . $ephPubRaw;
    $ikm     = wp_hkdf($authSecret, $sharedSecret, $ikmInfo, 32);
    $cek     = wp_hkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
    $nonce   = wp_hkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

    // Pad + encrypt
    $plaintext  = $payload . "\x02"; // delimiter byte
    $tag        = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ciphertext === false) throw new \RuntimeException('AES-GCM encrypt failed');

    // aes128gcm content-coding header: salt(16) + rs(4) + idlen(1) + keyid(65)
    $body = $salt . pack('N', 4096) . chr(strlen($ephPubRaw)) . $ephPubRaw . $ciphertext . $tag;

    // ── 3. HTTP POST ──────────────────────────────────────────────────────
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: vapid t=' . $jwt . ', k=' . $vapidPub,
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
            'Urgency: normal',
        ],
    ]);
    $response = curl_exec($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http === 410 || $http === 404) throw new \RuntimeException("$http subscription expired");
    if ($http >= 400) throw new \RuntimeException("Push HTTP $http: " . substr($response, 0, 200));
}

// ── VAPID helpers ────────────────────────────────────────────────────────────

/**
 * Build PKCS#8 PEM from base64url VAPID private + public keys.
 * VAPID keys are raw 32-byte scalars (base64url).
 * openssl_sign needs a proper PEM to work with EC keys.
 */
function wp_vapid_pem(string $privB64u, string $pubB64u): string {
    $priv = wp_b64u_dec($privB64u);  // 32 bytes
    $pub  = wp_b64u_dec($pubB64u);   // 65 bytes (uncompressed)
    if (strlen($pub) === 65 && $pub[0] === "\x04") {
        // already uncompressed
    } else {
        throw new \RuntimeException('VAPID public key must be 65-byte uncompressed point');
    }

    // ECPrivateKey (SEC1) DER, then wrap in PKCS#8
    // SEQUENCE {
    //   INTEGER 1
    //   OCTET STRING (private key)
    //   [0] OID prime256v1
    //   [1] BIT STRING (public key)
    // }
    $ecPrivKey =
        "\x30\x77"           // SEQUENCE, 119 bytes
      . "\x02\x01\x01"       // INTEGER 1
      . "\x04\x20" . $priv   // OCTET STRING, 32 bytes
      . "\xa0\x0a"           // [0] EXPLICIT, 10 bytes
      . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"  // OID prime256v1
      . "\xa1\x44"           // [1] EXPLICIT, 68 bytes
      . "\x03\x42\x00" . $pub; // BIT STRING (1 unused bit byte + 65 bytes)

    // PKCS#8 wraps the ECPrivateKey in PrivateKeyInfo
    $pkcs8AlgId =
        "\x30\x13"           // SEQUENCE
      . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"  // OID id-ecPublicKey
      . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // OID prime256v1

    $ecPrivKeyOctet = "\x04" . chr(strlen($ecPrivKey)) . $ecPrivKey;

    $pkcs8Inner = "\x02\x01\x00" . $pkcs8AlgId . $ecPrivKeyOctet;
    $pkcs8      = "\x30" . chr(strlen($pkcs8Inner)) . $pkcs8Inner;

    return "-----BEGIN PRIVATE KEY-----\n"
        . chunk_split(base64_encode($pkcs8), 64, "\n")
        . "-----END PRIVATE KEY-----\n";
}

/**
 * Convert DER-encoded ECDSA signature to raw r||s (64 bytes) for JWT ES256.
 */
function wp_der_to_rs(string $der): string {
    // DER: 30 len 02 rlen r 02 slen s
    $offset = 2; // skip 30 + total-len
    $rLen   = ord($der[$offset + 1]);
    $r      = substr($der, $offset + 2, $rLen);
    $offset = $offset + 2 + $rLen;
    $sLen   = ord($der[$offset + 1]);
    $s      = substr($der, $offset + 2, $sLen);
    // remove leading 0x00 padding, then left-pad to 32 bytes
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

/**
 * Convert raw uncompressed EC public key (65 bytes, 0x04 prefix) to PEM.
 */
function wp_raw_pub_to_pem(string $raw): string {
    // SubjectPublicKeyInfo DER for prime256v1
    $header = "\x30\x59"
        . "\x30\x13"
        . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
        . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
        . "\x03\x42\x00"
        . $raw;
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($header), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

/**
 * HKDF-SHA256 (RFC 5869): extract+expand in one call.
 * $salt = IKM salt, $ikm = input key material, $info = context, $len = output bytes
 */
function wp_hkdf(string $salt, string $ikm, string $info, int $len): string {
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $t   = '';
    $okm = '';
    for ($i = 1; strlen($okm) < $len; $i++) {
        $t    = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
        $okm .= $t;
    }
    return substr($okm, 0, $len);
}

function wp_b64u(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function wp_b64u_dec(string $data): string {
    $pad = (4 - strlen($data) % 4) % 4;
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', $pad));
}

function fmtNotif(array $n): array {
    return [
        'id'        => (int)$n['id'],
        'type'      => $n['type'],
        'title'     => $n['title'],
        'body'      => $n['body'],
        'link'      => $n['link'],
        'icon'      => $n['icon'],
        'isRead'    => (bool)$n['is_read'],
        'createdAt' => $n['created_at'],
        'timeAgo'   => timeAgo($n['created_at']),
    ];
}

function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff/60)   . 'm ago';
    if ($diff < 86400) return floor($diff/3600)  . 'h ago';
    return floor($diff/86400) . 'd ago';
}
