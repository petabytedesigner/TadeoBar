<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/csrf.php';

require_admin();

function ensure_image_detach_history_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS image_detach_history (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            image_path VARCHAR(255) NOT NULL,
            owner_type VARCHAR(20) NOT NULL,
            owner_id INT UNSIGNED NOT NULL,
            menu_number INT UNSIGNED NULL,
            name_sq VARCHAR(180) NOT NULL,
            name_en VARCHAR(180) NOT NULL,
            detached_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY image_path_unique (image_path),
            KEY owner_lookup (owner_type, owner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function detach_table_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);

    return (int)$stmt->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/tadeo-admin/images.php');
}

if (!csrf_verify()) {
    redirect('/tadeo-admin/images.php?msg=csrf');
}

$pdo = db();
$type = (string)($_POST['type'] ?? '');
$id = (int)($_POST['id'] ?? 0);

try {
    ensure_image_detach_history_table($pdo);

    if ($type === 'product' && $id > 0) {
        $select = $pdo->prepare("
            SELECT id, menu_number, name_sq, name_en, image_path
            FROM products
            WHERE id = ?
              AND image_path IS NOT NULL
              AND image_path <> ''
            LIMIT 1
        ");
        $select->execute([$id]);
        $product = $select->fetch();

        if ($product) {
            $history = $pdo->prepare("
                INSERT INTO image_detach_history
                    (image_path, owner_type, owner_id, menu_number, name_sq, name_en, detached_at)
                VALUES
                    (?, 'product', ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    owner_type = VALUES(owner_type),
                    owner_id = VALUES(owner_id),
                    menu_number = VALUES(menu_number),
                    name_sq = VALUES(name_sq),
                    name_en = VALUES(name_en),
                    detached_at = NOW()
            ");
            $history->execute([
                $product['image_path'],
                (int)$product['id'],
                (int)$product['menu_number'],
                (string)$product['name_sq'],
                (string)$product['name_en'],
            ]);
        }

        $stmt = $pdo->prepare('UPDATE products SET image_path = NULL WHERE id = ?');
        $stmt->execute([$id]);

        redirect('/tadeo-admin/images.php?msg=detached');
    }

    if ($type === 'category' && $id > 0) {
        if (!detach_table_column_exists($pdo, 'categories', 'icon_image_path')) {
            redirect('/tadeo-admin/images.php?msg=invalid');
        }

        $select = $pdo->prepare("
            SELECT id, name_sq, name_en, icon_image_path
            FROM categories
            WHERE id = ?
              AND icon_image_path IS NOT NULL
              AND icon_image_path <> ''
            LIMIT 1
        ");
        $select->execute([$id]);
        $category = $select->fetch();

        if ($category) {
            $history = $pdo->prepare("
                INSERT INTO image_detach_history
                    (image_path, owner_type, owner_id, menu_number, name_sq, name_en, detached_at)
                VALUES
                    (?, 'category', ?, NULL, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    owner_type = VALUES(owner_type),
                    owner_id = VALUES(owner_id),
                    menu_number = NULL,
                    name_sq = VALUES(name_sq),
                    name_en = VALUES(name_en),
                    detached_at = NOW()
            ");
            $history->execute([
                $category['icon_image_path'],
                (int)$category['id'],
                (string)$category['name_sq'],
                (string)$category['name_en'],
            ]);
        }

        $stmt = $pdo->prepare('UPDATE categories SET icon_image_path = NULL WHERE id = ?');
        $stmt->execute([$id]);

        redirect('/tadeo-admin/images.php?msg=detached');
    }

    redirect('/tadeo-admin/images.php?msg=invalid');
} catch (Throwable $e) {
    redirect('/tadeo-admin/images.php?msg=error');
}
