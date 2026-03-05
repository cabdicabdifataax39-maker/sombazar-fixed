-- SomBazar Payments & Coupons Migration

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    plan VARCHAR(20) NOT NULL,
    amount DECIMAL(8,2) NOT NULL,
    method VARCHAR(20) NOT NULL,
    reference_code VARCHAR(100) NOT NULL UNIQUE,
    screenshot_url VARCHAR(500) NULL,
    coupon_code VARCHAR(50) NULL,
    discount_amount DECIMAL(8,2) DEFAULT 0,
    affiliate_id INT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    admin_note VARCHAR(300) NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user   (user_id),
    INDEX idx_status (status),
    UNIQUE idx_idem  (idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS discount_codes (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS affiliates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    ref_code VARCHAR(20) NOT NULL UNIQUE,
    commission_rate DECIMAL(5,2) DEFAULT 10.00,
    total_referrals INT DEFAULT 0,
    total_earned DECIMAL(10,2) DEFAULT 0.00,
    pending_payout DECIMAL(10,2) DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
