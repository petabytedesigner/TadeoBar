<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    redirect('/tadeo-admin/images.php?msg=csrf');
}

function image_trash_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);

    return (int)$stmt->fetchColumn() > 0;
}

function image_trash_column_exists(PDO $pdo, string $table, string $column): bool
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

function ensure_image_trash_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS image_trash (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            original_path VARCHAR(255) NOT NULL,
            trash_path VARCHAR(255) NOT NULL,
            owner_type VARCHAR(20) NULL,
            owner_id INT UNSIGNED NULL,
            menu_number INT UNSIGNED NULL,
            name_sq VARCHAR(180) NULL,
            name_en VARCHAR(180) NULL,
            deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY original_path_unique (original_path),
            UNIQUE KEY trash_path_unique (trash_path),
            KEY deleted_at_lookup (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function image_delete_safe_relative_path(string $path): ?string
{
    $path = trim($path);
    $path = ltrim($path, '/');

    if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
        return null;
    }

    if ($path === 'uploads/products/.htaccess' || $path === 'uploads/categories/.htaccess') {
        return null;
    }

    if (!str_starts_with($path, 'uploads/products/') && !str_starts_with($path, 'uploads/categories/')) {
        return null;
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];

    return in_array($extension, $allowedExtensions, true) ? $path : null;
}

function image_trash_destination(string $relativePath): array
{
    $root = dirname(__DIR__);
    $folder = str_starts_with($relativePath, 'uploads/products/') ? 'products' : 'categories';
    $filename = basename($relativePath);

    $trashDir = $root . '/uploads/trash/' . $folder;

    if (!is_dir($trashDir)) {
        mkdir($trashDir, 0755, true);
    }

    $trashRelative = 'uploads/trash/' . $folder . '/' . $filename;
    $trashAbsolute = $root . '/' . $trashRelative;

    if (is_file($trashAbsolute)) {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $filename = $name . '-' . date('Ymd-His') . ($ext !== '' ? '.' . $ext : '');
        $trashRelative = 'uploads/trash/' . $folder . '/' . $filename;
        $trashAbsolute = $root . '/' . $trashRelative;
    }

    return [$trashRelative, $trashAbsolute];
}

$relativePath = image_delete_safe_relative_path((string)($_POST['path'] ?? ''));

if ($relativePath === null) {
    redirect('/tadeo-admin/images.php?msg=invalid');
}

$root = dirname(__DIR__);
$absolutePath = $root . '/' . $relativePath;

if (!is_file($absolutePath)) {
    redirect('/tadeo-admin/images.php?msg=invalid');
}

$pdo = db();

try {
    ensure_image_trash_table($pdo);

    $ownerType = null;
    $ownerId = null;
    $menuNumber = null;
    $nameSq = null;
    $nameEn = null;

    $productStmt = $pdo->prepare("
        SELECT id, menu_number, name_sq, name_en
        FROM products
        WHERE image_path = ?
        LIMIT 1
    ");
    $productStmt->execute([$relativePath]);
    $product = $productStmt->fetch();

    if ($product) {
        $ownerType = 'product';
        $ownerId = (int)$product['id'];
        $menuNumber = (int)$product['menu_number'];
        $nameSq = (string)$product['name_sq'];
        $nameEn = (string)$product['name_en'];
    } elseif (image_trash_column_exists($pdo, 'categories', 'icon_image_path')) {
        $categoryStmt = $pdo->prepare("
            SELECT id, name_sq, name_en
            FROM categories
            WHERE icon_image_path = ?
            LIMIT 1
        ");
        $categoryStmt->execute([$relativePath]);
        $category = $categoryStmt->fetch();

        if ($category) {
            $ownerType = 'category';
            $ownerId = (int)$category['id'];
            $nameSq = (string)$category['name_sq'];
            $nameEn = (string)$category['name_en'];
        }
    }

    if ($ownerType === null && image_trash_table_exists($pdo, 'image_detach_history')) {
        $historyStmt = $pdo->prepare("
            SELECT owner_type, owner_id, menu_number, name_sq, name_en
            FROM image_detach_history
            WHERE image_path = ?
            LIMIT 1
        ");
        $historyStmt->execute([$relativePath]);
        $history = $historyStmt->fetch();

        if ($history) {
            $ownerType = (string)$history['owner_type'];
            $ownerId = (int)$history['owner_id'];
            $menuNumber = $history['menu_number'] !== null ? (int)$history['menu_number'] : null;
            $nameSq = (string)$history['name_sq'];
            $nameEn = (string)$history['name_en'];
        }
    }

    [$trashRelative, $trashAbsolute] = image_trash_destination($relativePath);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('UPDATE products SET image_path = NULL WHERE image_path = ?');
    $stmt->execute([$relativePath]);

    if (image_trash_column_exists($pdo, 'categories', 'icon_image_path')) {
        $stmt = $pdo->prepare('UPDATE categories SET icon_image_path = NULL WHERE icon_image_path = ?');
        $stmt->execute([$relativePath]);
    }

    $stmt = $pdo->prepare("
        INSERT INTO image_trash
            (original_path, trash_path, owner_type, owner_id, menu_number, name_sq, name_en, deleted_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            trash_path = VALUES(trash_path),
            owner_type = VALUES(owner_type),
            owner_id = VALUES(owner_id),
            menu_number = VALUES(menu_number),
            name_sq = VALUES(name_sq),
            name_en = VALUES(name_en),
            deleted_at = NOW()
    ");
    $stmt->execute([$relativePath, $trashRelative, $ownerType, $ownerId, $menuNumber, $nameSq, $nameEn]);

    if (!rename($absolutePath, $trashAbsolute)) {
        $pdo->rollBack();
        redirect('/tadeo-admin/images.php?msg=delete_failed');
    }

    $pdo->commit();

    redirect('/tadeo-admin/images.php?msg=image_trashed');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    redirect('/tadeo-admin/images.php?msg=error');
}
