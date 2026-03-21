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

// affiliates.status
if (!colExists($db, 'affiliates', 'status'))
    $results[] = tryExec($db, 'affiliates.status',
        "ALTER TABLE affiliates ADD COLUMN status ENUM('pending','approved','rejected') DEFAULT 'pending'");
else $results[] = "ALREADY: affiliates.status";

// Set existing active affiliates to approved
$results[] = tryExec($db, 'backfill approved',
    "UPDATE affiliates SET status = 'approved' WHERE is_active = 1 AND status = 'pending'");

// users.ref_code
if (!colExists($db, 'users', 'ref_code'))
    $results[] = tryExec($db, 'users.ref_code',
        "ALTER TABLE users ADD COLUMN ref_code VARCHAR(20) NULL");
else $results[] = "ALREADY: users.ref_code";

// users.referred_by
if (!colExists($db, 'users', 'referred_by'))
    $results[] = tryExec($db, 'users.referred_by',
        "ALTER TABLE users ADD COLUMN referred_by INT NULL");
else $results[] = "ALREADY: users.referred_by";

// payments.affiliate_id
if (!colExists($db, 'payments', 'affiliate_id'))
    $results[] = tryExec($db, 'payments.affiliate_id',
        "ALTER TABLE payments ADD COLUMN affiliate_id INT NULL");
else $results[] = "ALREADY: payments.affiliate_id";

header('Content-Type: text/plain');
echo implode("\n", $results) . "\n\nDONE";
