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
  "public/includes/product_ordering.php"
  "public/tadeo-admin/login.php"
  "public/tadeo-admin/dashboard.php"
  "public/tadeo-admin/forgot-password.php"
  "public/tadeo-admin/verify-reset-code.php"
  "public/tadeo-admin/reset-password.php"
  "public/tadeo-admin/recovery-setup.php"
  "public/tadeo-admin/products.php"
  "public/tadeo-admin/product-create.php"
  "public/tadeo-admin/product-edit.php"
  "public/tadeo-admin/product-delete.php"
  "public/tadeo-admin/product-restore.php"
  "public/tadeo-admin/product-toggle.php"
  "public/tadeo-admin/product-trash.php"
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
  "database/migrations/20260901-product-menu-ordering.sql"
  "database/migrations/20260902-auth-recovery-hardening.sql"
)

for file in "${required_files[@]}"; do
  [ -f "$file" ] || fail "Required file missing: $file"
done
ok "Required application, security, recovery, ordering, and image-editor files exist"

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
grep -q 'session_version' database/schema.sql \
  || fail "database/schema.sql is missing revocable admin sessions"
grep -q 'sent_at' database/schema.sql \
  || fail "database/schema.sql is missing delivered-code tracking"
grep -q 'session_version' database/migrations/20260902-auth-recovery-hardening.sql \
  || fail "Auth/recovery migration is missing session_version"
grep -q 'sent_at' database/migrations/20260902-auth-recovery-hardening.sql \
  || fail "Auth/recovery migration is missing sent_at"
grep -q 'site_ip_guard_enforce' public/includes/db.php \
  || fail "db.php is not enforcing the site-wide IP guard"
grep -q 'SITE_IP_GUARD_MAX_FAILED_LOGINS = 15' public/includes/security_guard.php \
  || fail "IP guard failed-login threshold is not configured as expected"
grep -q 'SITE_IP_GUARD_BLOCK_HOURS = 24' public/includes/security_guard.php \
  || fail "IP guard block duration is not configured as expected"
grep -q 'function find_admin_by_login_identifier' public/includes/auth.php \
  || fail "Authentication does not support username/email identifiers"
grep -q "return 'admin:'" public/includes/auth.php \
  || fail "Username/email aliases are not sharing the same login-attempt key"
grep -q 'session_version' public/includes/auth.php \
  || fail "Admin sessions are not versioned"
grep -q 'Username ose email' public/tadeo-admin/login.php \
  || fail "Login UI does not expose username/email login"
grep -q 'PASSWORD_RESET_MAX_CODE_ATTEMPTS = 10' public/includes/password_recovery.php \
  || fail "Password-reset codes are not limited to 10 verification attempts"
grep -q 'PASSWORD_RESET_MAX_REQUESTS_PER_SHORT_WINDOW = 3' public/includes/password_recovery.php \
  || fail "Password-reset short-window send limit is not configured"
grep -q 'PASSWORD_RESET_SHORT_WINDOW_MINUTES = 15' public/includes/password_recovery.php \
  || fail "Password-reset short window is not 15 minutes"
grep -q 'PASSWORD_RESET_MAX_REQUESTS_PER_LONG_WINDOW = 6' public/includes/password_recovery.php \
  || fail "Password-reset 12-hour send limit is not configured"
grep -q 'PASSWORD_RESET_LONG_WINDOW_HOURS = 12' public/includes/password_recovery.php \
  || fail "Password-reset long window is not 12 hours"
grep -q 'PASSWORD_RESET_REQUEST_COOLDOWN_SECONDS = 60' public/includes/password_recovery.php \
  || fail "Password-reset resend cooldown is not 60 seconds"
grep -q 'FOR UPDATE' public/includes/password_recovery.php \
  || fail "Password-reset counters are not serialized with row locking"
grep -q 'session_version = session_version + 1' public/includes/password_recovery.php \
  || fail "Password reset is not revoking existing admin sessions"
grep -q 'recovery_email_change_begin' public/tadeo-admin/settings.php \
  || fail "Settings does not require verification before changing the login/recovery email"
grep -q 'recovery_email_change_verify' public/tadeo-admin/settings.php \
  || fail "Settings is missing login/recovery email verification"
ok "Authentication and password recovery hardening is present"

grep -q 'sha256sum' scripts/deploy.sh \
  || fail "deploy.sh is not using SHA-256 content comparison"
grep -Fq 'lftp -u "$FTP_USER","$FTP_PASS" "$FTP_HOST" < "$SYNC_SCRIPT"' scripts/deploy.sh \
  || fail "deploy.sh is not using Termux-compatible stdin execution for sync"
grep -Fq 'lftp -u "$FTP_USER","$FTP_PASS" "$FTP_HOST" < "$VERIFY_SCRIPT"' scripts/deploy.sh \
  || fail "deploy.sh is not using Termux-compatible stdin execution for verification"
if grep -Fq ' -f "$SYNC_SCRIPT"' scripts/deploy.sh || grep -Fq ' -f "$VERIFY_SCRIPT"' scripts/deploy.sh; then
  fail "deploy.sh still contains unsupported lftp -f script execution"
fi
grep -Fq 'declare -A REMOTE_DIRS=()' scripts/deploy.sh \
  || fail "deploy.sh is not tracking directories present in the live snapshot"
grep -Fq 'ensure_sync_remote_dir()' scripts/deploy.sh \
  || fail "deploy.sh is missing safe remote-directory preparation"
grep -Fq 'SYNC_DIRS_READY["$rel_dir"]=1' scripts/deploy.sh \
  || fail "deploy.sh is not de-duplicating remote directory creation"
grep -Fq 'set cmd:fail-exit no' scripts/deploy.sh \
  || fail "deploy.sh does not tolerate FTP 550 only during best-effort directory creation"
ok "Checksum deployment and Termux/InfinityFree FTP handling are present"

grep -q 'trash_menu_number' database/schema.sql \
  || fail "database/schema.sql is missing trash-safe menu numbering"
grep -q 'trash_menu_number' database/migrations/20260901-product-menu-ordering.sql \
  || fail "Product menu-ordering migration is incomplete"
grep -q 'function ensure_product_ordering_schema' public/includes/product_ordering.php \
  || fail "Product ordering schema helper is missing"
grep -q 'ensure_product_ordering_schema' public/tadeo-admin/dashboard.php \
  || fail "Dashboard does not initialize the product-ordering upgrade path"
grep -q 'ensure_product_ordering_schema' public/tadeo-admin/products.php \
  || fail "Products page does not initialize the product-ordering upgrade path"
grep -q 'product_ordering_prepare_insert' public/tadeo-admin/product-create.php \
  || fail "Product create is not preserving strict 1..N ordering"
grep -q 'product_ordering_move_live' public/tadeo-admin/product-edit.php \
  || fail "Product edit is not preserving strict 1..N ordering"
grep -q 'product_ordering_trash' public/tadeo-admin/product-delete.php \
  || fail "Product trash does not release and compact menu numbering"
grep -q 'product_ordering_restore' public/tadeo-admin/product-restore.php \
  || fail "Product restore does not reinsert into strict menu numbering"
grep -q 'deleted_at IS NULL' public/tadeo-admin/product-toggle.php \
  || fail "Product toggle can target trashed products"
ok "Strict 1..N product ordering and trash-safe restore integration is present"

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
  git check-ignore -q .deploy-audit/ \
    || fail ".deploy-audit/ is not ignored by Git"

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

  ok "Private files and local deploy-audit artifacts are excluded from Git"
else
  echo "WARNING: .git metadata not found; Git tracking checks were skipped."
fi

echo "NOTE: Server-side includes/config.local.php and includes/recovery.local.php are intentionally preserved by deploy.sh."
echo "NOTE: Runtime product/category/trash images and uploads/.trash-cleanup-last-run are preserved by deploy.sh."
echo "===== Preflight passed ====="
