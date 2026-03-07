<?php
error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':   handleList();   break;
    case 'add':    if ($method !== 'POST') jsonError('Method not allowed', 405); handleAdd();    break;
    case 'remove': if (!in_array($method, ['POST','DELETE'])) jsonError('Method not allowed', 405); handleRemove(); break;
    case 'check':  handleCheck();  break;
    default:       jsonError('Unknown action', 404);
}

// GET api/favorites.php?action=list
function handleList(): void {
    $uid = requireAuth();
    $db  = getDB();

    $st = $db->prepare(
        'SELECT l.*, u.display_name AS seller_name, u.avatar_url AS seller_photo,
                u.is_verified AS seller_verified, f.created_at AS favorited_at
         FROM favorites f
         JOIN listings l ON f.listing_id = l.id
         JOIN users u ON l.user_id = u.id
         WHERE f.user_id = ? AND l.status != "deleted"
         ORDER BY f.created_at DESC'
    );
    $st->execute([$uid]);

    jsonSuccess(array_map(function ($r) {
        $images = json_decode($r['images'] ?? '[]', true) ?: [];
        return [
            'id'          => $r['id'],
            'userId'      => $r['user_id'],
            'category'    => $r['category'],
            'listingType' => $r['listing_type'] ?? 'sale',
            'title'       => $r['title'],
            'price'       => (float) $r['price'],
            'currency'    => $r['currency'],
            'city'        => $r['city'],
            'featured'    => (bool) $r['featured'],
            'images'      => $images,
            'status'      => $r['status'],
            'createdAt'   => $r['created_at'],
            'favoritedAt' => $r['favorited_at'],
            'seller'      => [
                'displayName' => $r['seller_name'],
                'verified'    => (bool) $r['seller_verified'],
            ],
        ];
    }, $st->fetchAll()));
}

// POST api/favorites.php?action=add   body: { "listingId": 5 }
function handleAdd(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    $lid  = (int) ($data['listingId'] ?? 0);
    if (!$lid) jsonError('listingId required');

    $db = getDB();
    $st = $db->prepare('SELECT l.id, l.title, l.user_id FROM listings l WHERE l.id = ? AND l.status != "deleted"');
    $st->execute([$lid]);
    $listing = $st->fetch();
    if (!$listing) jsonError('Listing not found', 404);

    $db->prepare('INSERT IGNORE INTO favorites (user_id, listing_id) VALUES (?, ?)')->execute([$uid, $lid]);

    // Satıcıya bildirim — kendisi favoriliyorsa gönderme
    if ((int)$listing['user_id'] !== $uid) {
        try {
            $buyerSt = $db->prepare('SELECT display_name FROM users WHERE id=?');
            $buyerSt->execute([$uid]);
            $buyerName = $buyerSt->fetch()['display_name'] ?? 'Someone';
            $db->prepare("INSERT IGNORE INTO notifications (user_id,type,title,body,link,icon) VALUES (?,?,?,?,?,?)")
               ->execute([$listing['user_id'], 'system',
                   "Someone saved your listing",
                   "$buyerName added \"" . $listing['title'] . "\" to their favorites.",
                   'listing.html?id=' . $lid, '']);
        } catch(\Throwable $e) {}
    }

    jsonSuccess(['added' => true, 'listingId' => $lid]);
}

// POST api/favorites.php?action=remove   body: { "listingId": 5 }
function handleRemove(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    $lid  = (int) ($data['listingId'] ?? 0);
    if (!$lid) jsonError('listingId required');

    $db = getDB();
    $db->prepare('DELETE FROM favorites WHERE user_id = ? AND listing_id = ?')->execute([$uid, $lid]);
    jsonSuccess(['removed' => true, 'listingId' => $lid]);
}

// GET api/favorites.php?action=check&listing_id=5
function handleCheck(): void {
    $uid = requireAuth();
    $lid = (int) ($_GET['listing_id'] ?? 0);
    if (!$lid) jsonError('listing_id required');

    $db = getDB();
    $st = $db->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND listing_id = ?');
    $st->execute([$uid, $lid]);
    jsonSuccess(['isFavorite' => (bool) $st->fetch()]);
}
