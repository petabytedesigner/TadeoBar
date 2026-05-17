<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

function ensure_product_trash_column(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'deleted_at'");
    $exists = $stmt !== false && $stmt->fetch() !== false;

    if (!$exists) {
        $pdo->exec("ALTER TABLE products ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
    }
}

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
    ensure_product_trash_column($pdo);

    $stmt = $pdo->prepare("
        UPDATE products
        SET deleted_at = NULL,
            is_active = 0
        WHERE id = ?
          AND deleted_at IS NOT NULL
        LIMIT 1
    ");
    $stmt->execute([$id]);

    redirect('/tadeo-admin/product-trash.php?msg=Produkti%20u%20rikthye%20si%20i%20fshehur');
} catch (Throwable $e) {
    redirect('/tadeo-admin/product-trash.php?msg=Produkti%20nuk%20u%20rikthye');
}
