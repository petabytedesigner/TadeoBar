<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function admin_header_setting_get(string $key, string $default = ''): string
{
    try {
        $stmt = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            return $default;
        }

        $value = trim((string)$value);

        return $value !== '' ? $value : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function render_admin_header(array $admin, string $active): void
{
    $barName = admin_header_setting_get('bar_name', 'Tadeo Bar');

    $items = [
        'dashboard' => ['Paneli', '/tadeo-admin/dashboard.php'],
        'products' => ['Produktet', '/tadeo-admin/products.php'],
        'categories' => ['Kategoritë', '/tadeo-admin/categories.php'],
        'images' => ['Imazhet', '/tadeo-admin/images.php'],
        'wifi' => ['WiFi', '/tadeo-admin/wifi.php'],
        'analytics' => ['Analitika', '/tadeo-admin/analytics.php'],
        'settings' => ['Cilësimet', '/tadeo-admin/settings.php'],
    ];
    ?>
    <header class="admin-header">
        <div class="admin-topbar">
            <div class="admin-identity">
                <div class="admin-brand"><?= e($barName) ?></div>
                <div class="admin-muted">I identifikuar si <?= e($admin['username'] ?? '') ?></div>
            </div>

            <div class="admin-header-actions">
                <a class="admin-view-menu-button" href="/" target="_blank" rel="noopener">Menuja</a>
                <a class="admin-logout-button" href="/tadeo-admin/logout.php" aria-label="Dil nga paneli">Dil</a>
            </div>
        </div>

        <nav class="admin-nav" aria-label="Menuja e administrimit">
            <?php foreach ($items as $key => [$label, $href]): ?>
                <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </header>
    <?php
}
