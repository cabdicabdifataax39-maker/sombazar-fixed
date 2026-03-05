-- SomBazar Initial Schema Migration
-- Created: 2025-01-01

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(80) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    phone VARCHAR(30) NULL,
    city VARCHAR(50) NULL DEFAULT 'Hargeisa',
    bio TEXT NULL,
    avatar_url VARCHAR(500) NULL,
    plan VARCHAR(20) NOT NULL DEFAULT 'free',
    plan_expires_at DATETIME NULL,
    role ENUM('user','admin') DEFAULT 'user',
    is_banned TINYINT(1) DEFAULT 0,
    is_verified TINYINT(1) DEFAULT 0,
    email_verified TINYINT(1) DEFAULT 0,
    ref_code VARCHAR(20) NULL,
    referred_by INT NULL,
    token_invalidated_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role  (role),
    INDEX idx_plan  (plan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS listings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    category VARCHAR(50) NOT NULL,
    price DECIMAL(12,2) NULL,
    currency ENUM('USD','SLSH') DEFAULT 'USD',
    negotiable TINYINT(1) DEFAULT 0,
    condition_type VARCHAR(20) NULL,
    city VARCHAR(50) NULL,
    phone VARCHAR(30) NULL,
    images JSON NULL,
    status ENUM('pending','active','sold','expired','deleted','rejected') DEFAULT 'pending',
    views INT DEFAULT 0,
    boost_expires_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user   (user_id),
    INDEX idx_status (status),
    INDEX idx_category (category),
    INDEX idx_city   (city),
    FULLTEXT idx_search (title, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
