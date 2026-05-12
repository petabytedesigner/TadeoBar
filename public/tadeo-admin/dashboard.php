<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();

$activeProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 1")->fetchColumn();
$hiddenProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 0")->fetchColumn();
$categories = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE is_active = 1")->fetchColumn();

$today = (int)$pdo->query("SELECT COUNT(*) FROM visits WHERE visit_date = CURDATE()")->fetchColumn();
$yesterday = (int)$pdo->query("SELECT COUNT(*) FROM visits WHERE visit_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetchColumn();
$last7 = (int)$pdo->query("SELECT COUNT(*) FROM visits WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)")->fetchColumn();
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Paneli | Tadeo Bar Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-delete-modal-2">
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'dashboard'); ?>

        <main>
            <h1 class="admin-title">Paneli</h1>
            <p class="admin-muted">Përmbledhje e menusë dhe vizitorëve.</p>

            <section class="grid">
                <article class="stat-card">
                    <small>Produkte aktive</small>
                    <strong><?= e($activeProducts) ?></strong>
                </article>

                <article class="stat-card">
                    <small>Produkte të fshehura</small>
                    <strong><?= e($hiddenProducts) ?></strong>
                </article>

                <article class="stat-card">
                    <small>Kategori aktive</small>
                    <strong><?= e($categories) ?></strong>
                </article>

                <article class="stat-card">
                    <small>Vizitorë sot</small>
                    <strong><?= e($today) ?></strong>
                </article>

                <article class="stat-card">
                    <small>Vizitorë dje</small>
                    <strong><?= e($yesterday) ?></strong>
                </article>

                <article class="stat-card">
                    <small>Vizitorë 7 ditët e fundit</small>
                    <strong><?= e($last7) ?></strong>
                </article>
            </section>

            <section class="panel">
                <h2>Hapat e radhës</h2>
                <p class="admin-muted">
                    Produktet janë lidhur me databazën. Më pas vazhdojmë me kategoritë, imazhet, WiFi, analitikën dhe cilësimet.
                </p>
            </section>
        </main>
    </div>
</body>
</html>
