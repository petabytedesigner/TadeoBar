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
    redirect('/tadeo-admin/product-trash.php?msg=Produkt%20i%20pavlefshem');
}

try {
    $pdo = db();

    if (!product_ordering_restore($pdo, $id)) {
        redirect('/tadeo-admin/product-trash.php?msg=Produkt%20i%20pavlefshem');
    }

    redirect('/tadeo-admin/product-trash.php?msg=Produkti%20u%20rikthye%20si%20i%20fshehur');
} catch (Throwable $e) {
    redirect('/tadeo-admin/product-trash.php?msg=Produkti%20nuk%20u%20rikthye');
}
