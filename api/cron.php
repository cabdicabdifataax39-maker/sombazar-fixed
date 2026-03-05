<?php
/**
 * SomBazar — Cron Jobs
 * 
 * Her saat çalıştır:
 *   * * * * * php /var/www/html/api/cron.php >> /var/log/sombazar_cron.log 2>&1
 * 
 * Veya spesifik görevler için:
 *   0 * * * *   php /var/www/html/api/cron.php --task=expire_offers
 *   0 0 * * *   php /var/www/html/api/cron.php --task=cleanup
 *   0 2 * * 0   php /var/www/html/api/cron.php --task=sitemap
 */

// CLI'dan mı çalışıyor kontrol et
$isCLI = php_sapi_name() === 'cli';

// Web'den çalıştırılıyorsa güvenlik kontrolü
if (!$isCLI) {
    $secret = $_GET['secret'] ?? '';
    $expectedSecret = getenv('CRON_SECRET') ?: 'changeme_cron_secret';
    if (!hash_equals($expectedSecret, $secret)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=UTF-8');
}

// Config yükle (output buffering kapalıyken)
define('CRON_MODE', true);
// Content-Type override — cron'da JSON header istemiyoruz
$_CRON_SKIP_HEADERS = true;

// Manuel config yükle
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (!$line || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        putenv(trim(substr($line, 0, $pos)) . '=' . trim(substr($line, $pos+1)));
    }
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function log_cron(string $task, string $msg): void {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] [{$task}] {$msg}\n";
    flush();
}

// Hangi görev çalışacak
$task = ($isCLI && isset($argv))
    ? (preg_match('/--task=(\w+)/', implode(' ', $argv), $m) ? $m[1] : 'all')
    : ($_GET['task'] ?? 'all');

$tasks = ($task === 'all')
    ? ['expire_offers', 'expire_plans', 'expire_listings', 'cleanup_temp', 'cleanup_rate_limit', 'sitemap_ping', 'send_reminders']
    : [$task];

log_cron('cron', 'Starting tasks: ' . implode(', ', $tasks));

foreach ($tasks as $t) {
    try {
        match($t) {
            'expire_offers'    => task_expire_offers(),
            'expire_plans'     => task_expire_plans(),
            'expire_listings'  => task_expire_listings(),
            'cleanup_temp'     => task_cleanup_temp(),
            'cleanup_rate_limit' => task_cleanup_rate_limit(),
            'sitemap_ping'     => task_sitemap_ping(),
            'send_reminders'   => task_send_reminders(),
            default            => log_cron($t, 'Unknown task'),
        };
    } catch (Throwable $e) {
        log_cron($t, 'ERROR: ' . $e->getMessage());
    }
}

log_cron('cron', 'Done.');

// ── GÖREVLER ──────────────────────────────────────────────────

/**
 * 48 saati geçen teklifleri otomatik reddet
 */
function task_expire_offers(): void {
    $db = getDB();
    $st = $db->prepare("
        UPDATE offers 
        SET status = 'expired', updated_at = NOW()
        WHERE status = 'pending' 
          AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)
    ");
    $st->execute();
    $count = $st->rowCount();
    log_cron('expire_offers', "{$count} offers expired");
}

/**
 * Süresi dolmuş planları free'ye düşür
 */
function task_expire_plans(): void {
    $db = getDB();
    $st = $db->prepare("
        UPDATE users 
        SET plan = 'free', plan_expires_at = NULL
        WHERE plan != 'free' 
          AND plan_expires_at IS NOT NULL 
          AND plan_expires_at < NOW()
    ");
    $st->execute();
    $count = $st->rowCount();
    log_cron('expire_plans', "{$count} plans downgraded to free");
}

/**
 * 30 günden fazla askıda kalan ilanları expired'a çek
 */
function task_expire_listings(): void {
    $db = getDB();
    $st = $db->prepare("
        UPDATE listings 
        SET status = 'expired', updated_at = NOW()
        WHERE status = 'active' 
          AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $st->execute();
    $count = $st->rowCount();
    log_cron('expire_listings', "{$count} listings marked expired");
}

/**
 * 7 günden eski temp dosyaları temizle
 */
function task_cleanup_temp(): void {
    $uploadDir = __DIR__ . '/../uploads/temp/';
    if (!is_dir($uploadDir)) { log_cron('cleanup_temp', 'No temp dir'); return; }
    $count = 0;
    foreach (glob($uploadDir . '*') as $file) {
        if (is_file($file) && filemtime($file) < time() - 604800) {
            @unlink($file);
            $count++;
        }
    }
    log_cron('cleanup_temp', "{$count} temp files deleted");
}

/**
 * Eski rate limit dosyalarını temizle
 */
function task_cleanup_rate_limit(): void {
    $dir = sys_get_temp_dir() . '/sombazar_rl/';
    if (!is_dir($dir)) { log_cron('cleanup_rl', 'No rl dir'); return; }
    $count = 0;
    foreach (glob($dir . '*') as $file) {
        if (is_file($file) && filemtime($file) < time() - 3600) {
            @unlink($file);
            $count++;
        }
    }
    log_cron('cleanup_rl', "{$count} rate limit files cleaned");
}

/**
 * Sitemap'i Google'a ping at
 */
function task_sitemap_ping(): void {
    $siteUrl = getenv('SITE_URL') ?: 'https://sombazar.com';
    $sitemap = urlencode($siteUrl . '/sitemap.xml');
    $endpoints = [
        "https://www.google.com/ping?sitemap={$sitemap}",
        "https://www.bing.com/ping?sitemap={$sitemap}",
    ];
    foreach ($endpoints as $url) {
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'method' => 'GET']]);
        @file_get_contents($url, false, $ctx);
    }
    log_cron('sitemap_ping', 'Pinged Google & Bing');
}

/**
 * Plan süresi dolmak üzere olan kullanıcılara hatırlatma gönder (3 gün öncesi)
 */
function task_send_reminders(): void {
    $db = getDB();
    try {
        $st = $db->prepare("
            SELECT u.email, u.display_name, u.plan, u.plan_expires_at
            FROM users u
            WHERE u.plan != 'free'
              AND u.plan_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)
              AND u.reminder_sent_at IS NULL
        ");
        $st->execute();
        $users = $st->fetchAll();
        log_cron('send_reminders', count($users) . ' renewal reminders to send');
        // E-posta gönderimi için mailer'ı yükle
        // require_once __DIR__ . '/mailer.php';
        // foreach ($users as $u) { Mailer::sendRenewalReminder(...); }
    } catch(\Throwable $e) {
        log_cron('send_reminders', 'Skipped (column may not exist): ' . $e->getMessage());
    }
}
