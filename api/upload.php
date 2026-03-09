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

// ── Cloudinary'ye yükle ──────────────────────────────────────────────────
function uploadToCloudinary(string $tmpFile, string $folder = 'sombazar', string $context = 'listing'): ?string {
    $cloudName = getenv('CLOUDINARY_CLOUD_NAME');
    $apiKey    = getenv('CLOUDINARY_API_KEY');
    $apiSecret = getenv('CLOUDINARY_API_SECRET');

    if (!$cloudName || !$apiKey || !$apiSecret) return null;

    $timestamp = time();

    // Context'e göre transformation: listing vs avatar
    if ($context === 'avatar') {
        // Avatar: 200x200 kare, yüz odaklı, WebP, kalite 85
        $transformation = 'c_fill,g_face,h_200,w_200,q_85,f_auto';
    } else {
        // Listing: max 1200px genişlik, WebP, kalite 82, şeridi sil
        $transformation = 'c_limit,w_1200,h_900,q_82,f_auto,fl_strip_profile';
    }

    // İmza: transformation dahil edilmeli
    $paramStr  = "folder=$folder&timestamp=$timestamp&transformation=$transformation";
    $signature = sha1($paramStr . $apiSecret);

    $ch = curl_init("https://api.cloudinary.com/v1_1/$cloudName/image/upload");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'file'           => new CURLFile($tmpFile),
            'folder'         => $folder,
            'timestamp'      => $timestamp,
            'api_key'        => $apiKey,
            'signature'      => $signature,
            'transformation' => $transformation,
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (!isset($data['secure_url'])) return null;

    // f_auto ile Cloudinary otomatik WebP döner - URL'e dönüşüm ekle
    // secure_url: .../upload/v123/sombazar/file.jpg
    // → .../upload/c_limit,w_1200,q_82,f_auto/v123/sombazar/file.jpg
    return $data['secure_url'];
}

// ── Avatar yükleme ───────────────────────────────────────────────────────
if (isset($_FILES['avatar'])) {
    $file    = $_FILES['avatar'];
    $tmpName = $file['tmp_name'];
    $size    = $file['size'];

    if ($file['error'] !== UPLOAD_ERR_OK) jsonError('Upload error');
    if ($size > MAX_FILE_SIZE) jsonError('File too large (max 5MB)');

    $info = getimagesize($tmpName);
    if (!$info) jsonError('Invalid image');

    $mime = @mime_content_type($tmpName);
    $allowedMime = ['image/jpeg','image/png','image/webp','image/gif'];
    if (!in_array($mime, $allowedMime)) jsonError('Invalid file type');

    // Cloudinary'ye yükle
    $photoURL = uploadToCloudinary($tmpName, 'sombazar/avatars', 'avatar');

    // Cloudinary yoksa local'e yükle
    if (!$photoURL) {
        $uploadDir = UPLOAD_DIR . 'avatars/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $ext = match($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            default      => 'jpg',
        };
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($tmpName, $uploadDir . $filename)) jsonError('Failed to save image');
        $photoURL = UPLOAD_URL . 'avatars/' . $filename;
        $dbPath   = 'avatars/' . $filename;
    } else {
        $dbPath = $photoURL;
    }

    $db = getDB();
    $db->prepare('UPDATE users SET avatar_url = ?, photo_url = ? WHERE id = ?')->execute([$dbPath, $dbPath, $uid]);
    jsonSuccess(['photoURL' => $photoURL]);
}

jsonError('No file uploaded');
