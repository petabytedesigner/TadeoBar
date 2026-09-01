<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();

$categories = $pdo->query("
    SELECT id, name_sq, name_en
    FROM categories
    WHERE is_active = 1
    ORDER BY sort_order, id
")->fetchAll();

$nextNumber = (int)$pdo->query("SELECT COALESCE(MAX(menu_number), 0) + 1 FROM products")->fetchColumn();

$error = '';

$data = [
    'menu_number' => $nextNumber,
    'category_id' => $categories[0]['id'] ?? 1,
    'name_sq' => '',
    'name_en' => '',
    'price_all' => '',
    'sort_order' => $nextNumber,
    'is_active' => 1,
];

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
                $imagePath = handle_product_image_upload('image_file', $data['name_en'] !== '' ? $data['name_en'] : $data['name_sq']);

                $stmt = $pdo->prepare("
                    INSERT INTO products
                        (menu_number, category_id, name_sq, name_en, price_all, image_path, is_active, sort_order)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?)
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
                ]);

                redirect('/tadeo-admin/products.php?msg=Produkti u shtua');
            } catch (Throwable $e) {
                $error = 'Produkti nuk u shtua: ' . $e->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Shto Produkt | <?= e(site_bar_name()) ?> Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-admin-header-actions-2">
    <link rel="stylesheet" href="/assets/css/product-image-preview.css?v=20260901-2">
    <link rel="stylesheet" href="/assets/css/product-image-editor.css?v=20260901-1">
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'products'); ?>

        <main>
            <h1 class="admin-title">Shto produkt</h1>

            <?php if ($error !== ''): ?>
                <div class="error"><?= e($error) ?></div>
            <?php endif; ?>

            <form class="form-card" method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>

                <div class="form-grid">
                    <div>
                        <label>Numri i produktit</label>
                        <input name="menu_number" type="number" min="1" value="<?= e($data['menu_number']) ?>" required>
                    </div>

                    <div>
                        <label>Kategoria</label>
                        <select name="category_id" required>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= e($category['id']) ?>" <?= (int)$category['id'] === (int)$data['category_id'] ? 'selected' : '' ?>>
                                    <?= e($category['name_sq']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Emri shqip</label>
                        <input name="name_sq" value="<?= e($data['name_sq']) ?>" required>
                    </div>

                    <div>
                        <label>Emri anglisht</label>
                        <input name="name_en" value="<?= e($data['name_en']) ?>" required>
                    </div>

                    <div>
                        <label>Çmimi ALL</label>
                        <input name="price_all" type="number" min="1" value="<?= e($data['price_all']) ?>" required>
                    </div>

                    <div>
                        <label>Renditja</label>
                        <input name="sort_order" type="number" value="<?= e($data['sort_order']) ?>" required>
                    </div>

                    <div class="full" data-product-image-editor-root>
                        <label>Ngarko imazh për produktin</label>

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
                                AUTO përdor kontrollin dhe optimizimin aktual. EDITO hap editorin e plotë para upload-it për crop, zoom, rotate, flip, përmirësime, filtra, resize, shënime dhe watermark.
                            </div>
                        </div>

                        <input
                            name="image_file"
                            type="file"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                            data-product-image-input
                            data-preview-target="productImagePreview"
                        >
                        <div class="help-text">Imazhi final i produktit duhet të jetë portrait. Lejohen raporte nga 9:16 deri afërsisht 4:5, p.sh. 720×1280, 900×1350, 1080×1620 ose 1080×1920. Syno 150–350 KB; mbi 500 KB është i rëndë, ndërsa maksimumi final është 800 KB. Lejohen JPG, PNG ose WEBP deri 10 MB; serveri e ruan automatikisht si WEBP të optimizuar.</div>

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
                            <button type="button" class="btn btn-secondary" data-open-image-editor>Hap / rihap editorin</button>
                        </div>
                        <div class="product-image-editor-notice" data-editor-notice hidden></div>
                    </div>
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" name="is_active" <?= (int)$data['is_active'] === 1 ? 'checked' : '' ?>>
                    Aktiv
                </label>

                <button type="submit">Ruaj produktin</button>
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
