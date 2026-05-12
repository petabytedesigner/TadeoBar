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

function flash_message(string $msg): string
{
    return match ($msg) {
        'detached' => 'Imazhi u hoq nga produkti/kategoria.',
        'deleted' => 'Imazhi u fshi përgjithmonë.',
        'csrf' => 'Kontrolli i sigurisë dështoi. Rifresko faqen dhe provo përsëri.',
        'invalid' => 'Kërkesa nuk është e vlefshme.',
        'error' => 'Veprimi nuk u krye. Provo përsëri.',
        default => '',
    };
}

$productImages = [];
$categoryImages = [];

try {
    $productImages = $pdo->query(
        "SELECT id, menu_number, name_sq, name_en, image_path
         FROM products
         WHERE image_path IS NOT NULL AND image_path <> ''
         ORDER BY menu_number, id"
    )->fetchAll();

    if (table_column_exists($pdo, 'categories', 'icon_image_path')) {
        $categoryImages = $pdo->query(
            "SELECT id, name_sq, name_en, icon, icon_image_path
             FROM categories
             WHERE icon_image_path IS NOT NULL AND icon_image_path <> ''
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
            grid-template-columns: repeat(2, minmax(0, 1fr));
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

        .media-warning {
            margin-top: 10px;
            color: #ffb6b6;
            font-size: 12px;
            line-height: 1.45;
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
                Menaxho imazhet e produkteve dhe kategorive. Mund të heqësh lidhjen nga menuja ose të fshish file-in përgjithmonë.
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
                <article class="stat-card"><small>Imazhe të palidhura</small><strong><?= e($unusedCount) ?></strong></article>
                <article class="stat-card"><small>Në përdorim</small><strong><?= e($totalCount) ?></strong></article>
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
                                    <span>Tipi: Produkt</span>
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
                                              data-title="Fshi imazhin përgjithmonë?"
                                              data-message="Ky veprim fshin file-in nga serveri dhe heq lidhjen nga çdo produkt ose kategori që e përdor. Nuk kthehet pas."
                                              data-confirm-label="Fshi përgjithmonë">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="path" value="<?= e($info['path']) ?>">
                                            <button class="btn btn-danger" type="submit">Fshi përgjithmonë</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
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
                                              data-title="Fshi imazhin përgjithmonë?"
                                              data-message="Ky veprim fshin file-in nga serveri dhe heq lidhjen nga çdo produkt ose kategori që e përdor. Nuk kthehet pas."
                                              data-confirm-label="Fshi përgjithmonë">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="path" value="<?= e($info['path']) ?>">
                                            <button class="btn btn-danger" type="submit">Fshi përgjithmonë</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="media-section">
                <h2>Imazhe të palidhura</h2>
                <?php if ($unusedImages === []): ?>
                    <div class="panel"><p class="admin-muted">Nuk ka imazhe të palidhura për momentin.</p></div>
                <?php else: ?>
                    <div class="product-grid">
                        <?php foreach ($unusedImages as $path): ?>
                            <?php $info = image_file_info($path); ?>
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

                                <div class="media-path"><?= e($info['path']) ?></div>
                                <div class="media-meta">
                                    <span>Madhësia: <?= e(human_file_size($info['size'])) ?></span>
                                    <span>Tipi: File</span>
                                </div>

                                <div class="media-actions">
                                    <form method="post" action="/tadeo-admin/image-delete.php" class="js-confirm-action"
                                          data-title="Fshi file-in përgjithmonë?"
                                          data-message="Ky file nuk është i lidhur me produkt ose kategori. Fshirja nuk kthehet pas."
                                          data-confirm-label="Fshi përgjithmonë">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="path" value="<?= e($info['path']) ?>">
                                        <button class="btn btn-danger" type="submit">Fshi përgjithmonë</button>
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
</body>
</html>
