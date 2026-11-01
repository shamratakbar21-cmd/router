#!/usr/bin/env bash
# Upgrade WinControl from a new management tree. Rolls back by restoring the pre-upgrade backup.
set -euo pipefail
NEW_SOURCE="${1:-}"
APP_ROOT="${APP_ROOT:-/opt/nur-wincontrol}"
[[ -n "${NEW_SOURCE}" && -f "${NEW_SOURCE}/artisan" ]] || { echo "usage: $0 /path/to/new/management" >&2; exit 2; }

/usr/local/sbin/nur-wincontrol-backup
BACKUP_HINT="$(ls -1dt /var/backups/nur-wincontrol/* 2>/dev/null | head -1 || true)"
echo "pre_upgrade_backup=${BACKUP_HINT}"

systemctl stop nur-wincontrol-queue.service || true
rsync -a --delete \
  --exclude '.env' \
  --exclude 'database/database.sqlite' \
  --exclude 'storage' \
  --exclude 'node_modules' \
  --exclude 'vendor' \
  "${NEW_SOURCE}/" "${APP_ROOT}/"

cd "${APP_ROOT}"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
if [[ -f package-lock.json ]]; then npm ci --ignore-scripts; else npm install --ignore-scripts; fi
npm run build
php artisan migrate --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache || true
systemctl start nur-wincontrol-queue.service
/usr/local/sbin/nur-wincontrol-health
echo "upgrade_complete"
echo "Rollback: stop queue; restore ${BACKUP_HINT}/database.sqlite and storage.tgz; rsync previous tree; restart."
