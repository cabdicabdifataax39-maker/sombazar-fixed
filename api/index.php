<?php
/**
 * SomBazar API Router
 * Maps clean REST URLs to PHP handler files + action params.
 * Used by Nginx (replaces Apache .htaccess RewriteRules).
 */

// ── CORS ──────────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Pass Authorization header through PHP-FPM (FastCGI strips it)
if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (isset($headers['Authorization'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $headers['Authorization'];
        }
    }
}

// ── Parse URI ─────────────────────────────────────────────────────────────────
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Strip leading /api/ prefix
$path = preg_replace('#^/api/?#', '', $uri);
$path = trim($path, '/');

// ── Routing ───────────────────────────────────────────────────────────────────

// AUTH  /api/auth/*
if (preg_match('#^auth/login$#', $path)) {
    $_GET['action'] = 'login';
    require __DIR__ . '/auth_shim.php';
    exit;
}
if (preg_match('#^auth/register$#', $path)) {
    $_GET['action'] = 'register';
    require __DIR__ . '/auth_shim.php';
    exit;
}
if (preg_match('#^auth/me$#', $path)) {
    $_GET['action'] = 'me';
    require __DIR__ . '/auth_shim.php';
    exit;
}
if (preg_match('#^auth/logout$#', $path)) {
    $_GET['action'] = 'logout';
    require __DIR__ . '/auth.php';
    exit;
}

// LISTINGS  /api/listings/*
if (preg_match('#^listings/(\d+)/mark-sold$#', $path, $m)) {
    $_GET['action'] = 'mark_sold'; $_GET['id'] = $m[1];
    require __DIR__ . '/listings.php'; exit;
}
if (preg_match('#^listings/(\d+)/mark-active$#', $path, $m)) {
    $_GET['action'] = 'mark_active'; $_GET['id'] = $m[1];
    require __DIR__ . '/listings.php'; exit;
}
if (preg_match('#^listings/(\d+)/relist$#', $path, $m)) {
    $_GET['action'] = 'relist'; $_GET['id'] = $m[1];
    require __DIR__ . '/listings.php'; exit;
}
if (preg_match('#^listings/(\d+)/similar$#', $path, $m)) {
    $_GET['action'] = 'similar'; $_GET['id'] = $m[1];
    require __DIR__ . '/listings.php'; exit;
}
if (preg_match('#^listings/(\d+)/favorite$#', $path, $m)) {
    $_GET['action'] = $method === 'DELETE' ? 'unfavorite' : 'favorite';
    $_GET['id'] = $m[1];
    require __DIR__ . '/listings.php'; exit;
}
if (preg_match('#^listings/(\d+)$#', $path, $m)) {
    $_GET['id'] = $m[1];
    $_GET['action'] = match($method) {
        'GET'    => 'get',
        'DELETE' => 'delete',
        default  => 'update',
    };
    require __DIR__ . '/listings.php'; exit;
}
if (preg_match('#^listings$#', $path)) {
    $_GET['action'] = $method === 'POST' ? 'create' : 'list';
    require __DIR__ . '/listings.php'; exit;
}

// CONVERSATIONS / MESSAGES  /api/conversations/*
if (preg_match('#^conversations/(\d+)/messages$#', $path, $m)) {
    $_GET['conversation_id'] = $m[1];
    $_GET['action'] = $method === 'POST' ? 'send' : 'messages';
    require __DIR__ . '/messages.php'; exit;
}
if (preg_match('#^conversations$#', $path)) {
    $_GET['action'] = $method === 'POST' ? 'start' : 'conversations';
    require __DIR__ . '/messages.php'; exit;
}

// OFFERS  /api/offers/*
if (preg_match('#^offers/(\d+)/respond$#', $path, $m)) {
    $_GET['action'] = 'respond'; $_GET['id'] = $m[1];
    require __DIR__ . '/offers.php'; exit;
}
if (preg_match('#^offers/(\d+)/cancel$#', $path, $m)) {
    $_GET['action'] = 'cancel'; $_GET['id'] = $m[1];
    require __DIR__ . '/offers.php'; exit;
}
if (preg_match('#^offers/(\d+)$#', $path, $m)) {
    $_GET['action'] = 'get'; $_GET['id'] = $m[1];
    require __DIR__ . '/offers.php'; exit;
}
if (preg_match('#^offers$#', $path)) {
    $_GET['action'] = $method === 'POST' ? 'make' : 'my_offers';
    require __DIR__ . '/offers.php'; exit;
}

// RESERVATIONS  /api/reservations/*
if (preg_match('#^reservations/(\d+)/confirm$#', $path, $m)) {
    $_GET['action'] = 'confirm'; $_GET['id'] = $m[1];
    require __DIR__ . '/reservations.php'; exit;
}
if (preg_match('#^reservations/(\d+)/cancel$#', $path, $m)) {
    $_GET['action'] = 'cancel'; $_GET['id'] = $m[1];
    require __DIR__ . '/reservations.php'; exit;
}
if (preg_match('#^reservations/(\d+)$#', $path, $m)) {
    $_GET['action'] = 'get'; $_GET['id'] = $m[1];
    require __DIR__ . '/reservations.php'; exit;
}
if (preg_match('#^reservations$#', $path)) {
    $_GET['action'] = $method === 'POST' ? 'create' : 'list';
    require __DIR__ . '/reservations.php'; exit;
}

// STORES  /api/stores/*
if (preg_match('#^stores/([^/]+)/follow$#', $path, $m)) {
    $_GET['action'] = 'follow'; $_GET['slug'] = $m[1];
    require __DIR__ . '/stores.php'; exit;
}
if (preg_match('#^stores/([^/]+)/unfollow$#', $path, $m)) {
    $_GET['action'] = 'unfollow'; $_GET['slug'] = $m[1];
    require __DIR__ . '/stores.php'; exit;
}
if (preg_match('#^stores/([^/]+)$#', $path, $m)) {
    $_GET['action'] = 'get'; $_GET['slug'] = $m[1];
    require __DIR__ . '/stores.php'; exit;
}

// FAVORITES  /api/favorites/*
if (preg_match('#^favorites/(\d+)$#', $path, $m)) {
    $_GET['action'] = $method === 'DELETE' ? 'unfavorite' : 'favorite';
    $_GET['id'] = $m[1];
    require __DIR__ . '/listings.php'; exit;
}
if (preg_match('#^favorites$#', $path)) {
    $_GET['action'] = 'favorites';
    require __DIR__ . '/listings.php'; exit;
}

// PROFILE  /api/profile/*
if (preg_match('#^profile/listings$#', $path)) {
    $_GET['action'] = 'my_listings';
    require __DIR__ . '/listings.php'; exit;
}
if (preg_match('#^profile/favorites$#', $path)) {
    $_GET['action'] = 'favorites';
    require __DIR__ . '/listings.php'; exit;
}
if (preg_match('#^profile$#', $path)) {
    $_GET['action'] = $method === 'PUT' ? 'update' : 'get';
    require __DIR__ . '/profile.php'; exit;
}

// UPLOAD  /api/upload
if (preg_match('#^upload$#', $path)) {
    require __DIR__ . '/upload.php'; exit;
}

// PLANS / PAYMENT
if (preg_match('#^plans$#', $path)) {
    $_GET['action'] = 'plans';
    require __DIR__ . '/payment.php'; exit;
}
if (preg_match('#^payment/subscribe$#', $path)) {
    $_GET['action'] = 'subscribe';
    require __DIR__ . '/payment.php'; exit;
}

// HEALTH CHECK
if (preg_match('#^health(\.php)?$#', $path)) {
    require __DIR__ . '/health.php'; exit;
}

// ── 404 ───────────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=UTF-8');
http_response_code(404);
echo json_encode(['success' => false, 'error' => "API route not found: $path"]);
