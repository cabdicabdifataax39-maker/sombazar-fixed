<?php
/**
 * SomBazar Mailer — Brevo API (primary) + SMTP fallback
 */

class Mailer {

    private static function getConfig(): array {
        return [
            'host'      => getenv('SMTP_HOST')      ?: 'smtp-relay.brevo.com',
            'port'      => (int)(getenv('SMTP_PORT') ?: 587),
            'user'      => getenv('SMTP_USER')      ?: '',
            'pass'      => getenv('SMTP_PASS')      ?: '',
            'from'      => getenv('SMTP_FROM')      ?: 'noreply@sombazar.com',
            'from_name' => getenv('SMTP_FROM_NAME') ?: 'SomBazar',
            'brevo_key' => getenv('BREVO_API_KEY')  ?: '',
        ];
    }

    public static function send(string $to, string $toName, string $subject, string $htmlBody, string $textBody = ''): bool {
        $cfg = self::getConfig();

        if (empty($textBody)) {
            $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $htmlBody));
        }

        // Primary: Brevo API
        if (!empty($cfg['brevo_key'])) {
            try {
                return self::brevoSend($cfg, $to, $toName, $subject, $htmlBody, $textBody);
            } catch (\Exception $e) {
                error_log("Brevo API error: " . $e->getMessage() . " — falling back to SMTP");
            }
        }

        // Fallback: SMTP
        if (!empty($cfg['user'])) {
            try {
                $boundary  = 'SB_' . md5(uniqid());
                $messageId = '<' . uniqid('sb') . '@sombazar.com>';
                $date      = date('r');

                $headers  = "From: {$cfg['from_name']} <{$cfg['from']}>\r\n";
                $headers .= "To: {$toName} <{$to}>\r\n";
                $headers .= "Subject: {$subject}\r\n";
                $headers .= "Message-ID: {$messageId}\r\n";
                $headers .= "Date: {$date}\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

                $body  = "--{$boundary}\r\n";
                $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
                $body .= quoted_printable_encode($textBody) . "\r\n";
                $body .= "--{$boundary}\r\n";
                $body .= "Content-Type: text/html; charset=UTF-8\r\n";
                $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
                $body .= quoted_printable_encode($htmlBody) . "\r\n";
                $body .= "--{$boundary}--\r\n";

                return self::smtpSend($cfg, $to, $cfg['from'], $headers . "\r\n" . $body);
            } catch (\Exception $e) {
                error_log("SMTP error: " . $e->getMessage());
            }
        }

        error_log("Mailer: no provider configured. Would send to: $to | Subject: $subject");
        return false;
    }

    private static function brevoSend(array $cfg, string $to, string $toName, string $subject, string $html, string $text): bool {
        $payload = json_encode([
            'sender'      => ['name' => $cfg['from_name'], 'email' => $cfg['from']],
            'to'          => [['email' => $to, 'name' => $toName]],
            'subject'     => $subject,
            'htmlContent' => $html,
            'textContent' => $text,
        ]);

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'api-key: ' . $cfg['brevo_key'],
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new \Exception("cURL error: $curlErr");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception("Brevo API returned HTTP $httpCode: $response");
        }

        return true;
    }

    private static function smtpSend(array $cfg, string $to, string $from, string $message): bool {
        $port = $cfg['port'];
        $ssl  = ($port === 465);
        $host = ($ssl ? 'ssl://' : '') . $cfg['host'];
        $sock = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$sock) throw new \Exception("SMTP connect failed: $errstr ($errno)");

        stream_set_timeout($sock, 10);
        self::expect($sock, 220);
        self::cmd($sock, "EHLO sombazar.com", 250);

        if (!$ssl && $port === 587) {
            self::cmd($sock, "STARTTLS", 220);
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            self::cmd($sock, "EHLO sombazar.com", 250);
        }

        self::cmd($sock, "AUTH LOGIN", 334);
        self::cmd($sock, base64_encode($cfg['user']), 334);
        self::cmd($sock, base64_encode($cfg['pass']), 235);
        self::cmd($sock, "MAIL FROM:<{$from}>", 250);
        self::cmd($sock, "RCPT TO:<{$to}>", 250);
        self::cmd($sock, "DATA", 354);
        fwrite($sock, $message . "\r\n.\r\n");
        self::expect($sock, 250);
        self::cmd($sock, "QUIT", 221);
        fclose($sock);
        return true;
    }

    private static function cmd($sock, string $cmd, int $expectedCode): string {
        fwrite($sock, $cmd . "\r\n");
        return self::expect($sock, $expectedCode);
    }

    private static function expect($sock, int $code): string {
        $response = '';
        while ($line = fgets($sock, 512)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        $actual = (int) substr($response, 0, 3);
        if ($actual !== $code) {
            throw new \Exception("SMTP expected $code got $actual: " . trim($response));
        }
        return $response;
    }

    // ── Email Templates ──────────────────────────────────────────

    public static function sendWelcome(string $to, string $name): bool {
        $subject = 'Welcome to SomBazar!';
        $html = self::template("Welcome, {$name}!", "
            <p>Thank you for joining <strong>SomBazar</strong> — Somaliland's largest online marketplace.</p>
            <p>You can now post ads, message sellers, and discover thousands of listings across Somaliland.</p>
            <p style='margin-top:24px'>
              <a href='".SITE_URL."' style='background:#f97316;color:white;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block'>Browse Listings →</a>
            </p>
            <p style='margin-top:24px;font-size:13px;color:#64748b'>Need help? Reply to this email or visit our <a href='".SITE_URL."/faq.html' style='color:#f97316'>Help Center</a>.</p>
        ");
        return self::send($to, $name, $subject, $html);
    }

    public static function sendPasswordReset(string $to, string $name, string $resetUrl): bool {
        $subject = 'Reset Your SomBazar Password';
        $html = self::template('Reset Your Password', "
            <p>Hi <strong>{$name}</strong>,</p>
            <p>We received a request to reset your password. Click the button below to create a new password:</p>
            <p style='margin:28px 0;text-align:center'>
              <a href='{$resetUrl}' style='background:#f97316;color:white;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block;font-size:15px'>Reset Password →</a>
            </p>
            <p style='font-size:13px;color:#64748b'>This link expires in <strong>1 hour</strong>. If you didn't request a password reset, you can safely ignore this email.</p>
            <p style='font-size:12px;color:#94a3b8;margin-top:16px'>Or copy this link: <a href='{$resetUrl}' style='color:#f97316'>{$resetUrl}</a></p>
        ");
        return self::send($to, $name, $subject, $html);
    }

    public static function sendListingApproved(string $to, string $name, string $title, string $listingUrl): bool {
        $subject = "Your listing is live: {$title}";
        $html = self::template('Your Listing is Live! 🎉', "
            <p>Hi <strong>{$name}</strong>,</p>
            <p>Great news! Your listing <strong>\"{$title}\"</strong> has been approved and is now live on SomBazar.</p>
            <p style='margin:24px 0'>
              <a href='{$listingUrl}' style='background:#f97316;color:white;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block'>View Listing →</a>
            </p>
            <p style='font-size:13px;color:#64748b'>Share your listing with friends and family to get more buyers!</p>
        ");
        return self::send($to, $name, $subject, $html);
    }

    public static function sendListingRejected(string $to, string $name, string $title, string $reason): bool {
        $subject = "Listing update required: {$title}";
        $html = self::template('Listing Needs Changes', "
            <p>Hi <strong>{$name}</strong>,</p>
            <p>Your listing <strong>\"{$title}\"</strong> requires some changes before it can be published.</p>
            <div style='background:#fef2f2;border-left:4px solid #ef4444;padding:16px;border-radius:0 8px 8px 0;margin:20px 0'>
              <strong style='color:#b91c1c'>Reason:</strong>
              <p style='color:#7f1d1d;margin:4px 0 0'>{$reason}</p>
            </div>
            <p>Please update your listing and resubmit.</p>
            <p style='margin:20px 0'>
              <a href='".SITE_URL."/profile.html' style='background:#f97316;color:white;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block'>Edit Listing →</a>
            </p>
        ");
        return self::send($to, $name, $subject, $html);
    }

    public static function sendNewMessage(string $to, string $name, string $fromName, string $preview, string $msgUrl): bool {
        $subject = "New message from {$fromName}";
        $html = self::template("New Message", "
            <p>Hi <strong>{$name}</strong>,</p>
            <p><strong>{$fromName}</strong> sent you a message on SomBazar:</p>
            <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin:20px 0;font-style:italic;color:#475569'>
              \"{$preview}\"
            </div>
            <p style='margin:20px 0'>
              <a href='{$msgUrl}' style='background:#f97316;color:white;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block'>Reply Now →</a>
            </p>
        ");
        return self::send($to, $name, $subject, $html);
    }

    public static function sendPaymentApproved(string $to, string $name, string $plan, string $expires): bool {
        $subject = "Payment Approved — {$plan} Plan Active";
        $html = self::template('Payment Approved! 🎉', "
            <p>Hi <strong>{$name}</strong>,</p>
            <p>Your payment has been approved. Your <strong style='color:#f97316;text-transform:capitalize'>{$plan} Plan</strong> is now active.</p>
            <div style='background:#f0fdf4;border:1px solid #86efac;border-radius:12px;padding:20px;margin:20px 0;text-align:center'>
              <div style='font-size:32px;margin-bottom:8px'>✅</div>
              <strong style='font-size:18px;color:#166534;text-transform:capitalize'>{$plan} Plan</strong><br>
              <span style='color:#16a34a;font-size:13px'>Active until {$expires}</span>
            </div>
            <p style='margin:20px 0'>
              <a href='".SITE_URL."/post.html' style='background:#f97316;color:white;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block'>Post an Ad →</a>
            </p>
        ");
        return self::send($to, $name, $subject, $html);
    }

    public static function sendOfferNotification(string $to, string $name, string $listingTitle, string $offerAmount, string $offersUrl): bool {
        $subject = 'New Offer on Your Listing — SomBazar';
        $html = self::template('New Offer Received', "
            <p>Hi <strong>{$name}</strong>, you have received a new offer!</p>
            <p>Someone has made an offer of <strong>{$offerAmount}</strong> on your listing:</p>
            <p style='background:#f8fafc;padding:12px 16px;border-radius:10px;font-weight:700;color:#0f172a'>{$listingTitle}</p>
            <p>Log in to review and respond to this offer within 48 hours.</p>
            <p><a href='{$offersUrl}' style='display:inline-block;background:#f97316;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:700'>View Offer →</a></p>
        ");
        return self::send($to, $name, $subject, $html);
    }

    public static function sendOfferResponded(string $to, string $name, string $listingTitle, string $action, string $offersUrl): bool {
        $subject = "Your Offer was {$action} — SomBazar";
        $color   = $action === 'Accepted' ? '#16a34a' : ($action === 'Countered' ? '#f97316' : '#dc2626');
        $html = self::template("Offer {$action}", "
            <p>Hi <strong>{$name}</strong>, your offer has been responded to.</p>
            <p>The seller has <strong style='color:{$color}'>{$action}</strong> your offer on:</p>
            <p style='background:#f8fafc;padding:12px 16px;border-radius:10px;font-weight:700;color:#0f172a'>{$listingTitle}</p>
            <p><a href='{$offersUrl}' style='display:inline-block;background:#f97316;color:white;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:700'>View Details →</a></p>
        ");
        return self::send($to, $name, $subject, $html);
    }

    public static function sendReceipt(string $to, string $name, string $plan, float $amount, float $discount, string $receiptNo, string $method, string $reference, string $receiptUrl): bool {
        $finalAmount = $amount - $discount;
        $discountHtml = $discount > 0
            ? "<tr><td style='padding:8px 0;color:#64748b'>Discount</td><td style='padding:8px 0;text-align:right;color:#16a34a'>-\${$discount}</td></tr>"
            : '';
        $html = self::template("Payment Confirmed!", "
            <p>Hi {$name}, your payment for <strong>{$plan}</strong> plan has been approved.</p>
            <table width='100%' style='border-collapse:collapse;margin:16px 0'>
                <tr style='border-bottom:1px solid #e2e8f0'><td style='padding:8px 0;color:#64748b'>Receipt</td><td style='padding:8px 0;text-align:right;font-weight:700'>{$receiptNo}</td></tr>
                <tr style='border-bottom:1px solid #e2e8f0'><td style='padding:8px 0;color:#64748b'>Plan</td><td style='padding:8px 0;text-align:right'>{$plan}</td></tr>
                <tr style='border-bottom:1px solid #e2e8f0'><td style='padding:8px 0;color:#64748b'>Amount</td><td style='padding:8px 0;text-align:right'>\${$amount}</td></tr>
                {$discountHtml}
                <tr><td style='padding:10px 0;font-weight:800;font-size:16px'>Total Paid</td><td style='padding:10px 0;text-align:right;font-weight:800;color:#f97316'>\${$finalAmount} USD</td></tr>
            </table>
            <a href='{$receiptUrl}' style='display:block;background:#f97316;color:#fff;text-decoration:none;text-align:center;padding:14px;border-radius:10px;font-weight:700'>Download Receipt →</a>
        ");
        return self::send($to, $name, "Payment Receipt {$receiptNo} — SomBazar", $html);
    }

    public static function sendPlanCancelled(string $to, string $name, string $plan): bool {
        $html = self::template("Plan Cancelled", "
            <p>Hi {$name}, your <strong>{$plan}</strong> plan has been cancelled and you have been moved to the Free plan.</p>
            <p style='color:#64748b;margin-top:12px'>Your existing active listings remain visible. You can upgrade again at any time.</p>
            <a href='".SITE_URL."/packages.html' style='display:block;background:#f97316;color:#fff;text-decoration:none;text-align:center;padding:14px;border-radius:10px;font-weight:700;margin-top:20px'>View Plans →</a>
        ");
        return self::send($to, $name, "Your {$plan} plan has been cancelled — SomBazar", $html);
    }

    public static function sendPaymentReceipt(string $to, string $name, array $payment): bool {
        $planLabel  = ucfirst($payment['plan'] ?? 'Standard');
        $amount     = number_format((float)($payment['amount'] ?? 0), 2);
        $currency   = $payment['currency'] ?? 'USD';
        $date       = date('F j, Y', strtotime($payment['approved_at'] ?? $payment['created_at'] ?? 'now'));
        $ref        = $payment['reference_code'] ?? $payment['id'] ?? 'N/A';
        $receiptNo  = 'SB-' . str_pad($payment['id'] ?? 0, 6, '0', STR_PAD_LEFT);
        $expiresAt  = $payment['plan_expires_at'] ?? null;
        $validUntil = $expiresAt ? date('F j, Y', strtotime($expiresAt)) : '30 days from approval';

        $html = self::template("Payment Confirmed!", "
            <p>Hi <strong>{$name}</strong>, thank you for your payment!</p>
            <div style='background:#f8fafc;border-radius:12px;padding:20px;margin:20px 0'>
              <table style='width:100%;border-collapse:collapse;font-size:14px'>
                <tr><td style='padding:8px 0;color:#64748b'>Receipt No</td><td style='padding:8px 0;text-align:right;font-weight:700'>{$receiptNo}</td></tr>
                <tr><td style='padding:8px 0;color:#64748b'>Reference</td><td style='padding:8px 0;text-align:right'>{$ref}</td></tr>
                <tr><td style='padding:8px 0;color:#64748b'>Plan</td><td style='padding:8px 0;text-align:right'>{$planLabel}</td></tr>
                <tr><td style='padding:8px 0;color:#64748b'>Date</td><td style='padding:8px 0;text-align:right'>{$date}</td></tr>
                <tr><td style='padding:8px 0;color:#64748b'>Valid Until</td><td style='padding:8px 0;text-align:right'>{$validUntil}</td></tr>
                <tr style='border-top:2px solid #e2e8f0'>
                  <td style='padding:12px 0 4px;font-weight:800;font-size:16px'>Total Paid</td>
                  <td style='padding:12px 0 4px;text-align:right;font-weight:800;color:#f97316;font-size:18px'>{$currency} {$amount}</td>
                </tr>
              </table>
            </div>
            <a href='".SITE_URL."/profile.html#payments' style='display:block;background:#f97316;color:#fff;text-decoration:none;text-align:center;padding:14px;border-radius:10px;font-weight:700'>View My Plan →</a>
        ");
        return self::send($to, $name, "Receipt #{$receiptNo} — SomBazar {$planLabel} Plan", $html);
    }

    public static function sendAffiliateCommission(string $to, string $name, float $commission, float $total, string $refCode): bool {
        return self::send($to, $name,
            "You earned \${$commission} commission — SomBazar Affiliate",
            self::template(
                "💰 Commission Earned!",
                "<p>Hi <strong>{$name}</strong>,</p>
                 <p>Great news! You just earned a commission from your referral link.</p>
                 <table style='width:100%;border-collapse:collapse;margin:16px 0'>
                   <tr><td style='padding:10px;background:#f8fafc;border-radius:6px;font-weight:600;color:#374151'>Commission Earned</td>
                       <td style='padding:10px;background:#f8fafc;border-radius:6px;font-weight:800;color:#16a34a;text-align:right'>+\${$commission}</td></tr>
                   <tr><td style='padding:10px;font-weight:600;color:#374151'>Total Pending Payout</td>
                       <td style='padding:10px;font-weight:800;color:#ec5b13;text-align:right'>\${$total}</td></tr>
                   <tr><td style='padding:10px;background:#f8fafc;border-radius:6px;font-weight:600;color:#374151'>Your Ref Code</td>
                       <td style='padding:10px;background:#f8fafc;border-radius:6px;font-family:monospace;font-weight:700;text-align:right'>{$refCode}</td></tr>
                 </table>
                 <p style='color:#64748b;font-size:13px'>Once your balance reaches \$5, contact us via the support page and we'll send your payment via Zaad or eDahab within 48 hours.</p>"
            )
        );
    }

    public static function sendAffiliateApplicationNotification(string $adminEmail, string $applicantName, string $applicantEmail): bool {
        return self::send($adminEmail, 'SomBazar Admin',
            "New Affiliate Application — {$applicantName}",
            self::template(
                "New Affiliate Application",
                "<p>A new user has applied for the affiliate program.</p>
                 <table style='width:100%;border-collapse:collapse;margin:16px 0'>
                   <tr><td style='padding:10px;background:#f8fafc;border-radius:6px;font-weight:600;color:#374151'>Name</td>
                       <td style='padding:10px;background:#f8fafc;border-radius:6px;font-weight:700'>" . htmlspecialchars($applicantName) . "</td></tr>
                   <tr><td style='padding:10px;font-weight:600;color:#374151'>Email</td>
                       <td style='padding:10px;font-weight:700'>" . htmlspecialchars($applicantEmail) . "</td></tr>
                 </table>
                 <p><a href='https://sombazar-fixed-production.up.railway.app/admin.html#affiliates'
                    style='background:#ec5b13;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block'>
                    Review in Admin Panel →</a></p>"
            )
        );
    }

    private static function template(string $heading, string $content): string {
        $year    = date('Y');
        $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://sombazar.com';
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:'Segoe UI',Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:40px 20px">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">
        <tr><td style="background:linear-gradient(135deg,#0c1445,#1a0a3c);border-radius:16px 16px 0 0;padding:28px 32px;text-align:center">
          <a href="{$siteUrl}" style="font-size:26px;font-weight:900;color:#f97316;text-decoration:none;letter-spacing:-0.5px">SomBazar</a>
          <p style="color:rgba(255,255,255,0.6);font-size:12px;margin:4px 0 0">Somaliland's Largest Marketplace</p>
        </td></tr>
        <tr><td style="background:white;padding:32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0">
          <h2 style="font-size:22px;font-weight:800;color:#0f172a;margin:0 0 20px">{$heading}</h2>
          <div style="font-size:15px;color:#334155;line-height:1.7">{$content}</div>
        </td></tr>
        <tr><td style="background:#f1f5f9;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 16px 16px;padding:20px 32px;text-align:center">
          <p style="font-size:12px;color:#94a3b8;margin:0">© {$year} SomBazar. All rights reserved.<br>
          <a href="{$siteUrl}/privacy.html" style="color:#f97316;text-decoration:none">Privacy Policy</a> ·
          <a href="{$siteUrl}/terms.html" style="color:#f97316;text-decoration:none">Terms</a></p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }
}
