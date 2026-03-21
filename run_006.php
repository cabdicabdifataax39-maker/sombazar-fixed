<?php
if ($_GET['key'] !== 'sombazar2026') { http_response_code(403); die('no'); }
require_once __DIR__ . '/api/config.php';
$db = getDB();
$results = [];

function colExists(PDO $db, string $table, string $col): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

function tryExec(PDO $db, string $label, string $sql): string {
    try { $db->exec($sql); return "OK: $label"; }
    catch (Throwable $e) { return "SKIP ($label): " . $e->getMessage(); }
}

// 1. conversations — archived_by_user1
if (!colExists($db, 'conversations', 'archived_by_user1'))
    $results[] = tryExec($db, 'archived_by_user1', "ALTER TABLE conversations ADD COLUMN archived_by_user1 TINYINT(1) DEFAULT 0");
else $results[] = "ALREADY: conversations.archived_by_user1";

// 2. conversations — archived_by_user2
if (!colExists($db, 'conversations', 'archived_by_user2'))
    $results[] = tryExec($db, 'archived_by_user2', "ALTER TABLE conversations ADD COLUMN archived_by_user2 TINYINT(1) DEFAULT 0");
else $results[] = "ALREADY: conversations.archived_by_user2";

// 3. payments — billing_cycle
if (!colExists($db, 'payments', 'billing_cycle'))
    $results[] = tryExec($db, 'billing_cycle', "ALTER TABLE payments ADD COLUMN billing_cycle ENUM('monthly','annual') DEFAULT 'monthly'");
else $results[] = "ALREADY: payments.billing_cycle";

// 4. users — notifications_email
if (!colExists($db, 'users', 'notifications_email'))
    $results[] = tryExec($db, 'notifications_email', "ALTER TABLE users ADD COLUMN notifications_email TINYINT(1) DEFAULT 1");
else $results[] = "ALREADY: users.notifications_email";

// 5. users — notifications_push
if (!colExists($db, 'users', 'notifications_push'))
    $results[] = tryExec($db, 'notifications_push', "ALTER TABLE users ADD COLUMN notifications_push TINYINT(1) DEFAULT 1");
else $results[] = "ALREADY: users.notifications_push";

// 6. users — notifications_sms
if (!colExists($db, 'users', 'notifications_sms'))
    $results[] = tryExec($db, 'notifications_sms', "ALTER TABLE users ADD COLUMN notifications_sms TINYINT(1) DEFAULT 0");
else $results[] = "ALREADY: users.notifications_sms";

// 7. reports — report_type
if (!colExists($db, 'reports', 'report_type'))
    $results[] = tryExec($db, 'report_type', "ALTER TABLE reports ADD COLUMN report_type VARCHAR(50) NULL");
else $results[] = "ALREADY: reports.report_type";

// 8. reports — status
if (!colExists($db, 'reports', 'status'))
    $results[] = tryExec($db, 'status', "ALTER TABLE reports ADD COLUMN status ENUM('pending','reviewed','resolved','dismissed') DEFAULT 'pending'");
else $results[] = "ALREADY: reports.status";

// 9. reports — listing_ref
if (!colExists($db, 'reports', 'listing_ref'))
    $results[] = tryExec($db, 'listing_ref', "ALTER TABLE reports ADD COLUMN listing_ref VARCHAR(200) NULL");
else $results[] = "ALREADY: reports.listing_ref";

// 10. reports — reporter_ip
if (!colExists($db, 'reports', 'reporter_ip'))
    $results[] = tryExec($db, 'reporter_ip', "ALTER TABLE reports ADD COLUMN reporter_ip VARCHAR(45) NULL");
else $results[] = "ALREADY: reports.reporter_ip";

header('Content-Type: text/plain');
echo implode("\n", $results) . "\n\nDONE";
