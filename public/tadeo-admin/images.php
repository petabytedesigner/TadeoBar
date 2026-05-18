<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();

$error = '';
$msg = (string)($_GET['msg'] ?? '');

function table_column_exists(PDO $pdo, string $table, string $column): bool
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

function image_safe_relative_path(?string $relativePath): ?string
{
    $relativePath = trim((string)$relativePath);
    $relativePath = ltrim($relativePath, '/');

    if ($relativePath === '' || str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
        return null;
    }

    if (!str_starts_with($relativePath, 'uploads/products/') && !str_starts_with($relativePath, 'uploads/categories/')) {
        return null;
    }

    if (basename($relativePath) === '.htaccess') {
        return null;
    }

    return $relativePath;
}

function image_file_info(?string $relativePath): array
{
    $safePath = image_safe_relative_path($relativePath);

    if ($safePath === null) {
        return [
            'path' => '',
            'exists' => false,
            'size' => 0,
            'mtime' => 0,
        ];
    }

    $absolutePath = dirname(__DIR__) . '/' . $safePath;
    $exists = is_file($absolutePath);

    return [
        'path' => $safePath,
        'exists' => $exists,
        'size' => $exists ? (int)filesize($absolutePath) : 0,
        'mtime' => $exists ? (int)filemtime($absolutePath) : 0,
    ];
}

function human_file_size($bytes): string
{
    $bytes = (int)$bytes;

    if ($bytes <= 0) {
        return '—';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $value = (float)$bytes;

    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }

    return $i === 0 ? (string)$bytes . ' B' : number_format($value, 1) . ' ' . $units[$i];
}

function list_uploaded_images(string $folder): array
{
    $base = dirname(__DIR__) . '/uploads/' . $folder;
    $relativeBase = 'uploads/' . $folder;

    if (!is_dir($base)) {
        return [];
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
    $items = [];

    foreach (scandir($base) ?: [] as $file) {
        if ($file === '.' || $file === '..' || $file === '.htaccess') {
            continue;
        }

        $absolutePath = $base . '/' . $file;

        if (!is_file($absolutePath)) {
            continue;
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            continue;
        }

        $items[] = $relativeBase . '/' . $file;
    }

    sort($items);

    return $items;
}

function ensure_image_detach_history_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS image_detach_history (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            image_path VARCHAR(255) NOT NULL,
            owner_type VARCHAR(20) NOT NULL,
            owner_id INT UNSIGNED NOT NULL,
            menu_number INT UNSIGNED NULL,
            name_sq VARCHAR(180) NOT NULL,
            name_en VARCHAR(180) NOT NULL,
            detached_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY image_path_unique (image_path),
            KEY owner_lookup (owner_type, owner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function flash_message(string $msg): string
{
    return match ($msg) {
        'detached' => 'Imazhi u hoq nga produkti/kategoria. File-i mbeti në server.',
        'deleted' => 'Imazhi u fshi përgjithmonë.',
        'image_trashed' => 'Imazhi u çua në kosh.',
        'attached' => 'Imazhi u lidh me sukses.',
        'already_used' => 'Ky imazh është tashmë i lidhur me një produkt ose kategori.',
        'attach_failed' => 'Imazhi nuk u lidh dot. Kontrollo nëse produkti/kategoria ka tashmë imazh.',
        'delete_failed' => 'Lidhja u hoq, por file-i nuk u fshi dot nga serveri.',
        'csrf' => 'Kontrolli i sigurisë dështoi. Rifresko faqen dhe provo përsëri.',
        'invalid' => 'Kërkesa nuk është e vlefshme.',
        'error' => 'Veprimi nuk u krye. Provo përsëri.',
        default => '',
    };
}

$hasCategoryImageColumn = false;
$productImages = [];
$categoryImages = [];
$productsWithoutImages = [];
$categoriesWithoutImages = [];
$detachHistoryByPath = [];

try {
    ensure_image_detach_history_table($pdo);

    $hasCategoryImageColumn = table_column_exists($pdo, 'categories', 'icon_image_path');
    $hasProductDeletedAt = table_column_exists($pdo, 'products', 'deleted_at');
    $productDeletedWhere = $hasProductDeletedAt ? 'AND p.deleted_at IS NULL' : '';

    $productImages = $pdo->query(
        "SELECT
            p.id,
            p.menu_number,
            p.name_sq,
            p.name_en,
            p.image_path,
            c.name_sq AS category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.image_path IS NOT NULL AND p.image_path <> '' {$productDeletedWhere}
         ORDER BY p.menu_number, p.id"
    )->fetchAll();

    $productsWithoutImages = $pdo->query(
        "SELECT
            p.id,
            p.menu_number,
            p.name_sq,
            p.name_en,
            c.name_sq AS category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE (p.image_path IS NULL OR p.image_path = '') {$productDeletedWhere}
         ORDER BY p.menu_number, p.id"
    )->fetchAll();

    $detachHistory = $pdo->query("
        SELECT image_path, owner_type, owner_id, menu_number, name_sq, name_en, detached_at
        FROM image_detach_history
        ORDER BY detached_at DESC
    ")->fetchAll();

    foreach ($detachHistory as $historyItem) {
        $safePath = image_safe_relative_path($historyItem['image_path'] ?? null);
        if ($safePath !== null) {
            $detachHistoryByPath[$safePath] = $historyItem;
        }
    }

    if ($hasCategoryImageColumn) {
        $categoryImages = $pdo->query(
            "SELECT id, name_sq, name_en, icon, icon_image_path
             FROM categories
             WHERE icon_image_path IS NOT NULL AND icon_image_path <> ''
             ORDER BY sort_order, id"
        )->fetchAll();

        $categoriesWithoutImages = $pdo->query(
            "SELECT id, name_sq, name_en, icon
             FROM categories
             WHERE icon_image_path IS NULL OR icon_image_path = ''
             ORDER BY sort_order, id"
        )->fetchAll();
    } else {
        $categoriesWithoutImages = $pdo->query(
            "SELECT id, name_sq, name_en, icon
             FROM categories
             ORDER BY sort_order, id"
        )->fetchAll();
    }
} catch (Throwable $e) {
    $error = 'Imazhet nuk u ngarkuan. Kontrollo databazën ose strukturën e tabelave.';
}

$usedPaths = [];

foreach ($productImages as $image) {
    $safePath = image_safe_relative_path($image['image_path'] ?? null);
    if ($safePath !== null) {
        $usedPaths[$safePath] = true;
    }
}

foreach ($categoryImages as $image) {
    $safePath = image_safe_relative_path($image['icon_image_path'] ?? null);
    if ($safePath !== null) {
        $usedPaths[$safePath] = true;
    }
}

$uploadedPaths = array_merge(
    list_uploaded_images('products'),
    list_uploaded_images('categories')
);

$unusedImages = [];

foreach ($uploadedPaths as $path) {
    if (!isset($usedPaths[$path])) {
        $unusedImages[] = $path;
    }
}

$productCount = count($productImages);
$categoryCount = count($categoryImages);
$withoutProductImageCount = count($productsWithoutImages);
$withoutCategoryImageCount = count($categoriesWithoutImages);
$unusedCount = count($unusedImages);
$totalCount = $productCount + $categoryCount;
$flash = flash_message($msg);
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Imazhet | Tadeo Bar Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-admin-images-v2">
    <style>
        .media-tabs {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-top: 18px;
        }

        .media-section {
            margin-top: 22px;
        }

        .media-card-image {
            width: 100%;
            height: 145px;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,.1);
            background: #050505;
            margin-bottom: 12px;
            display: grid;
            place-items: center;
        }

        .media-card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .media-fallback {
            color: var(--gold-light);
            font-size: 42px;
            font-weight: 900;
        }

        .media-path {
            margin-top: 10px;
            padding: 10px;
            border-radius: 12px;
            background: rgba(255,255,255,.045);
            color: var(--muted);
            font-size: 12px;
            line-height: 1.4;
            word-break: break-all;
        }

        .media-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 10px;
        }

        .media-meta span {
            display: block;
            padding: 8px 10px;
            border-radius: 10px;
            background: rgba(255,255,255,.045);
            color: var(--muted);
            font-size: 12px;
        }

        .media-actions {
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }

        .media-actions form {
            margin: 0;
        }

        .media-actions .btn,
        .media-actions button {
            width: 100%;
        }

        .media-empty-preview {
            width: 100%;
            height: 120px;
            border-radius: 16px;
            border: 1px dashed rgba(243, 201, 109, .28);
            background:
                radial-gradient(circle at 30% 20%, rgba(243, 201, 109, .12), transparent 34%),
                rgba(255,255,255,.035);
            display: grid;
            place-items: center;
            color: var(--gold-light);
            font-weight: 900;
            margin-bottom: 12px;
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: grid;
            place-items: center;
            padding: 18px;
            background: rgba(0, 0, 0, .72);
            backdrop-filter: blur(4px);
        }

        .modal-card {
            width: min(520px, 100%);
            border: 1px solid rgba(243, 201, 109, .22);
            border-radius: 24px;
            background:
                radial-gradient(circle at 20% 0%, rgba(243, 201, 109, .12), transparent 34%),
                #111;
            box-shadow: 0 26px 90px rgba(0, 0, 0, .55);
            padding: 22px;
        }

        .modal-card h2 {
            margin-top: 0;
        }

        .modal-card p {
            color: var(--muted);
            line-height: 1.55;
        }

        .modal-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 18px;
        }




        /* MEDIA PREVIOUS OWNER START */
        .media-previous-owner {
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(243, 201, 109, .22);
            background: rgba(243, 201, 109, .08);
            color: var(--gold-light);
            font-size: 13px;
            line-height: 1.45;
            font-weight: 800;
        }

        .media-previous-owner strong {
            color: var(--text);
        }
        /* MEDIA PREVIOUS OWNER END */

        /* MEDIA ATTACH FIELD START */
        .media-attach-field {
            display: grid;
            gap: 7px;
        }

        .media-attach-field span {
            color: var(--muted);
            font-size: 12px;
            font-weight: 900;
        }

        .media-attach-field select {
            width: 100%;
            min-height: 42px;
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 12px;
            padding: 10px;
            color: var(--text);
            background: rgba(0,0,0,.30);
            outline: none;
        }
        /* MEDIA ATTACH FIELD END */

        /* Images manager compact missing-image sections */
        .media-collapsible {
            border: 1px solid rgba(255,255,255,.10);
            border-radius: 20px;
            background:
                radial-gradient(circle at 12% 0%, rgba(243, 201, 109, .08), transparent 32%),
                rgba(255,255,255,.035);
            overflow: hidden;
        }

        .media-collapsible summary {
            cursor: pointer;
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 16px 18px;
            color: var(--gold-light);
            font-weight: 900;
        }

        .media-collapsible summary::-webkit-details-marker {
            display: none;
        }

        .media-collapsible-title {
            display: grid;
            gap: 4px;
        }

        .media-collapsible-title span {
            color: var(--gold-light);
            font-size: 18px;
        }

        .media-collapsible-title small {
            color: var(--muted);
            font-weight: 700;
            line-height: 1.4;
        }

        .media-collapsible-count {
            min-width: 42px;
            min-height: 34px;
            padding: 8px 12px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(243, 201, 109, .28);
            background: rgba(243, 201, 109, .10);
            color: var(--gold-light);
            font-weight: 900;
        }

        .media-collapsible-body {
            padding: 16px;
            border-top: 1px solid rgba(255,255,255,.10);
        }


        /* IMAGES MANAGER FILTERS START */
        .media-manager-toolbar {
            border: 1px solid rgba(243, 201, 109, .18);
            border-radius: 22px;
            padding: 16px;
            background:
                radial-gradient(circle at 12% 0%, rgba(243, 201, 109, .10), transparent 34%),
                rgba(255,255,255,.035);
        }

        .media-toolbar-grid {
            display: grid;
            grid-template-columns: minmax(220px, 1.4fr) minmax(180px, .9fr) minmax(180px, .9fr) auto;
            gap: 12px;
            align-items: end;
        }

        .media-filter-field {
            display: grid;
            gap: 7px;
        }

        .media-filter-field span {
            color: var(--muted);
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .6px;
        }

        .media-filter-field input,
        .media-filter-field select {
            width: 100%;
            min-height: 44px;
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 14px;
            padding: 11px 12px;
            color: var(--text);
            background: rgba(0,0,0,.30);
            outline: none;
        }

        .media-filter-field input:focus,
        .media-filter-field select:focus {
            border-color: rgba(243, 201, 109, .44);
            box-shadow: 0 0 0 3px rgba(243, 201, 109, .10);
        }

        .media-filter-actions {
            display: grid;
            align-items: end;
        }

        .media-filter-summary {
            margin-top: 12px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
        }

        .media-card-hidden {
            display: none !important;
        }

        @media (max-width: 980px) {
            .media-toolbar-grid {
                grid-template-columns: 1fr;
            }
        }
        /* IMAGES MANAGER FILTERS END */

        @media (max-width: 780px) {
            .media-tabs,
            .modal-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'images'); ?>

        <main>
            <h1 class="admin-title">Imazhet</h1>
            <p class="admin-muted">
                Menaxho imazhet e produkteve dhe kategorive. Mund të shtosh, ndryshosh, heqësh lidhjen ose të fshish file-in përgjithmonë.
            </p>

            <?php if ($flash !== ''): ?>
                <div class="msg"><?= e($flash) ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="error"><?= e($error) ?></div>
            <?php endif; ?>

            <section class="grid">
                <article class="stat-card"><small>Imazhe produktesh</small><strong><?= e($productCount) ?></strong></article>
                <article class="stat-card"><small>Imazhe kategorish</small><strong><?= e($categoryCount) ?></strong></article>
                <article class="stat-card"><small>Produkte pa imazh</small><strong><?= e($withoutProductImageCount) ?></strong></article>
                <article class="stat-card"><small>Kategori pa imazh</small><strong><?= e($withoutCategoryImageCount) ?></strong></article>
                <article class="stat-card"><small>Imazhe të palidhura</small><strong><?= e($unusedCount) ?></strong></article>
                <article class="stat-card"><small>Në përdorim</small><strong><?= e($totalCount) ?></strong></article>
            </section>

            <section class="media-tabs">
                <a class="btn btn-secondary" href="/tadeo-admin/products.php">Menaxho produktet</a>
                <a class="btn btn-secondary" href="/tadeo-admin/categories.php">Menaxho kategoritë</a>
                <a class="btn btn-secondary" href="/tadeo-admin/image-trash.php">Koshi i imazheve</a>
            </section>

            <section class="media-section media-manager-toolbar" id="mediaManagerToolbar" aria-label="Filtrat e imazheve">
                <div class="media-toolbar-grid">
                    <label class="media-filter-field">
                        <span>Kërko</span>
                        <input type="search" id="imageSearch" placeholder="Produkt, kategori, emër file-i...">
                    </label>

                    <label class="media-filter-field">
                        <span>Statusi</span>
                        <select id="imageStatusFilter">
                            <option value="all">Të gjitha</option>
                            <option value="product-linked">Produkte me imazh</option>
                            <option value="product-missing">Produkte pa imazh</option>
                            <option value="category-linked">Kategori me imazh</option>
                            <option value="category-missing">Kategori pa imazh</option>
                            <option value="unused">Imazhe të palidhura</option>
                            <option value="missing-file">File mungon në server</option>
                            <option value="large-file">Mbi 500 KB</option>
                            <option value="non-webp">Jo WebP</option>
                        </select>
                    </label>

                    <label class="media-filter-field">
                        <span>Kategoria</span>
                        <select id="imageCategoryFilter">
                            <option value="all">Të gjitha kategoritë</option>
                        </select>
                    </label>

                    <div class="media-filter-actions">
                        <button class="btn btn-secondary" type="button" id="clearImageFilters">Pastro filtrat</button>
                    </div>
                </div>

                <div class="media-filter-summary" id="imageFilterSummary">Duke shfaqur të gjitha rezultatet.</div>
            </section>

            <section class="media-section">
                <h2>Imazhe produktesh</h2>
                <?php if ($productImages === []): ?>
                    <div class="panel"><p class="admin-muted">Ende nuk ka imazhe produktesh.</p></div>
                <?php else: ?>
                    <div class="product-grid">
                        <?php foreach ($productImages as $image): ?>
                            <?php $info = image_file_info((string)$image['image_path']); ?>
                            <article class="product-admin-card">
                                <div class="product-admin-top">
                                    <div class="product-number">#<?= e($image['menu_number']) ?></div>
                                    <span class="badge <?= $info['exists'] ? 'badge-active' : 'badge-hidden' ?>"><?= $info['exists'] ? 'OK' : 'Mungon' ?></span>
                                </div>

                                <div class="media-card-image">
                                    <?php if ($info['exists']): ?>
                                        <img src="/<?= e($info['path']) ?>" alt="<?= e($image['name_sq']) ?>">
                                    <?php else: ?>
                                        <div class="media-fallback">!</div>
                                    <?php endif; ?>
                                </div>

                                <h3><?= e($image['name_sq']) ?></h3>
                                <p><?= e($image['name_en']) ?></p>

                                <div class="media-path"><?= e($info['path']) ?></div>
                                <div class="media-meta">
                                    <span>Madhësia: <?= e(human_file_size($info['size'])) ?></span>
                                    <span>Kategoria: <?= e($image['category_name'] ?? '—') ?></span>
                                </div>

                                <div class="media-actions">
                                    <a class="btn btn-secondary" href="/tadeo-admin/product-edit.php?id=<?= e($image['id']) ?>">Ndrysho imazhin</a>

                                    <form method="post" action="/tadeo-admin/image-detach.php" class="js-confirm-action"
                                          data-title="Hiq imazhin nga produkti?"
                                          data-message="Produkti do të mbetet në menu, por pa këtë imazh. File-i nuk do të fshihet nga serveri."
                                          data-confirm-label="Hiq lidhjen">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="type" value="product">
                                        <input type="hidden" name="id" value="<?= e($image['id']) ?>">
                                        <button class="btn btn-secondary" type="submit">Hiq lidhjen</button>
                                    </form>

                                    <?php if ($info['path'] !== ''): ?>
                                        <form method="post" action="/tadeo-admin/image-delete.php" class="js-confirm-action"
                                              data-title="Ço imazhin në kosh?"
                                              data-message="Ky veprim e çon imazhin në kosh dhe heq lidhjen nga produkti ose kategoria. Mund të rikthehet nga koshi."
                                              data-confirm-label="Ço në kosh">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="path" value="<?= e($info['path']) ?>">
                                            <button class="btn btn-danger" type="submit">Ço në kosh</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="media-section">
                <h2>Imazhe kategorish</h2>
                <?php if ($categoryImages === []): ?>
                    <div class="panel"><p class="admin-muted">Ende nuk ka imazhe kategorish.</p></div>
                <?php else: ?>
                    <div class="product-grid">
                        <?php foreach ($categoryImages as $image): ?>
                            <?php $info = image_file_info((string)$image['icon_image_path']); ?>
                            <article class="product-admin-card">
                                <div class="product-admin-top">
                                    <div class="product-number"><?= e($image['icon'] ?: '•') ?></div>
                                    <span class="badge <?= $info['exists'] ? 'badge-active' : 'badge-hidden' ?>"><?= $info['exists'] ? 'OK' : 'Mungon' ?></span>
                                </div>

                                <div class="media-card-image">
                                    <?php if ($info['exists']): ?>
                                        <img src="/<?= e($info['path']) ?>" alt="<?= e($image['name_sq']) ?>">
                                    <?php else: ?>
                                        <div class="media-fallback"><?= e($image['icon'] ?: '!') ?></div>
                                    <?php endif; ?>
                                </div>

                                <h3><?= e($image['name_sq']) ?></h3>
                                <p><?= e($image['name_en']) ?></p>

                                <div class="media-path"><?= e($info['path']) ?></div>
                                <div class="media-meta">
                                    <span>Madhësia: <?= e(human_file_size($info['size'])) ?></span>
                                    <span>Tipi: Kategori</span>
                                </div>

                                <div class="media-actions">
                                    <a class="btn btn-secondary" href="/tadeo-admin/category-edit.php?id=<?= e($image['id']) ?>">Ndrysho imazhin</a>

                                    <form method="post" action="/tadeo-admin/image-detach.php" class="js-confirm-action"
                                          data-title="Hiq imazhin nga kategoria?"
                                          data-message="Kategoria do të mbetet në menu, por pa këtë imazh. File-i nuk do të fshihet nga serveri."
                                          data-confirm-label="Hiq lidhjen">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="type" value="category">
                                        <input type="hidden" name="id" value="<?= e($image['id']) ?>">
                                        <button class="btn btn-secondary" type="submit">Hiq lidhjen</button>
                                    </form>

                                    <?php if ($info['path'] !== ''): ?>
                                        <form method="post" action="/tadeo-admin/image-delete.php" class="js-confirm-action"
                                              data-title="Ço imazhin në kosh?"
                                              data-message="Ky veprim e çon imazhin në kosh dhe heq lidhjen nga produkti ose kategoria. Mund të rikthehet nga koshi."
                                              data-confirm-label="Ço në kosh">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="path" value="<?= e($info['path']) ?>">
                                            <button class="btn btn-danger" type="submit">Ço në kosh</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <details class="media-section media-collapsible">
                <summary>
                    <span class="media-collapsible-title">
                        <span>Produkte pa imazh</span>
                        <small>Hape vetëm kur do të shtosh ose kontrollosh imazhet që mungojnë.</small>
                    </span>
                    <strong class="media-collapsible-count"><?= e($withoutProductImageCount) ?></strong>
                </summary>
                <div class="media-collapsible-body">
                <?php if ($productsWithoutImages === []): ?>
                    <div class="panel"><p class="admin-muted">Nuk ka produkte aktive pa imazh.</p></div>
                <?php else: ?>
                    <div class="product-grid">
                        <?php foreach ($productsWithoutImages as $product): ?>
                            <article class="product-admin-card">
                                <div class="product-admin-top">
                                    <div class="product-number">#<?= e($product['menu_number']) ?></div>
                                    <span class="badge badge-hidden">Pa imazh</span>
                                </div>

                                <div class="media-empty-preview">Shto imazh</div>

                                <h3><?= e($product['name_sq']) ?></h3>
                                <p><?= e($product['name_en']) ?></p>

                                <div class="media-meta">
                                    <span>Kategoria: <?= e($product['category_name'] ?? '—') ?></span>
                                    <span>Tipi: Produkt</span>
                                </div>

                                <div class="media-actions">
                                    <a class="btn btn-secondary" href="/tadeo-admin/product-edit.php?id=<?= e($product['id']) ?>">Shto imazh</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                </div>
            </details>
            <details class="media-section media-collapsible">
                <summary>
                    <span class="media-collapsible-title">
                        <span>Kategori pa imazh</span>
                        <small>Hape vetëm kur do të shtosh ose kontrollosh imazhet e kategorive që mungojnë.</small>
                    </span>
                    <strong class="media-collapsible-count"><?= e($withoutCategoryImageCount) ?></strong>
                </summary>
                <div class="media-collapsible-body">
                <?php if ($categoriesWithoutImages === []): ?>
                    <div class="panel"><p class="admin-muted">Të gjitha kategoritë kanë imazh.</p></div>
                <?php else: ?>
                    <div class="product-grid">
                        <?php foreach ($categoriesWithoutImages as $category): ?>
                            <article class="product-admin-card">
                                <div class="product-admin-top">
                                    <div class="product-number"><?= e($category['icon'] ?: '•') ?></div>
                                    <span class="badge badge-hidden">Pa imazh</span>
                                </div>

                                <div class="media-empty-preview">Shto imazh</div>

                                <h3><?= e($category['name_sq']) ?></h3>
                                <p><?= e($category['name_en']) ?></p>

                                <div class="media-meta">
                                    <span>Tipi: Kategori</span>
                                    <span>Statusi: Pa imazh</span>
                                </div>

                                <div class="media-actions">
                                    <a class="btn btn-secondary" href="/tadeo-admin/category-edit.php?id=<?= e($category['id']) ?>">Shto imazh</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                </div>
            </details>
            <section class="media-section">
                <h2>Imazhe të palidhura</h2>
                <?php if ($unusedImages === []): ?>
                    <div class="panel"><p class="admin-muted">Nuk ka imazhe të palidhura për momentin.</p></div>
                <?php else: ?>
                    <div class="product-grid">
                        <?php foreach ($unusedImages as $path): ?>
                            <?php
                                $info = image_file_info($path);
                                $previousOwner = $detachHistoryByPath[$info['path']] ?? null;
                            ?>
                            <article class="product-admin-card">
                                <div class="product-admin-top">
                                    <div class="product-number">File</div>
                                    <span class="badge badge-hidden">I palidhur</span>
                                </div>

                                <div class="media-card-image">
                                    <?php if ($info['exists']): ?>
                                        <img src="/<?= e($info['path']) ?>" alt="Imazh i palidhur">
                                    <?php else: ?>
                                        <div class="media-fallback">!</div>
                                    <?php endif; ?>
                                </div>

                                <h3>Imazh i palidhur</h3>
                                <p>Nuk përdoret nga produkt/kategori.</p>

                                <?php if ($previousOwner): ?>
                                    <div class="media-previous-owner">
                                        <strong>Më parë:</strong>
                                        <?php if (($previousOwner['owner_type'] ?? '') === 'product'): ?>
                                            #<?= e($previousOwner['menu_number']) ?> —
                                        <?php endif; ?>
                                        <?= e($previousOwner['name_sq']) ?> / <?= e($previousOwner['name_en']) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="media-path"><?= e($info['path']) ?></div>
                                <div class="media-meta">
                                    <span>Madhësia: <?= e(human_file_size($info['size'])) ?></span>
                                    <span>Tipi: File</span>
                                </div>

                                <div class="media-actions">
                                    <?php if (str_starts_with($info['path'], 'uploads/products/') && $productsWithoutImages !== []): ?>
                                        <form method="post" action="/tadeo-admin/image-attach.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="type" value="product">
                                            <input type="hidden" name="path" value="<?= e($info['path']) ?>">
                                            <label class="media-attach-field">
                                                <span>Lidh me produkt pa imazh</span>
                                                <select name="id" required>
                                                    <option value="">Zgjidh produktin</option>
                                                    <?php foreach ($productsWithoutImages as $product): ?>
                                                        <option value="<?= e($product['id']) ?>" <?= $previousOwner && ($previousOwner['owner_type'] ?? '') === 'product' && (int)$previousOwner['owner_id'] === (int)$product['id'] ? 'selected' : '' ?>>
                                                            #<?= e($product['menu_number']) ?> — <?= e($product['name_sq']) ?> / <?= e($product['name_en']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <button class="btn btn-secondary" type="submit">Lidh me produkt</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (str_starts_with($info['path'], 'uploads/categories/') && $categoriesWithoutImages !== []): ?>
                                        <form method="post" action="/tadeo-admin/image-attach.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="type" value="category">
                                            <input type="hidden" name="path" value="<?= e($info['path']) ?>">
                                            <label class="media-attach-field">
                                                <span>Lidh me kategori pa imazh</span>
                                                <select name="id" required>
                                                    <option value="">Zgjidh kategorinë</option>
                                                    <?php foreach ($categoriesWithoutImages as $category): ?>
                                                        <option value="<?= e($category['id']) ?>" <?= $previousOwner && ($previousOwner['owner_type'] ?? '') === 'category' && (int)$previousOwner['owner_id'] === (int)$category['id'] ? 'selected' : '' ?>>
                                                            <?= e($category['name_sq']) ?> / <?= e($category['name_en']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <button class="btn btn-secondary" type="submit">Lidh me kategori</button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="post" action="/tadeo-admin/image-delete.php" class="js-confirm-action"
                                          data-title="Ço file-in në kosh?"
                                          data-message="Ky file nuk është i lidhur me produkt ose kategori. Do të çohet në kosh dhe mund të rikthehet."
                                          data-confirm-label="Ço në kosh">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="path" value="<?= e($info['path']) ?>">
                                        <button class="btn btn-danger" type="submit">Ço në kosh</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <div class="modal-backdrop" id="confirmActionModal" hidden>
        <div class="modal-card">
            <h2 id="confirmActionTitle">Konfirmo veprimin</h2>
            <p id="confirmActionMessage">Ky veprim kërkon konfirmim.</p>

            <div class="modal-actions">
                <button class="btn btn-secondary" type="button" id="confirmActionCancel">Anulo</button>
                <button class="btn btn-danger" type="button" id="confirmActionSubmit">Konfirmo</button>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const modal = document.getElementById('confirmActionModal');
            const title = document.getElementById('confirmActionTitle');
            const message = document.getElementById('confirmActionMessage');
            const cancel = document.getElementById('confirmActionCancel');
            const submit = document.getElementById('confirmActionSubmit');
            let pendingForm = null;

            document.querySelectorAll('.js-confirm-action').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    event.preventDefault();

                    pendingForm = form;
                    title.textContent = form.dataset.title || 'Konfirmo veprimin';
                    message.textContent = form.dataset.message || 'Ky veprim kërkon konfirmim.';
                    submit.textContent = form.dataset.confirmLabel || 'Konfirmo';

                    modal.hidden = false;
                });
            });

            cancel.addEventListener('click', function () {
                pendingForm = null;
                modal.hidden = true;
            });

            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    pendingForm = null;
                    modal.hidden = true;
                }
            });

            submit.addEventListener('click', function () {
                if (pendingForm) {
                    pendingForm.submit();
                }
            });
        })();
    </script>

    <script>
        /* Images Manager v3 filters */
        (function () {
            const toolbar = document.getElementById('mediaManagerToolbar');
            const searchInput = document.getElementById('imageSearch');
            const statusFilter = document.getElementById('imageStatusFilter');
            const categoryFilter = document.getElementById('imageCategoryFilter');
            const clearButton = document.getElementById('clearImageFilters');
            const summary = document.getElementById('imageFilterSummary');

            if (!toolbar || !searchInput || !statusFilter || !categoryFilter || !clearButton || !summary) {
                return;
            }

            const cards = Array.from(document.querySelectorAll('.product-admin-card'));

            function normalize(value) {
                return (value || '')
                    .toString()
                    .toLowerCase()
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '');
            }

            function sectionTitle(card) {
                const section = card.closest('.media-section, details.media-collapsible');
                if (!section) {
                    return '';
                }

                const title = section.querySelector('h2, summary .media-collapsible-title span');
                return normalize(title ? title.textContent : '');
            }

            function cardType(card) {
                const title = sectionTitle(card);
                const badge = normalize(card.querySelector('.badge') ? card.querySelector('.badge').textContent : '');

                if (title.includes('imazhe produktesh')) {
                    return 'product-linked';
                }

                if (title.includes('imazhe kategorish')) {
                    return 'category-linked';
                }

                if (title.includes('produkte pa imazh')) {
                    return 'product-missing';
                }

                if (title.includes('kategori pa imazh')) {
                    return 'category-missing';
                }

                if (title.includes('imazhe te palidhura') || title.includes('imazhe të palidhura')) {
                    return 'unused';
                }

                if (badge.includes('mungon')) {
                    return 'missing-file';
                }

                return 'all';
            }

            function cardPath(card) {
                const path = card.querySelector('.media-path');
                return path ? path.textContent.trim() : '';
            }

            function cardCategory(card) {
                const spans = Array.from(card.querySelectorAll('.media-meta span'));
                const found = spans.find(function (span) {
                    return normalize(span.textContent).startsWith('kategoria:');
                });

                if (!found) {
                    return '';
                }

                return found.textContent.replace(/^Kategoria:\s*/i, '').trim();
            }

            function parseSizeBytes(card) {
                const text = card.textContent || '';
                const match = text.match(/Madhësia:\s*([0-9]+(?:[.,][0-9]+)?)\s*(B|KB|MB|GB)/i);

                if (!match) {
                    return 0;
                }

                const value = parseFloat(match[1].replace(',', '.'));
                const unit = match[2].toUpperCase();

                if (unit === 'GB') return value * 1024 * 1024 * 1024;
                if (unit === 'MB') return value * 1024 * 1024;
                if (unit === 'KB') return value * 1024;
                return value;
            }

            function isMissingFile(card) {
                const badge = normalize(card.querySelector('.badge') ? card.querySelector('.badge').textContent : '');
                return badge.includes('mungon');
            }

            function isNonWebp(card) {
                const path = normalize(cardPath(card));
                return path !== '' && !path.endsWith('.webp');
            }

            function populateCategories() {
                const values = new Set();

                cards.forEach(function (card) {
                    const category = cardCategory(card);

                    if (category && category !== '—') {
                        values.add(category);
                    }
                });

                Array.from(values).sort().forEach(function (category) {
                    const option = document.createElement('option');
                    option.value = category;
                    option.textContent = category;
                    categoryFilter.appendChild(option);
                });
            }

            function matchesStatus(card, status) {
                const type = cardType(card);

                if (status === 'all') {
                    return true;
                }

                if (status === 'missing-file') {
                    return isMissingFile(card);
                }

                if (status === 'large-file') {
                    return parseSizeBytes(card) > 500 * 1024;
                }

                if (status === 'non-webp') {
                    return isNonWebp(card);
                }

                return type === status;
            }

            function applyFilters() {
                const q = normalize(searchInput.value);
                const status = statusFilter.value;
                const category = categoryFilter.value;
                let visible = 0;

                cards.forEach(function (card) {
                    const text = normalize(card.textContent);
                    const cat = cardCategory(card);
                    const okSearch = q === '' || text.includes(q);
                    const okStatus = matchesStatus(card, status);
                    const okCategory = category === 'all' || cat === category;
                    const show = okSearch && okStatus && okCategory;

                    card.classList.toggle('media-card-hidden', !show);

                    if (show) {
                        visible++;
                    }
                });

                summary.textContent = 'Duke shfaqur ' + visible + ' nga ' + cards.length + ' rezultate.';
            }

            populateCategories();

            [searchInput, statusFilter, categoryFilter].forEach(function (el) {
                el.addEventListener('input', applyFilters);
                el.addEventListener('change', applyFilters);
            });

            clearButton.addEventListener('click', function () {
                searchInput.value = '';
                statusFilter.value = 'all';
                categoryFilter.value = 'all';
                applyFilters();
                searchInput.focus();
            });

            applyFilters();
        })();
    </script>

</body>
</html>
