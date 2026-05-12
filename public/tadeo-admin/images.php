<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();
$error = '';

function table_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(""
        . "SELECT COUNT(*) "
        . "FROM INFORMATION_SCHEMA.COLUMNS "
        . "WHERE TABLE_SCHEMA = DATABASE() "
        . "AND TABLE_NAME = ? "
        . "AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function image_file_info(string $relativePath): array
{
    $absolutePath = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    $exists = is_file($absolutePath);

    return [
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

$productImages = [];
$categoryImages = [];

try {
    $productImages = $pdo->query("
        SELECT id, menu_number, name_sq, name_en, image_path
        FROM products
        WHERE image_path IS NOT NULL AND image_path <> ''
        ORDER BY menu_number, id
    ")->fetchAll();

    if (table_column_exists($pdo, 'categories', 'icon_image_path')) {
        $categoryImages = $pdo->query("
            SELECT id, name_sq, name_en, icon, icon_image_path
            FROM categories
            WHERE icon_image_path IS NOT NULL AND icon_image_path <> ''
            ORDER BY sort_order, id
        ")->fetchAll();
    }
} catch (Throwable $e) {
    $error = 'Imazhet nuk u ngarkuan. Kontrollo databazën ose strukturën e tabelave.';
}

$productCount = count($productImages);
$categoryCount = count($categoryImages);
$totalCount = $productCount + $categoryCount;
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Imazhet | Tadeo Bar Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-admin-header-actions-2">
    <style>
        .media-tabs { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-top: 18px; }
        .media-section { margin-top: 18px; }
        .media-card-image { width: 100%; height: 145px; border-radius: 16px; overflow: hidden; border: 1px solid rgba(255,255,255,.1); background: #050505; margin-bottom: 12px; display: grid; place-items: center; }
        .media-card-image img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .media-fallback { color: var(--gold-light); font-size: 42px; font-weight: 900; }
        .media-path { margin-top: 10px; padding: 10px; border-radius: 12px; background: rgba(255,255,255,.045); color: var(--muted); font-size: 12px; line-height: 1.4; word-break: break-all; }
        .media-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
        .media-meta span { display: block; padding: 8px 10px; border-radius: 10px; background: rgba(255,255,255,.045); color: var(--muted); font-size: 12px; }
        @media (max-width: 780px) { .media-tabs { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'images'); ?>

        <main>
            <h1 class="admin-title">Imazhet</h1>
            <p class="admin-muted">
                Shiko imazhet e produkteve dhe kategorive. Ngarkimi i imazheve bëhet nga faqja Ndrysho produkt ose Ndrysho kategori.
            </p>

            <?php if ($error !== ''): ?>
                <div class="error"><?= e($error) ?></div>
            <?php endif; ?>

            <section class="grid">
                <article class="stat-card"><small>Imazhe produktesh</small><strong><?= e($productCount) ?></strong></article>
                <article class="stat-card"><small>Imazhe kategorish</small><strong><?= e($categoryCount) ?></strong></article>
                <article class="stat-card"><small>Totali</small><strong><?= e($totalCount) ?></strong></article>
            </section>

            <section class="media-tabs">
                <a class="btn btn-secondary" href="/tadeo-admin/products.php">Menaxho produktet</a>
                <a class="btn btn-secondary" href="/tadeo-admin/categories.php">Menaxho kategoritë</a>
            </section>

            <section class="media-section">
                <h2>Produktet</h2>
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
                                    <?php if ($info['exists']): ?><img src="/<?= e($image['image_path']) ?>" alt="<?= e($image['name_sq']) ?>"><?php else: ?><div class="media-fallback">!</div><?php endif; ?>
                                </div>
                                <h3><?= e($image['name_sq']) ?></h3>
                                <p><?= e($image['name_en']) ?></p>
                                <div class="media-path"><?= e($image['image_path']) ?></div>
                                <div class="media-meta"><span>Madhësia: <?= e(human_file_size($info['size'])) ?></span><span>Tipi: Produkt</span></div>
                                <p><a class="btn btn-secondary" href="/tadeo-admin/product-edit.php?id=<?= e($image['id']) ?>">Ndrysho imazhin</a></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="media-section">
                <h2>Kategoritë</h2>
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
                                    <?php if ($info['exists']): ?><img src="/<?= e($image['icon_image_path']) ?>" alt="<?= e($image['name_sq']) ?>"><?php else: ?><div class="media-fallback"><?= e($image['icon'] ?: '!') ?></div><?php endif; ?>
                                </div>
                                <h3><?= e($image['name_sq']) ?></h3>
                                <p><?= e($image['name_en']) ?></p>
                                <div class="media-path"><?= e($image['icon_image_path']) ?></div>
                                <div class="media-meta"><span>Madhësia: <?= e(human_file_size($info['size'])) ?></span><span>Tipi: Kategori</span></div>
                                <p><a class="btn btn-secondary" href="/tadeo-admin/category-edit.php?id=<?= e($image['id']) ?>">Ndrysho imazhin</a></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
