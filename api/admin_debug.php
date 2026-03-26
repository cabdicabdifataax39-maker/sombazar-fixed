<?php
require_once __DIR__ . "/config.php";
$uid = requireAuth();
$db = getDB();
$st = $db->prepare("SELECT is_admin, role FROM users WHERE id = ?");
$st->execute([$uid]);
$me = $st->fetch();
echo json_encode([
    "uid" => $uid,
    "is_admin" => $me["is_admin"] ?? null,
    "role" => $me["role"] ?? null,
    "action" => $_GET["action"] ?? "none",
]);
