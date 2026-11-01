#!/usr/bin/env bash
# Backup WinControl DB + storage (APP_KEY required to restore encrypted SSH material).
set -euo pipefail
APP_ROOT="${APP_ROOT:-/opt/nur-wincontrol}"
DEST_ROOT="${BACKUP_ROOT:-/var/backups/nur-wincontrol}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DEST="${DEST_ROOT}/${STAMP}"
mkdir -p "${DEST}"
umask 077

# Capture APP_KEY fingerprint only (not the key) for restore pairing checks.
if [[ -f "${APP_ROOT}/.env" ]]; then
  awk -F= '/^APP_KEY=/{print "app_key_present=yes"; print "app_key_sha256="}' "${APP_ROOT}/.env" >/dev/null
  KEY="$(awk -F= '/^APP_KEY=/{print substr($0,index($0,"=")+1)}' "${APP_ROOT}/.env")"
  printf 'app_key_sha256=%s\n' "$(printf '%s' "${KEY}" | sha256sum | awk '{print $1}')" > "${DEST}/meta.txt"
  printf 'created_at_utc=%s\napp_root=%s\n' "${STAMP}" "${APP_ROOT}" >> "${DEST}/meta.txt"
fi

if [[ -f "${APP_ROOT}/database/database.sqlite" ]]; then
  sqlite3 "${APP_ROOT}/database/database.sqlite" ".backup '${DEST}/database.sqlite'"
fi
tar -C "${APP_ROOT}" -czf "${DEST}/storage.tgz" storage
# Do not copy .env into shared backups by default; operator must retain APP_KEY offline.
chmod -R go-rwx "${DEST}"
# Retention: 14 days
find "${DEST_ROOT}" -mindepth 1 -maxdepth 1 -type d -mtime +14 -exec rm -rf {} +
echo "backup_ok ${DEST}"
