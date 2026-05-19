<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();

ensure_category_icon_image_column($pdo);

$categories = $pdo->query("
    SELECT
        c.id,
        c.slug,
        c.name_sq,
        c.name_en,
        c.icon,
        c.icon_image_path,
        c.sort_order,
        c.is_active,
        COUNT(p.id) AS product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id
    ORDER BY c.sort_order, c.id
")->fetchAll();

$activeCategories = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE is_active = 1")->fetchColumn();
$hiddenCategories = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE is_active = 0")->fetchColumn();
$totalCategories = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();

$flash = (string)($_GET['msg'] ?? '');
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Kategoritë | <?= e(site_bar_name()) ?> Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-admin-header-actions-2">
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'categories'); ?>

        <main>
            <h1 class="admin-title">Kategoritë</h1>
            <p class="admin-muted">
                Menaxho kategoritë e menusë, emrat, ikonat, imazhet, renditjen dhe statusin.
            </p>

            <?php if ($flash !== ''): ?>
                <div class="msg"><?= e($flash) ?></div>
            <?php endif; ?>

            <section class="grid">
                <article class="stat-card">
                    <small>Totali i kategorive</small>
                    <strong><?= e($totalCategories) ?></strong>
                </article>
                <article class="stat-card">
                    <small>Kategori aktive</small>
                    <strong><?= e($activeCategories) ?></strong>
                </article>
                <article class="stat-card">
                    <small>Kategori të fshehura</small>
                    <strong><?= e($hiddenCategories) ?></strong>
                </article>
            </section>

            <p>
                <a class="btn" style="width:auto" href="/tadeo-admin/category-create.php">+ Shto kategori</a>
            </p>

            <section class="product-grid">
                <?php foreach ($categories as $category): ?>
                    <article class="product-admin-card">
                        <div class="product-admin-top">
                            <div class="category-heading">
                                <div class="category-media">
                                    <?php if (!empty($category['icon_image_path'])): ?>
                                        <img src="/<?= e($category['icon_image_path']) ?>" alt="<?= e($category['name_sq']) ?>">
                                    <?php else: ?>
                                        <span><?= e($category['icon'] ?: '•') ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="product-number">#<?= e($category['sort_order']) ?></div>
                            </div>

                            <?php if ((int)$category['is_active'] === 1): ?>
                                <span class="badge badge-active">Aktive</span>
                            <?php else: ?>
                                <span class="badge badge-hidden">E fshehur</span>
                            <?php endif; ?>
                        </div>

                        <h3><?= e($category['name_sq']) ?></h3>
                        <p><?= e($category['name_en']) ?></p>

                        <div class="admin-muted">Produkte: <?= e($category['product_count']) ?></div>

                        <div class="product-actions">
                            <a class="btn btn-secondary" href="/tadeo-admin/category-edit.php?id=<?= e($category['id']) ?>">Ndrysho</a>

                            <form method="post" action="/tadeo-admin/category-toggle.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e($category['id']) ?>">
                                <input type="hidden" name="is_active" value="<?= (int)$category['is_active'] === 1 ? '0' : '1' ?>">
                                <button type="submit" class="btn-secondary">
                                    <?= (int)$category['is_active'] === 1 ? 'Fshih nga menuja' : 'Shfaq në menu' ?>
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        </main>
    </div>
</body>
</html>
