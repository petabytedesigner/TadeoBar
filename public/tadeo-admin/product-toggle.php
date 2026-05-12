<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(403);
    exit('E ndaluar.');
}

$id = (int)($_POST['id'] ?? 0);
$isActive = (int)($_POST['is_active'] ?? 0);

if ($id <= 0) {
    redirect('/tadeo-admin/products.php?msg=Produkt i pavlefshëm');
}

$stmt = db()->prepare("UPDATE products SET is_active = ? WHERE id = ?");
$stmt->execute([$isActive === 1 ? 1 : 0, $id]);

redirect('/tadeo-admin/products.php?msg=Produkti u përditësua');
