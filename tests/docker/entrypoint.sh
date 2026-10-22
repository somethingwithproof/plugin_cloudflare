#!/usr/bin/env bash
# Copies the plugin into place, writes include/config.php, seeds the
# database from cacti.sql, runs Cacti's CLI installer and sets a known admin
# password, then starts Apache. The steps follow tests/e2e/entrypoint.sh and tests/e2e/docker/setup.sh in
# Cacti release/1.2.31. Safe to re-run against a seeded database.

set -euo pipefail

CACTI_ROOT=/var/www/html/cacti
DB_HOST="${CACTI_DB_HOST:-db}"
DB_PORT="${CACTI_DB_PORT:-3306}"
DB_NAME="${CACTI_DB_NAME:-cacti}"
DB_USER="${CACTI_DB_USER:-cactiuser}"
DB_PASS="${CACTI_DB_PASS:-cactiuser}"
ADMIN_PASS="${CACTI_ADMIN_PASS:-smoke-admin}"
PLUGIN_SRC="${CACTI_PLUGIN_SRC:-/src/cloudflare}"

log() { printf '[entrypoint] %s\n' "$*" >&2; }

sql() {
	mysql --host="${DB_HOST}" --port="${DB_PORT}" --user="${DB_USER}" \
		--password="${DB_PASS}" --protocol=TCP --batch --skip-column-names \
		"${DB_NAME}" "$@"
}

as_www() {
	runuser -u www-data -- "$@"
}

# The checkout is mounted read-only at PLUGIN_SRC and copied, so nothing
# here writes to the host tree. The exclusions follow .gitattributes
# export-ignore for the trees a release archive leaves out.
plugin_dir="${CACTI_ROOT}/plugins/cloudflare"
if [ ! -f "${PLUGIN_SRC}/setup.php" ]; then
	log "no plugin checkout at ${PLUGIN_SRC}"
	exit 1
fi
log "copying the plugin from ${PLUGIN_SRC}"
rm -rf "${plugin_dir}"
mkdir -p "${plugin_dir}"
tar -C "${PLUGIN_SRC}" \
	--exclude=./vendor \
	--exclude=./tests \
	--exclude=./features \
	--exclude=./docs/superpowers \
	--exclude=./.superpowers \
	--exclude=./.git \
	-cf - . | tar -C "${plugin_dir}" --no-same-owner -xf -

config_php="${CACTI_ROOT}/include/config.php"
cp "${CACTI_ROOT}/include/config.php.dist" "${config_php}"
sed -i -E \
	-e "s|^(\\\$database_hostname[[:space:]]*=[[:space:]]*)'[^']*';|\\1'${DB_HOST}';|" \
	-e "s|^(\\\$database_username[[:space:]]*=[[:space:]]*)'[^']*';|\\1'${DB_USER}';|" \
	-e "s|^(\\\$database_password[[:space:]]*=[[:space:]]*)'[^']*';|\\1'${DB_PASS}';|" \
	-e "s|^(\\\$database_default[[:space:]]*=[[:space:]]*)'[^']*';|\\1'${DB_NAME}';|" \
	-e "s|^(\\\$database_port[[:space:]]*=[[:space:]]*)'[^']*';|\\1'${DB_PORT}';|" \
	"${config_php}"
chown www-data:www-data "${config_php}"

log "waiting for MariaDB at ${DB_HOST}:${DB_PORT}"
attempt=0
until sql --execute='SELECT 1' >/dev/null 2>&1; do
	attempt=$((attempt + 1))
	if [ "${attempt}" -ge 60 ]; then
		log "MariaDB not reachable after ${attempt} attempts"
		exit 1
	fi
	sleep 2
done

if [ "$(sql --execute="SHOW TABLES LIKE 'version'")" = '' ]; then
	log "importing cacti.sql"
	sql < "${CACTI_ROOT}/cacti.sql"
fi

if [ "$(sql --execute='SELECT cacti FROM version LIMIT 1')" = 'new_install' ]; then
	log "running cli/install_cacti.php"
	as_www php "${CACTI_ROOT}/cli/install_cacti.php" --accept-eula --install --mode=1 >&2

	if [ "$(sql --execute='SELECT cacti FROM version LIMIT 1')" = 'new_install' ]; then
		log "installer left version at new_install"
		exit 1
	fi
fi

# shellcheck disable=SC2016 # PHP source; $argv is PHP's, not the shell's.
hash="$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "${ADMIN_PASS}")"
sql --execute="UPDATE user_auth SET password='${hash}', must_change_password='', password_change='', enabled='on' WHERE username='admin'"

log "ready"
exec "$@"
