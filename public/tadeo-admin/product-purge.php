<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

function ensure_product_trash_column(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'deleted_at'");
    $exists = $stmt !== false && $stmt->fetch() !== false;

    if (!$exists) {
        $pdo->exec("ALTER TABLE products ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
    }
}

function product_purge_safe_image_path(?string $path): ?string
{
    $path = ltrim(trim((string)$path), '/');

    if (
        $path === '' ||
        str_contains($path, '..') ||
        str_contains($path, "\0") ||
        !str_starts_with($path, 'uploads/products/')
    ) {
        return null;
    }

    return $path;
}

function product_purge_image_is_shared(PDO $pdo, string $path, int $productId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE image_path = ? AND id <> ?');
    $stmt->execute([$path, $productId]);

    if ((int)$stmt->fetchColumn() > 0) {
        return true;
    }

    $column = $pdo->query("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'categories'
          AND COLUMN_NAME = 'icon_image_path'
    ");

    if ($column !== false && (int)$column->fetchColumn() > 0) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE icon_image_path = ?');
        $stmt->execute([$path]);
        return (int)$stmt->fetchColumn() > 0;
    }

    return false;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(403);
    exit('Forbidden.');
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    redirect('/tadeo-admin/product-trash.php?msg=invalid');
}

$pdo = db();
$imageAbsolute = '';
$quarantinePath = '';
$imageQuarantined = false;

try {
    ensure_product_trash_column($pdo);
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, menu_number, name_sq, name_en, image_path, deleted_at
        FROM products
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$id]);
    $product = $stmt->fetch();

    if (!$product || $product['deleted_at'] === null) {
        $pdo->rollBack();
        redirect('/tadeo-admin/product-trash.php?msg=invalid');
    }

    $imagePath = product_purge_safe_image_path($product['image_path'] ?? null);

    if ($imagePath !== null && !product_purge_image_is_shared($pdo, $imagePath, $id)) {
        $imageAbsolute = dirname(__DIR__) . '/' . $imagePath;

        if (is_file($imageAbsolute)) {
            $quarantinePath = $imageAbsolute . '.purge-' . bin2hex(random_bytes(8));
            if (!@rename($imageAbsolute, $quarantinePath)) {
                throw new RuntimeException('Imazhi i produktit nuk u përgatit dot për fshirje.');
            }
            $imageQuarantined = true;
        }
    }

    $stmt = $pdo->prepare("
        DELETE FROM products
        WHERE id = ?
          AND deleted_at IS NOT NULL
        LIMIT 1
    ");
    $stmt->execute([$id]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Produkti nuk u fshi dot nga databaza.');
    }

    $historyExists = $pdo->query("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'image_detach_history'
    ");

    if ($historyExists !== false && (int)$historyExists->fetchColumn() > 0) {
        $cleanup = $pdo->prepare("DELETE FROM image_detach_history WHERE owner_type = 'product' AND owner_id = ?");
        $cleanup->execute([$id]);
    }

    $pdo->commit();

    if ($imageQuarantined && is_file($quarantinePath) && !@unlink($quarantinePath)) {
        if ($imageAbsolute !== '' && !is_file($imageAbsolute)) {
            @rename($quarantinePath, $imageAbsolute);
        }
    }

    redirect('/tadeo-admin/product-trash.php?msg=purged');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($imageQuarantined && $quarantinePath !== '' && is_file($quarantinePath) && $imageAbsolute !== '' && !is_file($imageAbsolute)) {
        @rename($quarantinePath, $imageAbsolute);
    }

    redirect('/tadeo-admin/product-trash.php?msg=error');
}
