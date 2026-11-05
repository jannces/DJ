#!/usr/bin/env bash
# =============================================================================
#  LGU Alicia LMS - one-shot installer for an Ubuntu server / virtual machine
#
#  Tested on Ubuntu Server 24.04 LTS. Ubuntu 22.04 also works (it adds the
#  ondrej/php PPA, because 22.04's own PHP 8.1 is too old for Laravel 12).
#
#  Usage (from inside the project folder, normally /var/www/lms):
#
#      sudo bash deploy/ubuntu/install.sh                # standard install
#      sudo bash deploy/ubuntu/install.sh --demo         # + demo employees/accounts
#      sudo bash deploy/ubuntu/install.sh --ip 192.168.1.50 --hostname onealicialms.lan
#
#  What it does, in order:
#     1. installs Apache, PHP 8.3, MariaDB, Composer and tools from apt
#     2. creates the database `lms_alicia` and a database user `lms`
#     3. writes .env (random DB password, random super-admin password)
#     4. composer install, migrations, seeders, storage link
#     5. self-signed HTTPS certificate for the hostname AND the server's IP
#     6. Apache site (HTTP -> HTTPS, LAN-only), PHP upload limits
#     7. queue worker (systemd) and scheduler (cron)
#     8. firewall (ufw): SSH, HTTP, HTTPS
#     9. the `lms` helper command: sudo lms status | start | stop | otp | ...
#
#  It is safe to run again: an existing .env, database and accounts are kept.
#  Passwords are printed at the end and saved to /root/lms-install-info.txt.
# =============================================================================
set -euo pipefail

# ----------------------------------------------------------------- arguments --
DEMO=0
SITE="onealicialms.lan"
SERVER_IP=""
FIREWALL=1
DB_NAME="lms_alicia"
DB_USER="lms"

while [ $# -gt 0 ]; do
  case "$1" in
    --demo)        DEMO=1 ;;
    --hostname)    SITE="${2:?--hostname needs a value}"; shift ;;
    --ip)          SERVER_IP="${2:?--ip needs a value}"; shift ;;
    --no-firewall) FIREWALL=0 ;;
    -h|--help)     sed -n '2,30p' "$0"; exit 0 ;;
    *) echo "Unknown option: $1  (try --help)" >&2; exit 2 ;;
  esac
  shift
done

# --------------------------------------------------------------- helpers -----
step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[32m[ok]\033[0m %s\n' "$*"; }
warn() { printf '    \033[33m[!]\033[0m %s\n' "$*"; }
die()  { printf '\n\033[31m[X] %s\033[0m\n' "$*" >&2; exit 1; }

HAS_SYSTEMD=0
[ -d /run/systemd/system ] && HAS_SYSTEMD=1

svc() {  # svc <start|restart|reload|enable> <service>
  if [ "$HAS_SYSTEMD" = 1 ]; then
    if [ "$1" = enable ]; then systemctl enable --now "$2" >/dev/null 2>&1 || true
    else systemctl "$1" "$2"; fi
  else
    [ "$1" = enable ] && set -- start "$2"
    service "$2" "$1" >/dev/null 2>&1 || service "$2" start
  fi
}

# Set KEY=VALUE in .env, replacing the line if it exists, appending if not.
set_env() {
  local key="$1" val="$2" file="$APP_DIR/.env"
  if grep -qE "^#?\s*${key}=" "$file"; then
    # Use a delimiter that cannot appear in generated values.
    sed -i -E "s|^#?\s*${key}=.*|${key}=${val}|" "$file"
  else
    printf '%s=%s\n' "$key" "$val" >> "$file"
  fi
}
get_env() { grep -E "^$1=" "$APP_DIR/.env" 2>/dev/null | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'; }

randpw() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c "${1:-24}"; }

# Every artisan command runs as the web server's user, so the log files and
# caches it creates are writable by Apache afterwards. Running them as root is
# the classic cause of "500 | Server Error" on a fresh Linux install: root
# creates storage/logs/laravel.log, and Apache can then never write to it.
artisan() { sudo -u www-data -H php "$APP_DIR/artisan" "$@"; }

# ------------------------------------------------------------------ checks ---
[ "$(id -u)" -eq 0 ] || die "Run this with sudo:   sudo bash deploy/ubuntu/install.sh"

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
[ -f "$APP_DIR/artisan" ] || die "Cannot find the project folder (no artisan file in $APP_DIR)."

. /etc/os-release
[ "${ID:-}" = ubuntu ] || warn "This is ${PRETTY_NAME:-an unknown OS}, not Ubuntu. Continuing anyway."
UBU_MAJOR="${VERSION_ID%%.*}"

step "LGU Alicia LMS installer"
echo "    Project folder : $APP_DIR"
echo "    OS             : ${PRETTY_NAME:-unknown}"

# Apache (www-data) must be able to walk down to the project folder. Ubuntu
# makes home folders private (mode 750), so a clone in /home/you/lms is
# invisible to the web server and every page is a 403.
d="$APP_DIR"
while [ "$d" != "/" ]; do
  if ! sudo -u www-data test -x "$d" 2>/dev/null && id www-data >/dev/null 2>&1; then
    die "The web server cannot open $d.
    Put the project in /var/www/lms instead:
        sudo mv \"$APP_DIR\" /var/www/lms && cd /var/www/lms
    then run this installer again."
  fi
  d="$(dirname "$d")"
done

# ----------------------------------------------------------- 1. packages -----
step "1/9  Installing Apache, PHP, MariaDB and tools (a few minutes)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq

if [ "${UBU_MAJOR:-24}" -lt 24 ] 2>/dev/null; then
  # 22.04 ships PHP 8.1; the project needs 8.2+. ondrej/php is the standard
  # source for newer PHP on Ubuntu LTS.
  apt-get install -y -qq software-properties-common >/dev/null
  add-apt-repository -y ppa:ondrej/php >/dev/null
  apt-get update -qq
  PHPV="8.3"
  PHP_PKGS="php8.3 php8.3-cli libapache2-mod-php8.3 php8.3-mysql php8.3-mbstring php8.3-xml
            php8.3-gd php8.3-zip php8.3-curl php8.3-bcmath php8.3-intl php8.3-sqlite3"
else
  PHPV=""
  PHP_PKGS="php php-cli libapache2-mod-php php-mysql php-mbstring php-xml php-gd
            php-zip php-curl php-bcmath php-intl php-sqlite3"
fi

# shellcheck disable=SC2086
apt-get install -y -qq apache2 mariadb-server mariadb-client $PHP_PKGS \
  unzip git curl openssl cron ca-certificates >/dev/null

PHPV="${PHPV:-$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')}"
php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || die "PHP $PHPV is too old; 8.2 or newer is required."
ok "PHP $PHPV, $(apache2 -v | head -1 | sed 's/Server version: //'), $(mariadb --version | awk '{print $5}' | tr -d ,)"

if ! command -v composer >/dev/null 2>&1; then
  if [ "${UBU_MAJOR:-24}" -ge 24 ] 2>/dev/null; then
    apt-get install -y -qq composer >/dev/null
  else
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
  fi
fi
ok "$(composer --version 2>/dev/null | head -1)"

svc enable mariadb
svc enable apache2
svc enable cron

# ------------------------------------------------------------- 2. database ---
step "2/9  Database"
for i in $(seq 1 30); do mariadb -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
mariadb -e 'SELECT 1' >/dev/null 2>&1 || die "MariaDB is not answering. Check: sudo journalctl -u mariadb"

FIRST_INSTALL=0
if [ ! -f "$APP_DIR/.env" ]; then
  FIRST_INSTALL=1
  cp "$APP_DIR/.env.example" "$APP_DIR/.env"
fi

DB_PASS="$(get_env DB_PASSWORD)"
if [ -z "$DB_PASS" ] || [ "$(get_env DB_USERNAME)" != "$DB_USER" ]; then
  DB_PASS="$(randpw 24)"
fi

mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
ok "database '$DB_NAME', user '$DB_USER' (MariaDB listens on 127.0.0.1 only)"

# ----------------------------------------------------------------- 3. .env ---
step "3/9  Settings file (.env)"

if [ -z "$SERVER_IP" ]; then
  SERVER_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}')"
  [ -n "$SERVER_IP" ] || SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
fi
[[ "$SERVER_IP" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Could not work out this machine's IP address. Pass it:  --ip 192.168.x.y"

# The LAN this server is on, e.g. 192.168.1.0/24 -- Apache serves only that.
IFACE="$(ip -4 -o addr show | awk -v ip="$SERVER_IP" '$4 ~ "^"ip"/" {print $2; exit}')"
SUBNET="$(ip -4 route show dev "${IFACE:-lo}" proto kernel scope link 2>/dev/null | awk '{print $1; exit}')"
[ -n "$SUBNET" ] || SUBNET="${SERVER_IP%.*}.0/24"
ok "server IP $SERVER_IP on $SUBNET (interface ${IFACE:-?})"

set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "https://$SITE"
set_env DB_CONNECTION mysql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
set_env DB_PASSWORD "$DB_PASS"

if [ "$FIRST_INSTALL" = 1 ]; then
  set_env SEED_SUPERADMIN_PASSWORD "\"Alicia-$(randpw 10)!9\""
fi
# Shown in the summary. Only used by the seeder on the very first install;
# after the first login the super admin has chosen their own password.
ADMIN_PASS="$(get_env SEED_SUPERADMIN_PASSWORD)"
chown root:www-data "$APP_DIR/.env"
chmod 640 "$APP_DIR/.env"
ok ".env written (readable by root and the web server only)"

# ------------------------------------------------------ 4. app + database ----
step "4/9  PHP libraries and database tables"
cd "$APP_DIR"
export COMPOSER_ALLOW_SUPERUSER=1
if [ "$DEMO" = 1 ]; then
  # The demo seeder uses Faker, which is a development dependency.
  composer install --no-interaction --prefer-dist --optimize-autoloader --no-progress -q
else
  composer install --no-interaction --prefer-dist --optimize-autoloader --no-progress --no-dev -q
fi
ok "composer install"

# Writable by the web server: storage/ and bootstrap/cache/ only.
mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/private bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

[ -n "$(get_env APP_KEY)" ] || artisan key:generate --force -q
artisan config:clear -q
artisan migrate --force
ok "migrations"

USERS="$(mariadb -N -B -e "SELECT COUNT(*) FROM \`$DB_NAME\`.users" 2>/dev/null || echo 0)"
if [ "$USERS" = 0 ]; then
  artisan db:seed --force
  ok "roles, permissions, leave types, holidays, settings, super admin"
else
  ok "existing accounts found ($USERS) - seeders skipped, nothing overwritten"
fi
if [ "$DEMO" = 1 ]; then
  artisan db:seed --class=DemoDataSeeder --force
  ok "demo data"
fi

[ -L public/storage ] || artisan storage:link -q
ok "storage link"

# --------------------------------------------------------- 5. certificate ----
step "5/9  HTTPS certificate"
CRT="$APP_DIR/deploy/certs/lms.crt"
if [ -f "$CRT" ] && openssl x509 -in "$CRT" -noout -ext subjectAltName 2>/dev/null | grep -q "IP Address:$SERVER_IP" \
   && openssl x509 -in "$CRT" -noout -ext subjectAltName 2>/dev/null | grep -q "DNS:$SITE"; then
  ok "existing certificate already covers $SITE and $SERVER_IP"
else
  bash "$APP_DIR/deploy/make-cert.sh" "$SITE" "$SERVER_IP" | sed 's/^/    /'
fi
chmod 600 "$APP_DIR/deploy/certs/lms.key"
chmod 644 "$CRT"

# -------------------------------------------------------------- 6. apache ----
step "6/9  Apache web server"
a2enmod -q ssl rewrite headers >/dev/null
sed -e "s|__SITE__|$SITE|g" \
    -e "s|__SERVER_IP__|$SERVER_IP|g" \
    -e "s|__APP_DIR__|$APP_DIR|g" \
    -e "s|__SUBNET__|$SUBNET|g" \
    "$APP_DIR/deploy/ubuntu/apache-lms.conf" > /etc/apache2/sites-available/lms.conf
a2dissite -q 000-default >/dev/null 2>&1 || true
a2ensite -q lms >/dev/null

# Ubuntu's PHP allows 2 MB uploads; the leave form accepts 5 MB documents and
# 8 MB signature images, so those would fail with no useful message.
cat > "/etc/php/$PHPV/apache2/conf.d/99-lms.ini" <<'INI'
; LGU Alicia LMS - written by deploy/ubuntu/install.sh
upload_max_filesize = 10M
post_max_size = 40M
memory_limit = 256M
max_execution_time = 120
expose_php = Off
date.timezone = Asia/Manila
INI
cp "/etc/php/$PHPV/apache2/conf.d/99-lms.ini" "/etc/php/$PHPV/cli/conf.d/99-lms.ini"

# Do not advertise the Apache version on error pages.
cat > /etc/apache2/conf-available/lms-hardening.conf <<'CONF'
ServerTokens Prod
ServerSignature Off
CONF
a2enconf -q lms-hardening >/dev/null

apache2ctl configtest 2>&1 | grep -v "Syntax OK" | sed 's/^/    /' || true
apache2ctl configtest >/dev/null 2>&1 || die "Apache configuration test failed (see above)."
svc restart apache2
ok "site enabled: https://$SITE and https://$SERVER_IP (LAN $SUBNET only)"

# ------------------------------------------------- 7. worker + scheduler -----
step "7/9  Background worker and scheduler"
sed -e "s|__APP_DIR__|$APP_DIR|g" "$APP_DIR/deploy/ubuntu/lms-queue.service" > /etc/systemd/system/lms-queue.service
if [ "$HAS_SYSTEMD" = 1 ]; then
  systemctl daemon-reload
  systemctl enable --now lms-queue >/dev/null 2>&1
  systemctl restart lms-queue
  ok "lms-queue service running (sends OTP codes and notifications)"
else
  warn "systemd is not running here, so the queue worker was not started."
  warn "Start it by hand:  sudo -u www-data php $APP_DIR/artisan queue:work --tries=3 &"
fi

cat > /etc/cron.d/lms <<CRON
# LGU Alicia LMS scheduler: leave accrual, unblocking, OTP pruning, nightly backup.
* * * * * www-data cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1
CRON
chmod 644 /etc/cron.d/lms
ok "cron entry /etc/cron.d/lms (every minute)"

# ------------------------------------------------------------ 8. firewall ----
step "8/9  Firewall"
if [ "$FIREWALL" = 1 ] && command -v ufw >/dev/null 2>&1; then
  ufw allow OpenSSH >/dev/null 2>&1 || ufw allow 22/tcp >/dev/null
  ufw allow 80/tcp >/dev/null
  ufw allow 443/tcp >/dev/null
  if ufw --force enable >/dev/null 2>&1; then ok "ufw on: SSH, HTTP, HTTPS allowed; everything else blocked"
  else warn "ufw could not be enabled here (fine inside containers)."; fi
else
  warn "firewall step skipped"
fi

# -------------------------------------------------------------- 9. helper ----
step "9/9  The 'lms' helper command"
chmod +x "$APP_DIR/deploy/ubuntu/lms" "$APP_DIR/deploy/ubuntu/install.sh" "$APP_DIR/deploy/make-cert.sh"
ln -sf "$APP_DIR/deploy/ubuntu/lms" /usr/local/bin/lms
ok "try:  sudo lms status"

# --------------------------------------------------------------- summary -----
INFO=/root/lms-install-info.txt
{
  echo "LGU Alicia LMS - installed $(date '+%Y-%m-%d %H:%M')"
  echo
  echo "Project folder   : $APP_DIR"
  echo "Address          : https://$SITE   or   https://$SERVER_IP"
  echo "Database         : $DB_NAME  user $DB_USER  password $DB_PASS"
  if [ -n "$ADMIN_PASS" ]; then
    echo "Super admin      : username superadmin  (superadmin@alicia.gov.ph)"
    echo "First password   : $ADMIN_PASS   (first login only - you must then change it)"
  fi
  [ "$DEMO" = 1 ] && echo "Demo accounts    : password Alicia@2026Demo!  (see docs/RUN_ON_UBUNTU.md)"
  echo
  echo "On every PC that will use the system, add this line to its hosts file:"
  echo "    $SERVER_IP   $SITE"
} > "$INFO"
chmod 600 "$INFO"

printf '\n\033[1;32m============================================================\033[0m\n'
printf '\033[1;32m  Installation complete\033[0m\n'
printf '\033[1;32m============================================================\033[0m\n'
cat "$INFO" | sed 's/^/  /'
echo
echo "  One-time codes (OTP) are written to the log until you set up email."
echo "  After entering your password on the login page, run:   sudo lms otp"
echo
echo "  This summary is saved in $INFO (root only)."
echo
