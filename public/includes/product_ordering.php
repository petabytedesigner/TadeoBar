<?php
declare(strict_types=1);

/**
 * Keep all non-trashed products numbered strictly 1..N.
 *
 * Trashed products release menu_number and preserve their former position in
 * trash_menu_number. MySQL UNIQUE permits multiple NULL menu_number values,
 * so any number can immediately be reused by the live menu.
 */

function product_ordering_column_info(PDO $pdo, string $column): ?array
{
    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'products'
           AND COLUMN_NAME = ?
         LIMIT 1"
    );
    $stmt->execute([$column]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function product_ordering_live_rows(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, menu_number
         FROM products
         WHERE deleted_at IS NULL
         ORDER BY (menu_number IS NULL), menu_number, id"
    )->fetchAll();
}

function product_ordering_sequence_is_strict(array $rows): bool
{
    $expected = 1;

    foreach ($rows as $row) {
        if ((int)($row['menu_number'] ?? 0) !== $expected) {
            return false;
        }
        $expected++;
    }

    return true;
}

function product_ordering_normalize_live(PDO $pdo): void
{
    $rows = product_ordering_live_rows($pdo);

    if (product_ordering_sequence_is_strict($rows)) {
        return;
    }

    if ($pdo->inTransaction()) {
        throw new RuntimeException('Numërimi i produkteve nuk mund të normalizohet brenda një transaksioni tjetër.');
    }

    $pdo->beginTransaction();

    try {
        // Release all live numbers first so the UNIQUE key cannot collide while
        // the compact 1..N sequence is rebuilt.
        $pdo->exec("UPDATE products SET menu_number = NULL WHERE deleted_at IS NULL");

        $update = $pdo->prepare(
            "UPDATE products
             SET menu_number = ?
             WHERE id = ?
               AND deleted_at IS NULL"
        );

        $next = 1;
        foreach ($rows as $row) {
            $update->execute([$next, (int)$row['id']]);
            $next++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function ensure_product_ordering_schema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    if ($pdo->inTransaction()) {
        throw new RuntimeException('Schema e numërimit duhet verifikuar jashtë transaksionit.');
    }

    if (product_ordering_column_info($pdo, 'deleted_at') === null) {
        $pdo->exec(
            "ALTER TABLE products
             ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at"
        );
    }

    if (product_ordering_column_info($pdo, 'trash_menu_number') === null) {
        $pdo->exec(
            "ALTER TABLE products
             ADD COLUMN trash_menu_number INT UNSIGNED NULL DEFAULT NULL AFTER menu_number"
        );
    }

    $menuNumberInfo = product_ordering_column_info($pdo, 'menu_number');
    if ($menuNumberInfo === null) {
        throw new RuntimeException('Kolona products.menu_number mungon.');
    }

    if (strtoupper((string)$menuNumberInfo['IS_NULLABLE']) !== 'YES') {
        $pdo->exec(
            "ALTER TABLE products
             MODIFY COLUMN menu_number INT UNSIGNED NULL DEFAULT NULL"
        );
    }

    // Upgrade legacy trash rows before compacting the live sequence.
    $pdo->exec(
        "UPDATE products
         SET trash_menu_number = COALESCE(trash_menu_number, menu_number),
             menu_number = NULL
         WHERE deleted_at IS NOT NULL
           AND menu_number IS NOT NULL"
    );

    product_ordering_normalize_live($pdo);
    $done = true;
}

function product_ordering_live_count(PDO $pdo): int
{
    return (int)$pdo->query(
        "SELECT COUNT(*)
         FROM products
         WHERE deleted_at IS NULL"
    )->fetchColumn();
}

function product_ordering_validate_position(int $position, int $maxPosition): void
{
    if ($position < 1 || $position > $maxPosition) {
        throw new RuntimeException(
            'Numri i produktit duhet të jetë nga 1 deri në ' . $maxPosition . '.'
        );
    }
}

function product_ordering_prepare_insert(PDO $pdo, int $position): void
{
    $count = product_ordering_live_count($pdo);
    product_ordering_validate_position($position, $count + 1);

    if ($position > $count) {
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE products
         SET menu_number = menu_number + 1
         WHERE deleted_at IS NULL
           AND menu_number >= ?
         ORDER BY menu_number DESC"
    );
    $stmt->execute([$position]);
}

function product_ordering_move_live(PDO $pdo, int $productId, int $newPosition): void
{
    $stmt = $pdo->prepare(
        "SELECT menu_number
         FROM products
         WHERE id = ?
           AND deleted_at IS NULL
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([$productId]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new RuntimeException('Produkti nuk u gjet në menunë aktive.');
    }

    $oldPosition = (int)$row['menu_number'];
    $count = product_ordering_live_count($pdo);
    product_ordering_validate_position($newPosition, $count);

    if ($oldPosition === $newPosition) {
        return;
    }

    $release = $pdo->prepare(
        "UPDATE products
         SET menu_number = NULL
         WHERE id = ?
           AND deleted_at IS NULL"
    );
    $release->execute([$productId]);

    if ($newPosition < $oldPosition) {
        $shift = $pdo->prepare(
            "UPDATE products
             SET menu_number = menu_number + 1
             WHERE deleted_at IS NULL
               AND menu_number >= ?
               AND menu_number < ?
             ORDER BY menu_number DESC"
        );
        $shift->execute([$newPosition, $oldPosition]);
    } else {
        $shift = $pdo->prepare(
            "UPDATE products
             SET menu_number = menu_number - 1
             WHERE deleted_at IS NULL
               AND menu_number > ?
               AND menu_number <= ?
             ORDER BY menu_number ASC"
        );
        $shift->execute([$oldPosition, $newPosition]);
    }

    $assign = $pdo->prepare(
        "UPDATE products
         SET menu_number = ?
         WHERE id = ?
           AND deleted_at IS NULL"
    );
    $assign->execute([$newPosition, $productId]);
}

function product_ordering_trash(PDO $pdo, int $productId): bool
{
    ensure_product_ordering_schema($pdo);
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "SELECT menu_number
             FROM products
             WHERE id = ?
               AND deleted_at IS NULL
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([$productId]);
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->rollBack();
            return false;
        }

        $oldPosition = (int)$row['menu_number'];
        if ($oldPosition <= 0) {
            throw new RuntimeException('Produkti nuk ka numër menuje të vlefshëm.');
        }

        $trash = $pdo->prepare(
            "UPDATE products
             SET trash_menu_number = ?,
                 menu_number = NULL,
                 deleted_at = NOW(),
                 is_active = 0
             WHERE id = ?
               AND deleted_at IS NULL"
        );
        $trash->execute([$oldPosition, $productId]);

        if ($trash->rowCount() !== 1) {
            throw new RuntimeException('Produkti nuk u çua dot në kosh.');
        }

        $shift = $pdo->prepare(
            "UPDATE products
             SET menu_number = menu_number - 1
             WHERE deleted_at IS NULL
               AND menu_number > ?
             ORDER BY menu_number ASC"
        );
        $shift->execute([$oldPosition]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function product_ordering_restore(PDO $pdo, int $productId): bool
{
    ensure_product_ordering_schema($pdo);
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "SELECT trash_menu_number
             FROM products
             WHERE id = ?
               AND deleted_at IS NOT NULL
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([$productId]);
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->rollBack();
            return false;
        }

        $count = product_ordering_live_count($pdo);
        $originalPosition = (int)($row['trash_menu_number'] ?? 0);
        $restorePosition = $originalPosition >= 1 && $originalPosition <= ($count + 1)
            ? $originalPosition
            : ($count + 1);

        if ($restorePosition <= $count) {
            $shift = $pdo->prepare(
                "UPDATE products
                 SET menu_number = menu_number + 1
                 WHERE deleted_at IS NULL
                   AND menu_number >= ?
                 ORDER BY menu_number DESC"
            );
            $shift->execute([$restorePosition]);
        }

        $restore = $pdo->prepare(
            "UPDATE products
             SET menu_number = ?,
                 trash_menu_number = NULL,
                 deleted_at = NULL,
                 is_active = 0
             WHERE id = ?
               AND deleted_at IS NOT NULL"
        );
        $restore->execute([$restorePosition, $productId]);

        if ($restore->rowCount() !== 1) {
            throw new RuntimeException('Produkti nuk u rikthye dot.');
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
