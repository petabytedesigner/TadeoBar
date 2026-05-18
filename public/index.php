<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/trash_cleanup.php';

function public_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string)$value;
}

function public_table_column_exists(PDO $pdo, string $table, string $column): bool
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

function public_safe_image_src(?string $relativePath): ?string
{
    $path = trim((string)$relativePath);
    $path = ltrim($path, '/');

    if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
        return null;
    }

    if (!str_starts_with($path, 'uploads/products/') && !str_starts_with($path, 'uploads/categories/')) {
        return null;
    }

    $absolutePath = __DIR__ . '/' . $path;

    if (!is_file($absolutePath)) {
        return null;
    }

    return '/' . $path;
}

function public_slug_icon_key(string $slug, string $nameSq, string $nameEn): string
{
    $value = strtolower($slug . ' ' . $nameSq . ' ' . $nameEn);

    if (str_contains($value, 'beer') || str_contains($value, 'birr')) {
        return 'beer';
    }

    if (str_contains($value, 'coffee') || str_contains($value, 'kafe')) {
        return 'coffee';
    }

    if (str_contains($value, 'hot') || str_contains($value, 'ngroht')) {
        return 'hot';
    }

    if (str_contains($value, 'cold') || str_contains($value, 'ftoht') || str_contains($value, 'ice')) {
        return 'cold';
    }

    if (str_contains($value, 'spirit') || str_contains($value, 'alkool') || str_contains($value, 'liquor')) {
        return 'spirits';
    }

    return 'drink';
}

function public_svg_icon(string $key): string
{
    $icons = [
        'coffee' => '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M18 25h25v10a12 12 0 0 1-12 12h-1a12 12 0 0 1-12-12V25Z"/><path d="M43 29h4a6 6 0 0 1 0 12h-5"/><path d="M20 52h24"/><path d="M24 12c-3 4 3 5 0 9"/><path d="M33 12c-3 4 3 5 0 9"/></svg>',
        'hot' => '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M32 55c10-7 15-15 12-25-2-7-7-11-10-18-1 9-9 13-12 21-3 9 1 17 10 22Z"/><path d="M32 55c-2-6 5-10 2-17-4 4-9 8-2 17Z"/></svg>',
        'cold' => '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M20 18h24l-4 36H24L20 18Z"/><path d="M18 18h28"/><path d="M25 29h14"/><path d="M28 10l4 8 4-8"/><path d="M16 36l8-2"/><path d="M48 36l-8-2"/></svg>',
        'beer' => '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M18 18h26v34H18V18Z"/><path d="M44 25h5a6 6 0 0 1 0 12h-5"/><path d="M24 18v34"/><path d="M32 18v34"/><path d="M18 18c0-5 4-8 8-6 3-5 11-4 13 1 4-1 7 1 7 5"/></svg>',
        'spirits' => '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M27 10h10v12l7 10v20H20V32l7-10V10Z"/><path d="M25 10h14"/><path d="M22 36h20"/><path d="M26 45h12"/></svg>',
        'drink' => '<svg viewBox="0 0 64 64" aria-hidden="true"><path d="M18 14h28l-4 38H22L18 14Z"/><path d="M16 14h32"/><path d="M24 28h16"/><path d="M32 14v38"/></svg>',
    ];

    return $icons[$key] ?? $icons['drink'];
}

function public_wifi_escape(string $value): string
{
    return strtr($value, [
        '\\' => '\\\\',
        ';' => '\;',
        ',' => '\,',
        ':' => '\:',
        '"' => '\"',
    ]);
}

function public_wifi_payload(string $ssid, string $password, string $security): string
{
    $ssid = public_wifi_escape($ssid);

    if ($security === 'nopass') {
        return 'WIFI:T:nopass;S:' . $ssid . ';;';
    }

    return 'WIFI:T:WPA;S:' . $ssid . ';P:' . public_wifi_escape($password) . ';;';
}

$settings = [
    'bar_name' => 'Tadeo Bar',
    'default_language' => 'sq',
    'currency' => 'ALL',
    'show_prices' => '1',
    'wifi_ssid' => 'TadeoBar',
    'wifi_password' => '',
    'wifi_security' => 'WPA',
];

$categories = [];
$productsByCategory = [];
$pageError = '';

try {
    $pdo = db();

    run_trash_cleanup_if_due($pdo);

    foreach ($settings as $key => $default) {
        $settings[$key] = public_setting($pdo, $key, $default);
    }

    if (!in_array($settings['default_language'], ['sq', 'en'], true)) {
        $settings['default_language'] = 'sq';
    }

    if (!in_array($settings['wifi_security'], ['WPA', 'nopass'], true)) {
        $settings['wifi_security'] = 'WPA';
    }

    $hasCategoryImages = public_table_column_exists($pdo, 'categories', 'icon_image_path');
    $categoryImageSelect = $hasCategoryImages ? 'icon_image_path' : 'NULL AS icon_image_path';

    $hasProductDeletedAt = public_table_column_exists($pdo, 'products', 'deleted_at');
    $productDeletedWhere = $hasProductDeletedAt ? 'AND p.deleted_at IS NULL' : '';

    $categories = $pdo->query(
        "SELECT id, slug, name_sq, name_en, {$categoryImageSelect}, sort_order
         FROM categories
         WHERE is_active = 1
         ORDER BY sort_order, id"
    )->fetchAll();

    $products = $pdo->query(
        "SELECT
            p.id,
            p.menu_number,
            p.category_id,
            p.name_sq,
            p.name_en,
            p.price_all,
            p.image_path,
            p.sort_order
         FROM products p
         INNER JOIN categories c ON c.id = p.category_id
         WHERE p.is_active = 1 AND c.is_active = 1 {$productDeletedWhere}
         ORDER BY c.sort_order, p.sort_order, p.menu_number, p.id"
    )->fetchAll();

    foreach ($products as $product) {
        $categoryId = (int)$product['category_id'];
        $productsByCategory[$categoryId][] = $product;
    }
} catch (Throwable $e) {
    $pageError = 'Menuja nuk u ngarkua. Ju lutemi provoni përsëri pak më vonë.';
}

$barName = trim($settings['bar_name']) !== '' ? $settings['bar_name'] : 'Tadeo Bar';
$defaultLang = $settings['default_language'];
$currency = trim($settings['currency']) !== '' ? trim($settings['currency']) : 'ALL';
$showPrices = $settings['show_prices'] !== '0';
$wifiPayload = public_wifi_payload($settings['wifi_ssid'], $settings['wifi_password'], $settings['wifi_security']);
?>
<!doctype html>
<html lang="<?= e($defaultLang) ?>">
<head>
    <meta charset="utf-8">
    <title><?= e($barName) ?> | Menu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070707">
    <link rel="icon" href="/favicon.ico">
    <meta name="description" content="<?= e($barName) ?> digital menu and WiFi access.">
    <link rel="stylesheet" href="/assets/css/public-menu.css?v=20260518-mobile-long-text-guard-v1">
</head>
<body>
    <div class="site-shell" id="top">
        <header class="hero">
            <nav class="topbar" aria-label="Menuja kryesore">
                <a class="brand brand-text-only" href="#top" aria-label="<?= e($barName) ?>">
                    <span>
                        <strong><?= e($barName) ?></strong>
                        <small data-sq="Digital Menu" data-en="Digital Menu">Digital Menu</small>
                    </span>
                </a>

                <div class="topbar-actions">
                    <div class="language-switch" aria-label="Zgjidh gjuhën">
                        <button class="<?= $defaultLang === 'sq' ? 'active' : '' ?>" type="button" data-lang-button="sq">SQ</button>
                        <button class="<?= $defaultLang === 'en' ? 'active' : '' ?>" type="button" data-lang-button="en">EN</button>
                    </div>
                </div>
            </nav>

            <section class="hero-grid">
                <div class="hero-copy">
                    <p class="eyebrow" data-sq="Mirë se vini në" data-en="Welcome to"><?= $defaultLang === 'sq' ? 'Mirë se vini në' : 'Welcome to' ?></p>
                    <h1><?= e($barName) ?></h1>
                    <p class="hero-text" data-sq="Zgjidh pijet dhe shijo momentin." data-en="Choose your drinks and enjoy the moment.">
                        <?= $defaultLang === 'sq' ? 'Zgjidh pijet dhe shijo momentin.' : 'Choose your drinks and enjoy the moment.' ?>
                    </p>
                    <div class="hero-line"></div>
                </div>

                <aside class="wifi-card" id="wifi-card">
                    <div class="wifi-card-head wifi-card-head-clean">
                        <div>
                            <h2 data-sq="Lidhu me WiFi" data-en="Connect to WiFi"><?= $defaultLang === 'sq' ? 'Lidhu me WiFi' : 'Connect to WiFi' ?></h2>
                            <p data-sq="Skano QR ose kopjo password-in." data-en="Scan QR or copy the password."><?= $defaultLang === 'sq' ? 'Skano QR ose kopjo password-in.' : 'Scan QR or copy the password.' ?></p>
                        </div>
                    </div>

                    <div class="wifi-fields">
                        <div>
                            <small>SSID</small>
                            <strong><?= e($settings['wifi_ssid']) ?></strong>
                        </div>

                        <div>
                            <small>Password</small>
                            <strong id="wifiPassword"><?= e($settings['wifi_security'] === 'nopass' ? 'Open WiFi' : $settings['wifi_password']) ?></strong>
                        </div>
                    </div>

                    <div class="wifi-buttons">
                        <button class="primary-button" type="button" id="toggleQr" data-sq="Shfaq QR" data-en="Show QR"><?= $defaultLang === 'sq' ? 'Shfaq QR' : 'Show QR' ?></button>
                        <button class="ghost-button" type="button" id="copyWifi" data-sq="Kopjo password" data-en="Copy password"><?= $defaultLang === 'sq' ? 'Kopjo password' : 'Copy password' ?></button>
                    </div>

                    <div class="wifi-qr" id="wifiQrBox" data-qr-payload="<?= e($wifiPayload) ?>" hidden></div>
                    <div class="toast" id="toast" data-sq="U kopjua" data-en="Copied"><?= $defaultLang === 'sq' ? 'U kopjua' : 'Copied' ?></div>
                </aside>
            </section>
        </header>

        <main class="menu-panel">
            <?php if ($pageError !== ''): ?>
                <div class="menu-error"><?= e($pageError) ?></div>
            <?php endif; ?>

            <nav class="category-nav" aria-label="Kategoritë">
                <?php
                    $allCategoryImagePath = __DIR__ . '/assets/images/categories/all.webp';
                    $allCategoryImage = is_file($allCategoryImagePath) ? '/assets/images/categories/all.webp' : null;
                ?>
                <button class="category-pill active" type="button" data-filter="all">
                    <span class="pill-icon">
                        <?php if ($allCategoryImage !== null): ?>
                            <img src="<?= e($allCategoryImage) ?>" alt="<?= e($defaultLang === 'sq' ? 'Të gjitha' : 'All') ?>">
                        <?php else: ?>
                            <?= public_svg_icon('drink') ?>
                        <?php endif; ?>
                    </span>
                    <span data-sq="Të gjitha" data-en="All"><?= $defaultLang === 'sq' ? 'Të gjitha' : 'All' ?></span>
                </button>

                <?php foreach ($categories as $category): ?>
                    <?php
                        $categoryImage = public_safe_image_src($category['icon_image_path'] ?? null);
                        $iconKey = public_slug_icon_key((string)$category['slug'], (string)$category['name_sq'], (string)$category['name_en']);
                    ?>
                    <button class="category-pill" type="button" data-filter="<?= e($category['slug']) ?>">
                        <span class="pill-icon">
                            <?php if ($categoryImage !== null): ?>
                                <img src="<?= e($categoryImage) ?>" alt="<?= e($category['name_sq']) ?>">
                            <?php else: ?>
                                <?= public_svg_icon($iconKey) ?>
                            <?php endif; ?>
                        </span>
                        <span data-sq="<?= e($category['name_sq']) ?>" data-en="<?= e($category['name_en']) ?>">
                            <?= e($defaultLang === 'sq' ? $category['name_sq'] : $category['name_en']) ?>
                        </span>
                    </button>
                <?php endforeach; ?>
            </nav>

            <div class="menu-content">
                <?php foreach ($categories as $category): ?>
                    <?php
                        $categoryProducts = $productsByCategory[(int)$category['id']] ?? [];
                        $categoryImage = public_safe_image_src($category['icon_image_path'] ?? null);
                        $iconKey = public_slug_icon_key((string)$category['slug'], (string)$category['name_sq'], (string)$category['name_en']);
                    ?>

                    <section class="menu-section" id="<?= e($category['slug']) ?>" data-section="<?= e($category['slug']) ?>">
                        <div class="section-head">
                            <div class="section-title">
                                <span class="section-icon">
                                    <?php if ($categoryImage !== null): ?>
                                        <img src="<?= e($categoryImage) ?>" alt="<?= e($category['name_sq']) ?>">
                                    <?php else: ?>
                                        <?= public_svg_icon($iconKey) ?>
                                    <?php endif; ?>
                                </span>
                                <div>
                                    <h2 data-sq="<?= e($category['name_sq']) ?>" data-en="<?= e($category['name_en']) ?>">
                                        <?= e($defaultLang === 'sq' ? $category['name_sq'] : $category['name_en']) ?>
                                    </h2>
                                    <p data-sq="Zgjidh nga kjo kategori" data-en="Choose from this category">
                                        <?= $defaultLang === 'sq' ? 'Zgjidh nga kjo kategori' : 'Choose from this category' ?>
                                    </p>
                                </div>
                            </div>

                            <a href="#top" data-sq="Lart" data-en="Top"><?= $defaultLang === 'sq' ? 'Lart' : 'Top' ?></a>
                        </div>

                        <?php if ($categoryProducts === []): ?>
                            <div class="empty-card" data-sq="Nuk ka produkte aktive në këtë kategori." data-en="No active products in this category.">
                                <?= $defaultLang === 'sq' ? 'Nuk ka produkte aktive në këtë kategori.' : 'No active products in this category.' ?>
                            </div>
                        <?php else: ?>
                            <div class="product-grid">
                                <?php foreach ($categoryProducts as $product): ?>
                                    <?php
                                        $productImage = public_safe_image_src($product['image_path'] ?? null);
                                        $productTitle = $defaultLang === 'sq' ? $product['name_sq'] : $product['name_en'];
                                        $productSubtitle = $defaultLang === 'sq' ? $product['name_en'] : $product['name_sq'];
                                    ?>
                                    <article class="product-card" data-section="<?= e($category['slug']) ?>">
                                        <div class="product-media">
                                            <?php if ($productImage !== null): ?>
                                                <img class="product-media-bg" src="<?= e($productImage) ?>" alt="" aria-hidden="true" loading="lazy" decoding="async">
                                                <img class="product-media-main" src="<?= e($productImage) ?>" alt="<?= e($productTitle) ?>" loading="lazy" decoding="async">
                                            <?php else: ?>
                                                <div class="product-placeholder">
                                                    <?= public_svg_icon($iconKey) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="product-body">
                                            <div>
                                                <small>#<?= e($product['menu_number']) ?></small>
                                                <h3 data-sq="<?= e($product['name_sq']) ?>" data-en="<?= e($product['name_en']) ?>">
                                                    <?= e($productTitle) ?>
                                                </h3>
                                                <p data-sq="<?= e($product['name_en']) ?>" data-en="<?= e($product['name_sq']) ?>">
                                                    <?= e($productSubtitle) ?>
                                                </p>
                                            </div>

                                            <?php if ($showPrices): ?>
                                                <strong><?= e($product['price_all']) ?> <?= e($currency) ?></strong>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>

                <footer class="site-footer">
                    <strong><?= e($barName) ?></strong>
                    <span data-sq="Faleminderit që na zgjodhët." data-en="Thank you for choosing us.">
                        <?= $defaultLang === 'sq' ? 'Faleminderit që na zgjodhët.' : 'Thank you for choosing us.' ?>
                    </span>
                </footer>
            </div>
        </main>
    </div>

    <script src="/assets/js/wifi-qr.js?v=20260512-wifi-local-qr-1"></script>
    <script>
        (function () {
            let currentLang = document.documentElement.lang || 'sq';

            function setLang(lang) {
                currentLang = lang;
                document.documentElement.lang = lang;

                document.querySelectorAll('[data-sq][data-en]').forEach(function (element) {
                    element.textContent = element.getAttribute('data-' + lang) || element.textContent;
                });

                document.querySelectorAll('[data-lang-button]').forEach(function (button) {
                    button.classList.toggle('active', button.getAttribute('data-lang-button') === lang);
                });
            }

            document.querySelectorAll('[data-lang-button]').forEach(function (button) {
                button.addEventListener('click', function () {
                    setLang(button.getAttribute('data-lang-button') || 'sq');
                });
            });

            document.querySelectorAll('.category-pill').forEach(function (button) {
                button.addEventListener('click', function () {
                    const filter = button.getAttribute('data-filter') || 'all';

                    document.querySelectorAll('.category-pill').forEach(function (item) {
                        item.classList.remove('active');
                    });
                    button.classList.add('active');

                    document.querySelectorAll('.menu-section').forEach(function (section) {
                        section.hidden = filter !== 'all' && section.getAttribute('data-section') !== filter;
                    });
                });
            });

            const qrButton = document.getElementById('toggleQr');
            const qrBox = document.getElementById('wifiQrBox');

            function qrLabel(isOpen) {
                if (currentLang === 'sq') {
                    return isOpen ? 'Fshih QR' : 'Shfaq QR';
                }

                return isOpen ? 'Hide QR' : 'Show QR';
            }

            function renderWifiQrIfNeeded() {
                if (!qrBox) {
                    return;
                }

                const payload = qrBox.getAttribute('data-qr-payload') || '';

                if (qrBox.innerHTML.trim() !== '' || payload === '') {
                    return;
                }

                if (window.TadeoWifiQr && typeof window.TadeoWifiQr.encode === 'function' && typeof window.TadeoWifiQr.toSvg === 'function') {
                    try {
                        qrBox.innerHTML = window.TadeoWifiQr.toSvg(window.TadeoWifiQr.encode(payload));
                    } catch (error) {
                        qrBox.textContent = 'QR nuk u krijua dot.';
                    }
                    return;
                }

                qrBox.textContent = 'QR nuk u ngarkua dot.';
            }

            if (qrButton && qrBox) {
                qrButton.addEventListener('click', function () {
                    const willOpen = qrBox.hidden;
                    if (willOpen) {
                        renderWifiQrIfNeeded();
                    }

                    qrBox.hidden = !willOpen;
                    qrButton.textContent = qrLabel(willOpen);
                });
            }

            const copyButton = document.getElementById('copyWifi');
            const toast = document.getElementById('toast');
            const password = <?= json_encode($settings['wifi_security'] === 'nopass' ? '' : $settings['wifi_password'], JSON_UNESCAPED_UNICODE) ?>;

            function showToast() {
                if (!toast) {
                    return;
                }

                toast.classList.add('visible');
                setTimeout(function () {
                    toast.classList.remove('visible');
                }, 1600);
            }

            if (copyButton) {
                copyButton.addEventListener('click', function () {
                    if (password === '') {
                        showToast();
                        return;
                    }

                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(password).then(showToast).catch(showToast);
                    } else {
                        const input = document.createElement('input');
                        input.value = password;
                        document.body.appendChild(input);
                        input.select();
                        document.execCommand('copy');
                        document.body.removeChild(input);
                        showToast();
                    }
                });
            }

            setLang(currentLang);
        })();
    </script>
    <script src="/assets/js/analytics.js?v=20260512-analytics-1" defer></script>
</body>
</html>
