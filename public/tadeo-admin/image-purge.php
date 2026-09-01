<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    redirect('/tadeo-admin/image-trash.php?msg=csrf');
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    redirect('/tadeo-admin/image-trash.php?msg=invalid');
}

$pdo = db();
$trashAbsolute = '';
$quarantinePath = '';
$fileQuarantined = false;
$row = null;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM image_trash WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        $pdo->rollBack();
        redirect('/tadeo-admin/image-trash.php?msg=invalid');
    }

    $trashPath = ltrim((string)$row['trash_path'], '/');

    if (
        !str_starts_with($trashPath, 'uploads/trash/') ||
        str_contains($trashPath, '..') ||
        str_contains($trashPath, "\0")
    ) {
        throw new RuntimeException('Trash path i pavlefshëm.');
    }

    $trashAbsolute = dirname(__DIR__) . '/' . $trashPath;

    if (is_file($trashAbsolute)) {
        $quarantinePath = $trashAbsolute . '.purge-' . bin2hex(random_bytes(8));
        if (!@rename($trashAbsolute, $quarantinePath)) {
            throw new RuntimeException('Imazhi nuk u përgatit dot për fshirje permanente.');
        }
        $fileQuarantined = true;
    }

    $delete = $pdo->prepare("DELETE FROM image_trash WHERE id = ?");
    $delete->execute([$id]);

    if ($delete->rowCount() !== 1) {
        throw new RuntimeException('Record-i i koshit nuk u fshi dot.');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($fileQuarantined && $quarantinePath !== '' && is_file($quarantinePath) && !is_file($trashAbsolute)) {
        @rename($quarantinePath, $trashAbsolute);
    }

    redirect('/tadeo-admin/image-trash.php?msg=error');
}

if ($fileQuarantined && $quarantinePath !== '' && is_file($quarantinePath) && !@unlink($quarantinePath)) {
    @rename($quarantinePath, $trashAbsolute);

    if ($row !== null && is_file($trashAbsolute)) {
        try {
            $restore = $pdo->prepare("
                INSERT INTO image_trash
                    (id, original_path, trash_path, owner_type, owner_id, menu_number, name_sq, name_en, deleted_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
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
            // Keep the file instead of deleting data when compensation cannot update the DB.
        }
    }

    redirect('/tadeo-admin/image-trash.php?msg=error');
}

redirect('/tadeo-admin/image-trash.php?msg=purged');
