<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

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
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Tadeo Bar Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <div class="admin-layout">
        <header class="admin-header">
            <div>
                <div class="admin-brand">Tadeo Bar</div>
                <div class="admin-muted">Logged in as <?= e($admin['username']) ?></div>
            </div>

            <nav class="admin-nav">
                <a class="active" href="/tadeo-admin/dashboard.php">Dashboard</a>
                <a href="#">Products</a>
                <a href="#">Categories</a>
                <a href="#">Images</a>
                <a href="#">WiFi</a>
                <a href="#">Analytics</a>
                <a href="#">Settings</a>
                <a href="/tadeo-admin/logout.php">Logout</a>
            </nav>
        </header>

        <main>
            <h1 class="admin-title">Dashboard</h1>
            <p class="admin-muted">Overview of menu and visitor activity.</p>

            <section class="grid">
                <article class="stat-card">
                    <small>Active products</small>
                    <strong><?= e($activeProducts) ?></strong>
                </article>

                <article class="stat-card">
                    <small>Hidden products</small>
                    <strong><?= e($hiddenProducts) ?></strong>
                </article>

                <article class="stat-card">
                    <small>Active categories</small>
                    <strong><?= e($categories) ?></strong>
                </article>

                <article class="stat-card">
                    <small>Visitors today</small>
                    <strong><?= e($today) ?></strong>
                </article>

                <article class="stat-card">
                    <small>Visitors yesterday</small>
                    <strong><?= e($yesterday) ?></strong>
                </article>

                <article class="stat-card">
                    <small>Visitors last 7 days</small>
                    <strong><?= e($last7) ?></strong>
                </article>
            </section>

            <section class="panel">
                <h2>Next modules</h2>
                <p class="admin-muted">
                    Products, Categories, Images, WiFi, Analytics and Settings will be connected step by step.
                </p>
            </section>
        </main>
    </div>
</body>
</html>
