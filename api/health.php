<?php
// SomBazar — Setup Health Check
// Open in browser: http://localhost/sombazar-fixed/api/health.php
// Everything green? You're good to go.

header('Content-Type: text/html; charset=UTF-8');

$checks = [];

// 1. PHP version
$phpOk    = version_compare(PHP_VERSION, '8.0.0', '>=');
$checks[] = [
    'name'   => 'PHP Version',
    'ok'     => $phpOk,
    'detail' => 'Current: ' . PHP_VERSION . ($phpOk ? ' ✅' : ' ❌ (PHP 8.0+ required)'),
];

// 2. .env file
$envFile   = __DIR__ . '/../.env';
$envExists = file_exists($envFile);
$checks[]  = [
    'name'   => '.env File',
    'ok'     => $envExists,
    'detail' => $envExists ? 'Found ✅' : 'Not found ❌ — create a .env file in the project root',
];

// 3. .env contents
$env   = [];
$envOk = false;
if ($envExists) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $env[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
    }
    $required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'SITE_URL', 'JWT_SECRET'];
    $missing  = array_filter($required, fn($k) => !isset($env[$k]) || $env[$k] === '');
    $envOk    = empty($missing);
    $checks[] = [
        'name'   => '.env Contents',
        'ok'     => $envOk,
        'detail' => $envOk ? 'All required keys present ✅' : 'Missing keys ❌: ' . implode(', ', $missing),
    ];
}

// 4. Database connection
$dbOk     = false;
$dbDetail = '';
if ($envOk) {
    try {
        $dsn = 'mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $dbOk     = true;
        $dbDetail = 'Connected ✅';
        $tables   = $pdo->query("SHOW TABLES LIKE 'users'")->fetchAll();
        $dbDetail .= count($tables)
            ? ' — users table exists ✅'
            : ' — ⚠️ users table missing! Import schema.sql.';
    } catch (PDOException $e) {
        $dbDetail = 'Connection failed ❌: ' . $e->getMessage();
    }
}
$checks[] = [
    'name'   => 'Database',
    'ok'     => $dbOk,
    'detail' => $dbDetail ?: ($envOk ? 'Could not test' : 'Fix .env first'),
];

// 5. uploads/ directories
foreach (['uploads/', 'uploads/listings/', 'uploads/avatars/'] as $rel) {
    $dir      = __DIR__ . '/../' . $rel;
    $exists   = is_dir($dir);
    if (!$exists) @mkdir($dir, 0755, true);
    $writable = is_dir($dir) && is_writable($dir);
    $checks[] = [
        'name'   => $rel,
        'ok'     => $writable,
        'detail' => $writable ? 'Writable ✅' : ($exists ? 'Exists but not writable ❌ — run chmod 755' : 'Could not create ❌'),
    ];
}

$allOk = !in_array(false, array_column($checks, 'ok'), true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SomBazar — Setup Check</title>
<style>
  body  { font-family: sans-serif; max-width: 640px; margin: 40px auto; padding: 0 20px; background: #f5f5f5; }
  h1    { font-size: 22px; }
  .card { background: white; border-radius: 10px; padding: 16px 20px; margin-bottom: 10px; border-left: 4px solid #ccc; }
  .ok   { border-left-color: #22c55e; }
  .fail { border-left-color: #ef4444; }
  .name   { font-weight: bold; font-size: 15px; }
  .detail { color: #555; font-size: 13px; margin-top: 4px; }
  .banner { padding: 14px 20px; border-radius: 10px; font-weight: bold; margin-bottom: 24px; }
  .banner.ok   { background: #dcfce7; color: #166534; }
  .banner.fail { background: #fee2e2; color: #991b1b; }
  .next { background: white; border-radius: 10px; padding: 20px; margin-top: 16px; }
  .next h2 { font-size: 16px; margin-top: 0; }
  code { background: #f0f0f0; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
  a { color: #2563eb; }
</style>
</head>
<body>
<h1>🛒 SomBazar — Setup Check</h1>

<div class="banner <?= $allOk ? 'ok' : 'fail' ?>">
  <?= $allOk
    ? '✅ Everything looks good! The site is ready to use.'
    : '❌ Some issues found. Fix the red items below.' ?>
</div>

<?php foreach ($checks as $c): ?>
<div class="card <?= $c['ok'] ? 'ok' : 'fail' ?>">
  <div class="name"><?= htmlspecialchars($c['name']) ?></div>
  <div class="detail"><?= htmlspecialchars($c['detail']) ?></div>
</div>
<?php endforeach; ?>

<?php if (!$dbOk && $envOk): ?>
<div class="next">
  <h2>⚡ Quick Fix — Database Setup</h2>
  <ol>
    <li>Open <strong>phpMyAdmin</strong></li>
    <li>Create a new database named <code><?= htmlspecialchars($env['DB_NAME'] ?? 'sombazar') ?></code> with collation <code>utf8mb4_unicode_ci</code></li>
    <li>Select that database → click <strong>Import</strong> → upload <code>schema.sql</code></li>
    <li>Refresh this page</li>
  </ol>
</div>
<?php endif; ?>

<?php if ($allOk): ?>
<div class="next">
  <h2>🎉 Setup complete!</h2>
  <p>You can now delete this file or restrict access to it.</p>
  <p><a href="../index.html">→ Go to the site</a></p>
</div>
<?php endif; ?>
</body>
</html>
