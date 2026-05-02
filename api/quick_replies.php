<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'PHP Error: ' . $err['message']]);
    }
});

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

ensureQuickRepliesTable();

switch ($action) {
    case 'list':   handleList();                                                             break;
    case 'save':   if ($method !== 'POST') jsonError('Method not allowed', 405); handleSave();   break;
    case 'delete': if ($method !== 'POST') jsonError('Method not allowed', 405); handleDelete(); break;
    case 'use':    if ($method !== 'POST') jsonError('Method not allowed', 405); handleUse();    break;
    default: jsonError('Unknown action: ' . $action);
}

function ensureQuickRepliesTable(): void {
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS seller_quick_replies (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            seller_id  INT NOT NULL,
            body       TEXT NOT NULL,
            use_count  INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_seller (seller_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {}
}

function handleList(): void {
    $uid = requireAuth();
    $db  = getDB();

    $st = $db->prepare("SELECT id, body, use_count, created_at FROM seller_quick_replies WHERE seller_id = ? ORDER BY use_count DESC, created_at DESC");
    $st->execute([$uid]);
    jsonSuccess(['replies' => $st->fetchAll()]);
}

function handleSave(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $body = trim($data['body'] ?? '');
    $id   = (int)($data['id'] ?? 0);

    if (!$body) jsonError('body required');
    if (mb_strlen($body) > 500) jsonError('Too long (max 500 chars)');

    $db = getDB();

    if ($id) {
        // Guncelle
        $st = $db->prepare("SELECT seller_id FROM seller_quick_replies WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row || (int)$row['seller_id'] !== $uid) jsonError('Not found or forbidden', 403);

        $db->prepare("UPDATE seller_quick_replies SET body = ? WHERE id = ?")->execute([$body, $id]);
        jsonSuccess(['message' => 'Updated', 'id' => $id]);
    } else {
        // Yeni ekle (max 20 limit)
        $countSt = $db->prepare("SELECT COUNT(*) FROM seller_quick_replies WHERE seller_id = ?");
        $countSt->execute([$uid]);
        if ((int)$countSt->fetchColumn() >= 20) jsonError('Maximum 20 quick replies allowed');

        $db->prepare("INSERT INTO seller_quick_replies (seller_id, body) VALUES (?, ?)")->execute([$uid, $body]);
        jsonSuccess(['message' => 'Saved', 'id' => (int)$db->lastInsertId()], 201);
    }
}

function handleDelete(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($data['id'] ?? 0);
    if (!$id) jsonError('id required');

    $db = getDB();
    $st = $db->prepare("SELECT seller_id FROM seller_quick_replies WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || (int)$row['seller_id'] !== $uid) jsonError('Not found or forbidden', 403);

    $db->prepare("DELETE FROM seller_quick_replies WHERE id = ?")->execute([$id]);
    jsonSuccess(['message' => 'Deleted']);
}

function handleUse(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($data['id'] ?? 0);
    if (!$id) jsonError('id required');

    $db = getDB();
    $st = $db->prepare("SELECT seller_id FROM seller_quick_replies WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || (int)$row['seller_id'] !== $uid) jsonError('Not found or forbidden', 403);

    $db->prepare("UPDATE seller_quick_replies SET use_count = use_count + 1 WHERE id = ?")->execute([$id]);
    jsonSuccess(['message' => 'ok']);
}
