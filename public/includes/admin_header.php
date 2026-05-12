<?php
declare(strict_types=1);

function render_admin_header(array $admin, string $active): void
{
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
                <div class="admin-brand">Tadeo Bar</div>
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
