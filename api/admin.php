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
require_once __DIR__ . '/mailer.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'stats';

// All admin actions require auth + admin role
$uid = requireAuth();
$db  = getDB();
$st  = $db->prepare('SELECT is_admin FROM users WHERE id = ?');
$st->execute([$uid]);
$me  = $st->fetch();
if (!$me || !$me['is_admin']) jsonError('Forbidden — Admins only', 403);

// Auto-create missing tables/columns silently (each in own try/catch)
try { $db->exec("ALTER TABLE users ADD COLUMN token_invalidated_at DATETIME NULL"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
try { $db->exec("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) DEFAULT 0"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
try { $db->exec("ALTER TABLE users ADD COLUMN banned TINYINT(1) DEFAULT 0"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
try { $db->exec("ALTER TABLE users ADD COLUMN ban_reason VARCHAR(500) NULL"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
try { $db->exec("ALTER TABLE users ADD COLUMN badge_level VARCHAR(20) DEFAULT 'basic'"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
try { $db->exec("ALTER TABLE users ADD COLUMN verification_status VARCHAR(20) DEFAULT 'none'"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
try { $db->exec("ALTER TABLE users ADD COLUMN verification_note VARCHAR(500) NULL"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
try { $db->exec("ALTER TABLE users ADD COLUMN seller_type VARCHAR(20) DEFAULT 'individual'"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
try { $db->exec("ALTER TABLE users ADD COLUMN blacklisted TINYINT(1) DEFAULT 0"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
try { $db->exec("CREATE TABLE IF NOT EXISTS blacklist (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(30), national_id VARCHAR(100), reason VARCHAR(500), added_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
try { $db->exec("CREATE TABLE IF NOT EXISTS admin_log (id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, action VARCHAR(100), target_type VARCHAR(30), target_id INT, note TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
try { $db->exec("CREATE TABLE IF NOT EXISTS verification_docs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, doc_type VARCHAR(50), file_url VARCHAR(500), uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP, superseded TINYINT(1) DEFAULT 0) ENGINE=InnoDB"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
try { $db->exec("CREATE TABLE IF NOT EXISTS reports (id INT AUTO_INCREMENT PRIMARY KEY, listing_id INT NOT NULL, reporter_id INT NOT NULL, reason VARCHAR(300), resolved TINYINT(1) DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }

switch ($action) {
    case 'csrf_token':        handleGetCsrfToken();     break;
    case 'stats':             handleStats();            break;
    case 'users':             handleUsers();            break;
    case 'user':              handleUser();             break;
    case 'ban_user':          handleBanUser();          break;
    case 'verify_user':       handleVerifyUser();       break;
    case 'listings':          handleListings();         break;
    case 'approve_listing':   handleApproveListing();   break;
    case 'reject_listing':    handleRejectListing();    break;
    case 'delete_listing':    handleDeleteListing();    break;
    case 'verifications':     handleVerifications();    break;
    case 'blacklist':         handleBlacklist();        break;
    case 'add_blacklist':     handleAddBlacklist();     break;
    case 'remove_blacklist':  handleRemoveBlacklist();  break;
    case 'log':               handleLog();              break;
    case 'payments':          handlePayments();         break;
    case 'all_offers':        handleAllOffers();        break;
    case 'export_csv':        handleExportCsv();        break;
    
    case 'get_coupons':       handleListCoupons();     break;
    case 'create_coupon':     handleCreateCoupon();    break;
    case 'toggle_coupon':     handleToggleCoupon();    break;
    case 'delete_coupon':     handleDeleteCoupon();    break;
    case 'get_affiliates':    handleListAffiliates();  break;
    case 'toggle_affiliate':  handleToggleAffiliate(); break;
    case 'affiliate_payout':  handleAffiliatePayout(); break;
    case 'get_coupon_stats':  handleCouponStats();     break;
    case 'clean':             handleClean();            break;
    case 'approve_payment':   handleApprovePayment();   break;
    case 'reject_payment':    handleRejectPayment();    break;
    case 'get_reports':       handleGetReports();       break;
    case 'resolve_report':    handleResolveReport();    break;
    default: jsonError('Unknown action', 404);
}

function logAction(int $adminId, string $action, string $type, int $targetId, string $note = ''): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $noteWithIp = $note ? "$note | IP: $ip" : "IP: $ip";
    // Avoid double IP if already included
    if (strpos($note, 'IP:') !== false) $noteWithIp = $note;
    getDB()->prepare('INSERT INTO admin_log (admin_id, action, target_type, target_id, note) VALUES (?,?,?,?,?)')
           ->execute([$adminId, $action, $type, $targetId, $noteWithIp]);
}

function handleStats(): void {
    $db = getDB();
    $stats = [
        'total_users'        => (int)$db->query('SELECT COUNT(*) FROM users WHERE is_admin=0')->fetchColumn(),
        'verified_users'     => (int)$db->query('SELECT COUNT(*) FROM users WHERE is_verified=1')->fetchColumn(),
        'pending_verif'      => (int)$db->query("SELECT COUNT(*) FROM users WHERE verification_status='pending'")->fetchColumn(),
        'total_listings'     => (int)$db->query("SELECT COUNT(*) FROM listings WHERE status != 'deleted'")->fetchColumn(),
        'active_listings'    => (int)$db->query("SELECT COUNT(*) FROM listings WHERE status='active'")->fetchColumn(),
        'pending_listings'   => (int)$db->query("SELECT COUNT(*) FROM listings WHERE status='pending'")->fetchColumn(),
        'total_messages'     => (int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn(),
        'total_conversations'=> (int)$db->query('SELECT COUNT(*) FROM conversations')->fetchColumn(),
        'banned_users'       => (int)$db->query('SELECT COUNT(*) FROM users WHERE is_banned=1')->fetchColumn(),
        'blacklisted'        => (int)$db->query('SELECT COUNT(*) FROM blacklist')->fetchColumn(),
        'new_users_today'    => (int)$db->query("SELECT COUNT(*) FROM users WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
        'new_listings_today' => (int)$db->query("SELECT COUNT(*) FROM listings WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
        'new_users_week'     => (int)$db->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn(),
        'new_listings_week'  => (int)$db->query("SELECT COUNT(*) FROM listings WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn(),
        'pending_payments'   => (function() use ($db) { try { return (int)$db->query("SELECT COUNT(*) FROM payments WHERE status='pending'")->fetchColumn(); } catch(\Exception $e) { return 0; } })(),
        'total_revenue'      => (function() use ($db) { try { return (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='approved'")->fetchColumn(); } catch(\Exception $e) { return 0; } })(),
        'reported_listings'  => (function() use ($db) { try { return (int)$db->query("SELECT COUNT(DISTINCT listing_id) FROM reports WHERE resolved=0")->fetchColumn(); } catch(\Exception $e) { return 0; } })(),
        'total_offers'       => (function() use ($db) { try { return (int)$db->query("SELECT COUNT(*) FROM offers")->fetchColumn(); } catch(\Exception $e) { return 0; } })(),
        'pending_offers'     => (function() use ($db) { try { return (int)$db->query("SELECT COUNT(*) FROM offers WHERE status='pending'")->fetchColumn(); } catch(\Exception $e) { return 0; } })(),
        'accepted_offers'    => (function() use ($db) { try { return (int)$db->query("SELECT COUNT(*) FROM offers WHERE status='accepted'")->fetchColumn(); } catch(\Exception $e) { return 0; } })(),
    ];
    // Category breakdown
    $cats = $db->query("SELECT category, COUNT(*) as cnt FROM listings WHERE status='active' GROUP BY category ORDER BY cnt DESC")->fetchAll();
    $stats['categories'] = array_map(fn($r) => ['category'=>$r['category'],'count'=>(int)$r['cnt']], $cats);
    // Recent signups chart (last 7 days)
    $daily = $db->query("SELECT DATE(created_at) as day, COUNT(*) as cnt FROM users WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY day ORDER BY day")->fetchAll();
    $stats['signups_chart'] = array_map(fn($r) => ['day'=>$r['day'],'count'=>(int)$r['cnt']], $daily);
    jsonSuccess($stats);
}

function handleUsers(): void {
    $db     = getDB();
    $search = $_GET['q'] ?? '';
    $status = $_GET['status'] ?? '';
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;

    $where = ['u.is_admin = 0'];
    $params = [];
    if ($search) { $where[] = '(u.display_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)'; $s = "%$search%"; $params = array_merge($params, [$s,$s,$s]); }
    if ($status === 'pending')    { $where[] = "u.verification_status = 'pending'"; }
    if ($status === 'banned')     { $where[] = 'u.banned = 1'; }
    if ($status === 'verified')   { $where[] = 'u.verified = 1'; }
    if ($status === 'unverified') { $where[] = 'u.verified = 0'; }

    $whereSQL = implode(' AND ', $where);
    $params[] = $limit; $params[] = $offset;

    $st = $db->prepare(
        "SELECT u.*, (SELECT COUNT(*) FROM listings WHERE user_id=u.id AND status!='deleted') as listing_count
         FROM users u WHERE $whereSQL ORDER BY u.created_at DESC LIMIT ? OFFSET ?"
    );
    $st->execute($params);

    $total = $db->prepare("SELECT COUNT(*) FROM users u WHERE $whereSQL");
    $total->execute(array_slice($params, 0, -2));

    jsonSuccess([
        'users' => array_map('formatAdminUser', $st->fetchAll()),
        'total' => (int)$total->fetchColumn(),
        'page'  => $page,
        'pages' => (int)ceil($total->fetchColumn() / $limit),
    ]);
}

function handleUser(): void {
    $db  = getDB();
    $uid = (int)($_GET['user_id'] ?? 0);
    if (!$uid) jsonError('User ID required');

    $st = $db->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$uid]);
    $user = $st->fetch();
    if (!$user) jsonError('User not found', 404);

    // Get their docs
    $docs = $db->prepare('SELECT * FROM verification_docs WHERE user_id = ? ORDER BY uploaded_at DESC');
    $docs->execute([$uid]);

    // Get their listings
    $listings = $db->prepare("SELECT id,title,category,status,created_at,price,currency FROM listings WHERE user_id=? AND status!='deleted' ORDER BY created_at DESC LIMIT 20");
    $listings->execute([$uid]);

    jsonSuccess([
        'user'     => formatAdminUser($user),
        'docs'     => $docs->fetchAll(),
        'listings' => $listings->fetchAll(),
    ]);
}

function handleBanUser(): void {
    global $uid, $db;
    $data   = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($data['user_id'] ?? 0);
    $ban    = (bool)($data['ban'] ?? true);
    $reason = trim($data['reason'] ?? '');

    if (!$userId) jsonError('User ID required');
    if ($ban && !$reason) jsonError('Ban reason required');

    // Invalidate existing tokens on ban
    if ($ban) {
        $db->prepare('UPDATE users SET is_banned=1, ban_reason=?, token_invalidated_at=NOW() WHERE id=?')->execute([$reason, $userId]);
    } else {
        $db->prepare('UPDATE users SET is_banned=0, ban_reason=NULL, token_invalidated_at=NULL WHERE id=?')->execute([$userId]);
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    logAction($uid, $ban?'ban':'unban', 'user', $userId, "$reason | IP: $ip");
    jsonSuccess(['message' => $ban ? "User banned: $reason" : 'User unbanned']);
}

function handleVerifyUser(): void {
    global $uid, $db;
    $data   = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($data['user_id'] ?? 0);
    $action = $data['action'] ?? ''; // approve | reject
    $note   = trim($data['note'] ?? '');
    $badge  = $data['badge'] ?? 'verified'; // verified | premium

    if (!$userId || !in_array($action, ['approve','reject'])) jsonError('Invalid parameters');

    if ($action === 'approve') {
        $db->prepare('UPDATE users SET is_verified=1, verification_status="approved", verification_note=?, badge_level=? WHERE id=?')
           ->execute([$note, $badge, $userId]);
    } else {
        $db->prepare('UPDATE users SET verified=0, verification_status="rejected", verification_note=? WHERE id=?')
           ->execute([$note ?: 'Documents not accepted. Please resubmit.', $userId]);
    }
    // Get user phone for WhatsApp notification link
    $st2 = $db->prepare('SELECT phone, display_name FROM users WHERE id = ?');
    $st2->execute([$userId]);
    $notifyUser = $st2->fetch();
    $waPhone = preg_replace('/[^0-9]/', '', $notifyUser['phone'] ?? '');
    $waName  = $notifyUser['display_name'] ?? 'User';

    $waMsg = $action === 'approve'
        ? urlencode("Hello $waName, your SomBazar account has been verified! You can now post listings with a verified badge.")
        : urlencode("Hello $waName, your SomBazar verification was not approved. Reason: $note. Please resubmit at: http://localhost:8080/sombazar-fixed/verify.html");

    $waLink = $waPhone ? "https://wa.me/$waPhone?text=$waMsg" : null;

    logAction($uid, "verify_$action", 'verification', $userId, $note);

    // In-app bildirim
    try {
        if ($action === 'approve') {
            $db->prepare("INSERT INTO notifications (user_id,type,title,body,link,icon) VALUES (?,?,?,?,?,?)")
               ->execute([$userId, 'system', 'Account verified! ✅',
                   'Your identity has been verified. You now have a verified badge on your profile.',
                   'profile.html', '']);
        } else {
            $db->prepare("INSERT INTO notifications (user_id,type,title,body,link,icon) VALUES (?,?,?,?,?,?)")
               ->execute([$userId, 'system', 'Verification not approved',
                   $note ?: 'Your documents were not accepted. Please resubmit.',
                   'verify.html', '']);
        }
    } catch(\Throwable $e) {}

    jsonSuccess([
        'message'   => "User verification $action" . 'd',
        'waLink'    => $waLink,
        'waName'    => $waName,
    ]);
}

function handleListings(): void {
    $db     = getDB();
    $status = $_GET['status'] ?? 'all';
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $search = $_GET['q'] ?? '';
    $limit  = 20;
    $offset = ($page-1)*$limit;

    $where  = [];
    $params = [];
    if ($status !== 'all') { $where[] = 'l.status = ?'; $params[] = $status; }
    if ($search) { $where[] = '(l.title LIKE ? OR u.display_name LIKE ?)'; $s="%$search%"; $params=array_merge($params,[$s,$s]); }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $params[] = $limit; $params[] = $offset;

    $st = $db->prepare(
        "SELECT l.*, u.display_name AS seller_name, u.email AS seller_email, u.verified AS seller_verified
         FROM listings l JOIN users u ON l.user_id=u.id $whereSQL ORDER BY l.created_at DESC LIMIT ? OFFSET ?"
    );
    $st->execute($params);

    $total = $db->prepare("SELECT COUNT(*) FROM listings l JOIN users u ON l.user_id=u.id $whereSQL");
    $total->execute(array_slice($params, 0, -2));

    jsonSuccess([
        'listings' => array_map(fn($r) => [
            'id'            => $r['id'],
            'title'         => $r['title'],
            'category'      => $r['category'],
            'price'         => $r['price'],
            'currency'      => $r['currency'],
            'city'          => $r['city'],
            'status'        => $r['status'],
            'seller'        => $r['seller_name'],
            'sellerEmail'   => $r['seller_email'],
            'sellerVerified'=> (bool)$r['seller_verified'],
            'createdAt'     => $r['created_at'],
            'views'         => $r['views'],
            'reportCount'   => (int)($r['report_count'] ?? 0),
            'images'        => json_decode($r['images']??'[]', true),
        ], $st->fetchAll()),
        'total' => (int)$total->fetchColumn(),
    ]);
}

function handleApproveListing(): void {
    global $uid, $db;
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($data['listing_id'] ?? 0);
    if (!$id) jsonError('Listing ID required');
    $db->prepare("UPDATE listings SET status='active' WHERE id=?")->execute([$id]);
    logAction($uid, 'approve_listing', 'listing', $id);

    try {
        $lst = $db->prepare('SELECT l.title, l.user_id, u.email, u.display_name FROM listings l JOIN users u ON l.user_id=u.id WHERE l.id=?');
        $lst->execute([$id]);
        $row = $lst->fetch();
        if ($row) {
            $url = SITE_URL . '/listing.html?id=' . $id;
            Mailer::sendListingApproved($row['email'], $row['display_name'], $row['title'], $url);
            // In-app bildirim
            $db->prepare("INSERT INTO notifications (user_id,type,title,body,link,icon) VALUES (?,?,?,?,?,?)")
               ->execute([$row['user_id'], 'listing', 'Your listing is live! 🎉',
                   '"' . $row['title'] . '" has been approved and is now active.',
                   'listing.html?id=' . $id, '']);
        }
    } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }

    jsonSuccess(['message' => 'Listing approved']);
}

function handleRejectListing(): void {
    global $uid, $db;
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($data['listing_id'] ?? 0);
    $note = trim($data['note'] ?? $data['reason'] ?? 'Does not meet our listing guidelines.');
    if (!$id) jsonError('Listing ID required');
    $db->prepare("UPDATE listings SET status='rejected' WHERE id=?")->execute([$id]);
    logAction($uid, 'reject_listing', 'listing', $id, $note);

    try {
        $lst = $db->prepare('SELECT l.title, l.user_id, u.email, u.display_name FROM listings l JOIN users u ON l.user_id=u.id WHERE l.id=?');
        $lst->execute([$id]);
        $row = $lst->fetch();
        if ($row) {
            Mailer::sendListingRejected($row['email'], $row['display_name'], $row['title'], $note);
            // In-app bildirim
            $db->prepare("INSERT INTO notifications (user_id,type,title,body,link,icon) VALUES (?,?,?,?,?,?)")
               ->execute([$row['user_id'], 'listing', 'Listing not approved',
                   '"' . $row['title'] . '" was rejected: ' . $note,
                   'post.html', '']);
        }
    } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }

    jsonSuccess(['message' => 'Listing rejected']);
}

function handleDeleteListing(): void {
    global $uid, $db;
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($data['listing_id'] ?? 0);
    if (!$id) jsonError('Listing ID required');
    $db->prepare("UPDATE listings SET status='deleted' WHERE id=?")->execute([$id]);
    logAction($uid, 'delete_listing', 'listing', $id);
    jsonSuccess(['message' => 'Listing deleted']);
}

function handleVerifications(): void {
    $db = getDB();
    // Try with superseded filter (migrate4 needed), fallback without
    try {
        $st = $db->prepare(
            "SELECT u.*, d.doc_type, d.file_url, d.uploaded_at as doc_uploaded
             FROM users u
             JOIN verification_docs d ON d.user_id = u.id
             WHERE u.verification_status = 'pending'
               AND d.superseded = 0
             ORDER BY u.id ASC, d.uploaded_at ASC"
        );
        $st->execute();
        $st->fetchAll(); // test query
        // Re-run properly
        $st = $db->prepare(
            "SELECT u.*, d.doc_type, d.file_url, d.uploaded_at as doc_uploaded
             FROM users u
             JOIN verification_docs d ON d.user_id = u.id
             WHERE u.verification_status = 'pending'
               AND d.superseded = 0
             ORDER BY u.id ASC, d.uploaded_at ASC"
        );
    } catch(\Exception $e) {
        // superseded column doesn't exist yet - fallback
        $st = $db->prepare(
            "SELECT u.*, d.doc_type, d.file_url, d.uploaded_at as doc_uploaded
             FROM users u
             JOIN verification_docs d ON d.user_id = u.id
             WHERE u.verification_status = 'pending'
             ORDER BY u.id ASC, d.uploaded_at ASC"
        );
    }
    $st->execute();
    $rows = $st->fetchAll();

    // Group by user
    $grouped = [];
    foreach ($rows as $r) {
        if (!isset($grouped[$r['id']])) {
            $grouped[$r['id']] = formatAdminUser($r);
            $grouped[$r['id']]['docs'] = [];
        }
        $grouped[$r['id']]['docs'][] = ['type' => $r['doc_type'], 'url' => $r['file_url'], 'uploadedAt' => $r['doc_uploaded']];
    }
    jsonSuccess(array_values($grouped));
}

function handleBlacklist(): void {
    $db = getDB();
    $st = $db->query('SELECT b.*, u.display_name AS added_by_name FROM blacklist b LEFT JOIN users u ON b.added_by=u.id ORDER BY b.created_at DESC LIMIT 100');
    jsonSuccess($st->fetchAll());
}

function handleAddBlacklist(): void {
    global $uid, $db;
    $data   = json_decode(file_get_contents('php://input'), true);
    $phone  = trim($data['phone'] ?? '');
    $natId  = trim($data['national_id'] ?? '');
    $reason = trim($data['reason'] ?? '');
    if (!$phone && !$natId) jsonError('Phone or national ID required');
    if (!$reason) jsonError('Reason required');
    $db->prepare('INSERT INTO blacklist (phone, national_id, reason, added_by) VALUES (?,?,?,?)')
       ->execute([$phone, $natId, $reason, $uid]);
    // Also ban matching users
    if ($phone) $db->prepare("UPDATE users SET is_banned=1, ban_reason=? WHERE phone=?")->execute(["Blacklisted: $reason", $phone]);
    logAction($uid, 'add_blacklist', 'user', 0, "phone:$phone nat_id:$natId reason:$reason");
    jsonSuccess(['message' => 'Added to blacklist']);
}

function handleRemoveBlacklist(): void {
    global $uid, $db;
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($data['id'] ?? 0);
    if (!$id) jsonError('ID required');
    $db->prepare('DELETE FROM blacklist WHERE id = ?')->execute([$id]);
    logAction($uid, 'remove_blacklist', 'blacklist', $id, '');
    jsonSuccess(['message' => 'Removed from blacklist']);
}

function handleLog(): void {
    $db = getDB();
    $st = $db->prepare(
        'SELECT l.*, u.display_name AS admin_name FROM admin_log l JOIN users u ON l.admin_id=u.id ORDER BY l.created_at DESC LIMIT 100'
    );
    $st->execute();
    jsonSuccess($st->fetchAll());
}

function formatAdminUser(array $u): array {
    return [
        'id'                 => $u['id'],
        'displayName'        => $u['display_name'],
        'email'              => $u['email'],
        'phone'              => $u['phone'],
        'city'               => $u['city'],
        'sellerType'         => $u['seller_type'] ?? 'individual',
        'verificationStatus' => $u['verification_status'] ?? 'none',
        'verificationNote'   => $u['verification_note'] ?? '',
        'badgeLevel'         => $u['badge_level'] ?? 'basic',
        'verified'           => (bool)$u['is_verified'],
        'banned'             => (bool)($u['is_banned'] ?? false),
        'banReason'          => $u['ban_reason'] ?? '',
        'blacklisted'        => (bool)($u['blacklisted'] ?? false),
        'isAdmin'            => (bool)($u['is_admin'] ?? false),
        'plan'               => $u['plan'] ?? 'free',
        'listingCount'       => (int)($u['listing_count'] ?? 0),
        'photoURL'           => ($u['photo_url'] ?? null) ? UPLOAD_URL . $u['photo_url'] : null,
        'createdAt'          => $u['created_at'],
        'lastSeen'           => $u['last_seen'] ?? null,
    ];
}

// ── PAYMENTS ────────────────────────────────────────────────────────────────

$PLANS_ADMIN = [
    'standard' => ['price' => 8,  'label' => 'Standard', 'listing_limit' => 10,  'photo_limit' => 5,  'boost_credits' => 0, 'days' => 30],
    'pro'      => ['price' => 20, 'label' => 'Pro',      'listing_limit' => 999, 'photo_limit' => 15, 'boost_credits' => 2, 'days' => 30],
    'agency'   => ['price' => 50, 'label' => 'Agency',   'listing_limit' => 999, 'photo_limit' => 20, 'boost_credits' => 5, 'days' => 30],
];

function handlePayments(): void {
    $db     = getDB();
    // Auto-create payments table if not exists
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            plan ENUM('standard','pro','agency') NOT NULL,
            amount DECIMAL(8,2) NOT NULL,
            method ENUM('zaad','edahab') NOT NULL,
            reference_code VARCHAR(100) NOT NULL,
            screenshot_url VARCHAR(500) NULL,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            admin_note VARCHAR(300) NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            idempotency_key VARCHAR(100) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
    } catch(\Exception $e) {}
    $status = $_GET['status'] ?? 'pending';
    $where  = $status !== 'all' ? "WHERE p.status = ?" : "WHERE 1";
    $params = $status !== 'all' ? [$status] : [];

    $st = $db->prepare(
        "SELECT p.*, u.display_name, u.email, u.phone
         FROM payments p JOIN users u ON p.user_id = u.id
         $where ORDER BY p.created_at DESC LIMIT 100"
    );
    $st->execute($params);
    $rows = $st->fetchAll();

    jsonSuccess(['payments' => array_map(fn($r) => [
        'id'             => $r['id'],
        'userId'         => $r['user_id'],
        'userName'       => $r['display_name'],
        'userEmail'      => $r['email'],
        'userPhone'      => $r['phone'],
        'plan'           => $r['plan'],
        'amount'         => $r['amount'],
        'method'         => $r['method'],
        'referenceCode'  => $r['reference_code'],
        'screenshotUrl'  => $r['screenshot_url'],
        'status'         => $r['status'],
        'adminNote'      => $r['admin_note'],
        'createdAt'      => $r['created_at'],
        'reviewedAt'     => $r['reviewed_at'],
        'couponCode'     => $r['coupon_code']     ?? null,
        'discountAmount' => $r['discount_amount'] ?? 0,
    ], $rows)]);
}

function handleExportCsv(): void {
    requireAuth(false); // admin check
    $type = $_GET['type'] ?? 'users';
    $db   = getDB();

    // Admin kontrolü
    $uid = getAuthUser();
    $check = $db->prepare('SELECT is_admin FROM users WHERE id=?');
    $check->execute([$uid]);
    $user = $check->fetch();
    if (!$user || !$user['is_admin']) jsonError('Forbidden', 403);

    // Content-Type override - CSV
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sombazar_' . $type . '_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');

    try {
        if ($type === 'users') {
            fputcsv($out, ['ID','Name','Email','Phone','City','Verified','Banned','Created']);
            $rows = $db->query("SELECT id,display_name,email,phone,city,verified,banned,created_at FROM users WHERE is_admin=0 ORDER BY id DESC")->fetchAll();
            foreach ($rows as $r) fputcsv($out, [$r['id'],$r['display_name'],$r['email'],$r['phone'],$r['city'],$r['verified'],$r['banned'],$r['created_at']]);
        } elseif ($type === 'listings') {
            fputcsv($out, ['ID','Title','Category','Price','Currency','City','Status','User','Created']);
            $rows = $db->query("SELECT l.id,l.title,l.category,l.price,l.currency,l.city,l.status,u.display_name,l.created_at FROM listings l JOIN users u ON l.user_id=u.id ORDER BY l.id DESC LIMIT 1000")->fetchAll();
            foreach ($rows as $r) fputcsv($out, [$r['id'],$r['title'],$r['category'],$r['price'],$r['currency'],$r['city'],$r['status'],$r['display_name'],$r['created_at']]);
        } elseif ($type === 'offers') {
            fputcsv($out, ['ID','Listing','Buyer','Seller','Amount','Currency','Round','Status','Created']);
            $rows = $db->query("SELECT o.id,l.title,b.display_name AS buyer,s.display_name AS seller,o.amount,o.currency,o.round,o.status,o.created_at FROM offers o JOIN listings l ON o.listing_id=l.id JOIN users b ON o.buyer_id=b.id JOIN users s ON o.seller_id=s.id ORDER BY o.id DESC LIMIT 1000")->fetchAll();
            foreach ($rows as $r) fputcsv($out, [$r['id'],$r['title'],$r['buyer'],$r['seller'],$r['amount'],$r['currency'],$r['round'],$r['status'],$r['created_at']]);
        }
    } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }

    fclose($out);
    exit();
}

function handleClean(): void {
    $uid  = requireAuth();
    $type = $_GET['type'] ?? 'offers';
    $db   = getDB();

    $check = $db->prepare('SELECT is_admin FROM users WHERE id=?');
    $check->execute([$uid]);
    $user = $check->fetch();
    if (!$user || !$user['is_admin']) jsonError('Forbidden', 403);

    $count = 0;
    try {
        if ($type === 'offers') {
            $st = $db->exec("UPDATE offers SET status='expired' WHERE status='pending' AND expires_at < NOW()");
            $count = $st;
        }
    } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }

    jsonSuccess(['message' => "Cleaned $count expired records ✅"]);
}

function handleAllOffers(): void {
    $db     = getDB();
    $status = $_GET['status'] ?? 'all';

    try {
        $db->exec("UPDATE offers SET status='expired' WHERE status='pending' AND expires_at < NOW()");

        $sql = "SELECT o.*,
                       l.title AS listing_title,
                       b.display_name AS buyer_name,
                       s.display_name AS seller_name
                FROM offers o
                JOIN listings l ON o.listing_id = l.id
                JOIN users b ON o.buyer_id  = b.id
                JOIN users s ON o.seller_id = s.id";

        $params = [];
        if ($status !== 'all') {
            $sql .= " WHERE o.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY o.created_at DESC LIMIT 200";

        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();

        $offers = array_map(function($o) {
            $expiresIn = null;
            if (in_array($o['status'], ['pending','countered']) && !empty($o['expires_at'])) {
                $diff = strtotime($o['expires_at']) - time();
                if ($diff > 0) {
                    $h = floor($diff / 3600); $m = floor(($diff % 3600) / 60);
                    $expiresIn = "{$h}h {$m}m remaining";
                } else { $expiresIn = 'Expired'; }
            }
            return [
                'id'            => (int)$o['id'],
                'listingId'     => (int)$o['listing_id'],
                'listingTitle'  => $o['listing_title'],
                'buyerName'     => $o['buyer_name'],
                'sellerName'    => $o['seller_name'],
                'round'         => (int)$o['round'],
                'amount'        => (float)$o['amount'],
                'currency'      => $o['currency'],
                'status'        => $o['status'],
                'counterAmount' => $o['counter_amount'] ? (float)$o['counter_amount'] : null,
                'expiresIn'     => $expiresIn,
                'createdAt'     => $o['created_at'],
            ];
        }, $rows);

        jsonSuccess(['offers' => $offers, 'total' => count($offers)]);
    } catch(\Throwable $e) {
        jsonSuccess(['offers' => [], 'total' => 0]);
    }
}

function handleApprovePayment(): void {
    global $uid;
    requireCsrf($uid);
    $PLANS_ADMIN = [
        'standard' => ['price' => 8,  'label' => 'Standard', 'listing_limit' => 10,  'photo_limit' => 5,  'boost_credits' => 0, 'days' => 30],
        'pro'      => ['price' => 20, 'label' => 'Pro',      'listing_limit' => 999, 'photo_limit' => 15, 'boost_credits' => 2, 'days' => 30],
        'agency'   => ['price' => 50, 'label' => 'Agency',   'listing_limit' => 999, 'photo_limit' => 20, 'boost_credits' => 5, 'days' => 30],
    ];
    try {
    $db   = getDB();

    // Tabloları otomatik oluştur
    try { $db->exec("ALTER TABLE users ADD COLUMN plan VARCHAR(20) DEFAULT 'free'"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
    try { $db->exec("ALTER TABLE users ADD COLUMN plan_expires_at DATETIME NULL"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
    try { $db->exec("CREATE TABLE IF NOT EXISTS packages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        plan VARCHAR(20) DEFAULT 'free',
        listing_limit INT DEFAULT 3,
        photo_limit INT DEFAULT 2,
        boost_credits INT DEFAULT 0,
        started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NULL,
        payment_id INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
    try { $db->exec("ALTER TABLE payments ADD COLUMN reviewed_by INT NULL"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
    try { $db->exec("ALTER TABLE payments ADD COLUMN reviewed_at DATETIME NULL"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
    try { $db->exec("ALTER TABLE payments ADD COLUMN admin_note VARCHAR(300) NULL"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $pid  = (int)($data['payment_id'] ?? 0);
    if (!$pid) jsonError('Missing payment_id');

    $st = $db->prepare('SELECT * FROM payments WHERE id = ?');
    $st->execute([$pid]);
    $pay = $st->fetch();
    if (!$pay) jsonError('Payment not found', 404);
    if ($pay['status'] !== 'pending') jsonError('Payment already reviewed');

    $plan = $pay['plan'];
    $plan_data = $PLANS_ADMIN[$plan] ?? null;
    if (!$plan_data) jsonError('Unknown plan');

    // Mark payment approved
    $db->prepare('UPDATE payments SET status="approved", reviewed_by=?, reviewed_at=NOW() WHERE id=?')
       ->execute([$uid, $pid]);

    // Activate plan on user
    $expires = date('Y-m-d H:i:s', strtotime('+' . $plan_data['days'] . ' days'));
    $db->prepare('UPDATE users SET plan=?, plan_expires_at=? WHERE id=?')
       ->execute([$plan, $expires, $pay['user_id']]);

    // Create/update package record - upsert by user_id
    $existPkg = $db->prepare('SELECT id FROM packages WHERE user_id = ?');
    $existPkg->execute([$pay['user_id']]);
    if ($existPkg->fetch()) {
        $db->prepare('UPDATE packages SET plan=?, listing_limit=?, photo_limit=?, boost_credits=?, started_at=NOW(), expires_at=?, payment_id=? WHERE user_id=?')
           ->execute([$plan, $plan_data['listing_limit'], $plan_data['photo_limit'], $plan_data['boost_credits'], $expires, $pid, $pay['user_id']]);
    } else {
        $db->prepare('INSERT INTO packages (user_id, plan, listing_limit, photo_limit, boost_credits, started_at, expires_at, payment_id) VALUES (?,?,?,?,?,NOW(),?,?)')
           ->execute([$pay['user_id'], $plan, $plan_data['listing_limit'], $plan_data['photo_limit'], $plan_data['boost_credits'], $expires, $pid]);
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    logAction($uid, 'approve_payment', 'user', $pay['user_id'], "Plan: $plan, Amount: $" . $pay['amount'] . " | IP: $ip");

    // Affiliate komisyon hesapla ve ekle
    try {
        if (!empty($pay['affiliate_id'])) {
            $affSt = $db->prepare("SELECT id, commission_rate FROM affiliates WHERE id = ?");
            $affSt->execute([$pay['affiliate_id']]);
            $aff = $affSt->fetch();
            if ($aff) {
                $commission = round((float)$pay['amount'] * ((float)$aff['commission_rate'] / 100), 2);
                $db->prepare("UPDATE affiliates SET total_earned = total_earned + ?, pending_payout = pending_payout + ? WHERE id = ?")
                   ->execute([$commission, $commission, $aff['id']]);
                logAction($uid, 'affiliate_commission', 'affiliates', $aff['id'], "Commission \$$commission for payment #$pid");
            }
        }
    } catch(\Throwable $e) { error_log("admin.php affiliate commission error: " . $e->getMessage()); }

    // Email bildirimi
    try {
        $ust = $db->prepare('SELECT email, display_name FROM users WHERE id=?');
        $ust->execute([$pay['user_id']]);
        $usr = $ust->fetch();
        if ($usr) Mailer::sendPaymentApproved($usr['email'], $usr['display_name'], $plan, date('M j, Y', strtotime($expires)));
    } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }

    // In-app bildirim
    try {
        $db->prepare("INSERT INTO notifications (user_id,type,title,body,link,icon) VALUES (?,?,?,?,?,?)")
           ->execute([$pay['user_id'], 'system', "Your $plan plan is now active! 🚀",
               "Payment confirmed. Your plan is active until " . date('M j, Y', strtotime($expires)) . ".",
               'packages.html', '']);
    } catch(\Throwable $e) {}

    jsonSuccess(['message' => 'Payment approved, plan activated.', 'plan' => $plan, 'expires_at' => $expires]);
    } catch(Throwable $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'Payment approval failed. Please try again.']);
        exit();
    }
}

function handleRejectPayment(): void {
    global $uid;
    requireCsrf($uid);
    $db   = getDB();
    try { $db->exec("ALTER TABLE payments ADD COLUMN reviewed_by INT NULL"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
    try { $db->exec("ALTER TABLE payments ADD COLUMN reviewed_at DATETIME NULL"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }
    try { $db->exec("ALTER TABLE payments ADD COLUMN admin_note VARCHAR(300) NULL"); } catch(\Throwable $e) { error_log("admin.php error: " . $e->getMessage()); }

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $pid  = (int)($data['payment_id'] ?? 0);
    $note = trim($data['note'] ?? '');
    if (!$pid) jsonError('Missing payment_id');

    $st = $db->prepare('SELECT * FROM payments WHERE id = ?');
    $st->execute([$pid]);
    $pay = $st->fetch();
    if (!$pay) jsonError('Payment not found', 404);
    if ($pay['status'] !== 'pending') jsonError('Payment already reviewed');

    $db->prepare('UPDATE payments SET status="rejected", admin_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?')
       ->execute([$note, $uid, $pid]);

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    logAction($uid, 'reject_payment', 'user', $pay['user_id'], "Reason: $note | IP: $ip");

    // In-app bildirim
    try {
        $db->prepare("INSERT INTO notifications (user_id,type,title,body,link,icon) VALUES (?,?,?,?,?,?)")
           ->execute([$pay['user_id'], 'system', 'Payment could not be verified',
               $note ?: 'Your payment was not approved. Please contact support or try again.',
               'packages.html', '']);
    } catch(\Throwable $e) {}

    jsonSuccess(['message' => 'Payment rejected.']);
}

function handleGetCsrfToken(): void {
    global $uid;
    $token = generateCsrfToken($uid);
    jsonSuccess(['csrf_token' => $token]);
}

// ── Kupon Yönetimi ───────────────────────────────────────────
function handleListCoupons(): void {
    requireAdmin();
    $db = getDB();
    try {
        $st = $db->query("SELECT * FROM discount_codes ORDER BY created_at DESC");
        jsonSuccess(['coupons' => $st->fetchAll()]);
    } catch(\Throwable $e) { jsonSuccess(['coupons' => []]); }
}

function handleCreateCoupon(): void {
    requireAdmin();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $code  = strtoupper(trim($data['code']     ?? ''));
    $type  = $data['type']     ?? 'percent';
    $value = (float)($data['value']    ?? 0);
    $maxUses  = (int)($data['max_uses'] ?? 0);
    $expires  = !empty($data['expires_at']) ? $data['expires_at'] : null;
    $minPlan  = !empty($data['min_plan'])   ? $data['min_plan']   : null;

    if (!$code || !$value) jsonError('Code and value required');
    if (!in_array($type, ['percent','fixed'])) jsonError('Invalid type');
    if ($type === 'percent' && $value > 100)   jsonError('Percent cannot exceed 100');

    try {
        $db = getDB();
        $db->prepare("INSERT INTO discount_codes (code,type,value,max_uses,expires_at,min_plan) VALUES (?,?,?,?,?,?)")
           ->execute([$code, $type, $value, $maxUses, $expires, $minPlan]);
        jsonSuccess(['message' => "Coupon {$code} created", 'id' => $db->lastInsertId()]);
    } catch(\Throwable $e) {
        if (str_contains($e->getMessage(),'Duplicate')) jsonError('Coupon code already exists');
        jsonError('Database error');
    }
}

function handleToggleCoupon(): void {
    requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('Missing id');
    $db = getDB();
    $db->prepare("UPDATE discount_codes SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    jsonSuccess(['message' => 'Toggled']);
}

function handleDeleteCoupon(): void {
    requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('Missing id');
    getDB()->prepare("DELETE FROM discount_codes WHERE id = ?")->execute([$id]);
    jsonSuccess(['message' => 'Deleted']);
}

// ── Affiliate Sistemi ────────────────────────────────────────
function handleListAffiliates(): void {
    requireAdmin();
    $db = getDB();
    try {
        $st = $db->query("
            SELECT a.*, u.display_name, u.email
            FROM affiliates a
            JOIN users u ON u.id = a.user_id
            ORDER BY a.total_earned DESC
        ");
        jsonSuccess(['affiliates' => $st->fetchAll()]);
    } catch(\Throwable $e) { jsonSuccess(['affiliates' => []]); }
}

function handleAffiliateApprove(): void {
    requireAdmin();
    $data   = json_decode(file_get_contents('php://input'), true) ?? [];
    $userId = (int)($data['user_id'] ?? 0);
    if (!$userId) jsonError('user_id required');

    $db = getDB();
    // Rastgele ref kodu oluştur
    $refCode = 'SB' . strtoupper(substr(md5($userId . time()), 0, 6));
    try {
        $db->prepare("INSERT IGNORE INTO affiliates (user_id, ref_code) VALUES (?,?)")
           ->execute([$userId, $refCode]);
        $db->prepare("UPDATE users SET ref_code = ? WHERE id = ?")
           ->execute([$refCode, $userId]);
        jsonSuccess(['ref_code' => $refCode, 'message' => "Affiliate approved with code {$refCode}"]);
    } catch(\Throwable $e) { jsonError('Database error'); }
}

function handleAffiliatePayout(): void {
    requireAdmin();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $affiliateId = (int)($data['affiliate_id'] ?? 0);
    $amount      = (float)($data['amount']      ?? 0);
    if (!$affiliateId || $amount <= 0) jsonError('affiliate_id and amount required');

    $db = getDB();
    try {
        $db->prepare("UPDATE affiliates SET pending_payout = GREATEST(0, pending_payout - ?), total_earned = total_earned WHERE id = ?")
           ->execute([$amount, $affiliateId]);
        jsonSuccess(['message' => "Payout of \${$amount} recorded"]);
    } catch(\Throwable $e) { jsonError('Database error'); }
}

// ── requireAdmin helper ──────────────────────────────────────
function requireAdmin(): void {
    global $uid, $db;
    // $uid and $db are already set at the top of the file after requireAuth()
    // Just double-check the admin flag
    $st = $db->prepare('SELECT is_admin FROM users WHERE id = ?');
    $st->execute([$uid]);
    $me = $st->fetch();
    if (!$me || !$me['is_admin']) jsonError('Forbidden — Admins only', 403);
}

// ── handleToggleAffiliate ────────────────────────────────────
function handleToggleAffiliate(): void {
    requireAdmin();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($_GET['id'] ?? $data['id'] ?? $data['affiliate_id'] ?? 0);
    if (!$id) jsonError('Missing affiliate id');
    $db = getDB();
    try {
        $db->prepare("UPDATE affiliates SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
        jsonSuccess(['message' => 'Affiliate toggled']);
    } catch(\Throwable $e) { jsonError('Database error'); }
}

// ── handleCouponStats ────────────────────────────────────────
function handleCouponStats(): void {
    requireAdmin();
    $db = getDB();
    $couponId = (int)($_GET['coupon_id'] ?? 0);

    try {
        if ($couponId) {
            // Per-coupon stats
            $st = $db->prepare("SELECT id, code, uses_count, value, type FROM discount_codes WHERE id = ?");
            $st->execute([$couponId]);
            $coupon = $st->fetch();
            if (!$coupon) jsonError('Coupon not found', 404);

            // Recent payments that used this coupon
            $payments = $db->prepare(
                "SELECT p.id, p.amount, p.created_at, u.display_name, u.email,
                        (CASE WHEN dc.type='percent' THEN ROUND(p.amount * dc.value/100, 2) ELSE dc.value END) as discount_amount
                 FROM payments p
                 JOIN users u ON u.id = p.user_id
                 JOIN discount_codes dc ON dc.id = ?
                 WHERE p.coupon_code = dc.code AND p.status = 'approved'
                 ORDER BY p.created_at DESC LIMIT 10"
            );
            $payments->execute([$couponId]);
            $recent = $payments->fetchAll();

            $totalDiscount = array_sum(array_column($recent, 'discount_amount'));

            jsonSuccess([
                'total_uses'     => (int)$coupon['uses_count'],
                'total_discount' => round($totalDiscount, 2),
                'recent_uses'    => $recent,
            ]);
        } else {
            // Global stats
            $total  = (int)$db->query("SELECT COUNT(*) FROM discount_codes")->fetchColumn();
            $active = (int)$db->query("SELECT COUNT(*) FROM discount_codes WHERE is_active = 1")->fetchColumn();
            $used   = (int)$db->query("SELECT COALESCE(SUM(uses_count),0) FROM discount_codes")->fetchColumn();
            $top    = $db->query("SELECT code, uses_count, value, type FROM discount_codes ORDER BY uses_count DESC LIMIT 5")->fetchAll();
            jsonSuccess([
                'total_coupons'  => $total,
                'active_coupons' => $active,
                'total_uses'     => $used,
                'top_coupons'    => $top,
            ]);
        }
    } catch(\Throwable $e) {
        jsonSuccess(['total_uses' => 0, 'total_discount' => 0, 'recent_uses' => []]);
    }
}

// ── handleGetReports ──────────────────────────────────────────
function handleGetReports(): void {
    requireAdmin();
    $db = getDB();
    try {
        // note sütunu yoksa ekle
        try { $db->exec("ALTER TABLE reports ADD COLUMN note TEXT NULL"); } catch(\Throwable $e) {}

        $resolved = (int)($_GET['resolved'] ?? 0);
        $st = $db->prepare(
            "SELECT r.id, r.reason, r.note, r.resolved, r.created_at,
                    l.id as listing_id, l.title as listing_title,
                    reporter.id as reporter_id, reporter.display_name as reporter_name, reporter.email as reporter_email,
                    owner.id as owner_id, owner.display_name as owner_name, owner.email as owner_email
             FROM reports r
             JOIN listings l ON l.id = r.listing_id
             JOIN users reporter ON reporter.id = r.reporter_id
             JOIN users owner    ON owner.id    = l.user_id
             WHERE r.resolved = ?
             ORDER BY r.created_at DESC
             LIMIT 100"
        );
        $st->execute([$resolved]);
        $reports = $st->fetchAll();
        $total_unresolved = (int)$db->query("SELECT COUNT(*) FROM reports WHERE resolved=0")->fetchColumn();
        jsonSuccess(['reports' => $reports, 'total_unresolved' => $total_unresolved]);
    } catch(\Throwable $e) {
        jsonError($e->getMessage());
    }
}

// ── handleResolveReport ───────────────────────────────────────
function handleResolveReport(): void {
    requireAdmin();
    $adminId = requireAdmin();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $reportId = (int)($data['report_id'] ?? 0);
    $action   = $data['action'] ?? 'resolve'; // resolve | delete_listing
    if (!$reportId) jsonError('report_id required');
    $db = getDB();

    // Raporu çöz
    $db->prepare("UPDATE reports SET resolved=1 WHERE id=?")->execute([$reportId]);

    if ($action === 'delete_listing') {
        // İlgili listing'i de sil
        $r = $db->prepare("SELECT listing_id FROM reports WHERE id=?");
        $r->execute([$reportId]);
        $row = $r->fetch();
        if ($row) {
            $db->prepare("UPDATE listings SET status='deleted' WHERE id=?")->execute([$row['listing_id']]);
            logAction($adminId, 'delete_listing_via_report', 'listing', (int)$row['listing_id'], 'Deleted via report #'.$reportId);
        }
    }

    logAction($adminId, 'resolve_report', 'report', $reportId, 'action='.$action);
    jsonSuccess(['message' => 'Report resolved']);
}
