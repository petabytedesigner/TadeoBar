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

command -v php >/dev/null 2>&1 || fail "php is not installed"
command -v lftp >/dev/null 2>&1 || fail "lftp is not installed"
ok "Required commands are available"

[ -f ".env" ] || fail ".env file not found"
[ -f "public/includes/config.local.php" ] || fail "public/includes/config.local.php not found"
ok "Private deployment files exist"

set -a
# shellcheck disable=SC1091
source .env
set +a

[ -n "${FTP_HOST:-}" ] || fail "FTP_HOST is missing in .env"
[ -n "${FTP_USER:-}" ] || fail "FTP_USER is missing in .env"
[ -n "${FTP_PASS:-}" ] || fail "FTP_PASS is missing in .env"
ok "FTP configuration is present"

php -l public/includes/config.local.php >/dev/null || fail "config.local.php has invalid PHP syntax"

php -r '
require "public/includes/config.local.php";
foreach (["DB_HOST", "DB_PORT", "DB_NAME", "DB_USER", "DB_PASS"] as $name) {
    if (!defined($name)) {
        fwrite(STDERR, "Missing DB constant: {$name}\n");
        exit(1);
    }
    $value = trim((string) constant($name));
    if ($value === "" || str_contains($value, "YOUR_")) {
        fwrite(STDERR, "Invalid DB value for: {$name}\n");
        exit(1);
    }
}
' || fail "Database configuration is incomplete"
ok "Database configuration is complete"

required_files=(
  "public/includes/.htaccess"
  "public/includes/db.php"
  "public/includes/auth.php"
  "public/includes/csrf.php"
  "public/includes/password_recovery.php"
  "public/includes/smtp_mailer.php"
  "public/tadeo-admin/login.php"
  "public/tadeo-admin/forgot-password.php"
  "public/tadeo-admin/verify-reset-code.php"
  "public/tadeo-admin/reset-password.php"
  "public/tadeo-admin/recovery-setup.php"
  "database/schema.sql"
  "database/migrations/20260831-password-recovery.sql"
)

for file in "${required_files[@]}"; do
  [ -f "$file" ] || fail "Required file missing: $file"
done
ok "Required application and recovery files exist"

grep -q 'Require all denied' public/includes/.htaccess \
  || fail "public/includes/.htaccess does not deny direct access"
grep -q 'password_reset_codes' database/schema.sql \
  || fail "database/schema.sql is missing password_reset_codes"
grep -q 'password_reset_codes' database/migrations/20260831-password-recovery.sql \
  || fail "Password recovery migration is incomplete"
ok "Security and database recovery structure is present"

php_files_checked=0
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null || fail "PHP syntax error: $file"
  php_files_checked=$((php_files_checked + 1))
done < <(find public -type f -name '*.php' -print0)
ok "PHP syntax passed for ${php_files_checked} files"

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

echo "===== Preflight passed ====="
