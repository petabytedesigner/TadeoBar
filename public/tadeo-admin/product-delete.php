<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(403);
    exit('Forbidden.');
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    redirect('/tadeo-admin/products.php?msg=Produkt i pavlefshëm');
}

$stmt = db()->prepare("DELETE FROM products WHERE id = ?");
$stmt->execute([$id]);

redirect('/tadeo-admin/products.php?msg=Produkti u fshi');
