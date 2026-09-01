-- Bar Tadeo: strict 1..N product numbering with trash-safe restore
-- Trashed products preserve their former position in trash_menu_number and
-- release menu_number so the live menu remains compact and reusable.

SET NAMES utf8mb4;

SET @sql = IF(
  EXISTS(
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'trash_menu_number'
  ),
  'SELECT 1',
  'ALTER TABLE `products` ADD COLUMN `trash_menu_number` int unsigned NULL DEFAULT NULL AFTER `menu_number`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'menu_number'
      AND IS_NULLABLE = 'YES'
  ),
  'SELECT 1',
  'ALTER TABLE `products` MODIFY COLUMN `menu_number` int unsigned NULL DEFAULT NULL'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `products`
SET `trash_menu_number` = COALESCE(`trash_menu_number`, `menu_number`),
    `menu_number` = NULL
WHERE `deleted_at` IS NOT NULL
  AND `menu_number` IS NOT NULL;

DROP TEMPORARY TABLE IF EXISTS `tmp_tadeo_product_menu_order`;
CREATE TEMPORARY TABLE `tmp_tadeo_product_menu_order` (
  `id` int unsigned NOT NULL,
  `new_menu_number` int unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MEMORY;

SET @tadeo_menu_number := 0;
INSERT INTO `tmp_tadeo_product_menu_order` (`id`, `new_menu_number`)
SELECT `id`, (@tadeo_menu_number := @tadeo_menu_number + 1)
FROM `products`
WHERE `deleted_at` IS NULL
ORDER BY (`menu_number` IS NULL), `menu_number`, `id`;

UPDATE `products`
SET `menu_number` = NULL
WHERE `deleted_at` IS NULL;

UPDATE `products` p
INNER JOIN `tmp_tadeo_product_menu_order` t ON t.`id` = p.`id`
SET p.`menu_number` = t.`new_menu_number`;

DROP TEMPORARY TABLE `tmp_tadeo_product_menu_order`;
