-- Bar Tadeo - Password recovery migration
-- Safe to run on the current live database.
-- Creates only the password-reset table and its non-sensitive setting.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `password_reset_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int unsigned NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `request_ip_hash` char(64) NOT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_password_reset_ip_time` (`request_ip_hash`,`created_at`),
  KEY `idx_password_reset_admin_active` (`admin_id`,`used_at`,`expires_at`),
  CONSTRAINT `fk_password_reset_admin`
    FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`)
VALUES ('protected_recovery_enabled', '1');
