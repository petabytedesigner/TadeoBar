# Bar Tadeo

Digital menu and WiFi website for Bar Tadeo in Durrës.

## Public site

- Production URL: `https://tadeobar.gt.tc/`
- Public entry point: `public/index.php`
- Admin panel: `/tadeo-admin/`
- Languages: Albanian and English
- Currency: ALL

## Hosting

The contents of `public/` are deployed to InfinityFree under:

```text
/htdocs
```

Deployment is handled by:

```text
scripts/deploy.sh
```

The deploy script requires a local `.env` with FTP credentials and `public/includes/config.local.php` with the database configuration. Both files are intentionally excluded from Git.

## Project structure

```text
public/                 Public PHP application and assets
public/tadeo-admin/     Admin panel
public/includes/        Shared PHP helpers and configuration loader
public/uploads/         Product/category images and trash folders
database/schema.sql     Complete non-secret application schema
database/seed/          Sanitized historical menu snapshot
scripts/deploy.sh       FTPS deployment script
RESTORE.md              Full restore/recovery guide
```

## Database recovery

`database/schema.sql` defines the current application structure without storing real admin accounts, password hashes, WiFi passwords, visits, trash records, or credentials.

`database/seed/tadeobar-menu.sql` is a sanitized menu snapshot generated on May 17, 2026. It is a fallback for menu recovery, not the authoritative final live database export.

For complete recovery, keep a private full database backup outside GitHub and follow `RESTORE.md`.
