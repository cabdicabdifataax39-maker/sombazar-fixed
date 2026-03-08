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

// Auto-migrate: gerekli kolonları ekle
$_db = getDB();
try { $_db->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS featured TINYINT(1) DEFAULT 0"); } catch(\Throwable $e) {}
try { $_db->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS boosted_until DATETIME NULL"); } catch(\Throwable $e) {}
try { $_db->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS views INT DEFAULT 0"); } catch(\Throwable $e) {}
try { $_db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS plan VARCHAR(20) NOT NULL DEFAULT 'free'"); } catch(\Throwable $e) {}
try { $_db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS plan_expires_at DATETIME NULL"); } catch(\Throwable $e) {}
unset($_db);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;

// boosted_until kolonu yoksa ekle
try {
    getDB()->exec("ALTER TABLE listings ADD COLUMN boosted_until DATETIME NULL");
} catch(\Throwable $e) {}

switch ($action) {
    case 'list':         handleList();        break;
    case 'get':          if (!$id) jsonError('ID required'); handleGet($id); break;
    case 'create':       if ($method !== 'POST') jsonError('Method not allowed', 405); handleCreate(); break;
    case 'update':       if (!in_array($method, ['POST','PUT'])) jsonError('Method not allowed', 405); if (!$id) jsonError('ID required'); handleUpdate($id); break;
    case 'delete':       if (!in_array($method, ['POST','DELETE'])) jsonError('Method not allowed', 405); if (!$id) jsonError('ID required'); handleDelete($id); break;
    case 'mark_sold':    if ($method !== 'POST') jsonError('Method not allowed', 405); if (!$id) jsonError('ID required'); handleMarkSold($id); break;
    case 'mark_active':  if ($method !== 'POST') jsonError('Method not allowed', 405); if (!$id) jsonError('ID required'); handleMarkActive($id); break;
    case 'boost':        if ($method !== 'POST') jsonError('Method not allowed', 405); handleBoost(); break;
    case 'report':       if ($method !== 'POST') jsonError('Method not allowed', 405); handleReport(); break;
    case 'upload_images':if ($method !== 'POST') jsonError('Method not allowed', 405); handleUploadImages(); break;
    case 'user_listings':handleUserListings(); break;
    default:             jsonError('Unknown action', 404);
}

function handleList(): void {
    $db  = getDB();
    $sql = 'SELECT l.*, u.display_name AS seller_name, u.avatar_url AS seller_photo, u.is_verified AS seller_verified, u.city AS seller_city, u.created_at AS seller_since
            FROM listings l
            JOIN users u ON l.user_id = u.id
            WHERE l.status = "active"';
    $params = [];

    if (!empty($_GET['category'])) {
        $sql .= ' AND l.category = ?';
        $params[] = $_GET['category'];
    }
    if (!empty($_GET['listing_type'])) {
        $sql .= ' AND l.listing_type = ?';
        $params[] = $_GET['listing_type'];
    }
    if (!empty($_GET['city']) && $_GET['city'] !== 'All') {
        $sql .= ' AND l.city = ?';
        $params[] = $_GET['city'];
    }
    if (!empty($_GET['featured'])) {
        $sql .= ' AND l.featured = 1';
    }
    if (!empty($_GET['user_id'])) {
        $sql .= ' AND l.user_id = ?';
        $params[] = (int) $_GET['user_id'];
    }
    if (!empty($_GET['q'])) {
        $q = trim($_GET['q']);
        // Try FULLTEXT first, fall back to LIKE
        static $hasFullText = null;
        if ($hasFullText === null) {
            try {
                $db->query("SELECT 1 FROM listings WHERE MATCH(title,description) AGAINST ('test' IN BOOLEAN MODE) LIMIT 0");
                $hasFullText = true;
            } catch(\Exception $e) { $hasFullText = false; }
        }
        if ($hasFullText) {
            $ftQuery = implode(' ', array_map(
                fn($w) => '+' . preg_replace('/[^\w\x{0600}-\x{06FF}]/u', '', $w) . '*',
                array_filter(explode(' ', $q))
            ));
            if ($ftQuery) {
                $sql     .= ' AND MATCH(l.title, l.description) AGAINST (? IN BOOLEAN MODE)';
                $params[] = $ftQuery;
            }
        } else {
            $sql     .= ' AND (l.title LIKE ? OR l.description LIKE ? OR l.city LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
    }
    if (!empty($_GET['condition']) && $_GET['condition'] !== 'All') {
        $sql .= ' AND l.condition_status = ?';
        $params[] = $_GET['condition'];
    }
    if (!empty($_GET['price_min'])) {
        $sql .= ' AND l.price >= ?';
        $params[] = (float) $_GET['price_min'];
    }
    if (!empty($_GET['price_max'])) {
        $sql .= ' AND l.price <= ?';
        $params[] = (float) $_GET['price_max'];
    }

    $sortMap = ['price' => 'l.price ASC', 'views' => 'l.views DESC', 'createdAt' => 'l.created_at DESC'];
    $sort     = $sortMap[$_GET['sortBy'] ?? 'createdAt'] ?? 'l.created_at DESC';
    $sql     .= " ORDER BY l.featured DESC, $sort";

    $limit    = min((int) ($_GET['pageSize'] ?? 20), 100);
    $offset   = (int) ($_GET['offset'] ?? 0);
    $sql     .= ' LIMIT ? OFFSET ?';
    $params[] = $limit;
    $params[] = $offset;

    $st = $db->prepare($sql);
    $st->execute($params);

    jsonSuccess(array_map('formatListing', $st->fetchAll()));
}

function handleGet(int $id): void {
    $db = getDB();
    $st = $db->prepare(
        'SELECT l.*, u.display_name AS seller_name, u.avatar_url AS seller_photo,
                u.is_verified AS seller_verified, u.city AS seller_city, u.created_at AS seller_since
         FROM listings l
         JOIN users u ON l.user_id = u.id
         WHERE l.id = ? AND l.status != "deleted"'
    );
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) jsonError('Listing not found', 404);

    $db->prepare('UPDATE listings SET views = views + 1 WHERE id = ?')->execute([$id]);
    $row['views']++;

    jsonSuccess(formatListing($row, true));
}

function handleCreate(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);

    foreach (['category', 'title', 'price'] as $f) {
        if (empty($data[$f])) jsonError("Field '$f' is required");
    }

    $db = getDB();

    // ── Rate limit: 1 saatte max 10 yeni ilan ─────────────────────────────
    $rlSt = $db->prepare("SELECT COUNT(*) FROM listings WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $rlSt->execute([$uid]);
    if ((int)$rlSt->fetchColumn() >= 10) {
        jsonError('Rate limit: You can post at most 10 listings per hour. Please wait a while.', 429);
    }

    $listingType  = in_array($data['listing_type'] ?? '', ['sale', 'rent']) ? $data['listing_type'] : 'sale';
    $rentalPeriod = null;
    if ($listingType === 'rent') {
        $rentalPeriod = in_array($data['rental_period'] ?? '', ['daily', 'monthly', 'yearly'])
            ? $data['rental_period'] : 'monthly';
    }
    $st = $db->prepare(
        'INSERT INTO listings (user_id, category, listing_type, rental_period, title, description,
                               price, currency, negotiable, city, condition_status, phone, images, specs, year, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $st->execute([
        $uid,
        $data['category'],
        $listingType,
        $rentalPeriod,
        trim($data['title']),
        trim($data['description'] ?? ''),
        (float) $data['price'],
        $data['currency'] ?? 'USD',
        !empty($data['negotiable']) ? 1 : 0,
        $data['city'] ?? 'Hargeisa',
        $data['condition'] ?? 'Good',
        $data['phone'] ?? null,
        json_encode($data['images'] ?? []),
        json_encode($data['specs'] ?? []),
        isset($data['year']) && $data['year'] ? (int)$data['year'] : null,
        'active',
    ]);

    handleGet((int) $db->lastInsertId());
}

function handleUpdate(int $id): void {
    $uid = requireAuth();
    $db  = getDB();

    $st = $db->prepare('SELECT user_id FROM listings WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) jsonError('Listing not found', 404);
    if ((int) $row['user_id'] !== $uid) jsonError('Forbidden', 403);

    $data   = json_decode(file_get_contents('php://input'), true);
    $fields = [];
    $params = [];

    $map = [
        'title'         => 'title',
        'description'   => 'description',
        'price'         => 'price',
        'currency'      => 'currency',
        'negotiable'    => 'negotiable',
        'city'          => 'city',
        'condition'     => 'condition_status',
        'phone'         => 'phone',
        'images'        => 'images',
        'specs'         => 'specs',
        'year'          => 'year',
        'featured'      => 'featured',
        'status'        => 'status',
        'listing_type'  => 'listing_type',
        'rental_period' => 'rental_period',
        'category'      => 'category',
    ];

    foreach ($map as $key => $col) {
        if (array_key_exists($key, $data)) {
            $val = $data[$key];
            if (in_array($col, ['images', 'specs']))        $val = is_array($val) ? json_encode($val) : $val;
            elseif (in_array($col, ['negotiable','featured'])) $val = ($val === true || $val === 1 || $val === '1' || $val === 'true') ? 1 : 0;
            elseif ($col === 'price')                        $val = (float) $val;
            elseif ($col === 'year')                         $val = ($val !== null && $val !== '') ? (int)$val : null;
            $fields[] = "$col = ?";
            $params[]  = $val;
        }
    }

    if (empty($fields)) jsonError('No fields to update');

    $params[] = $id;
    $db->prepare('UPDATE listings SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    handleGet($id);
}

function handleDelete(int $id): void {
    $uid = requireAuth();
    $db  = getDB();

    $st = $db->prepare('SELECT user_id FROM listings WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) jsonError('Listing not found', 404);
    if ((int) $row['user_id'] !== $uid) jsonError('Forbidden', 403);

    $db->prepare('UPDATE listings SET status = "deleted" WHERE id = ?')->execute([$id]);
    jsonSuccess(['deleted' => true]);
}

function handleMarkSold(int $id): void {
    $uid  = requireAuth();
    $db   = getDB();
    $data = json_decode(file_get_contents('php://input'), true);
    $status = ($data['status'] ?? 'sold') === 'rented' ? 'rented' : 'sold';

    $st = $db->prepare('SELECT user_id FROM listings WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) jsonError('Listing not found', 404);
    if ((int) $row['user_id'] !== $uid) jsonError('Forbidden', 403);

    $db->prepare('UPDATE listings SET status = ? WHERE id = ?')->execute([$status, $id]);
    jsonSuccess(['id' => $id, 'status' => $status]);
}

function handleMarkActive(int $id): void {
    $uid = requireAuth();
    $db  = getDB();

    $st = $db->prepare('SELECT user_id FROM listings WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) jsonError('Listing not found', 404);
    if ((int) $row['user_id'] !== $uid) jsonError('Forbidden', 403);

    $db->prepare('UPDATE listings SET status = "active" WHERE id = ?')->execute([$id]);
    jsonSuccess(['id' => $id, 'status' => 'active']);
}


// ── Cloudinary Upload ────────────────────────────────────────────────────
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

function handleUploadImages(): void {
    $uid    = requireAuth();
    $listId = (int) ($_GET['listing_id'] ?? 0);

    if (!isset($_FILES['images'])) jsonError('No images uploaded');

    $uploadDir = UPLOAD_DIR . 'listings/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $urls     = [];
    $rawFiles = $_FILES['images'];

    // Normalize single and multiple file uploads into the same format
    if (!is_array($rawFiles['name'])) {
        $files = [[
            'name'     => $rawFiles['name'],
            'tmp_name' => $rawFiles['tmp_name'],
            'size'     => $rawFiles['size'],
            'error'    => $rawFiles['error'],
        ]];
    } else {
        $files = [];
        $count = count($rawFiles['name']);
        for ($i = 0; $i < $count; $i++) {
            $files[] = [
                'name'     => $rawFiles['name'][$i],
                'tmp_name' => $rawFiles['tmp_name'][$i],
                'size'     => $rawFiles['size'][$i],
                'error'    => $rawFiles['error'][$i],
            ];
        }
    }

    foreach ($files as $file) {
        if ($file['error'] !== 0) continue;
        if ($file['size'] > MAX_FILE_SIZE) continue;

        $info = @getimagesize($file['tmp_name']);
        if (!$info) continue;

        // 0.3 Path Traversal: Kullanıcı dosya adı HİÇ kullanılmaz
        // GD ile yeniden oluşturulup .webp olarak kaydedilir (EXIF de temizlenir)
        $mime = @mime_content_type($file['tmp_name']);
        $allowedMime = ['image/jpeg','image/png','image/webp','image/gif'];
        if (!in_array($mime, $allowedMime)) continue;

        // UUID dosya adı - Path Traversal önlemi
        // GD ile WebP'ye çevir; GD yoksa orijinal formatı koru
        $ext = match($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            default      => 'jpg',
        };

        $gdOk = false;
        if (function_exists('imagewebp')) {
            $src = null;
            if ($mime === 'image/jpeg') $src = @imagecreatefromjpeg($file['tmp_name']);
            elseif ($mime === 'image/png')  $src = @imagecreatefrompng($file['tmp_name']);
            elseif ($mime === 'image/webp') $src = @imagecreatefromwebp($file['tmp_name']);
            elseif ($mime === 'image/gif')  $src = @imagecreatefromgif($file['tmp_name']);

            if ($src) {
                $filename = bin2hex(random_bytes(16)) . '.webp';
                $gdOk = imagewebp($src, $uploadDir . $filename, 82);
                imagedestroy($src);
            }
        }

        if (!$gdOk) {
            // GD yoksa ya da başarısız — orijinal dosyayı UUID ismiyle kaydet
            $filename = bin2hex(random_bytes(16)) . '.' . $ext;
            $gdOk = move_uploaded_file($file['tmp_name'], $uploadDir . $filename);
        }

        if ($gdOk) {
            // Cloudinary'ye yükle
            $cloudUrl = uploadToCloudinary($uploadDir . $filename, 'sombazar/listings');
            if ($cloudUrl) {
                @unlink($uploadDir . $filename); // local dosyayı sil
                $urls[] = $cloudUrl;
            } else {
                $urls[] = UPLOAD_URL . 'listings/' . $filename;
            }
        }
    }

    if ($listId) {
        $db = getDB();
        $st = $db->prepare('SELECT images FROM listings WHERE id = ?');
        $st->execute([$listId]);
        $row = $st->fetch();
        if ($row) {
            $existing = json_decode($row['images'] ?? '[]', true) ?: [];
            $db->prepare('UPDATE listings SET images = ? WHERE id = ?')
               ->execute([json_encode(array_merge($existing, $urls)), $listId]);
        }
    }

    jsonSuccess(['urls' => $urls]);
}

function handleReport(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $lid  = (int)($data['listing_id'] ?? 0);
    $reason = trim($data['reason'] ?? '');
    if (!$lid)   jsonError('listing_id required');
    if (!$reason) jsonError('reason required');

    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            listing_id INT NOT NULL,
            reporter_id INT NOT NULL,
            reason VARCHAR(300),
            resolved TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
    } catch(\Exception $e) {}

    // Prevent duplicate reports from same user
    $chk = $db->prepare('SELECT id FROM reports WHERE listing_id=? AND reporter_id=?');
    $chk->execute([$lid, $uid]);
    if ($chk->fetch()) jsonError('You have already reported this listing');

    $db->prepare('INSERT INTO reports (listing_id, reporter_id, reason) VALUES (?,?,?)')
       ->execute([$lid, $uid, $reason]);

    jsonSuccess(['message' => 'Report submitted. Thank you!']);
}

function handleUserListings(): void {
    // Return all of the authenticated user's listings except deleted ones
    $uid = requireAuth();
    $db  = getDB();

    $st = $db->prepare(
        'SELECT l.*, u.display_name AS seller_name, u.avatar_url AS seller_photo, u.is_verified AS seller_verified
         FROM listings l
         JOIN users u ON l.user_id = u.id
         WHERE l.user_id = ? AND l.status != "deleted"
         ORDER BY l.created_at DESC
         LIMIT 100'
    );
    $st->execute([$uid]);

    jsonSuccess(array_map('formatListing', $st->fetchAll()));
}

function formatListing(array $r, bool $detail = false): array {
    $images = json_decode($r['images'] ?? '[]', true) ?: [];
    $esc    = fn($v) => $v !== null ? htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : null;

    $out = [
        'id'           => (int)$r['id'],
        'userId'       => (int)$r['user_id'],
        'category'     => $esc($r['category']),
        'listingType'  => $r['listing_type'] ?? 'sale',
        'rentalPeriod' => $r['rental_period'] ?? null,
        'title'        => $esc($r['title']),
        'price'        => (float) $r['price'],
        'currency'     => $esc($r['currency']),
        'negotiable'   => (bool) $r['negotiable'],
        'city'         => $esc($r['city']),
        'condition'    => $esc($r['condition_status']),
        'phone'        => $r['phone'],   // maskeleniyor frontend'de
        'featured'     => (bool) $r['featured'],
        'views'        => (int) $r['views'],
        'images'       => array_map('strval', $images),
        'status'       => $r['status'],
        'createdAt'    => $r['created_at'],
        'year'         => $r['year'] ? (int)$r['year'] : null,
        'boostedUntil' => $r['boosted_until'] ?? null,
    ];

    if ($detail) {
        $out['description'] = $esc($r['description']);
        $out['specs']       = json_decode($r['specs'] ?? '{}', true) ?: [];
        $out['seller']      = [
            'id'          => (int)$r['user_id'],
            'displayName' => $esc($r['seller_name'] ?? null),
            'photoURL'    => (function($p) { if (!$p) return null; return str_starts_with($p, 'http') ? $p : UPLOAD_URL . $p; })($r['avatar_url'] ?? $r['seller_photo'] ?? null),
            'verified'    => (bool) ($r['seller_verified'] ?? false),
            'city'        => $esc($r['seller_city'] ?? null),
            'memberSince' => isset($r['seller_since']) ? date('Y', strtotime($r['seller_since'])) : null,
        ];
    }

    return $out;
}

function handleBoost(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $lid  = (int)($data['listing_id'] ?? 0);
    if (!$lid) jsonError('Missing listing_id');

    $db = getDB();

    // İlan sahibi mi kontrol et
    $st = $db->prepare('SELECT user_id FROM listings WHERE id=? AND status="active"');
    $st->execute([$lid]);
    $listing = $st->fetch();
    if (!$listing) jsonError('Listing not found', 404);
    if ((int)$listing['user_id'] !== $uid) jsonError('Not your listing', 403);

    // Boost kredisi var mı?
    $pkg = $db->prepare('SELECT boost_credits FROM packages WHERE user_id=?');
    $pkg->execute([$uid]);
    $pkgRow = $pkg->fetch();
    $credits = (int)($pkgRow['boost_credits'] ?? 0);
    if ($credits <= 0) jsonError('No boost credits remaining. Upgrade your plan.');

    // Boost uygula - 7 gün
    $until = date('Y-m-d H:i:s', strtotime('+7 days'));
    try {
        $db->exec("ALTER TABLE listings ADD COLUMN boosted_until DATETIME NULL");
    } catch(\Throwable $e) {}
    $db->prepare('UPDATE listings SET boosted_until=? WHERE id=?')->execute([$until, $lid]);

    // Kredi düş
    $db->prepare('UPDATE packages SET boost_credits = boost_credits - 1 WHERE user_id=?')->execute([$uid]);

    jsonSuccess(['message' => 'Listing boosted for 7 days!', 'boosted_until' => $until]);
}
