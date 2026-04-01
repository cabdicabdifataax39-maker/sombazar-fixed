<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'PHP Error: ' . $err['message']]);
    }
});

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

$uid  = requireAuth();
$type = trim($_GET['type'] ?? 'logo');

if (!in_array($type, ['logo','cover'])) jsonError('type must be logo or cover');

$db = getDB();
$st = $db->prepare("SELECT id FROM stores WHERE owner_id = ?");
$st->execute([$uid]);
$store = $st->fetch();
if (!$store) jsonError('Store not found. Create a store first.', 404);
$storeId = (int)$store['id'];

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    jsonError('No file uploaded or upload error');
}

$file     = $_FILES['file'];
$tmpPath  = $file['tmp_name'];
$mimeType = mime_content_type($tmpPath);

if (!in_array($mimeType, ['image/jpeg','image/jpg','image/png','image/webp'])) {
    jsonError('Invalid file type. Allowed: jpg, png, webp');
}
if ($file['size'] > 5 * 1024 * 1024) jsonError('File too large. Max 5MB');

$cloudName = getenv('CLOUDINARY_CLOUD_NAME');
$apiKey    = getenv('CLOUDINARY_API_KEY');
$apiSecret = getenv('CLOUDINARY_API_SECRET');

if ($cloudName && $apiKey && $apiSecret) {
    $folder    = 'sombazar/stores';
    $timestamp = time();
    $signature = sha1("folder=$folder&timestamp=$timestamp" . $apiSecret);

    $ch = curl_init("https://api.cloudinary.com/v1_1/$cloudName/image/upload");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'file'      => new CURLFile($tmpPath),
            'folder'    => $folder,
            'timestamp' => $timestamp,
            'api_key'   => $apiKey,
            'signature' => $signature,
        ],
    ]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!isset($data['secure_url'])) {
        jsonError('Cloudinary upload failed: ' . ($data['error']['message'] ?? 'Unknown'));
    }
    $imageUrl = $data['secure_url'];
} else {
    $uploadDir = UPLOAD_DIR . 'stores/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    // Uzantıyı kullanıcı dosya adından değil MIME type'tan al
    $ext = match($mimeType) {
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/png'               => 'png',
        'image/webp'              => 'webp',
        default                   => 'jpg',
    };
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($tmpPath, $uploadDir . $filename)) jsonError('Failed to save file');
    $imageUrl = UPLOAD_URL . 'stores/' . $filename;
}

// Dinamik kolon adı injection riskini önlemek için CASE kullan
$db->prepare("UPDATE stores SET
    logo_url  = CASE WHEN ? = 'logo'  THEN ? ELSE logo_url  END,
    cover_url = CASE WHEN ? = 'cover' THEN ? ELSE cover_url END,
    updated_at = NOW()
    WHERE id = ?")->execute([$type, $imageUrl, $type, $imageUrl, $storeId]);

jsonSuccess(['url' => $imageUrl, 'type' => $type, 'message' => ucfirst($type) . ' updated successfully']);
