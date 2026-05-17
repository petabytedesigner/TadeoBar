<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

function attach_safe_image_path(?string $relativePath): ?string
{
    $relativePath = trim((string)$relativePath);
    $relativePath = ltrim($relativePath, '/');

    if ($relativePath === '' || str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
        return null;
    }

    if (!str_starts_with($relativePath, 'uploads/products/') && !str_starts_with($relativePath, 'uploads/categories/')) {
        return null;
    }

    if (basename($relativePath) === '.htaccess') {
        return null;
    }

    $absolutePath = dirname(__DIR__) . '/' . $relativePath;

    if (!is_file($absolutePath)) {
        return null;
    }

    return $relativePath;
}

function attach_table_column_exists(PDO $pdo, string $table, string $column): bool
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
    redirect('/tadeo-admin/images.php?msg=csrf');
}

$type = (string)($_POST['type'] ?? '');
$id = (int)($_POST['id'] ?? 0);
$path = attach_safe_image_path($_POST['path'] ?? null);

if (!in_array($type, ['product', 'category'], true) || $id <= 0 || $path === null) {
    redirect('/tadeo-admin/images.php?msg=invalid');
}

try {
    $pdo = db();

    $usedProduct = $pdo->prepare("SELECT COUNT(*) FROM products WHERE image_path = ?");
    $usedProduct->execute([$path]);

    $usedCategoryCount = 0;
    if (attach_table_column_exists($pdo, 'categories', 'icon_image_path')) {
        $usedCategory = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE icon_image_path = ?");
        $usedCategory->execute([$path]);
        $usedCategoryCount = (int)$usedCategory->fetchColumn();
    }

    if ((int)$usedProduct->fetchColumn() > 0 || $usedCategoryCount > 0) {
        redirect('/tadeo-admin/images.php?msg=already_used');
    }

    if ($type === 'product') {
        if (!str_starts_with($path, 'uploads/products/')) {
            redirect('/tadeo-admin/images.php?msg=invalid');
        }

        $stmt = $pdo->prepare("
            UPDATE products
            SET image_path = ?
            WHERE id = ?
              AND (image_path IS NULL OR image_path = '')
            LIMIT 1
        ");
        $stmt->execute([$path, $id]);

        if ($stmt->rowCount() !== 1) {
            redirect('/tadeo-admin/images.php?msg=attach_failed');
        }

        $cleanup = $pdo->prepare("DELETE FROM image_detach_history WHERE image_path = ?");
        $cleanup->execute([$path]);

        redirect('/tadeo-admin/images.php?msg=attached');
    }

    if (!str_starts_with($path, 'uploads/categories/')) {
        redirect('/tadeo-admin/images.php?msg=invalid');
    }

    if (!attach_table_column_exists($pdo, 'categories', 'icon_image_path')) {
        redirect('/tadeo-admin/images.php?msg=attach_failed');
    }

    $stmt = $pdo->prepare("
        UPDATE categories
        SET icon_image_path = ?
        WHERE id = ?
          AND (icon_image_path IS NULL OR icon_image_path = '')
        LIMIT 1
    ");
    $stmt->execute([$path, $id]);

    if ($stmt->rowCount() !== 1) {
        redirect('/tadeo-admin/images.php?msg=attach_failed');
    }

    $cleanup = $pdo->prepare("DELETE FROM image_detach_history WHERE image_path = ?");
    $cleanup->execute([$path]);

    redirect('/tadeo-admin/images.php?msg=attached');
} catch (Throwable $e) {
    redirect('/tadeo-admin/images.php?msg=error');
}
