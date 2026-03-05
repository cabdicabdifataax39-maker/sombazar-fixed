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
// Rate limit — sadece yazma işlemlerine uygula (GET okuma isteklerine değil)
if ($_SERVER['REQUEST_METHOD'] !== 'GET') applyEndpointRateLimit('offers');

require_once __DIR__ . '/mailer.php';

$action = $_GET['action'] ?? '';
autoMigrateOffers();

switch ($action) {
    case 'make':           handleMake();          break;
    case 'accept_counter': handleAcceptCounter(); break;
    case 'respond':        handleRespond();       break;
    case 'cancel':         handleCancel();        break;
    case 'listing_offers': handleListingOffers(); break;
    case 'my_offers':      handleMyOffers();      break;
    case 'get':            handleGet();           break;
    default: jsonError('Unknown action: ' . $action);
}

function autoMigrateOffers(): void {
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS offers (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            listing_id     INT NOT NULL,
            buyer_id       INT NOT NULL,
            seller_id      INT NOT NULL,
            round          TINYINT UNSIGNED DEFAULT 1,
            amount         DECIMAL(15,2) NOT NULL,
            currency       VARCHAR(10) DEFAULT 'USD',
            note           TEXT NULL,
            status         VARCHAR(20) DEFAULT 'pending',
            counter_amount DECIMAL(15,2) NULL,
            counter_note   TEXT NULL,
            expires_at     DATETIME NOT NULL,
            responded_at   DATETIME NULL,
            created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_listing (listing_id),
            INDEX idx_buyer   (buyer_id),
            INDEX idx_seller  (seller_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(\Throwable $e) {}
    // updated_at kolonu yoksa ekle (eski kurulumlar için)
    try { $db->exec("ALTER TABLE offers ADD COLUMN updated_at DATETIME NULL"); } catch(\Throwable $e) {}
}

function handleMake(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $listingId = (int)($data['listing_id'] ?? 0);
    $amount    = (float)($data['amount']   ?? 0);
    $currency  = trim($data['currency']    ?? 'USD');
    $note      = trim($data['note']        ?? '');

    if (!$listingId)  jsonError('Missing listing_id');
    if ($amount <= 0) jsonError('Invalid amount');
    if (!in_array($currency, ['USD','SLSH','ETB'])) $currency = 'USD';

    $db = getDB();
    $lst = $db->prepare('SELECT id, user_id, status FROM listings WHERE id=?');
    $lst->execute([$listingId]);
    $listing = $lst->fetch();
    if (!$listing)                         jsonError('Listing not found', 404);
    if ($listing['status'] !== 'active')   jsonError('Listing is not active');
    if ((int)$listing['user_id'] === $uid) jsonError('Cannot offer on your own listing');

    $sellerId = (int)$listing['user_id'];
    $expires  = date('Y-m-d H:i:s', strtotime('+48 hours'));
    $now      = date('Y-m-d H:i:s');

    $st = $db->prepare("SELECT * FROM offers WHERE listing_id=? AND buyer_id=? ORDER BY created_at DESC LIMIT 1");
    $st->execute([$listingId, $uid]);
    $existing = $st->fetch();

    if (!$existing) {
        $ins = $db->prepare("INSERT INTO offers (listing_id,buyer_id,seller_id,round,amount,currency,note,status,expires_at) VALUES (?,?,?,1,?,?,?,'pending',?)");
        $ins->execute([$listingId, $uid, $sellerId, $amount, $currency, $note ?: null, $expires]);
        $offerId = (int)$db->lastInsertId();

        // Email: seller'a teklif bildirimi
        try {
            $sellerRow = $db->prepare('SELECT email, display_name FROM users WHERE id=?');
            $sellerRow->execute([$sellerId]);
            $seller = $sellerRow->fetch();
            $lstTitle = $db->prepare('SELECT title FROM listings WHERE id=?');
            $lstTitle->execute([$listingId]);
            $lstRow = $lstTitle->fetch();
            if ($seller && $lstRow) {
                Mailer::sendOfferNotification(
                    $seller['email'],
                    $seller['display_name'],
                    $lstRow['title'],
                    $currency . ' ' . number_format($amount, 2),
                    SITE_URL . '/profile.html?tab=offers'
                );
            }
        } catch(\Throwable $e) { error_log("Email error: " . $e->getMessage()); }

        jsonSuccess(['offer_id'=>$offerId,'round'=>1,'expires_at'=>$expires,'message'=>'Offer submitted (Round 1/5). Seller has 48 hours to respond.']);
    }

    if ($existing['status'] === 'pending') {
        jsonError('You already have a pending offer. Wait for the seller to respond.');
    }
    if ($existing['status'] === 'countered') {
        $newRound = (int)$existing['round'] + 1;
        if ($newRound > 5) jsonError('Maximum 5 rounds reached.');
        $upd = $db->prepare("UPDATE offers SET round=?,amount=?,currency=?,note=?,status='pending',counter_amount=NULL,counter_note=NULL,expires_at=?,responded_at=NULL WHERE id=?");
        $upd->execute([$newRound, $amount, $currency, $note ?: null, $expires, (int)$existing['id']]);
        jsonSuccess(['offer_id'=>(int)$existing['id'],'round'=>$newRound,'expires_at'=>$expires,'message'=>"New offer sent (Round $newRound/5). Seller has 48 hours to respond."]);
    }

    jsonError('Cannot make offer. Current status: ' . ($existing['status'] ?? 'unknown'));
}

function handleAcceptCounter(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $oid  = (int)($data['offer_id'] ?? 0);
    if (!$oid) jsonError('Missing offer_id');

    $db = getDB();
    $st = $db->prepare('SELECT * FROM offers WHERE id=?');
    $st->execute([$oid]);
    $offer = $st->fetch();
    if (!$offer)                          jsonError('Not found', 404);
    if ((int)$offer['buyer_id'] !== $uid) jsonError('Forbidden', 403);
    if ($offer['status'] !== 'countered') jsonError('No counter to accept');
    if (!$offer['counter_amount'])        jsonError('No counter amount');

    $db->prepare("UPDATE offers SET status='accepted',amount=?,counter_amount=NULL,counter_note=NULL,responded_at=NOW() WHERE id=?")
       ->execute([(float)$offer['counter_amount'], $oid]);

    sendOfferMessage((int)$offer['seller_id'], (int)$offer['buyer_id'], (int)$offer['listing_id'],
        'Counter Accepted! The buyer accepted your counter offer of ' . $offer['currency'] . ' ' . number_format((float)$offer['counter_amount'],2) . '. Please arrange the exchange.');

    jsonSuccess(['message'=>'Counter offer accepted!','status'=>'accepted']);
}

function handleRespond(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $oid   = (int)($data['offer_id']       ?? 0);
    $act   = trim($data['action']          ?? '');
    $camt  = isset($data['counter_amount']) ? (float)$data['counter_amount'] : null;
    $cnote = trim($data['counter_note']    ?? '');

    if (!$oid) jsonError('Missing offer_id');
    if (!in_array($act, ['accept','reject','counter'])) jsonError('Invalid action');
    if ($act === 'counter' && (!$camt || $camt <= 0)) jsonError('Counter amount required');

    $db = getDB();
    $st = $db->prepare('SELECT * FROM offers WHERE id=?');
    $st->execute([$oid]);
    $offer = $st->fetch();
    if (!$offer)                           jsonError('Not found', 404);
    if ((int)$offer['seller_id'] !== $uid) jsonError('Only the seller can respond');
    if ($offer['status'] !== 'pending')    jsonError('Offer is not pending (status: ' . $offer['status'] . ')');
    if (strtotime($offer['expires_at']) < time()) {
        $db->prepare("UPDATE offers SET status='expired' WHERE id=?")->execute([$oid]);
        jsonError('Offer has expired');
    }

    $now = date('Y-m-d H:i:s');
    $lst = $db->prepare('SELECT title FROM listings WHERE id=?');
    $lst->execute([$offer['listing_id']]);
    $ltitle = ($lst->fetch()['title'] ?? 'the listing');

    if ($act === 'accept') {
        $db->prepare("UPDATE offers SET status='accepted',responded_at=? WHERE id=?")->execute([$now,$oid]);
        $db->prepare("UPDATE offers SET status='rejected',responded_at=? WHERE listing_id=? AND id!=? AND status='pending'")->execute([$now,$offer['listing_id'],$oid]);
        sendOfferMessage((int)$offer['seller_id'], (int)$offer['buyer_id'], (int)$offer['listing_id'],
            "✅ Offer Accepted! Your offer of {$offer['currency']} " . number_format((float)$offer['amount'],2) . " for \"$ltitle\" was accepted. Contact the seller to complete the deal.");
        jsonSuccess(['message'=>'Offer accepted!','status'=>'accepted']);
    }
    if ($act === 'reject') {
        $db->prepare("UPDATE offers SET status='rejected',responded_at=? WHERE id=?")->execute([$now,$oid]);
        sendOfferMessage((int)$offer['seller_id'], (int)$offer['buyer_id'], (int)$offer['listing_id'],
            "❌ Offer Rejected. Your offer of {$offer['currency']} " . number_format((float)$offer['amount'],2) . " for \"$ltitle\" was declined. You can make a new offer if you'd like.");
        jsonSuccess(['message'=>'Offer rejected.','status'=>'rejected']);
    }
    if ($act === 'counter') {
        if ((int)$offer['round'] >= 5) {
            $db->prepare("UPDATE offers SET status='rejected',responded_at=? WHERE id=?")->execute([$now,$oid]);
            jsonError('Max 5 rounds reached. Offer closed.');
        }
        $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));
        $db->prepare("UPDATE offers SET status='countered',counter_amount=?,counter_note=?,responded_at=?,expires_at=? WHERE id=?")
           ->execute([$camt, $cnote ?: null, $now, $expires, $oid]);
        $cnoteText = $cnote ? " Note: $cnote" : '';
        sendOfferMessage((int)$offer['seller_id'], (int)$offer['buyer_id'], (int)$offer['listing_id'],
            "↩ Counter Offer for \"$ltitle\": {$offer['currency']} " . number_format($camt,2) . ".$cnoteText Tap to accept or make a new offer.");
        jsonSuccess(['message'=>'Counter offer sent. Buyer has 48 hours to respond.','status'=>'countered']);
    }
}

function handleCancel(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $oid  = (int)($data['offer_id'] ?? 0);
    if (!$oid) jsonError('Missing offer_id');

    $db = getDB();
    $st = $db->prepare('SELECT * FROM offers WHERE id=?');
    $st->execute([$oid]);
    $offer = $st->fetch();
    if (!$offer)                          jsonError('Not found', 404);
    if ((int)$offer['buyer_id'] !== $uid) jsonError('Forbidden', 403);
    if (!in_array($offer['status'], ['pending','countered'])) jsonError('Cannot cancel');

    $db->prepare("UPDATE offers SET status='cancelled',responded_at=NOW() WHERE id=?")->execute([$oid]);
    jsonSuccess(['message'=>'Offer cancelled.']);
}

function handleListingOffers(): void {
    $uid = requireAuth();
    $lid = (int)($_GET['listing_id'] ?? 0);
    if (!$lid) jsonError('Missing listing_id');

    $db  = getDB();
    $chk = $db->prepare('SELECT user_id FROM listings WHERE id=?');
    $chk->execute([$lid]);
    $lst = $chk->fetch();
    if (!$lst || (int)$lst['user_id'] !== $uid) jsonError('Forbidden', 403);

    try { $db->exec("UPDATE offers SET status='expired' WHERE status='pending' AND expires_at < NOW()"); } catch(\Throwable $e) {}

    try {
        $st = $db->prepare("SELECT o.*, u.display_name AS buyer_name, u.phone AS buyer_phone FROM offers o JOIN users u ON o.buyer_id=u.id WHERE o.listing_id=? ORDER BY o.created_at DESC");
        $st->execute([$lid]);
        jsonSuccess(['offers' => array_map('fmtOffer', $st->fetchAll())]);
    } catch(\Throwable $e) {
        jsonSuccess(['offers' => []]);
    }
}

function handleMyOffers(): void {
    $uid  = requireAuth();
    $type = $_GET['type'] ?? 'sent';
    $db   = getDB();

    try { $db->exec("UPDATE offers SET status='expired' WHERE status='pending' AND expires_at < NOW()"); } catch(\Throwable $e) {}

    try {
        if ($type === 'sent') {
            $st = $db->prepare("SELECT o.*, l.title AS listing_title, l.images AS listing_images, l.price AS listing_price, u.display_name AS seller_name FROM offers o JOIN listings l ON o.listing_id=l.id JOIN users u ON o.seller_id=u.id WHERE o.buyer_id=? ORDER BY o.created_at DESC LIMIT 50");
        } else {
            $st = $db->prepare("SELECT o.*, l.title AS listing_title, l.images AS listing_images, l.price AS listing_price, u.display_name AS buyer_name FROM offers o JOIN listings l ON o.listing_id=l.id JOIN users u ON o.buyer_id=u.id WHERE o.seller_id=? ORDER BY o.created_at DESC LIMIT 50");
        }
        $st->execute([$uid]);
        jsonSuccess(['offers' => array_map('fmtOffer', $st->fetchAll())]);
    } catch(\Throwable $e) {
        jsonSuccess(['offers' => [], 'debug' => $e->getMessage()]);
    }
}

function handleGet(): void {
    $uid = requireAuth();
    $oid = (int)($_GET['offer_id'] ?? 0);
    if (!$oid) jsonError('Missing offer_id');

    $db = getDB();
    try {
        $st = $db->prepare("SELECT o.*, l.title AS listing_title, l.images AS listing_images, b.display_name AS buyer_name, s.display_name AS seller_name FROM offers o JOIN listings l ON o.listing_id=l.id JOIN users b ON o.buyer_id=b.id JOIN users s ON o.seller_id=s.id WHERE o.id=? AND (o.buyer_id=? OR o.seller_id=?)");
        $st->execute([$oid, $uid, $uid]);
        $offer = $st->fetch();
        if (!$offer) jsonError('Not found', 404);
        jsonSuccess(['offer' => fmtOffer($offer)]);
    } catch(\Throwable $e) {
        jsonError('Error: ' . $e->getMessage());
    }
}

function sendOfferMessage(int $fromId, int $toId, int $listingId, string $text): void {
    // Bildirim oluştur
    try {
        $db = getDB();
        $lst = $db->prepare('SELECT title FROM listings WHERE id=?');
        $lst->execute([$listingId]);
        $ltitle = ($lst->fetch()['title'] ?? 'a listing');

        // Bildirim tipini ve ikonunu belirle
        $type = 'offer_update';
        $icon = '🤝';
        if (str_contains($text, 'Accepted')) { $type = 'offer_accepted'; $icon = '✅'; }
        elseif (str_contains($text, 'Rejected')) { $type = 'offer_rejected'; $icon = '❌'; }
        elseif (str_contains($text, 'Counter')) { $type = 'offer_counter'; $icon = '↩'; }

        $db->prepare(
            "INSERT INTO notifications (user_id,type,title,body,link,icon) VALUES (?,?,?,?,?,?)"
        )->execute([
            $toId, $type,
            str_contains($text,'Accepted') ? 'Offer Accepted! 🎉' :
            (str_contains($text,'Rejected') ? 'Offer Declined' : 'Counter Offer Received'),
            mb_substr($text, 0, 150),
            'listing.html?id=' . $listingId,
            $icon
        ]);
    } catch(\Throwable $e) {}
    try {
        $db = getDB();
        $st = $db->prepare("SELECT id FROM conversations WHERE listing_id=? AND ((user1_id=? AND user2_id=?) OR (user1_id=? AND user2_id=?)) LIMIT 1");
        $st->execute([$listingId, $fromId, $toId, $toId, $fromId]);
        $conv = $st->fetch();
        if (!$conv) {
            $db->prepare("INSERT INTO conversations (listing_id,user1_id,user2_id) VALUES (?,?,?)")->execute([$listingId,$fromId,$toId]);
            $convId = (int)$db->lastInsertId();
        } else {
            $convId = (int)$conv['id'];
        }
        $db->prepare("INSERT INTO messages (conversation_id,sender_id,text,created_at) VALUES (?,?,?,NOW())")->execute([$convId,$fromId,$text]);
        try { $db->prepare("UPDATE conversations SET last_message=?,last_message_at=NOW() WHERE id=?")->execute([$text,$convId]); } catch(\Throwable $e) {}
    } catch(\Throwable $e) {}
}

function fmtOffer(array $o): array {
    $imgs = [];
    try { $raw = json_decode($o['listing_images'] ?? '[]', true); if (is_array($raw)) $imgs = $raw; } catch(\Throwable $e) {}

    $expiresIn = null;
    if (in_array($o['status'], ['pending','countered']) && !empty($o['expires_at'])) {
        $diff = strtotime($o['expires_at']) - time();
        if ($diff > 0) {
            $h = floor($diff/3600); $m = floor(($diff%3600)/60);
            $expiresIn = $h > 0 ? "{$h}h {$m}m remaining" : "{$m}m remaining";
        } else { $expiresIn = 'Expired'; }
    }

    return [
        'id'            => (int)$o['id'],
        'listingId'     => (int)$o['listing_id'],
        'listingTitle'  => $o['listing_title']  ?? null,
        'listingImage'  => $imgs[0]             ?? null,
        'listingPrice'  => isset($o['listing_price']) ? (float)$o['listing_price'] : null,
        'buyerId'       => (int)$o['buyer_id'],
        'sellerId'      => (int)$o['seller_id'],
        'buyerName'     => $o['buyer_name']     ?? null,
        'sellerName'    => $o['seller_name']    ?? null,
        'buyerPhone'    => $o['buyer_phone']    ?? null,
        'round'         => (int)$o['round'],
        'amount'        => (float)$o['amount'],
        'currency'      => $o['currency'],
        'note'          => $o['note']           ?? null,
        'status'        => $o['status'],
        'counterAmount' => isset($o['counter_amount']) && $o['counter_amount'] !== null ? (float)$o['counter_amount'] : null,
        'counterNote'   => $o['counter_note']   ?? null,
        'expiresAt'     => $o['expires_at'],
        'expiresIn'     => $expiresIn,
        'respondedAt'   => $o['responded_at']   ?? null,
        'createdAt'     => $o['created_at'],
    ];
}
