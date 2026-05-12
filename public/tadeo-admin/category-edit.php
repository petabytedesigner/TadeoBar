<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();

ensure_category_icon_image_column($pdo);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    redirect('/tadeo-admin/categories.php?msg=Kategori e pavlefshme');
}

$stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$category = $stmt->fetch();

if (!$category) {
    redirect('/tadeo-admin/categories.php?msg=Kategoria nuk u gjet');
}

$error = '';

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
                    $data['name_en'] !== '' ? $data['name_en'] : $data['name_sq'],
                    $category['icon_image_path'] ?? null
                );

                $stmt = $pdo->prepare("
                    UPDATE categories
                    SET
                        slug = ?,
                        name_sq = ?,
                        name_en = ?,
                        icon = ?,
                        icon_image_path = ?,
                        sort_order = ?,
                        is_active = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $data['slug'],
                    $data['name_sq'],
                    $data['name_en'],
                    $data['icon'],
                    $imagePath,
                    $data['sort_order'],
                    $data['is_active'],
                    $id,
                ]);

                redirect('/tadeo-admin/categories.php?msg=Kategoria u përditësua');
            } catch (Throwable $e) {
                $error = 'Kategoria nuk u përditësua: ' . $e->getMessage();
            }
        }
    }

    $category = array_merge($category, $data ?? []);
}
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Ndrysho Kategori | Tadeo Bar Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-analytics-1">
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'categories'); ?>

        <main>
            <h1 class="admin-title">Ndrysho kategori</h1>
            <p class="admin-muted"><?= e($category['name_sq']) ?> / <?= e($category['name_en']) ?></p>

            <?php if ($error !== ''): ?>
                <div class="error"><?= e($error) ?></div>
            <?php endif; ?>

            <form class="form-card" method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e($id) ?>">

                <div class="form-grid">
                    <div>
                        <label>Emri shqip</label>
                        <input name="name_sq" value="<?= e($category['name_sq']) ?>" required>
                    </div>

                    <div>
                        <label>Emri anglisht</label>
                        <input name="name_en" value="<?= e($category['name_en']) ?>" required>
                    </div>

                    <div>
                        <label>Slug</label>
                        <input name="slug" value="<?= e($category['slug']) ?>" required>
                    </div>

                    <div>
                        <label>Emoji fallback</label>
                        <input name="icon" value="<?= e($category['icon']) ?>">
                        <div class="help-text">Përdoret vetëm nëse nuk ka imazh kategorie.</div>
                    </div>

                    <div>
                        <label>Renditja</label>
                        <input name="sort_order" type="number" min="1" value="<?= e($category['sort_order']) ?>" required>
                    </div>

                    <div class="full">
                        <label>Ngarko / zëvendëso imazhin e kategorisë</label>
                        <input name="icon_image_file" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                        <div class="help-text">Nëse nuk zgjedh imazh të ri, imazhi aktual mbetet. Lejohen JPG, PNG ose WEBP, maksimumi 2 MB.</div>

                        <?php if (!empty($category['icon_image_path'])): ?>
                            <div class="current-image category-current-image">
                                <img src="/<?= e($category['icon_image_path']) ?>" alt="<?= e($category['name_sq']) ?>">
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" name="is_active" <?= (int)$category['is_active'] === 1 ? 'checked' : '' ?>>
                    Aktive
                </label>

                <button type="submit">Ruaj ndryshimet</button>
                <a class="btn btn-secondary" href="/tadeo-admin/categories.php">Anulo</a>
            </form>
        </main>
    </div>
</body>
</html>
