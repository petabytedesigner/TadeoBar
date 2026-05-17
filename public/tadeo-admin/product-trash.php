<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();

function ensure_product_trash_column(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'deleted_at'");
    $exists = $stmt !== false && $stmt->fetch() !== false;

    if (!$exists) {
        $pdo->exec("ALTER TABLE products ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
    }
}

ensure_product_trash_column($pdo);

$products = $pdo->query("
    SELECT
        p.id,
        p.menu_number,
        p.name_sq,
        p.name_en,
        p.price_all,
        p.image_path,
        p.deleted_at,
        c.name_sq AS category_name_sq,
        c.name_en AS category_name_en
    FROM products p
    INNER JOIN categories c ON c.id = p.category_id
    WHERE p.deleted_at IS NOT NULL
    ORDER BY p.deleted_at DESC, p.id DESC
")->fetchAll();

$flash = (string)($_GET['msg'] ?? '');
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Produktet e fshira | Tadeo Bar Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-admin-header-actions-2">
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'products'); ?>

        <main>
            <h1 class="admin-title">Produktet e fshira</h1>
            <p class="admin-muted">
                Këto produkte janë në kosh. Nuk shfaqen në menunë publike. Mund t’i rikthesh si produkte të fshehura.
            </p>

            <?php if ($flash !== ''): ?>
                <div class="msg"><?= e($flash) ?></div>
            <?php endif; ?>

            <p>
                <a class="btn btn-secondary" style="width:auto" href="/tadeo-admin/products.php">← Kthehu te produktet</a>
            </p>

            <?php if ($products === []): ?>
                <div class="panel">
                    <p class="admin-muted">Nuk ka produkte të fshira.</p>
                </div>
            <?php else: ?>
                <section class="product-grid">
                    <?php foreach ($products as $product): ?>
                        <article class="product-admin-card">
                            <div class="product-admin-top">
                                <div class="product-number">#<?= e($product['menu_number']) ?></div>
                                <span class="badge badge-hidden">Në kosh</span>
                            </div>

                            <?php if (!empty($product['image_path'])): ?>
                                <div class="product-thumb">
                                    <img src="/<?= e($product['image_path']) ?>" alt="<?= e($product['name_sq']) ?>">
                                </div>
                            <?php endif; ?>

                            <h3><?= e($product['name_sq']) ?></h3>
                            <p><?= e($product['name_en']) ?></p>

                            <div class="admin-muted"><?= e($product['category_name_sq']) ?></div>
                            <div class="product-price"><?= e($product['price_all']) ?> ALL</div>
                            <p class="admin-muted">Fshirë më: <?= e($product['deleted_at']) ?></p>

                            <div class="product-actions">
                                <form method="post" action="/tadeo-admin/product-restore.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e($product['id']) ?>">
                                    <button type="submit" class="btn-secondary">Rikthe si të fshehur</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
