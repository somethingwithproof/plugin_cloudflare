#!/usr/bin/env bash
# Install smoke for the plugin against a real Cacti 1.2.31. Brings up
# compose.cacti.yml, walks the plugin lifecycle through Cacti's CLI and web
# UI, and stops at the first failed step. KEEP=1 leaves the stack running;
# CF_PLUGIN_DIR mounts another directory in place of this checkout.
#
# Needs tests/docker/build/cacti.tar; run through `make smoke`.

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
project="${CF_SMOKE_PROJECT:-cf-smoke}"
port="${CF_SMOKE_PORT:-8090}"
admin_pass="${CF_SMOKE_ADMIN_PASS:-smoke-admin}"
viewer_pass='smoke-viewer'
base="http://127.0.0.1:${port}/cacti"
page="${base}/plugins/cloudflare/cloudflare_accounts.php"
denied_marker='You are not permitted to access this section of Cacti.'

export CF_SMOKE_PORT="${port}"
export CF_SMOKE_ADMIN_PASS="${admin_pass}"

dc=(docker compose -p "${project}" -f "${here}/compose.cacti.yml")
work="$(mktemp -d)"
step='setup'

teardown() {
	local rc=$?

	if [ "${rc}" -ne 0 ]; then
		printf 'FAIL: %s\n' "${step}"
	fi

	if [ "${KEEP:-0}" = '1' ]; then
		printf 'KEEP=1: stack %s left running at %s/\n' "${project}" "${base}" >&2
	else
		"${dc[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
	fi

	rm -rf "${work}"
	exit "${rc}"
}
trap teardown EXIT

pass() {
	printf 'PASS: %s\n' "${step}"
}

die() {
	printf '  %s\n' "$@" >&2
	exit 1
}

sql() {
	"${dc[@]}" exec -T db mariadb --user=cactiuser --password=cactiuser \
		--batch --skip-column-names cacti --execute="$1"
}

plugin_manage() {
	"${dc[@]}" exec -T -u www-data web php cli/plugin_manage.php --plugin=cloudflare "$@"
}

plugin_status() {
	sql "SELECT COALESCE(MAX(status), 'none') FROM plugin_config WHERE directory = 'cloudflare'"
}

plugin_tables() {
	sql "SELECT table_name FROM information_schema.tables WHERE table_schema = 'cacti' AND table_name LIKE 'plugin!_cloudflare!_%' ESCAPE '!' ORDER BY table_name"
}

core_counts() {
	sql "SELECT (SELECT COUNT(*) FROM host), (SELECT COUNT(*) FROM graph_local), (SELECT COUNT(*) FROM data_template)"
}

# A CLI run that printed a PHP error did not succeed, whatever it exits with.
require_clean() {
	if grep -qiE 'PHP (Fatal|Parse|Warning|Notice|Deprecated)|Fatal error|Uncaught' <<<"$1"; then
		die 'PHP error in output:' "$1"
	fi
}

# http <jar> <out> <curl args...>; prints the final status code.
http() {
	local jar="$1" out="$2"
	shift 2
	curl -sS -L --max-time 30 -b "${jar}" -c "${jar}" -o "${out}" -w '%{http_code}' "$@"
}

csrf_from() {
	# sed rather than head picks the first match: sed drains its input, so
	# grep never dies of SIGPIPE, which pipefail would report as a failure.
	grep -oE "name=.__csrf_magic. value=\"[^\"]+\"" "$1" | sed -n -E '1s/.*value="([^"]+)"/\1/p' || true
}

login() {
	local user="$1" pass="$2" jar="$3" token code

	code="$(http "${jar}" "${work}/login-form.html" "${base}/index.php")"
	[ "${code}" = '200' ] || die "login form returned ${code}"

	token="$(csrf_from "${work}/login-form.html")"
	[ -n "${token}" ] || die 'no __csrf_magic token on the login form'

	code="$(http "${jar}" "${work}/login-${user}.html" \
		--data-urlencode 'action=login' \
		--data-urlencode "login_username=${user}" \
		--data-urlencode "login_password=${pass}" \
		--data-urlencode 'realm=local' \
		--data-urlencode "__csrf_magic=${token}" \
		"${base}/index.php")"
	[ "${code}" = '200' ] || die "login POST for ${user} returned ${code}"

	if grep -q 'login_username' "${work}/login-${user}.html"; then
		die "${user} is still on the login form after posting credentials"
	fi
}

require_accounts_page() {
	local out="$1" code="$2"

	[ "${code}" = '200' ] || die "accounts page returned ${code}"
	grep -q 'Cloudflare Accounts' "${out}" || die 'accounts page lacks the "Cloudflare Accounts" title'
	if grep -qF "${denied_marker}" "${out}"; then
		die 'accounts page is the permission-denied page'
	fi
	require_clean "$(cat "${out}")"
}

require_denied() {
	local out="$1"

	grep -qF "${denied_marker}" "${out}" || die 'expected the permission-denied page' "$(head -c 400 "${out}")"
	if grep -q 'Cloudflare Accounts' "${out}"; then
		die 'permission-denied response still contains the accounts page'
	fi
}

admin_jar="${work}/admin.jar"
viewer_jar="${work}/viewer.jar"
# cut drains its input, so no stage of the pipeline dies of SIGPIPE.
token="cfsmoke_$(head -c 600 /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9' | cut -c 1-40)"

step='setup: stack up'
[ "${#token}" -eq 48 ] || die 'could not generate a test token'
"${dc[@]}" up -d --build --wait >"${work}/up.log" 2>&1 || die "$(tail -n 40 "${work}/up.log")"
counts_before="$(core_counts)"
pass

step='1 install and enable through cli/plugin_manage.php'
out="$(plugin_manage --install --enable 2>&1)" || die "${out}"
require_clean "${out}"
grep -qF 'Plugin cloudflare installed successfully.' <<<"${out}" || die 'install not reported' "${out}"
grep -qF 'Plugin cloudflare enabled.' <<<"${out}" || die 'enable not reported' "${out}"
pass

step='2 plugin tables exist and plugin_config.status = 1'
tables="$(plugin_tables)"
[ -n "${tables}" ] || die 'no plugin_cloudflare_* tables'
[ "$(plugin_status)" = '1' ] || die "status is $(plugin_status)"
realms="$(sql "SELECT id, file FROM plugin_realms WHERE plugin = 'cloudflare' ORDER BY id")"
[ "$(wc -l <<<"${realms}")" -eq 2 ] || die 'expected two plugin realms' "${realms}"
pass

step='3 disable, re-enable and reinstall keep the tables'
out="$(plugin_manage --disable 2>&1)" || die "${out}"
require_clean "${out}"
[ "$(plugin_status)" = '4' ] || die "status after disable is $(plugin_status)"
out="$(plugin_manage --enable 2>&1)" || die "${out}"
require_clean "${out}"
[ "$(plugin_status)" = '1' ] || die "status after enable is $(plugin_status)"
# The CLI skips an installed plugin, so call the installer plugins.php uses.
out="$("${dc[@]}" exec -T -u www-data web php -r \
	'chdir("/var/www/html/cacti"); include "./include/cli_check.php"; api_plugin_install("cloudflare");' 2>&1)" || die "${out}"
require_clean "${out}"
[ "$(plugin_status)" = '4' ] || die "status after reinstall is $(plugin_status)"
out="$(plugin_manage --enable 2>&1)" || die "${out}"
require_clean "${out}"
[ "$(plugin_status)" = '1' ] || die "status after reinstall and enable is $(plugin_status)"
[ "$(plugin_tables)" = "${tables}" ] || die 'tables changed' "$(plugin_tables)"
[ "$(sql "SELECT id, file FROM plugin_realms WHERE plugin = 'cloudflare' ORDER BY id")" = "${realms}" ] \
	|| die 'realm ids changed, which would orphan user_auth_realm grants'
pass

step='4 admin gets the accounts page over HTTP'
login admin "${admin_pass}" "${admin_jar}"
code="$(http "${admin_jar}" "${work}/admin-accounts.html" "${page}")"
require_accounts_page "${work}/admin-accounts.html" "${code}"
pass

step='4b a disabled plugin page is denied, and served again once re-enabled'
out="$(plugin_manage --disable 2>&1)" || die "${out}"
http "${admin_jar}" "${work}/admin-disabled.html" "${page}" >/dev/null
require_denied "${work}/admin-disabled.html"
out="$(plugin_manage --enable 2>&1)" || die "${out}"
code="$(http "${admin_jar}" "${work}/admin-reenabled.html" "${page}")"
require_accounts_page "${work}/admin-reenabled.html" "${code}"
pass

step='5 a user with only Cloudflare: View is denied the accounts page'
view_realm="$(sql "SELECT id + 100 FROM plugin_realms WHERE plugin = 'cloudflare' AND file = 'cloudflare.php'")"
[ -n "${view_realm}" ] || die 'no realm for cloudflare.php'
# shellcheck disable=SC2016 # PHP source; $argv is PHP's, not the shell's.
viewer_hash="$("${dc[@]}" exec -T web php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "${viewer_pass}")"
# Columns follow tests/e2e/docker/setup.sh in Cacti release/1.2.31. Realm 8
# (Console Access) lets the user log in; the plugin realm is the only other.
sql "INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, show_tree, show_list, show_preview, graph_settings, login_opts, policy_graphs, policy_trees, policy_hosts, policy_graph_templates, enabled, lastchange, lastlogin, password_history, locked, failed_attempts, lastfail, reset_perms)
	VALUES ('viewer', '${viewer_hash}', 0, 'Cloudflare Viewer', '', '', 'on', 'on', 'on', 'on', 1, 2, 2, 2, 2, 'on', -1, -1, '-1', '', 0, 0, 0)"
viewer_id="$(sql "SELECT id FROM user_auth WHERE username = 'viewer'")"
sql "INSERT INTO user_auth_realm (realm_id, user_id) VALUES (8, ${viewer_id}), (${view_realm}, ${viewer_id})"
login viewer "${viewer_pass}" "${viewer_jar}"
http "${viewer_jar}" "${work}/viewer-accounts.html" "${page}" >/dev/null
require_denied "${work}/viewer-accounts.html"
pass

step='5b verify with a file token fails as unreachable and stores no token'
printf '%s' "${token}" | "${dc[@]}" exec -T web sh -c \
	'umask 077 && mkdir -p /var/lib/cf-smoke && cat > /var/lib/cf-smoke/token && chown -R www-data:www-data /var/lib/cf-smoke'
http "${admin_jar}" "${work}/edit.html" "${page}?action=edit" >/dev/null
csrf="$(csrf_from "${work}/edit.html")"
[ -n "${csrf}" ] || die 'no __csrf_magic token on the edit form'
http "${admin_jar}" "${work}/save.html" \
	--data-urlencode 'action=save' \
	--data-urlencode 'save_component_account=1' \
	--data-urlencode 'id=0' \
	--data-urlencode 'name=smoke' \
	--data-urlencode 'cf_account_id=0123456789abcdef0123456789abcdef' \
	--data-urlencode 'token_source=file' \
	--data-urlencode 'token_file=/var/lib/cf-smoke/token' \
	--data-urlencode 'enabled=on' \
	--data-urlencode 'collect_tunnels=on' \
	--data-urlencode 'discovery_interval=300' \
	--data-urlencode "__csrf_magic=${csrf}" \
	"${page}" >/dev/null
account_id="$(sql "SELECT id FROM plugin_cloudflare_accounts WHERE name = 'smoke'")"
[ -n "${account_id}" ] || die 'account was not saved' "$(head -c 600 "${work}/save.html")"
# The save redirects to a header=false fragment that carries no token, so
# take it from the list page the actions form is posted from.
http "${admin_jar}" "${work}/list.html" "${page}" >/dev/null
grep -qF "chk_${account_id}" "${work}/list.html" || die 'saved account is not on the list page'
csrf="$(csrf_from "${work}/list.html")"
[ -n "${csrf}" ] || die 'no __csrf_magic token on the list page'
http "${admin_jar}" "${work}/confirm.html" \
	--data-urlencode 'action=actions' \
	--data-urlencode 'drp_action=2' \
	--data-urlencode "chk_${account_id}=on" \
	--data-urlencode "__csrf_magic=${csrf}" \
	"${page}" >/dev/null
grep -qF 'Verify the following Cloudflare Account' "${work}/confirm.html" || die 'no verify confirmation page'
csrf="$(csrf_from "${work}/confirm.html")"
http "${admin_jar}" "${work}/verify.html" \
	--data-urlencode 'action=actions' \
	--data-urlencode 'drp_action=2' \
	--data-urlencode "selected_items=a:1:{i:0;i:${account_id};}" \
	--data-urlencode "__csrf_magic=${csrf}" \
	"${page}" >/dev/null
# unreachable proves the token was read from the file and handed to the
# transport; a file error would leave the later grep with nothing to find.
status="$(sql "SELECT CONCAT(status, '|', IFNULL(last_checked_at, 'null')) FROM plugin_cloudflare_accounts WHERE id = ${account_id}")"
case "${status}" in
	unreachable\|null) die "verify did not record a check: ${status}" ;;
	unreachable\|*) ;;
	*) die "expected status unreachable, got ${status}" ;;
esac
pass

step='6 uninstall drops plugin tables and settings and leaves Cacti data alone'
sql "INSERT INTO settings (name, value) VALUES ('cloudflare_ui_timeout', '10') ON DUPLICATE KEY UPDATE value = VALUES(value)"
out="$(plugin_manage --disable --uninstall 2>&1)" || die "${out}"
require_clean "${out}"
[ -z "$(plugin_tables)" ] || die 'tables remain' "$(plugin_tables)"
[ "$(sql "SELECT COUNT(*) FROM settings WHERE name LIKE 'cloudflare!_%' ESCAPE '!'")" = '0' ] || die 'cloudflare_ settings remain'
[ "$(plugin_status)" = 'none' ] || die "plugin_config row remains with status $(plugin_status)"
[ "$(sql "SELECT COUNT(*) FROM plugin_realms WHERE plugin = 'cloudflare'")" = '0' ] || die 'plugin realms remain'
[ "$(sql "SELECT COUNT(*) FROM user_auth_realm WHERE realm_id = ${view_realm}")" = '0' ] || die 'viewer realm grant remains'
counts_after="$(core_counts)"
[ "${counts_after}" = "${counts_before}" ] || die "host/graph_local/data_template counts ${counts_before} became ${counts_after}"
pass

step='7 the token appears in no log, page or database dump'
"${dc[@]}" exec -T web grep -q 'CLOUDFLARE' log/cacti.log || die 'cacti.log has no CLOUDFLARE lines, so the search below proves nothing'
if "${dc[@]}" exec -T web grep -rqF "${token}" log/; then
	die 'token found under log/'
fi
if "${dc[@]}" logs --no-color web gateway 2>&1 | grep -qF "${token}"; then
	die 'token found in the Apache logs'
fi
if grep -rqF "${token}" "${work}"; then
	die 'token found in a saved HTTP response'
fi
if "${dc[@]}" exec -T db mariadb-dump --user=root --password=smoke-root --all-databases 2>/dev/null | grep -qF "${token}"; then
	die 'token found in the database'
fi
pass

step='8 no PHP errors in cacti.log or the Apache error log'
if "${dc[@]}" exec -T web grep -E 'ERROR PHP|PHP (Fatal|Parse|Warning|Notice|Deprecated)' log/cacti.log; then
	die 'cacti.log has PHP errors'
fi
if "${dc[@]}" logs --no-color web 2>&1 | grep -E 'PHP (Fatal|Parse|Warning|Notice|Deprecated)'; then
	die 'the Apache error log has PHP errors'
fi
pass
