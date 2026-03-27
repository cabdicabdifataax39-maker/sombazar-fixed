<?php
require_once __DIR__ . '/config.php';

$token = $_GET['token'] ?? '';
if ($token !== getenv('MIGRATION_TOKEN')) { http_response_code(403); die('Forbidden'); }

$dryRun = ($_GET['dry_run'] ?? '1') === '1';
$limit  = (int)($_GET['limit'] ?? 50);

$cloudName = getenv('CLOUDINARY_CLOUD_NAME');
$apiKey    = getenv('CLOUDINARY_API_KEY');
$apiSecret = getenv('CLOUDINARY_API_SECRET');

header('Content-Type: application/json');

if (!$cloudName || !$apiKey || !$apiSecret) {
    die(json_encode(['error' => 'Cloudinary env vars missing']));
}

$db = getDB();

// uploads/listings/ iceren tum ilanlar
$st = $db->prepare("
    SELECT id, images FROM listings 
    WHERE images LIKE '%uploads/listings/%'
    LIMIT ?
");
$st->execute([$limit]);
$listings = $st->fetchAll();

$results = [];
$migrated = 0;
$failed = 0;

foreach ($listings as $listing) {
    $images = json_decode($listing['images'], true);
    if (!$images) continue;

    $newImages = [];
    $changed = false;

    foreach ($images as $url) {
        if (str_contains($url, 'cloudinary.com')) {
            $newImages[] = $url;
            continue;
        }

        if (!str_contains($url, 'uploads/listings/')) {
            $newImages[] = $url;
            continue;
        }

        // Local dosya yolunu bul
        $filename = basename($url);
        $localPath = UPLOAD_DIR . 'listings/' . $filename;

        if ($dryRun) {
            $exists = file_exists($localPath);
            $results[] = [
                'listing_id' => $listing['id'],
                'url' => $url,
                'filename' => $filename,
                'file_exists' => $exists,
                'status' => 'dry_run'
            ];
            $newImages[] = $url;
            continue;
        }

        if (!file_exists($localPath)) {
            // Dosya yok - Railway'de silinmis
            // Placeholder gorsel kullan
            $newImages[] = ''; // Bos birak
            $results[] = ['listing_id' => $listing['id'], 'status' => 'file_deleted', 'url' => $url];
            $failed++;
            continue;
        }

        // Cloudinary'ye yukle
        $timestamp = time();
        $folder = 'sombazar/listings';
        $signature = sha1("folder=$folder&timestamp=$timestamp" . $apiSecret);

        $ch = curl_init("https://api.cloudinary.com/v1_1/$cloudName/image/upload");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile($localPath),
                'folder' => $folder,
                'timestamp' => $timestamp,
                'api_key' => $apiKey,
                'signature' => $signature,
            ],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (isset($data['secure_url'])) {
            $newImages[] = $data['secure_url'];
            $changed = true;
            $migrated++;
            $results[] = ['listing_id' => $listing['id'], 'status' => 'migrated', 'new_url' => $data['secure_url']];
            @unlink($localPath);
        } else {
            $newImages[] = $url;
            $failed++;
            $results[] = ['listing_id' => $listing['id'], 'status' => 'failed', 'error' => $data['error']['message'] ?? 'unknown'];
        }
    }

    // Bos URL'leri kaldir
    $newImages = array_values(array_filter($newImages));

    if ($changed && !$dryRun) {
        $db->prepare("UPDATE listings SET images = ? WHERE id = ?")
           ->execute([json_encode($newImages), $listing['id']]);
    }
}

echo json_encode([
    'dry_run' => $dryRun,
    'listings_found' => count($listings),
    'migrated' => $migrated,
    'failed' => $failed,
    'results' => $results,
], JSON_PRETTY_PRINT);
