<?php
if ($_GET['key'] !== 'sombazar2026') { http_response_code(403); die('no'); }
require_once __DIR__ . '/api/config.php';
$db = getDB();
$sqls = [
    "ALTER TABLE listings MODIFY COLUMN category ENUM('car','house','land','electronics','furniture','jobs','services','hotel','fashion') NOT NULL",
    "ALTER TABLE conversations ADD COLUMN IF NOT EXISTS archived_by_user1 TINYINT(1) DEFAULT 0",
    "ALTER TABLE conversations ADD COLUMN IF NOT EXISTS archived_by_user2 TINYINT(1) DEFAULT 0",
    "ALTER TABLE payments ADD COLUMN IF NOT EXISTS billing_cycle ENUM('monthly','annual') DEFAULT 'monthly'",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS notifications_email TINYINT(1) DEFAULT 1",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS notifications_push TINYINT(1) DEFAULT 1",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS notifications_sms TINYINT(1) DEFAULT 0",
    "ALTER TABLE reports MODIFY COLUMN listing_id INT NULL",
    "ALTER TABLE reports MODIFY COLUMN reporter_id INT NULL",
    "ALTER TABLE reports ADD COLUMN IF NOT EXISTS report_type VARCHAR(50) NULL",
    "ALTER TABLE reports ADD COLUMN IF NOT EXISTS status ENUM('pending','reviewed','resolved','dismissed') DEFAULT 'pending'",
    "ALTER TABLE reports ADD COLUMN IF NOT EXISTS listing_ref VARCHAR(200) NULL",
    "ALTER TABLE reports ADD COLUMN IF NOT EXISTS reporter_ip VARCHAR(45) NULL",
];
$results = [];
foreach ($sqls as $sql) {
    try { $db->exec($sql); $results[] = "OK: " . substr($sql, 0, 80); }
    catch (Throwable $e) { $results[] = "SKIP: " . $e->getMessage(); }
}
header('Content-Type: text/plain');
echo implode("\n", $results) . "\n\nDONE";
