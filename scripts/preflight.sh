#!/data/data/com.termux/files/usr/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

ok() {
  echo "OK: $*"
}

echo "===== Bar Tadeo preflight ====="

required_commands=(php lftp sha256sum mktemp find dirname)
for command_name in "${required_commands[@]}"; do
  command -v "$command_name" >/dev/null 2>&1 || fail "$command_name is not installed"
done
ok "Required commands are available"

[ -f ".env" ] || fail ".env file not found"
ok "Private FTP configuration file exists"

set -a
# shellcheck disable=SC1091
source .env
set +a

[ -n "${FTP_HOST:-}" ] || fail "FTP_HOST is missing in .env"
[ -n "${FTP_USER:-}" ] || fail "FTP_USER is missing in .env"
[ -n "${FTP_PASS:-}" ] || fail "FTP_PASS is missing in .env"
ok "FTP configuration is present"

required_files=(
  "public/includes/.htaccess"
  "public/includes/db.php"
  "public/includes/auth.php"
  "public/includes/csrf.php"
  "public/includes/security_guard.php"
  "public/includes/password_recovery.php"
  "public/includes/smtp_mailer.php"
  "public/includes/upload.php"
  "public/tadeo-admin/login.php"
  "public/tadeo-admin/forgot-password.php"
  "public/tadeo-admin/verify-reset-code.php"
  "public/tadeo-admin/reset-password.php"
  "public/tadeo-admin/recovery-setup.php"
  "public/tadeo-admin/product-create.php"
  "public/tadeo-admin/product-edit.php"
  "public/tadeo-admin/category-create.php"
  "public/tadeo-admin/category-edit.php"
  "public/assets/js/product-image-preview.js"
  "public/assets/js/product-image-editor.js"
  "public/assets/css/product-image-editor.css"
  "public/assets/vendor/filerobot-image-editor/filerobot-image-editor.min.js"
  "public/assets/vendor/filerobot-image-editor/LICENSE"
  "public/assets/vendor/filerobot-image-editor/VERSION.txt"
  "public/assets/vendor/filerobot-image-editor/SHA256SUMS"
  "database/schema.sql"
  "database/migrations/20260831-password-recovery.sql"
  "database/migrations/20260901-security-ip-guard.sql"
)

for file in "${required_files[@]}"; do
  [ -f "$file" ] || fail "Required file missing: $file"
done
ok "Required application, security, recovery, and image-editor files exist"

grep -q 'Require all denied' public/includes/.htaccess \
  || fail "public/includes/.htaccess does not deny direct access"
grep -q 'password_reset_codes' database/schema.sql \
  || fail "database/schema.sql is missing password_reset_codes"
grep -q 'password_reset_codes' database/migrations/20260831-password-recovery.sql \
  || fail "Password recovery migration is incomplete"
grep -q 'security_ip_guard' database/schema.sql \
  || fail "database/schema.sql is missing security_ip_guard"
grep -q 'security_ip_guard' database/migrations/20260901-security-ip-guard.sql \
  || fail "IP guard migration is incomplete"
grep -q 'site_ip_guard_enforce' public/includes/db.php \
  || fail "db.php is not enforcing the site-wide IP guard"
grep -q 'SITE_IP_GUARD_MAX_FAILED_LOGINS = 15' public/includes/security_guard.php \
  || fail "IP guard failed-login threshold is not configured as expected"
grep -q 'SITE_IP_GUARD_BLOCK_HOURS = 24' public/includes/security_guard.php \
  || fail "IP guard block duration is not configured as expected"
grep -q 'sha256sum' scripts/deploy.sh \
  || fail "deploy.sh is not using SHA-256 content comparison"
ok "Security, checksum deployment, and database recovery structure is present"

editor_expected_hash='ff0d274db699974b2096786549a424473438a72971da416d54cd68c05cd282df'
read -r editor_actual_hash _ < <(sha256sum public/assets/vendor/filerobot-image-editor/filerobot-image-editor.min.js)
[ "$editor_actual_hash" = "$editor_expected_hash" ] \
  || fail "Filerobot editor bundle SHA-256 does not match the pinned v4.9.1 build"
grep -q '^Upstream tag: v4\.9\.1$' public/assets/vendor/filerobot-image-editor/VERSION.txt \
  || fail "Filerobot VERSION.txt is not pinned to upstream tag v4.9.1"
grep -q "$editor_expected_hash" public/assets/vendor/filerobot-image-editor/SHA256SUMS \
  || fail "Filerobot SHA256SUMS is missing the pinned bundle hash"
grep -q '/assets/vendor/filerobot-image-editor/filerobot-image-editor.min.js?v=4.9.1' public/tadeo-admin/product-create.php \
  || fail "Product create does not load the pinned local image editor"
grep -q '/assets/vendor/filerobot-image-editor/filerobot-image-editor.min.js?v=4.9.1' public/tadeo-admin/product-edit.php \
  || fail "Product edit does not load the pinned local image editor"
for transactional_form in \
  public/tadeo-admin/product-create.php \
  public/tadeo-admin/product-edit.php \
  public/tadeo-admin/category-create.php \
  public/tadeo-admin/category-edit.php; do
  grep -q 'run_prepared_image_upload_transaction' "$transactional_form" \
    || fail "$transactional_form is not using staged image/database transaction handling"
done
ok "Pinned local image editor and transactional image integration passed"

php_files_checked=0
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null || fail "PHP syntax error: $file"
  php_files_checked=$((php_files_checked + 1))
done < <(find public -type f -name '*.php' ! -name 'config.local.php' ! -name 'recovery.local.php' -print0)
ok "PHP syntax passed for ${php_files_checked} tracked application files"

bash -n scripts/deploy.sh || fail "Shell syntax error: scripts/deploy.sh"
bash -n scripts/preflight.sh || fail "Shell syntax error: scripts/preflight.sh"
ok "Deploy and preflight shell syntax passed"

if [ -d .git ] && command -v git >/dev/null 2>&1; then
  git check-ignore -q .env || fail ".env is not ignored by Git"
  git check-ignore -q public/includes/config.local.php \
    || fail "config.local.php is not ignored by Git"
  git check-ignore -q public/includes/recovery.local.php \
    || fail "recovery.local.php is not ignored by Git"

  if git ls-files --error-unmatch .env >/dev/null 2>&1; then
    fail ".env is tracked by Git"
  fi
  if git ls-files --error-unmatch public/includes/config.local.php >/dev/null 2>&1; then
    fail "config.local.php is tracked by Git"
  fi
  if git ls-files --error-unmatch public/includes/recovery.local.php >/dev/null 2>&1; then
    fail "recovery.local.php is tracked by Git"
  fi

  if [ -n "$(git status --porcelain --untracked-files=normal)" ]; then
    echo "WARNING: Git working tree is not clean. Review git status before final deploy."
  else
    ok "Git working tree is clean"
  fi

  ok "Private files are excluded from Git"
else
  echo "WARNING: .git metadata not found; Git tracking checks were skipped."
fi

echo "NOTE: Server-side includes/config.local.php and includes/recovery.local.php are intentionally preserved by deploy.sh."
echo "NOTE: Runtime product/category/trash images and uploads/.trash-cleanup-last-run are preserved by deploy.sh."
echo "===== Preflight passed ====="
