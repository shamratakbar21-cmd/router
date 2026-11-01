#!/usr/bin/env bash
# NUR WinControl production-style installer for a clean Ubuntu 22.04/24.04 VM.
# Does NOT use `php artisan serve`. Does NOT modify host production sites —
# intended to run ON the target VM as root.
set -euo pipefail

APP_NAME="${APP_NAME:-nur-wincontrol}"
APP_USER="${APP_USER:-www-data}"
APP_ROOT="${APP_ROOT:-/opt/nur-wincontrol}"
SOURCE_DIR="${SOURCE_DIR:-}"
DOMAIN="${DOMAIN:-wincontrol.example.invalid}"
LISTEN_HTTP_PORT="${LISTEN_HTTP_PORT:-8080}"
LISTEN_HTTPS_PORT="${LISTEN_HTTPS_PORT:-8443}"
DB_CONNECTION="${DB_CONNECTION:-sqlite}"
PHP_VERSION="${PHP_VERSION:-8.3}"
TLS_CERT="${TLS_CERT:-}"
TLS_KEY="${TLS_KEY:-}"
CREATE_ADMIN="${CREATE_ADMIN:-1}"
NONINTERACTIVE="${NONINTERACTIVE:-0}"

log() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[[ "$(id -u)" -eq 0 ]] || die "run as root on the target Ubuntu VM"

if [[ -z "${SOURCE_DIR}" ]]; then
  # Default: this script lives in management/deploy/ubuntu/
  SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
fi
[[ -f "${SOURCE_DIR}/artisan" ]] || die "SOURCE_DIR=${SOURCE_DIR} does not look like the management app"

export DEBIAN_FRONTEND=noninteractive
log "Installing OS packages (PHP ${PHP_VERSION}, nginx, sqlite, composer deps host tools)..."
apt-get update -y
apt-get install -y \
  "php${PHP_VERSION}-cli" "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-sqlite3" \
  "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" \
  "php${PHP_VERSION}-zip" "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-intl" \
  "php${PHP_VERSION}-tokenizer" \
  nginx sqlite3 curl unzip git openssh-client openssl ca-certificates \
  composer logrotate gnupg ca-certificates

# Vite 8 / laravel-vite-plugin require Node >= 20.19 (Ubuntu apt nodejs is often 18.x).
if ! command -v node >/dev/null 2>&1 || ! node -e 'process.exit(Number(process.versions.node.split(".")[0])>=20?0:1)'; then
  log "Installing Node.js 22.x from NodeSource (apt nodejs too old for Vite 8)..."
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
  apt-get install -y nodejs
fi
node -e 'process.exit(Number(process.versions.node.split(".")[0])>=20?0:1)' || die "Node.js >= 20 is required"

# Verify required PHP extensions
php${PHP_VERSION} -m | grep -qi sqlite3 || die "php-sqlite3 missing"
php${PHP_VERSION} -m | grep -qi mbstring || die "php-mbstring missing"
php${PHP_VERSION} -m | grep -qi openssl || die "php openssl missing"
php${PHP_VERSION} -m | grep -qi ctype || die "php ctype missing"
php${PHP_VERSION} -m | grep -qi json || die "php json missing"
php${PHP_VERSION} -m | grep -qi tokenizer || die "php tokenizer missing"
php${PHP_VERSION} -m | grep -qi xml || die "php xml missing"
php${PHP_VERSION} -m | grep -qi curl || die "php curl missing"
command -v ssh >/dev/null || die "openssh-client missing"

log "Syncing application into ${APP_ROOT}"
mkdir -p "${APP_ROOT}"
rsync -a --delete \
  --exclude '.env' \
  --exclude 'node_modules' \
  --exclude 'vendor' \
  --exclude 'storage/logs/*' \
  --exclude 'storage/framework/cache/*' \
  --exclude 'storage/framework/sessions/*' \
  --exclude 'storage/framework/views/*' \
  "${SOURCE_DIR}/" "${APP_ROOT}/"

cd "${APP_ROOT}"
mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache database
chown -R "${APP_USER}:${APP_USER}" storage bootstrap/cache database

if [[ ! -f .env ]]; then
  if [[ -f .env.example ]]; then
    cp .env.example .env
  else
    cat > .env <<EOF
APP_NAME="NUR WinControl"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://${DOMAIN}:${LISTEN_HTTPS_PORT}
LOG_CHANNEL=stack
LOG_LEVEL=info
DB_CONNECTION=sqlite
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
EOF
  fi
  # Force production-safe defaults for this deploy path
  sed -i "s/^APP_ENV=.*/APP_ENV=production/" .env || true
  sed -i "s/^APP_DEBUG=.*/APP_DEBUG=false/" .env || true
  if grep -q '^APP_URL=' .env; then
    sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN}:${LISTEN_HTTPS_PORT}|" .env
  else
    echo "APP_URL=https://${DOMAIN}:${LISTEN_HTTPS_PORT}" >> .env
  fi
  if grep -q '^DB_CONNECTION=' .env; then
    sed -i "s/^DB_CONNECTION=.*/DB_CONNECTION=${DB_CONNECTION}/" .env
  else
    echo "DB_CONNECTION=${DB_CONNECTION}" >> .env
  fi
  grep -q '^QUEUE_CONNECTION=' .env || echo 'QUEUE_CONNECTION=database' >> .env
  grep -q '^SESSION_DRIVER=' .env || echo 'SESSION_DRIVER=database' >> .env
  grep -q '^CACHE_STORE=' .env || echo 'CACHE_STORE=database' >> .env
fi

if [[ "${DB_CONNECTION}" == "sqlite" ]]; then
  touch database/database.sqlite
  chown "${APP_USER}:${APP_USER}" database/database.sqlite
  chmod 660 database/database.sqlite
  if grep -q '^DB_DATABASE=' .env; then
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${APP_ROOT}/database/database.sqlite|" .env
  else
    echo "DB_DATABASE=${APP_ROOT}/database/database.sqlite" >> .env
  fi
fi

log "Installing locked Composer dependencies (no-dev)..."
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

if ! grep -q '^APP_KEY=base64:' .env; then
  log "Generating APP_KEY..."
  php artisan key:generate --force --no-interaction
fi

log "Building frontend assets..."
if [[ -f package-lock.json ]]; then
  npm ci --ignore-scripts
else
  npm install --ignore-scripts
fi
npm run build

log "Running migrations..."
php artisan migrate --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache || true

# TLS material
CERT_DIR="/etc/nur-wincontrol/tls"
mkdir -p "${CERT_DIR}"
if [[ -n "${TLS_CERT}" && -n "${TLS_KEY}" && -f "${TLS_CERT}" && -f "${TLS_KEY}" ]]; then
  install -m 0644 "${TLS_CERT}" "${CERT_DIR}/fullchain.pem"
  install -m 0600 "${TLS_KEY}" "${CERT_DIR}/privkey.pem"
else
  log "Generating disposable self-signed TLS cert for ${DOMAIN} (replace with operator certs for pilot)"
  openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
    -keyout "${CERT_DIR}/privkey.pem" \
    -out "${CERT_DIR}/fullchain.pem" \
    -subj "/CN=${DOMAIN}/O=NUR WinControl Lab/C=US" >/dev/null 2>&1
  chmod 600 "${CERT_DIR}/privkey.pem"
fi

DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log "Installing nginx site on ports ${LISTEN_HTTP_PORT}/${LISTEN_HTTPS_PORT} (isolated from default :80/:443)..."
# Disable default site if present on this VM only
rm -f /etc/nginx/sites-enabled/default
sed \
  -e "s|__APP_ROOT__|${APP_ROOT}|g" \
  -e "s|__DOMAIN__|${DOMAIN}|g" \
  -e "s|__HTTP_PORT__|${LISTEN_HTTP_PORT}|g" \
  -e "s|__HTTPS_PORT__|${LISTEN_HTTPS_PORT}|g" \
  -e "s|__PHP_VERSION__|${PHP_VERSION}|g" \
  -e "s|__CERT_DIR__|${CERT_DIR}|g" \
  "${DEPLOY_DIR}/nginx/nur-wincontrol.conf.template" > /etc/nginx/sites-available/nur-wincontrol.conf
ln -sfn /etc/nginx/sites-available/nur-wincontrol.conf /etc/nginx/sites-enabled/nur-wincontrol.conf
nginx -t
systemctl enable --now nginx
systemctl reload nginx

log "Installing systemd units (queue + scheduler + Safe Mode watchdog)..."
sed -e "s|__APP_ROOT__|${APP_ROOT}|g" -e "s|__APP_USER__|${APP_USER}|g" -e "s|__PHP_VERSION__|${PHP_VERSION}|g" \
  "${DEPLOY_DIR}/systemd/nur-wincontrol-queue.service" > /etc/systemd/system/nur-wincontrol-queue.service
sed -e "s|__APP_ROOT__|${APP_ROOT}|g" -e "s|__APP_USER__|${APP_USER}|g" -e "s|__PHP_VERSION__|${PHP_VERSION}|g" \
  "${DEPLOY_DIR}/systemd/nur-wincontrol-scheduler.service" > /etc/systemd/system/nur-wincontrol-scheduler.service
cp "${DEPLOY_DIR}/systemd/nur-wincontrol-scheduler.timer" /etc/systemd/system/nur-wincontrol-scheduler.timer
sed -e "s|__APP_ROOT__|${APP_ROOT}|g" -e "s|__APP_USER__|${APP_USER}|g" -e "s|__PHP_VERSION__|${PHP_VERSION}|g" \
  "${DEPLOY_DIR}/systemd/nur-wincontrol-safemode.service" > /etc/systemd/system/nur-wincontrol-safemode.service
cp "${DEPLOY_DIR}/systemd/nur-wincontrol-safemode.timer" /etc/systemd/system/nur-wincontrol-safemode.timer

systemctl daemon-reload
systemctl enable --now php${PHP_VERSION}-fpm
systemctl enable --now nur-wincontrol-queue.service
systemctl enable --now nur-wincontrol-scheduler.timer
systemctl enable --now nur-wincontrol-safemode.timer

install -m 0644 "${DEPLOY_DIR}/logrotate/nur-wincontrol" /etc/logrotate.d/nur-wincontrol
install -m 0755 "${DEPLOY_DIR}/scripts/backup-wincontrol.sh" /usr/local/sbin/nur-wincontrol-backup
install -m 0755 "${DEPLOY_DIR}/scripts/healthcheck.sh" /usr/local/sbin/nur-wincontrol-health
install -m 0755 "${DEPLOY_DIR}/scripts/upgrade.sh" /usr/local/sbin/nur-wincontrol-upgrade
install -m 0755 "${DEPLOY_DIR}/scripts/rollback.sh" /usr/local/sbin/nur-wincontrol-rollback

# Daily DB backup cron
cat > /etc/cron.d/nur-wincontrol-backup <<EOF
15 2 * * * root /usr/local/sbin/nur-wincontrol-backup >/var/log/nur-wincontrol-backup.log 2>&1
EOF

chown -R "${APP_USER}:${APP_USER}" "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache" "${APP_ROOT}/database"

if [[ "${CREATE_ADMIN}" == "1" ]]; then
  ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.invalid}"
  ADMIN_NAME="${ADMIN_NAME:-Administrator}"
  if [[ "${NONINTERACTIVE}" == "1" ]]; then
    # --generate prints a one-time password; capture it for the operator root file only.
    BOOT_OUT="$(php artisan nur:create-admin "${ADMIN_EMAIL}" "${ADMIN_NAME}" --generate --no-interaction)"
    BOOT_PASS="$(printf '%s\n' "${BOOT_OUT}" | awk 'found{print; exit} /One-time bootstrap password/{found=1}')"
    umask 077
    {
      echo "email=${ADMIN_EMAIL}"
      echo "name=${ADMIN_NAME}"
      echo "one_time_password=${BOOT_PASS}"
      echo "must_rotate_password=true"
    } > /root/nur-wincontrol-bootstrap-admin.txt
    chmod 600 /root/nur-wincontrol-bootstrap-admin.txt
    log "Bootstrap admin written to /root/nur-wincontrol-bootstrap-admin.txt (mode 600)"
  else
    log "Create the first administrator interactively:"
    php artisan nur:create-admin "${ADMIN_EMAIL}" "${ADMIN_NAME}"
  fi
fi

/usr/local/sbin/nur-wincontrol-health || log "WARN: healthcheck reported issues; inspect nginx/php-fpm logs"

cat <<EOF

NUR WinControl install complete.
  App root : ${APP_ROOT}
  URL      : https://${DOMAIN}:${LISTEN_HTTPS_PORT}  (HTTP ${LISTEN_HTTP_PORT} redirects)
  Health   : /usr/local/sbin/nur-wincontrol-health
  Backup   : /usr/local/sbin/nur-wincontrol-backup
  Upgrade  : /usr/local/sbin/nur-wincontrol-upgrade /path/to/new/management/tree

Next:
  1) Replace self-signed TLS with operator certificates if this is a real pilot.
  2) php artisan nur:license-generate-signing-key --write-public-overlay (management host only).
  3) Enroll appliance host-key pins; never accept changed keys silently.
EOF
