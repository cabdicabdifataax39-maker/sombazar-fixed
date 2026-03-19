<?php
$token = $_GET['token'] ?? '';
if ($token !== 'sombazar_test_2026') die('Unauthorized');

$to = $_GET['to'] ?? '';
if (!$to) die('Kullanım: ?token=sombazar_test_2026&to=EMAIL_ADRESIN');

define('SITE_URL', 'https://sombazar-fixed-production.up.railway.app');
require_once __DIR__ . '/api/mailer.php';

echo "<h2>ENV VAR Kontrolü</h2>";
$vars = ['SMTP_HOST','SMTP_PORT','SMTP_USER','SMTP_PASS','SMTP_FROM'];
foreach ($vars as $v) {
    $val = getenv($v);
    echo "<b>$v</b>: " . ($val ? "✅ " . htmlspecialchars(substr($val,0,20))."..." : "❌ YOK") . "<br>";
}

echo "<h2>Email Gönderme Testi</h2>";
$result = Mailer::sendPasswordReset(
    $to,
    'Test Kullanıcı',
    SITE_URL . '/reset-password.html?token=test123'
);

if ($result) {
    echo "✅ <b>Email başarıyla gönderildi!</b> $to adresini kontrol et.";
} else {
    echo "❌ <b>Email gönderilemedi.</b> Railway loglarını kontrol et.";
}
