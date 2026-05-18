<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();

ensure_category_icon_image_column($pdo);

$error = '';

$data = [
    'slug' => '',
    'name_sq' => '',
    'name_en' => '',
    'icon' => '☕',
    'sort_order' => (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM categories")->fetchColumn(),
    'is_active' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Kontrolli i sigurisë dështoi. Rifresko faqen dhe provo përsëri.';
    } else {
        $data = [
            'slug' => strtolower(trim((string)($_POST['slug'] ?? ''))),
            'name_sq' => trim((string)($_POST['name_sq'] ?? '')),
            'name_en' => trim((string)($_POST['name_en'] ?? '')),
            'icon' => trim((string)($_POST['icon'] ?? '')),
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        if ($data['slug'] === '' || $data['name_sq'] === '' || $data['name_en'] === '' || $data['sort_order'] <= 0) {
            $error = 'Plotëso saktë të gjitha fushat e detyrueshme.';
        } elseif (!preg_match('/^[a-z0-9-]+$/', $data['slug'])) {
            $error = 'Slug duhet të ketë vetëm shkronja të vogla, numra dhe minus.';
        } else {
            try {
                $imagePath = handle_category_icon_upload(
                    'icon_image_file',
                    $data['name_en'] !== '' ? $data['name_en'] : $data['name_sq']
                );

                $stmt = $pdo->prepare("
                    INSERT INTO categories
                        (slug, name_sq, name_en, icon, icon_image_path, sort_order, is_active)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $data['slug'],
                    $data['name_sq'],
                    $data['name_en'],
                    $data['icon'],
                    $imagePath,
                    $data['sort_order'],
                    $data['is_active'],
                ]);

                redirect('/tadeo-admin/categories.php?msg=Kategoria u shtua');
            } catch (Throwable $e) {
                $error = 'Kategoria nuk u shtua: ' . $e->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Shto Kategori | Tadeo Bar Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-admin-header-actions-2">
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'categories'); ?>

        <main>
            <h1 class="admin-title">Shto kategori</h1>

            <?php if ($error !== ''): ?>
                <div class="error"><?= e($error) ?></div>
            <?php endif; ?>

            <form class="form-card" method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>

                <div class="form-grid">
                    <div>
                        <label>Emri shqip</label>
                        <input name="name_sq" value="<?= e($data['name_sq']) ?>" required>
                    </div>

                    <div>
                        <label>Emri anglisht</label>
                        <input name="name_en" value="<?= e($data['name_en']) ?>" required>
                    </div>

                    <div>
                        <label>Slug</label>
                        <input name="slug" value="<?= e($data['slug']) ?>" placeholder="cold-drinks" required>
                        <div class="help-text">Përdor vetëm shkronja të vogla, numra dhe minus.</div>
                    </div>

                    <div>
                        <label>Emoji fallback</label>
                        <input name="icon" value="<?= e($data['icon']) ?>">
                        <div class="help-text">Përdoret vetëm nëse nuk ka imazh kategorie.</div>
                    </div>

                    <div>
                        <label>Renditja</label>
                        <input name="sort_order" type="number" min="1" value="<?= e($data['sort_order']) ?>" required>
                    </div>

                    <div class="full">
                        <label>Ngarko imazh për kategorinë</label>
                        <input name="icon_image_file" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                        <div class="help-text">Lejohen JPG, PNG ose WEBP, maksimumi 2 MB. JPG/PNG konvertohen automatikisht në WEBP kur serveri e mbështet.</div>
                    </div>
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" name="is_active" <?= (int)$data['is_active'] === 1 ? 'checked' : '' ?>>
                    Aktive
                </label>

                <button type="submit">Ruaj kategorinë</button>
                <a class="btn btn-secondary" href="/tadeo-admin/categories.php">Anulo</a>
            </form>
        </main>
    </div>
</body>
</html>
