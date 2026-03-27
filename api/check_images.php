<?php
require_once __DIR__ . '/config.php';

$token = $_GET['token'] ?? '';
if ($token !== getenv('MIGRATION_TOKEN')) { http_response_code(403); die('Forbidden'); }

$db = getDB();
$st = $db->query("SELECT id, title, images FROM listings WHERE images IS NOT NULL AND images != '[]' LIMIT 10");
$rows = $st->fetchAll();

$result = [];
foreach ($rows as $r) {
    $imgs = json_decode($r['images'], true) ?: [];
    $result[] = [
        'id' => $r['id'],
        'title' => $r['title'],
        'first_image' => $imgs[0] ?? null,
        'count' => count($imgs)
    ];
}

header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
