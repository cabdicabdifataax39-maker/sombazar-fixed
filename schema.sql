-- ══════════════════════════════════════════════════════════════
--  SomBazar — Full Database Schema (Fresh Install)
--  Run: mysql -u root -p < schema.sql
-- ══════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS sombazar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sombazar;

-- ── Users ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    display_name          VARCHAR(120) NOT NULL,
    email                 VARCHAR(180) NOT NULL UNIQUE,
    password              VARCHAR(255) NOT NULL,
    phone                 VARCHAR(30),
    city                  VARCHAR(60)  DEFAULT 'Hargeisa',
    bio                   TEXT,
    photo_url             VARCHAR(500),
    -- Verification
    verified              TINYINT(1)   DEFAULT 0,
    verification_status   ENUM('none','pending','approved','rejected') DEFAULT 'none',
    seller_type           ENUM('individual','agency','company') DEFAULT 'individual',
    -- Ratings
    rating                DECIMAL(3,2) DEFAULT 0.00,
    review_count          INT          DEFAULT 0,
    -- Plan / Subscription
    plan                  VARCHAR(20)  DEFAULT 'free',
    plan_expires_at       DATETIME     NULL,
    -- Admin & moderation
    is_admin              TINYINT(1)   DEFAULT 0,
    banned                TINYINT(1)   DEFAULT 0,
    ban_reason            VARCHAR(300) NULL,
    -- Security
    token_invalidated_at  DATETIME     NULL,
    last_seen             DATETIME     NULL,
    deleted_at            DATETIME     NULL,
    -- Affiliate
    ref_code              VARCHAR(20)  NULL,
    -- Notifications
    notifications_email   TINYINT(1)   DEFAULT 1,
    notifications_push    TINYINT(1)   DEFAULT 0,
    notifications_sms     TINYINT(1)   DEFAULT 0,
    -- Timestamps
    created_at            DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email  (email),
    INDEX idx_plan   (plan),
    INDEX idx_admin  (is_admin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Listings ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS listings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    category        ENUM('car','house','land','electronics','furniture','jobs','services','hotel','fashion') NOT NULL,
    listing_type    ENUM('sale','rent') DEFAULT 'sale',
    rental_period   ENUM('daily','monthly','yearly') DEFAULT NULL,
    title           VARCHAR(150) NOT NULL,
    description     TEXT,
    price           DECIMAL(15,2) NOT NULL DEFAULT 0,
    currency        ENUM('USD','SLSH','ETB') DEFAULT 'USD',
    negotiable      TINYINT(1)   DEFAULT 0,
    city            VARCHAR(60)  DEFAULT 'Hargeisa',
    condition_status ENUM('New','Like New','Good','Used') DEFAULT 'Good',
    phone           VARCHAR(30),
    featured        TINYINT(1)   DEFAULT 0,
    boosted_until   DATETIME     NULL,
    views           INT          DEFAULT 0,
    status          ENUM('active','sold','rented','expired','deleted') DEFAULT 'active',
    specs           JSON,
    images          JSON,
    year            SMALLINT     NULL,
    created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_category (category),
    INDEX idx_city     (city),
    INDEX idx_status   (status),
    INDEX idx_featured (featured),
    INDEX idx_boosted  (boosted_until),
    FULLTEXT idx_search (title, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Conversations ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS conversations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user1_id        INT NOT NULL,
    user2_id        INT NOT NULL,
    listing_id      INT NULL,
    last_message    TEXT,
    last_message_at DATETIME NULL,
    unread_count_1  INT DEFAULT 0,
    unread_count_2  INT DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user1_id)   REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (user2_id)   REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE SET NULL,
    UNIQUE KEY unique_conv (user1_id, user2_id),
    INDEX idx_user1 (user1_id),
    INDEX idx_user2 (user2_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Messages ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS messages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_id       INT NOT NULL,
    text            TEXT NULL,
    image_url       VARCHAR(500) NULL,
    read_at         DATETIME NULL,
    deleted_at      DATETIME NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id)       REFERENCES users(id)         ON DELETE CASCADE,
    INDEX idx_conv (conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Favorites ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS favorites (
    user_id    INT NOT NULL,
    listing_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, listing_id),
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Offers ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS offers (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    listing_id     INT NOT NULL,
    buyer_id       INT NOT NULL,
    seller_id      INT NOT NULL,
    amount         DECIMAL(15,2) NOT NULL,
    currency       VARCHAR(10) DEFAULT 'USD',
    note           TEXT NULL,
    status         ENUM('pending','accepted','rejected','countered','cancelled','expired') DEFAULT 'pending',
    counter_amount DECIMAL(15,2) NULL,
    counter_note   TEXT NULL,
    round          INT DEFAULT 1,
    expires_at     DATETIME NULL,
    responded_at   DATETIME NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    FOREIGN KEY (buyer_id)   REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (seller_id)  REFERENCES users(id)    ON DELETE CASCADE,
    INDEX idx_buyer  (buyer_id),
    INDEX idx_seller (seller_id),
    INDEX idx_listing (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Reviews ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reviews (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    listing_id  INT NOT NULL,
    seller_id   INT NOT NULL,
    reviewer_id INT NOT NULL,
    rating      TINYINT NOT NULL,
    comment     TEXT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (listing_id)  REFERENCES listings(id) ON DELETE CASCADE,
    FOREIGN KEY (seller_id)   REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id)    ON DELETE CASCADE,
    UNIQUE KEY unique_review (listing_id, reviewer_id),
    INDEX idx_seller (seller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Notifications ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    type       VARCHAR(50),
    title      VARCHAR(255),
    body       TEXT NULL,
    link       VARCHAR(500) NULL,
    icon       VARCHAR(10)  NULL,
    is_read    TINYINT(1)   DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user   (user_id),
    INDEX idx_unread (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Verification Docs ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS verification_docs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    doc_type    VARCHAR(50),
    file_url    VARCHAR(500),
    superseded  TINYINT(1) DEFAULT 0,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Payments ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payments (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    plan             VARCHAR(20) NOT NULL,
    amount           DECIMAL(8,2) NOT NULL,
    method           VARCHAR(20) NOT NULL,
    reference_code   VARCHAR(100) NOT NULL UNIQUE,
    screenshot_url   VARCHAR(500) NULL,
    status           ENUM('pending','approved','rejected') DEFAULT 'pending',
    idempotency_key  VARCHAR(100) NULL,
    reviewed_by      INT NULL,
    reviewed_at      DATETIME NULL,
    admin_note       VARCHAR(300) NULL,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user   (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Packages (active plan per user) ────────────────────────────
CREATE TABLE IF NOT EXISTS packages (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL UNIQUE,
    plan           VARCHAR(20) DEFAULT 'free',
    listing_limit  INT DEFAULT 3,
    photo_limit    INT DEFAULT 2,
    boost_credits  INT DEFAULT 0,
    started_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at     DATETIME NULL,
    payment_id     INT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Discount Codes ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS discount_codes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(50) NOT NULL UNIQUE,
    type        ENUM('percent','fixed') DEFAULT 'percent',
    value       DECIMAL(8,2) NOT NULL,
    max_uses    INT DEFAULT 0,
    uses_count  INT DEFAULT 0,
    min_plan    VARCHAR(20) NULL,
    expires_at  DATETIME NULL,
    is_active   TINYINT(1) DEFAULT 1,
    created_by  INT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Affiliates ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS affiliates (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL UNIQUE,
    ref_code        VARCHAR(20) NOT NULL UNIQUE,
    commission_rate DECIMAL(5,2) DEFAULT 10.00,
    total_referrals INT DEFAULT 0,
    total_earned    DECIMAL(10,2) DEFAULT 0.00,
    pending_payout  DECIMAL(10,2) DEFAULT 0.00,
    is_active       TINYINT(1) DEFAULT 1,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Blacklist ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS blacklist (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    phone      VARCHAR(30) NOT NULL UNIQUE,
    reason     VARCHAR(300) NULL,
    added_by   INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Reports ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reports (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    listing_id  INT NOT NULL,
    reporter_id INT NOT NULL,
    reason      VARCHAR(300),
    resolved    TINYINT(1) DEFAULT 0,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (listing_id)  REFERENCES listings(id) ON DELETE CASCADE,
    FOREIGN KEY (reporter_id) REFERENCES users(id)    ON DELETE CASCADE,
    UNIQUE KEY unique_report (listing_id, reporter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Admin Log ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT NOT NULL,
    action      VARCHAR(100),
    target_type VARCHAR(50) NULL,
    target_id   INT NULL,
    note        TEXT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin  (admin_id),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Login Attempts ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS login_attempts (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    ip         VARCHAR(45) NOT NULL,
    email      VARCHAR(180) NULL,
    success    TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Password Reset Tokens ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    token      VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Push Subscriptions ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    endpoint   TEXT NOT NULL,
    p256dh     TEXT NULL,
    auth_key   TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ══════════════════════════════════════════════════════════════
--  ADMIN USER SETUP
--  After running this file, run the following to create admin:
--
--  1. Register normally via auth.html
--  2. Then run this SQL (replace YOUR@EMAIL.COM):
--     UPDATE users SET is_admin = 1 WHERE email = 'YOUR@EMAIL.COM';
--
--  OR use the pre-seeded admin below (password: Admin123!)
--  IMPORTANT: Change this password immediately after first login!
-- ══════════════════════════════════════════════════════════════

-- Pre-seeded admin account (password: Admin123!)
-- Hash: bcrypt cost 10, generated 2024
INSERT IGNORE INTO users (id, display_name, email, password, city, is_admin) VALUES
(1, 'Admin', 'admin@sombazar.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Hargeisa', 1);

-- NOTE: The password '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' = 'password'
-- Change it immediately: go to profile.html > Settings > Change Password
