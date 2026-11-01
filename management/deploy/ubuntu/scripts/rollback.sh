#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="${1:-}"
APP_ROOT="${APP_ROOT:-/opt/nur-wincontrol}"
[[ -n "${BACKUP_DIR}" && -d "${BACKUP_DIR}" ]] || { echo "usage: $0 /var/backups/nur-wincontrol/<stamp>" >&2; exit 2; }

systemctl stop nur-wincontrol-queue.service || true
if [[ -f "${BACKUP_DIR}/database.sqlite" ]]; then
  install -m 660 -o www-data -g www-data "${BACKUP_DIR}/database.sqlite" "${APP_ROOT}/database/database.sqlite"
fi
if [[ -f "${BACKUP_DIR}/storage.tgz" ]]; then
  tar -C "${APP_ROOT}" -xzf "${BACKUP_DIR}/storage.tgz"
  chown -R www-data:www-data "${APP_ROOT}/storage"
fi
php "${APP_ROOT}/artisan" config:clear || true
systemctl start nur-wincontrol-queue.service
/usr/local/sbin/nur-wincontrol-health
echo "rollback_complete from ${BACKUP_DIR}"
