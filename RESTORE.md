# Bar Tadeo — Restore Guide

Ky dokument shpjegon si të rikthehet projekti Bar Tadeo në një host të ri ose pas humbjes së instalimit ekzistues.

## 1. Çfarë ruhet në GitHub

Repo përmban kodin e aplikacionit, asetet publike, imazhet e menusë që janë commit-uar, schema-n e databazës dhe seed-in e sanitizuar të menusë.

Elementet private nuk duhet të ruhen në GitHub:

- `.env`
- `public/includes/config.local.php`
- `public/includes/recovery.local.php`
- kredencialet FTP
- kredencialet e databazës
- Gmail SMTP App Password
- protected recovery email/code hash
- password-e admin ose password hash-e reale nga instalimi live
- password-i real i WiFi

`.gitignore` i projektit i përjashton `.env`, `config.local.php`, `recovery.local.php` dhe artifact-et lokale `.deploy-audit/`.

## 2. Kërkesat

Për rikthim duhen:

- PHP me PDO MySQL
- MySQL/MariaDB
- mbështetje GD për JPG/PNG/WEBP (`imagewebp`, `imagecreatefromjpeg`, `imagecreatefrompng`, `imagecreatefromwebp`)
- `stream_socket_client` + TLS për Gmail SMTP password recovery
- FTP/FTPS për hostin
- `git`
- `lftp` nëse përdoret `scripts/deploy.sh`

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

```bash
cat > .env <<'EOF'
FTP_HOST='ftpupload.net'
FTP_USER='YOUR_FTP_USER'
FTP_PASS='YOUR_FTP_PASSWORD'
EOF
```

Mos bëj commit `.env`.

## 5. Krijo `public/includes/config.local.php`

Ky file përmban vetëm konfigurimin privat të databazës. Përdor `public/includes/config.local.example.php` si template.

```php
<?php
declare(strict_types=1);

const DB_HOST = 'YOUR_DB_HOST';
const DB_PORT = 3306;
const DB_NAME = 'YOUR_DB_NAME';
const DB_USER = 'YOUR_DB_USER';
const DB_PASS = 'YOUR_DB_PASSWORD';
```

Mos bëj commit këtë file. `scripts/deploy.sh` e ruan kopjen ekzistuese të serverit gjatë deploy-eve normale.

## 6. Rikthe databazën

### Restore i plotë — mënyra e rekomanduar

Për rikthim 100% të aplikacionit përdor export-in final të plotë të databazës nga backup-i final.

Full DB backup duhet të rikthejë këto tabela:

- `admins`
- `login_attempts`
- `security_ip_guard`
- `password_reset_codes`
- `categories`
- `products`
- `settings`
- `visits`
- `image_trash`
- `image_detach_history`

Pas importit mund të ekzekutosh `database/schema.sql`; ai është ndërtuar të krijojë strukturat që mungojnë, të shtojë kolonat e njohura të runtime-it dhe të normalizojë produktet jashtë koshit në numërim strikt `1..N`.

`security_ip_guard` përmban vetëm hash-e të IP-ve dhe state të përkohshëm të mbrojtjes; nuk përmban IP raw.

### Schema e repo-s

`database/schema.sql` përmban strukturën aktuale të aplikacionit pa kredenciale ose të dhëna sensitive.

Për një instalim live ekzistues ku kërkohet vetëm Forgot Password, ekziston migration-i i fokusuar:

```text
database/migrations/20260831-password-recovery.sql
```

Për brute-force/IP guard ekziston migration-i:

```text
database/migrations/20260901-security-ip-guard.sql
```

Për numërimin strikt të produkteve dhe sjelljen trash-safe ekziston migration-i idempotent:

```text
database/migrations/20260901-product-menu-ordering.sql
```

Ky migration:

- siguron `deleted_at` në instalime të vjetra;
- shton `trash_menu_number`;
- lejon `menu_number = NULL` vetëm për produktet në kosh;
- ruan numrin e vjetër të produkteve në kosh;
- liron numrat e tyre;
- normalizon produktet jashtë koshit në `1..N` duke ruajtur rendin ekzistues.

Admin Products përdor edhe `public/includes/product_ordering.php`, i cili verifikon/upgrade-on kolonat e nevojshme dhe vetë-rregullon një instalim legacy para mutimeve të produkteve.

Guard-i krijon automatikisht `security_ip_guard` në runtime nëse tabela mungon. Password-recovery helper-i siguron gjithashtu strukturën bazë të `password_reset_codes`; migration-et dhe `database/schema.sql` mbeten burimi i plotë për schema-n dhe indexet.

### Fallback pa full DB backup

Nëse nuk ke full database export:

1. Krijo databazën bosh.
2. Importo `database/seed/tadeobar-menu.sql` për snapshot-in historik të menusë.
3. Importo `database/schema.sql`.
4. Krijo një admin me password hash të ri.
5. Hyr në Admin dhe vendos Settings + WiFi.
6. Rikrijo `public/includes/recovery.local.php` nga kopja private e konfigurimit dhe testo Forgot Password end-to-end.

`database/seed/tadeobar-menu.sql` është vetëm sanitized snapshot i 17 majit 2026 dhe jo menuja finale live.

### Krijimi i adminit në një restore pa full backup

```bash
php -r "echo password_hash('PASSWORD_I_RI', PASSWORD_DEFAULT), PHP_EOL;"
```

Pastaj përdor hash-in vetëm në databazën private:

```sql
INSERT INTO admins (username, password_hash, is_active)
VALUES ('admin', 'HASH_I_GJENERUAR', 1);
```

## 7. Konfigurimi privat i recovery

`public/tadeo-admin/recovery-setup.php` ishte faqe autentikuese vetëm për konfigurimin fillestar dhe është hequr pasi Forgot Password u verifikua me sukses në instalimin live.

Në një restore të ri, `public/includes/recovery.local.php` duhet të rikthehet ose të rikrijohet privatisht jashtë Git. Ai përmban SMTP secret-et dhe protected recovery config, ngarkohet automatikisht nga aplikacioni dhe është i përjashtuar nga Git. `public/includes/.htaccess` bllokon aksesin HTTP në gjithë dosjen `includes`.

Pas rikthimit të konfigurimit privat, verifiko Forgot Password end-to-end para se instalimi të konsiderohet i rikthyer plotësisht.

## 8. Password recovery

Rrjedha finale është:

1. Login → `Harrove password-in?`
2. Konfirmo `Vazhdo` pa shkruar email ose username.
3. Gjenerohet kod random 6-shifror.
4. I njëjti kod dërgohet te recovery destination-et aktive.
5. Kodi skadon pas 10 minutash dhe ruhet vetëm si hash.
6. Pas verifikimit caktohet password-i i ri.

Mbrojtjet përfshijnë CSRF, rate limiting, maksimum 10 tentativa për kod, single-use reset code, resend cooldown, quota të dërgesave të suksesshme dhe revokim të sesioneve të vjetra pas reset-it.

## 9. Brute-force / IP guard

Login-i ka dy nivele mbrojtjeje server-side:

- 5 tentativa të dështuara për të njëjtin account të zgjidhur + IP brenda 10 minutave aktivizojnë soft guard-in;
- tentativa me kredenciale të pavlefshme vazhdon të regjistrohet edhe gjatë soft guard-it, ndërsa kredencialet korrekte nuk rrisin hard counter-in;
- 15 login-e të dështuara nga e njëjta IP brenda dritares 24-orëshe aktivizojnë hard block 24 orë;
- counter-i i hard guard është IP-only, pra ndryshimi i identifier-it nuk e anashkalon;
- login i suksesshëm reseton counter-in e hard guard;
- ruhet vetëm SHA-256 hash i IP-së, jo IP raw;
- IP e bllokuar merr përgjigje neutrale `404 Not Found` dhe nuk i shfaqet arsyeja, pragu ose koha e bllokimit;
- IP-të e tjera nuk preken.

Guard-i ngarkohet nga `public/includes/db.php`, prandaj bllokimi aplikohet në route-t dinamike publike, API dhe admin/recovery që përdorin databazën.

## 10. Rikthe imazhet

Repo përmban imazhet e commit-uara në:

```text
public/uploads/products/
public/uploads/categories/
```

Në një host krejt të ri ngarkoji manualisht një herë, sepse deploy script-i i përjashton upload-et runtime nga mirror-i normal.

## 11. Deploy kodin

Pasi `.env` dhe konfigurimi i serverit janë gati:

```bash
chmod +x scripts/deploy.sh
./scripts/deploy.sh
```

Script-i ngarkon `public/ -> /htdocs/` dhe ruan:

- product/category uploads
- trash uploads
- `includes/config.local.php`
- `includes/recovery.local.php` të serverit

Kjo do të thotë që konfigurimet private të serverit nuk fshihen nga deploy-et pasuese.

## 12. Kontrollo `.htaccess`

Pas restore kontrollo `/htdocs/.htaccess` dhe `/htdocs/includes/.htaccess`. Dosja `includes` duhet të ketë `Require all denied`.

## 13. Testet bazë pas restore/deploy

1. Hap menunë publike.
2. Kontrollo kategoritë, produktet dhe imazhet.
3. Kontrollo Shqip / English dhe çmimet.
4. Kontrollo WiFi section dhe QR.
5. Hyr te `/tadeo-admin/`.
6. Kontrollo dashboard, Products, Categories, Images, WiFi, Analytics dhe Settings.
7. Në Products verifiko që numrat jashtë koshit janë strikt `1..N`.
8. Testo një produkt prove: çoje në kosh dhe verifiko që numrat pas tij kompaktohen pa gap; riktheje dhe verifiko që futet përsëri në rend pa duplicate.
9. Verifiko që `recovery.local.php` është rikthyer privatisht dhe nuk është tracked në Git.
10. Testo Forgot Password dhe konfirmo marrjen e kodit 6-shifror.
11. Testo një kod të gabuar në mënyrë të kontrolluar dhe pastaj reset-in real të password-it.
12. Verifiko që login me username dhe email-in e verifikuar funksionon, ndërsa protected recovery email nuk përdoret si login.
13. Verifiko IP guard me dy IP/network-e të ndryshme vetëm nëse testi mund të bëhet pa bllokuar rrjetin kryesor të administrimit.
14. Hap `menu-audit.php` dhe kontrollo problemet kritike.
15. Testo error page dinamike.
16. Testo upload-in vetëm kur ke backup.

## 14. Cleanup

Cleanup automatik dhe manual fshijnë vetëm produkte/imazhe që kanë kaluar mbi 30 ditë në kosh.

## 15. Backup final

Mbaj jashtë GitHub:

- full database export live
- `public/includes/config.local.php`
- `public/includes/recovery.local.php`
- `.env` / kredencialet FTP
- `public/uploads/products/`
- `public/uploads/categories/`
- trash uploads nëse dëshiron rikthimin e historikut

`recovery.local.php` është backup-i autoritativ për Gmail App Password, protected recovery email dhe hash-in e kodit privat.

## 16. Verifikim final

Restore konsiderohet i përfunduar vetëm kur:

- menuja publike punon
- admin login punon
- databaza lidhet pa gabime
- produktet jashtë koshit kanë numërim strikt `1..N`
- trash/restore ruajnë invariantin e numërimit
- imazhet shfaqen
- konfigurimi privat i recovery është rikthyer
- Forgot Password punon end-to-end
- IP guard është verifikuar pa ndikuar IP të tjera
- `menu-audit.php` nuk raporton probleme kritike
- private local files nuk janë tracked në Git

Kontrollo me:

```bash
git status --short
git check-ignore .env public/includes/config.local.php public/includes/recovery.local.php .deploy-audit/
```
