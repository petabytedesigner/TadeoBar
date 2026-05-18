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

function trash_cleanup_products(PDO $pdo): int
{
    if (!trash_cleanup_column_exists($pdo, 'products', 'deleted_at')) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "DELETE FROM products
         WHERE deleted_at IS NOT NULL
           AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $stmt->execute();

    return $stmt->rowCount();
}

function trash_cleanup_images(PDO $pdo): int
{
    if (!trash_cleanup_table_exists($pdo, 'image_trash')) {
        return 0;
    }

    $rows = $pdo->query(
        "SELECT id, trash_path
         FROM image_trash
         WHERE deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    )->fetchAll();

    if ($rows === []) {
        return 0;
    }

    $root = dirname(__DIR__);
    $deleted = 0;

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $trashPath = ltrim((string)$row['trash_path'], '/');

        if (
            $id <= 0 ||
            $trashPath === '' ||
            str_contains($trashPath, '..') ||
            str_contains($trashPath, "\0") ||
            !str_starts_with($trashPath, 'uploads/trash/')
        ) {
            continue;
        }

        $absolutePath = $root . '/' . $trashPath;

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        $stmt = $pdo->prepare("DELETE FROM image_trash WHERE id = ?");
        $stmt->execute([$id]);

        $deleted += $stmt->rowCount();
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
    } catch (Throwable $e) {
        // Cleanup must never break the public menu.
    } finally {
        trash_cleanup_mark_run();
    }
}
