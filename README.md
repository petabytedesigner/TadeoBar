# Bar Tadeo

Digital menu and WiFi website for Bar Tadeo in Durrës.

## Public site

- Production URL: `https://tadeobar.gt.tc/`
- Public entry point: `public/index.php`
- Admin panel: `/tadeo-admin/`
- Languages: Albanian and English
- Currency: ALL

## Admin recovery

The admin login includes a Gmail SMTP password-recovery flow:

- no email or username is entered on the Forgot Password page
- one random 6-digit code is sent to the configured recovery destinations
- the code expires after 10 minutes and is stored only as a hash
- the editable recovery email is managed from Admin → Settings
- the protected recovery email is read-only in the panel and can only be enabled/disabled with a private protection code
- Gmail SMTP credentials, Google App Password and protected recovery configuration stay in `public/includes/config.local.php` and are never committed

A non-secret template is available at:

```text
public/includes/config.local.example.php
```

Copy it to `config.local.php` locally and replace the placeholders with the private server values. Never commit the real file.

For an existing live database, the password-recovery feature can be enabled with the focused idempotent migration:

```text
database/migrations/20260831-password-recovery.sql
```

The migration only creates the password-reset table and the non-sensitive protected-recovery status setting; it does not modify menu products or categories.

## Hosting

The contents of `public/` are deployed to InfinityFree under:

```text
/htdocs
```

Deployment is handled by:

```text
scripts/deploy.sh
```

The deploy script requires a local `.env` with FTP credentials and `public/includes/config.local.php` with the database/SMTP configuration. Both files are intentionally excluded from Git.

## Project structure

```text
public/                 Public PHP application and assets
public/tadeo-admin/     Admin panel + Forgot Password flow
public/includes/        Shared PHP helpers, recovery logic and SMTP client
database/schema.sql     Complete non-secret application schema
database/migrations/   Focused upgrade scripts for existing installations
database/seed/          Sanitized historical menu snapshot
scripts/deploy.sh       FTPS deployment script
RESTORE.md              Full restore/recovery guide
```

## Database recovery

`database/schema.sql` defines the current application structure without storing real admin accounts, password hashes, reset codes, WiFi passwords, visits, trash records, or credentials.

`database/seed/tadeobar-menu.sql` is a sanitized menu snapshot generated on May 17, 2026. It is a fallback for menu recovery, not the authoritative final live database export.

For complete recovery, keep a private full database backup outside GitHub and follow `RESTORE.md`.
