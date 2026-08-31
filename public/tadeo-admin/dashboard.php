<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/trash_cleanup.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();

$cleanupMessage = '';
$cleanupError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_cleanup') {
    if (!csrf_verify()) {
        $cleanupError = 'Kontrolli i sigurisë dështoi. Rifresko faqen dhe provo përsëri.';
    } else {
        try {
            $deletedProducts = trash_cleanup_products($pdo);
            $deletedImages = trash_cleanup_images($pdo);
            trash_cleanup_mark_run();

            $cleanupMessage = 'Cleanup u krye. Produkte të fshira përgjithmonë: '
                . $deletedProducts
                . '. Imazhe të fshira përgjithmonë: '
                . $deletedImages
                . '.';
        } catch (Throwable $e) {
            $cleanupError = 'Cleanup nuk u krye dot. Provo përsëri ose kontrollo serverin.';
        }
    }
}

$activeProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 1")->fetchColumn();
$hiddenProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 0")->fetchColumn();
$totalProducts = $activeProducts + $hiddenProducts;

$activeCategories = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE is_active = 1")->fetchColumn();
$hiddenCategories = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE is_active = 0")->fetchColumn();
$totalCategories = $activeCategories + $hiddenCategories;

function dashboard_visit_count_between(PDO $pdo, string $startDate, string $endDate): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE visit_date BETWEEN ? AND ?");
    $stmt->execute([$startDate, $endDate]);

    return (int)$stmt->fetchColumn();
}

$dashboardTimeZone = new DateTimeZone('Europe/Tirane');
$dashboardToday = new DateTimeImmutable('today', $dashboardTimeZone);

$todayDate = $dashboardToday->format('Y-m-d');
$yesterdayDate = $dashboardToday->modify('-1 day')->format('Y-m-d');
$last7StartDate = $dashboardToday->modify('-6 days')->format('Y-m-d');

$today = dashboard_visit_count_between($pdo, $todayDate, $todayDate);
$yesterday = dashboard_visit_count_between($pdo, $yesterdayDate, $yesterdayDate);
$last7 = dashboard_visit_count_between($pdo, $last7StartDate, $todayDate);
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Paneli | <?= e(site_bar_name()) ?> Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-admin-header-actions-2">
    <style>
        .dashboard-sections {
            display: grid;
            grid-template-columns: minmax(0, 1.05fr) minmax(280px, .95fr);
            gap: 18px;
            margin-top: 24px;
        }

        .dashboard-status-list {
            display: grid;
            gap: 12px;
            margin-top: 16px;
        }

        .dashboard-status-item {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 14px;
            border: 1px solid rgba(255, 255, 255, .1);
            border-radius: 16px;
            background: rgba(255, 255, 255, .04);
        }

        .dashboard-status-item span {
            color: var(--muted);
            font-weight: 700;
        }

        .dashboard-status-item strong {
            color: var(--gold-light);
            font-weight: 900;
        }

        .dashboard-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 16px;
        }

        .dashboard-actions .btn,
        .dashboard-actions button {
            width: 100%;
            min-height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .dashboard-cleanup-form {
            grid-column: 1 / -1;
            margin: 0;
        }

        .dashboard-cleanup-form button {
            cursor: pointer;
        }

        .dashboard-note,
        .dashboard-cleanup-result,
        .dashboard-cleanup-error {
            margin-top: 16px;
            padding: 14px;
            border-radius: 16px;
            line-height: 1.55;
        }

        .dashboard-note {
            background: rgba(243, 201, 109, .08);
            border: 1px solid rgba(243, 201, 109, .18);
            color: var(--muted);
        }

        .dashboard-cleanup-result {
            background: rgba(107, 196, 127, .10);
            border: 1px solid rgba(107, 196, 127, .32);
            color: #b7f5c5;
            font-weight: 800;
        }

        .dashboard-cleanup-error {
            background: rgba(214, 69, 69, .10);
            border: 1px solid rgba(214, 69, 69, .35);
            color: #ffb6b6;
            font-weight: 800;
        }

        @media (max-width: 860px) {
            .dashboard-sections {
                grid-template-columns: 1fr;
            }

            .dashboard-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'dashboard'); ?>

        <main>
            <h1 class="admin-title">Paneli</h1>
            <p class="admin-muted">Përmbledhje e menusë, kategorive dhe vizitorëve.</p>

            <?php if ($cleanupMessage !== ''): ?>
                <div class="dashboard-cleanup-result"><?= e($cleanupMessage) ?></div>
            <?php endif; ?>

            <?php if ($cleanupError !== ''): ?>
                <div class="dashboard-cleanup-error"><?= e($cleanupError) ?></div>
            <?php endif; ?>

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
                    <strong><?= e($activeCategories) ?></strong>
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

            <section class="dashboard-sections">
                <article class="panel">
                    <h2>Gjendja e menusë</h2>
                    <p class="admin-muted">
                        Këtu shikon shpejt nëse menuja, kategoritë dhe vizitorët janë në gjendje normale.
                    </p>

                    <div class="dashboard-status-list">
                        <div class="dashboard-status-item">
                            <span>Totali i produkteve</span>
                            <strong><?= e($totalProducts) ?></strong>
                        </div>

                        <div class="dashboard-status-item">
                            <span>Produkte të dukshme në menu</span>
                            <strong><?= e($activeProducts) ?></strong>
                        </div>

                        <div class="dashboard-status-item">
                            <span>Totali i kategorive</span>
                            <strong><?= e($totalCategories) ?></strong>
                        </div>

                        <div class="dashboard-status-item">
                            <span>Kategori të dukshme në menu</span>
                            <strong><?= e($activeCategories) ?></strong>
                        </div>
                    </div>

                    <div class="dashboard-note">
                        Ndryshimet që bën te produktet, kategoritë, çmimet dhe WiFi reflektohen në menunë publike pas rifreskimit të faqes.
                    </div>
                </article>

                <article class="panel">
                    <h2>Veprime të shpejta</h2>
                    <p class="admin-muted">
                        Hap menjëherë pjesët kryesore të administrimit.
                    </p>

                    <div class="dashboard-actions">
                        <a class="btn btn-secondary" href="/tadeo-admin/products.php">Produktet</a>
                        <a class="btn btn-secondary" href="/tadeo-admin/categories.php">Kategoritë</a>
                        <a class="btn btn-secondary" href="/tadeo-admin/images.php">Imazhet</a>
                        <a class="btn btn-secondary" href="/tadeo-admin/wifi.php">WiFi</a>
                        <a class="btn btn-secondary" href="/tadeo-admin/analytics.php">Analitika</a>
                        <a class="btn btn-secondary" href="/tadeo-admin/settings.php">Cilësimet</a>

                        <form class="dashboard-cleanup-form" method="post" onsubmit="return confirm('Cleanup do të fshijë përgjithmonë vetëm produkte dhe imazhe që kanë mbi 30 ditë në kosh. Të vazhdoj?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="run_cleanup">
                            <button class="btn btn-secondary" type="submit">Run cleanup now</button>
                        </form>
                    </div>

                    <div class="dashboard-note">
                        Cleanup manual përdor të njëjtin rregull 30-ditor si cleanup automatik. Produktet dhe imazhet më të reja në kosh nuk preken.
                    </div>
                </article>
            </section>
        </main>
    </div>
</body>
</html>
