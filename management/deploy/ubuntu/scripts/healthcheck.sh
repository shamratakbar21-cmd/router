#!/usr/bin/env bash
set -euo pipefail
APP_ROOT="${APP_ROOT:-/opt/nur-wincontrol}"
HTTPS_PORT="${LISTEN_HTTPS_PORT:-8443}"
DOMAIN="${DOMAIN:-127.0.0.1}"
APP_USER="${APP_USER:-www-data}"
FAIL=0

check() {
  local name="$1"; shift
  if "$@"; then
    echo "[PASS] ${name}"
  else
    echo "[FAIL] ${name}"
    FAIL=1
  fi
}

check php_fpm systemctl is-active --quiet "php${PHP_VERSION:-8.3}-fpm"
check nginx systemctl is-active --quiet nginx
check queue systemctl is-active --quiet nur-wincontrol-queue
check scheduler_timer systemctl is-active --quiet nur-wincontrol-scheduler.timer
check safemode_timer systemctl is-active --quiet nur-wincontrol-safemode.timer
check artisan_ok test -x "${APP_ROOT}/artisan"
check env_key grep -q '^APP_KEY=base64:' "${APP_ROOT}/.env"
check storage_writable runuser -u "${APP_USER}" -- test -w "${APP_ROOT}/storage/logs"
check cache_writable runuser -u "${APP_USER}" -- test -w "${APP_ROOT}/bootstrap/cache"
check database_directory_writable runuser -u "${APP_USER}" -- test -w "${APP_ROOT}/database"

# Local HTTPS health — Laravel /up
if curl -kfsS "https://127.0.0.1:${HTTPS_PORT}/up" >/dev/null 2>&1 \
  || curl -kfsS "https://${DOMAIN}:${HTTPS_PORT}/up" >/dev/null 2>&1; then
  echo "[PASS] https_up"
else
  echo "[FAIL] https_up"
  FAIL=1
fi

exit "${FAIL}"
