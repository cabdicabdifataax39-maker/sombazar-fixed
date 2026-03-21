<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server error']);
    }
});

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

applyEndpointRateLimit('reports');

$data = json_decode(file_get_contents('php://input'), true) ?? [];

$reportType  = trim($data['report_type']  ?? '');
$description = trim($data['description']  ?? '');
$listingRef  = trim($data['listing_ref']  ?? '');
$listingId   = isset($data['listing_id']) ? (int)$data['listing_id'] : null;

$validTypes = ['scam', 'fake', 'prohibited', 'offensive', 'spam', 'other',
               'counterfeit', 'dangerous', 'harassment', 'copyright'];

if (!$reportType)                                  jsonError('Report type is required');
if (!in_array($reportType, $validTypes))           jsonError('Invalid report type');
if (!$description || strlen($description) < 10)   jsonError('Description must be at least 10 characters');
if (strlen($description) > 3000)                  jsonError('Description too long');

$db = getDB();

// Auto-migrate: ensure report_type and status columns exist (migration 005 may have added them)
try { $db->exec("ALTER TABLE reports ADD COLUMN report_type VARCHAR(50) NULL"); } catch (\Throwable $e) {}
try { $db->exec("ALTER TABLE reports ADD COLUMN status ENUM('pending','reviewed','resolved','dismissed') DEFAULT 'pending'"); } catch (\Throwable $e) {}
try { $db->exec("ALTER TABLE reports ADD COLUMN listing_ref VARCHAR(200) NULL"); } catch (\Throwable $e) {}
try { $db->exec("ALTER TABLE reports ADD COLUMN reporter_ip VARCHAR(45) NULL"); } catch (\Throwable $e) {}
// Make listing_id and reporter_id nullable for anonymous reports from safety page
try { $db->exec("ALTER TABLE reports MODIFY COLUMN listing_id INT NULL"); } catch (\Throwable $e) {}
try { $db->exec("ALTER TABLE reports MODIFY COLUMN reporter_id INT NULL"); } catch (\Throwable $e) {}
// Remove unique constraint that blocks multiple reports from same user on same listing
try { $db->exec("ALTER TABLE reports DROP INDEX unique_report"); } catch (\Throwable $e) {}

// Get reporter ID if logged in (optional)
$reporterId = null;
try {
    $token = null;
    $auth  = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) $token = trim($m[1]);
    if ($token) {
        // Minimal JWT decode — don't hard-fail if invalid
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            if (!empty($payload['uid'])) $reporterId = (int)$payload['uid'];
        }
    }
} catch (\Throwable $e) {}

// If listingId not provided but listingRef contains digits, try to resolve
if (!$listingId && $listingRef) {
    preg_match('/(\d+)/', $listingRef, $m);
    if (!empty($m[1])) {
        $chk = $db->prepare("SELECT id FROM listings WHERE id = ? LIMIT 1");
        $chk->execute([(int)$m[1]]);
        if ($row = $chk->fetch()) $listingId = (int)$row['id'];
    }
}

try {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $st = $db->prepare("INSERT INTO reports
        (listing_id, reporter_id, reason, report_type, listing_ref, reporter_ip, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
    $st->execute([
        $listingId,
        $reporterId,
        $description,
        $reportType,
        $listingRef ?: null,
        $ip,
    ]);
} catch (\Throwable $e) {
    error_log('reports.php DB error: ' . $e->getMessage());
    jsonError('Failed to submit report. Please try again.');
}

jsonSuccess(['message' => 'Your report has been submitted. Our team will review it within 24 hours.']);
