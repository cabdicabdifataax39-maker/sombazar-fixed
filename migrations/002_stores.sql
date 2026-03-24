-- ============================================================
-- SomaBazar — Migration 002: Stores
-- charset: utf8mb4, collation: utf8mb4_unicode_ci
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ------------------------------------------------------------
-- stores
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stores` (
    `id`                   INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `owner_id`             INT UNSIGNED     NOT NULL,
    `store_name`           VARCHAR(100)     NOT NULL,
    `slug`                 VARCHAR(110)     NOT NULL,
    `store_type`           ENUM('electronics','auto','real_estate','fashion','food','services','other') NOT NULL DEFAULT 'other',
    `logo_url`             VARCHAR(500)     NULL DEFAULT NULL,
    `cover_url`            VARCHAR(500)     NULL DEFAULT NULL,
    `phone`                VARCHAR(30)      NULL DEFAULT NULL,
    `whatsapp`             VARCHAR(30)      NULL DEFAULT NULL,
    `email`                VARCHAR(191)     NULL DEFAULT NULL,
    `address_text`         TEXT             NULL DEFAULT NULL,
    `city`                 VARCHAR(100)     NULL DEFAULT NULL,
    `district`             VARCHAR(100)     NULL DEFAULT NULL,
    `latitude`             DECIMAL(10,8)    NULL DEFAULT NULL,
    `longitude`            DECIMAL(11,8)    NULL DEFAULT NULL,
    `description`          TEXT             NULL DEFAULT NULL,
    `founded_year`         YEAR             NULL DEFAULT NULL,
    `working_hours`        JSON             NULL DEFAULT NULL,
    `verification_status`  ENUM('unverified','pending','verified','rejected') NOT NULL DEFAULT 'unverified',
    `verification_docs`    JSON             NULL DEFAULT NULL,
    `verified_at`          DATETIME         NULL DEFAULT NULL,
    `verified_by`          INT UNSIGNED     NULL DEFAULT NULL,
    `total_listings`       INT UNSIGNED     NOT NULL DEFAULT 0,
    `total_sales`          INT UNSIGNED     NOT NULL DEFAULT 0,
    `avg_rating`           DECIMAL(3,2)     NOT NULL DEFAULT 0.00,
    `review_count`         INT UNSIGNED     NOT NULL DEFAULT 0,
    `response_rate`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `response_time`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `status`               ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',
    `plan`                 ENUM('free','basic','pro','enterprise') NOT NULL DEFAULT 'free',
    `plan_expires_at`      DATETIME         NULL DEFAULT NULL,
    `meta_title`           VARCHAR(160)     NULL DEFAULT NULL,
    `meta_description`     VARCHAR(320)     NULL DEFAULT NULL,
    `created_at`           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_stores_owner_id` (`owner_id`),
    UNIQUE KEY `uq_stores_slug`     (`slug`),
    KEY `idx_stores_store_type`          (`store_type`),
    KEY `idx_stores_verification_status` (`verification_status`),
    KEY `idx_stores_status`              (`status`),
    KEY `idx_stores_plan`                (`plan`),
    KEY `idx_stores_city`                (`city`),
    KEY `idx_stores_verified_by`         (`verified_by`),
    CONSTRAINT `fk_stores_owner`       FOREIGN KEY (`owner_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT `fk_stores_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- store_categories
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `store_categories` (
    `store_id`    INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `is_primary`  BOOLEAN      NOT NULL DEFAULT FALSE,
    PRIMARY KEY (`store_id`, `category_id`),
    KEY `idx_store_categories_category_id` (`category_id`),
    CONSTRAINT `fk_store_categories_store`    FOREIGN KEY (`store_id`)    REFERENCES `stores`     (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_store_categories_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- store_hours_exceptions
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `store_hours_exceptions` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `store_id`   INT UNSIGNED  NOT NULL,
    `date`       DATE          NOT NULL,
    `is_closed`  BOOLEAN       NOT NULL DEFAULT FALSE,
    `open_time`  TIME          NULL DEFAULT NULL,
    `close_time` TIME          NULL DEFAULT NULL,
    `note`       VARCHAR(100)  NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_store_hours_exceptions_store_id` (`store_id`),
    KEY `idx_store_hours_exceptions_date`     (`date`),
    CONSTRAINT `fk_store_hours_exceptions_store` FOREIGN KEY (`store_id`)
        REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- listings.store_id FK (stores tablosu artık mevcut)
-- ------------------------------------------------------------
ALTER TABLE `listings`
    ADD CONSTRAINT `fk_listings_store`
        FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

SET foreign_key_checks = 1;