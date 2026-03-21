<?php
if ($_GET['key'] !== 'sombazar2026') { http_response_code(403); die('no'); }
require_once __DIR__ . '/api/config.php';
$db = getDB();

// Fix: set status=approved for all is_active=1 affiliates
$r1 = $db->exec("UPDATE affiliates SET status = 'approved' WHERE is_active = 1");
echo "Updated active affiliates to approved status: " . $r1 . " rows\n";

// Show current state
$st = $db->query("SELECT id, user_id, ref_code, is_active, status FROM affiliates");
$rows = $st->fetchAll();
foreach ($rows as $r) {
    echo "ID:{$r['id']} user:{$r['user_id']} code:{$r['ref_code']} active:{$r['is_active']} status:{$r['status']}\n";
}
echo "DONE";
