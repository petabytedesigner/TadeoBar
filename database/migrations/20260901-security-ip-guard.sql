-- Bar Tadeo: site-wide brute-force IP guard
-- Stores only a SHA-256 IP hash, never the raw IP address.

CREATE TABLE IF NOT EXISTS `security_ip_guard` (
  `ip_hash` char(64) NOT NULL,
  `failed_attempts` smallint unsigned NOT NULL DEFAULT 0,
  `window_started_at` datetime NOT NULL,
  `blocked_until` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ip_hash`),
  KEY `idx_security_ip_guard_blocked_until` (`blocked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
