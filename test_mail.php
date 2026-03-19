<?php
// test_mail.php - Deploy et, tarayıcıdan aç, sonra sil
// Güvenlik: token kontrolü
$token = $_GET['token'] ?? '';
if ($token !== 'sombazar_test_2026') {
    die('Unauthorized');
}

$to = $_GET['to'] ?? '';
if (!$to) {
    die('?token=sombazar_test_2026&to=EMAIL_ADRESIN');
}

// ENV VAR kontrolü
echo "<h2>ENV VAR Kontrolü</h2>";
$vars = ['SMTP_HOST','SMTP_PORT','SMTP_USER','SMTP_PASS','SMTP_FROM'];
foreach ($vars as $v) {
    $val = getenv($v);
    echo "<b>$v</b>: " . ($val ? "✅ " . htmlspecialchars(substr($val,0,10))."..." : "❌ YOK") . "<br>";
}

// PHPMailer ile SMTP testi
echo "<h2>SMTP Test</h2>";

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = getenv('SMTP_HOST') ?: 'smtp-relay.brevo.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = getenv('SMTP_USER');
    $mail->Password   = getenv('SMTP_PASS');
    $mail->SMTPSecure = 'tls';
    $mail->Port       = (int)(getenv('SMTP_PORT') ?: 587);

    $from = getenv('SMTP_FROM') ?: 'noreply@sombazar.com';
    $mail->setFrom($from, 'SomBazar Test');
    $mail->addAddress($to);
    $mail->Subject = 'SomBazar SMTP Test ✅';
    $mail->Body    = 'Bu email Brevo SMTP üzerinden başarıyla gönderildi!';

    $mail->send();
    echo "✅ <b>Email gönderildi!</b> $to adresini kontrol et.";
} catch (Exception $e) {
    echo "❌ <b>Hata:</b> " . htmlspecialchars($mail->ErrorInfo);
}
