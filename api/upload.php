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
function uploadToCloudinary(string $tmpFile, string $folder = 'sombazar'): ?string {
    $cloudName = getenv('CLOUDINARY_CLOUD_NAME');
    $apiKey    = getenv('CLOUDINARY_API_KEY');
    $apiSecret = getenv('CLOUDINARY_API_SECRET');

    if (!$cloudName || !$apiKey || !$apiSecret) return null;

    $timestamp = time();
    $params    = "folder=$folder&timestamp=$timestamp";
    $signature = sha1($params . $apiSecret);

    $ch = curl_init("https://api.cloudinary.com/v1_1/$cloudName/image/upload");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'file'      => new CURLFile($tmpFile),
            'folder'    => $folder,
            'timestamp' => $timestamp,
            'api_key'   => $apiKey,
            'signature' => $signature,
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['secure_url'] ?? null;
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
    $photoURL = uploadToCloudinary($tmpName, 'sombazar/avatars');

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
    $db->prepare('UPDATE users SET avatar_url = ? WHERE id = ?')->execute([$dbPath, $uid]);
    jsonSuccess(['photoURL' => $photoURL]);
}

jsonError('No file uploaded');
