-- Migration 008: is_admin kolonu ekle
ALTER TABLE users ADD COLUMN is_admin TINYINT(1) DEFAULT 0;
UPDATE users SET is_admin = 1 WHERE role = 'admin';
