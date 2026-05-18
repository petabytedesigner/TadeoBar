<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin_header.php';

$admin = require_admin();
$pdo = db();

function ensure_image_trash_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS image_trash (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            original_path VARCHAR(255) NOT NULL,
            trash_path VARCHAR(255) NOT NULL,
            owner_type VARCHAR(20) NULL,
            owner_id INT UNSIGNED NULL,
            menu_number INT UNSIGNED NULL,
            name_sq VARCHAR(180) NULL,
            name_en VARCHAR(180) NULL,
            deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY original_path_unique (original_path),
            UNIQUE KEY trash_path_unique (trash_path),
            KEY deleted_at_lookup (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function image_trash_flash(string $msg): string
{
    return match ($msg) {
        'restored' => 'Imazhi u rikthye me sukses.',
        'purged' => 'Imazhi u fshi përgjithmonë.',
        'restore_conflict' => 'Imazhi nuk u rikthye sepse ekziston një file tjetër me të njëjtin emër.',
        'invalid' => 'Kërkesa nuk është e vlefshme.',
        'csrf' => 'Kontrolli i sigurisë dështoi. Rifresko faqen dhe provo përsëri.',
        'error' => 'Veprimi nuk u krye. Provo përsëri.',
        default => $msg,
    };
}

ensure_image_trash_table($pdo);

$images = $pdo->query("
    SELECT
        *,
        GREATEST(0, 30 - TIMESTAMPDIFF(DAY, deleted_at, NOW())) AS days_left
    FROM image_trash
    ORDER BY deleted_at DESC, id DESC
")->fetchAll();

$trashCount = count($images);
$flash = image_trash_flash((string)($_GET['msg'] ?? ''));
?>
<!doctype html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>Koshi i imazheve | Tadeo Bar Admin</title>
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

        .media-card-image {
            width: 100%;
            height: 170px;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,.10);
            background: #050505;
            margin-bottom: 12px;
        }

        .media-card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .media-path,
        .trash-owner,
        .trash-meta {
            margin-top: 10px;
            padding: 10px;
            border-radius: 12px;
            background: rgba(255,255,255,.045);
            color: var(--muted);
            font-size: 12px;
            line-height: 1.4;
            word-break: break-all;
        }

        .trash-owner {
            border: 1px solid rgba(243, 201, 109, .18);
            background: rgba(243, 201, 109, .08);
            color: var(--gold-light);
            font-weight: 900;
        }

        .product-actions form {
            margin: 0;
        }

        @media (max-width: 780px) {
            .trash-hero-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php render_admin_header($admin, 'images'); ?>

        <main>
            <h1 class="admin-title">Koshi i imazheve</h1>

            <section class="trash-hero">
                <div class="trash-hero-grid">
                    <p class="admin-muted">
                        Imazhet në kosh nuk përdoren nga menuja. Mund t’i rikthesh ose t’i fshish përgjithmonë.
                        Pas 30 ditësh pastrohen automatikisht nga cleanup-i i faqes kryesore.
                    </p>

                    <div class="trash-count" title="Imazhe në kosh">
                        <?= e($trashCount) ?>
                    </div>
                </div>

                <?php if ($flash !== ''): ?>
                    <div class="msg"><?= e($flash) ?></div>
                <?php endif; ?>
            </section>

            <div class="trash-actions">
                <a class="btn btn-secondary" style="width:auto" href="/tadeo-admin/images.php">← Kthehu te imazhet</a>
            </div>

            <?php if ($images === []): ?>
                <div class="panel">
                    <p class="admin-muted">Koshi i imazheve është bosh.</p>
                </div>
            <?php else: ?>
                <section class="product-grid">
                    <?php foreach ($images as $image): ?>
                        <article class="product-admin-card">
                            <div class="product-admin-top">
                                <div class="product-number">File</div>
                                <span class="badge badge-hidden">Në kosh</span>
                            </div>

                            <div class="media-card-image">
                                <?php if (is_file(dirname(__DIR__) . '/' . $image['trash_path'])): ?>
                                    <img src="/<?= e($image['trash_path']) ?>" alt="Imazh në kosh">
                                <?php else: ?>
                                    <div class="admin-muted" style="padding:16px">File mungon në server.</div>
                                <?php endif; ?>
                            </div>

                            <h3>Imazh në kosh</h3>

                            <?php if (!empty($image['name_sq'])): ?>
                                <div class="trash-owner">
                                    Më parë:
                                    <?php if (($image['owner_type'] ?? '') === 'product'): ?>
                                        #<?= e($image['menu_number']) ?> —
                                    <?php endif; ?>
                                    <?= e($image['name_sq']) ?> / <?= e($image['name_en']) ?>
                                </div>
                            <?php endif; ?>

                            <div class="media-path">Origjinal: <?= e($image['original_path']) ?></div>
                            <div class="media-path">Në kosh: <?= e($image['trash_path']) ?></div>

                            <div class="trash-meta">
                                Fshirë më: <?= e($image['deleted_at']) ?><br>
                                Ditë deri në fshirje: <?= e($image['days_left']) ?>
                            </div>

                            <div class="product-actions">
                                <form method="post" action="/tadeo-admin/image-restore.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e($image['id']) ?>">
                                    <button type="submit" class="btn-secondary">Rikthe imazhin</button>
                                </form>

                                <form method="post" action="/tadeo-admin/image-purge.php" class="js-confirm-purge">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e($image['id']) ?>">
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
                if (!confirm('Je i sigurt? Ky imazh do të fshihet përgjithmonë dhe nuk rikthehet më.')) {
                    event.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
