<?php
declare(strict_types=1);

function trash_cleanup_table_exists(PDO $pdo, string $table): bool
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

function trash_cleanup_column_exists(PDO $pdo, string $table, string $column): bool
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

function trash_cleanup_marker_path(): string
{
    return dirname(__DIR__) . '/uploads/.trash-cleanup-last-run';
}

function trash_cleanup_should_run(int $intervalSeconds = 86400): bool
{
    $marker = trash_cleanup_marker_path();

    if (!is_file($marker)) {
        return true;
    }

    $lastRun = (int)@filemtime($marker);

    return $lastRun <= 0 || (time() - $lastRun) >= $intervalSeconds;
}

function trash_cleanup_mark_run(): void
{
    $marker = trash_cleanup_marker_path();
    $dir = dirname($marker);

    if (is_dir($dir)) {
        @touch($marker);
    }
}

function trash_cleanup_safe_product_image(?string $path): ?string
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

function trash_cleanup_product_image_shared(PDO $pdo, string $path, int $productId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE image_path = ? AND id <> ?');
    $stmt->execute([$path, $productId]);

    if ((int)$stmt->fetchColumn() > 0) {
        return true;
    }

    if (trash_cleanup_column_exists($pdo, 'categories', 'icon_image_path')) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE icon_image_path = ?');
        $stmt->execute([$path]);
        return (int)$stmt->fetchColumn() > 0;
    }

    return false;
}

function trash_cleanup_products(PDO $pdo): int
{
    if (!trash_cleanup_column_exists($pdo, 'products', 'deleted_at')) {
        return 0;
    }

    $rows = $pdo->query(
        "SELECT id, image_path
         FROM products
         WHERE deleted_at IS NOT NULL
           AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
         ORDER BY id"
    )->fetchAll();

    if ($rows === []) {
        return 0;
    }

    $root = dirname(__DIR__);
    $deleted = 0;
    $hadFailure = false;

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        if ($id <= 0) {
            continue;
        }

        $imageAbsolute = '';
        $quarantinePath = '';
        $quarantined = false;

        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare(
                "SELECT id, image_path
                 FROM products
                 WHERE id = ?
                   AND deleted_at IS NOT NULL
                   AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                 LIMIT 1
                 FOR UPDATE"
            );
            $lock->execute([$id]);
            $current = $lock->fetch();

            if (!$current) {
                $pdo->rollBack();
                continue;
            }

            $imagePath = trash_cleanup_safe_product_image($current['image_path'] ?? null);

            if ($imagePath !== null && !trash_cleanup_product_image_shared($pdo, $imagePath, $id)) {
                $imageAbsolute = $root . '/' . $imagePath;

                if (is_file($imageAbsolute)) {
                    $quarantinePath = $imageAbsolute . '.cleanup-' . bin2hex(random_bytes(8));
                    if (!@rename($imageAbsolute, $quarantinePath)) {
                        throw new RuntimeException('Product image quarantine failed.');
                    }
                    $quarantined = true;
                }
            }

            $stmt = $pdo->prepare('DELETE FROM products WHERE id = ? AND deleted_at IS NOT NULL');
            $stmt->execute([$id]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Product cleanup delete failed.');
            }

            if (trash_cleanup_table_exists($pdo, 'image_detach_history')) {
                $history = $pdo->prepare("DELETE FROM image_detach_history WHERE owner_type = 'product' AND owner_id = ?");
                $history->execute([$id]);
            }

            $pdo->commit();
            $deleted++;

            if ($quarantined && is_file($quarantinePath) && !@unlink($quarantinePath)) {
                if ($imageAbsolute !== '' && !is_file($imageAbsolute)) {
                    @rename($quarantinePath, $imageAbsolute);
                }
                $hadFailure = true;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($quarantined && is_file($quarantinePath) && $imageAbsolute !== '' && !is_file($imageAbsolute)) {
                @rename($quarantinePath, $imageAbsolute);
            }

            $hadFailure = true;
        }
    }

    if ($hadFailure) {
        throw new RuntimeException('Një ose më shumë produkte/imazhe nuk u pastruan plotësisht.');
    }

    return $deleted;
}

function trash_cleanup_images(PDO $pdo): int
{
    if (!trash_cleanup_table_exists($pdo, 'image_trash')) {
        return 0;
    }

    $rows = $pdo->query(
        "SELECT id
         FROM image_trash
         WHERE deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
         ORDER BY id"
    )->fetchAll();

    if ($rows === []) {
        return 0;
    }

    $root = dirname(__DIR__);
    $deleted = 0;
    $hadFailure = false;

    foreach ($rows as $candidate) {
        $id = (int)$candidate['id'];
        if ($id <= 0) {
            $hadFailure = true;
            continue;
        }

        $trashAbsolute = '';
        $quarantinePath = '';
        $quarantined = false;
        $row = null;

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT *
                 FROM image_trash
                 WHERE id = ?
                   AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            if (!$row) {
                $pdo->rollBack();
                continue;
            }

            $trashPath = ltrim((string)$row['trash_path'], '/');

            if (
                $trashPath === '' ||
                str_contains($trashPath, '..') ||
                str_contains($trashPath, "\0") ||
                !str_starts_with($trashPath, 'uploads/trash/')
            ) {
                throw new RuntimeException('Invalid image trash path.');
            }

            $trashAbsolute = $root . '/' . $trashPath;

            if (is_file($trashAbsolute)) {
                $quarantinePath = $trashAbsolute . '.cleanup-' . bin2hex(random_bytes(8));
                if (!@rename($trashAbsolute, $quarantinePath)) {
                    throw new RuntimeException('Image trash quarantine failed.');
                }
                $quarantined = true;
            }

            $delete = $pdo->prepare('DELETE FROM image_trash WHERE id = ?');
            $delete->execute([$id]);

            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('Image trash cleanup delete failed.');
            }

            $pdo->commit();
            $deleted++;

            if ($quarantined && is_file($quarantinePath) && !@unlink($quarantinePath)) {
                if ($trashAbsolute !== '' && !is_file($trashAbsolute)) {
                    @rename($quarantinePath, $trashAbsolute);
                }

                if ($row !== null && is_file($trashAbsolute)) {
                    try {
                        $restore = $pdo->prepare(
                            "INSERT INTO image_trash
                                (id, original_path, trash_path, owner_type, owner_id, menu_number, name_sq, name_en, deleted_at)
                             VALUES
                                (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $restore->execute([
                            (int)$row['id'],
                            (string)$row['original_path'],
                            (string)$row['trash_path'],
                            $row['owner_type'],
                            $row['owner_id'],
                            $row['menu_number'],
                            $row['name_sq'],
                            $row['name_en'],
                            (string)$row['deleted_at'],
                        ]);
                    } catch (Throwable $e) {
                        // Keep the restored file; Menu Audit can surface it if DB compensation fails.
                    }
                }

                $hadFailure = true;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($quarantined && $quarantinePath !== '' && is_file($quarantinePath) && $trashAbsolute !== '' && !is_file($trashAbsolute)) {
                @rename($quarantinePath, $trashAbsolute);
            }

            $hadFailure = true;
        }
    }

    if ($hadFailure) {
        throw new RuntimeException('Një ose më shumë imazhe nuk u pastruan plotësisht.');
    }

    return $deleted;
}

function run_trash_cleanup_if_due(PDO $pdo): void
{
    if (!trash_cleanup_should_run()) {
        return;
    }

    try {
        trash_cleanup_products($pdo);
        trash_cleanup_images($pdo);
        trash_cleanup_mark_run();
    } catch (Throwable $e) {
        // Cleanup must never break the public menu. No marker means the next request may retry.
    }
}
