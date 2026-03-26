<?php
/**
 * SomaBazar Admin — Store Yonetimi
 * Bu dosya admin.php tarafindan include edilir.
 * Standalone cagrildiginda da calisir.
 */

// Direkt erisimde standalone mod
if (!defined('SOMABAZAR_ADMIN_INCLUDED')) {
    // Standalone mod - kendi auth ve switch'ini calistir
    require_once __DIR__ . '/config.php';
    
    $uid = requireAuth(true);
    $db  = getDB();
    $st  = $db->prepare('SELECT role, is_admin FROM users WHERE id = ?');
    $st->execute([$uid]);
    $me  = $st->fetch();
    if (!$me || (!$me['is_admin'] && $me['role'] !== 'admin')) {
        jsonError('Forbidden — Admins only', 403);
    }
    
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($action) {
        case 'admin_stores':
            handleAdminStores();
            break;
        case 'admin_suspend_store':
            if ($method !== 'POST') jsonError('Method not allowed', 405);
            handleAdminSuspendStore();
            break;
        case 'admin_verification_queue':
            handleAdminVerificationQueue();
            break;
        default:
            jsonError('Unknown action: ' . $action);
    }
    exit;
}

// Include mod - sadece fonksiyonlar tanimlanir, switch calistirilmaz

function handleAdminStores(): void {
    $db      = getDB();
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(50, (int)($_GET['per_page'] ?? 20));
    $offset  = ($page - 1) * $perPage;
    $search  = trim($_GET['search'] ?? '');
    $status  = trim($_GET['status'] ?? '');
    $verif   = trim($_GET['verification_status'] ?? '');

    $where  = ['1=1'];
    $params = [];

    if ($search) {
        $where[]  = "(s.store_name LIKE ? OR s.city LIKE ? OR u.email LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($status) { $where[] = "s.status = ?"; $params[] = $status; }
    if ($verif)  { $where[] = "s.verification_status = ?"; $params[] = $verif; }

    $whereSQL = implode(' AND ', $where);

    $countSt = $db->prepare("SELECT COUNT(*) FROM stores s JOIN users u ON s.owner_id = u.id WHERE $whereSQL");
    $countSt->execute($params);
    $total = (int)$countSt->fetchColumn();

    $st = $db->prepare("
        SELECT s.*, u.email AS owner_email, u.display_name AS owner_name,
               (SELECT COUNT(*) FROM listings l WHERE l.user_id = s.owner_id AND l.status = 'active') AS active_listings
        FROM stores s
        JOIN users u ON s.owner_id = u.id
        WHERE $whereSQL
        ORDER BY s.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $perPage;
    $params[] = $offset;
    $st->execute($params);

    header('X-Total-Count: ' . $total);
    jsonSuccess([
        'stores'      => $st->fetchAll(),
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => ceil($total / $perPage),
    ]);
}

function handleAdminSuspendStore(): void {
    $data    = json_decode(file_get_contents('php://input'), true) ?? [];
    $storeId = (int)($data['store_id'] ?? 0);
    $action  = trim($data['action'] ?? '');
    $reason  = trim($data['reason'] ?? '');

    if (!$storeId) jsonError('store_id required');
    if (!in_array($action, ['suspend','activate'])) jsonError('action must be suspend or activate');

    $db = getDB();
    $st = $db->prepare("SELECT id, owner_id, store_name FROM stores WHERE id = ?");
    $st->execute([$storeId]);
    $store = $st->fetch();
    if (!$store) jsonError('Store not found', 404);

    $newStatus = $action === 'suspend' ? 'suspended' : 'active';
    $db->prepare("UPDATE stores SET status = ?, updated_at = NOW() WHERE id = ?")
       ->execute([$newStatus, $storeId]);

    try {
        $title = $action === 'suspend' ? 'Magazaniz askiya alindi' : 'Magazaniz yeniden aktif edildi';
        $body  = $reason ?: ($action === 'suspend'
            ? 'Magazaniz kural ihlali nedeniyle askiya alindi.'
            : 'Magazaniz yonetici tarafindan yeniden aktif edildi.');
        $db->prepare("INSERT INTO notifications (user_id, type, title, body, url) VALUES (?, 'store_status', ?, ?, ?)")
           ->execute([$store['owner_id'], $title, $body, '/profile.html?tab=store']);
    } catch (\Throwable $e) {}

    jsonSuccess(['message' => "Store {$action}d", 'status' => $newStatus]);
}

function handleAdminVerificationQueue(): void {
    $db     = getDB();
    $status = trim($_GET['status'] ?? 'pending');

    $validStatus = ['pending','under_review','approved','rejected','more_info_needed'];
    if (!in_array($status, $validStatus)) $status = 'pending';

    $st = $db->prepare("
        SELECT vr.*,
               s.store_name, s.city, s.store_type, s.logo_url, s.owner_id,
               u.email AS owner_email, u.display_name AS owner_name
        FROM verification_requests vr
        JOIN stores s ON vr.store_id = s.id
        JOIN users u  ON s.owner_id  = u.id
        WHERE vr.status = ?
        ORDER BY vr.submitted_at ASC
    ");
    $st->execute([$status]);
    $requests = $st->fetchAll();

    foreach ($requests as &$r) {
        $r['documents'] = $r['documents'] ? json_decode($r['documents'], true) : [];
    }

    jsonSuccess(['requests' => $requests, 'count' => count($requests)]);
}
