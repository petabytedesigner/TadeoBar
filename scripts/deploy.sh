#!/data/data/com.termux/files/usr/bin/bash
set -euo pipefail

if [ ! -f ".env" ]; then
  echo "ERROR: .env file not found"
  exit 1
fi

set -a
source .env
set +a

LOCAL_DIR="public"
REMOTE_DIR="/htdocs"

if [ -z "${FTP_HOST:-}" ] || [ -z "${FTP_USER:-}" ] || [ -z "${FTP_PASS:-}" ]; then
  echo "ERROR: FTP_HOST, FTP_USER, or FTP_PASS is missing in .env"
  exit 1
fi

echo "Deploying $LOCAL_DIR to $FTP_HOST:$REMOTE_DIR"

lftp -u "$FTP_USER","$FTP_PASS" "$FTP_HOST" <<LFTP
set cmd:fail-exit yes
set ftp:ssl-force true
set ftp:ssl-protect-data true
set ssl:verify-certificate yes
mirror -R --delete --verbose --no-perms "$LOCAL_DIR"/ "$REMOTE_DIR"/
bye
LFTP

echo "Deploy completed."
