# Deployment Guide — LAN / XAMPP / Apache / HTTPS

Target: one Windows (or Linux) server on the LGU LAN. No internet required at runtime.

## 1. Prerequisites
- XAMPP with **PHP 8.3+** (Apache + MariaDB/MySQL) — https://www.apachefriends.org
- Composer 2.x
- (Optional, recommended) Redis for cache/queue — on Windows use Memurai or keep the
  default `database`/`file` drivers set in `.env.example` (works out of the box).
- Git (to clone) or the release ZIP.

## 2. Install
```bash
cd C:\xampp\htdocs
git clone <repo> lms && cd lms
composer install --no-dev --optimize-autoloader
copy .env.example .env
php artisan key:generate
```

Edit `.env` (LAN values):
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://lms.alicia.local
DB_CONNECTION=mysql
DB_HOST=127.0.0.1  DB_DATABASE=lms_alicia  DB_USERNAME=lms_app  DB_PASSWORD=<strong>
SESSION_DRIVER=database  SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database        # or redis
CACHE_STORE=database             # or redis
MAIL_MAILER=smtp  MAIL_HOST=<lan-smtp>  MAIL_PORT=25 ...
```

Create DB + user (phpMyAdmin or):
```sql
CREATE DATABASE lms_alicia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'lms_app'@'localhost' IDENTIFIED BY '<strong>';
GRANT SELECT,INSERT,UPDATE,DELETE ON lms_alicia.* TO 'lms_app'@'localhost';
-- hardening (ADR-008): after first migrate, optionally REVOKE DELETE ON audit tables.
```

Migrate + seed:
```bash
php artisan migrate --force
php artisan db:seed --force                 # roles, permissions, leave types, holidays, settings, admin account
php artisan db:seed --class=DemoDataSeeder  # OPTIONAL demo employees/requests (never on live data)
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## 3. Apache VirtualHost + HTTPS (self-signed)
Generate cert (Windows: use the bundled `deploy/make-cert.bat`; Linux: `deploy/make-cert.sh`):
```
deploy/make-cert.sh lms.alicia.local   # outputs deploy/certs/lms.crt + lms.key
```
`httpd-vhosts.conf`:
```apache
<VirtualHost *:80>
  ServerName lms.alicia.local
  Redirect permanent / https://lms.alicia.local/
</VirtualHost>
<VirtualHost *:443>
  ServerName lms.alicia.local
  DocumentRoot "C:/xampp/htdocs/lms/public"
  SSLEngine on
  SSLCertificateFile "C:/xampp/htdocs/lms/deploy/certs/lms.crt"
  SSLCertificateKeyFile "C:/xampp/htdocs/lms/deploy/certs/lms.key"
  <Directory "C:/xampp/htdocs/lms/public">
    AllowOverride All
    Require ip 192.168.1.0/24 127.0.0.1   # LAN-only at the web tier too
  </Directory>
</VirtualHost>
```
Enable `mod_ssl`/`mod_rewrite`, add `192.168.1.x lms.alicia.local` to clients' hosts file or LAN DNS,
import `lms.crt` into client trust stores (avoids browser warnings).

## 4. Workers & scheduler
- Queue: `php artisan queue:work --tries=3` — install as a service
  (Windows: `deploy/queue-worker.xml` for NSSM / Task Scheduler at logon; Linux: `deploy/lms-queue.service`).
- Scheduler: run `php artisan schedule:run` every minute
  (Windows Task Scheduler / cron: `* * * * * php /path/artisan schedule:run`).

## 5. First login & bootstrap
Seeded Super Admin: `superadmin@alicia.gov.ph` (password printed by seeder / see AdminGuide).
1. Log in from the server itself (127.0.0.1 is pre-authorized as a device).
2. Register the LAN workstations under **Admin → Authorized Devices**.
3. Enable device enforcement in **Admin → System Settings** (`security.device_enforcement`).
4. Change the seeded password; configure SMTP; send yourself a test OTP.

## 6. Backup & restore
- `php artisan lms:backup` → timestamped `storage/app/backups/lms_YYYYmmdd_HHMMSS.zip`
  (DB dump via mysqldump if available, else PHP fallback dump + uploaded documents).
- Scheduled daily at 01:00. Copy backups off-host.
- Restore: `php artisan lms:restore <zip>` (interactive confirmation) or manual: import SQL
  dump, unzip `storage/app/private` files back.

## 7. Hardening checklist
- [ ] `APP_DEBUG=false`, unique `APP_KEY`
- [ ] MySQL bound to 127.0.0.1; app DB user without DDL rights
- [ ] Apache `Require ip` LAN ranges only; directory listing off
- [ ] Firewall: allow 443 inbound from LAN only; block 80 except redirect; 3306/6379 local only
- [ ] Device enforcement ON; default seeder passwords rotated
- [ ] Backups verified restorable monthly
- [ ] OS updates via WSUS/apt mirror per LGU policy


## 8. Offline (no-internet) operation

The system is designed to run with **zero internet access** at runtime:
- All frontend libraries (Bootstrap 5, Bootstrap Icons + fonts, Chart.js, SweetAlert2,
  Swagger UI) are vendored under `public/vendor/` and served locally — no CDN.
- No page references any external host (verified); the app makes no outbound HTTP calls.
- OTP and notification emails go to the **LAN mail server** (`MAIL_MAILER=smtp`) or to the
  log (`MAIL_MAILER=log`) — neither needs the internet.
- The unused Laravel welcome page (which pulled Google Fonts/CDN) has been removed.

**One-time setup steps that DO need internet** (run once, on a machine that has it):
`composer install` and (if you rebuild assets) `npm install`. After that, copy the whole
`lms/` folder — including `vendor/` and `public/vendor/` — to the offline LAN server and it
runs without any connection. To verify offline: disconnect the network and confirm the login
page, dashboards, charts, icons and `/api/documentation` all render normally.
