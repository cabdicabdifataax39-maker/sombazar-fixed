<?php
// admin.php versiyonu kontrol
$file = __FILE__;
$lines = count(file($file));
$mtime = date("Y-m-d H:i:s", filemtime($file));
$content = file_get_contents($file);
$hasStats = strpos($content, "case '''stats'''") !== false;
$hasCsrf = strpos($content, "csrf_token") !== false;
echo json_encode([
    "file" => $file,
    "lines" => $lines,
    "modified" => $mtime,
    "has_stats_case" => $hasStats,
    "has_csrf_token" => $hasCsrf,
    "php_version" => PHP_VERSION,
]);
