-- SomBazar Migration 007: Stores, Reservations, Quick Replies, OTP, Rate Limits
-- Idempotent: CREATE TABLE IF NOT EXISTS + ALTER ... IF NOT EXISTS

-- ── 1. stores tablosu ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS stores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    store_name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    store_type ENUM('electronics','auto','real_estate','fashion','food','services','other') DEFAULT 'other',
    logo_url VARCHAR(500) NULL,
    cover_url VARCHAR(500) NULL,
    phone VARCHAR(30) NULL,
    whatsapp VARCHAR(30) NULL,
    address_text TEXT NULL,
    city VARCHAR(50) NULL DEFAULT 'Hargeisa',
    district VARCHAR(50) NULL,
    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,
    description TEXT NULL,
    working_hours JSON NULL,
    verification_status ENUM('unverified','pending','verified','rejected') DEFAULT 'unverified',
    verification_docs JSON NULL,
    verified_at DATETIME NULL,
    verified_by INT NULL,
    total_listings INT DEFAULT 0,
    avg_rating DECIMAL(3,2) DEFAULT 0.00,
    review_count INT DEFAULT 0,
    response_rate TINYINT DEFAULT 0,
    status ENUM('active','suspended','closed') DEFAULT 'active',
    plan ENUM('free','basic','pro','enterprise') DEFAULT 'free',
    plan_expires_at DATETIME NULL,
    follower_count INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_owner (owner_id),
    INDEX idx_city (city),
    INDEX idx_type (store_type),
    INDEX idx_verification (verification_status),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. store_followers tablosu ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS store_followers (
    store_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (store_id, user_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. store_hours_exceptions tablosu ────────────────────────────────
CREATE TABLE IF NOT EXISTS store_hours_exceptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_id INT NOT NULL,
    exception_date DATE NOT NULL,
    is_closed TINYINT(1) DEFAULT 0,
    open_time TIME NULL,
    close_time TIME NULL,
    note VARCHAR(100) NULL,
    INDEX idx_store (store_id),
    INDEX idx_date (exception_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. verification_requests tablosu ─────────────────────────────────
CREATE TABLE IF NOT EXISTS verification_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_id INT NOT NULL,
    level ENUM('identity','business','sector') NOT NULL,
    status ENUM('pending','under_review','approved','rejected','more_info_needed') DEFAULT 'pending',
    documents JSON NULL,
    admin_notes TEXT NULL,
    rejection_reason TEXT NULL,
    reviewed_by INT NULL,
    submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    INDEX idx_store (store_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. reservations tablosu ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NOT NULL,
    buyer_id INT NOT NULL,
    seller_id INT NOT NULL,
    status ENUM('pending','confirmed','expired','completed','cancelled') DEFAULT 'pending',
    duration_type ENUM('2h','4h','today','tomorrow') NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_listing (listing_id),
    INDEX idx_buyer (buyer_id),
    INDEX idx_seller (seller_id),
    INDEX idx_status (status),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. seller_quick_replies tablosu ──────────────────────────────────
CREATE TABLE IF NOT EXISTS seller_quick_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    body TEXT NOT NULL,
    use_count INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_seller (seller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 7. otp_codes tablosu ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS otp_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    type ENUM('email','phone','password_reset') NOT NULL,
    code VARCHAR(10) NOT NULL,
    attempts TINYINT DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier (identifier, type),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 8. listing_views tablosu ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS listing_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NOT NULL,
    user_id INT NULL,
    ip_address VARCHAR(45) NOT NULL,
    viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_listing (listing_id),
    INDEX idx_listing_date (listing_id, viewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 9. reports tablosu (yoksa oluştur) ───────────────────────────────
CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reporter_id INT NOT NULL,
    reported_user_id INT NULL,
    reported_listing_id INT NULL,
    report_type ENUM('fake_listing','spam','fraud','inappropriate','other') DEFAULT 'other',
    description TEXT NULL,
    status ENUM('pending','reviewed','resolved','dismissed') DEFAULT 'pending',
    admin_notes TEXT NULL,
    resolved_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reporter (reporter_id),
    INDEX idx_listing (reported_listing_id),
    INDEX idx_user (reported_user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 10. blacklist tablosu (yoksa oluştur) ────────────────────────────
CREATE TABLE IF NOT EXISTS blacklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(30) NULL,
    national_id VARCHAR(50) NULL,
    ip_address VARCHAR(45) NULL,
    reason TEXT NULL,
    added_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_phone (phone),
    INDEX idx_national_id (national_id),
    INDEX idx_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 11. Mevcut tablolara eksik kolonlar ekle ─────────────────────────

-- conversations: status + archived kolonları
ALTER TABLE conversations
    ADD COLUMN IF NOT EXISTS status ENUM('active','closed','blocked') DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS archived_by_buyer TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS archived_by_seller TINYINT(1) DEFAULT 0;

-- messages: type kolonu
ALTER TABLE messages
    ADD COLUMN IF NOT EXISTS type ENUM('text','offer','counter_offer','system') DEFAULT 'text',
    ADD COLUMN IF NOT EXISTS offer_amount DECIMAL(12,2) NULL,
    ADD COLUMN IF NOT EXISTS offer_status ENUM('pending','accepted','rejected','countered','auto_rejected','expired') NULL;

-- offers: expires_at kolonu
ALTER TABLE offers
    ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS conversation_id INT NULL;

-- listings: store_id + min_offer kolonları
ALTER TABLE listings
    ADD COLUMN IF NOT EXISTS store_id INT NULL,
    ADD COLUMN IF NOT EXISTS price_type ENUM('fixed','negotiable') DEFAULT 'negotiable',
    ADD COLUMN IF NOT EXISTS min_offer_amount DECIMAL(12,2) NULL,
    ADD COLUMN IF NOT EXISTS expires_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS condition_label VARCHAR(20) NULL;

-- users: store_id referansı
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS store_id INT NULL,
    ADD COLUMN IF NOT EXISTS phone_verified TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS national_id VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS last_seen DATETIME NULL,
    ADD COLUMN IF NOT EXISTS banned TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS ban_reason VARCHAR(255) NULL;

-- reviews: status kolonu
ALTER TABLE reviews
    ADD COLUMN IF NOT EXISTS status ENUM('visible','hidden','flagged') DEFAULT 'visible';
