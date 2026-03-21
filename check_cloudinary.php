<?php
if ($_GET['key'] !== 'sombazar2026') { http_response_code(403); die('no'); }
require_once __DIR__ . '/api/config.php';

$name   = getenv('CLOUDINARY_CLOUD_NAME');
$key    = getenv('CLOUDINARY_API_KEY');
$secret = getenv('CLOUDINARY_API_SECRET');

header('Content-Type: text/plain');
echo "CLOUDINARY_CLOUD_NAME : " . ($name   ? "SET ({$name})"              : "MISSING") . "\n";
echo "CLOUDINARY_API_KEY    : " . ($key    ? "SET (" . substr($key,0,6) . "...)" : "MISSING") . "\n";
echo "CLOUDINARY_API_SECRET : " . ($secret ? "SET (" . substr($secret,0,4) . "...)" : "MISSING") . "\n";

if ($name && $key && $secret) {
    // Test API call
    $ts  = time();
    $sig = sha1("timestamp={$ts}{$secret}");
    $ch  = curl_init("https://api.cloudinary.com/v1_1/{$name}/resources/image?max_results=1");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "{$key}:{$secret}",
    ]);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "\nCloudinary API test: HTTP {$code}\n";
    if ($code === 200) echo "✓ Cloudinary connection OK\n";
    else echo "✗ Cloudinary error: " . substr($r, 0, 200) . "\n";
} else {
    echo "\n✗ Cannot test — env vars missing\n";
}
