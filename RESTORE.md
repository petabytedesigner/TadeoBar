# Bar Tadeo — Restore Guide

Ky dokument shpjegon si të rikthehet projekti Bar Tadeo në një host të ri ose pas humbjes së instalimit ekzistues.

## 1. Çfarë ruhet në GitHub

Repo përmban kodin e aplikacionit, asetet publike, imazhet e menusë që janë commit-uar, schema-n e databazës dhe seed-in e sanitizuar të menusë.

Elementet private nuk duhet të ruhen në GitHub:

- `.env`
- `public/includes/config.local.php`
- kredencialet FTP
- kredencialet e databazës
- Gmail SMTP App Password
- protected recovery email/code hash
- password-e admin ose password hash-e reale nga instalimi live
- password-i real i WiFi

`.gitignore` i projektit i përjashton `.env` dhe `public/includes/config.local.php`.

## 2. Kërkesat

Për rikthim duhen:

- PHP me PDO MySQL
- MySQL/MariaDB
- mbështetje GD për JPG/PNG/WEBP (`imagewebp`, `imagecreatefromjpeg`, `imagecreatefrompng`, `imagecreatefromwebp`)
- `stream_socket_client` + TLS për Gmail SMTP password recovery
- FTP/FTPS për hostin
- `git`
- `lftp` nëse përdoret `scripts/deploy.sh`

Në Termux zakonisht projekti klonohet dhe deploy bëhet nga i njëjti workflow si zhvillimi normal.

## 3. Klono repo-n

```bash
git clone https://github.com/petabytedesigner/TadeoBar.git
cd TadeoBar
```

Nëse repo ekziston lokalisht:

```bash
git pull origin main
```

## 4. Krijo `.env` vetëm lokalisht

`scripts/deploy.sh` lexon kredencialet FTP nga `.env` në root të projektit.

Shembull:

```bash
cat > .env <<'EOF'
FTP_HOST='ftpupload.net'
FTP_USER='YOUR_FTP_USER'
FTP_PASS='YOUR_FTP_PASSWORD'
EOF
```

Mos bëj commit `.env`.

Nëse password-i përmban karaktere speciale, mbaje vlerën të quotuar si më sipër.

## 5. Krijo `public/includes/config.local.php`

Aplikacioni e merr lidhjen me databazën dhe sekretet e Gmail password recovery nga ky file. Ai nuk ruhet në GitHub dhe duhet krijuar në çdo instalim të ri.

Shembull:

```php
<?php
declare(strict_types=1);

const DB_HOST = 'YOUR_DB_HOST';
const DB_PORT = 3306;
const DB_NAME = 'YOUR_DB_NAME';
const DB_USER = 'YOUR_DB_USER';
const DB_PASS = 'YOUR_DB_PASSWORD';

const SMTP_HOST = 'smtp.gmail.com';
const SMTP_PORT = 587;
const SMTP_USERNAME = 'YOUR_GMAIL_ADDRESS';
const SMTP_PASSWORD = 'YOUR_GOOGLE_APP_PASSWORD';
const SMTP_FROM_EMAIL = 'YOUR_GMAIL_ADDRESS';
const SMTP_FROM_NAME = 'Bar Tadeo';

const PROTECTED_RECOVERY_EMAIL = 'YOUR_PROTECTED_RECOVERY_EMAIL';
const PROTECTED_RECOVERY_CODE_HASH = 'YOUR_PASSWORD_HASH';
```

`SMTP_PASSWORD` duhet të jetë Google App Password, jo password-i normal i Gmail-it.

Për kodin privat që aktivizon/çaktivizon protected recovery email, ruaj vetëm hash-in. Gjeneroje lokalisht:

```bash
php -r "echo password_hash('YOUR_PROTECTED_CODE', PASSWORD_DEFAULT), PHP_EOL;"
```

Kopjo rezultatin te `PROTECTED_RECOVERY_CODE_HASH`. Mos ruaj kodin real në repo.

Email-i i mbrojtur ndryshohet vetëm nga `config.local.php`; paneli nuk ka fushë për ta modifikuar. Email-i tjetër i rikuperimit vendoset nga Admin → Cilësimet.

Mos bëj commit këtë file.

**E rëndësishme:** krijoje `config.local.php` përpara deploy-it. `scripts/deploy.sh` ndalon nëse ky file mungon dhe nuk nis FTP mirror-in pa konfigurimin lokal të databazës.

## 6. Rikthe databazën

### Restore i plotë — mënyra e rekomanduar

Për rikthim 100% të aplikacionit përdor export-in final të plotë të databazës nga backup-i final.

Full DB backup duhet të rikthejë këto tabela:

- `admins`
- `login_attempts`
- `password_reset_codes`
- `categories`
- `products`
- `settings`
- `visits`
- `image_trash`
- `image_detach_history`

Importoje export-in e plotë në databazën e re me phpMyAdmin ose mjetin e hostit.

Pas importit të full backup mund të ekzekutosh edhe `database/schema.sql`; script-i është ndërtuar që të krijojë vetëm strukturat që mungojnë dhe të shtojë kolonat e njohura të runtime-it kur mungojnë.

### Schema e repo-s: `database/schema.sql`

Repo përmban:

```text
database/schema.sql
```

Ky është schema i plotë i aplikacionit dhe përmban strukturën e tabelave që përdor kodi aktual:

- `categories`
- `products`
- `admins`
- `login_attempts`
- `password_reset_codes`
- `settings`
- `visits`
- `image_trash`
- `image_detach_history`

Schema përfshin gjithashtu kolonat që versionet e vjetra mund t'i krijonin gjatë runtime-it, përfshirë:

- `categories.icon_image_path`
- `products.deleted_at`

Ai nuk përmban llogari admin, password hash real, WiFi password, reset codes, vizita, trash records ose kredenciale.

### Fallback pa full DB backup

Nëse nuk ke full database export dhe duhet të rindërtosh instalimin vetëm nga repo:

1. Krijo një databazë bosh.
2. Importo `database/seed/tadeobar-menu.sql` për kategoritë dhe produktet e snapshot-it të ruajtur.
3. Importo `database/schema.sql` pas seed-it. Kjo krijon tabelat e tjera dhe shton `products.deleted_at` nëse seed-i i vjetër nuk e ka.
4. Krijo llogarinë admin me një password hash të ri.
5. Hyr në Admin dhe vendos Settings + WiFi + recovery email sipas instalimit real.

Rendi **seed → schema** është i qëllimshëm për fallback restore, sepse menu seed-i i vjetër rikrijon tabelat `categories` dhe `products`; schema pastaj i sjell ato në strukturën që pret kodi aktual.

### `database/seed/tadeobar-menu.sql`

Ky file është një **sanitized menu snapshot i gjeneruar më 17 maj 2026**, jo export-i final live i menusë.

Ai përmban vetëm kategoritë dhe produktet e snapshot-it dhe nuk përmban admin, login attempts, settings, visits, image trash, WiFi password ose kredenciale.

Prandaj përdoret vetëm si fallback. Kur të bëhet backup-i final live, export-i final i DB-së është burimi autoritativ për restore të plotë.

### Krijimi i adminit në një restore pa full backup

Mos ruaj password admin në tekst të thjeshtë në repo. Gjenero hash lokalisht me PHP:

```bash
php -r "echo password_hash('PASSWORD_I_RI', PASSWORD_DEFAULT), PHP_EOL;"
```

Pastaj përdor hash-in e prodhuar vetëm në databazën private:

```sql
INSERT INTO admins (username, password_hash, is_active)
VALUES ('admin', 'HASH_I_GJENERUAR', 1);
```

Ndrysho menjëherë username/password nga Admin → Cilësimet nëse ke përdorur vlera të përkohshme gjatë restore-it.

## 7. Password recovery

Rrjedha e rikuperimit është:

1. Login → `Harrove password-in?`
2. Përdoruesi konfirmon `Vazhdo` pa shkruar email ose username.
3. Gjenerohet një kod random 6-shifror.
4. I njëjti kod dërgohet veçmas te protected recovery email (kur është aktiv) dhe te recovery email i vendosur nga paneli.
5. Kodi skadon pas 10 minutash dhe ruhet në DB vetëm si hash.
6. Pas verifikimit vendoset password-i i ri.

Mbrojtjet kryesore:

- CSRF në çdo POST të recovery flow
- maksimumi 3 kërkesa kodi për IP brenda 15 minutave
- minimumi 60 sekonda ndërmjet kërkesave nga e njëjta IP
- maksimumi 5 tentativa për kodin 6-shifror
- kodi përdoret vetëm një herë
- protected recovery email nuk editohet nga paneli
- aktivizimi/çaktivizimi i protected email kërkon kodin privat dhe bllokohet për 15 minuta pas 5 gabimeve në të njëjtën admin session
- Gmail App Password dhe protected code hash ruhen vetëm në `config.local.php`

Për instalimin ekzistues, ekzekuto `database/schema.sql` përpara testimit të Forgot Password që tabela `password_reset_codes` të ekzistojë.

## 8. Rikthe imazhet

Repo përmban imazhet e commit-uara në:

```text
public/uploads/products/
public/uploads/categories/
```

Këto duhen ngarkuar në host të ri te:

```text
/htdocs/uploads/products/
/htdocs/uploads/categories/
```

### E rëndësishme për deploy-in e parë

`scripts/deploy.sh` i përjashton imazhet e produkteve, kategorive dhe trash-it nga mirror-i normal që të mos fshihen upload-et e bëra nga admin paneli në server.

Prandaj, në një host krejt të ri, ngarko `uploads/products` dhe `uploads/categories` manualisht një herë përpara ose pas deploy-it të parë.

Shembull me `lftp`:

```bash
set -a
source .env
set +a

lftp -u "$FTP_USER","$FTP_PASS" "$FTP_HOST" <<'LFTP'
set cmd:fail-exit yes
set ftp:ssl-force true
set ftp:ssl-protect-data true
set ftp:passive-mode true
set ssl:verify-certificate yes
mirror -R --verbose public/uploads/products /htdocs/uploads/products
mirror -R --verbose public/uploads/categories /htdocs/uploads/categories
bye
LFTP
```

Folderët `uploads/trash/...` janë të dhëna runtime. Në restore normal mund të fillojnë bosh, përveç rastit kur po rikthen një backup të plotë të trash-it bashkë me tabelën `image_trash`.

## 9. Deploy kodin

Pasi `.env` dhe `config.local.php` janë gati:

```bash
chmod +x scripts/deploy.sh
./scripts/deploy.sh
```

Script-i ngarkon:

```text
public/ -> /htdocs/
```

Dhe ruan imazhet runtime të produkteve, kategorive dhe trash-it nga fshirja gjatë deploy-eve normale.

## 10. Kontrollo `.htaccess`

Pas restore kontrollo që `/htdocs/.htaccess` ekziston. Ai përmban:

- `DirectoryIndex index.php`
- error pages dinamike
- security headers
- cache headers për asetet statike

Mos e ekspozo ose ndrysho përmes URL-së publike; verifikoje nga repo/FTP.

## 11. Testet bazë pas restore

Bëji këto teste me radhë:

1. Hap menunë publike dhe kontrollo që ngarkohet pa error.
2. Kontrollo të gjitha kategoritë.
3. Kontrollo disa produkte nga kategori të ndryshme dhe imazhet e tyre.
4. Kontrollo Shqip / English.
5. Kontrollo çmimet.
6. Kontrollo WiFi section dhe QR.
7. Hyr te `/tadeo-admin/`.
8. Kontrollo dashboard-in.
9. Kontrollo Products, Categories, Images, WiFi, Analytics dhe Settings.
10. Vendos recovery email nga Settings dhe kontrollo protected recovery status.
11. Testo Forgot Password dhe konfirmo që i njëjti kod arrin në të dy recovery email-et aktive.
12. Testo ndryshimin e password-it me kodin 6-shifror.
13. Hap `menu-audit.php` dhe kontrollo që nuk ka gabime kritike ose file DB që mungojnë.
14. Testo një URL që nuk ekziston dhe verifiko error page dinamike.
15. Testo upload-in e një imazhi vetëm nëse restore-i është verifikuar dhe ke backup.

## 12. Kontrolli i uploads pas restore

Nga Admin → Audit Menu kontrollo:

- file DB që mungojnë në server
- file në server që nuk përdoren nga DB
- imazhe në kosh
- produkte në kosh
- raportet/dimensionet e imazheve
- madhësitë e imazheve
- file të rrezikshme në `uploads`

Audit-i është read-only dhe nuk duhet të ndryshojë databazën.

## 13. Cleanup

Cleanup automatik fshin vetëm produkte dhe imazhe që kanë kaluar më shumë se 30 ditë në kosh.

Në Admin → Paneli ekziston edhe `Run cleanup now`. Butoni manual përdor të njëjtin kufi 30-ditor; nuk fshin elemente më të reja.

## 14. Çfarë duhet të përmbajë backup-i final

Për restore të plotë mbaj jashtë GitHub një backup të sigurt me:

- full database export live
- `public/includes/config.local.php`
- Gmail SMTP App Password
- protected recovery email/code hash
- kredencialet FTP të ruajtura në mënyrë private
- `public/uploads/products/`
- `public/uploads/categories/`
- trash uploads + `image_trash` vetëm nëse kërkohet rikthim i historikut të koshit

GitHub mbetet burimi i kodit dhe schema-s, por nuk duhet të përdoret për ruajtjen e sekreteve.

## 15. Verifikim final

Restore konsiderohet i përfunduar vetëm kur:

- menuja publike punon
- admin login punon
- databaza lidhet pa gabime
- imazhet shfaqen
- Gmail SMTP password recovery punon
- `menu-audit.php` nuk raporton probleme kritike
- `config.local.php` dhe `.env` nuk janë tracked në Git

Kontrollo me:

```bash
git status --short
git check-ignore .env public/includes/config.local.php
```

Të dy file-t private duhet të jenë të injoruar nga Git.
