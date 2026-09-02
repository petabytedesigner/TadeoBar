<?php
declare(strict_types=1);

const AUDIT_PRODUCT_IMAGE_WARNING_BYTES = 512000;
const AUDIT_PRODUCT_IMAGE_MAX_BYTES = 819200;
const AUDIT_CATEGORY_IMAGE_MAX_BYTES = 512000;

const AUDIT_PRODUCT_RATIO_MIN = 0.55;
const AUDIT_PRODUCT_RATIO_MAX = 0.82;
const AUDIT_PRODUCT_MIN_WIDTH = 600;
const AUDIT_PRODUCT_MIN_HEIGHT = 1000;

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

function audit_absolute_path(string $relativePath): string
{
    return dirname(__DIR__) . '/' . ltrim($relativePath, '/');
}

function audit_uploaded_images(string $folder): array
{
    $folder = trim($folder, '/');
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

function audit_scan_dangerous_upload_files(): array
{
    $base = dirname(__DIR__) . '/uploads';
    $dangerousExtensions = ['php', 'phtml', 'phar', 'js', 'html', 'htm'];
    $items = [];

    if (!is_dir($base)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        $filename = $file->getFilename();
        if ($filename === '.htaccess') {
            continue;
        }

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $dangerousExtensions, true)) {
            continue;
        }

        $absolute = $file->getPathname();
        $items[] = 'uploads/' . ltrim(str_replace('\\', '/', substr($absolute, strlen($base))), '/');
    }

    sort($items);

    return $items;
}

function audit_human_file_size(int $bytes): string
{
    if ($bytes <= 0) {
        return '—';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $index = 0;
    $value = (float)$bytes;

    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    return $index === 0 ? (string)$bytes . ' B' : number_format($value, 1) . ' ' . $units[$index];
}

function audit_image_metadata(string $path): array
{
    $absolute = audit_absolute_path($path);

    if (!is_file($absolute)) {
        return [
            'exists' => false,
            'size' => 0,
            'width' => null,
            'height' => null,
            'readable' => false,
        ];
    }

    $dimensions = @getimagesize($absolute);
    $width = is_array($dimensions) && isset($dimensions[0]) ? (int)$dimensions[0] : null;
    $height = is_array($dimensions) && isset($dimensions[1]) ? (int)$dimensions[1] : null;

    return [
        'exists' => true,
        'size' => (int)filesize($absolute),
        'width' => $width,
        'height' => $height,
        'readable' => $width !== null && $height !== null,
    ];
}

function audit_dimensions_label(array $meta): string
{
    if (($meta['width'] ?? null) === null || ($meta['height'] ?? null) === null) {
        return 'dimensione të palexueshme';
    }

    return (string)$meta['width'] . '×' . (string)$meta['height'];
}

function audit_product_image_policy_issues(array $meta): array
{
    $width = $meta['width'] ?? null;
    $height = $meta['height'] ?? null;
    $issues = [];

    if (!is_int($width) || !is_int($height) || $width <= 0 || $height <= 0) {
        return [[
            'title' => 'Dimensionet e imazhit të produktit nuk lexohen',
            'detail' => 'dimensione të palexueshme',
        ]];
    }

    $ratio = $width / $height;

    if ($ratio < AUDIT_PRODUCT_RATIO_MIN || $ratio > AUDIT_PRODUCT_RATIO_MAX) {
        $issues[] = [
            'title' => 'Imazh produkti jashtë raportit të lejuar',
            'detail' => audit_dimensions_label($meta) . ' → raport ' . number_format($ratio, 3) . ' / lejohet 0.55–0.82',
        ];
    }

    if ($width < AUDIT_PRODUCT_MIN_WIDTH || $height < AUDIT_PRODUCT_MIN_HEIGHT) {
        $issues[] = [
            'title' => 'Imazh produkti me dimensione të vogla',
            'detail' => audit_dimensions_label($meta) . ' / minimumi ' . AUDIT_PRODUCT_MIN_WIDTH . '×' . AUDIT_PRODUCT_MIN_HEIGHT,
        ];
    }

    return $issues;
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
$hasProductDeletedAt = audit_table_column_exists($pdo, 'products', 'deleted_at');
$deletedAtSelect = $hasProductDeletedAt ? '' : ', NULL AS deleted_at';

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
        p.*{$deletedAtSelect},
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
$trashedProducts = 0;
$activeCategories = 0;
$hiddenCategories = 0;
$productsWithoutImages = 0;
$categoriesWithoutImages = 0;
$trashedProductItems = [];

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
        } else {
            $meta = audit_image_metadata($imagePath);

            if (!$meta['exists']) {
                audit_add($warnings, 'warning', 'Imazh kategorie mungon në server', $nameSq . ' → ' . $imagePath);
            } else {
                if (!$meta['readable']) {
                    audit_add($warnings, 'warning', 'Dimensionet e imazhit të kategorisë nuk lexohen', $nameSq . ' → ' . $imagePath);
                }

                if ((int)$meta['size'] > AUDIT_CATEGORY_IMAGE_MAX_BYTES) {
                    audit_add(
                        $warnings,
                        'warning',
                        'Imazh kategorie shumë i madh',
                        $nameSq . ' → ' . $imagePath . ' → ' . audit_human_file_size((int)$meta['size']) . ' / kufiri ' . audit_human_file_size(AUDIT_CATEGORY_IMAGE_MAX_BYTES)
                    );
                }
            }
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
$liveMenuNumbers = [];
foreach ($products as $product) {
    if (($product['deleted_at'] ?? null) !== null) {
        continue;
    }

    $menuNumber = (int)($product['menu_number'] ?? 0);

    if ($menuNumber > 0) {
        $menuNumberCounts[$menuNumber] = ($menuNumberCounts[$menuNumber] ?? 0) + 1;
        $liveMenuNumbers[] = $menuNumber;
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
    $deletedAt = $product['deleted_at'] ?? null;
    $isTrashed = $deletedAt !== null;
    $label = $nameSq !== '' ? $nameSq : 'Produkt ID: ' . $id;

    if ($isTrashed) {
        $trashedProducts++;
        $trashedProductItems[] = [
            'severity' => 'info',
            'title' => '#' . (string)$menuNumber . ' — ' . $label,
            'detail' => 'Në kosh që prej: ' . (string)$deletedAt,
        ];
        continue;
    }

    if ($isActive === 1) {
        $activeProducts++;
    } else {
        $hiddenProducts++;
    }

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
        audit_add($critical, 'critical', 'Produkt me pozicion menuje të pavlefshëm', $label);
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
    } else {
        $meta = audit_image_metadata($imagePath);

        if (!$meta['exists']) {
            audit_add($warnings, 'warning', 'Imazh produkti mungon në server', $label . ' → ' . $imagePath);
        } else {
            if (!$meta['readable']) {
                audit_add($warnings, 'warning', 'Dimensionet e imazhit të produktit nuk lexohen', $label . ' → ' . $imagePath);
            } else {
                foreach (audit_product_image_policy_issues($meta) as $issue) {
                    audit_add($warnings, 'warning', $issue['title'], $label . ' → ' . $imagePath . ' → ' . $issue['detail']);
                }
            }

            if ((int)$meta['size'] > AUDIT_PRODUCT_IMAGE_MAX_BYTES) {
                audit_add(
                    $warnings,
                    'warning',
                    'Imazh produkti mbi kufirin maksimal 800 KB',
                    $label . ' → ' . $imagePath . ' → ' . audit_human_file_size((int)$meta['size']) . ' / kufiri ' . audit_human_file_size(AUDIT_PRODUCT_IMAGE_MAX_BYTES)
                );
            } elseif ((int)$meta['size'] > AUDIT_PRODUCT_IMAGE_WARNING_BYTES) {
                audit_add(
                    $warnings,
                    'warning',
                    'Imazh produkti mbi 500 KB',
                    $label . ' → ' . $imagePath . ' → ' . audit_human_file_size((int)$meta['size']) . ' / rekomandohet 150–350 KB'
                );
            }
        }
    }
}

foreach ($menuNumberCounts as $menuNumber => $count) {
    if ($count > 1) {
        audit_add($critical, 'critical', 'Pozicion menuje i përsëritur', '#' . $menuNumber . ' përdoret ' . $count . ' herë.');
    }
}

sort($liveMenuNumbers, SORT_NUMERIC);
if ($liveMenuNumbers !== []) {
    $expectedMenuNumbers = range(1, count($liveMenuNumbers));

    if ($liveMenuNumbers !== $expectedMenuNumbers) {
        $missingNumbers = array_values(array_diff($expectedMenuNumbers, $liveMenuNumbers));
        $unexpectedNumbers = array_values(array_diff($liveMenuNumbers, $expectedMenuNumbers));
        $details = [];

        if ($missingNumbers !== []) {
            $details[] = 'mungojnë: ' . implode(', ', $missingNumbers);
        }

        if ($unexpectedNumbers !== []) {
            $details[] = 'jashtë rendit të pritshëm: ' . implode(', ', $unexpectedNumbers);
        }

        audit_add(
            $critical,
            'critical',
            'Numërimi i menusë ka boshllëqe ose vlera jashtë rendit',
            'Pozicionet duhet të formojnë një seri të vazhdueshme nga 1 deri te numri i produkteve jashtë koshit. ' . implode(' · ', $details)
        );
    }
}

$activeProductsByCategory = [];
foreach ($products as $product) {
    if (($product['deleted_at'] ?? null) !== null || (int)$product['is_active'] !== 1) {
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

$trashImages = array_merge(
    audit_uploaded_images('trash/products'),
    audit_uploaded_images('trash/categories')
);

$dangerousUploadFiles = audit_scan_dangerous_upload_files();

$missingDbFiles = 0;
foreach ($warnings as $warning) {
    if (str_contains($warning['title'], 'mungon në server')) {
        $missingDbFiles++;
    }
}

if ($productsWithoutImages > 0) {
    audit_add($info, 'info', 'Produkte pa imazh', (string)$productsWithoutImages . ' produkte aktive ose të fshehura nuk kanë imazh.');
}

if ($categoriesWithoutImages > 0) {
    audit_add($info, 'info', 'Kategori pa imazh', (string)$categoriesWithoutImages . ' kategori nuk kanë imazh.');
}

if (count($unusedImages) > 0) {
    audit_add($info, 'info', 'Skedarë në server që nuk përdoren', (string)count($unusedImages) . ' skedarë janë në uploads/products ose uploads/categories, por nuk përdoren nga databaza.');
}

if (count($trashImages) > 0) {
    audit_add($info, 'info', 'Imazhe në kosh', (string)count($trashImages) . ' skedarë janë në uploads/trash.');
}

if ($trashedProducts > 0) {
    audit_add($info, 'info', 'Produkte në kosh', (string)$trashedProducts . ' produkte janë në kosh dhe nuk shfaqen në menunë publike.');
}

if (count($dangerousUploadFiles) > 0) {
    audit_add($warnings, 'warning', 'Skedarë të rrezikshëm në uploads', (string)count($dangerousUploadFiles) . ' skedarë kanë një format që nuk duhet të jetë në uploads.');
}

$totalProducts = count($products);
$totalLiveProducts = $totalProducts - $trashedProducts;
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

function audit_render_path_list(array $paths, string $emptyText): void
{
    if ($paths === []) {
        ?>
        <div class="audit-empty"><?= e($emptyText) ?></div>
        <?php
        return;
    }

    foreach ($paths as $path) {
        $meta = audit_image_metadata($path);
        $detailParts = [];

        if ($meta['exists']) {
            $detailParts[] = audit_human_file_size((int)$meta['size']);
            $detailParts[] = audit_dimensions_label($meta);
        } else {
            $detailParts[] = 'skedari nuk u gjet në server';
        }
        ?>
        <article class="audit-item audit-info">
            <strong><?= e($path) ?></strong>
            <p><?= e(implode(' · ', $detailParts)) ?></p>
        </article>
        <?php
    }
}

function audit_render_plain_path_list(array $paths, string $emptyText): void
{
    if ($paths === []) {
        ?>
        <div class="audit-empty"><?= e($emptyText) ?></div>
        <?php
        return;
    }

    foreach ($paths as $path) {
        ?>
        <article class="audit-item audit-warning">
            <strong><?= e($path) ?></strong>
            <p>Ky skedar nuk duhet të jetë në uploads. Lejohen vetëm imazhe dhe skedari mbrojtës .htaccess.</p>
        </article>
        <?php
    }
}
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Auditimi i menusë | <?= e(site_bar_name()) ?> Admin</title>
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

        .audit-note {
            margin-top: 14px;
            padding: 14px;
            border-radius: 16px;
            border: 1px solid rgba(243, 201, 109, .18);
            background: rgba(243, 201, 109, .07);
            color: var(--muted);
            line-height: 1.5;
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
            <h1 class="admin-title">Auditimi i menusë</h1>
            <p class="admin-muted">
                Kontroll diagnostik për produktet, kategoritë, imazhet dhe problemet që mund të ndikojnë te menuja publike.
            </p>

            <div class="audit-actions">
                <a class="btn btn-secondary" href="/tadeo-admin/products.php">Produktet</a>
                <a class="btn btn-secondary" href="/tadeo-admin/product-trash.php">Produktet në kosh</a>
                <a class="btn btn-secondary" href="/tadeo-admin/categories.php">Kategoritë</a>
                <a class="btn btn-secondary" href="/tadeo-admin/images.php">Imazhet</a>
                <a class="btn btn-secondary" href="/" target="_blank" rel="noopener">Hap menunë publike</a>
            </div>

            <div class="audit-note">
                Ky kontroll nuk ndryshon databazën dhe nuk fshin skedarë. Imazhet e produkteve duhet të jenë vertikale, me raport gjerësi/lartësi 0.55–0.82 dhe minimum 600×1000 px. Mbi 500 KB shfaqet paralajmërim dhe 800 KB është kufiri maksimal. Pozicionet e produkteve jashtë koshit kontrollohen që të jenë të vazhdueshme nga 1, pa boshllëqe ose përsëritje.
            </div>

            <section class="audit-summary">
                <article class="stat-card"><small>Produkte gjithsej</small><strong><?= e($totalProducts) ?></strong></article>
                <article class="stat-card"><small>Produkte jashtë koshit</small><strong><?= e($totalLiveProducts) ?></strong></article>
                <article class="stat-card"><small>Produkte në kosh</small><strong><?= e($trashedProducts) ?></strong></article>
                <article class="stat-card"><small>Kategori</small><strong><?= e($totalCategories) ?></strong></article>
                <article class="stat-card"><small>Gabime kritike</small><strong><?= e($totalCritical) ?></strong></article>
                <article class="stat-card"><small>Paralajmërime</small><strong><?= e($totalWarnings) ?></strong></article>
                <article class="stat-card"><small>Skedarë të databazës që mungojnë</small><strong><?= e($missingDbFiles) ?></strong></article>
                <article class="stat-card"><small>Skedarë të palidhur</small><strong><?= e(count($unusedImages)) ?></strong></article>
                <article class="stat-card"><small>Imazhe në kosh</small><strong><?= e(count($trashImages)) ?></strong></article>
                <article class="stat-card"><small>Skedarë të rrezikshëm</small><strong><?= e(count($dangerousUploadFiles)) ?></strong></article>
                <article class="stat-card"><small>Produkte aktive</small><strong><?= e($activeProducts) ?></strong></article>
                <article class="stat-card"><small>Kategori aktive</small><strong><?= e($activeCategories) ?></strong></article>
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

            <section class="audit-section">
                <h2>Skedarë në server që nuk përdoren</h2>
                <div class="audit-list">
                    <?php audit_render_path_list($unusedImages, 'Nuk u gjetën skedarë të palidhur në uploads/products ose uploads/categories.'); ?>
                </div>
            </section>

            <section class="audit-section">
                <h2>Imazhe në kosh</h2>
                <div class="audit-list">
                    <?php audit_render_path_list($trashImages, 'Nuk u gjetën imazhe në uploads/trash.'); ?>
                </div>
            </section>

            <section class="audit-section">
                <h2>Skedarë të rrezikshëm në uploads</h2>
                <div class="audit-list">
                    <?php audit_render_plain_path_list($dangerousUploadFiles, 'Nuk u gjetën skedarë të rrezikshëm në uploads.'); ?>
                </div>
            </section>

            <section class="audit-section">
                <h2>Produkte në kosh</h2>
                <div class="audit-list">
                    <?php audit_render_items($trashedProductItems, 'Nuk u gjetën produkte në kosh.'); ?>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
