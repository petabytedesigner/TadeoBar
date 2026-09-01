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

## Hosting

The contents of `public/` are deployed to InfinityFree under:

```text
/htdocs
```

Before deployment, run:

```bash
bash scripts/preflight.sh
```

The preflight checks required commands, FTP configuration, critical recovery/security files, PHP syntax, and Git exclusions for private files. It intentionally does not require local MySQL credentials for an existing live installation.

Deployment is handled by:

```text
scripts/deploy.sh
```

`deploy.sh` automatically runs the same preflight before starting the FTPS mirror. It requires only a local `.env` with FTP credentials. Existing server-side `public/includes/config.local.php` and `public/includes/recovery.local.php` are excluded from the mirror and preserved on the server, along with runtime uploads. All private files remain excluded from Git.

For a brand-new server/restore, `config.local.php` must still be created on that server before the application can connect to MySQL.

## Project structure

```text
public/                 Public PHP application and assets
public/tadeo-admin/     Admin panel + Forgot Password + one-time recovery setup
public/includes/        Shared PHP helpers, DB/security config loader, recovery logic and SMTP client
database/schema.sql     Complete non-secret application schema
database/migrations/    Focused upgrade scripts for existing installations
database/seed/          Sanitized historical menu snapshot
scripts/preflight.sh    Final local checks before deployment
scripts/deploy.sh       FTPS deployment script
RESTORE.md              Full restore/recovery guide
```

## Database recovery

`database/schema.sql` defines the current application structure without storing real admin accounts, password hashes, reset codes, WiFi passwords, visits, trash records, IP guard runtime state, or credentials.

`database/seed/tadeobar-menu.sql` is a sanitized menu snapshot generated on May 17, 2026. It is a fallback for menu recovery, not the authoritative final live database export.

For complete recovery, keep a private full database backup outside GitHub and follow `RESTORE.md`.
