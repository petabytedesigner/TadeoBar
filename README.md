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
database/seed/          Sanitized menu seed
scripts/deploy.sh       FTPS deployment script
RESTORE.md              Full restore/recovery guide
```

## Recovery

See `RESTORE.md` before rebuilding the site on a new device or host. The sanitized SQL seed is not a complete database backup; keep a private full database export for final recovery.
