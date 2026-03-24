-- ============================================================
-- SomaBazar — Migration 001: Core Tables
-- charset: utf8mb4, collation: utf8mb4_unicode_ci
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ------------------------------------------------------------
-- users
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`                VARCHAR(100)    NOT NULL,
    `email`               VARCHAR(191)    NOT NULL,
    `phone`               VARCHAR(30)     NOT NULL,
    `password_hash`       VARCHAR(255)    NOT NULL,
    `role`                ENUM('buyer','seller','store_owner','admin') NOT NULL DEFAULT 'buyer',
    `status`              ENUM('pending','active','banned','deleted') NOT NULL DEFAULT 'pending',
    `national_id`         VARCHAR(50)     NULL DEFAULT NULL,
    `avatar_url`          VARCHAR(500)    NULL DEFAULT NULL,
    `email_verified_at`   DATETIME        NULL DEFAULT NULL,
    `phone_verified_at`   DATETIME        NULL DEFAULT NULL,
    `last_login_at`       DATETIME        NULL DEFAULT NULL,
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    UNIQUE KEY `uq_users_phone` (`phone`),
    KEY `idx_users_role`   (`role`),
    KEY `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- categories
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
    `id`         INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `parent_id`  INT UNSIGNED   NULL DEFAULT NULL,
    `name`       VARCHAR(100)   NOT NULL,
    `slug`       VARCHAR(110)   NOT NULL,
    `icon_url`   VARCHAR(500)   NULL DEFAULT NULL,
    `sort_order` SMALLINT       NOT NULL DEFAULT 0,
    `is_active`  BOOLEAN        NOT NULL DEFAULT TRUE,
    `created_at` DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_categories_slug` (`slug`),
    KEY `idx_categories_parent_id` (`parent_id`),
    KEY `idx_categories_is_active` (`is_active`),
    CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`)
        REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- listings
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `listings` (
    `id`               INT UNSIGNED       NOT NULL AUTO_INCREMENT,
    `seller_id`        INT UNSIGNED       NOT NULL,
    `store_id`         INT UNSIGNED       NULL DEFAULT NULL,
    `category_id`      INT UNSIGNED       NOT NULL,
    `title`            VARCHAR(100)       NOT NULL,
    `description`      TEXT               NULL,
    `price`            DECIMAL(10,2)      NOT NULL DEFAULT 0.00,
    `price_type`       ENUM('fixed','negotiable') NOT NULL DEFAULT 'negotiable',
    `min_offer_amount` DECIMAL(10,2)      NULL DEFAULT NULL,
    `condition`        ENUM('new','like_new','good','fair') NOT NULL,
    `location_city`    VARCHAR(100)       NULL DEFAULT NULL,
    `location_district`VARCHAR(100)       NULL DEFAULT NULL,
    `latitude`         DECIMAL(10,8)      NULL DEFAULT NULL,
    `longitude`        DECIMAL(11,8)      NULL DEFAULT NULL,
    `status`           ENUM('pending','active','sold','reserved','rejected','expired','paused','deleted') NOT NULL DEFAULT 'pending',
    `views_count`      INT UNSIGNED       NOT NULL DEFAULT 0,
    `expires_at`       DATETIME           NULL DEFAULT NULL,
    `created_at`       DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_listings_seller_id`   (`seller_id`),
    KEY `idx_listings_store_id`    (`store_id`),
    KEY `idx_listings_category_id` (`category_id`),
    KEY `idx_listings_status`      (`status`),
    KEY `idx_listings_price`       (`price`),
    KEY `idx_listings_created_at`  (`created_at`),
    KEY `idx_listings_expires_at`  (`expires_at`),
    CONSTRAINT `fk_listings_seller`   FOREIGN KEY (`seller_id`)   REFERENCES `users`       (`id`) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT `fk_listings_category` FOREIGN KEY (`category_id`) REFERENCES `categories`  (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- listing_images
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `listing_images` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `listing_id`   INT UNSIGNED  NOT NULL,
    `image_url`    VARCHAR(500)  NOT NULL,
    `thumbnail_url`VARCHAR(500)  NULL DEFAULT NULL,
    `sort_order`   SMALLINT      NOT NULL DEFAULT 0,
    `is_primary`   BOOLEAN       NOT NULL DEFAULT FALSE,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_listing_images_listing_id` (`listing_id`),
    KEY `idx_listing_images_is_primary` (`is_primary`),
    CONSTRAINT `fk_listing_images_listing` FOREIGN KEY (`listing_id`)
        REFERENCES `listings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- tags
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tags` (
    `id`        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `name`      VARCHAR(50)   NOT NULL,
    `slug`      VARCHAR(60)   NOT NULL,
    `use_count` INT UNSIGNED  NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tags_name` (`name`),
    UNIQUE KEY `uq_tags_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- listing_tags
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `listing_tags` (
    `listing_id` INT UNSIGNED NOT NULL,
    `tag_id`     INT UNSIGNED NOT NULL,
    PRIMARY KEY (`listing_id`, `tag_id`),
    KEY `idx_listing_tags_tag_id` (`tag_id`),
    CONSTRAINT `fk_listing_tags_listing` FOREIGN KEY (`listing_id`)
        REFERENCES `listings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_listing_tags_tag` FOREIGN KEY (`tag_id`)
        REFERENCES `tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;