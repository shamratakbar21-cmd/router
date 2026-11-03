#!/usr/bin/env bash
# Protected code + database + storage snapshot. Retain APP_KEY separately.
set -euo pipefail
APP_ROOT="${APP_ROOT:-/opt/nur-wincontrol}"
DEST_ROOT="${BACKUP_ROOT:-/var/backups/nur-wincontrol}"
STAMP="$(date -u +%Y%m%dT%H%M%S)-$$"
DEST="${DEST_ROOT}/${STAMP}"
umask 077
mkdir -p "${DEST}"
chmod 700 "${DEST}"
[[ -f "${APP_ROOT}/.env" ]] || { echo 'Missing application environment' >&2; exit 1; }
KEY="$(sed -n 's/^APP_KEY=//p' "${APP_ROOT}/.env")"
[[ -n "${KEY}" ]] || { echo 'APP_KEY is missing' >&2; exit 1; }
printf 'app_key_sha256=%s\n' "$(printf '%s' "${KEY}" | sha256sum | cut -d' ' -f1)" > "${DEST}/meta.txt"
if [[ -f "${APP_ROOT}/database/database.sqlite" ]]; then
  sqlite3 "${APP_ROOT}/database/database.sqlite" ".backup '${DEST}/database.sqlite'"
else
  echo 'This backup procedure requires SQLite; configure a consistent database-native backup first.' >&2
  exit 1
fi
tar -C "${APP_ROOT}" -czf "${DEST}/storage.tgz" storage
tar -C "${APP_ROOT}" --exclude='./.env*' --exclude='./bootstrap/cache/*.php' --exclude='./database/database.sqlite*' --exclude='./storage' --exclude='./node_modules' --exclude='./.git' -czf "${DEST}/code.tgz" .
SNAPSHOT_FILES=(meta.txt database.sqlite storage.tgz code.tgz)
if [[ -n "${NGINX_SITE:-}" ]]; then
  [[ -f "${NGINX_SITE}" ]] || { echo 'Selected nginx site is missing' >&2; exit 1; }
  cp -p "${NGINX_SITE}" "${DEST}/webserver.conf"
  printf 'nginx_site=%s\n' "${NGINX_SITE}" >> "${DEST}/meta.txt"
  SNAPSHOT_FILES+=(webserver.conf)
fi
(cd "${DEST}" && sha256sum "${SNAPSHOT_FILES[@]}" > SHA256SUMS)
chmod -R go-rwx "${DEST}"
find "${DEST_ROOT}" -mindepth 1 -maxdepth 1 -type d -mtime +14 -exec rm -rf -- {} +
echo "backup_ok ${DEST}"
