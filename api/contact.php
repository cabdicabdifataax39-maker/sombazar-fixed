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
require_once __DIR__ . '/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Basic rate limit — 5 requests per IP per hour (stored in DB)
applyEndpointRateLimit('contact');

$data = json_decode(file_get_contents('php://input'), true) ?? [];

$name    = trim($data['name']    ?? '');
$email   = trim($data['email']   ?? '');
$subject = trim($data['subject'] ?? 'General Enquiry');
$message = trim($data['message'] ?? '');

// Validation
if (!$name)                           jsonError('Name is required');
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Valid email is required');
if (!$message || strlen($message) < 10) jsonError('Message must be at least 10 characters');
if (strlen($message) > 5000)          jsonError('Message too long (max 5000 chars)');

// Honeypot / spam check
if (!empty($data['website'])) {
    // Fake success to confuse bots
    jsonSuccess(['message' => 'Thank you! We will get back to you soon.']);
}

$db = getDB();

// Auto-create contact_messages table if not exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS contact_messages (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(120) NOT NULL,
        email       VARCHAR(200) NOT NULL,
        subject     VARCHAR(200) DEFAULT 'General Enquiry',
        message     TEXT NOT NULL,
        ip          VARCHAR(45) NULL,
        status      ENUM('new','read','replied') DEFAULT 'new',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {}

// Save to DB
try {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $st = $db->prepare("INSERT INTO contact_messages (name, email, subject, message, ip) VALUES (?,?,?,?,?)");
    $st->execute([$name, $email, $subject, $message, $ip]);
} catch (\Throwable $e) {
    error_log('contact.php DB error: ' . $e->getMessage());
    // Don't fail — still send email
}

// Send email notification to admin
$siteEmail = getenv('SMTP_FROM') ?: getenv('SENDGRID_FROM') ?: 'noreply@sombazar.com';
$adminEmail = getenv('ADMIN_EMAIL') ?: $siteEmail;

try {
    Mailer::send(
        $adminEmail,
        'SomBazar Admin',
        "New Contact Form: $subject",
        "<h2>New Contact Message</h2>
         <p><strong>From:</strong> " . htmlspecialchars($name) . " &lt;" . htmlspecialchars($email) . "&gt;</p>
         <p><strong>Subject:</strong> " . htmlspecialchars($subject) . "</p>
         <p><strong>Message:</strong></p>
         <blockquote style='border-left:3px solid #f97316;padding-left:12px;color:#374151'>" .
             nl2br(htmlspecialchars($message)) .
         "</blockquote>
         <hr>
         <small>Sent from SomBazar contact form | IP: " . htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "</small>"
    );
} catch (\Throwable $e) {
    error_log('contact.php mailer error: ' . $e->getMessage());
}

// Send confirmation to user
try {
    Mailer::send(
        $email,
        $name,
        'We received your message — SomBazar',
        "<p>Hi " . htmlspecialchars($name) . ",</p>
         <p>Thanks for reaching out! We've received your message and will get back to you within 24 hours.</p>
         <p><strong>Your message:</strong></p>
         <blockquote style='border-left:3px solid #f97316;padding-left:12px;color:#374151'>" .
             nl2br(htmlspecialchars($message)) .
         "</blockquote>
         <p>— The SomBazar Team</p>"
    );
} catch (\Throwable $e) {
    error_log('contact.php user confirmation error: ' . $e->getMessage());
}

jsonSuccess(['message' => 'Thank you! We will get back to you within 24 hours.']);
