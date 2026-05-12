#!/usr/bin/env python3
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
admin_dir = ROOT / 'public' / 'tadeo-admin'
includes_dir = ROOT / 'public' / 'includes'
css_path = ROOT / 'public' / 'assets' / 'css' / 'admin.css'

includes_dir.mkdir(parents=True, exist_ok=True)

header_php = '''<?php
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

            <a class="admin-logout-button" href="/tadeo-admin/logout.php" aria-label="Dil nga paneli">Dil</a>
        </div>

        <nav class="admin-nav" aria-label="Menuja e administrimit">
            <?php foreach ($items as $key => [$label, $href]): ?>
                <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </header>
    <?php
}
'''

(includes_dir / 'admin_header.php').write_text(header_php, encoding='utf-8')

active_by_file = {
    'dashboard.php': 'dashboard',
    'products.php': 'products',
    'product-create.php': 'products',
    'product-edit.php': 'products',
    'categories.php': 'categories',
    'images.php': 'images',
    'wifi.php': 'wifi',
    'analytics.php': 'analytics',
    'settings.php': 'settings',
}

require_line = "require_once __DIR__ . '/../includes/admin_header.php';"

for filename, active in active_by_file.items():
    path = admin_dir / filename
    if not path.exists():
        continue

    text = path.read_text(encoding='utf-8')

    if require_line not in text:
        lines = text.splitlines()
        insert_at = None
        for i, line in enumerate(lines):
            if line.startswith("require_once __DIR__ . '/../includes/"):
                insert_at = i + 1
        if insert_at is None:
            insert_at = 2
        lines.insert(insert_at, require_line)
        text = '\n'.join(lines) + '\n'

    replacement = f"\\1\n        <?php render_admin_header($admin, '{active}'); ?>\n\n        <main>"
    new_text, count = re.subn(
        r'(<body>\s*<div class="admin-layout">\s*)(.*?)(\s*<main>)',
        replacement,
        text,
        count=1,
        flags=re.S,
    )
    if count != 1:
        raise SystemExit(f'Could not normalize header in {path}')

    path.write_text(new_text, encoding='utf-8')

css = css_path.read_text(encoding='utf-8') if css_path.exists() else ''

css = re.sub(r'/\* ADMIN LOGOUT TOP RIGHT FIX START \*/.*?/\* ADMIN LOGOUT TOP RIGHT FIX END \*/\s*', '', css, flags=re.S)
css = re.sub(r'/\* Admin header layout v2: logout button fixed to top-right \*/.*', '', css, flags=re.S)
css = re.sub(r'/\* FINAL CLEAN ADMIN HEADER START \*/.*?/\* FINAL CLEAN ADMIN HEADER END \*/\s*', '', css, flags=re.S)
css = re.sub(r'/\* TADEO GOLD LOGOUT BUTTON FINAL START \*/.*?/\* TADEO GOLD LOGOUT BUTTON FINAL END \*/\s*', '', css, flags=re.S)

css += r'''

/* TADEO GOLD LOGOUT BUTTON FINAL START */
.admin-header {
    display: block !important;
    position: static !important;
    padding-right: 0 !important;
    margin-bottom: 24px !important;
}

.admin-topbar {
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) auto !important;
    align-items: start !important;
    gap: 18px !important;
    width: 100% !important;
    margin-bottom: 22px !important;
}

.admin-identity {
    min-width: 0 !important;
}

.admin-logout-button {
    justify-self: end !important;
    align-self: start !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: auto !important;
    min-width: 86px !important;
    margin: 8px 0 0 0 !important;
    padding: 12px 18px !important;
    border-radius: 16px !important;
    border: 1px solid rgba(243, 201, 109, .48) !important;
    background: linear-gradient(135deg, rgba(243, 201, 109, .22), rgba(217, 164, 65, .13)) !important;
    color: var(--gold-light) !important;
    font-weight: 900 !important;
    line-height: 1 !important;
    text-decoration: none !important;
    box-shadow: 0 14px 34px rgba(0, 0, 0, .34), inset 0 1px 0 rgba(255,255,255,.08) !important;
}

.admin-logout-button:hover,
.admin-logout-button:focus {
    background: linear-gradient(135deg, rgba(243, 201, 109, .32), rgba(217, 164, 65, .20)) !important;
    border-color: rgba(243, 201, 109, .72) !important;
    outline: none !important;
}

.admin-nav {
    width: 100% !important;
    margin-top: 0 !important;
    margin-bottom: 18px !important;
}

@media (max-width: 780px) {
    .admin-topbar {
        grid-template-columns: minmax(0, 1fr) auto !important;
        gap: 12px !important;
        margin-bottom: 20px !important;
    }

    .admin-brand {
        font-size: 34px !important;
        line-height: 1.05 !important;
    }

    .admin-logout-button {
        min-width: 72px !important;
        margin-top: 7px !important;
        padding: 11px 15px !important;
        border-radius: 14px !important;
        font-size: 15px !important;
    }

    .admin-nav {
        overflow-x: auto !important;
        flex-wrap: nowrap !important;
        padding-bottom: 4px !important;
    }

    .admin-nav a {
        flex: 0 0 auto !important;
    }
}
/* TADEO GOLD LOGOUT BUTTON FINAL END */
'''
css_path.write_text(css, encoding='utf-8')

print('Admin header cleaned. Logout button is now top-right with gold styling.')
