-- Bar Tadeo - Authentication and recovery hardening
-- Safe to run on an existing installation.

SET NAMES utf8mb4;

-- Revocable admin sessions. Incrementing session_version invalidates older sessions.
SET @sql = IF(
  EXISTS(
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'admins'
      AND COLUMN_NAME = 'session_version'
  ),
  'SELECT 1',
  'ALTER TABLE `admins` ADD COLUMN `session_version` int unsigned NOT NULL DEFAULT 1 AFTER `password_hash`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Only successfully delivered reset codes count toward send limits.
SET @sql = IF(
  EXISTS(
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'password_reset_codes'
      AND COLUMN_NAME = 'sent_at'
  ),
  'SELECT 1',
  'ALTER TABLE `password_reset_codes` ADD COLUMN `sent_at` datetime DEFAULT NULL AFTER `request_ip_hash`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'password_reset_codes'
      AND INDEX_NAME = 'idx_password_reset_admin_sent'
  ),
  'SELECT 1',
  'ALTER TABLE `password_reset_codes` ADD KEY `idx_password_reset_admin_sent` (`admin_id`,`sent_at`)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'password_reset_codes'
      AND INDEX_NAME = 'idx_password_reset_ip_sent'
  ),
  'SELECT 1',
  'ALTER TABLE `password_reset_codes` ADD KEY `idx_password_reset_ip_sent` (`request_ip_hash`,`sent_at`)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
