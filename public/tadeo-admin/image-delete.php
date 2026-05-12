<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/csrf.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/tadeo-admin/images.php');
}

if (!csrf_verify()) {
    redirect('/tadeo-admin/images.php?msg=csrf');
}

function image_delete_table_column_exists(PDO $pdo, string $table, string $column): bool
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

    if (!in_array($extension, $allowedExtensions, true)) {
        return null;
    }

    return $path;
}

$relativePath = image_delete_safe_relative_path((string)($_POST['path'] ?? ''));

if ($relativePath === null) {
    redirect('/tadeo-admin/images.php?msg=invalid');
}

$absolutePath = dirname(__DIR__) . '/' . $relativePath;
$pdo = db();

try {
    $stmt = $pdo->prepare('UPDATE products SET image_path = NULL WHERE image_path = ?');
    $stmt->execute([$relativePath]);

    if (image_delete_table_column_exists($pdo, 'categories', 'icon_image_path')) {
        $stmt = $pdo->prepare('UPDATE categories SET icon_image_path = NULL WHERE icon_image_path = ?');
        $stmt->execute([$relativePath]);
    }

    if (is_file($absolutePath) && !unlink($absolutePath)) {
        redirect('/tadeo-admin/images.php?msg=delete_failed');
    }

    redirect('/tadeo-admin/images.php?msg=deleted');
} catch (Throwable $e) {
    redirect('/tadeo-admin/images.php?msg=error');
}
