<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

function restore_column_exists(PDO $pdo, string $table, string $column): bool
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    redirect('/tadeo-admin/image-trash.php?msg=csrf');
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    redirect('/tadeo-admin/image-trash.php?msg=invalid');
}

try {
    $pdo = db();

    $stmt = $pdo->prepare("SELECT * FROM image_trash WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $image = $stmt->fetch();

    if (!$image) {
        redirect('/tadeo-admin/image-trash.php?msg=invalid');
    }

    $root = dirname(__DIR__);
    $originalPath = ltrim((string)$image['original_path'], '/');
    $trashPath = ltrim((string)$image['trash_path'], '/');

    if (
        (!str_starts_with($originalPath, 'uploads/products/') && !str_starts_with($originalPath, 'uploads/categories/')) ||
        !str_starts_with($trashPath, 'uploads/trash/') ||
        str_contains($originalPath, '..') ||
        str_contains($trashPath, '..')
    ) {
        redirect('/tadeo-admin/image-trash.php?msg=invalid');
    }

    $originalAbsolute = $root . '/' . $originalPath;
    $trashAbsolute = $root . '/' . $trashPath;

    if (!is_file($trashAbsolute)) {
        redirect('/tadeo-admin/image-trash.php?msg=invalid');
    }

    if (is_file($originalAbsolute)) {
        redirect('/tadeo-admin/image-trash.php?msg=restore_conflict');
    }

    $originalDir = dirname($originalAbsolute);

    if (!is_dir($originalDir)) {
        mkdir($originalDir, 0755, true);
    }

    if (!rename($trashAbsolute, $originalAbsolute)) {
        redirect('/tadeo-admin/image-trash.php?msg=error');
    }

    if (($image['owner_type'] ?? '') === 'product' && !empty($image['owner_id'])) {
        $deletedWhere = restore_column_exists($pdo, 'products', 'deleted_at') ? 'AND deleted_at IS NULL' : '';

        $stmt = $pdo->prepare("
            UPDATE products
            SET image_path = ?
            WHERE id = ?
              AND (image_path IS NULL OR image_path = '')
              {$deletedWhere}
            LIMIT 1
        ");
        $stmt->execute([$originalPath, (int)$image['owner_id']]);
    }

    if (
        ($image['owner_type'] ?? '') === 'category' &&
        !empty($image['owner_id']) &&
        restore_column_exists($pdo, 'categories', 'icon_image_path')
    ) {
        $stmt = $pdo->prepare("
            UPDATE categories
            SET icon_image_path = ?
            WHERE id = ?
              AND (icon_image_path IS NULL OR icon_image_path = '')
            LIMIT 1
        ");
        $stmt->execute([$originalPath, (int)$image['owner_id']]);
    }

    $delete = $pdo->prepare("DELETE FROM image_trash WHERE id = ?");
    $delete->execute([$id]);

    redirect('/tadeo-admin/image-trash.php?msg=restored');
} catch (Throwable $e) {
    redirect('/tadeo-admin/image-trash.php?msg=error');
}
