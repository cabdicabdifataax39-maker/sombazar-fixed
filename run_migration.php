<?php
/**
 * SomBazar — Migration Runner
 * Bu dosyayı sombazar-fixed klasörüne koy, tarayıcıda aç, sil.
 * URL: https://sombazar-fixed-production.up.railway.app/run_migration.php
 */

// Güvenlik: basit token kontrolü
$token = $_GET['token'] ?? '';
if ($token !== 'migrate005now') {
    die('Unauthorized. Add ?token=migrate005now to URL');
}

// Config yükle
require_once __DIR__ . '/api/config.php';

$db = getDB();
$results = [];
$errors  = [];

function runSQL(PDO $db, string $label, string $sql): array {
    try {
        $db->exec($sql);
        return ['label' => $label, 'status' => 'OK ✅'];
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        // "Duplicate column" veya "already exists" hataları normal - migration zaten yapılmış
        if (str_contains($msg, 'Duplicate column') ||
            str_contains($msg, 'already exists') ||
            str_contains($msg, 'Multiple primary key')) {
            return ['label' => $label, 'status' => 'ALREADY EXISTS ✅ (skipped)'];
        }
        return ['label' => $label, 'status' => 'ERROR ❌: ' . $msg];
    }
}

// ── 1. listings.status ENUM'a pending ve rejected ekle ──────────────────────
$results[] = runSQL($db, '1. listings.status ENUM',
    "ALTER TABLE listings MODIFY COLUMN status
     ENUM('pending','active','sold','rented','expired','rejected','deleted')
     NOT NULL DEFAULT 'pending'"
);

// ── 2. blacklist.national_id ekle ───────────────────────────────────────────
$results[] = runSQL($db, '2. blacklist.national_id',
    "ALTER TABLE blacklist ADD COLUMN national_id VARCHAR(50) NULL AFTER phone"
);

// blacklist.phone nullable yap
$results[] = runSQL($db, '2b. blacklist.phone nullable',
    "ALTER TABLE blacklist MODIFY COLUMN phone VARCHAR(30) NULL"
);

// ── 3. reviews.status ekle ──────────────────────────────────────────────────
$results[] = runSQL($db, '3. reviews.status',
    "ALTER TABLE reviews ADD COLUMN status
     ENUM('pending','approved','flagged','rejected')
     NOT NULL DEFAULT 'pending' AFTER comment"
);

$results[] = runSQL($db, '3b. reviews.moderated_by',
    "ALTER TABLE reviews ADD COLUMN moderated_by INT NULL AFTER status"
);

$results[] = runSQL($db, '3c. reviews.moderated_at',
    "ALTER TABLE reviews ADD COLUMN moderated_at DATETIME NULL AFTER moderated_by"
);

// Mevcut yorumları approved yap
$results[] = runSQL($db, '3d. existing reviews → approved',
    "UPDATE reviews SET status = 'approved' WHERE status = 'pending'"
);

// ── 4. reports.report_type ve status ekle ───────────────────────────────────
$results[] = runSQL($db, '4. reports.report_type',
    "ALTER TABLE reports ADD COLUMN report_type
     ENUM('Listing','User','Order','Message','Other')
     NOT NULL DEFAULT 'Listing' AFTER reason"
);

$results[] = runSQL($db, '4b. reports.target_type',
    "ALTER TABLE reports ADD COLUMN target_type VARCHAR(20) NULL AFTER report_type"
);

$results[] = runSQL($db, '4c. reports.target_id',
    "ALTER TABLE reports ADD COLUMN target_id INT NULL AFTER target_type"
);

$results[] = runSQL($db, '4d. reports.status',
    "ALTER TABLE reports ADD COLUMN status
     ENUM('pending','reviewing','reviewed','dismissed')
     NOT NULL DEFAULT 'pending' AFTER target_id"
);

// Mevcut resolved kayıtları migrate et
$results[] = runSQL($db, '4e. existing reports → migrate status',
    "UPDATE reports SET status = 'reviewed' WHERE resolved = 1"
);

$results[] = runSQL($db, '4f. reports.listing_id nullable',
    "ALTER TABLE reports MODIFY COLUMN listing_id INT NULL"
);

// ── 5. admin_log.ip_address ekle ────────────────────────────────────────────
$results[] = runSQL($db, '5. admin_log.ip_address',
    "ALTER TABLE admin_log ADD COLUMN ip_address VARCHAR(45) NULL AFTER note"
);

// ── 6. listings index ────────────────────────────────────────────────────────
$results[] = runSQL($db, '6. idx_listings_pending',
    "CREATE INDEX idx_listings_pending ON listings (status, created_at)"
);

// ── Doğrulama sorgusu ────────────────────────────────────────────────────────
$checks = [
    ['listings', 'status'],
    ['blacklist', 'national_id'],
    ['reviews', 'status'],
    ['reports', 'report_type'],
    ['reports', 'status'],
    ['admin_log', 'ip_address'],
];

$verifications = [];
foreach ($checks as [$table, $column]) {
    $st = $db->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $st->execute([$table, $column]);
    $row = $st->fetch();
    $verifications[] = [
        'check' => "$table.$column",
        'result' => $row ? $row['COLUMN_TYPE'] . ' ✅' : 'MISSING ❌'
    ];
}

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Migration 005 — SomBazar</title>
<style>
body { font-family: monospace; background: #0f1117; color: #e2e8f0; padding: 30px; }
h1 { color: #ec5b13; }
h2 { color: #94a3b8; margin-top: 30px; }
table { border-collapse: collapse; width: 100%; margin-top: 10px; }
th { background: #1e2535; padding: 10px; text-align: left; color: #94a3b8; font-size: 12px; }
td { padding: 10px; border-bottom: 1px solid #2a3047; font-size: 13px; }
.ok   { color: #4ade80; }
.err  { color: #f87171; }
.warn { color: #fbbf24; }
.delete-notice { background: #fee2e2; color: #991b1b; padding: 16px; border-radius: 8px; margin-top: 30px; font-size: 14px; }
</style>
</head>
<body>
<h1>🔧 SomBazar — Migration 005</h1>

<h2>Çalıştırılan SQL Komutları</h2>
<table>
<tr><th>İşlem</th><th>Sonuç</th></tr>
<?php foreach ($results as $r): ?>
<tr>
  <td><?= htmlspecialchars($r['label']) ?></td>
  <td class="<?= str_contains($r['status'], '❌') ? 'err' : (str_contains($r['status'], 'ALREADY') ? 'warn' : 'ok') ?>">
    <?= htmlspecialchars($r['status']) ?>
  </td>
</tr>
<?php endforeach; ?>
</table>

<h2>Doğrulama</h2>
<table>
<tr><th>Kolon</th><th>Sonuç</th></tr>
<?php foreach ($verifications as $v): ?>
<tr>
  <td><?= htmlspecialchars($v['check']) ?></td>
  <td class="<?= str_contains($v['result'], '❌') ? 'err' : 'ok' ?>">
    <?= htmlspecialchars($v['result']) ?>
  </td>
</tr>
<?php endforeach; ?>
</table>

<div class="delete-notice">
  ⚠️ <strong>GÜVENLİK:</strong> Migration tamamlandı!
  Şimdi bu dosyayı sunucudan sil:<br><br>
  <code>C:\xampp\htdocs\sombazar-fixed\run_migration.php</code><br><br>
  Sonra tekrar deploy et.
</div>
</body>
</html>
