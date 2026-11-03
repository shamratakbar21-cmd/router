#!/usr/bin/env bash
# Restore one complete compatible code/database/storage set; never regenerate APP_KEY.
set -euo pipefail
BACKUP_DIR="${1:-}"
APP_ROOT="${APP_ROOT:-/opt/nur-wincontrol}"
APP_USER="${APP_USER:-nur-wincontrol}"
[[ -n "${BACKUP_DIR}" && -f "${BACKUP_DIR}/code.tgz" && -f "${BACKUP_DIR}/SHA256SUMS" ]] || { echo "usage: $0 /path/to/complete-backup (code.tgz required)" >&2; exit 2; }
(cd "${BACKUP_DIR}" && sha256sum -c SHA256SUMS)
KEY="$(sed -n 's/^APP_KEY=//p' "${APP_ROOT}/.env")"
EXPECTED="$(sed -n 's/^app_key_sha256=//p' "${BACKUP_DIR}/meta.txt")"
[[ "$(printf '%s' "${KEY}" | sha256sum | cut -d' ' -f1)" == "${EXPECTED}" ]] || { echo 'APP_KEY does not match this backup; restore the original key before rollback.' >&2; exit 1; }
if [[ -f "${BACKUP_DIR}/webserver.conf" ]]; then
  SAVED_SITE="$(sed -n 's/^nginx_site=//p' "${BACKUP_DIR}/meta.txt")"
  [[ -n "${NGINX_SITE:-}" && "${NGINX_SITE}" == "${SAVED_SITE}" ]] || { echo 'Set NGINX_SITE to the original selected site before rollback' >&2; exit 1; }
fi
STAGE="$(mktemp -d /var/tmp/nur-wincontrol-rollback.XXXXXXXX)"
trap 'rm -rf -- "${STAGE}"' EXIT
chmod 700 "${STAGE}"
tar -C "${STAGE}" -xzf "${BACKUP_DIR}/code.tgz"
php "${APP_ROOT}/artisan" down --retry=60
systemctl stop nur-wincontrol-queue.service nur-wincontrol-scheduler.timer nur-wincontrol-safemode.timer
rsync -a --delete --exclude='.env' --exclude='storage' --exclude='database/database.sqlite*' "${STAGE}/" "${APP_ROOT}/"
install -m 660 -o "${APP_USER}" -g "${APP_USER}" "${BACKUP_DIR}/database.sqlite" "${APP_ROOT}/database/database.sqlite"
rm -f -- "${APP_ROOT}/database/database.sqlite-wal" "${APP_ROOT}/database/database.sqlite-shm"
# Quiesced application: replace storage to remove incompatible new cache/session data.
find "${APP_ROOT}/storage" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
tar -C "${APP_ROOT}" -xzf "${BACKUP_DIR}/storage.tgz"
chown -R "${APP_USER}:${APP_USER}" "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache" "${APP_ROOT}/database"
cd "${APP_ROOT}"
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
if [[ -f "${BACKUP_DIR}/webserver.conf" ]]; then
  cp -p "${BACKUP_DIR}/webserver.conf" "${NGINX_SITE}"
  nginx -t
  systemctl reload nginx
fi
php artisan up
systemctl restart "php${PHP_VERSION:-8.3}-fpm"
systemctl start nur-wincontrol-queue.service nur-wincontrol-scheduler.timer nur-wincontrol-safemode.timer
"${HEALTH_COMMAND:-/usr/local/sbin/nur-wincontrol-health}"
echo "rollback_complete code_database_storage=${BACKUP_DIR}"
