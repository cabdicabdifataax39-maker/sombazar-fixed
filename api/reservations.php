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
    case 'create':   if ($method !== 'POST') jsonError('Method not allowed', 405); handleCreate();  break;
    case 'respond':  if ($method !== 'POST') jsonError('Method not allowed', 405); handleRespond(); break;
    case 'cancel':   if ($method !== 'POST') jsonError('Method not allowed', 405); handleCancel();  break;
    case 'list':     handleList();  break;
    case 'get':      handleGet();   break;
    default: jsonError('Unknown action: ' . $action);
}

// ── Rezervasyon olustur ──────────────────────────────────────────────────
function handleCreate(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $listingId    = (int)($data['listing_id']    ?? 0);
    $durationType = trim($data['duration_type']  ?? '');

    if (!$listingId)    jsonError('listing_id required');

    $validDurations = ['2h','4h','today','tomorrow'];
    if (!in_array($durationType, $validDurations)) jsonError('Invalid duration_type. Use: 2h, 4h, today, tomorrow');

    $db = getDB();

    // Ilani getir
    // Transaction + SELECT FOR UPDATE: race condition önle
    $db->beginTransaction();
    $st = $db->prepare("SELECT id, user_id, title, status FROM listings WHERE id = ? FOR UPDATE");
    $st->execute([$listingId]);
    $listing = $st->fetch();
    if (!$listing) jsonError('Listing not found', 404);
    if ($listing['status'] !== 'active') jsonError('Listing is not available');
    if ((int)$listing['user_id'] === $uid) jsonError('Cannot reserve your own listing');

    $sellerId = (int)$listing['user_id'];

    // Zaten aktif rezervasyon var mi?
    $chk = $db->prepare("SELECT id FROM reservations WHERE listing_id = ? AND buyer_id = ? AND status IN ('pending','confirmed')");
    $chk->execute([$listingId, $uid]);
    if ($chk->fetch()) jsonError('You already have an active reservation for this listing');

    // Baska biri rezerve etmis mi?
    $chk2 = $db->prepare("SELECT id FROM reservations WHERE listing_id = ? AND status = 'confirmed'");
    $chk2->execute([$listingId]);
    if ($chk2->fetch()) jsonError('This listing is already reserved by someone else');

    // expires_at hesapla
    $expiresAt = match($durationType) {
        '2h'      => date('Y-m-d H:i:s', strtotime('+2 hours')),
        '4h'      => date('Y-m-d H:i:s', strtotime('+4 hours')),
        'today'   => date('Y-m-d 23:59:59'),
        'tomorrow'=> date('Y-m-d 23:59:59', strtotime('+1 day')),
    };

    $ins = $db->prepare("INSERT INTO reservations (listing_id, buyer_id, seller_id, status, duration_type, expires_at) VALUES (?, ?, ?, 'pending', ?, ?)");
    $ins->execute([$listingId, $uid, $sellerId, $durationType, $expiresAt]);
    $resId = (int)$db->lastInsertId();

    // Saticiya bildirim
    try {
        $buyerSt = $db->prepare("SELECT display_name FROM users WHERE id = ?");
        $buyerSt->execute([$uid]);
        $buyerName = $buyerSt->fetchColumn() ?: 'Someone';

        $db->prepare("INSERT INTO notifications (user_id, type, title, body, url) VALUES (?, 'reservation', ?, ?, ?)")
           ->execute([
               $sellerId,
               "Urun rezervasyon talebi: \"{$listing['title']}\"",
               "$buyerName urunuzu ayirtmak istiyor. Onayla veya reddet.",
               '/messages.html'
           ]);
    } catch (\Throwable $e) {}

    jsonSuccess([
        'reservation_id' => $resId,
        'expires_at'     => $expiresAt,
        'status'         => 'pending',
        'message'        => 'Reservation request sent. Waiting for seller confirmation.'
    ], 201);
}

// ── Rezervasyona yanit ver (satici) ──────────────────────────────────────
function handleRespond(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $resId  = (int)($data['reservation_id'] ?? 0);
    $action = trim($data['action'] ?? '');

    if (!$resId) jsonError('reservation_id required');
    if (!in_array($action, ['confirm','reject'])) jsonError('action must be confirm or reject');

    $db = getDB();
    $st = $db->prepare("SELECT * FROM reservations WHERE id = ?");
    $st->execute([$resId]);
    $res = $st->fetch();

    if (!$res) jsonError('Reservation not found', 404);
    if ((int)$res['seller_id'] !== $uid) jsonError('Only the seller can respond', 403);
    if ($res['status'] !== 'pending') jsonError('Reservation is not pending (status: ' . $res['status'] . ')');

    // Suresi dolmus mu?
    if (strtotime($res['expires_at']) < time()) {
        $db->prepare("UPDATE reservations SET status = 'expired' WHERE id = ?")->execute([$resId]);
        jsonError('Reservation has expired');
    }

    if ($action === 'confirm') {
        $db->prepare("UPDATE reservations SET status = 'confirmed', updated_at = NOW() WHERE id = ?")->execute([$resId]);

        // Ilan durumunu 'reserved' yap
        try {
            $db->prepare("UPDATE listings SET status = 'reserved' WHERE id = ? AND status = 'active'")
               ->execute([$res['listing_id']]);
        } catch (\Throwable $e) {
            // listings tablosunda 'reserved' status yoksa ekle
            try {
                $db->exec("ALTER TABLE listings MODIFY COLUMN status ENUM('pending','active','sold','reserved','expired','rejected','deleted') DEFAULT 'pending'");
                $db->prepare("UPDATE listings SET status = 'reserved' WHERE id = ?")->execute([$res['listing_id']]);
            } catch (\Throwable $e2) {}
        }

        // Aliciya bildirim
        try {
            $lstSt = $db->prepare("SELECT title FROM listings WHERE id = ?");
            $lstSt->execute([$res['listing_id']]);
            $title = $lstSt->fetchColumn() ?: 'Listing';

            $db->prepare("INSERT INTO notifications (user_id, type, title, body, url) VALUES (?, 'reservation_confirmed', ?, ?, ?)")
               ->execute([
                   $res['buyer_id'],
                   "Rezervasyonunuz onaylandi! \"{$title}\"",
                   'Satici rezervasyonunuzu onayladi. Lutfen belirtilen surede gidiniz.',
                   '/listing.html?id=' . $res['listing_id']
               ]);
        } catch (\Throwable $e) {}

        jsonSuccess(['message' => 'Reservation confirmed', 'status' => 'confirmed']);

    } else {
        $db->prepare("UPDATE reservations SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$resId]);

        // Aliciya bildirim
        try {
            $db->prepare("INSERT INTO notifications (user_id, type, title, body, url) VALUES (?, 'reservation_rejected', ?, ?, ?)")
               ->execute([
                   $res['buyer_id'],
                   'Rezervasyon reddedildi',
                   'Satici rezervasyon talebinizi reddetti.',
                   '/listing.html?id=' . $res['listing_id']
               ]);
        } catch (\Throwable $e) {}

        jsonSuccess(['message' => 'Reservation rejected', 'status' => 'cancelled']);
    }
}

// ── Iptal et ─────────────────────────────────────────────────────────────
function handleCancel(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $resId = (int)($data['reservation_id'] ?? 0);
    if (!$resId) jsonError('reservation_id required');

    $db = getDB();
    $st = $db->prepare("SELECT * FROM reservations WHERE id = ?");
    $st->execute([$resId]);
    $res = $st->fetch();

    if (!$res) jsonError('Reservation not found', 404);
    if ((int)$res['buyer_id'] !== $uid && (int)$res['seller_id'] !== $uid) {
        jsonError('Forbidden', 403);
    }
    if (!in_array($res['status'], ['pending','confirmed'])) {
        jsonError('Cannot cancel (status: ' . $res['status'] . ')');
    }

    $db->prepare("UPDATE reservations SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$resId]);

    // Ilan durumunu active'e geri al
    try {
        $db->prepare("UPDATE listings SET status = 'active' WHERE id = ? AND status = 'reserved'")
           ->execute([$res['listing_id']]);
    } catch (\Throwable $e) {}

    // Diger tarafa bildirim
    $otherId = (int)$res['buyer_id'] === $uid ? (int)$res['seller_id'] : (int)$res['buyer_id'];
    try {
        $db->prepare("INSERT INTO notifications (user_id, type, title, body, url) VALUES (?, 'reservation_cancelled', ?, ?, ?)")
           ->execute([
               $otherId,
               'Rezervasyon iptal edildi',
               'Rezervasyon iptal edildi, urun tekrar musait.',
               '/listing.html?id=' . $res['listing_id']
           ]);
    } catch (\Throwable $e) {}

    jsonSuccess(['message' => 'Reservation cancelled']);
}

// ── Rezervasyonlarimi listele ─────────────────────────────────────────────
function handleList(): void {
    $uid  = requireAuth();
    $role = trim($_GET['role'] ?? 'buyer'); // buyer veya seller
    $db   = getDB();

    // Suresi dolanlari guncelle
    try {
        $db->exec("UPDATE reservations SET status = 'expired' WHERE status IN ('pending','confirmed') AND expires_at < NOW()");
    } catch (\Throwable $e) {}

    $col = $role === 'seller' ? 'seller_id' : 'buyer_id';

    $st = $db->prepare("
        SELECT r.*,
               l.title AS listing_title,
               l.images AS listing_images,
               l.price AS listing_price,
               l.currency AS listing_currency,
               b.display_name AS buyer_name,
               b.avatar_url   AS buyer_avatar,
               s.display_name AS seller_name,
               s.avatar_url   AS seller_avatar
        FROM reservations r
        JOIN listings l ON r.listing_id = l.id
        JOIN users b    ON r.buyer_id   = b.id
        JOIN users s    ON r.seller_id  = s.id
        WHERE r.$col = ?
        ORDER BY r.created_at DESC
        LIMIT 50
    ");
    $st->execute([$uid]);
    $rows = $st->fetchAll();

    foreach ($rows as &$r) {
        $imgs = [];
        try { $imgs = json_decode($r['listing_images'] ?? '[]', true) ?: []; } catch (\Throwable $e) {}
        $r['listing_image'] = $imgs[0] ?? null;
        unset($r['listing_images']);

        // Kalan sure
        if (in_array($r['status'], ['pending','confirmed'])) {
            $diff = strtotime($r['expires_at']) - time();
            if ($diff > 0) {
                $h = floor($diff / 3600);
                $m = floor(($diff % 3600) / 60);
                $r['time_remaining'] = $h > 0 ? "{$h}s {$m}d kaldi" : "{$m}d kaldi";
            } else {
                $r['time_remaining'] = 'Suresi doldu';
            }
        } else {
            $r['time_remaining'] = null;
        }
    }

    jsonSuccess(['reservations' => $rows, 'role' => $role]);
}

// ── Tekil rezervasyon getir ───────────────────────────────────────────────
function handleGet(): void {
    $uid   = requireAuth();
    $resId = (int)($_GET['id'] ?? 0);
    if (!$resId) jsonError('id required');

    $db = getDB();
    $st = $db->prepare("SELECT * FROM reservations WHERE id = ? AND (buyer_id = ? OR seller_id = ?)");
    $st->execute([$resId, $uid, $uid]);
    $res = $st->fetch();
    if (!$res) jsonError('Reservation not found', 404);

    jsonSuccess($res);
}
