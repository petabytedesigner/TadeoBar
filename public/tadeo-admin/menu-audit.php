<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();

function audit_table_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);

    return (int)$stmt->fetchColumn() > 0;
}

function audit_safe_image_path(?string $path): ?string
{
    $path = trim((string)$path);
    $path = ltrim($path, '/');

    if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
        return null;
    }

    if (!str_starts_with($path, 'uploads/products/') && !str_starts_with($path, 'uploads/categories/')) {
        return null;
    }

    if (basename($path) === '.htaccess') {
        return null;
    }

    return $path;
}

function audit_image_exists(?string $path): bool
{
    $safe = audit_safe_image_path($path);

    if ($safe === null) {
        return false;
    }

    return is_file(dirname(__DIR__) . '/' . $safe);
}

function audit_uploaded_images(string $folder): array
{
    $base = dirname(__DIR__) . '/uploads/' . $folder;
    $relativeBase = 'uploads/' . $folder;

    if (!is_dir($base)) {
        return [];
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
    $items = [];

    foreach (scandir($base) ?: [] as $file) {
        if ($file === '.' || $file === '..' || $file === '.htaccess') {
            continue;
        }

        $absolute = $base . '/' . $file;

        if (!is_file($absolute)) {
            continue;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed, true)) {
            continue;
        }

        $items[] = $relativeBase . '/' . $file;
    }

    sort($items);

    return $items;
}

function audit_add(array &$bucket, string $severity, string $title, string $detail): void
{
    $bucket[] = [
        'severity' => $severity,
        'title' => $title,
        'detail' => $detail,
    ];
}

$critical = [];
$warnings = [];
$info = [];

$hasCategoryImages = audit_table_column_exists($pdo, 'categories', 'icon_image_path');

$categories = $pdo->query(
    "SELECT *
     FROM categories
     ORDER BY sort_order, id"
)->fetchAll();

$categoryById = [];
foreach ($categories as $category) {
    $categoryById[(int)$category['id']] = $category;
}

$categoryImageSelect = $hasCategoryImages ? 'c.icon_image_path' : 'NULL AS icon_image_path';

$products = $pdo->query(
    "SELECT
        p.*,
        c.name_sq AS category_name_sq,
        c.name_en AS category_name_en,
        c.is_active AS category_is_active,
        {$categoryImageSelect}
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     ORDER BY p.menu_number, p.id"
)->fetchAll();

$activeProducts = 0;
$hiddenProducts = 0;
$activeCategories = 0;
$hiddenCategories = 0;
$productsWithoutImages = 0;
$categoriesWithoutImages = 0;

foreach ($categories as $category) {
    $nameSq = trim((string)($category['name_sq'] ?? ''));
    $nameEn = trim((string)($category['name_en'] ?? ''));
    $slug = trim((string)($category['slug'] ?? ''));

    if ((int)$category['is_active'] === 1) {
        $activeCategories++;
    } else {
        $hiddenCategories++;
    }

    if ($nameSq === '') {
        audit_add($critical, 'critical', 'Kategori pa emër shqip', 'ID: ' . (string)$category['id']);
    }

    if ($nameEn === '') {
        audit_add($critical, 'critical', 'Kategori pa emër anglisht', 'ID: ' . (string)$category['id']);
    }

    if ($slug === '') {
        audit_add($critical, 'critical', 'Kategori pa slug', $nameSq !== '' ? $nameSq : 'ID: ' . (string)$category['id']);
    }

    if ($hasCategoryImages) {
        $imagePath = audit_safe_image_path($category['icon_image_path'] ?? null);

        if ($imagePath === null) {
            $categoriesWithoutImages++;
        } elseif (!audit_image_exists($imagePath)) {
            audit_add($warnings, 'warning', 'Imazh kategorie mungon në server', $nameSq . ' → ' . $imagePath);
        }
    } else {
        $categoriesWithoutImages = count($categories);
    }
}

$slugCounts = [];
foreach ($categories as $category) {
    $slug = trim((string)($category['slug'] ?? ''));
    if ($slug !== '') {
        $slugCounts[$slug] = ($slugCounts[$slug] ?? 0) + 1;
    }
}

foreach ($slugCounts as $slug => $count) {
    if ($count > 1) {
        audit_add($critical, 'critical', 'Slug kategorie i përsëritur', $slug . ' përdoret ' . $count . ' herë.');
    }
}

$menuNumberCounts = [];
foreach ($products as $product) {
    $menuNumber = (int)($product['menu_number'] ?? 0);

    if ($menuNumber > 0) {
        $menuNumberCounts[$menuNumber] = ($menuNumberCounts[$menuNumber] ?? 0) + 1;
    }
}

foreach ($products as $product) {
    $id = (int)$product['id'];
    $nameSq = trim((string)($product['name_sq'] ?? ''));
    $nameEn = trim((string)($product['name_en'] ?? ''));
    $price = (int)($product['price_all'] ?? 0);
    $menuNumber = (int)($product['menu_number'] ?? 0);
    $categoryId = (int)($product['category_id'] ?? 0);
    $isActive = (int)($product['is_active'] ?? 0);

    if ($isActive === 1) {
        $activeProducts++;
    } else {
        $hiddenProducts++;
    }

    $label = $nameSq !== '' ? $nameSq : 'Produkt ID: ' . $id;

    if ($nameSq === '') {
        audit_add($critical, 'critical', 'Produkt pa emër shqip', 'ID: ' . $id);
    }

    if ($nameEn === '') {
        audit_add($critical, 'critical', 'Produkt pa emër anglisht', $label);
    }

    if ($price <= 0) {
        audit_add($critical, 'critical', 'Produkt me çmim të pavlefshëm', $label . ' → ' . $price . ' ALL');
    }

    if ($menuNumber <= 0) {
        audit_add($critical, 'critical', 'Produkt me numër menuje të pavlefshëm', $label);
    }

    if ($categoryId <= 0 || !isset($categoryById[$categoryId])) {
        audit_add($critical, 'critical', 'Produkt pa kategori të vlefshme', $label);
    }

    if ($isActive === 1 && isset($product['category_is_active']) && (int)$product['category_is_active'] === 0) {
        audit_add($warnings, 'warning', 'Produkt aktiv në kategori joaktive', $label . ' → ' . (string)($product['category_name_sq'] ?? 'Kategori e panjohur'));
    }

    $imagePath = audit_safe_image_path($product['image_path'] ?? null);

    if ($imagePath === null) {
        $productsWithoutImages++;
    } elseif (!audit_image_exists($imagePath)) {
        audit_add($warnings, 'warning', 'Imazh produkti mungon në server', $label . ' → ' . $imagePath);
    }
}

foreach ($menuNumberCounts as $menuNumber => $count) {
    if ($count > 1) {
        audit_add($warnings, 'warning', 'Numër menuje i përsëritur', '#' . $menuNumber . ' përdoret ' . $count . ' herë.');
    }
}

$activeProductsByCategory = [];
foreach ($products as $product) {
    if ((int)$product['is_active'] !== 1) {
        continue;
    }

    $categoryId = (int)$product['category_id'];
    $activeProductsByCategory[$categoryId] = ($activeProductsByCategory[$categoryId] ?? 0) + 1;
}

foreach ($categories as $category) {
    $categoryId = (int)$category['id'];
    $isActive = (int)$category['is_active'] === 1;
    $activeCount = $activeProductsByCategory[$categoryId] ?? 0;

    if ($isActive && $activeCount === 0) {
        audit_add($warnings, 'warning', 'Kategori aktive pa produkte aktive', (string)$category['name_sq']);
    }
}

$usedPaths = [];
foreach ($products as $product) {
    $safe = audit_safe_image_path($product['image_path'] ?? null);
    if ($safe !== null) {
        $usedPaths[$safe] = true;
    }
}

if ($hasCategoryImages) {
    foreach ($categories as $category) {
        $safe = audit_safe_image_path($category['icon_image_path'] ?? null);
        if ($safe !== null) {
            $usedPaths[$safe] = true;
        }
    }
}

$uploadedPaths = array_merge(
    audit_uploaded_images('products'),
    audit_uploaded_images('categories')
);

$unusedImages = [];
foreach ($uploadedPaths as $path) {
    if (!isset($usedPaths[$path])) {
        $unusedImages[] = $path;
    }
}

if ($productsWithoutImages > 0) {
    audit_add($info, 'info', 'Produkte pa imazh', (string)$productsWithoutImages . ' produkte nuk kanë imazh.');
}

if ($categoriesWithoutImages > 0) {
    audit_add($info, 'info', 'Kategori pa imazh', (string)$categoriesWithoutImages . ' kategori nuk kanë imazh.');
}

if (count($unusedImages) > 0) {
    audit_add($info, 'info', 'Imazhe të palidhura', (string)count($unusedImages) . ' file janë në uploads por nuk përdoren nga DB.');
}

$totalProducts = count($products);
$totalCategories = count($categories);
$totalCritical = count($critical);
$totalWarnings = count($warnings);
$totalInfo = count($info);

function audit_render_items(array $items, string $emptyText): void
{
    if ($items === []) {
        ?>
        <div class="audit-empty"><?= e($emptyText) ?></div>
        <?php
        return;
    }

    foreach ($items as $item) {
        ?>
        <article class="audit-item audit-<?= e($item['severity']) ?>">
            <strong><?= e($item['title']) ?></strong>
            <p><?= e($item['detail']) ?></p>
        </article>
        <?php
    }
}
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Audit Menu | <?= e(site_bar_name()) ?> Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-admin-header-actions-2">
    <style>
        .audit-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-top: 20px;
        }

        .audit-section {
            margin-top: 24px;
        }

        .audit-list {
            display: grid;
            gap: 12px;
        }

        .audit-item {
            padding: 14px;
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,.10);
            background: rgba(255,255,255,.04);
        }

        .audit-item strong {
            display: block;
            margin-bottom: 6px;
        }

        .audit-item p {
            margin: 0;
            color: var(--muted);
            line-height: 1.45;
            word-break: break-word;
        }

        .audit-critical {
            border-color: rgba(214, 69, 69, .45);
            background: rgba(214, 69, 69, .10);
        }

        .audit-critical strong {
            color: #ffb6b6;
        }

        .audit-warning {
            border-color: rgba(243, 201, 109, .38);
            background: rgba(243, 201, 109, .08);
        }

        .audit-warning strong {
            color: var(--gold-light);
        }

        .audit-info strong {
            color: #d8e6ff;
        }

        .audit-empty {
            padding: 16px;
            border-radius: 16px;
            border: 1px solid rgba(107, 196, 127, .32);
            background: rgba(107, 196, 127, .10);
            color: #b7f5c5;
            font-weight: 900;
        }

        .audit-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 18px;
        }

        @media (max-width: 900px) {
            .audit-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 560px) {
            .audit-summary {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'dashboard'); ?>

        <main>
            <h1 class="admin-title">Audit Menu</h1>
            <p class="admin-muted">
                Kontroll read-only për produktet, kategoritë, imazhet dhe problemet që mund të ndikojnë te menuja publike.
            </p>

            <div class="audit-actions">
                <a class="btn btn-secondary" href="/tadeo-admin/products.php">Produktet</a>
                <a class="btn btn-secondary" href="/tadeo-admin/categories.php">Kategoritë</a>
                <a class="btn btn-secondary" href="/tadeo-admin/images.php">Imazhet</a>
                <a class="btn btn-secondary" href="/" target="_blank" rel="noopener">Hap menunë publike</a>
            </div>

            <section class="audit-summary">
                <article class="stat-card"><small>Produkte</small><strong><?= e($totalProducts) ?></strong></article>
                <article class="stat-card"><small>Kategori</small><strong><?= e($totalCategories) ?></strong></article>
                <article class="stat-card"><small>Gabime kritike</small><strong><?= e($totalCritical) ?></strong></article>
                <article class="stat-card"><small>Paralajmërime</small><strong><?= e($totalWarnings) ?></strong></article>
                <article class="stat-card"><small>Produkte aktive</small><strong><?= e($activeProducts) ?></strong></article>
                <article class="stat-card"><small>Produkte të fshehura</small><strong><?= e($hiddenProducts) ?></strong></article>
                <article class="stat-card"><small>Kategori aktive</small><strong><?= e($activeCategories) ?></strong></article>
                <article class="stat-card"><small>Kategori të fshehura</small><strong><?= e($hiddenCategories) ?></strong></article>
            </section>

            <section class="audit-section">
                <h2>Gabime kritike</h2>
                <div class="audit-list">
                    <?php audit_render_items($critical, 'Nuk u gjetën gabime kritike.'); ?>
                </div>
            </section>

            <section class="audit-section">
                <h2>Paralajmërime</h2>
                <div class="audit-list">
                    <?php audit_render_items($warnings, 'Nuk u gjetën paralajmërime.'); ?>
                </div>
            </section>

            <section class="audit-section">
                <h2>Informacion</h2>
                <div class="audit-list">
                    <?php audit_render_items($info, 'Nuk ka informacion shtesë për t’u shfaqur.'); ?>
                </div>
            </section>

            <?php if ($unusedImages !== []): ?>
                <section class="audit-section">
                    <h2>Imazhe të palidhura</h2>
                    <div class="audit-list">
                        <?php foreach ($unusedImages as $path): ?>
                            <article class="audit-item audit-info">
                                <strong><?= e($path) ?></strong>
                                <p>Ky file është në uploads, por nuk përdoret nga produkt ose kategori.</p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
