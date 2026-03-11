<?php
// Dynamic sitemap - ilan sayfalarını Google'a bildir
header('Content-Type: application/xml; charset=UTF-8');
require_once __DIR__ . '/api/config.php';

// Domain - SITE_URL env'den al, yoksa HTTP_HOST'tan
$domain = defined('SITE_URL') ? rtrim(SITE_URL, '/') : rtrim(($_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'sombazar.com'), '/');

$db   = getDB();
$now  = date('c');
$urls = [];

// Aktif ilanlar
try {
    $st = $db->query("SELECT id, title, updated_at, created_at FROM listings WHERE status='active' ORDER BY created_at DESC LIMIT 5000");
    while ($row = $st->fetch()) {
        $lastmod = $row['updated_at'] ?: $row['created_at'];
        $lastmod = $lastmod ? date('c', strtotime($lastmod)) : $now;
        $urls[] = [
            'loc'      => $domain . '/listing.html?id=' . (int)$row['id'],
            'lastmod'  => $lastmod,
            'priority' => '0.7',
            'changefreq' => 'weekly',
        ];
    }
} catch(Exception $e) {}

// Public profiller
try {
    $st = $db->query("SELECT id, display_name, updated_at FROM users WHERE is_admin=0 AND banned=0 AND deleted_at IS NULL LIMIT 1000");
    while ($row = $st->fetch()) {
        $urls[] = [
            'loc'      => $domain . '/user.html?id=' . (int)$row['id'],
            'lastmod'  => $now,
            'priority' => '0.5',
            'changefreq' => 'monthly',
        ];
    }
} catch(Exception $e) {}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// Statik sayfalar
$staticPages = [
    ['/',               '1.0', 'daily'],
    ['/listings.html',  '0.9', 'hourly'],
    ['/listings.html?category=car',         '0.8', 'hourly'],
    ['/listings.html?category=house',       '0.8', 'hourly'],
    ['/listings.html?category=electronics', '0.8', 'hourly'],
    ['/listings.html?category=jobs',        '0.8', 'hourly'],
    ['/listings.html?category=furniture',   '0.7', 'daily'],
    ['/listings.html?category=land',        '0.7', 'daily'],
    ['/listings.html?category=services',    '0.7', 'daily'],
    ['/auth.html',      '0.4', 'monthly'],
];

foreach ($staticPages as [$path, $priority, $freq]) {
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($domain . $path) . "</loc>\n";
    echo "    <lastmod>$now</lastmod>\n";
    echo "    <changefreq>$freq</changefreq>\n";
    echo "    <priority>$priority</priority>\n";
    echo "  </url>\n";
}

foreach ($urls as $url) {
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($url['loc']) . "</loc>\n";
    echo "    <lastmod>" . htmlspecialchars($url['lastmod']) . "</lastmod>\n";
    echo "    <changefreq>" . htmlspecialchars($url['changefreq']) . "</changefreq>\n";
    echo "    <priority>" . htmlspecialchars($url['priority']) . "</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>';
