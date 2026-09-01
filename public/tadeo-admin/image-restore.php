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

$pdo = db();
$movedToLive = false;
$originalAbsolute = '';
$trashAbsolute = '';

try {
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
        str_contains($trashPath, '..') ||
        str_contains($originalPath, "\0") ||
        str_contains($trashPath, "\0")
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

    if (!is_dir($originalDir) && !mkdir($originalDir, 0755, true) && !is_dir($originalDir)) {
        throw new RuntimeException('Folderi i rikthimit nuk u krijua dot.');
    }

    $pdo->beginTransaction();

    if (!rename($trashAbsolute, $originalAbsolute)) {
        throw new RuntimeException('Imazhi nuk u rikthye dot në folderin aktiv.');
    }
    $movedToLive = true;

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

    if ($delete->rowCount() !== 1) {
        throw new RuntimeException('Record-i i koshit nuk u përditësua dot.');
    }

    $pdo->commit();

    redirect('/tadeo-admin/image-trash.php?msg=restored');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($movedToLive && $originalAbsolute !== '' && $trashAbsolute !== '' && is_file($originalAbsolute) && !is_file($trashAbsolute)) {
        @rename($originalAbsolute, $trashAbsolute);
    }

    redirect('/tadeo-admin/image-trash.php?msg=error');
}
