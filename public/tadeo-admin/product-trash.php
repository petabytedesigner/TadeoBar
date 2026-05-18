<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();

function ensure_product_trash_column(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'deleted_at'");
    $exists = $stmt !== false && $stmt->fetch() !== false;

    if (!$exists) {
        $pdo->exec("ALTER TABLE products ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
    }
}

function purge_old_trashed_products(PDO $pdo): int
{
    $stmt = $pdo->prepare("
        DELETE FROM products
        WHERE deleted_at IS NOT NULL
          AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute();

    return $stmt->rowCount();
}

function product_trash_flash(string $msg): string
{
    return match ($msg) {
        'purged' => 'Produkti u fshi përgjithmonë.',
        'invalid' => 'Kërkesa nuk është e vlefshme.',
        'error' => 'Veprimi nuk u krye. Provo përsëri.',
        default => $msg,
    };
}

ensure_product_trash_column($pdo);
$autoPurged = purge_old_trashed_products($pdo);

$products = $pdo->query("
    SELECT
        p.id,
        p.menu_number,
        p.name_sq,
        p.name_en,
        p.price_all,
        p.image_path,
        p.deleted_at,
        GREATEST(0, 30 - TIMESTAMPDIFF(DAY, p.deleted_at, NOW())) AS days_left,
        c.name_sq AS category_name_sq,
        c.name_en AS category_name_en
    FROM products p
    INNER JOIN categories c ON c.id = p.category_id
    WHERE p.deleted_at IS NOT NULL
    ORDER BY p.deleted_at DESC, p.id DESC
")->fetchAll();

$trashCount = count($products);
$flash = product_trash_flash((string)($_GET['msg'] ?? ''));
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Produktet e fshira | Tadeo Bar Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/admin.css?v=20260512-admin-header-actions-2">
    <style>
        .trash-hero {
            border: 1px solid rgba(243, 201, 109, .18);
            border-radius: 24px;
            padding: 18px;
            background:
                radial-gradient(circle at 12% 0%, rgba(243, 201, 109, .12), transparent 34%),
                rgba(255,255,255,.035);
            margin: 18px 0;
        }

        .trash-hero-grid {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 16px;
            align-items: center;
        }

        .trash-hero strong {
            color: var(--gold-light);
        }

        .trash-count {
            min-width: 88px;
            min-height: 68px;
            border-radius: 20px;
            border: 1px solid rgba(243, 201, 109, .28);
            background: rgba(243, 201, 109, .10);
            color: var(--gold-light);
            display: grid;
            place-items: center;
            font-size: 30px;
            font-weight: 900;
        }

        .trash-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin: 16px 0 22px;
        }

        .trash-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 12px;
        }

        .trash-meta span {
            padding: 9px 10px;
            border-radius: 12px;
            background: rgba(255,255,255,.045);
            color: var(--muted);
            font-weight: 800;
            font-size: 13px;
        }

        .trash-card-note {
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(243, 201, 109, .18);
            background: rgba(243, 201, 109, .08);
            color: var(--gold-light);
            font-weight: 900;
            line-height: 1.4;
        }

        .product-actions form {
            margin: 0;
        }

        @media (max-width: 780px) {
            .trash-hero-grid,
            .trash-meta {
                grid-template-columns: 1fr;
            }

            .trash-count {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'products'); ?>

        <main>
            <h1 class="admin-title">Produktet e fshira</h1>

            <section class="trash-hero">
                <div class="trash-hero-grid">
                    <div>
                        <p class="admin-muted">
                            Produktet në kosh nuk shfaqen në menunë publike. Mund t’i rikthesh si të fshehura ose t’i fshish përgjithmonë.
                            Produktet që qëndrojnë këtu më shumë se <strong>30 ditë</strong> pastrohen automatikisht kur hapet kjo faqe.
                        </p>
                    </div>

                    <div class="trash-count" title="Produkte në kosh">
                        <?= e($trashCount) ?>
                    </div>
                </div>

                <?php if ($autoPurged > 0): ?>
                    <div class="msg"><?= e($autoPurged) ?> produkte të vjetra u fshinë automatikisht.</div>
                <?php endif; ?>

                <?php if ($flash !== ''): ?>
                    <div class="msg"><?= e($flash) ?></div>
                <?php endif; ?>
            </section>

            <div class="trash-actions">
                <a class="btn btn-secondary" style="width:auto" href="/tadeo-admin/products.php">← Kthehu te produktet</a>
            </div>

            <?php if ($products === []): ?>
                <div class="panel">
                    <p class="admin-muted">Nuk ka produkte të fshira.</p>
                </div>
            <?php else: ?>
                <section class="product-grid">
                    <?php foreach ($products as $product): ?>
                        <article class="product-admin-card">
                            <div class="product-admin-top">
                                <div class="product-number">#<?= e($product['menu_number']) ?></div>
                                <span class="badge badge-hidden">Në kosh</span>
                            </div>

                            <?php if (!empty($product['image_path'])): ?>
                                <div class="product-thumb">
                                    <img src="/<?= e($product['image_path']) ?>" alt="<?= e($product['name_sq']) ?>">
                                </div>
                            <?php endif; ?>

                            <h3><?= e($product['name_sq']) ?></h3>
                            <p><?= e($product['name_en']) ?></p>

                            <div class="admin-muted"><?= e($product['category_name_sq']) ?></div>
                            <div class="product-price"><?= e($product['price_all']) ?> ALL</div>

                            <div class="trash-meta">
                                <span>Fshirë më: <?= e($product['deleted_at']) ?></span>
                                <span>Ditë deri në fshirje: <?= e($product['days_left']) ?></span>
                            </div>

                            <div class="trash-card-note">
                                Rikthimi e kthen produktin si të fshehur, jo direkt aktiv.
                            </div>

                            <div class="product-actions">
                                <form method="post" action="/tadeo-admin/product-restore.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e($product['id']) ?>">
                                    <button type="submit" class="btn-secondary">Rikthe si të fshehur</button>
                                </form>

                                <form method="post" action="/tadeo-admin/product-purge.php" class="js-confirm-purge">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e($product['id']) ?>">
                                    <button type="submit" class="btn-danger">Fshi përgjithmonë</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <script>
        document.querySelectorAll('.js-confirm-purge').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!confirm('Je i sigurt? Ky produkt do të fshihet përgjithmonë dhe nuk rikthehet më.')) {
                    event.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
