<?php
/**
 * SomaBazar — Image Migration Tool
 * Yerel uploads/listings/ klasöründeki görselleri Cloudinary'ye yükler
 * URL: /api/migrate_images.php?token=YOUR_TOKEN&dry_run=1
 */

require_once __DIR__ . '/config.php';

$token = $_GET['token'] ?? '';
$validToken = getenv('MIGRATION_TOKEN');
if (!$validToken || $token !== $validToken) {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden']));
}

$dryRun = ($_GET['dry_run'] ?? '0') === '1';
$limit  = (int)($_GET['limit'] ?? 10);

$cloudName = getenv('CLOUDINARY_CLOUD_NAME');
$apiKey    = getenv('CLOUDINARY_API_KEY');
$apiSecret = getenv('CLOUDINARY_API_SECRET');
$siteUrl   = SITE_URL;

header('Content-Type: application/json');

// Cloudinary env vars kontrol
if (!$cloudName || !$apiKey || !$apiSecret) {
    die(json_encode([
        'error' => 'Cloudinary env vars eksik!',
        'CLOUDINARY_CLOUD_NAME' => $cloudName ? 'SET' : 'MISSING',
        'CLOUDINARY_API_KEY'    => $apiKey    ? 'SET' : 'MISSING',
        'CLOUDINARY_API_SECRET' => $apiSecret ? 'SET' : 'MISSING',
    ]));
}

$db = getDB();

// Local URL iceren ilanları bul
$localUrlPattern = $siteUrl . '/uploads/listings/%';
$st = $db->prepare("
    SELECT id, images FROM listings 
    WHERE images LIKE ? AND images IS NOT NULL
    LIMIT ?
");
$st->execute([$localUrlPattern, $limit]);
$listings = $st->fetchAll();

$results = [];
$migrated = 0;
$failed   = 0;
$skipped  = 0;

foreach ($listings as $listing) {
    $images = json_decode($listing['images'], true);
    if (!$images || !is_array($images)) { $skipped++; continue; }

    $newImages = [];
    $changed = false;

    foreach ($images as $url) {
        // Zaten Cloudinary'de mi?
        if (str_contains($url, 'cloudinary.com')) {
            $newImages[] = $url;
            continue;
        }

        // Local URL - dosya yolu
        $localPath = str_replace($siteUrl . '/uploads/', UPLOAD_DIR, $url);

        if (!file_exists($localPath)) {
            // Dosya yok - placeholder koy
            $newImages[] = $url;
            $results[] = ['listing_id' => $listing['id'], 'url' => $url, 'status' => 'file_not_found'];
            $failed++;
            continue;
        }

        if ($dryRun) {
            $newImages[] = $url . '_[would_migrate]';
            $results[] = ['listing_id' => $listing['id'], 'url' => $url, 'status' => 'dry_run'];
            $migrated++;
            continue;
        }

        // Cloudinary'ye yukle
        $timestamp = time();
        $folder    = 'sombazar/listings';
        $signature = sha1("folder=$folder&timestamp=$timestamp" . $apiSecret);

        $ch = curl_init("https://api.cloudinary.com/v1_1/$cloudName/image/upload");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POSTFIELDS     => [
                'file'      => new CURLFile($localPath),
                'folder'    => $folder,
                'timestamp' => $timestamp,
                'api_key'   => $apiKey,
                'signature' => $signature,
            ],
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (isset($data['secure_url'])) {
            $newImages[] = $data['secure_url'];
            $changed = true;
            $results[] = ['listing_id' => $listing['id'], 'old' => $url, 'new' => $data['secure_url'], 'status' => 'migrated'];
            $migrated++;
        } else {
            $newImages[] = $url;
            $results[] = ['listing_id' => $listing['id'], 'url' => $url, 'status' => 'upload_failed', 'error' => $data['error']['message'] ?? $curlError];
            $failed++;
        }
    }

    // Guncelle
    if ($changed && !$dryRun) {
        $db->prepare("UPDATE listings SET images = ? WHERE id = ?")
           ->execute([json_encode($newImages), $listing['id']]);
    }
}

echo json_encode([
    'dry_run'       => $dryRun,
    'cloudinary'    => $cloudName,
    'listings_found'=> count($listings),
    'migrated'      => $migrated,
    'failed'        => $failed,
    'skipped'       => $skipped,
    'results'       => $results,
]);
