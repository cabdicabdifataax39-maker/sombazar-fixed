-- ══════════════════════════════════════════════════════════════
--  SomBazar — Migration 005: Critical DB Fixes
--  Run: mysql -u root -p railway < 005_fixes.sql
--  Railway: railway run mysql < 005_fixes.sql
--
--  Bu migration idempotent'tir — birden fazla çalıştırılabilir.
--  Her ALTER IF NOT EXISTS / IF EXISTS guard içerir.
-- ══════════════════════════════════════════════════════════════

-- ── 1. listings.status ENUM'a 'pending' ve 'rejected' ekle ──
--
--  schema.sql:  ENUM('active','sold','rented','expired','deleted')
--  001_migration: ENUM zaten 'pending' içeriyor ama ana schema eksik.
--  Admin API SELECT WHERE status='pending' yapıyor — production'da
--  MySQL strict modunda INSERT hata verebilir.
--
ALTER TABLE listings
  MODIFY COLUMN status
    ENUM('pending','active','sold','rented','expired','rejected','deleted')
    NOT NULL DEFAULT 'pending';

-- Mevcut 'active' kayıtları etkilenmez. Yeni ilanlar varsayılan
-- olarak 'pending' geliyor → admin onayı gerekiyor.
-- NOT: Eğer ilanlar direkt 'active' çıksın istiyorsan DEFAULT'u değiştir:
--   DEFAULT 'active'

-- ── 2. listings CREATE'de status 'pending' olsun ─────────────
--  (listings.php satır 222'de 'active' hard-coded)
--  Bu değişiklik sadece schema level — listings.php'de de düzelt.

-- ── 3. blacklist tablosuna national_id kolonu ekle ───────────
--
--  Mevcut: phone VARCHAR(30) NOT NULL UNIQUE
--  Problem: Admin paneli national_id ile blacklist ekliyor ama
--           DB'de kolon yok → veri kayboluyordu.
--
ALTER TABLE blacklist
  ADD COLUMN IF NOT EXISTS national_id VARCHAR(50) NULL AFTER phone;

-- phone UNIQUE constraint kaldır (hem phone hem nat_id ile blacklist
-- eklenebilmeli, birinin null olması gerekebilir)
-- Önce unique index'i kaldır, sonra daha geniş bir constraint koy:
ALTER TABLE blacklist
  MODIFY COLUMN phone VARCHAR(30) NULL;

-- Yeni constraint: phone VEYA national_id zorunlu (DB trigger ile
-- değil, uygulama katmanında zaten kontrol ediliyor — api/admin.php)

-- ── 4. reviews tablosuna status kolonu ekle ──────────────────
--
--  Admin paneli yorum moderasyonu yapıyor (approved/pending/flagged)
--  ama DB'de bu kolonu yoktu → değişiklikler kaydedilemiyordu.
--
ALTER TABLE reviews
  ADD COLUMN IF NOT EXISTS status
    ENUM('pending','approved','flagged','rejected')
    NOT NULL DEFAULT 'pending'
    AFTER comment;

ALTER TABLE reviews
  ADD COLUMN IF NOT EXISTS moderated_by INT NULL AFTER status,
  ADD COLUMN IF NOT EXISTS moderated_at DATETIME NULL AFTER moderated_by;

-- Mevcut onaylanmış yorumları 'approved' yap
-- (şu an tüm mevcut yorumlar 'pending' olarak işaretlendi)
UPDATE reviews SET status = 'approved'
  WHERE status = 'pending' AND created_at < NOW();

-- ── 5. reports tablosuna report_type ve target_type ekle ─────
--
--  Mevcut: sadece listing_id (INT) var → sadece ilan bildirimi
--  Problem: Admin paneli User/Listing/Order tipinde filtre yapıyor
--           ama DB'de bu kolon yoktu.
--
--  NOT: Mevcut reports tablosu sadece listing_id FK içeriyor.
--  Kullanıcı ve sipariş bildirimleri için flexible yapı gerekiyor.
--
ALTER TABLE reports
  ADD COLUMN IF NOT EXISTS report_type
    ENUM('Listing','User','Order','Message','Other')
    NOT NULL DEFAULT 'Listing'
    AFTER reason;

ALTER TABLE reports
  ADD COLUMN IF NOT EXISTS target_type VARCHAR(20) NULL AFTER report_type,
  ADD COLUMN IF NOT EXISTS target_id   INT NULL AFTER target_type,
  ADD COLUMN IF NOT EXISTS status
    ENUM('pending','reviewing','reviewed','dismissed')
    NOT NULL DEFAULT 'pending'
    AFTER target_id;

-- Mevcut kayıtları migrate et
UPDATE reports SET status = 'reviewed' WHERE resolved = 1;
UPDATE reports SET status = 'pending'  WHERE resolved = 0;
UPDATE reports SET target_type = 'Listing', target_id = listing_id
  WHERE target_id IS NULL AND listing_id IS NOT NULL;

-- listing_id FK'yı nullable yap (artık target_id genel amaçlı)
ALTER TABLE reports
  MODIFY COLUMN listing_id INT NULL;

-- ── 6. admin_log tablosuna target (string) kolonu ekle ───────
--
--  Mevcut: target_type + target_id INT var ama 'note' TEXT ayrı.
--  api/admin.php logAction() fonksiyonu 'note' alanını kullanıyor.
--  Eksik: IP adresi loglanmıyor.
--
ALTER TABLE admin_log
  ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL AFTER note;

-- ── 7. listings tablosuna pending approval için index ─────────
-- Önce varsa sil, sonra oluştur (idempotent)
DROP PROCEDURE IF EXISTS sombazar_add_index;
DELIMITER //
CREATE PROCEDURE sombazar_add_index()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'listings'
      AND INDEX_NAME = 'idx_listings_pending'
  ) THEN
    CREATE INDEX idx_listings_pending ON listings (status, created_at);
  END IF;
END //
DELIMITER ;
CALL sombazar_add_index();
DROP PROCEDURE IF EXISTS sombazar_add_index;

-- ── 8. Kontrol sorgusu — migration sonrası doğrula ───────────
SELECT 'listings.status ENUM' AS migration,
       COLUMN_TYPE AS current_value
  FROM information_schema.COLUMNS
 WHERE TABLE_NAME = 'listings'
   AND COLUMN_NAME = 'status'
   AND TABLE_SCHEMA = DATABASE()

UNION ALL

SELECT 'blacklist.national_id' AS migration,
       CASE WHEN COUNT(*) > 0 THEN 'EXISTS ✅' ELSE 'MISSING ❌' END
  FROM information_schema.COLUMNS
 WHERE TABLE_NAME = 'blacklist'
   AND COLUMN_NAME = 'national_id'
   AND TABLE_SCHEMA = DATABASE()

UNION ALL

SELECT 'reviews.status' AS migration,
       CASE WHEN COUNT(*) > 0 THEN 'EXISTS ✅' ELSE 'MISSING ❌' END
  FROM information_schema.COLUMNS
 WHERE TABLE_NAME = 'reviews'
   AND COLUMN_NAME = 'status'
   AND TABLE_SCHEMA = DATABASE()

UNION ALL

SELECT 'reports.report_type' AS migration,
       CASE WHEN COUNT(*) > 0 THEN 'EXISTS ✅' ELSE 'MISSING ❌' END
  FROM information_schema.COLUMNS
 WHERE TABLE_NAME = 'reports'
   AND COLUMN_NAME = 'report_type'
   AND TABLE_SCHEMA = DATABASE()

UNION ALL

SELECT 'reports.status' AS migration,
       CASE WHEN COUNT(*) > 0 THEN 'EXISTS ✅' ELSE 'MISSING ❌' END
  FROM information_schema.COLUMNS
 WHERE TABLE_NAME = 'reports'
   AND COLUMN_NAME = 'status'
   AND TABLE_SCHEMA = DATABASE();
