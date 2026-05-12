#!/data/data/com.termux/files/usr/bin/bash
set -euo pipefail

CSS_FILE="public/assets/css/admin.css"
MARKER_START="/* ADMIN LOGOUT TOP RIGHT FIX START */"
MARKER_END="/* ADMIN LOGOUT TOP RIGHT FIX END */"

if [ ! -f "$CSS_FILE" ]; then
  echo "ERROR: $CSS_FILE not found. Run this from the TadeoBar repository root."
  exit 1
fi

python - <<'PY'
from pathlib import Path

path = Path("public/assets/css/admin.css")
text = path.read_text(encoding="utf-8")
start = "/* ADMIN LOGOUT TOP RIGHT FIX START */"
end = "/* ADMIN LOGOUT TOP RIGHT FIX END */"

block = r'''
/* ADMIN LOGOUT TOP RIGHT FIX START */
.admin-header {
    position: relative;
    align-items: flex-start;
    padding-right: 112px;
}

.admin-header > a[href$="logout.php"],
.admin-header > div > a[href$="logout.php"],
.admin-header a.admin-logout,
.admin-header .admin-logout {
    position: absolute;
    top: 6px;
    right: 0;
    width: auto !important;
    margin: 0 !important;
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    min-height: 38px;
    padding: 10px 16px;
    border-radius: 14px;
    border: 1px solid rgba(217, 164, 65, .30);
    background: rgba(217, 164, 65, .12);
    color: var(--gold-light);
    font-weight: 900;
    line-height: 1;
    text-decoration: none !important;
    box-shadow: 0 10px 30px rgba(0, 0, 0, .25);
}

.admin-header > a[href$="logout.php"]:hover,
.admin-header > div > a[href$="logout.php"]:hover,
.admin-header a.admin-logout:hover,
.admin-header .admin-logout:hover {
    background: rgba(217, 164, 65, .20);
    border-color: rgba(243, 201, 109, .48);
}

.admin-nav {
    width: 100%;
    margin-top: 20px;
}

@media (max-width: 780px) {
    .admin-header {
        padding-right: 86px;
    }

    .admin-header > a[href$="logout.php"],
    .admin-header > div > a[href$="logout.php"],
    .admin-header a.admin-logout,
    .admin-header .admin-logout {
        top: 4px;
        right: 0;
        min-height: 36px;
        padding: 9px 13px;
        border-radius: 12px;
        font-size: 14px;
    }

    .admin-nav {
        margin-top: 18px;
    }
}
/* ADMIN LOGOUT TOP RIGHT FIX END */
'''.strip() + "\n"

if start in text and end in text:
    before = text.split(start)[0].rstrip()
    after = text.split(end, 1)[1].lstrip()
    text = before + "\n\n" + block + "\n" + after
else:
    text = text.rstrip() + "\n\n" + block

path.write_text(text, encoding="utf-8")
print("OK: admin logout layout fix applied to public/assets/css/admin.css")
PY
