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
TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/tadeobar-deploy.XXXXXX")"
REMOTE_SNAPSHOT="$TMP_DIR/remote"
UPLOAD_LIST="$TMP_DIR/upload.list"
DELETE_LIST="$TMP_DIR/delete.list"
SYNC_SCRIPT="$TMP_DIR/sync.lftp"
VERIFY_DIR="$TMP_DIR/verify"
VERIFY_SCRIPT="$TMP_DIR/verify.lftp"

cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT INT TERM

mkdir -p "$REMOTE_SNAPSHOT" "$VERIFY_DIR"
: > "$UPLOAD_LIST"
: > "$DELETE_LIST"

is_preserved_path() {
  local rel="$1"

  case "$rel" in
    includes/config.local.php|includes/recovery.local.php|uploads/.trash-cleanup-last-run)
      return 0
      ;;
    uploads/products/*.jpg|uploads/products/*.jpeg|uploads/products/*.png|uploads/products/*.webp)
      return 0
      ;;
    uploads/categories/*.jpg|uploads/categories/*.jpeg|uploads/categories/*.png|uploads/categories/*.webp)
      return 0
      ;;
    uploads/trash/products/*.jpg|uploads/trash/products/*.jpeg|uploads/trash/products/*.png|uploads/trash/products/*.webp)
      return 0
      ;;
    uploads/trash/categories/*.jpg|uploads/trash/categories/*.jpeg|uploads/trash/categories/*.png|uploads/trash/categories/*.webp)
      return 0
      ;;
  esac

  return 1
}

file_sha256() {
  local hash rest
  read -r hash rest < <(sha256sum "$1")
  printf '%s' "$hash"
}

lftp_quote() {
  local value="$1"

  if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
    echo "ERROR: Refusing unsafe path containing a newline: $value" >&2
    exit 1
  fi

  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  value="${value//\$/\\\$}"
  value="${value//\`/\\\`}"
  printf '"%s"' "$value"
}

write_lftp_settings() {
  cat <<'EOF'
set cmd:fail-exit yes
set ftp:ssl-force true
set ftp:ssl-protect-data true
set ftp:passive-mode true
set ftp:list-options -a
set ssl:verify-certificate yes
EOF
}

echo "===== Bar Tadeo checksum deploy ====="
echo "Reading deployable files from the live host..."

lftp -u "$FTP_USER","$FTP_PASS" "$FTP_HOST" <<LFTP
set cmd:fail-exit yes
set ftp:ssl-force true
set ftp:ssl-protect-data true
set ftp:passive-mode true
set ftp:list-options -a
set ssl:verify-certificate yes
mirror --no-perms \
  --exclude-glob includes/config.local.php \
  --exclude-glob includes/recovery.local.php \
  --exclude-glob uploads/.trash-cleanup-last-run \
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
  "$REMOTE_DIR"/ "$REMOTE_SNAPSHOT"/
bye
LFTP

declare -A LOCAL_FILES=()
declare -A REMOTE_FILES=()

while IFS= read -r -d '' path; do
  rel="${path#"$LOCAL_DIR"/}"
  if is_preserved_path "$rel"; then
    continue
  fi
  LOCAL_FILES["$rel"]=1
done < <(find "$LOCAL_DIR" -type f -print0)

while IFS= read -r -d '' path; do
  rel="${path#"$REMOTE_SNAPSHOT"/}"
  if is_preserved_path "$rel"; then
    continue
  fi
  REMOTE_FILES["$rel"]=1
done < <(find "$REMOTE_SNAPSHOT" -type f -print0)

same_count=0
upload_count=0
delete_count=0

for rel in "${!LOCAL_FILES[@]}"; do
  local_path="$LOCAL_DIR/$rel"
  remote_path="$REMOTE_SNAPSHOT/$rel"

  if [[ -f "$remote_path" ]]; then
    local_hash="$(file_sha256 "$local_path")"
    remote_hash="$(file_sha256 "$remote_path")"

    if [[ "$local_hash" == "$remote_hash" ]]; then
      ((same_count += 1))
      continue
    fi
  fi

  printf '%s\0' "$rel" >> "$UPLOAD_LIST"
  ((upload_count += 1))
done

for rel in "${!REMOTE_FILES[@]}"; do
  if [[ -z "${LOCAL_FILES[$rel]+x}" ]]; then
    printf '%s\0' "$rel" >> "$DELETE_LIST"
    ((delete_count += 1))
  fi
done

echo
echo "===== CHECKSUM PLAN ====="
echo "SAME:   $same_count"
echo "UPLOAD: $upload_count"
echo "DELETE: $delete_count"

if (( upload_count > 0 )); then
  echo
  echo "Files to upload:"
  while IFS= read -r -d '' rel; do
    echo "  + $rel"
  done < "$UPLOAD_LIST"
fi

if (( delete_count > 0 )); then
  echo
  echo "Files to delete from host:"
  while IFS= read -r -d '' rel; do
    echo "  - $rel"
  done < "$DELETE_LIST"
fi

if (( upload_count == 0 && delete_count == 0 )); then
  echo
  echo "Host and repo deployable files are already identical. Nothing to transfer or delete."
  echo "Deploy completed with zero remote changes."
  exit 0
fi

write_lftp_settings > "$SYNC_SCRIPT"

while IFS= read -r -d '' rel; do
  local_path="$ROOT_DIR/$LOCAL_DIR/$rel"
  remote_path="$REMOTE_DIR/$rel"
  remote_parent="${remote_path%/*}"

  printf 'mkdir -p %s\n' "$(lftp_quote "$remote_parent")" >> "$SYNC_SCRIPT"

  if [[ "$rel" == "assets/images/categories/all.webp" ]]; then
    temp_path="$remote_parent/.all-image-upload.tmp"
    printf 'rm -f %s\n' "$(lftp_quote "$temp_path")" >> "$SYNC_SCRIPT"
    printf 'put %s -o %s\n' "$(lftp_quote "$local_path")" "$(lftp_quote "$temp_path")" >> "$SYNC_SCRIPT"
    printf 'rm -f %s\n' "$(lftp_quote "$remote_path")" >> "$SYNC_SCRIPT"
    printf 'mv %s %s\n' "$(lftp_quote "$temp_path")" "$(lftp_quote "$remote_path")" >> "$SYNC_SCRIPT"
  else
    printf 'put %s -o %s\n' "$(lftp_quote "$local_path")" "$(lftp_quote "$remote_path")" >> "$SYNC_SCRIPT"
  fi
done < "$UPLOAD_LIST"

while IFS= read -r -d '' rel; do
  remote_path="$REMOTE_DIR/$rel"
  printf 'rm -f %s\n' "$(lftp_quote "$remote_path")" >> "$SYNC_SCRIPT"
done < "$DELETE_LIST"

printf 'bye\n' >> "$SYNC_SCRIPT"

echo
echo "Applying checksum plan..."
# Feed commands through stdin for compatibility with Termux lftp builds that
# do not expose the desktop-style -f script-file option.
lftp -u "$FTP_USER","$FTP_PASS" "$FTP_HOST" < "$SYNC_SCRIPT"

if (( upload_count > 0 )); then
  write_lftp_settings > "$VERIFY_SCRIPT"

  while IFS= read -r -d '' rel; do
    verify_path="$VERIFY_DIR/$rel"
    verify_parent="${verify_path%/*}"
    remote_path="$REMOTE_DIR/$rel"

    mkdir -p "$verify_parent"
    printf 'get %s -o %s\n' "$(lftp_quote "$remote_path")" "$(lftp_quote "$verify_path")" >> "$VERIFY_SCRIPT"
  done < "$UPLOAD_LIST"

  printf 'bye\n' >> "$VERIFY_SCRIPT"

  echo "Verifying uploaded files by SHA-256..."
  lftp -u "$FTP_USER","$FTP_PASS" "$FTP_HOST" < "$VERIFY_SCRIPT"

  while IFS= read -r -d '' rel; do
    local_hash="$(file_sha256 "$LOCAL_DIR/$rel")"
    remote_hash="$(file_sha256 "$VERIFY_DIR/$rel")"

    if [[ "$local_hash" != "$remote_hash" ]]; then
      echo "ERROR: SHA-256 verification failed after upload: $rel" >&2
      exit 1
    fi
  done < "$UPLOAD_LIST"
fi

echo
echo "===== DEPLOY RESULT ====="
echo "UNCHANGED: $same_count"
echo "UPLOADED:  $upload_count"
echo "DELETED:   $delete_count"
echo "All uploaded files passed SHA-256 verification."
echo "Deploy completed."