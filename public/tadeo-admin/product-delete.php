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
    redirect('/tadeo-admin/products.php?msg=' . rawurlencode('Produkt i pavlefshëm'));
}

try {
    $pdo = db();

    if (!product_ordering_trash($pdo, $id)) {
        redirect('/tadeo-admin/products.php?msg=' . rawurlencode('Produkti nuk u gjet'));
    }

    redirect('/tadeo-admin/products.php?msg=' . rawurlencode('Produkti u çua në kosh'));
} catch (Throwable $e) {
    redirect('/tadeo-admin/products.php?msg=' . rawurlencode('Produkti nuk u çua dot në kosh'));
}
