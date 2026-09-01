<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/product_ordering.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(403);
    exit('Forbidden.');
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    redirect('/tadeo-admin/products.php?msg=Produkt%20i%20pavlefshem');
}

try {
    $pdo = db();

    if (!product_ordering_trash($pdo, $id)) {
        redirect('/tadeo-admin/products.php?msg=Produkti%20nuk%20u%20gjet');
    }

    redirect('/tadeo-admin/products.php?msg=Produkti%20u%20cua%20ne%20kosh');
} catch (Throwable $e) {
    redirect('/tadeo-admin/products.php?msg=Produkti%20nuk%20u%20fshi');
}
