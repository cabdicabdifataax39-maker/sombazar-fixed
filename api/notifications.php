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
autoMigrateNotifications();

switch ($action) {
    case 'list':         handleList();        break;
    case 'unread_count': handleUnreadCount(); break;
    case 'mark_read':    handleMarkRead();    break;
    case 'mark_all_read':handleMarkAllRead(); break;
    case 'push_subscribe': handlePushSubscribe(); break;
    default: jsonError('Unknown action');
}

function autoMigrateNotifications(): void {
    $db = getDB();
    try { $db->exec("ALTER TABLE conversations ADD COLUMN unread_count_1 INT DEFAULT 0"); } catch(\Throwable $e) {}
    try { $db->exec("ALTER TABLE conversations ADD COLUMN unread_count_2 INT DEFAULT 0"); } catch(\Throwable $e) {}
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS notifications (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            type       VARCHAR(30) NOT NULL,
            title      VARCHAR(200) NOT NULL,
            body       TEXT NULL,
            link       VARCHAR(300) NULL,
            icon       VARCHAR(10) DEFAULT '🔔',
            is_read    TINYINT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_read (user_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(\Throwable $e) {}
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            endpoint   TEXT NOT NULL,
            p256dh     TEXT NOT NULL,
            auth       VARCHAR(100) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_endpoint (user_id, endpoint(100))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(\Throwable $e) {}
}

function handleList(): void {
    $uid   = requireAuth();
    $limit = min((int)($_GET['limit'] ?? 30), 100);
    $db    = getDB();
    try {
        $st = $db->prepare(
            "SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ?"
        );
        $st->execute([$uid, $limit]);
        $rows = $st->fetchAll();
        jsonSuccess(['notifications' => array_map('fmtNotif', $rows)]);
    } catch(\Throwable $e) {
        jsonSuccess(['notifications' => []]);
    }
}

function handleUnreadCount(): void {
    $uid = getAuthUser(); // Soft auth - token yoksa 0 döndür
    if (!$uid) {
        jsonSuccess(['total'=>0,'messages'=>0,'offers'=>0,'reviews'=>0,'unread'=>0]);
        return;
    }
    $db  = getDB();
    try {
        $st = $db->prepare(
            "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN type='message' THEN 1 ELSE 0 END) AS messages,
                SUM(CASE WHEN type LIKE 'offer%' THEN 1 ELSE 0 END) AS offers,
                SUM(CASE WHEN type='review' THEN 1 ELSE 0 END) AS reviews
             FROM notifications WHERE user_id=? AND is_read=0"
        );
        $st->execute([$uid]);
        $row = $st->fetch();

        // Mesaj unread count da ekle
        $msgSt = $db->prepare(
            "SELECT COALESCE(SUM(CASE WHEN user1_id=? THEN unread_count_1 ELSE unread_count_2 END),0) AS unread
             FROM conversations WHERE user1_id=? OR user2_id=?"
        );
        $msgSt->execute([$uid, $uid, $uid]);
        $msgRow = $msgSt->fetch();

        jsonSuccess([
            'total'    => (int)$row['total'],
            'messages' => (int)($msgRow['unread'] ?? 0),
            'offers'   => (int)$row['offers'],
            'reviews'  => (int)$row['reviews'],
            'unread'   => (int)$row['total'],
        ]);
    } catch(\Throwable $e) {
        jsonSuccess(['total'=>0,'messages'=>0,'offers'=>0,'reviews'=>0,'unread'=>0]);
    }
}

function handleMarkRead(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $nid  = (int)($data['notification_id'] ?? 0);
    $db   = getDB();
    if ($nid) {
        $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$nid, $uid]);
    }
    jsonSuccess(['message' => 'Marked as read']);
}

function handleMarkAllRead(): void {
    $uid = requireAuth();
    $db  = getDB();
    $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);
    jsonSuccess(['message' => 'All marked as read']);
}

function handlePushSubscribe(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $endpoint = $data['endpoint'] ?? '';
    $p256dh   = $data['keys']['p256dh'] ?? '';
    $auth     = $data['keys']['auth']   ?? '';
    if (!$endpoint || !$p256dh || !$auth) jsonError('Invalid subscription data');
    $db = getDB();
    try {
        $db->prepare(
            "INSERT INTO push_subscriptions (user_id,endpoint,p256dh,auth)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE p256dh=VALUES(p256dh), auth=VALUES(auth)"
        )->execute([$uid, $endpoint, $p256dh, $auth]);
        jsonSuccess(['message' => 'Push subscription saved']);
    } catch(\Throwable $e) {
        jsonError('Failed to save subscription');
    }
}

// Bildirim oluştur (diğer PHP dosyalarından çağrılır)
function createNotification(int $userId, string $type, string $title, string $body = '', string $link = '', string $icon = '🔔'): void {
    try {
        $db = getDB();
        $db->prepare(
            "INSERT INTO notifications (user_id,type,title,body,link,icon) VALUES (?,?,?,?,?,?)"
        )->execute([$userId, $type, $title, $body, $link, $icon]);
    } catch(\Throwable $e) {}
}

function fmtNotif(array $n): array {
    return [
        'id'        => (int)$n['id'],
        'type'      => $n['type'],
        'title'     => $n['title'],
        'body'      => $n['body'],
        'link'      => $n['link'],
        'icon'      => $n['icon'],
        'isRead'    => (bool)$n['is_read'],
        'createdAt' => $n['created_at'],
        'timeAgo'   => timeAgo($n['created_at']),
    ];
}

function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff/60)   . 'm ago';
    if ($diff < 86400) return floor($diff/3600)  . 'h ago';
    return floor($diff/86400) . 'd ago';
}
