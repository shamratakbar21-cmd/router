#!/usr/bin/env bash
# Upgrade WinControl from a new management tree. Rolls back by restoring the pre-upgrade backup.
set -euo pipefail
NEW_SOURCE="${1:-}"
APP_ROOT="${APP_ROOT:-/opt/nur-wincontrol}"
APP_USER="${APP_USER:-nur-wincontrol}"
[[ -n "${NEW_SOURCE}" && -f "${NEW_SOURCE}/artisan" ]] || { echo "usage: $0 /path/to/new/management" >&2; exit 2; }

# Refuse incompatible tools/source before entering maintenance mode.
[[ "$(readlink -f "${NEW_SOURCE}")" != "$(readlink -f "${APP_ROOT}")" ]] || { echo 'New source must be separate from the deployed application' >&2; exit 2; }
node -e 'const v=process.versions.node.split(".").map(Number);process.exit(v[0]>22||(v[0]===22&&v[1]>=12)||(v[0]===20&&v[1]>=19)?0:1)' || { echo 'Node 20.19+ or 22.12+ is required' >&2; exit 1; }
[[ -f "${NEW_SOURCE}/package-lock.json" ]] || { echo 'Locked frontend dependencies are required' >&2; exit 1; }
php "${APP_ROOT}/artisan" down --retry=60
systemctl stop nur-wincontrol-queue.service nur-wincontrol-scheduler.timer nur-wincontrol-safemode.timer
BACKUP_RESULT="$(APP_ROOT="${APP_ROOT}" bash "${NEW_SOURCE}/deploy/ubuntu/scripts/backup-wincontrol.sh")"
BACKUP_HINT="${BACKUP_RESULT##*backup_ok }"
[[ -f "${BACKUP_HINT}/code.tgz" ]] || { echo "Complete pre-upgrade backup missing" >&2; exit 1; }
echo "pre_upgrade_backup=${BACKUP_HINT}"

systemctl stop nur-wincontrol-queue.service || true
rsync -a --delete \
  --exclude '.env' \
  --exclude 'database/database.sqlite*' \
  --exclude 'storage' \
  --exclude 'node_modules' \
  --exclude 'vendor' \
  "${NEW_SOURCE}/" "${APP_ROOT}/"

cd "${APP_ROOT}"
mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache database
chown -R "${APP_USER}:${APP_USER}" storage bootstrap/cache database
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
npm ci --ignore-scripts
npm run build
php artisan migrate --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
if [[ -n "${NGINX_SITE:-}" ]]; then
  # Update only the explicitly selected app site, preserving its ports, TLS and pool.
  awk '/^[[:space:]]*fastcgi_read_timeout[[:space:]]/ {next} /^[[:space:]]*fastcgi_pass[[:space:]]/ {print "        fastcgi_read_timeout 660s;"} {print}' "${NGINX_SITE}" > "${NGINX_SITE}.staging-new"
  mv "${NGINX_SITE}.staging-new" "${NGINX_SITE}"
  nginx -t
  systemctl reload nginx
fi
php artisan up
systemctl restart php${PHP_VERSION:-8.3}-fpm
systemctl start nur-wincontrol-scheduler.timer nur-wincontrol-safemode.timer
chown -R "${APP_USER}:${APP_USER}" storage bootstrap/cache database
systemctl start nur-wincontrol-queue.service
for helper in backup-wincontrol healthcheck upgrade rollback; do
  case "${helper}" in backup-wincontrol) target=backup ;; healthcheck) target=health ;; *) target="${helper}" ;; esac
  install -m 0755 "deploy/ubuntu/scripts/${helper}.sh" "/usr/local/sbin/nur-wincontrol-${target}"
done
"${HEALTH_COMMAND:-/usr/local/sbin/nur-wincontrol-health}"
echo "upgrade_complete"
echo "Rollback: APP_ROOT=${APP_ROOT} APP_USER=${APP_USER} PHP_VERSION=${PHP_VERSION:-8.3} NGINX_SITE=${NGINX_SITE:-} /usr/local/sbin/nur-wincontrol-rollback ${BACKUP_HINT}"
