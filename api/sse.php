<?php
// SomBazar — Server-Sent Events for real-time messages
require_once __DIR__ . '/config.php';

// SSE için JSON header'ı override et
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // Nginx buffering'i kapat
header('Connection: keep-alive');

$user = getAuthUser();
if (!$user) {
    echo "event: error\ndata: {\"msg\":\"Unauthorized\"}\n\n";
    flush();
    exit;
}

$userId = $user['id'];
$lastId = intval($_GET['lastId'] ?? 0);

// 30 saniye max (Railway timeout'u aşmamak için)
$maxTime = 28;
$start   = time();

// Disable output buffering
if (ob_get_level()) ob_end_flush();

while ((time() - $start) < $maxTime) {
    // Yeni mesajları kontrol et
    $stmt = $pdo->prepare("
        SELECT m.id, m.conversationId, m.senderId, m.content, m.createdAt,
               u.name as senderName, u.avatar_url as senderAvatar
        FROM messages m
        JOIN users u ON u.id = m.senderId
        JOIN conversations c ON c.id = m.conversationId
        WHERE (c.buyerId = ? OR c.sellerId = ?)
          AND m.senderId != ?
          AND m.id > ?
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
                'conversationId' => (int)$msg['conversationId'],
                'senderId'       => (int)$msg['senderId'],
                'senderName'     => $msg['senderName'],
                'senderAvatar'   => $msg['senderAvatar'],
                'content'        => $msg['content'],
                'createdAt'      => $msg['createdAt'],
            ]);
            echo "id: {$msg['id']}\n";
            echo "event: message\n";
            echo "data: {$data}\n\n";
        }
        flush();
    }

    // Heartbeat - bağlantıyı canlı tut
    echo ": heartbeat\n\n";
    flush();

    if (connection_aborted()) break;
    sleep(1);
}

// Reconnect yönlendirmesi
echo "event: reconnect\ndata: {}\n\n";
flush();
