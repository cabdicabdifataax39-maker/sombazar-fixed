<?php
// GÜVENLİK: Bu dosyayı kullandıktan sonra SİL!
require_once __DIR__ . '/api/config.php';

$secret = $_GET['secret'] ?? '';
if ($secret !== 'sombazar2026') {
    die('Unauthorized');
}

$email = $_GET['email'] ?? '';
if (!$email) {
    die('Email gerekli: ?secret=sombazar2026&email=senin@email.com');
}

$db = getDB();
$st = $db->prepare('UPDATE users SET is_admin = 1 WHERE email = ?');
$st->execute([$email]);

if ($st->rowCount() > 0) {
    echo "✅ Başarılı! $email artık admin. Bu dosyayı şimdi sil!";
} else {
    echo "❌ Kullanıcı bulunamadı: $email";
}
