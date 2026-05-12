<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();

$q = trim((string)($_GET['q'] ?? ''));
$categoryId = (int)($_GET['category_id'] ?? 0);
$status = (string)($_GET['status'] ?? 'all');

$categories = $pdo->query("
    SELECT id, name_sq, name_en
    FROM categories
    WHERE is_active = 1
    ORDER BY sort_order, id
")->fetchAll();

$where = ['1 = 1'];
$params = [];

if ($q !== '') {
    $where[] = '(p.name_sq LIKE ? OR p.name_en LIKE ? OR CAST(p.menu_number AS CHAR) LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($categoryId > 0) {
    $where[] = 'p.category_id = ?';
    $params[] = $categoryId;
}

if ($status === 'active') {
    $where[] = 'p.is_active = 1';
} elseif ($status === 'hidden') {
    $where[] = 'p.is_active = 0';
}

$sql = "
    SELECT
        p.id,
        p.menu_number,
        p.name_sq,
        p.name_en,
        p.price_all,
        p.image_path,
        p.is_active,
        p.sort_order,
        c.name_en AS category_name_en,
        c.name_sq AS category_name_sq
    FROM products p
    INNER JOIN categories c ON c.id = p.category_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY c.sort_order, p.sort_order, p.menu_number
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$activeProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 1")->fetchColumn();
$hiddenProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 0")->fetchColumn();
$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

$flash = (string)($_GET['msg'] ?? '');
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Produktet | Tadeo Bar Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-logout-final-1">
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'products'); ?>

        <main>
            <h1 class="admin-title">Produktet</h1>
            <p class="admin-muted">
                Menaxho emrat, çmimet, kategoritë, statusin dhe imazhet e produkteve.
            </p>

            <?php if ($flash !== ''): ?>
                <div class="msg"><?= e($flash) ?></div>
            <?php endif; ?>

            <section class="grid">
                <article class="stat-card">
                    <small>Totali i produkteve</small>
                    <strong><?= e($totalProducts) ?></strong>
                </article>
                <article class="stat-card">
                    <small>Produkte aktive</small>
                    <strong><?= e($activeProducts) ?></strong>
                </article>
                <article class="stat-card">
                    <small>Produkte të fshehura</small>
                    <strong><?= e($hiddenProducts) ?></strong>
                </article>
                <article class="stat-card">
                    <small>Të shfaqura këtu</small>
                    <strong><?= e(count($products)) ?></strong>
                </article>
            </section>

            <form class="toolbar" method="get">
                <div>
                    <label>Kërko</label>
                    <input name="q" value="<?= e($q) ?>" placeholder="Kërko produkt ose numër">
                </div>

                <div>
                    <label>Kategoria</label>
                    <select name="category_id">
                        <option value="0">Të gjitha kategoritë</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= e($category['id']) ?>" <?= (int)$category['id'] === $categoryId ? 'selected' : '' ?>>
                                <?= e($category['name_sq']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Statusi</label>
                    <select name="status">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Të gjitha</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Aktive</option>
                        <option value="hidden" <?= $status === 'hidden' ? 'selected' : '' ?>>Të fshehura</option>
                    </select>
                </div>

                <button type="submit">Filtro</button>
            </form>

            <p>
                <a class="btn" style="width:auto" href="/tadeo-admin/product-create.php">+ Shto produkt</a>
            </p>

            <section class="product-grid">
                <?php foreach ($products as $product): ?>
                    <article class="product-admin-card">
                        <div class="product-admin-top">
                            <div class="product-number">#<?= e($product['menu_number']) ?></div>
                            <?php if ((int)$product['is_active'] === 1): ?>
                                <span class="badge badge-active">Aktiv</span>
                            <?php else: ?>
                                <span class="badge badge-hidden">I fshehur</span>
                            <?php endif; ?>
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

                        <div class="product-actions">
                            <a class="btn btn-secondary" href="/tadeo-admin/product-edit.php?id=<?= e($product['id']) ?>">Ndrysho</a>

                            <form method="post" action="/tadeo-admin/product-toggle.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e($product['id']) ?>">
                                <input type="hidden" name="is_active" value="<?= (int)$product['is_active'] === 1 ? '0' : '1' ?>">
                                <button type="submit" class="btn-secondary">
                                    <?= (int)$product['is_active'] === 1 ? 'Fshih nga menuja' : 'Shfaq në menu' ?>
                                </button>
                            </form>

                            <form method="post" action="/tadeo-admin/product-delete.php" onsubmit="return confirm('Je i sigurt që do ta fshish këtë produkt përgjithmonë? Ky veprim nuk kthehet pas.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e($product['id']) ?>">
                                <button type="submit" class="btn-danger">Fshi përgjithmonë</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        </main>
    </div>
</body>
</html>
