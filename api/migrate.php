<?php
/**
 * SomBazar — Database Migration System
 * 
 * Kullanım:
 *   php api/migrate.php            — Bekleyen tüm migration'ları çalıştır
 *   php api/migrate.php --status   — Migration durumunu göster
 *   php api/migrate.php --rollback — Son migration'ı geri al
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// .env yükle
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

$dsn = 'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4';
$db  = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Migrations tablosu oluştur
$db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(100) NOT NULL UNIQUE,
    applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    checksum VARCHAR(64) NULL
) ENGINE=InnoDB");

$migrationsDir = __DIR__ . '/../migrations/';
$action = in_array('--status', $argv) ? 'status' : (in_array('--rollback', $argv) ? 'rollback' : 'migrate');

// Mevcut migration'ları yükle
$applied = $db->query("SELECT version FROM schema_migrations ORDER BY version")->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

// Migration dosyalarını bul
$files = glob($migrationsDir . '*.sql');
sort($files);

if ($action === 'status') {
    echo str_pad('Version', 50) . str_pad('Status', 12) . "Applied At\n";
    echo str_repeat('-', 80) . "\n";
    foreach ($files as $file) {
        $ver = basename($file, '.sql');
        $status = isset($applied[$ver]) ? '✅ applied' : '⏳ pending';
        $at = isset($applied[$ver]) ? $db->query("SELECT applied_at FROM schema_migrations WHERE version = " . $db->quote($ver))->fetchColumn() : '—';
        echo str_pad($ver, 50) . str_pad($status, 12) . $at . "\n";
    }
    exit(0);
}

if ($action === 'migrate') {
    $pending = array_filter($files, fn($f) => !isset($applied[basename($f, '.sql')]));
    if (empty($pending)) { echo "✅ All migrations applied. Nothing to do.\n"; exit(0); }

    foreach ($pending as $file) {
        $ver  = basename($file, '.sql');
        $sql  = file_get_contents($file);
        $hash = md5($sql);
        echo "Applying {$ver}…";
        try {
            $db->exec("BEGIN");
            $db->exec($sql);
            $db->prepare("INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)")->execute([$ver, $hash]);
            $db->exec("COMMIT");
            echo " ✅\n";
        } catch (Throwable $e) {
            $db->exec("ROLLBACK");
            echo " ❌ {$e->getMessage()}\n";
            exit(1);
        }
    }
    echo "✅ All done.\n";
    exit(0);
}


// ── Notifications kolonları ekle (yoksa) ──────────────────────────────
$notifCols = [
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS notifications_email TINYINT(1) DEFAULT 0",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS notifications_push  TINYINT(1) DEFAULT 0",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS notifications_sms   TINYINT(1) DEFAULT 0",
];
foreach ($notifCols as $sql) {
    try { $db->exec($sql); } catch (Exception $e) { /* already exists */ }
}
