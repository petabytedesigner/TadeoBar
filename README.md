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

For manual/fallback database upgrades, the focused idempotent migration remains available at:

```text
database/migrations/20260831-password-recovery.sql
```

## Hosting

The contents of `public/` are deployed to InfinityFree under:

```text
/htdocs
```

Deployment is handled by:

```text
scripts/deploy.sh
```

The deploy script requires a local `.env` with FTP credentials and `public/includes/config.local.php` with the database configuration. `recovery.local.php` is generated on the server by the one-time recovery setup and is preserved by later deploys. All private files are excluded from Git.

## Project structure

```text
public/                 Public PHP application and assets
public/tadeo-admin/     Admin panel + Forgot Password + one-time recovery setup
public/includes/        Shared PHP helpers, DB config loader, recovery logic and SMTP client
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
