-- Bar Tadeo database schema
-- Safe for a fresh database and for upgrading an existing installation.
-- Contains structure and non-sensitive defaults only.
-- Does NOT contain admin accounts, password hashes, WiFi passwords, visits, trash records, or credentials.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(80) NOT NULL,
  `name_sq` varchar(120) NOT NULL,
  `name_en` varchar(120) NOT NULL,
  `icon` varchar(20) NOT NULL DEFAULT '',
  `icon_image_path` varchar(255) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_slug` (`slug`),
  KEY `idx_categories_active_sort` (`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `menu_number` int unsigned NOT NULL,
  `category_id` int unsigned NOT NULL,
  `name_sq` varchar(180) NOT NULL,
  `name_en` varchar(180) NOT NULL,
  `price_all` int unsigned NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_menu_number` (`menu_number`),
  KEY `idx_products_category` (`category_id`,`is_active`,`sort_order`),
  KEY `idx_products_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admins` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(120) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(120) NOT NULL,
  `ip_hash` char(64) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_guard` (`username`,`ip_hash`,`success`,`attempted_at`),
  KEY `idx_login_attempts_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `visits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `visitor_id` char(64) NOT NULL,
  `visit_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_visits_visitor_day` (`visitor_id`,`visit_date`),
  KEY `idx_visits_date` (`visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `image_trash` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `original_path` varchar(255) NOT NULL,
  `trash_path` varchar(255) NOT NULL,
  `owner_type` varchar(20) DEFAULT NULL,
  `owner_id` int unsigned DEFAULT NULL,
  `menu_number` int unsigned DEFAULT NULL,
  `name_sq` varchar(180) DEFAULT NULL,
  `name_en` varchar(180) DEFAULT NULL,
  `deleted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_image_trash_path` (`trash_path`),
  KEY `idx_image_trash_original_path` (`original_path`),
  KEY `idx_image_trash_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `image_detach_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `image_path` varchar(255) NOT NULL,
  `owner_type` varchar(20) NOT NULL,
  `owner_id` int unsigned NOT NULL,
  `menu_number` int unsigned DEFAULT NULL,
  `name_sq` varchar(180) NOT NULL,
  `name_en` varchar(180) NOT NULL,
  `detached_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_image_detach_path` (`image_path`),
  KEY `idx_image_detach_owner` (`owner_type`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrade compatibility for installations created from older menu-only seeds.
-- Add categories.icon_image_path only when it is missing.
SET @sql = IF(
  EXISTS(
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'categories'
      AND COLUMN_NAME = 'icon_image_path'
  ),
  'SELECT 1',
  'ALTER TABLE `categories` ADD COLUMN `icon_image_path` varchar(255) DEFAULT NULL AFTER `icon`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add products.deleted_at only when it is missing.
SET @sql = IF(
  EXISTS(
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'deleted_at'
  ),
  'SELECT 1',
  'ALTER TABLE `products` ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL AFTER `updated_at`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Non-sensitive defaults. Existing settings are never overwritten.
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('bar_name', 'Bar Tadeo'),
  ('default_language', 'sq'),
  ('currency', 'ALL'),
  ('show_prices', '1'),
  ('wifi_ssid', 'TadeoBar'),
  ('wifi_security', 'WPA');

SET FOREIGN_KEY_CHECKS=1;
