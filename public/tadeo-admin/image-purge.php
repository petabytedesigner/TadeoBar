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

try {
    $pdo = db();

    $stmt = $pdo->prepare("SELECT trash_path FROM image_trash WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        redirect('/tadeo-admin/image-trash.php?msg=invalid');
    }

    $trashPath = ltrim((string)$row['trash_path'], '/');

    if (str_starts_with($trashPath, 'uploads/trash/') && !str_contains($trashPath, '..')) {
        $absolutePath = dirname(__DIR__) . '/' . $trashPath;

        if (is_file($absolutePath)) {
            unlink($absolutePath);
        }
    }

    $delete = $pdo->prepare("DELETE FROM image_trash WHERE id = ?");
    $delete->execute([$id]);

    redirect('/tadeo-admin/image-trash.php?msg=purged');
} catch (Throwable $e) {
    redirect('/tadeo-admin/image-trash.php?msg=error');
}
