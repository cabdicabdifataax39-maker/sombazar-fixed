<?php
// Serves identity documents ONLY to admins - never expose direct URLs
require_once __DIR__ . '/config.php';

$uid = requireAuth();
$db  = getDB();

// Must be admin
$st = $db->prepare('SELECT is_admin FROM users WHERE id = ?');
$st->execute([$uid]);
$me = $st->fetch();
if (!$me || !$me['is_admin']) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$docId = (int)($_GET['id'] ?? 0);
if (!$docId) { http_response_code(400); echo 'Missing id'; exit; }

$st = $db->prepare('SELECT file_url FROM verification_docs WHERE id = ?');
$st->execute([$docId]);
$doc = $st->fetch();
if (!$doc) { http_response_code(404); echo 'Not found'; exit;  }

// Convert stored URL to local file path
$url      = $doc['file_url'];
$basePath = dirname(__DIR__); // /sombazar-fixed/
$localPath = $basePath . '/' . ltrim(parse_url($url, PHP_URL_PATH), '/');
// Strip site prefix from path
$localPath = str_replace(rtrim(SITE_URL, '/'), '', $url);
$localPath = $basePath . $localPath;

if (!file_exists($localPath)) {
    // Try serving the URL directly as last resort
    header('Location: ' . $url);
    exit;
}

$mime = mime_content_type($localPath) ?: 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline');
header('Cache-Control: private, max-age=3600');
readfile($localPath);
