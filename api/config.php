<?php
ob_start(); // Buffer tüm çıktıyı — header'dan önce whitespace önle
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// SomBazar — config.php

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(self)');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://accounts.google.com https://apis.google.com https://www.googletagmanager.com https://www.gstatic.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob: https://res.cloudinary.com https://*.googleapis.com https://*.gstatic.com; connect-src 'self' https://api.anthropic.com https://res.cloudinary.com https://www.google-analytics.com https://region1.google-analytics.com; frame-src https://www.google.com;");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── Environment Variables ────────────────────────────────────────────
// Önce Railway/sunucu getenv() — sonra .env dosyası (local geliştirme)
$env = [];
$envKeys = ['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS','SITE_URL','UPLOAD_URL',
            'JWT_SECRET','MSG_KEY','SMTP_HOST','SMTP_PORT','SMTP_USER','SMTP_PASS',
            'SMTP_FROM','SMTP_FROM_NAME','GOOGLE_MAPS_KEY',
            'CLOUDINARY_CLOUD_NAME','CLOUDINARY_API_KEY','CLOUDINARY_API_SECRET'];

// 1. Railway / hosting environment variables (en yüksek öncelik)
foreach ($envKeys as $key) {
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        $env[$key] = $val;
    }
}

// 2. .env dosyasından eksikleri tamamla (local XAMPP için)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        $val = trim($val, "\r");
        if (($commentPos = strpos($val, ' #')) !== false) {
            $val = trim(substr($val, 0, $commentPos));
        }
        if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) && !isset($env[$key])) {
            $env[$key] = $val;
        }
    }
}

// Required fields — DB_PASS is optional (empty password is valid)
$required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'SITE_URL', 'JWT_SECRET'];
foreach ($required as $key) {
    if (!isset($env[$key]) || $env[$key] === '') {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => "Missing required .env key: '$key'"]);
        exit();
    }
}

define('DB_HOST',    $env['DB_HOST']);
define('DB_NAME',    $env['DB_NAME']);
define('DB_USER',    $env['DB_USER']);
define('DB_PASS',    $env['DB_PASS'] ?? '');
define('DB_CHARSET', 'utf8mb4');
define('SITE_URL',   rtrim($env['SITE_URL'], '/'));
define('JWT_SECRET', $env['JWT_SECRET']);
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB

// SMTP + Cloudinary — putenv ile set et
foreach (['SMTP_HOST','SMTP_PORT','SMTP_USER','SMTP_PASS','SMTP_FROM','SMTP_FROM_NAME',
          'CLOUDINARY_CLOUD_NAME','CLOUDINARY_API_KEY','CLOUDINARY_API_SECRET'] as $_k) {
    if (!empty($env[$_k])) putenv("$_k={$env[$_k]}");
}

// Database connection
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn     = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET SESSION time_zone = '+00:00'"  );
        } catch (PDOException $e) {
            http_response_code(500);
            $msg = (strpos(SITE_URL, 'localhost') !== false)
                ? 'Database connection failed: ' . $e->getMessage()
                : 'Database connection failed. Please check your .env settings.';
            ob_end_clean();
            echo json_encode(['success' => false, 'error' => $msg]);
            exit();
        }
    }
    return $pdo;
}

// JSON helpers
// ── API Versioning Header ────────────────────────────────────────────────
define('API_VERSION', '1.0.0');
header('X-API-Version: ' . API_VERSION);
header('X-Powered-By: SomBazar');

// ── Genel API Rate Limiter (file-based, Redis olmadan) ──────────────────
// Her endpoint ayrı limit uygulayabilir — bu global fallback
function checkRateLimit(string $key, int $maxRequests = 60, int $windowSeconds = 60): void {
    $ip  = $_SERVER['HTTP_CF_CONNECTING_IP']     // Cloudflare
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown';
    $ip  = explode(',', $ip)[0]; // İlk IP'yi al

    // Localhost geliştirme ortamında rate limit devre dışı
    if (in_array($ip, ['127.0.0.1', '::1', 'localhost'])) return;

    $dir  = sys_get_temp_dir() . '/sombazar_rl/';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }

    $file = $dir . preg_replace('/[^a-zA-Z0-9_]/', '_', $key . '_' . $ip);
    $now  = time();
    $data = ['count' => 0, 'window_start' => $now];

    if (file_exists($file)) {
        $raw = @json_decode(file_get_contents($file), true);
        if ($raw && ($now - $raw['window_start']) < $windowSeconds) {
            $data = $raw;
        }
    }

    $data['count']++;
    @file_put_contents($file, json_encode($data), LOCK_EX);

    if ($data['count'] > $maxRequests) {
        $retryAfter = $windowSeconds - ($now - $data['window_start']);
        header('Retry-After: ' . $retryAfter);
        header('X-RateLimit-Limit: ' . $maxRequests);
        header('X-RateLimit-Remaining: 0');
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait ' . $retryAfter . ' seconds.', 'retry_after' => $retryAfter]);
        exit();
    }

    header('X-RateLimit-Limit: ' . $maxRequests);
    header('X-RateLimit-Remaining: ' . max(0, $maxRequests - $data['count']));
}

// Endpoint bazlı rate limit konfigürasyonu
function applyEndpointRateLimit(string $endpoint): void {
    $limits = [
        'auth'          => [20,  300],  // 20 istek/5 dakika
        'upload'        => [30,  3600], // 30 istek/saat
        'listings_post' => [10,  3600], // 10 yeni ilan/saat
        'offers'        => [30,  3600], // 30 teklif/saat
        'messages'      => [100, 3600], // 100 mesaj/saat
        'reviews'       => [10,  3600], // 10 yorum/saat
        'payment'       => [20,  3600], // 20 ödeme/saat
        'default'       => [120, 60],   // 120 istek/dk (genel)
    ];
    [$max, $window] = $limits[$endpoint] ?? $limits['default'];
    checkRateLimit($endpoint, $max, $window);
}


function jsonSuccess($data, int $code = 200): void {
    ob_end_clean(); // Bekleyen çıktıyı temizle
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data]);
    exit();
}

function jsonError(string $message, int $code = 400, array $extra = []): void {
    ob_end_clean(); // Bekleyen çıktıyı temizle
    http_response_code($code);
    $resp = array_merge(['success' => false, 'error' => $message], $extra);
    echo json_encode($resp);
    exit();
}

// JWT
function createToken(int $userId): string {
    $header  = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64_encode(json_encode(['uid' => $userId, 'iat' => time(), 'exp' => time() + 86400 * 30]));
    $sig     = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$sig";
}

function verifyToken(?string $token): ?int {
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $payload, $sig] = $parts;
    $expected = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $data = json_decode(base64_decode($payload), true);
    if (!$data || $data['exp'] < time()) return null;
    return (int) $data['uid'];
}

function getAuthUser(): ?int {
    // Try multiple sources for Authorization header
    $auth = $_SERVER['HTTP_AUTHORIZATION']
         ?? $_SERVER['HTTP_X_AUTHORIZATION']
         ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
         ?? '';

    // Apache with CGI/FastCGI fallback
    if (!$auth) {
        $allHeaders = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $auth = $allHeaders['Authorization'] ?? $allHeaders['authorization'] ?? '';
    }

    // Last resort: parse from apache_request_headers
    if (!$auth && function_exists('apache_request_headers')) {
        $apacheHeaders = apache_request_headers() ?: [];
        $auth = $apacheHeaders['Authorization'] ?? $apacheHeaders['authorization'] ?? '';
    }

    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        return verifyToken(trim($m[1]));
    }
    return null;
}

function requireAuth(bool $skipBanCheck = false): int {
    $uid = getAuthUser();
    if (!$uid) jsonError('Unauthorized', 401);

    if (!$skipBanCheck) {
        $db = getDB();
        // Try with new columns (migrate4), fallback to basic columns
        try {
            $st = $db->prepare('SELECT banned, ban_reason, token_invalidated_at, last_seen FROM users WHERE id = ?');
            $st->execute([$uid]);
        } catch(\Throwable $e) {
            $st = $db->prepare('SELECT 0 as banned, NULL as ban_reason, NULL as token_invalidated_at, NULL as last_seen FROM users WHERE id = ?');
            $st->execute([$uid]);
        }
        $user = $st->fetch();
        if (!$user) jsonError('Unauthorized', 401);
        if (!empty($user['banned'])) jsonError('Your account has been suspended.' . (!empty($user['ban_reason']) ? ' Reason: ' . $user['ban_reason'] : ''), 403);
        // Check token invalidation
        if (!empty($user['token_invalidated_at'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_AUTHORIZATION'] ?? '';
            if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
                $parts   = explode('.', $m[1]);
                $payload = json_decode(base64_decode($parts[1] ?? ''), true);
                $iat     = $payload['iat'] ?? 0;
                if ($iat < strtotime($user['token_invalidated_at'])) {
                    jsonError('Session expired. Please sign in again.', 401);
                }
            }
        }
        // Update last_seen — throttled: only if last update was >60 seconds ago
        $lastSeen = $user['last_seen'] ?? null;
        if (!$lastSeen || (time() - strtotime($lastSeen)) > 60) {
            try {
                $db->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?')->execute([$uid]);
            } catch(\Throwable $e) {}
        }
    }
    return $uid;
}

// ── CSRF Protection ─────────────────────────────────────────────────────
function generateCsrfToken(int $uid): string {
    $token = bin2hex(random_bytes(32));
    $key = "csrf_{$uid}";
    // Basit file-based storage (session yerine, stateless API için)
    $dir = sys_get_temp_dir() . '/sombazar_csrf/';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    file_put_contents($dir . $key, $token . '|' . (time() + 3600));
    return $token;
}

function verifyCsrfToken(int $uid, string $token): bool {
    if (!$token) return false;
    $dir = sys_get_temp_dir() . '/sombazar_csrf/';
    $file = $dir . "csrf_{$uid}";
    if (!file_exists($file)) return false;
    [$storedToken, $expires] = explode('|', file_get_contents($file));
    if (time() > (int)$expires) { @unlink($file); return false; }
    return hash_equals($storedToken, $token);
}

function requireCsrf(int $uid): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($uid, $token)) {
        jsonError('Invalid CSRF token. Refresh the page and try again.', 403);
    }
}

// ── Message Encryption (AES-256-CBC) ────────────────────────────────────
function encryptMessage(string $text): string {
    $key = defined('MSG_KEY') ? MSG_KEY : (defined('JWT_SECRET') ? substr(hash('sha256', JWT_SECRET, true), 0, 32) : random_bytes(32));
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($text, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($enc === false) return $text; // fallback
    return base64_encode($iv . $enc);
}

function decryptMessage(string $data): string {
    if (!$data) return '';
    $key = defined('MSG_KEY') ? MSG_KEY : (defined('JWT_SECRET') ? substr(hash('sha256', JWT_SECRET, true), 0, 32) : '');
    if (!$key) return $data;
    $raw = base64_decode($data);
    if (!$raw || strlen($raw) < 16) return $data; // şifresiz eski mesaj
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    $dec = @openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $dec !== false ? $dec : $data; // çözülemezse orijinali döndür
}
