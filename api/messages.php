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

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'conversations';

ensureMessageTables(); // Auto-create/migrate tables on every request

switch ($action) {
    case 'conversations':  handleConversations(); break;
    case 'messages':       handleMessages();      break;
    case 'send':           if ($method!=='POST') jsonError('Method not allowed',405); handleSend();         break;
    case 'get_or_create':  if ($method!=='POST') jsonError('Method not allowed',405); handleGetOrCreate();  break;
    case 'typing':         if ($method!=='POST') jsonError('Method not allowed',405); handleTyping();       break;
    case 'mark_read':      if ($method!=='POST') jsonError('Method not allowed',405); handleMarkRead();     break;
    case 'delete_message': if ($method!=='POST') jsonError('Method not allowed',405); handleDeleteMessage();break;
    case 'unread_count':   handleUnreadCount();   break;
    case 'archive':        if ($method!=='POST') jsonError('Method not allowed',405); handleArchive(); break;
    default: jsonError('Unknown action', 404);
}

function ensureMessageTables(): void {
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS conversations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            listing_id INT NULL,
            user1_id INT NOT NULL,
            user2_id INT NOT NULL,
            last_message TEXT NULL,
            last_message_at DATETIME NULL,
            unread_count_1 INT DEFAULT 0,
            unread_count_2 INT DEFAULT 0,
            user1_typing_at DATETIME NULL,
            user2_typing_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_users (user1_id, user2_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT NOT NULL,
            sender_id INT NOT NULL,
            text TEXT NULL,
            image_url VARCHAR(500) NULL,
            read_at DATETIME NULL,
            deleted_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_conv (conversation_id),
            INDEX idx_sender (sender_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(\Throwable $e) {}
}

function handleConversations(): void {
    $uid = requireAuth();
    $db  = getDB();

    $st = $db->prepare(
        'SELECT c.*,
                u1.display_name AS user1_name, u1.photo_url AS user1_photo,
                u2.display_name AS user2_name, u2.photo_url AS user2_photo,
                l.title AS listing_title, l.price AS listing_price, l.currency AS listing_currency
         FROM conversations c
         JOIN users u1 ON c.user1_id = u1.id
         JOIN users u2 ON c.user2_id = u2.id
         LEFT JOIN listings l ON c.listing_id = l.id
         WHERE c.user1_id = ? OR c.user2_id = ?
         ORDER BY c.last_message_at DESC'
    );
    $st->execute([$uid, $uid]);

    jsonSuccess(array_map(function($r) use ($uid) {
        $isUser1   = $r['user1_id'] == $uid;
        $otherId   = $isUser1 ? $r['user2_id']    : $r['user1_id'];
        $otherName = $isUser1 ? $r['user2_name']  : $r['user1_name'];
        $otherPhoto= $isUser1 ? $r['user2_photo'] : $r['user1_photo'];
        $unread    = $isUser1 ? $r['unread_count_1'] : $r['unread_count_2'];
        // Other person typing?
        $otherTypingAt = $isUser1 ? $r['user2_typing_at'] : $r['user1_typing_at'];
        $isTyping  = $otherTypingAt && (strtotime($otherTypingAt) > time() - 5);

        return [
            'id'             => $r['id'],
            'otherUserId'    => $otherId,
            'otherUserName'  => $otherName,
            'otherUserPhoto' => $otherPhoto ? UPLOAD_URL . $otherPhoto : null,
            'lastMessage'    => $r['last_message'],
            'lastMessageAt'  => $r['last_message_at'],
            'unreadCount'    => (int) $unread,
            'isTyping'       => $isTyping,
            'listing'        => $r['listing_id'] ? [
                'id'       => $r['listing_id'],
                'title'    => $r['listing_title'],
                'price'    => $r['listing_price'],
                'currency' => $r['listing_currency'],
            ] : null,
        ];
    }, $st->fetchAll()));
}

function handleMessages(): void {
    $uid    = requireAuth();
    $convId = (int)($_GET['conversation_id'] ?? 0);
    if (!$convId) jsonError('Conversation ID required');

    $db = getDB();

    // Verify membership
    $st = $db->prepare('SELECT * FROM conversations WHERE id = ? AND (user1_id = ? OR user2_id = ?)');
    $st->execute([$convId, $uid, $uid]);
    $conv = $st->fetch();
    if (!$conv) jsonError('Forbidden', 403);

    // Mark messages as read (messages sent by other person)
    $db->prepare('UPDATE messages SET read_at = NOW() WHERE conversation_id = ? AND sender_id != ? AND read_at IS NULL')
       ->execute([$convId, $uid]);

    // Reset unread count for this user
    $col = $conv['user1_id'] == $uid ? 'unread_count_1' : 'unread_count_2';
    $db->prepare("UPDATE conversations SET $col = 0 WHERE id = ?")->execute([$convId]);

    // Get messages
    $st = $db->prepare(
        'SELECT m.*, u.display_name AS sender_name, u.avatar_url AS sender_photo
         FROM messages m
         JOIN users u ON m.sender_id = u.id
         WHERE m.conversation_id = ? AND m.deleted_at IS NULL
         ORDER BY m.created_at ASC
         LIMIT 200'
    );
    $st->execute([$convId]);

    jsonSuccess(array_map(fn($r) => [
        'id'         => $r['id'],
        'senderId'   => $r['sender_id'],
        'senderName' => $r['sender_name'],
        'senderPhoto'=> $r['sender_photo'] ? UPLOAD_URL . $r['sender_photo'] : null,
        'text'       => decryptMessage($r['text']),
        'imageUrl'   => $r['image_url'] ?? null,
        'readAt'     => $r['read_at'],
        'createdAt'  => $r['created_at'],
    ], $st->fetchAll()));
}

function handleSend(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);

    $convId = (int)($data['conversationId'] ?? 0);
    $text   = trim($data['text'] ?? '');

    if (!$convId) jsonError('Conversation ID required');
    if (!$text && empty($data['imageUrl'])) jsonError('Message cannot be empty');
    if (mb_strlen($text) > 2000) jsonError('Message too long (max 2000 chars)');

    $db = getDB();

    $st = $db->prepare('SELECT * FROM conversations WHERE id = ? AND (user1_id = ? OR user2_id = ?)');
    $st->execute([$convId, $uid, $uid]);
    $conv = $st->fetch();
    if (!$conv) jsonError('Forbidden', 403);

    $imageUrl = $data['imageUrl'] ?? null;
    $db->prepare('INSERT INTO messages (conversation_id, sender_id, text, image_url) VALUES (?,?,?,?)')
       ->execute([$convId, $uid, encryptMessage($text), $imageUrl]);
    $msgId = (int)$db->lastInsertId();

    $otherCol = $conv['user1_id'] == $uid ? 'unread_count_2' : 'unread_count_1';
    $preview  = $imageUrl ? '[Photo]' : mb_substr($text, 0, 200);
    $db->prepare("UPDATE conversations SET last_message=?, last_message_at=NOW(), $otherCol=$otherCol+1 WHERE id=?")
       ->execute([$preview, $convId]);

    // Clear own typing flag
    $typingCol = $conv['user1_id'] == $uid ? 'user1_typing_at' : 'user2_typing_at';
    $db->prepare("UPDATE conversations SET $typingCol = NULL WHERE id = ?")->execute([$convId]);

    // Karşı tarafa bildirim oluştur
    try {
        $otherId = $conv['user1_id'] == $uid ? $conv['user2_id'] : $conv['user1_id'];
        $senderSt = $db->prepare('SELECT display_name FROM users WHERE id=?');
        $senderSt->execute([$uid]);
        $senderName = $senderSt->fetch()['display_name'] ?? 'Someone';
        $preview = mb_substr($text ?: '📷 Image', 0, 80);
        $db->prepare(
            "INSERT INTO notifications (user_id,type,title,body,link,icon) VALUES (?,?,?,?,?,?)"
        )->execute([
            $otherId, 'message',
            "New message from $senderName",
            $preview,
            'messages.html?conv=' . $convId,
            '💬'
        ]);
    } catch(\Throwable $e) {}

    jsonSuccess([
        'id'        => $msgId,
        'senderId'  => $uid,
        'text'      => $text,
        'imageUrl'  => $imageUrl,
        'readAt'    => null,
        'createdAt' => date('Y-m-d H:i:s'),
    ], 201);
}

function handleTyping(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    $convId = (int)($data['conversationId'] ?? 0);
    if (!$convId) jsonError('Conversation ID required');

    $db = getDB();
    $st = $db->prepare('SELECT * FROM conversations WHERE id = ? AND (user1_id = ? OR user2_id = ?)');
    $st->execute([$convId, $uid, $uid]);
    $conv = $st->fetch();
    if (!$conv) jsonError('Forbidden', 403);

    $col = $conv['user1_id'] == $uid ? 'user1_typing_at' : 'user2_typing_at';
    $val = ($data['typing'] ?? false) ? date('Y-m-d H:i:s') : null;
    $db->prepare("UPDATE conversations SET $col = ? WHERE id = ?")->execute([$val, $convId]);

    jsonSuccess(['ok' => true]);
}

function handleMarkRead(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    $convId = (int)($data['conversationId'] ?? 0);
    if (!$convId) return;

    $db = getDB();
    $db->prepare('UPDATE messages SET read_at = NOW() WHERE conversation_id = ? AND sender_id != ? AND read_at IS NULL')
       ->execute([$convId, $uid]);

    jsonSuccess(['ok' => true]);
}

function handleDeleteMessage(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    $msgId = (int)($data['messageId'] ?? 0);
    if (!$msgId) jsonError('Message ID required');

    $db = getDB();
    // Only sender can delete
    $st = $db->prepare('SELECT * FROM messages WHERE id = ? AND sender_id = ?');
    $st->execute([$msgId, $uid]);
    if (!$st->fetch()) jsonError('Forbidden', 403);

    $db->prepare('UPDATE messages SET deleted_at = NOW(), text = "" WHERE id = ?')->execute([$msgId]);
    jsonSuccess(['ok' => true]);
}

function handleUnreadCount(): void {
    $uid = requireAuth();
    $db  = getDB();

    $st = $db->prepare(
        'SELECT SUM(CASE WHEN user1_id=? THEN unread_count_1 ELSE unread_count_2 END) AS total
         FROM conversations WHERE user1_id=? OR user2_id=?'
    );
    $st->execute([$uid, $uid, $uid]);
    $row = $st->fetch();

    jsonSuccess(['count' => (int)($row['total'] ?? 0)]);
}

function handleGetOrCreate(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    $otherId   = (int)($data['otherUserId'] ?? 0);
    $listingId = (int)($data['listingId']   ?? 0) ?: null;

    if (!$otherId) jsonError('Other user ID required');
    if ($otherId === $uid) jsonError('Cannot chat with yourself');

    $db = getDB();
    $u1 = min($uid, $otherId);
    $u2 = max($uid, $otherId);

    $st = $db->prepare('SELECT id FROM conversations WHERE user1_id=? AND user2_id=?');
    $st->execute([$u1, $u2]);
    $conv = $st->fetch();

    if ($conv) {
        jsonSuccess(['conversationId' => $conv['id']]);
    } else {
        $db->prepare('INSERT INTO conversations (user1_id, user2_id, listing_id) VALUES (?,?,?)')
           ->execute([$u1, $u2, $listingId]);
        jsonSuccess(['conversationId' => (int)$db->lastInsertId()], 201);
    }
}

// ── Archive: konuşmayı arşivle / arşivden çıkar ──────────────────────────
function handleArchive(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $convId = (int)($data['conversation_id'] ?? 0);
    if (!$convId) jsonError('conversation_id required');

    $db = getDB();

    // archived_by_user1 / archived_by_user2 kolonları yoksa ekle
    try { $db->exec("ALTER TABLE conversations ADD COLUMN archived_by_user1 TINYINT(1) DEFAULT 0"); } catch(\Throwable $e) {}
    try { $db->exec("ALTER TABLE conversations ADD COLUMN archived_by_user2 TINYINT(1) DEFAULT 0"); } catch(\Throwable $e) {}

    // Kullanıcının bu konuşmada olup olmadığını kontrol et
    $st = $db->prepare("SELECT id, user1_id, user2_id FROM conversations WHERE id = ?");
    $st->execute([$convId]);
    $conv = $st->fetch();
    if (!$conv) jsonError('Conversation not found', 404);

    if ($conv['user1_id'] == $uid) {
        $col = 'archived_by_user1';
    } elseif ($conv['user2_id'] == $uid) {
        $col = 'archived_by_user2';
    } else {
        jsonError('Not your conversation', 403);
    }

    // Toggle: şu anki durumu al, tersine çevir
    $current = (int)($conv[$col] ?? 0);
    $newVal  = $current ? 0 : 1;
    $db->prepare("UPDATE conversations SET {$col} = ? WHERE id = ?")
       ->execute([$newVal, $convId]);

    jsonSuccess([
        'archived'       => (bool)$newVal,
        'conversationId' => $convId,
        'message'        => $newVal ? 'Conversation archived.' : 'Conversation unarchived.',
    ]);
}
