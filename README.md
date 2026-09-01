# Bar Tadeo

Digital menu and WiFi website for Bar Tadeo in Durrës.

## Public site

- Production URL: `https://tadeobar.gt.tc/`
- Public entry point: `public/index.php`
- Admin panel: `/tadeo-admin/`
- Languages: Albanian and English
- Currency: ALL

## Login brute-force protection

Admin login has two server-side protection layers:

- soft guard: 5 failed attempts for the same username + IP within 10 minutes
- hard IP guard: 15 failed login attempts from the same IP within a 24-hour counter window trigger a 24-hour block
- the hard counter is independent of username, so rotating usernames does not bypass it
- a successful login resets the hard counter for that IP
- only a SHA-256 IP hash is stored; the raw IP is not stored in the guard table
- an actively blocked IP is denied across the dynamic Bar Tadeo application, including the public menu/API and admin/recovery routes
- other IP addresses remain unaffected
- client-facing HTML/JavaScript does not expose the thresholds or block duration
- the ordinary soft-limit response is intentionally indistinguishable from invalid credentials
- a hard-blocked request receives a generic `404 Not Found` response without `Retry-After` or a lockout explanation

The hard guard state is stored in `security_ip_guard`. Its schema is present in `database/schema.sql`, and the focused migration is:

```text
database/migrations/20260901-security-ip-guard.sql
```

`public/includes/security_guard.php` also creates the table automatically if an existing live installation has not run the migration yet.

## Admin recovery

The admin login includes a Gmail SMTP password-recovery flow:

- no email or username is entered on the Forgot Password page
- one random 6-digit code is sent to the configured recovery destinations
- the code expires after 10 minutes and is stored only as a hash
- the editable recovery email is managed from Admin → Settings
- the protected recovery email is read-only in the panel and can only be enabled/disabled with a private protection code
- Gmail SMTP credentials and protected recovery secrets stay in `public/includes/recovery.local.php` and are never committed

Initial live configuration is performed from the authenticated one-time page:

```text
/tadeo-admin/recovery-setup.php
```

The setup page requires the current admin password, tests Gmail SMTP/TLS/authentication, sends a test message to both recovery destinations, ensures the password-reset DB table exists, hashes the private protection code, and only then creates `recovery.local.php`. Once completed, the setup page refuses to overwrite the existing recovery configuration and can be deleted after live verification.

Database credentials remain separate in:

```text
public/includes/config.local.php
```

A non-secret DB template is available at:

```text
public/includes/config.local.example.php
```

For manual/fallback database upgrades, the focused idempotent recovery migration remains available at:

```text
database/migrations/20260831-password-recovery.sql
```

## Product numbering and trash

All products outside the trash use a strict `1..N` `menu_number` sequence.

- creating a product at an existing position shifts later products automatically
- moving a product to another number shifts only the affected range
- sending a product to trash stores its former position in `trash_menu_number`, releases `menu_number`, and compacts the live sequence
- restoring a product inserts it back at its preserved position when valid, shifts later products, and restores it as hidden
- trashed products therefore never reserve a live menu number

The runtime helper is:

```text
public/includes/product_ordering.php
```

The schema is present in `database/schema.sql`, and the focused idempotent migration for existing installations is:

```text
database/migrations/20260901-product-menu-ordering.sql
```

Admin product pages also verify/upgrade the required columns and normalize legacy live numbering before mutations, so old installations are self-healing when product administration is opened.

## Hosting

The contents of `public/` are deployed to InfinityFree under:

```text
/htdocs
```

Before deployment, run:

```bash
bash scripts/preflight.sh
```

The preflight checks required commands, FTP configuration, critical recovery/security files, strict product-ordering integration, PHP syntax, checksum-deploy requirements, deploy-script shell syntax, and Git exclusions for private files. It intentionally does not require local MySQL credentials for an existing live installation.

Deployment is handled by:

```text
scripts/deploy.sh
```

`deploy.sh` uses content-based SHA-256 synchronization instead of relying on FTP timestamps. It first downloads a temporary snapshot of deployable files from `/htdocs`, including hidden files such as `.htaccess`, then compares every deployable host file byte-for-byte with `public/`.

- identical SHA-256: the file is left untouched
- missing or different SHA-256: only that file is uploaded
- remote deployable file absent from `public/`: only that file is deleted
- every uploaded file is downloaded again and SHA-256 verified after transfer
- `assets/images/categories/all.webp` uses its InfinityFree temporary-name workaround only when its content actually differs
- `public/includes/config.local.php`, `public/includes/recovery.local.php`, runtime product/category/trash images, and `uploads/.trash-cleanup-last-run` are excluded from synchronization and preserved on the server

The deploy output reports `SAME`, `UPLOAD`, and `DELETE` counts before making changes. If host and repo deployable files are already identical, deploy exits without uploading or deleting anything.

For a brand-new server/restore, `config.local.php` must still be created on that server before the application can connect to MySQL.

## Project structure

```text
public/                 Public PHP application and assets
public/tadeo-admin/     Admin panel + Forgot Password + one-time recovery setup
public/includes/        Shared PHP helpers, DB/security config loader, recovery logic, ordering logic and SMTP client
database/schema.sql     Complete non-secret application schema
database/migrations/    Focused upgrade scripts for existing installations
database/seed/          Sanitized historical menu snapshot
scripts/preflight.sh    Final local checks before deployment
scripts/deploy.sh       FTPS SHA-256 checksum deployment script
RESTORE.md              Full restore/recovery guide
```

## Database recovery

`database/schema.sql` defines the current application structure without storing real admin accounts, password hashes, reset codes, WiFi passwords, visits, trash records, IP guard runtime state, or credentials.

`database/seed/tadeobar-menu.sql` is a sanitized menu snapshot generated on May 17, 2026. It is a fallback for menu recovery, not the authoritative final live database export.

For complete recovery, keep a private full database backup outside GitHub and follow `RESTORE.md`.
