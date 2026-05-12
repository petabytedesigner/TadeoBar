#!/data/data/com.termux/files/usr/bin/bash
set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -d "public/tadeo-admin" ]; then
  echo "ERROR: public/tadeo-admin folder not found. Run this from the TadeoBar repo."
  exit 1
fi

backup_dir="backup/admin-header-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup_dir"
cp -a public/tadeo-admin "$backup_dir/"
cp -a public/assets/css/admin.css "$backup_dir/admin.css"

python - <<'PY'
from pathlib import Path
import re

root = Path("public/tadeo-admin")

nav_items = [
    ("dashboard.php", "Paneli", "dashboard"),
    ("products.php", "Produktet", "products"),
    ("categories.php", "Kategoritë", "categories"),
    ("images.php", "Imazhet", "images"),
    ("wifi.php", "WiFi", "wifi"),
    ("analytics.php", "Analitika", "analytics"),
    ("settings.php", "Cilësimet", "settings"),
]

product_pages = {
    "products.php",
    "product-create.php",
    "product-edit.php",
    "product-toggle.php",
    "product-delete.php",
}

def active_key(filename: str) -> str:
    if filename == "dashboard.php":
        return "dashboard"
    if filename in product_pages:
        return "products"
    if filename == "categories.php":
        return "categories"
    if filename == "images.php":
        return "images"
    if filename == "wifi.php":
        return "wifi"
    if filename == "analytics.php":
        return "analytics"
    if filename == "settings.php":
        return "settings"
    return ""

def build_header(filename: str) -> str:
    active = active_key(filename)
    links = []
    for href, label, key in nav_items:
        cls = ' class="active"' if key == active else ''
        links.append(f'                <a{cls} href="/tadeo-admin/{href}">{label}</a>')
    nav = "\n".join(links)

    return '''<header class="admin-header">
            <div class="admin-topbar">
                <div class="admin-identity">
                    <div class="admin-brand">Tadeo Bar</div>
                    <?php if (isset($admin)): ?>
                        <div class="admin-muted">I identifikuar si <?= e($admin['username']) ?></div>
                    <?php endif; ?>
                </div>

                <a class="admin-logout" href="/tadeo-admin/logout.php">Dil</a>
            </div>

            <nav class="admin-nav">
''' + nav + '''
            </nav>
        </header>'''

pattern = re.compile(r'<header\s+class="admin-header"[^>]*>.*?</header>', re.S | re.I)

changed = []
for path in sorted(root.glob("*.php")):
    if path.name in {"login.php", "logout.php", "index.php", "product-toggle.php", "product-delete.php"}:
        continue

    text = path.read_text(encoding="utf-8")
    if '<header' not in text or 'admin-header' not in text:
        continue

    new_header = build_header(path.name)
    new_text, count = pattern.subn(new_header, text, count=1)

    if count == 0:
        print(f"WARNING: header not replaced in {path}")
        continue

    if new_text != text:
        path.write_text(new_text, encoding="utf-8")
        changed.append(str(path))

print("Updated admin headers:")
for item in changed:
    print(" -", item)
PY

cat >> public/assets/css/admin.css <<'CSS'

/* Admin header layout v2: logout button fixed to top-right */
.admin-header {
    display: block !important;
    margin-bottom: 22px !important;
}

.admin-topbar {
    display: flex !important;
    align-items: flex-start !important;
    justify-content: space-between !important;
    gap: 16px !important;
    margin-bottom: 18px !important;
}

.admin-identity {
    min-width: 0;
}

.admin-logout {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: auto !important;
    flex: 0 0 auto !important;
    margin-top: 0 !important;
    margin-left: auto !important;
    padding: 11px 18px !important;
    border-radius: 14px !important;
    border: 1px solid rgba(217, 164, 65, .35) !important;
    background: rgba(217, 164, 65, .14) !important;
    color: #f3c96d !important;
    text-decoration: none !important;
    font-weight: 900 !important;
    line-height: 1 !important;
}

.admin-logout:hover {
    background: rgba(217, 164, 65, .22) !important;
}

.admin-nav {
    display: flex !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
    align-items: center !important;
}

@media (max-width: 780px) {
    .admin-topbar {
        flex-direction: row !important;
        align-items: flex-start !important;
    }

    .admin-brand {
        font-size: 34px !important;
        line-height: 1.05 !important;
    }

    .admin-logout {
        margin-top: 4px !important;
        padding: 10px 15px !important;
    }

    .admin-nav {
        margin-top: 6px !important;
    }
}
CSS

echo "OK: Admin header/logout layout patch applied."
echo "Now run:"
echo "  php -l public/tadeo-admin/dashboard.php"
echo "  php -l public/tadeo-admin/products.php"
echo "  ./scripts/deploy.sh"
