#!/data/data/com.termux/files/usr/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

bash scripts/preflight.sh

set -a
source .env
set +a

LOCAL_DIR="public"
REMOTE_DIR="/htdocs"
STATIC_ALL_LOCAL="public/assets/images/categories/all.webp"
STATIC_ALL_REMOTE="/htdocs/assets/images/categories/all.webp"
STATIC_ALL_TMP="/htdocs/assets/images/categories/.all-image-upload.tmp"

echo "Deploying $LOCAL_DIR to $FTP_HOST:$REMOTE_DIR"
echo "Preserving server DB/recovery config and uploaded images..."

lftp -u "$FTP_USER","$FTP_PASS" "$FTP_HOST" <<LFTP
set cmd:fail-exit yes
set ftp:ssl-force true
set ftp:ssl-protect-data true
set ftp:passive-mode true
set ssl:verify-certificate yes
mirror -R --delete --verbose --no-perms \
  --exclude-glob includes/config.local.php \
  --exclude-glob includes/recovery.local.php \
  --exclude-glob assets/images/categories/all.webp \
  --exclude-glob uploads/products/*.jpg \
  --exclude-glob uploads/products/*.jpeg \
  --exclude-glob uploads/products/*.png \
  --exclude-glob uploads/products/*.webp \
  --exclude-glob uploads/categories/*.jpg \
  --exclude-glob uploads/categories/*.jpeg \
  --exclude-glob uploads/categories/*.png \
  --exclude-glob uploads/categories/*.webp \
  --exclude-glob uploads/trash/products/*.jpg \
  --exclude-glob uploads/trash/products/*.jpeg \
  --exclude-glob uploads/trash/products/*.png \
  --exclude-glob uploads/trash/products/*.webp \
  --exclude-glob uploads/trash/categories/*.jpg \
  --exclude-glob uploads/trash/categories/*.jpeg \
  --exclude-glob uploads/trash/categories/*.png \
  --exclude-glob uploads/trash/categories/*.webp \
  "$LOCAL_DIR"/ "$REMOTE_DIR"/

# InfinityFree FTP may reject direct STOR to a .webp filename. Upload this
# tracked static asset under a neutral temporary name, then rename it.
mkdir -p /htdocs/assets/images/categories
rm -f "$STATIC_ALL_TMP"
put "$STATIC_ALL_LOCAL" -o "$STATIC_ALL_TMP"
rm -f "$STATIC_ALL_REMOTE"
mv "$STATIC_ALL_TMP" "$STATIC_ALL_REMOTE"
cls -l "$STATIC_ALL_REMOTE"

bye
LFTP

echo "Deploy completed."
