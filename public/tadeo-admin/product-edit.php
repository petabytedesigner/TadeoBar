<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    redirect('/tadeo-admin/products.php?msg=Produkt i pavlefshëm');
}

$categories = $pdo->query("
    SELECT id, name_sq, name_en
    FROM categories
    WHERE is_active = 1
    ORDER BY sort_order, id
")->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    redirect('/tadeo-admin/products.php?msg=Produkti nuk u gjet');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Kontrolli i sigurisë dështoi. Rifresko faqen dhe provo përsëri.';
    } else {
        $data = [
            'menu_number' => (int)($_POST['menu_number'] ?? 0),
            'category_id' => (int)($_POST['category_id'] ?? 0),
            'name_sq' => trim((string)($_POST['name_sq'] ?? '')),
            'name_en' => trim((string)($_POST['name_en'] ?? '')),
            'price_all' => (int)($_POST['price_all'] ?? 0),
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        if ($data['menu_number'] <= 0 || $data['category_id'] <= 0 || $data['name_sq'] === '' || $data['name_en'] === '' || $data['price_all'] <= 0) {
            $error = 'Plotëso saktë të gjitha fushat e detyrueshme.';
        } else {
            try {
                $uploadPlan = prepare_product_image_upload(
                    'image_file',
                    $data['name_en'] !== '' ? $data['name_en'] : $data['name_sq'],
                    $product['image_path'] ?? null
                );

                run_prepared_image_upload_transaction(
                    $pdo,
                    $uploadPlan,
                    function (?string $imagePath) use ($pdo, $data, $id): void {
                        $stmt = $pdo->prepare("
                            UPDATE products
                            SET
                                menu_number = ?,
                                category_id = ?,
                                name_sq = ?,
                                name_en = ?,
                                price_all = ?,
                                image_path = ?,
                                is_active = ?,
                                sort_order = ?
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $data['menu_number'],
                            $data['category_id'],
                            $data['name_sq'],
                            $data['name_en'],
                            $data['price_all'],
                            $imagePath,
                            $data['is_active'],
                            $data['sort_order'],
                            $id,
                        ]);
                    }
                );

                redirect('/tadeo-admin/products.php?msg=Produkti u përditësua');
            } catch (Throwable $e) {
                $error = 'Produkti nuk u përditësua: ' . $e->getMessage();
            }
        }
    }

    $product = array_merge($product, $data ?? []);
}

$currentImageUrl = !empty($product['image_path']) ? '/' . ltrim((string)$product['image_path'], '/') : '';
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Ndrysho Produkt | <?= e(site_bar_name()) ?> Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-admin-header-actions-2">
    <link rel="stylesheet" href="/assets/css/product-image-preview.css?v=20260901-2">
    <link rel="stylesheet" href="/assets/css/product-image-editor.css?v=20260901-1">
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'products'); ?>

        <main>
            <h1 class="admin-title">Ndrysho produkt</h1>
            <p class="admin-muted"><?= e($product['name_sq']) ?> / <?= e($product['name_en']) ?></p>

            <?php if ($error !== ''): ?>
                <div class="error"><?= e($error) ?></div>
            <?php endif; ?>

            <form class="form-card" method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e($id) ?>">

                <div class="form-grid">
                    <div>
                        <label>Numri i produktit</label>
                        <input name="menu_number" type="number" min="1" value="<?= e($product['menu_number']) ?>" required>
                    </div>

                    <div>
                        <label>Kategoria</label>
                        <select name="category_id" required>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= e($category['id']) ?>" <?= (int)$category['id'] === (int)$product['category_id'] ? 'selected' : '' ?>>
                                    <?= e($category['name_sq']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Emri shqip</label>
                        <input name="name_sq" value="<?= e($product['name_sq']) ?>" required>
                    </div>

                    <div>
                        <label>Emri anglisht</label>
                        <input name="name_en" value="<?= e($product['name_en']) ?>" required>
                    </div>

                    <div>
                        <label>Çmimi ALL</label>
                        <input name="price_all" type="number" min="1" value="<?= e($product['price_all']) ?>" required>
                    </div>

                    <div>
                        <label>Renditja</label>
                        <input name="sort_order" type="number" value="<?= e($product['sort_order']) ?>" required>
                    </div>

                    <div
                        class="full"
                        data-product-image-editor-root
                        data-existing-image="<?= e($currentImageUrl) ?>"
                    >
                        <label>Ngarko / zëvendëso imazhin</label>

                        <div class="product-image-mode">
                            <span class="product-image-mode-title">Mënyra e imazhit</span>
                            <div class="product-image-mode-options">
                                <label class="product-image-mode-option">
                                    <input type="radio" name="image_mode_ui" value="auto" data-image-mode checked>
                                    <span>AUTO</span>
                                </label>
                                <label class="product-image-mode-option">
                                    <input type="radio" name="image_mode_ui" value="edit" data-image-mode>
                                    <span>EDITO</span>
                                </label>
                            </div>
                            <div class="help-text">
                                AUTO përdor kontrollin dhe optimizimin aktual. EDITO mund të përpunojë një imazh të ri ose imazhin aktual me crop, zoom, rotate, flip, përmirësime, filtra, resize, shënime dhe watermark.
                            </div>
                        </div>

                        <input
                            name="image_file"
                            type="file"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                            data-product-image-input
                            data-preview-target="productImagePreview"
                        >
                        <div class="help-text">Nëse nuk zgjedh imazh të ri, imazhi aktual mbetet i pandryshuar. Imazhi final duhet të jetë portrait me raport W/H 0.55–0.82 dhe të paktën 600×1000 px. Serveri e ruan automatikisht si WEBP të optimizuar.</div>

                        <div class="product-image-preview" id="productImagePreview" aria-live="polite" hidden>
                            <div class="product-image-preview-grid">
                                <div class="product-image-preview-media">
                                    <img data-preview-image alt="Preview i imazhit">
                                </div>
                                <div class="product-image-preview-details">
                                    <div class="product-image-preview-status is-loading" data-preview-status>Po kontrollohet imazhi…</div>
                                    <button type="button" class="btn btn-secondary product-image-edit-now" data-edit-now hidden>Editoje tani</button>
                                    <dl class="product-image-preview-meta">
                                        <div><dt>File</dt><dd data-preview-name>—</dd></div>
                                        <div><dt>Format</dt><dd data-preview-type>—</dd></div>
                                        <div><dt>Dimensione</dt><dd data-preview-dimensions>—</dd></div>
                                        <div><dt>Ratio W/H</dt><dd data-preview-ratio>—</dd></div>
                                        <div><dt>Madhësia burim</dt><dd data-preview-size>—</dd></div>
                                    </dl>
                                    <p class="product-image-preview-note">Preview kontrollon file-in që do të dërgohet. Serveri bën optimizimin final WEBP dhe validimin përfundimtar gjatë ruajtjes.</p>
                                </div>
                            </div>
                        </div>

                        <div class="product-image-editor-actions" data-editor-actions hidden>
                            <button type="button" class="btn btn-secondary" data-open-image-editor>
                                <?= $currentImageUrl !== '' ? 'Hap editorin / edito imazhin aktual' : 'Hap / rihap editorin' ?>
                            </button>
                        </div>
                        <div class="product-image-editor-notice" data-editor-notice hidden></div>

                        <?php if ($currentImageUrl !== ''): ?>
                            <div class="current-image">
                                <img src="<?= e($currentImageUrl) ?>" alt="<?= e($product['name_sq']) ?>">
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" name="is_active" <?= (int)$product['is_active'] === 1 ? 'checked' : '' ?>>
                    Aktiv
                </label>

                <button type="submit">Ruaj ndryshimet</button>
                <a class="btn btn-secondary" href="/tadeo-admin/products.php">Anulo</a>
            </form>
        </main>
    </div>

    <div class="product-image-editor-shell" data-product-image-editor-shell hidden>
        <div class="product-image-editor-shell-inner">
            <div class="product-image-editor-warning" data-product-image-editor-warning hidden></div>
            <div class="product-image-editor-container" data-product-image-editor-container></div>
        </div>
    </div>

    <script src="/assets/vendor/filerobot-image-editor/filerobot-image-editor.min.js?v=4.9.1" defer></script>
    <script src="/assets/js/product-image-preview.js?v=20260901-2" defer></script>
    <script src="/assets/js/product-image-editor.js?v=20260901-1" defer></script>
</body>
</html>
