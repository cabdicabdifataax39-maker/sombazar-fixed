<?php
/**
 * SomaBazar Web Migration Runner
 * URL: /run_migration.php?token=YOUR_MIGRATION_TOKEN
 */

$token = $_GET['token'] ?? '';
$validToken = getenv('MIGRATION_TOKEN');

if (!$validToken) {
    http_response_code(500);
    die('<h2>MIGRATION_TOKEN environment variable tanimli degil!</h2>');
}
if ($token !== $validToken) {
    http_response_code(403);
    die('<h2>Gecersiz token.</h2>');
}

// DB baglantisi - hem DB_HOST hem MYSQLHOST destekle
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
    http_response_code(500);
    die('<h2>DB Baglanti Hatasi: ' . htmlspecialchars($e->getMessage()) . '</h2>');
}

// migrations_log tablosunu olustur
$pdo->exec("CREATE TABLE IF NOT EXISTS migrations_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Daha once calistirilan migration'lari topla
$applied = [];
try {
    $rows = $pdo->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $r) $applied[$r] = true;
} catch (Exception $e) {}
try {
    $rows = $pdo->query("SELECT filename FROM migrations_log")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $r) $applied[$r] = true;
} catch (Exception $e) {}

// Migration dosyalarini bul ve sirala
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
  .box { background: #1a1a1a; padding: 20px; border-radius: 8px; margin: 15px 0; line-height: 2; }
</style>
</head>
<body>
<h1>SomaBazar Migration Runner</h1>
<div class="box">
<?php

$ran = 0; $skipped = 0; $errors = 0;

foreach ($files as $file) {
    $basename = basename($file);
    $version  = basename($file, '.sql');

    if (isset($applied[$basename]) || isset($applied[$version])) {
        echo "<span class='skip'>⏭ SKIP: {$basename}</span><br>";
        $skipped++;
        continue;
    }

    $sql = file_get_contents($file);

    // SQL'i statement'lara bol
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
    foreach ($statements as $stmt) {
        if (!trim($stmt) || trim($stmt) === ';') continue;
        try {
            $pdo->exec($stmt);
        } catch (Exception $e) {
            $msg = $e->getMessage();
            // Zaten var olan kolon/index hatalarini gec
            if (strpos($msg, 'Duplicate column') !== false
             || strpos($msg, 'already exists') !== false
             || strpos($msg, '1060') !== false
             || strpos($msg, '1061') !== false) {
                continue;
            }
            echo "<span class='err'>❌ HATA [{$basename}]: " . htmlspecialchars($msg) . "</span><br>";
            $success = false;
            $errors++;
            break;
        }
    }

    if ($success) {
        try {
            $pdo->prepare("INSERT IGNORE INTO migrations_log (filename) VALUES (?)")->execute([$basename]);
        } catch (Exception $e) {}
        echo "<span class='ok'>✅ OK: {$basename}</span><br>";
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
