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

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'ping': jsonSuccess(['pong' => true, 'time' => date('H:i:s')]); break;
    case 'db_test':
        try {
            $r = getDB()->query('SELECT COUNT(*) FROM stores')->fetchColumn();
            jsonSuccess(['stores_count' => $r]);
        } catch (\Throwable $e) {
            jsonError('DB error: ' . $e->getMessage());
        }
        break;
    case 'create':         if ($method !== 'POST') jsonError('Method not allowed', 405); handleCreate();        break;
    case 'update':         if ($method !== 'POST') jsonError('Method not allowed', 405); handleUpdate();        break;
    case 'get':            handleGet();           break;
    case 'my_store':       handleMyStore();       break;
    case 'listings':       handleListings();      break;
    case 'follow':         if ($method !== 'POST') jsonError('Method not allowed', 405); handleFollow();        break;
    case 'unfollow':       if ($method !== 'POST') jsonError('Method not allowed', 405); handleUnfollow();      break;
    case 'open_status':    handleOpenStatus();    break;
    case 'analytics':      handleAnalytics();     break;
    case 'working_hours':  handleWorkingHours();  break;
    case 'all':            handleAll();           break;
    case 'verify_request': if ($method !== 'POST') jsonError('Method not allowed', 405); handleVerifyRequest(); break;
    case 'verify_respond': if ($method !== 'POST') jsonError('Method not allowed', 405); handleVerifyRespond(); break;
    default: jsonError('Unknown action: ' . $action);
}

// ── Slug uretici ─────────────────────────────────────────────────────────
function generateSlug(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $map  = ['ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ı'=>'i','ç'=>'c',
              'Ş'=>'s','Ğ'=>'g','Ü'=>'u','Ö'=>'o','İ'=>'i','Ç'=>'c'];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', trim($text));
    return trim($text, '-');
}

function uniqueSlug(string $base, int $excludeId = 0): string {
    $db   = getDB();
    $slug = generateSlug($base);
    $orig = $slug;
    $i    = 2;
    while (true) {
        $st = $db->prepare("SELECT id FROM stores WHERE slug = ? AND id != ?");
        $st->execute([$slug, $excludeId]);
        if (!$st->fetch()) break;
        $slug = $orig . '-' . $i++;
    }
    return $slug;
}

// ── Dukkan olustur ───────────────────────────────────────────────────────
function handleCreate(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $name = trim($data['store_name'] ?? '');
    $type = trim($data['store_type'] ?? 'other');
    $city = trim($data['city']       ?? 'Hargeisa');
    $desc = trim($data['description']?? '');
    $phone= trim($data['phone']      ?? '');

    if (!$name) jsonError('store_name required');

    $validTypes = ['electronics','auto','real_estate','fashion','food','services','other'];
    if (!in_array($type, $validTypes)) $type = 'other';

    $db = getDB();

    // Kullanicinin zaten dukkan var mi?
    $st = $db->prepare("SELECT id, slug FROM stores WHERE owner_id = ?");
    $st->execute([$uid]);
    $existing = $st->fetch();
    if ($existing) {
        jsonSuccess(['store_id' => $existing['id'], 'slug' => $existing['slug'], 'already_exists' => true]);
    }

    $slug = uniqueSlug($name);

    try {
        $db->beginTransaction();

        $ins = $db->prepare("INSERT INTO stores 
            (owner_id, store_name, slug, store_type, city, description, phone, status, plan, verification_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 'free', 'unverified')");
        $ins->execute([$uid, $name, $slug, $type, $city, $desc ?: null, $phone ?: null]);
        $storeId = (int)$db->lastInsertId();

        // users tablosuna store_id yaz
        try {
            $db->prepare("UPDATE users SET store_id = ? WHERE id = ?")->execute([$storeId, $uid]);
        } catch (\Throwable $e) {}

        $db->commit();

        jsonSuccess(['store_id' => $storeId, 'slug' => $slug, 'message' => 'Store created successfully'], 201);
    } catch (\Throwable $e) {
        $db->rollBack();
        jsonError('Failed to create store: ' . $e->getMessage());
    }
}

// ── Dukkan guncelle ──────────────────────────────────────────────────────
function handleUpdate(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $db = getDB();
    $st = $db->prepare("SELECT id, slug FROM stores WHERE owner_id = ?");
    $st->execute([$uid]);
    $store = $st->fetch();
    if (!$store) jsonError('Store not found', 404);

    $fields = [];
    $params = [];

    $allowed = ['store_name','store_type','city','district','description',
                'phone','whatsapp','address_text','latitude','longitude'];

    foreach ($allowed as $f) {
        if (isset($data[$f])) {
            $fields[] = "$f = ?";
            $params[] = trim((string)$data[$f]) ?: null;
        }
    }

    // Slug guncelle (isim degistiyse)
    if (isset($data['store_name']) && $data['store_name']) {
        $newSlug = uniqueSlug($data['store_name'], (int)$store['id']);
        $fields[] = "slug = ?";
        $params[] = $newSlug;
    }

    if (empty($fields)) jsonError('No fields to update');

    $params[] = $uid;
    $db->prepare("UPDATE stores SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE owner_id = ?")
       ->execute($params);

    jsonSuccess(['message' => 'Store updated']);
}

// ── Dukkan getir (slug veya id ile) ─────────────────────────────────────
function handleGet(): void {
    $db     = getDB();
    $slug   = trim($_GET['slug']   ?? '');
    $id     = (int)($_GET['id']    ?? 0);
    $uid    = getAuthUser();

    if (!$slug && !$id) jsonError('slug or id required');

    $where = $slug ? "s.slug = ?" : "s.id = ?";
    $param = $slug ?: $id;

    $st = $db->prepare("
        SELECT s.*,
               u.display_name AS owner_name,
               u.avatar_url   AS owner_avatar
        FROM stores s
        JOIN users u ON s.owner_id = u.id
        WHERE $where AND s.status = 'active'
    ");
    $st->execute([$param]);
    $store = $st->fetch();
    if (!$store) jsonError('Store not found', 404);

    // Takip ediyor mu?
    $isFollowing = false;
    if ($uid) {
        $fst = $db->prepare("SELECT 1 FROM store_followers WHERE store_id = ? AND user_id = ?");
        $fst->execute([$store['id'], $uid]);
        $isFollowing = (bool)$fst->fetch();
    }

    // Aktif ilan sayisi
    $cst = $db->prepare("SELECT COUNT(*) FROM listings WHERE user_id = ? AND status = 'active'");
    $cst->execute([$store['owner_id']]);
    $listingCount = (int)$cst->fetchColumn();

    $store['is_following']   = $isFollowing;
    $store['listing_count']  = $listingCount;
    $store['working_hours']  = $store['working_hours'] ? json_decode($store['working_hours'], true) : null;
    $store['open_status']    = getOpenStatus($store);

    jsonSuccess($store);
}

// ── Kendi dukkanim ───────────────────────────────────────────────────────
function handleMyStore(): void {
    $uid = requireAuth();
    $db  = getDB();

    $st = $db->prepare("SELECT * FROM stores WHERE owner_id = ?");
    $st->execute([$uid]);
    $store = $st->fetch();
    if (!$store) jsonError('No store found', 404);

    $store['working_hours'] = $store['working_hours'] ? json_decode($store['working_hours'], true) : null;
    $store['open_status']   = getOpenStatus($store);

    jsonSuccess($store);
}

// ── Dukkan ilanlari ──────────────────────────────────────────────────────
function handleListings(): void {
    $storeId  = (int)($_GET['store_id'] ?? 0);
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = min(20, (int)($_GET['per_page'] ?? 12));
    $offset   = ($page - 1) * $perPage;

    if (!$storeId) jsonError('store_id required');

    $db = getDB();
    $st = $db->prepare("SELECT owner_id FROM stores WHERE id = ?");
    $st->execute([$storeId]);
    $store = $st->fetch();
    if (!$store) jsonError('Store not found', 404);

    $uid    = getAuthUser();
    $isOwner= $uid && $uid === (int)$store['owner_id'];
    $status = $isOwner ? ($_GET['status'] ?? 'active') : 'active';

    $validStatus = ['active','pending','sold','expired','deleted'];
    if (!in_array($status, $validStatus)) $status = 'active';

    $countSt = $db->prepare("SELECT COUNT(*) FROM listings WHERE user_id = ? AND status = ?");
    $countSt->execute([$store['owner_id'], $status]);
    $total = (int)$countSt->fetchColumn();

    $st = $db->prepare("SELECT * FROM listings WHERE user_id = ? AND status = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $st->execute([$store['owner_id'], $status, $perPage, $offset]);
    $listings = $st->fetchAll();

    // Gorselleri parse et
    foreach ($listings as &$l) {
        $l['images'] = $l['images'] ? json_decode($l['images'], true) : [];
    }

    header('X-Total-Count: ' . $total);
    jsonSuccess([
        'listings'   => $listings,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'total_pages'=> ceil($total / $perPage),
    ]);
}

// ── Takip et / birak ─────────────────────────────────────────────────────
function handleFollow(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid  = (int)($data['store_id'] ?? $_GET['store_id'] ?? 0);
    if (!$sid) jsonError('store_id required');

    $db = getDB();
    try {
        $db->prepare("INSERT IGNORE INTO store_followers (store_id, user_id) VALUES (?, ?)")
           ->execute([$sid, $uid]);
        $db->prepare("UPDATE stores SET follower_count = (SELECT COUNT(*) FROM store_followers WHERE store_id = ?) WHERE id = ?")
           ->execute([$sid, $sid]);
        jsonSuccess(['following' => true]);
    } catch (\Throwable $e) {
        jsonError('Error: ' . $e->getMessage());
    }
}

function handleUnfollow(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid  = (int)($data['store_id'] ?? $_GET['store_id'] ?? 0);
    if (!$sid) jsonError('store_id required');

    $db = getDB();
    $db->prepare("DELETE FROM store_followers WHERE store_id = ? AND user_id = ?")
       ->execute([$sid, $uid]);
    $db->prepare("UPDATE stores SET follower_count = (SELECT COUNT(*) FROM store_followers WHERE store_id = ?) WHERE id = ?")
       ->execute([$sid, $sid]);
    jsonSuccess(['following' => false]);
}

// ── Acik/kapali durumu ────────────────────────────────────────────────────
function handleOpenStatus(): void {
    $sid = (int)($_GET['store_id'] ?? 0);
    if (!$sid) jsonError('store_id required');

    $db = getDB();
    $st = $db->prepare("SELECT working_hours FROM stores WHERE id = ?");
    $st->execute([$sid]);
    $store = $st->fetch();
    if (!$store) jsonError('Not found', 404);

    $store['working_hours'] = $store['working_hours'] ? json_decode($store['working_hours'], true) : null;
    jsonSuccess(getOpenStatus($store));
}

function getOpenStatus(array $store): array {
    $hours = $store['working_hours'];
    if (!$hours) return ['is_open' => null, 'label' => 'Calisma saati belirtilmemis'];

    try {
        $tz  = new \DateTimeZone('Africa/Nairobi'); // UTC+3, Somaliland
        $now = new \DateTime('now', $tz);
        $day = strtolower($now->format('D')); // mon, tue...
        $dayMap = ['mon'=>'mon','tue'=>'tue','wed'=>'wed','thu'=>'thu',
                   'fri'=>'fri','sat'=>'sat','sun'=>'sun'];
        $key = $dayMap[$day] ?? $day;

        $todayHours = $hours[$key] ?? null;
        if (!$todayHours || ($todayHours['closed'] ?? false)) {
            return ['is_open' => false, 'label' => 'Bugun kapali'];
        }

        $open  = $todayHours['open']  ?? '09:00';
        $close = $todayHours['close'] ?? '18:00';
        $current = $now->format('H:i');

        $isOpen = $current >= $open && $current <= $close;

        if ($isOpen) {
            // Kapanmaya 1 saat kaliyor mu?
            $closeTime = new \DateTime($close, $tz);
            $diff = ($closeTime->getTimestamp() - $now->getTimestamp()) / 60;
            $label = $diff <= 60
                ? "Kapanmak uzere — {$close}'e kadar"
                : "Acik — {$close}'e kadar";
        } else {
            $label = $current < $open
                ? "{$open}'de aciliyor"
                : 'Bugun kapandi';
        }

        return ['is_open' => $isOpen, 'label' => $label, 'open' => $open, 'close' => $close];
    } catch (\Throwable $e) {
        return ['is_open' => null, 'label' => 'Hata'];
    }
}

// ── Calisma saatleri ─────────────────────────────────────────────────────
function handleWorkingHours(): void {
    $uid    = requireAuth();
    $method = $_SERVER['REQUEST_METHOD'];
    $db     = getDB();

    $st = $db->prepare("SELECT id, working_hours FROM stores WHERE owner_id = ?");
    $st->execute([$uid]);
    $store = $st->fetch();
    if (!$store) jsonError('Store not found', 404);

    if ($method === 'GET') {
        $wh = $store['working_hours'] ? json_decode($store['working_hours'], true) : null;
        jsonSuccess(['working_hours' => $wh]);
    }

    // POST: guncelle
    $data  = json_decode(file_get_contents('php://input'), true) ?? [];
    $hours = $data['hours'] ?? null;
    if (!$hours || !is_array($hours)) jsonError('hours object required');

    $days = ['mon','tue','wed','thu','fri','sat','sun'];
    $clean = [];
    foreach ($days as $d) {
        if (!isset($hours[$d])) continue;
        $h = $hours[$d];
        if (isset($h['closed']) && $h['closed']) {
            $clean[$d] = ['closed' => true];
        } else {
            $clean[$d] = [
                'open'   => $h['open']  ?? '09:00',
                'close'  => $h['close'] ?? '18:00',
                'closed' => false,
            ];
        }
    }

    $db->prepare("UPDATE stores SET working_hours = ?, updated_at = NOW() WHERE owner_id = ?")
       ->execute([json_encode($clean), $uid]);

    jsonSuccess(['message' => 'Working hours updated', 'working_hours' => $clean]);
}

// ── Dukkan analitikleri ──────────────────────────────────────────────────
function handleAnalytics(): void {
    $uid = requireAuth();
    $db  = getDB();

    $st = $db->prepare("SELECT id, owner_id FROM stores WHERE owner_id = ?");
    $st->execute([$uid]);
    $store = $st->fetch();
    if (!$store) jsonError('Store not found', 404);

    $ownerId = (int)$store['owner_id'];

    // Bu haftaki goruntulenme (listing_views)
    $viewsSt = $db->prepare("
        SELECT COUNT(*) FROM listing_views lv
        JOIN listings l ON lv.listing_id = l.id
        WHERE l.user_id = ? AND lv.viewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $viewsSt->execute([$ownerId]);
    $weeklyViews = (int)$viewsSt->fetchColumn();

    // Bu ayki goruntulenme
    $monthViewsSt = $db->prepare("
        SELECT COUNT(*) FROM listing_views lv
        JOIN listings l ON lv.listing_id = l.id
        WHERE l.user_id = ? AND lv.viewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $monthViewsSt->execute([$ownerId]);
    $monthlyViews = (int)$monthViewsSt->fetchColumn();

    // Aktif ilan sayisi
    $activeSt = $db->prepare("SELECT COUNT(*) FROM listings WHERE user_id = ? AND status = 'active'");
    $activeSt->execute([$ownerId]);
    $activeListings = (int)$activeSt->fetchColumn();

    // Toplam teklif (bu ay)
    $offersSt = $db->prepare("
        SELECT COUNT(*) FROM offers
        WHERE seller_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $offersSt->execute([$ownerId]);
    $monthlyOffers = (int)$offersSt->fetchColumn();

    // En cok gorunen 5 ilan
    $topSt = $db->prepare("
        SELECT l.id, l.title, l.price, l.currency, COUNT(lv.id) AS view_count
        FROM listings l
        LEFT JOIN listing_views lv ON l.id = lv.listing_id
        WHERE l.user_id = ? AND l.status = 'active'
        GROUP BY l.id
        ORDER BY view_count DESC
        LIMIT 5
    ");
    $topSt->execute([$ownerId]);
    $topListings = $topSt->fetchAll();

    jsonSuccess([
        'weekly_views'   => $weeklyViews,
        'monthly_views'  => $monthlyViews,
        'active_listings'=> $activeListings,
        'monthly_offers' => $monthlyOffers,
        'top_listings'   => $topListings,
        'follower_count' => (int)($store['follower_count'] ?? 0),
    ]);
}

// ── Tum dukkanlar ────────────────────────────────────────────────────────
function handleAll(): void {
    $db      = getDB();
    $city    = trim($_GET['city']       ?? '');
    $type    = trim($_GET['store_type'] ?? '');
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(20, (int)($_GET['per_page'] ?? 12));
    $offset  = ($page - 1) * $perPage;

    $where  = ["s.status = 'active'"];
    $params = [];

    if ($city) { $where[] = "s.city = ?"; $params[] = $city; }
    if ($type) { $where[] = "s.store_type = ?"; $params[] = $type; }

    $whereSQL = implode(' AND ', $where);

    $countSt = $db->prepare("SELECT COUNT(*) FROM stores s WHERE $whereSQL");
    $countSt->execute($params);
    $total = (int)$countSt->fetchColumn();

    $st = $db->prepare("
        SELECT s.id, s.store_name, s.slug, s.store_type, s.city,
               s.logo_url, s.cover_url, s.description,
               s.avg_rating, s.review_count, s.follower_count,
               s.verification_status, s.plan, s.working_hours,
               (SELECT COUNT(*) FROM listings l WHERE l.user_id = s.owner_id AND l.status = 'active') AS listing_count
        FROM stores s
        WHERE $whereSQL
        ORDER BY s.verification_status = 'verified' DESC, s.avg_rating DESC, s.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $perPage;
    $params[] = $offset;
    $st->execute($params);
    $stores = $st->fetchAll();

    foreach ($stores as &$s) {
        $s['working_hours'] = $s['working_hours'] ? json_decode($s['working_hours'], true) : null;
        $s['open_status']   = getOpenStatus($s);
    }

    header('X-Total-Count: ' . $total);
    jsonSuccess([
        'stores'      => $stores,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => ceil($total / $perPage),
    ]);
}

// ── Dogrulama talebi ─────────────────────────────────────────────────────
function handleVerifyRequest(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $level= $data['level'] ?? 'identity';

    $validLevels = ['identity','business','sector'];
    if (!in_array($level, $validLevels)) jsonError('Invalid level');

    $db = getDB();
    $st = $db->prepare("SELECT id FROM stores WHERE owner_id = ?");
    $st->execute([$uid]);
    $store = $st->fetch();
    if (!$store) jsonError('Store not found', 404);

    // Zaten bekleyen talep var mi?
    $chk = $db->prepare("SELECT id FROM verification_requests WHERE store_id = ? AND status IN ('pending','under_review')");
    $chk->execute([$store['id']]);
    if ($chk->fetch()) jsonError('You already have a pending verification request');

    $db->prepare("INSERT INTO verification_requests (store_id, level, status) VALUES (?, ?, 'pending')")
       ->execute([$store['id'], $level]);

    // stores tablosuna verification_status guncelle
    $db->prepare("UPDATE stores SET verification_status = 'pending' WHERE id = ?")
       ->execute([$store['id']]);

    jsonSuccess(['message' => 'Verification request submitted. Admin will review within 72 hours.']);
}

// ── Admin: dogrulama yaniti ───────────────────────────────────────────────
function handleVerifyRespond(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    // Admin kontrolu
    $db = getDB();
    $st = $db->prepare("SELECT role FROM users WHERE id = ?");
    $st->execute([$uid]);
    $user = $st->fetch();
    if (!$user || $user['role'] !== 'admin') jsonError('Admin only', 403);

    $reqId  = (int)($data['request_id'] ?? 0);
    $action = $data['action'] ?? '';
    $notes  = $data['admin_notes'] ?? '';
    $reason = $data['rejection_reason'] ?? '';

    if (!$reqId) jsonError('request_id required');
    if (!in_array($action, ['approve','reject','more_info'])) jsonError('Invalid action');

    $reqSt = $db->prepare("SELECT * FROM verification_requests WHERE id = ?");
    $reqSt->execute([$reqId]);
    $req = $reqSt->fetch();
    if (!$req) jsonError('Request not found', 404);

    $statusMap = ['approve' => 'approved', 'reject' => 'rejected', 'more_info' => 'more_info_needed'];
    $newStatus = $statusMap[$action];

    $db->prepare("UPDATE verification_requests SET status = ?, admin_notes = ?, rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
       ->execute([$newStatus, $notes ?: null, $reason ?: null, $uid, $reqId]);

    // Dukkan verification_status guncelle
    $storeStatus = $action === 'approve' ? 'verified' : ($action === 'reject' ? 'rejected' : 'pending');
    $db->prepare("UPDATE stores SET verification_status = ?, verified_by = ?, verified_at = ? WHERE id = ?")
       ->execute([$storeStatus, $action === 'approve' ? $uid : null, $action === 'approve' ? date('Y-m-d H:i:s') : null, $req['store_id']]);

    // Dukkan sahibine bildirim
    try {
        $ownerSt = $db->prepare("SELECT owner_id FROM stores WHERE id = ?");
        $ownerSt->execute([$req['store_id']]);
        $ownerId = (int)$ownerSt->fetchColumn();

        $title = $action === 'approve' ? 'Magazaniz onaylandi! ✅' :
                ($action === 'reject'  ? 'Magazaniz onaylanmadi ❌' : 'Ek bilgi gerekiyor ℹ️');
        $body  = $notes ?: ($reason ?: 'Magazanizin dogrulama durumu guncellendi.');

        $db->prepare("INSERT INTO notifications (user_id, type, title, body, url) VALUES (?, 'store_verification', ?, ?, ?)")
           ->execute([$ownerId, $title, $body, '/profile.html?tab=store']);
    } catch (\Throwable $e) {}

    jsonSuccess(['message' => 'Verification ' . $action . 'd', 'store_status' => $storeStatus]);
}
