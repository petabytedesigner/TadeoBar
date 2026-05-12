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

$pdo = db();
$type = (string)($_POST['type'] ?? '');
$id = (int)($_POST['id'] ?? 0);

try {
    if ($type === 'product' && $id > 0) {
        $stmt = $pdo->prepare('UPDATE products SET image_path = NULL WHERE id = ?');
        $stmt->execute([$id]);

        redirect('/tadeo-admin/images.php?msg=detached');
    }

    if ($type === 'category' && $id > 0) {
        $stmt = $pdo->prepare('UPDATE categories SET icon_image_path = NULL WHERE id = ?');
        $stmt->execute([$id]);

        redirect('/tadeo-admin/images.php?msg=detached');
    }

    redirect('/tadeo-admin/images.php?msg=invalid');
} catch (Throwable $e) {
    redirect('/tadeo-admin/images.php?msg=error');
}
