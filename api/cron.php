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
    $secret = $_GET['token'] ?? $_GET['secret'] ?? '';
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
            'expire_listings'      => task_expire_listings(),
            'expire_reservations'  => task_expire_reservations(),
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

    // Bildirim göndereceğimiz teklifleri önce çek
    $toExpire = $db->prepare("
        SELECT o.id, o.buyer_id, o.seller_id, l.title AS listing_title, l.id AS listing_id
        FROM offers o JOIN listings l ON o.listing_id = l.id
        WHERE o.status = 'pending'
          AND o.created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)
    ");
    $toExpire->execute();
    $expiring = $toExpire->fetchAll();

    $st = $db->prepare("
        UPDATE offers 
        SET status = 'expired', updated_at = NOW()
        WHERE status = 'pending' 
          AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)
    ");
    $st->execute();
    $count = $st->rowCount();

    // Alıcıya bildirim: teklifin süresi doldu
    foreach ($expiring as $o) {
        try {
            $db->prepare("INSERT INTO notifications (user_id,type,title,body,link,icon) VALUES (?,?,?,?,?,?)")
               ->execute([$o['buyer_id'], 'offer_rejected',
                   'Offer expired',
                   "Your offer on \"" . $o['listing_title'] . "\" was not responded to within 48 hours.",
                   'offers.html?listing=' . $o['listing_id'], '']);
        } catch(\Throwable $e) {}
    }

    log_cron('expire_offers', "{$count} offers expired, " . count($expiring) . " buyers notified");
}

/**
 * Süresi dolmuş planları free'ye düşür
 */
function task_expire_plans(): void {
    $db = getDB();

    // Bildirim için önce kimi etkiliyor al
    $toExpire = $db->prepare("
        SELECT id, plan FROM users
        WHERE plan != 'free'
          AND plan_expires_at IS NOT NULL
          AND plan_expires_at < NOW()
    ");
    $toExpire->execute();
    $expiring = $toExpire->fetchAll();

    $st = $db->prepare("
        UPDATE users 
        SET plan = 'free', plan_expires_at = NULL
        WHERE plan != 'free' 
          AND plan_expires_at IS NOT NULL 
          AND plan_expires_at < NOW()
    ");
    $st->execute();
    $count = $st->rowCount();

    foreach ($expiring as $u) {
        try {
            $db->prepare("INSERT INTO notifications (user_id,type,title,body,link,icon) VALUES (?,?,?,?,?,?)")
               ->execute([$u['id'], 'system',
                   'Your plan has expired',
                   "Your " . ucfirst($u['plan']) . " plan has ended. Upgrade to continue enjoying premium features.",
                   'packages.html', '']);
        } catch(\Throwable $e) {}
    }

    log_cron('expire_plans', "{$count} plans downgraded to free");
}

/**
 * 30 günden fazla askıda kalan ilanları expired'a çek
 */
function task_expire_listings(): void {
    $db = getDB();

    // Kimin ilanı etkileniyor
    $toExpire = $db->prepare("
        SELECT id, user_id, title FROM listings
        WHERE status = 'active'
          AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $toExpire->execute();
    $expiring = $toExpire->fetchAll();

    $st = $db->prepare("
        UPDATE listings 
        SET status = 'expired', updated_at = NOW()
        WHERE status = 'active' 
          AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $st->execute();
    $count = $st->rowCount();

    foreach ($expiring as $l) {
        try {
            $db->prepare("INSERT INTO notifications (user_id,type,title,body,link,icon) VALUES (?,?,?,?,?,?)")
               ->execute([$l['user_id'], 'listing',
                   'Listing expired',
                   "Your listing \"" . $l['title'] . "\" has expired after 30 days. Re-post it to make it visible again.",
                   'post.html', '']);
        } catch(\Throwable $e) {}
    }

    log_cron('expire_listings', "{$count} listings marked expired");
}

/**
 * 7 günden eski temp dosyaları temizle
 */


/**
 * Suresi dolan rezervasyonlari expire et
 */
function task_expire_reservations(): void {
    $db = getDB();

    // Pending - 2 saatten eski
    $db->prepare("
        UPDATE reservations
        SET status = 'expired', updated_at = NOW()
        WHERE status = 'pending'
          AND created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)
    ")->execute();

    // Confirmed - expires_at gecmis
    $toExpire = $db->prepare("
        SELECT r.id, r.buyer_id, r.seller_id, r.listing_id, l.title
        FROM reservations r
        JOIN listings l ON r.listing_id = l.id
        WHERE r.status = 'confirmed'
          AND r.expires_at < NOW()
    ");
    $toExpire->execute();
    $expiring = $toExpire->fetchAll();

    if (!empty($expiring)) {
        $db->prepare("
            UPDATE reservations
            SET status = 'expired', updated_at = NOW()
            WHERE status = 'confirmed' AND expires_at < NOW()
        ")->execute();

        // listing'i tekrar active yap
        foreach ($expiring as $r) {
            try {
                $db->prepare("UPDATE listings SET status = 'active' WHERE id = ? AND status = 'reserved'")
                   ->execute([$r['listing_id']]);

                // Aliciya bildirim
                $db->prepare("INSERT INTO notifications (user_id,type,title,body,url) VALUES (?,?,?,?,?)")
                   ->execute([$r['buyer_id'], 'reservation',
                       'Reservation expired',
                       "Your reservation for "{$r['title']}" has expired.",
                       '/listing.html?id=' . $r['listing_id']]);

                // Saticiya bildirim
                $db->prepare("INSERT INTO notifications (user_id,type,title,body,url) VALUES (?,?,?,?,?)")
                   ->execute([$r['seller_id'], 'reservation',
                       'Reservation expired',
                       "Reservation for "{$r['title']}" expired. Item is active again.",
                       '/listing.html?id=' . $r['listing_id']]);
            } catch (\Throwable $e) {}
        }
    }

    $count = count($expiring);
    log_cron('expire_reservations', "{$count} reservations expired");
}

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
        // reminder_sent_at kolonu yoksa ekle
        try { $db->exec("ALTER TABLE users ADD COLUMN reminder_sent_at DATETIME NULL"); } catch(\Throwable $e) {}

        $st = $db->prepare("
            SELECT u.id, u.email, u.display_name, u.plan, u.plan_expires_at
            FROM users u
            WHERE u.plan != 'free'
              AND u.plan_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)
              AND (u.reminder_sent_at IS NULL OR u.reminder_sent_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
        ");
        $st->execute();
        $users = $st->fetchAll();
        log_cron('send_reminders', count($users) . ' renewal reminders to send');

        require_once __DIR__ . '/mailer.php';
        foreach ($users as $u) {
            $expiresFormatted = date('M j, Y', strtotime($u['plan_expires_at']));
            // Email
            try {
                Mailer::send(
                    $u['email'], $u['display_name'],
                    'Your SomBazar plan expires in 3 days',
                    "<h2>Hi {$u['display_name']}!</h2><p>Your <b>" . ucfirst($u['plan']) . " plan</b> expires on <b>$expiresFormatted</b>. Renew now to keep your listings active and premium features.</p><p><a href='" . (defined('SITE_URL') ? SITE_URL : '') . "/packages.html' style='background:#f97316;color:white;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold'>Renew My Plan</a></p>"
                );
            } catch(\Throwable $e) {}
            // In-app bildirim
            try {
                $db->prepare("INSERT IGNORE INTO notifications (user_id,type,title,body,link,icon) VALUES (?,?,?,?,?,?)")
                   ->execute([$u['id'], 'system',
                       'Plan expires in 3 days ⏰',
                       "Your " . ucfirst($u['plan']) . " plan expires on $expiresFormatted. Renew to keep premium features.",
                       'packages.html', '']);
            } catch(\Throwable $e) {}
            // reminder_sent_at güncelle (tekrar göndermeyi önle)
            $db->prepare("UPDATE users SET reminder_sent_at = NOW() WHERE id = ?")->execute([$u['id']]);
        }
    } catch(\Throwable $e) {
        log_cron('send_reminders', 'Error: ' . $e->getMessage());
    }
}
