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
        echo json_encode(['success' => false, 'error' => 'PHP Error: ' . $err['message'] . ' on line ' . $err['line']]);
    }
});
require_once __DIR__ . '/config.php';
// Rate limit — otomatik uygula
// Rate limit: sadece ödeme initiate ve apply_coupon için (okuma işlemleri sınırlanmasın)
if ($_SERVER['REQUEST_METHOD'] === 'POST') applyEndpointRateLimit('payment');

require_once __DIR__ . '/mailer.php';

$action = $_GET['action'] ?? '';

// Paket fiyatları ve limitleri
const PLANS = [
    'standard' => ['price' => 8,  'label' => 'Standard', 'listing_limit' => 10,  'photo_limit' => 5,  'boost_credits' => 0],
    'pro'      => ['price' => 20, 'label' => 'Pro',      'listing_limit' => 999, 'photo_limit' => 15, 'boost_credits' => 2],
    'agency'   => ['price' => 50, 'label' => 'Agency',   'listing_limit' => 999, 'photo_limit' => 20, 'boost_credits' => 5],
];

const PAYMENT_NUMBERS = [
    'zaad'   => ['number' => '063XXXXXXX', 'name' => 'SomBazar Ltd'],
    'edahab' => ['number' => '077XXXXXXX', 'name' => 'SomBazar Ltd'],
];

// Auto-create tables if not exist
function ensureTables(): void {
    try { getDB()->exec("ALTER TABLE payments ADD UNIQUE INDEX idx_ref_unique (reference_code)"); } catch(\Throwable $e) {}
    $db = getDB();
    try {
        $db->exec("ALTER TABLE users ADD COLUMN plan VARCHAR(20) DEFAULT 'free'");
        $db->exec("ALTER TABLE users ADD COLUMN plan_expires_at DATETIME NULL");
    } catch(\Throwable $e) {}
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            plan VARCHAR(20) NOT NULL,
            amount DECIMAL(8,2) NOT NULL,
            method VARCHAR(20) NOT NULL,
            reference_code VARCHAR(100) NOT NULL UNIQUE,
            screenshot_url VARCHAR(500) NULL,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            admin_note VARCHAR(300) NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            idempotency_key VARCHAR(100) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
    } catch(\Throwable $e) {}
    // Discount codes table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS discount_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            type ENUM('percent','fixed') DEFAULT 'percent',
            value DECIMAL(8,2) NOT NULL,
            max_uses INT DEFAULT 0,
            uses_count INT DEFAULT 0,
            min_plan VARCHAR(20) NULL,
            expires_at DATETIME NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
    } catch(\Throwable $e) {}
    // Affiliates table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS affiliates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            ref_code VARCHAR(20) NOT NULL UNIQUE,
            commission_rate DECIMAL(5,2) DEFAULT 10.00,
            total_referrals INT DEFAULT 0,
            total_earned DECIMAL(10,2) DEFAULT 0.00,
            pending_payout DECIMAL(10,2) DEFAULT 0.00,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
    } catch(\Throwable $e) {}
    // referral_code column on users
    try { $db->exec("ALTER TABLE users ADD COLUMN ref_code VARCHAR(20) NULL"); } catch(\Throwable $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN referred_by INT NULL"); } catch(\Throwable $e) {}
    try { $db->exec("ALTER TABLE payments ADD COLUMN coupon_code VARCHAR(50) NULL"); } catch(\Throwable $e) {}
    try { $db->exec("ALTER TABLE payments ADD COLUMN discount_amount DECIMAL(8,2) DEFAULT 0"); } catch(\Throwable $e) {}
    try { $db->exec("ALTER TABLE payments ADD COLUMN affiliate_id INT NULL"); } catch(\Throwable $e) {}
}

ensureTables();

switch ($action) {
    case 'plans':    handlePlans();    break;
    case 'my_plan':  handleMyPlan();   break;
    case 'initiate': handleInitiate(); break;
    case 'status':   handleStatus();   break;
    case 'history':  handleHistory();  break;
    case 'apply_coupon':  handleApplyCoupon();  break;
    case 'public_coupons': handlePublicCoupons(); break;
    case 'cancel_plan':   handleCancelPlan();   break;
    case 'trial':         handleTrial();        break;
    case 'upgrade':       handleUpgrade();      break;
    case 'receipt':       handleReceipt();      break;
    case 'receipt_html':  handleReceiptHTML();  break;
    default: jsonError('Unknown action');
}

function handlePlans(): void {
    jsonSuccess(['plans' => PLANS, 'numbers' => PAYMENT_NUMBERS]);
}

function handleMyPlan(): void {
    $uid = requireAuth();
    $db  = getDB();

    // Ensure plan columns exist (auto-migrate)
    try { $db->exec("ALTER TABLE users ADD COLUMN plan VARCHAR(20) NOT NULL DEFAULT 'free'"); } catch(\Throwable $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN plan_expires_at DATETIME NULL"); } catch(\Throwable $e) {}

    try {
        $st = $db->prepare('SELECT plan, plan_expires_at FROM users WHERE id = ?');
        $st->execute([$uid]);
        $u = $st->fetch();
    } catch(\Throwable $e) {
        $u = ['plan' => 'free', 'plan_expires_at' => null];
    }

    $plan    = $u['plan'] ?? 'free';
    $expires = $u['plan_expires_at'] ?? null;

    // Süresi dolmuşsa free'ye düşür
    if ($expires && strtotime($expires) < time() && $plan !== 'free') {
        try {
            $db->prepare('UPDATE users SET plan = ?, plan_expires_at = NULL WHERE id = ?')
               ->execute(['free', $uid]);
        } catch(\Throwable $e) {}
        $plan    = 'free';
        $expires = null;
    }

    $limits = $plan === 'free'
        ? ['listing_limit' => 3, 'photo_limit' => 2, 'boost_credits' => 0]
        : (PLANS[$plan] ?? ['listing_limit' => 3, 'photo_limit' => 2, 'boost_credits' => 0]);

    // Aktif ilan sayısı
    $activeCount = 0;
    try {
        $st2 = $db->prepare("SELECT COUNT(*) FROM listings WHERE user_id = ? AND status NOT IN ('deleted','expired')");
        $st2->execute([$uid]);
        $activeCount = (int)$st2->fetchColumn();
    } catch(\Throwable $e) {}

    $limit = $limits['listing_limit'];
    jsonSuccess([
        'plan'           => $plan,
        'expiresAt'      => $expires,
        'listingLimit'   => $limit,
        'photoLimit'     => $limits['photo_limit'],
        'boostCredits'   => $limits['boost_credits'],
        'activeListings' => $activeCount,
        'canPost'        => $limit >= 999 || $activeCount < $limit,
    ]);
}

function handleInitiate(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $plan       = trim($data['plan']            ?? '');
    $method     = trim($data['method']          ?? '');
    $ref        = trim($data['reference_code']  ?? '');
    $screenshot = trim($data['screenshot_url']  ?? '');
    $couponCode = strtoupper(trim($data['coupon_code'] ?? ''));
    $affiliateRef = trim($data['ref'] ?? $_GET['ref'] ?? '');  // affiliate referral kodu

    if (!isset(PLANS[$plan]))                    jsonError('Invalid plan');
    if (!in_array($method, ['zaad','edahab']))   jsonError('Invalid payment method');
    if (strlen($ref) < 4)                        jsonError('Reference code too short');

    $db        = getDB();
    $plan_data = PLANS[$plan];
    $idem      = 'pay_' . $uid . '_' . $plan . '_' . substr(md5($ref), 0, 8);

    // Kupon doğrula ve indirim hesapla
    $discountAmount = 0.0;
    $validCouponId  = null;
    if ($couponCode) {
        try {
            $cSt = $db->prepare("SELECT * FROM discount_codes WHERE code = ? AND is_active = 1");
            $cSt->execute([$couponCode]);
            $coupon = $cSt->fetch();
            if ($coupon
                && (!$coupon['expires_at'] || strtotime($coupon['expires_at']) >= time())
                && (!$coupon['max_uses'] || $coupon['uses_count'] < $coupon['max_uses'])
                && (empty($coupon['min_plan']) || $coupon['min_plan'] === $plan)
            ) {
                $price = (float)$plan_data['price'];
                $discountAmount = $coupon['type'] === 'percent'
                    ? round($price * ($coupon['value'] / 100), 2)
                    : min((float)$coupon['value'], $price);
                $validCouponId = (int)$coupon['id'];
            } else {
                $couponCode = ''; // geçersizse temizle
            }
        } catch(\Throwable $e) { $couponCode = ''; }
    }

    // Affiliate ID bul
    $affiliateId = null;
    if ($affiliateRef) {
        try {
            $affSt = $db->prepare("SELECT id FROM affiliates WHERE ref_code = ? AND status = 'approved'");
            $affSt->execute([$affiliateRef]);
            $aff = $affSt->fetch();
            if ($aff) $affiliateId = (int)$aff['id'];
        } catch(\Throwable $e) {}
    }

    try {
        $chk = $db->prepare('SELECT id, status FROM payments WHERE idempotency_key = ?');
        $chk->execute([$idem]);
        $existing = $chk->fetch();
        if ($existing) {
            if ($existing['status'] === 'pending')  jsonSuccess(['payment_id' => $existing['id'], 'status' => 'pending', 'message' => 'Already submitted, awaiting review.']);
            if ($existing['status'] === 'approved') jsonError('This payment was already processed.');
        }

        $refChk = $db->prepare('SELECT id FROM payments WHERE reference_code = ?');
        $refChk->execute([$ref]);
        if ($refChk->fetch()) jsonError('This reference code has already been used.');

        $finalAmount = max(0, (float)$plan_data['price'] - $discountAmount);
        $st = $db->prepare('INSERT INTO payments (user_id, plan, amount, method, reference_code, screenshot_url, idempotency_key, affiliate_id, coupon_code, discount_amount) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$uid, $plan, $finalAmount, $method, $ref, $screenshot ?: null, $idem, $affiliateId, $couponCode ?: null, $discountAmount]);
        $paymentId = $db->lastInsertId();

        // Kupon kullanım sayısını artır
        if ($validCouponId) {
            try {
                $db->prepare("UPDATE discount_codes SET uses_count = uses_count + 1 WHERE id = ?")
                   ->execute([$validCouponId]);
            } catch(\Throwable $e) {}
        }

        // Affiliate toplam referral sayısını artır
        if ($affiliateId) {
            try {
                $db->prepare("UPDATE affiliates SET total_referrals = total_referrals + 1 WHERE id = ?")
                   ->execute([$affiliateId]);
            } catch(\Throwable $e) {}
        }

        jsonSuccess([
            'payment_id'      => $paymentId,
            'status'          => 'pending',
            'message'         => 'Payment submitted. We will review within 2 hours.',
            'amount'          => $finalAmount,
            'original_amount' => $plan_data['price'],
            'discount'        => $discountAmount,
            'plan'            => $plan_data['label'],
            'coupon_used'     => $couponCode ?: null,
        ]);
    } catch(\Throwable $e) {
        jsonError('Database error: ' . $e->getMessage());
    }
}

function handleStatus(): void {
    $uid = requireAuth();
    $pid = (int)($_GET['payment_id'] ?? 0);
    if (!$pid) jsonError('Missing payment_id');

    $db = getDB();
    try {
        $st = $db->prepare('SELECT * FROM payments WHERE id = ? AND user_id = ?');
        $st->execute([$pid, $uid]);
        $p = $st->fetch();
        if (!$p) jsonError('Payment not found', 404);
        jsonSuccess(['id' => $p['id'], 'plan' => $p['plan'], 'amount' => $p['amount'],
                     'method' => $p['method'], 'status' => $p['status'],
                     'adminNote' => $p['admin_note'], 'createdAt' => $p['created_at']]);
    } catch(\Throwable $e) {
        jsonError('Database error');
    }
}

function handleHistory(): void {
    $uid = requireAuth();
    $db  = getDB();
    try {
        $st = $db->prepare('SELECT id, plan, amount, method, status, reference_code, created_at, reviewed_at FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
        $st->execute([$uid]);
        jsonSuccess(['payments' => $st->fetchAll()]);
    } catch(\Throwable $e) {
        jsonSuccess(['payments' => []]);
    }
}

// ── Yeni action'lar ──────────────────────────────────────────
// (switch'in default'undan önce çalışmaz — yeni switch ekle)

// ── Kupon Kodu Uygula ────────────────────────────────────────
function handlePublicCoupons(): void {
    // Giriş gerekmez - herkese açık aktif kuponları listele
    $db = getDB();
    $plan = trim($_GET['plan'] ?? '');
    try {
        $q = "SELECT code, type, value, expires_at, max_uses, uses_count,
                     (CASE WHEN max_uses > 0 THEN CONCAT(uses_count,'/',max_uses,' used') ELSE 'Unlimited' END) as usage_info
              FROM discount_codes
              WHERE is_active = 1
                AND (expires_at IS NULL OR expires_at > NOW())
                AND (max_uses = 0 OR uses_count < max_uses)";
        $params = [];
        if ($plan) {
            $q .= " AND (min_plan IS NULL OR min_plan = '' OR min_plan = ?)";
            $params[] = $plan;
        }
        $q .= " ORDER BY value DESC LIMIT 10";
        $st = $db->prepare($q);
        $st->execute($params);
        $coupons = $st->fetchAll();
        jsonSuccess(['coupons' => $coupons]);
    } catch(\Throwable $e) {
        jsonSuccess(['coupons' => []]);
    }
}

function handleApplyCoupon(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $code = strtoupper(trim($data['code'] ?? ''));
    $plan = trim($data['plan'] ?? '');
    $price = (float)(PLANS[$plan]['price'] ?? 0);

    if (!$code)  jsonError('Coupon code required');
    if (!$price) jsonError('Invalid plan');

    $db = getDB();
    try {
        $st = $db->prepare("SELECT * FROM discount_codes WHERE code = ? AND is_active = 1");
        $st->execute([$code]);
        $coupon = $st->fetch();

        if (!$coupon) jsonError('Invalid or expired coupon code', 404);

        // Expiry check
        if ($coupon['expires_at'] && strtotime($coupon['expires_at']) < time())
            jsonError('This coupon has expired');

        // Usage limit (DB column is uses_count)
        if ($coupon['max_uses'] > 0 && $coupon['uses_count'] >= $coupon['max_uses'])
            jsonError('This coupon has reached its usage limit');

        // Plan restriction
        if (!empty($coupon['min_plan']) && $coupon['min_plan'] !== $plan)
            jsonError('This coupon is only valid for the ' . ucfirst($coupon['min_plan']) . ' plan');

        // Calculate discount
        if ($coupon['type'] === 'percent') {
            $discount = round($price * ($coupon['value'] / 100), 2);
        } else {
            $discount = min((float)$coupon['value'], $price);
        }
        $finalPrice = max(0, $price - $discount);

        jsonSuccess([
            'valid'      => true,
            'code'       => $coupon['code'],
            'type'       => $coupon['type'],
            'value'      => $coupon['value'],
            'discount'   => $discount,
            'finalPrice' => $finalPrice,
            'couponId'   => $coupon['id'],
            'label'      => $coupon['type'] === 'percent'
                ? number_format($coupon['value'], 0) . '% off'
                : '$' . number_format($discount, 2) . ' off',
            'message'    => $coupon['type'] === 'percent'
                ? number_format($coupon['value'], 0) . '% discount applied!'
                : '$' . number_format($discount, 2) . ' discount applied!',
        ]);
    } catch(\Throwable $e) {
        jsonError('Coupon validation failed: ' . $e->getMessage());
    }
}

// ── Plan İptal ───────────────────────────────────────────────
function handleCancelPlan(): void {
    $uid = requireAuth();
    $db  = getDB();

    try {
        $st = $db->prepare('SELECT plan, plan_expires_at FROM users WHERE id = ?');
        $st->execute([$uid]);
        $u = $st->fetch();
    } catch(\Throwable $e) { jsonError('Database error'); }

    $plan = $u['plan'] ?? 'free';
    if ($plan === 'free') jsonError('You are already on the Free plan');

    // Plan'ı free'ye düşür (süresi dolana kadar mevcut özellikleri koru)
    // Gerçekte: plan_expires_at'i bugüne set et (anında iptal) VEYA süresi dolana bırak
    $cancelMode = 'immediate'; // 'immediate' | 'at_expiry'

    try {
        if ($cancelMode === 'immediate') {
            $db->prepare("UPDATE users SET plan = 'free', plan_expires_at = NULL WHERE id = ?")
               ->execute([$uid]);
            $message = 'Your plan has been cancelled. You are now on the Free plan.';
        } else {
            $db->prepare("UPDATE users SET plan_cancels_at = NOW() WHERE id = ?")
               ->execute([$uid]);
            $message = "Your plan will be cancelled at the end of the current period ({$u['plan_expires_at']}).";
        }
    } catch(\Throwable $e) { jsonError('Database error'); }

    // Email gönder
    try {
        $user = getDB()->prepare('SELECT email, display_name FROM users WHERE id = ?');
        $user->execute([$uid]);
        $userData = $user->fetch();
        if ($userData) {
            Mailer::sendPlanCancelled(
                $userData['email'],
                $userData['display_name'],
                strtoupper($plan[0]) . substr($plan, 1)
            );
        }
    } catch(\Throwable $e) {}

    jsonSuccess(['message' => $message, 'plan' => 'free']);
}

// ── Ödeme Makbuzu (JSON) ─────────────────────────────────────
function handleReceipt(): void {
    $uid = requireAuth();
    $pid = (int)($_GET['payment_id'] ?? 0);
    if (!$pid) jsonError('Missing payment_id');

    $db = getDB();
    try {
        $st = $db->prepare('
            SELECT p.*, u.display_name, u.email, u.city
            FROM payments p
            JOIN users u ON u.id = p.user_id
            WHERE p.id = ? AND p.user_id = ? AND p.status = "approved"
        ');
        $st->execute([$pid, $uid]);
        $p = $st->fetch();
    } catch(\Throwable $e) { jsonError('Database error'); }

    if (!$p) jsonError('Receipt not found or payment not approved', 404);

    $planData  = PLANS[$p['plan']] ?? ['label' => ucfirst($p['plan']), 'price' => $p['amount']];
    $receiptNo = 'SB-' . str_pad($p['id'], 6, '0', STR_PAD_LEFT);

    jsonSuccess(['receipt' => [
        'receiptNo'    => $receiptNo,
        'date'         => $p['reviewed_at'] ?: $p['created_at'],
        'customerName' => $p['display_name'],
        'customerEmail'=> $p['email'],
        'city'         => $p['city'] ?: 'Hargeisa',
        'plan'         => $planData['label'],
        'amount'       => (float)$p['amount'],
        'discount'     => (float)($p['discount_amount'] ?? 0),
        'finalAmount'  => (float)$p['amount'], // amount zaten finalAmount olarak kaydedildi
        'method'       => strtoupper($p['method']),
        'reference'    => $p['reference_code'],
        'couponCode'   => $p['coupon_code'] ?: null,
        'currency'     => 'USD',
        'status'       => 'Paid',
        'validUntil'   => date('Y-m-d', strtotime('+30 days', strtotime($p['created_at']))),
    ]]);
}

// ── Ödeme Makbuzu (HTML — print-ready) ───────────────────────
function handleReceiptHTML(): void {
    $uid = requireAuth();
    $pid = (int)($_GET['payment_id'] ?? 0);
    if (!$pid) { http_response_code(404); exit; }

    $db = getDB();
    try {
        $st = $db->prepare('
            SELECT p.*, u.display_name, u.email, u.city, u.phone
            FROM payments p
            JOIN users u ON u.id = p.user_id
            WHERE p.id = ? AND p.user_id = ? AND p.status = "approved"
        ');
        $st->execute([$pid, $uid]);
        $p = $st->fetch();
    } catch(\Throwable $e) { http_response_code(500); exit; }

    if (!$p) { http_response_code(404); echo "Receipt not found"; exit; }

    $planData  = PLANS[$p['plan']] ?? ['label' => ucfirst($p['plan'])];
    $receiptNo = 'SB-' . str_pad($p['id'], 6, '0', STR_PAD_LEFT);
    $date      = date('d M Y', strtotime($p['reviewed_at'] ?: $p['created_at']));
    $discount      = (float)($p['discount_amount'] ?? 0);
    $originalPrice = $discount > 0 ? ((float)$p['amount'] + $discount) : (float)$p['amount'];
    $total         = (float)$p['amount']; // amount zaten finalAmount

    header('Content-Type: text/html; charset=UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receipt #{$receiptNo} — SomBazar</title>
<style>
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family: 'DM Sans', -apple-system, sans-serif; background:#f8fafc; color:#1e293b; }
  .receipt { max-width:520px; margin:40px auto; background:#fff; border-radius:16px;
             box-shadow:0 4px 24px rgba(0,0,0,.1); overflow:hidden; }
  .header  { background:linear-gradient(135deg,#f97316,#ea580c); color:#fff; padding:32px 36px; text-align:center; }
  .logo    { font-size:24px; font-weight:800; letter-spacing:-.5px; margin-bottom:4px; }
  .logo span { background:rgba(255,255,255,.2); padding:2px 8px; border-radius:6px; margin-right:4px; }
  .receipt-no { font-size:13px; opacity:.85; margin-top:8px; }
  .body    { padding:32px 36px; }
  .row     { display:flex; justify-content:space-between; align-items:center;
             padding:12px 0; border-bottom:1px solid #f1f5f9; font-size:14px; }
  .row:last-child { border:none; }
  .row .label { color:#64748b; font-weight:500; }
  .row .value { font-weight:600; text-align:right; }
  .total   { background:#fff7ed; border-radius:12px; padding:16px 20px; margin:20px 0;
             display:flex; justify-content:space-between; align-items:center; }
  .total .t-label { font-size:14px; font-weight:700; color:#92400e; }
  .total .t-value { font-size:24px; font-weight:800; color:#f97316; }
  .badge   { display:inline-block; background:#dcfce7; color:#166534; border-radius:20px;
             padding:4px 14px; font-size:12px; font-weight:700; }
  .footer  { background:#f8fafc; padding:20px 36px; text-align:center; font-size:12px; color:#94a3b8; border-top:1px solid #f1f5f9; }
  .print-btn { display:block; margin:24px auto 0; background:#f97316; color:#fff; border:none;
               border-radius:10px; padding:10px 28px; font-size:14px; font-weight:700; cursor:pointer; }
  @media print { .print-btn { display:none; } body { background:#fff; } .receipt { box-shadow:none; } }
</style>
</head>
<body>
<div class="receipt">
  <div class="header">
    <div class="logo"><span>SB</span> SomBazar</div>
    <div style="font-size:15px;margin-top:8px;opacity:.9">Payment Receipt</div>
    <div class="receipt-no">Receipt No: {$receiptNo} &nbsp;·&nbsp; {$date}</div>
  </div>
  <div class="body">
    <div class="row"><span class="label">Customer</span><span class="value">{$p['display_name']}</span></div>
    <div class="row"><span class="label">Email</span><span class="value">{$p['email']}</span></div>
    <div class="row"><span class="label">Plan</span><span class="value">{$planData['label']}</span></div>
    <div class="row"><span class="label">Payment Method</span><span class="value">{$p['method']}</span></div>
    <div class="row"><span class="label">Reference Code</span><span class="value" style="font-family:monospace">{$p['reference_code']}</span></div>
HTML;
    if ($discount > 0) {
        echo '<div class="row"><span class="label">Original Price</span><span class="value">$' . number_format($originalPrice, 2) . '</span></div>';
        echo '<div class="row"><span class="label">Discount (' . htmlspecialchars($p['coupon_code'] ?? '') . ')</span><span class="value" style="color:#16a34a">-$' . number_format($discount, 2) . '</span></div>';
    }
    echo <<<HTML2
    <div class="total">
      <span class="t-label">Total Paid</span>
      <span class="t-value">\${$total} USD</span>
    </div>
    <div class="row"><span class="label">Status</span><span class="value"><span class="badge">✓ Paid</span></span></div>
    <div class="row"><span class="label">Valid Until</span><span class="value">{$date}</span></div>
  </div>
  <div class="footer">
    Thank you for using SomBazar &nbsp;·&nbsp; support@sombazar.com<br>
    This is an official payment receipt. Keep it for your records.
  </div>
</div>
<button class="print-btn" onclick="window.print()">🖨 Print / Save as PDF</button>
</body>
</html>
HTML2;
    exit;
}


// ── Plan Upgrade/Downgrade ─────────────────────────────────────
function handleUpgrade(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $newPlan = trim($data['plan'] ?? '');
    if (!$newPlan || !isset(PLANS[$newPlan])) jsonError('Invalid plan');

    $db = getDB();
    try {
        $st = $db->prepare('SELECT plan, plan_expires_at FROM users WHERE id = ?');
        $st->execute([$uid]);
        $user = $st->fetch();
    } catch(\Throwable $e) { jsonError('Database error'); }

    $currentPlan = $user['plan'] ?? 'free';
    if ($currentPlan === $newPlan) jsonError('You are already on this plan');

    $planPrices = array_column(PLANS, 'price', 'key');
    $currentPrice = (float)($planPrices[$currentPlan] ?? 0);
    $newPrice     = (float)(PLANS[$newPlan]['price'] ?? 0);
    $isUpgrade    = $newPrice > $currentPrice;

    // Kalan süreye göre kredi hesapla (upgrade ise)
    $credit = 0;
    if ($isUpgrade && $user['plan_expires_at'] && $currentPrice > 0) {
        $remaining = max(0, strtotime($user['plan_expires_at']) - time());
        $totalSecs  = 30 * 24 * 3600; // 30 gün
        $credit = round($currentPrice * ($remaining / $totalSecs), 2);
    }

    jsonSuccess([
        'currentPlan' => $currentPlan,
        'newPlan'     => $newPlan,
        'newPrice'    => $newPrice,
        'credit'      => $credit,
        'netAmount'   => max(0, $newPrice - $credit),
        'isUpgrade'   => $isUpgrade,
        'message'     => $isUpgrade
            ? "Upgrade to " . ucfirst($newPlan) . ". You have $" . number_format($credit, 2) . " credit from your current plan."
            : "Downgrade to " . ucfirst($newPlan) . ". Your current plan features will be active until expiry.",
    ]);
}

// ── 7-Day Free Trial ───────────────────────────────────────────
function handleTrial(): void {
    $uid  = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $plan = trim($data['plan'] ?? 'standard');

    if (!isset(PLANS[$plan]) || $plan === 'free') jsonError('Invalid plan for trial');

    $db = getDB();
    try {
        // Daha önce trial kullandı mı?
        $st = $db->prepare("SELECT id, plan, trial_used FROM users WHERE id = ?");
        $st->execute([$uid]);
        $user = $st->fetch();
    } catch(\Throwable $e) { jsonError('Database error'); }

    if ($user['trial_used']) jsonError('You have already used your free trial');
    if ($user['plan'] !== 'free') jsonError('Free trial is only available for free plan users');

    try {
        $trialEnd = date('Y-m-d H:i:s', strtotime('+7 days'));
        $db->prepare("UPDATE users SET plan = ?, plan_expires_at = ?, trial_used = 1 WHERE id = ?")
           ->execute([$plan, $trialEnd, $uid]);
    } catch(\Throwable $e) { jsonError('Database error'); }

    jsonSuccess([
        'plan'       => $plan,
        'trialEnd'   => $trialEnd,
        'message'    => "Your 7-day free trial of " . ucfirst($plan) . " has started!",
    ]);
}
