<?php
/**
 * SomaBazar Web Migration Runner v2
 * URL: /run_migration.php?token=YOUR_MIGRATION_TOKEN
 */

$token = $_GET['token'] ?? '';
$validToken = getenv('MIGRATION_TOKEN');

if (!$validToken) { http_response_code(500); die('<h2>MIGRATION_TOKEN tanimli degil!</h2>'); }
if ($token !== $validToken) { http_response_code(403); die('<h2>Gecersiz token.</h2>'); }

// DB baglantisi
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (!$line || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        if (!getenv($k)) putenv("$k=$v");
    }
}

$host = getenv('DB_HOST') ?: getenv('MYSQLHOST');
$port = getenv('DB_PORT') ?: getenv('MYSQLPORT') ?: '3306';
$name = getenv('DB_NAME') ?: getenv('MYSQLDATABASE');
$user = getenv('DB_USER') ?: getenv('MYSQLUSER');
$pass = getenv('DB_PASS') ?: getenv('MYSQLPASSWORD');

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die('<h2>DB Hatasi: ' . htmlspecialchars($e->getMessage()) . '</h2>');
}

// migrations_log tablosu
$pdo->exec("CREATE TABLE IF NOT EXISTS migrations_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Calistirilan migration'lari topla
$applied = [];
try {
    $rows = $pdo->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $r) { $applied[$r] = true; $applied[$r . '.sql'] = true; }
} catch (Exception $e) {}
try {
    $rows = $pdo->query("SELECT filename FROM migrations_log")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $r) { $applied[$r] = true; $applied[basename($r, '.sql')] = true; }
} catch (Exception $e) {}

// S01'den gelen ve zaten DB'de olan migration'lari manuel olarak isaretlenebilir
// 001 ve 002 zaten schema_migrations'ta kayitli olmayabilir ama tablolar var
// Bu dosyalari direkt atla
$alwaysSkip = ['001_core_tables.sql', '002_stores.sql'];

$dir = __DIR__ . '/migrations/';
$files = glob($dir . '*.sql');
sort($files);

?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>SomaBazar Migration Runner</title>
<style>
  body { font-family: monospace; background: #0a0a0a; color: #e0e0e0; padding: 30px; }
  h1   { color: #00b5c8; }
  .ok  { color: #4caf50; }
  .skip{ color: #888; }
  .err { color: #f44336; }
  .warn{ color: #ff9800; }
  .box { background: #1a1a1a; padding: 20px; border-radius: 8px; margin: 15px 0; line-height: 2; }
</style>
</head>
<body>
<h1>SomaBazar Migration Runner v2</h1>
<div class="box">
<?php

$ran = 0; $skipped = 0; $errors = 0;

foreach ($files as $file) {
    $basename = basename($file);
    $version  = basename($file, '.sql');

    // Her zaman atlanacaklar (S01 dosyalari - zaten DB'de mevcut)
    if (in_array($basename, $alwaysSkip)) {
        echo "<span class='skip'>⏭ SKIP (S01 dosyasi): {$basename}</span><br>";
        // migrations_log'a ekle ki bir daha denenmesin
        try { $pdo->prepare("INSERT IGNORE INTO migrations_log (filename) VALUES (?)")->execute([$basename]); } catch(Exception $e){}
        $skipped++;
        continue;
    }

    // Daha once calistirildi mi?
    if (isset($applied[$basename]) || isset($applied[$version])) {
        echo "<span class='skip'>⏭ SKIP (zaten calistirildi): {$basename}</span><br>";
        $skipped++;
        continue;
    }

    $sql = file_get_contents($file);

    // Statement'lara bol
    $statements = [];
    $current = '';
    foreach (explode("\n", $sql) as $line) {
        if (stripos(trim($line), 'DELIMITER') === 0) continue;
        $current .= $line . "\n";
        if (substr(rtrim($line), -1) === ';') {
            $stmt = trim($current);
            if ($stmt && $stmt !== ';') $statements[] = $stmt;
            $current = '';
        }
    }
    if (trim($current)) $statements[] = trim($current);

    $success = true;
    $warnings = [];

    foreach ($statements as $stmt) {
        if (!trim($stmt) || trim($stmt) === ';') continue;
        try {
            $pdo->exec($stmt);
        } catch (Exception $e) {
            $msg = $e->getMessage();
            $code = $e->getCode();
            // Zararsiz hatalar - devam et
            if (strpos($msg, 'Duplicate column') !== false      // 1060
             || strpos($msg, 'already exists') !== false
             || strpos($msg, "1060") !== false
             || strpos($msg, "1061") !== false                  // Duplicate key name
             || strpos($msg, "1050") !== false) {               // Table already exists
                $warnings[] = "⚠ Zaten var (normal): " . substr($msg, 0, 80);
                continue;
            }
            echo "<span class='err'>❌ HATA [{$basename}]: " . htmlspecialchars($msg) . "</span><br>";
            $success = false;
            $errors++;
            break;
        }
    }

    if ($success) {
        try { $pdo->prepare("INSERT IGNORE INTO migrations_log (filename) VALUES (?)")->execute([$basename]); } catch(Exception $e){}
        echo "<span class='ok'>✅ OK: {$basename}</span><br>";
        foreach ($warnings as $w) echo "<span class='warn' style='font-size:11px;padding-left:20px;'>{$w}</span><br>";
        $ran++;
    }
}

?>
</div>
<div class="box">
<b>Sonuc:</b>
<span class="ok">✅ <?= $ran ?> migration calistirildi</span> &nbsp;|&nbsp;
<span class="skip">⏭ <?= $skipped ?> atildi</span> &nbsp;|&nbsp;
<span class="err">❌ <?= $errors ?> hata</span>
</div>
</body>
</html>
