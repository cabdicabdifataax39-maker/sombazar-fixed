<?php
/**
 * SomBazar API Documentation
 * Erişim: /api/docs.php (Admin only in production)
 */

// Security: require admin JWT or migration token
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocalhost = in_array($ip, ['127.0.0.1', '::1']);

if (!$isLocalhost) {
    // Require valid MIGRATION_TOKEN or admin JWT
    $token = $_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    $expectedToken = getenv('MIGRATION_TOKEN') ?: '';
    
    $validToken = $expectedToken && hash_equals($expectedToken, $token);
    
    // Also accept admin JWT
    if (!$validToken && $token) {
        try {
            require_once __DIR__ . '/config.php';
            $uid = getAuthUser();
            if ($uid) {
                $db = getDB();
                $st = $db->prepare('SELECT is_admin FROM users WHERE id = ?');
                $st->execute([$uid]);
                $u = $st->fetch();
                $validToken = !empty($u['is_admin']);
            }
        } catch(Throwable $e) {}
    }
    
    if (!$validToken) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo 'Not Found';
        exit;
    }
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SomBazar API Docs v1.0</title>
<style>
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family: -apple-system, 'Segoe UI', sans-serif; background:#0f172a; color:#e2e8f0; line-height:1.6; }
  .sidebar { width:240px; position:fixed; top:0; left:0; height:100vh; background:#1e293b; overflow-y:auto; padding:20px 0; }
  .sidebar h1 { font-size:16px; font-weight:800; padding:0 20px 16px; color:#f97316; border-bottom:1px solid #334155; margin-bottom:12px; }
  .sidebar a { display:block; padding:7px 20px; color:#94a3b8; text-decoration:none; font-size:13px; }
  .sidebar a:hover, .sidebar a.active { color:#f97316; background:rgba(249,115,22,.1); }
  .main { margin-left:240px; padding:40px; max-width:900px; }
  h2 { font-size:22px; font-weight:800; color:#f97316; margin:40px 0 16px; padding-top:20px; border-top:1px solid #1e293b; }
  h2:first-child { border-top:none; }
  h3 { font-size:14px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.5px; margin:24px 0 8px; }
  .endpoint { background:#1e293b; border:1px solid #334155; border-radius:12px; margin-bottom:16px; overflow:hidden; }
  .endpoint-header { display:flex; align-items:center; gap:12px; padding:14px 18px; cursor:pointer; }
  .method { font-size:11px; font-weight:800; padding:3px 10px; border-radius:6px; flex-shrink:0; }
  .GET  { background:#0d3a50; color:#38bdf8; }
  .POST { background:#1a3a25; color:#4ade80; }
  .DELETE { background:#3a1a1a; color:#f87171; }
  .endpoint-path { font-family:monospace; font-size:14px; color:#e2e8f0; }
  .endpoint-desc { font-size:13px; color:#64748b; margin-left:auto; }
  .endpoint-body { display:none; padding:16px 18px; border-top:1px solid #334155; background:#0f172a; font-size:13px; }
  .endpoint-body.open { display:block; }
  pre { background:#1e293b; border-radius:8px; padding:14px; overflow-x:auto; font-size:12px; color:#a5f3fc; margin:10px 0; }
  .param { display:grid; grid-template-columns:160px 80px 1fr; gap:8px; padding:6px 0; border-bottom:1px solid #1e293b; font-size:12px; }
  .param .pname { color:#f97316; font-family:monospace; }
  .param .ptype { color:#94a3b8; }
  .badge { display:inline-block; background:#1e293b; border:1px solid #334155; border-radius:4px; padding:1px 7px; font-size:11px; color:#94a3b8; margin-left:6px; }
  .auth-badge { background:rgba(249,115,22,.15); border-color:#f97316; color:#f97316; }
  .version { font-size:12px; color:#64748b; margin-bottom:24px; }
</style>
</head>
<body>

<div class="sidebar">
  <h1>SomBazar API</h1>
  <a href="#auth">🔐 Authentication</a>
  <a href="#listings">📋 Listings</a>
  <a href="#messages">💬 Messages</a>
  <a href="#offers">🤝 Offers</a>
  <a href="#favorites">❤️ Favorites</a>
  <a href="#reviews">⭐ Reviews</a>
  <a href="#notifications">🔔 Notifications</a>
  <a href="#payment">💳 Payment</a>
  <a href="#upload">📤 Upload</a>
  <a href="#admin">🔧 Admin</a>
  <a href="#health">💚 Health</a>
</div>

<div class="main">
  <h1 style="font-size:28px;font-weight:800;color:#f1f5f9;margin-bottom:8px">SomBazar API</h1>
  <div class="version">Version 1.0.0 · Base URL: <code style="color:#f97316">https://sombazar.com/api/</code></div>

  <div style="background:#1e293b;border:1px solid #f97316;border-radius:10px;padding:16px 20px;margin-bottom:32px;font-size:13px">
    <b style="color:#f97316">Authentication:</b> Bearer token — <code>Authorization: Bearer &lt;jwt_token&gt;</code><br>
    <b style="color:#f97316">Rate Limits:</b> 120 req/min (general) · 20 req/5min (auth) · 30 req/hr (upload)<br>
    <b style="color:#f97316">Response format:</b> <code>{"success": bool, "data": {...}, "error": "..."}</code>
  </div>

  <?php
  $endpoints = [
    ['auth', '🔐 Authentication', [
      ['POST','auth.php?action=register','Register a new user','No auth required','{"email":"","password":"","name":""}','{"token":"jwt","user":{...}}'],
      ['POST','auth.php?action=login','Login','No auth required','{"email":"","password":""}','{"token":"jwt","user":{...}}'],
      ['POST','auth.php?action=forgot_password','Request password reset','No auth required','{"email":""}','{"message":"Email sent"}'],
      ['GET','auth.php?action=me','Get current user','🔐 Auth required','—','{"user":{...}}'],
    ]],
    ['listings', '📋 Listings', [
      ['GET','listings.php?action=list','Get listings (paginated)','Public','?category=&city=&q=&page=','{"listings":[...],"total":N}'],
      ['GET','listings.php?action=get','Get single listing','Public','?id=123','{"listing":{...},"seller":{...}}'],
      ['POST','listings.php?action=create','Create new listing','🔐 Auth','{"title":"","category":"","price":0,"city":""}','{"listing_id":N}'],
      ['POST','listings.php?action=update','Update listing','🔐 Auth + Owner','{"id":N,"title":""}','{"message":"Updated"}'],
      ['POST','listings.php?action=delete','Delete listing','🔐 Auth + Owner','{"id":N}','{"message":"Deleted"}'],
      ['POST','listings.php?action=mark_sold','Mark as sold','🔐 Auth + Owner','{"id":N}','{"message":"Marked as sold"}'],
    ]],
    ['messages', '💬 Messages', [
      ['GET','messages.php?action=conversations','Get conversations','🔐 Auth','—','{"conversations":[...]}'],
      ['GET','messages.php?action=messages','Get messages in conversation','🔐 Auth','?conversation_id=N','{"messages":[...]}'],
      ['POST','messages.php?action=send','Send a message','🔐 Auth','{"listing_id":N,"receiver_id":N,"body":""}','{"message":{...}}'],
    ]],
    ['offers', '🤝 Offers', [
      ['POST','offers.php?action=make','Make an offer','🔐 Auth','{"listing_id":N,"amount":0,"message":""}','{"offer":{...}}'],
      ['POST','offers.php?action=respond','Respond to offer','🔐 Auth + Owner','{"offer_id":N,"action":"accept|reject|counter","counter_amount":0}','{"offer":{...}}'],
      ['GET','offers.php?action=my_offers','Get my offers','🔐 Auth','?type=sent|received','{"offers":[...]}'],
    ]],
    ['payment', '💳 Payment', [
      ['GET','payment.php?action=plans','Get available plans','Public','—','{"plans":{...},"numbers":{...}}'],
      ['GET','payment.php?action=my_plan','Get my current plan','🔐 Auth','—','{"plan":"","expiresAt":"","canPost":true}'],
      ['POST','payment.php?action=initiate','Submit payment','🔐 Auth','{"plan":"","method":"zaad|edahab","reference_code":""}','{"payment_id":N}'],
      ['POST','payment.php?action=apply_coupon','Apply coupon code','Public','{"code":"","plan":""}','{"discount":N,"finalPrice":N}'],
      ['POST','payment.php?action=cancel_plan','Cancel subscription','🔐 Auth','—','{"message":"","plan":"free"}'],
      ['GET','payment.php?action=receipt_html','Download receipt (HTML)','🔐 Auth','?payment_id=N','HTML page'],
    ]],
    ['upload', '📤 Upload', [
      ['POST','upload.php','Upload image','🔐 Auth','multipart/form-data file=<image>','{"url":""}'],
    ]],
    ['health', '💚 Health Check', [
      ['GET','health.php','Server health status','Public','—','{"status":"ok","db":true,"uptime":N}'],
    ]],
  ];

  foreach ($endpoints as [$id, $title, $eps]) {
    echo "<h2 id=\"{$id}\">{$title}</h2>";
    foreach ($eps as [$method, $path, $desc, $auth, $body, $response]) {
      $bodyHtml = $body !== '—' ? "<h3>Request Body</h3><pre>{$body}</pre>" : '';
      $authBadge = $auth !== 'Public' && $auth !== 'No auth required' ? "<span class='badge auth-badge'>🔐 Auth</span>" : "<span class='badge'>Public</span>";
      echo <<<HTML
      <div class="endpoint">
        <div class="endpoint-header" onclick="this.nextElementSibling.classList.toggle('open')">
          <span class="method {$method}">{$method}</span>
          <span class="endpoint-path">/api/{$path}</span>
          {$authBadge}
          <span class="endpoint-desc">{$desc}</span>
        </div>
        <div class="endpoint-body">
          {$bodyHtml}
          <h3>Response</h3>
          <pre>{$response}</pre>
        </div>
      </div>
HTML;
    }
  }
  ?>
</div>
</body>
</html>
