#!/usr/bin/env bash
# Boot a throwaway Nextcloud with this openregister checkout enabled, the way the
# shared CI Newman job does it: a nextcloud/server git checkout, PostgreSQL, and
# PHP's built-in server on 127.0.0.1:${PORT}. No docker needed.
#
# PostgreSQL (or MySQL) is required. Measured 2026-11-09: on SQLite the install and
# app:enable succeed, but creating an object fails with "no such table:
# information_schema.columns" (the magic mapper), so most collections go red.
# DB_HOST/DB_PORT/DB_USER/DB_PASS/DB_NAME point at an existing server; without
# DB_HOST the script tries to start a private cluster with initdb on port 15432.
# DB=sqlite forces the SQLite path (only for endpoints that never store objects).
#
# Usage: boot-nextcloud.sh <openregister-checkout> [server-dir] [stable-branch]
# Prints READY base_url=http://localhost:${PORT} on success, or the failing step.
# Every step is idempotent, so a second run after a fix resumes.
set -euo pipefail
APP="$(cd "${1:?openregister checkout}" && pwd)"
SRV="${2:-$HOME/nc-server}"
REF="${3:-stable34}"
PORT="${PORT:-8080}"
step() { echo "== $*"; }
fail() { echo "BOOT FAILED at: $*" >&2; exit 1; }

step "php extensions"
missing=""
MODS="$(php -m)"
DB="${DB:-pgsql}"
req="mbstring zip curl xml"; [ "$DB" = pgsql ] && req="$req pdo_pgsql pgsql"; [ "$DB" = sqlite ] && req="$req pdo_sqlite sqlite3"
for ext in $req; do
  grep -qix "${ext}" <<<"$MODS" || missing="$missing $ext"
done
for ext in intl gd; do
  grep -qix "${ext}" <<<"$MODS" || echo "warning: php-$ext missing (recommended, not required for install)"
done
if [ -n "$missing" ]; then
  echo "missing:$missing; trying apt"
  pkgs=""; for e in $missing; do case $e in pdo_sqlite|sqlite3) pkgs="$pkgs php8.3-sqlite3";; pdo_pgsql|pgsql) pkgs="$pkgs php8.3-pgsql";; *) pkgs="$pkgs php8.3-$e";; esac; done
  (sudo -n true 2>/dev/null && sudo apt-get install -y -qq $pkgs) || apt-get install -y -qq $pkgs || fail "php extensions:$missing (install them, or report that Newman cannot run here)"
fi

if [ "$DB" = pgsql ] && [ -z "${DB_HOST:-}" ]; then
  step "private postgres on 127.0.0.1:15432"
  PGBIN="$(ls -d /usr/lib/postgresql/*/bin 2>/dev/null | sort -V | tail -1)"
  if [ -z "$PGBIN" ]; then
    (sudo -n true 2>/dev/null && sudo apt-get install -y -qq postgresql) || apt-get install -y -qq postgresql || fail "postgres not installable (no initdb, no apt): Newman cannot run here"
    PGBIN="$(ls -d /usr/lib/postgresql/*/bin | sort -V | tail -1)"
  fi
  PGDATA="$HOME/nc-pgdata"
  RUNAS=""; [ "$(id -u)" = 0 ] && { id postgres >/dev/null 2>&1 || useradd -m postgres; RUNAS="runuser -u postgres --"; mkdir -p "$PGDATA" && chown postgres "$PGDATA"; }
  if [ ! -f "$PGDATA/PG_VERSION" ]; then
    $RUNAS "$PGBIN/initdb" -D "$PGDATA" -U nextcloud --auth=trust >/dev/null || fail "initdb"
  fi
  $RUNAS "$PGBIN/pg_ctl" -D "$PGDATA" -o "-p 15432 -k /tmp -c listen_addresses=127.0.0.1" -l "$PGDATA/log" status >/dev/null 2>&1 || \
    $RUNAS "$PGBIN/pg_ctl" -D "$PGDATA" -o "-p 15432 -k /tmp -c listen_addresses=127.0.0.1" -l "$PGDATA/log" -w start || fail "pg_ctl start (see $PGDATA/log)"
  DB_HOST=127.0.0.1; DB_PORT=15432; DB_USER=nextcloud; DB_PASS=nextcloud; DB_NAME=nextcloud
fi

step "server checkout $REF"
if [ ! -f "$SRV/occ" ]; then
  git clone -q --depth 1 --branch "$REF" https://github.com/nextcloud/server "$SRV" || fail "git clone nextcloud/server"
  git -C "$SRV" submodule update --init --depth 1 3rdparty || fail "3rdparty submodule"
fi

step "app in apps/"
[ -e "$SRV/apps/openregister" ] || ln -s "$APP" "$SRV/apps/openregister"
[ -f "$APP/vendor/autoload.php" ] || (cd "$APP" && composer install --no-interaction "--${COMPOSER_PREFER:-prefer-source}") || fail "composer install"

step "install"
if ! php "$SRV/occ" status 2>/dev/null | grep -q "installed: true"; then
  if [ "$DB" = sqlite ]; then
    php "$SRV/occ" maintenance:install --database sqlite --admin-user admin --admin-pass admin || fail "occ maintenance:install"
  else
    php "$SRV/occ" maintenance:install --database pgsql --database-host "$DB_HOST" --database-port "${DB_PORT:-5432}" \
      --database-name "${DB_NAME:-nextcloud}" --database-user "${DB_USER:-nextcloud}" --database-pass "${DB_PASS:-nextcloud}" \
      --admin-user admin --admin-pass admin || fail "occ maintenance:install (pgsql at $DB_HOST:${DB_PORT:-5432})"
  fi
fi
php "$SRV/occ" config:system:set trusted_domains 1 --value=localhost >/dev/null
php "$SRV/occ" config:system:set appstoreenabled --type=boolean --value=false >/dev/null
php "$SRV/occ" app:enable openregister || fail "occ app:enable openregister"

step "web server"
cat > "$SRV/ci-router.php" <<'ROUTER'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}
$segments = explode('/', ltrim($path, '/'));
$prefix = '';
foreach ($segments as $i => $segment) {
    $prefix .= '/' . $segment;
    if (substr($segment, -4) !== '.php' || $prefix === '/index.php' || !is_file(__DIR__ . $prefix)) {
        continue;
    }
    $rest = implode('/', array_slice($segments, $i + 1));
    $_SERVER['SCRIPT_NAME'] = $prefix;
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . $prefix;
    $_SERVER['PHP_SELF'] = $path;
    $_SERVER['PATH_INFO'] = $rest === '' ? '' : '/' . $rest;
    require __DIR__ . $prefix;
    return true;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require __DIR__ . '/index.php';
ROUTER
if ! curl -s -o /dev/null --max-time 3 http://localhost:${PORT}/status.php; then
  (cd "$SRV" && PHP_CLI_SERVER_WORKERS=8 nohup php -S 127.0.0.1:${PORT} ci-router.php > "$SRV/php-server.log" 2>&1 &)
fi
for _ in $(seq 1 60); do
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 -u admin:admin http://localhost:${PORT}/index.php/apps/openregister/api/registers || true)
  if [ "${#code}" = 3 ] && [ "$code" -ge 100 ] && [ "$code" -lt 500 ]; then
    echo "READY base_url=http://localhost:${PORT} (registers answered HTTP $code)"
    echo "stop it by PID only: pgrep -f '^php -S 127.0.0.1:${PORT}' gives the PID"
    exit 0
  fi
  sleep 2
done
fail "openregister API did not answer on :${PORT} (see $SRV/php-server.log and $SRV/data/nextcloud.log)"
