<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'PHP Error: ' . $err['message'] . ' on line ' . $err['line']]);
    }
});

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

$uid = requireAuth();

if (!isset($_FILES['avatar'])) jsonError('No file uploaded');

$file    = $_FILES['avatar'];
$tmpName = $file['tmp_name'];
$size    = $file['size'];

if ($file['error'] !== UPLOAD_ERR_OK) jsonError('Upload error');
if ($size > MAX_FILE_SIZE) jsonError('File too large (max 5MB)');

$info = getimagesize($tmpName);
if (!$info) jsonError('Invalid image');

// 0.3 Path Traversal: Kullanıcı adı yoksayılır, MIME kontrolü
$mime = @mime_content_type($tmpName);
$allowedMime = ['image/jpeg','image/png','image/webp','image/gif'];
if (!in_array($mime, $allowedMime)) jsonError('Invalid file type');

$uploadDir = UPLOAD_DIR . 'avatars/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$ext = match($mime) {
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    default      => 'jpg',
};

$filename = null;
$ok = false;

// GD ile WebP'ye çevir (EXIF temizleme + güvenlik)
if (function_exists('imagewebp')) {
    $src = null;
    if ($mime === 'image/jpeg') $src = @imagecreatefromjpeg($tmpName);
    elseif ($mime === 'image/png')  $src = @imagecreatefrompng($tmpName);
    elseif ($mime === 'image/webp') $src = @imagecreatefromwebp($tmpName);
    elseif ($mime === 'image/gif')  $src = @imagecreatefromgif($tmpName);
    if ($src) {
        $filename = bin2hex(random_bytes(16)) . '.webp';
        $ok = imagewebp($src, $uploadDir . $filename, 85);
        imagedestroy($src);
    }
}

// GD yoksa fallback: orijinal dosyayı UUID ismiyle kaydet
if (!$ok) {
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $ok = move_uploaded_file($tmpName, $uploadDir . $filename);
}

if (!$ok) jsonError('Failed to save image');

// Update user record
$db = getDB();
$db->prepare('UPDATE users SET photo_url = ? WHERE id = ?')
   ->execute(['avatars/' . $filename, $uid]);

jsonSuccess(['photoURL' => UPLOAD_URL . 'avatars/' . $filename]);
