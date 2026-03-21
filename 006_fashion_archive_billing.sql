-- Migration 006: fashion category + conversation archiving + annual billing support
-- Run via run_migration.php on Railway

-- 1. Add 'fashion' to listings.category ENUM
ALTER TABLE listings
  MODIFY COLUMN category ENUM('car','house','land','electronics','furniture','jobs','services','hotel','fashion') NOT NULL;

-- 2. Add archive columns to conversations (safe — IF NOT EXISTS handled via try/catch in PHP)
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS archived_by_user1 TINYINT(1) DEFAULT 0;
ALTER TABLE conversations ADD COLUMN IF NOT EXISTS archived_by_user2 TINYINT(1) DEFAULT 0;

-- 3. Add billing_cycle to payments for annual plan support
ALTER TABLE payments ADD COLUMN IF NOT EXISTS billing_cycle ENUM('monthly','annual') DEFAULT 'monthly';

-- 4. Sync: ensure users.plan accepts 'agency'
ALTER TABLE users MODIFY COLUMN plan ENUM('free','standard','pro','agency') DEFAULT 'free';

-- 5. Notification preference columns on users
ALTER TABLE users ADD COLUMN IF NOT EXISTS notifications_email TINYINT(1) DEFAULT 1;
ALTER TABLE users ADD COLUMN IF NOT EXISTS notifications_push  TINYINT(1) DEFAULT 1;
ALTER TABLE users ADD COLUMN IF NOT EXISTS notifications_sms   TINYINT(1) DEFAULT 0;

-- 6. reports table: make listing_id nullable, add report_type/status/listing_ref columns
ALTER TABLE reports MODIFY COLUMN listing_id  INT NULL;
ALTER TABLE reports MODIFY COLUMN reporter_id INT NULL;
ALTER TABLE reports ADD COLUMN IF NOT EXISTS report_type  VARCHAR(50)  NULL;
ALTER TABLE reports ADD COLUMN IF NOT EXISTS status       ENUM('pending','reviewed','resolved','dismissed') DEFAULT 'pending';
ALTER TABLE reports ADD COLUMN IF NOT EXISTS listing_ref  VARCHAR(200) NULL;
ALTER TABLE reports ADD COLUMN IF NOT EXISTS reporter_ip  VARCHAR(45)  NULL;
