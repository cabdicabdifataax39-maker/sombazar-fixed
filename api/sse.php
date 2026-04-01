<?php
// SomBazar — Server-Sent Events (real-time messages)
require_once __DIR__ . '/config.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

// getAuthUser() returns int|null — NOT array
$userId = getAuthUser();
if (!$userId) {
    echo "event: error\ndata: {\"msg\":\"Unauthorized\"}\n\n";
    flush();
    exit;
}

$lastId = (int)($_GET['lastId'] ?? 0);
$maxTime = 25;
$start   = time();

if (ob_get_level()) ob_end_flush();

while ((time() - $start) < $maxTime) {

    $db = getDB(); // get fresh connection each loop iteration

    // Schema: conversations (user1_id, user2_id), messages (text, read_at)
    $stmt = $db->prepare("
        SELECT m.id,
               m.conversation_id,
               m.sender_id,
               m.text AS body,
               m.created_at,
               u.display_name AS sender_name,
               COALESCE(u.avatar_url, u.photo_url) AS sender_avatar
        FROM messages m
        JOIN users u         ON u.id = m.sender_id
        JOIN conversations c ON c.id = m.conversation_id
        WHERE (c.user1_id = ? OR c.user2_id = ?)
          AND m.sender_id != ?
          AND m.id > ?
          AND m.deleted_at IS NULL
        ORDER BY m.id ASC
        LIMIT 20
    ");
    $stmt->execute([$userId, $userId, $userId, $lastId]);
    $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($msgs) {
        foreach ($msgs as $msg) {
            $lastId = $msg['id'];
            $data = json_encode([
                'id'             => (int)$msg['id'],
                'conversationId' => (int)$msg['conversation_id'],
                'senderId'       => (int)$msg['sender_id'],
                'senderName'     => $msg['sender_name'],
                'senderAvatar'   => $msg['sender_avatar'],
                'content'        => $msg['body'],
                'createdAt'      => $msg['created_at'],
            ]);
            echo "id: {$msg['id']}\n";
            echo "event: message\n";
            echo "data: {$data}\n\n";
        }
        flush();
    }

    // Also check for new notifications
    $nStmt = $db->prepare("
        SELECT id, type, title, body, link
        FROM notifications
        WHERE user_id = ? AND is_read = 0
        ORDER BY id DESC
        LIMIT 5
    ");
    $nStmt->execute([$userId]);
    $notifs = $nStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($notifs) {
        foreach ($notifs as $notif) {
            echo "event: notification\n";
            echo "data: " . json_encode($notif) . "\n\n";
        }
        flush();
    }

    echo ": heartbeat\n\n";
    flush();

    if (connection_aborted()) break;
    sleep(1);
}

echo "event: reconnect\ndata: {}\n\n";
flush();
