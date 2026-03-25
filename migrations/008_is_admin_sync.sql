-- Migration 008: is_admin kolonu ekle (role='admin' olan kullanicilari sync et)
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_admin TINYINT(1) DEFAULT 0;
UPDATE users SET is_admin = 1 WHERE role = 'admin';
