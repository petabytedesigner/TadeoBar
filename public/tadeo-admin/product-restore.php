<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/product_ordering.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(403);
    exit('Kërkesa nuk lejohet.');
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    redirect('/tadeo-admin/product-trash.php?msg=' . rawurlencode('Produkt i pavlefshëm'));
}

try {
    $pdo = db();

    if (!product_ordering_restore($pdo, $id)) {
        redirect('/tadeo-admin/product-trash.php?msg=' . rawurlencode('Produkt i pavlefshëm'));
    }

    redirect('/tadeo-admin/product-trash.php?msg=' . rawurlencode('Produkti u rikthye si i fshehur'));
} catch (Throwable $e) {
    redirect('/tadeo-admin/product-trash.php?msg=' . rawurlencode('Produkti nuk u rikthye'));
}
