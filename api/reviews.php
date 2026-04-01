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

$action = $_GET['action'] ?? '';
autoMigrateReviews();

switch ($action) {
    case 'submit':       handleSubmit();      break;
    case 'get_seller':   handleGetSeller();   break;
    case 'get_listing':  handleGetListing();  break;
    case 'can_review':   handleCanReview();   break;
    case 'pending':      handlePending();     break;
    default: jsonError('Unknown action');
}

function autoMigrateReviews(): void {
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS reviews (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            listing_id  INT NOT NULL,
            reviewer_id INT NOT NULL,
            seller_id   INT NOT NULL,
            offer_id    INT NULL,
            rating      TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
            comment     TEXT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_review (listing_id, reviewer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(\Throwable $e) {}
}

// Değerlendirme gönder
function handleSubmit(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $listingId = (int)($data['listing_id'] ?? 0);
    $rating    = (int)($data['rating']     ?? 0);
    $comment   = trim($data['comment']     ?? '');

    if (!$listingId)          jsonError('Missing listing_id');
    if ($rating < 1 || $rating > 5) jsonError('Rating must be 1-5');

    $db = getDB();

    // İlanı ve satıcıyı bul
    $lst = $db->prepare('SELECT id, user_id, title FROM listings WHERE id=?');
    $lst->execute([$listingId]);
    $listing = $lst->fetch();
    if (!$listing) jsonError('Listing not found', 404);

    $sellerId = (int)$listing['user_id'];
    if ($sellerId === $uid) jsonError('Cannot review your own listing');

    // Daha önce değerlendirdi mi?
    $chk = $db->prepare('SELECT id FROM reviews WHERE listing_id=? AND reviewer_id=?');
    $chk->execute([$listingId, $uid]);
    if ($chk->fetch()) jsonError('You have already reviewed this listing');

    // Kabul edilmiş teklif var mı? (14 gün içinde)
    $offerChk = $db->prepare(
        "SELECT id FROM offers WHERE listing_id=? AND buyer_id=? AND status='accepted'
         AND responded_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) LIMIT 1"
    );
    $offerChk->execute([$listingId, $uid]);
    $offer = $offerChk->fetch();

    // Teklif yoksa — genel ziyaretçi de değerlendirme yapabilsin (14 gün şartı sadece öneri)
    $offerId = $offer ? (int)$offer['id'] : null;

    $ins = $db->prepare(
        'INSERT INTO reviews (listing_id, reviewer_id, seller_id, offer_id, rating, comment) VALUES (?,?,?,?,?,?)'
    );
    $ins->execute([$listingId, $uid, $sellerId, $offerId, $rating, $comment ?: null]);

    $reviewId = (int)$db->lastInsertId();
    // Satıcıya bildirim
    try {
        $reviewerSt = $db->prepare('SELECT display_name FROM users WHERE id=?');
        $reviewerSt->execute([$uid]);
        $reviewerName = $reviewerSt->fetch()['display_name'] ?? 'Someone';
        $stars = str_repeat('⭐', $rating);
        $db->prepare(
            "INSERT INTO notifications (user_id,type,title,body,link,icon) VALUES (?,?,?,?,?,?)"
        )->execute([
            $sellerId, 'review',
            "$reviewerName left you a review $stars",
            mb_substr($comment ?: 'No comment', 0, 100),
            'profile.html?tab=reviews',
            '⭐'
        ]);
    } catch(\Throwable $e) {}
    jsonSuccess(['message' => 'Review submitted!', 'review_id' => $reviewId]);
}

// Satıcının tüm değerlendirmeleri + ortalama puan
function handleGetSeller(): void {
    $sellerId = (int)($_GET['seller_id'] ?? 0);
    if (!$sellerId) jsonError('Missing seller_id');

    $db = getDB();
    try {
        $st = $db->prepare(
            "SELECT r.*, u.display_name AS reviewer_name, u.avatar_url AS reviewer_avatar,
                    l.title AS listing_title
             FROM reviews r
             JOIN users u ON r.reviewer_id = u.id
             JOIN listings l ON r.listing_id = l.id
             WHERE r.seller_id = ?
             ORDER BY r.created_at DESC
             LIMIT 50"
        );
        $st->execute([$sellerId]);
        $reviews = $st->fetchAll();

        $avg = $db->prepare('SELECT AVG(rating) AS avg, COUNT(*) AS total FROM reviews WHERE seller_id=?');
        $avg->execute([$sellerId]);
        $stats = $avg->fetch();

        // Seller bilgisi
        $sellerSt = $db->prepare('SELECT id, display_name, avatar_url, city FROM users WHERE id=?');
        $sellerSt->execute([$sellerId]);
        $seller = $sellerSt->fetch();

        // Rating breakdown
        $brkSt = $db->prepare('SELECT rating, COUNT(*) as cnt FROM reviews WHERE seller_id=? GROUP BY rating');
        $brkSt->execute([$sellerId]);
        $breakdown = [];
        foreach ($brkSt->fetchAll() as $b) {
            $breakdown[(int)$b['rating']] = (int)$b['cnt'];
        }

        jsonSuccess([
            'seller'     => $seller ?: null,
            'reviews'    => array_map('fmtReview', $reviews),
            'average'    => $stats['avg'] ? round((float)$stats['avg'], 1) : null,
            'count'      => (int)$stats['total'],
            'breakdown'  => $breakdown,
            // backwards compat
            'avg_rating' => $stats['avg'] ? round((float)$stats['avg'], 1) : null,
            'total'      => (int)$stats['total'],
        ]);
    } catch(\Throwable $e) {
        jsonSuccess(['reviews' => [], 'avg_rating' => null, 'total' => 0]);
    }
}

// Bir ilana ait değerlendirmeler
function handleGetListing(): void {
    $listingId = (int)($_GET['listing_id'] ?? 0);
    if (!$listingId) jsonError('Missing listing_id');

    $db = getDB();
    try {
        $st = $db->prepare(
            "SELECT r.*, u.display_name AS reviewer_name, u.avatar_url AS reviewer_avatar
             FROM reviews r
             JOIN users u ON r.reviewer_id = u.id
             WHERE r.listing_id = ?
             ORDER BY r.created_at DESC"
        );
        $st->execute([$listingId]);
        $reviews = $st->fetchAll();

        $avg = $db->prepare('SELECT AVG(rating) AS avg, COUNT(*) AS total FROM reviews WHERE listing_id=?');
        $avg->execute([$listingId]);
        $stats = $avg->fetch();

        // Listing'den seller_id çek
        $lstSt = $db->prepare('SELECT user_id FROM listings WHERE id=?');
        $lstSt->execute([$listingId]);
        $lstRow = $lstSt->fetch();
        $sellerId = $lstRow ? (int)$lstRow['user_id'] : 0;

        // Seller bilgisi
        $sellerSt = $db->prepare('SELECT id, display_name, avatar_url, city FROM users WHERE id=?');
        $sellerSt->execute([$sellerId]);
        $seller = $sellerSt->fetch();

        // Rating breakdown
        $brkSt = $db->prepare('SELECT rating, COUNT(*) as cnt FROM reviews WHERE seller_id=? GROUP BY rating');
        $brkSt->execute([$sellerId]);
        $breakdown = [];
        foreach ($brkSt->fetchAll() as $b) {
            $breakdown[(int)$b['rating']] = (int)$b['cnt'];
        }

        jsonSuccess([
            'seller'     => $seller ?: null,
            'reviews'    => array_map('fmtReview', $reviews),
            'average'    => $stats['avg'] ? round((float)$stats['avg'], 1) : null,
            'count'      => (int)$stats['total'],
            'breakdown'  => $breakdown,
            // backwards compat
            'avg_rating' => $stats['avg'] ? round((float)$stats['avg'], 1) : null,
            'total'      => (int)$stats['total'],
        ]);
    } catch(\Throwable $e) {
        jsonSuccess(['reviews' => [], 'avg_rating' => null, 'total' => 0]);
    }
}

// Kullanıcı bu ilanı değerlendirebilir mi?
function handleCanReview(): void {
    $uid       = requireAuth();
    $listingId = (int)($_GET['listing_id'] ?? 0);
    if (!$listingId) jsonError('Missing listing_id');

    $db = getDB();

    // Satıcı mı?
    $lst = $db->prepare('SELECT user_id FROM listings WHERE id=?');
    $lst->execute([$listingId]);
    $listing = $lst->fetch();
    if (!$listing) jsonError('Not found', 404);
    if ((int)$listing['user_id'] === $uid) {
        jsonSuccess(['can_review' => false, 'reason' => 'own_listing']);
        return;
    }

    // Daha önce yazdı mı?
    $chk = $db->prepare('SELECT id FROM reviews WHERE listing_id=? AND reviewer_id=?');
    $chk->execute([$listingId, $uid]);
    if ($chk->fetch()) {
        jsonSuccess(['can_review' => false, 'reason' => 'already_reviewed']);
        return;
    }

    // Kabul edilmiş teklif var mı?
    $offerChk = $db->prepare(
        "SELECT id, responded_at FROM offers WHERE listing_id=? AND buyer_id=? AND status='accepted' LIMIT 1"
    );
    $offerChk->execute([$listingId, $uid]);
    $offer = $offerChk->fetch();

    if ($offer) {
        // 90 gün içinde mi?
        $daysSince = (time() - strtotime($offer['responded_at'])) / 86400;
        jsonSuccess([
            'can_review'    => true,
            'reason'        => 'accepted_offer',
            'days_since'    => round($daysSince),
            'show_reminder' => $daysSince <= 14,
        ]);
    } else {
        // Teklif olmadan da değerlendirme yapılabilir (genel ziyaretçi)
        jsonSuccess(['can_review' => true, 'reason' => 'general']);
    }
}

// Değerlendirme bekleyen ilanlar (alıcı için reminder)
function handlePending(): void {
    $uid = requireAuth();
    $db  = getDB();

    try {
        $st = $db->prepare(
            "SELECT o.listing_id, o.responded_at, l.title, l.images,
                    u.display_name AS seller_name
             FROM offers o
             JOIN listings l ON o.listing_id = l.id
             JOIN users u ON l.user_id = u.id
             WHERE o.buyer_id = ? AND o.status = 'accepted'
               AND o.responded_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
               AND o.listing_id NOT IN (
                   SELECT listing_id FROM reviews WHERE reviewer_id = ?
               )
             ORDER BY o.responded_at DESC"
        );
        $st->execute([$uid, $uid]);
        $rows = $st->fetchAll();

        $pending = array_map(function($r) {
            $imgs = [];
            try { $imgs = json_decode($r['images'] ?? '[]', true) ?: []; } catch(\Throwable $e) {}
            $days = round((time() - strtotime($r['responded_at'])) / 86400);
            return [
                'listingId'    => (int)$r['listing_id'],
                'listingTitle' => $r['title'],
                'listingImage' => $imgs[0] ?? null,
                'sellerName'   => $r['seller_name'],
                'daysSince'    => $days,
            ];
        }, $rows);

        jsonSuccess(['pending' => $pending]);
    } catch(\Throwable $e) {
        jsonSuccess(['pending' => []]);
    }
}

function fmtReview(array $r): array {
    return [
        'id'             => (int)$r['id'],
        'listingId'      => (int)$r['listing_id'],
        'listingTitle'   => $r['listing_title'] ?? null,
        'reviewerId'     => (int)$r['reviewer_id'],
        'reviewerName'   => $r['reviewer_name'] ?? 'Anonymous',
        'reviewerAvatar' => $r['reviewer_avatar'] ?? null,
        'rating'         => (int)$r['rating'],
        'comment'        => $r['comment'] ?? null,
        'createdAt'      => $r['created_at'],
    ];
}
