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
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'submit':
        if ($method !== 'POST') jsonError('Method not allowed', 405);
        handleSubmit();
        break;
    case 'status':
        handleStatus();
        break;
    default:
        jsonError('Unknown action', 404);
}

function handleSubmit(): void {
    $uid  = requireAuth();
    $db   = getDB();
    $data = json_decode(file_get_contents('php://input'), true);

    $sellerType = $data['seller_type'] ?? 'individual';
    $phone      = trim($data['phone'] ?? '');
    $docs       = $data['docs'] ?? [];

    if (!in_array($sellerType, ['individual','agency','company'])) jsonError('Invalid seller type');
    if (!$phone) jsonError('Phone number required');
    if (empty($docs)) jsonError('Documents required');

    // Check blacklist
    $bl = $db->prepare('SELECT id FROM blacklist WHERE phone = ?');
    $bl->execute([$phone]);
    if ($bl->fetch()) jsonError('This phone number is not allowed to register.');

    // Update user seller_type, phone, verification_status
    $db->prepare('UPDATE users SET seller_type=?, phone=?, verification_status="pending" WHERE id=?')
       ->execute([$sellerType, $phone, $uid]);

    // Mark old docs as superseded (keep history) - column added in migrate4
    try { $db->prepare('UPDATE verification_docs SET superseded=1 WHERE user_id=?')->execute([$uid]); } catch(\Exception $e) { /* column not yet created */ }

    // Insert new docs
    $stmt = $db->prepare('INSERT INTO verification_docs (user_id, doc_type, file_url) VALUES (?,?,?)');
    foreach ($docs as $docType => $url) {
        $allowed = ['national_id_front','national_id_back','selfie','trade_license','authority_id','office_photo'];
        if (!in_array($docType, $allowed)) continue;
        $stmt->execute([$uid, $docType, $url]);
    }

    jsonSuccess(['message' => 'Verification submitted. Under review within 24 hours.']);
}

function handleStatus(): void {
    $uid = requireAuth();
    $db  = getDB();

    $st = $db->prepare('SELECT verification_status, verification_note, badge_level, verified FROM users WHERE id=?');
    $st->execute([$uid]);
    $row = $st->fetch();

    $docs = $db->prepare('SELECT doc_type, uploaded_at FROM verification_docs WHERE user_id=? ORDER BY uploaded_at DESC');
    $docs->execute([$uid]);

    jsonSuccess([
        'status'   => $row['verification_status'] ?? 'none',
        'note'     => $row['verification_note']   ?? '',
        'badge'    => $row['badge_level']          ?? 'basic',
        'verified' => (bool)$row['verified'],
        'docs'     => $docs->fetchAll(),
    ]);
}
