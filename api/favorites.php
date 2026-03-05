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
        'SELECT l.*, u.display_name AS seller_name, u.photo_url AS seller_photo,
                u.verified AS seller_verified, f.created_at AS favorited_at
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
    $st = $db->prepare('SELECT id FROM listings WHERE id = ? AND status != "deleted"');
    $st->execute([$lid]);
    if (!$st->fetch()) jsonError('Listing not found', 404);

    $db->prepare('INSERT IGNORE INTO favorites (user_id, listing_id) VALUES (?, ?)')->execute([$uid, $lid]);
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
