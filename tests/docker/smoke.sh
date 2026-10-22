#!/usr/bin/env bash
# Install smoke for the plugin against a real Cacti, 1.2.31 unless `make
# smoke CACTI_REF=...` names another tag. Brings up compose.cacti.yml, walks
# the plugin lifecycle through Cacti's CLI and web UI, polls the tunnel
# graphs through the real poller and rrdtool, and stops at the first failed
# step. KEEP=1 leaves the stack running; CF_PLUGIN_DIR mounts another
# directory in place of this checkout.
#
# Needs tests/docker/build/cacti.tar; run through `make smoke`.

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Distinct from `make smoke-up` (cf-smoke on 8090) so a run never tears
# down a stack someone is using.
project="${CF_SMOKE_PROJECT:-cf-smoke-run}"
port="${CF_SMOKE_PORT:-8091}"
admin_pass="${CF_SMOKE_ADMIN_PASS:-smoke-admin}"
viewer_pass='smoke-viewer'
base="http://127.0.0.1:${port}/cacti"
page="${base}/plugins/cloudflare/cloudflare_accounts.php"
viewer_page="${base}/plugins/cloudflare/cloudflare.php"
# The account tag in tests/fixtures/cloudflare/tunnel-list.json; the fake
# answers only under this account.
cf_account='699d98642c564d2e855e9661899b7252'
fixture_tunnels='0b6a8e4c-6c3b-4f64-9d6e-2f6c1f8a1d11|staging|inactive
7d1c3f52-91a4-4a39-8a55-3e1b0c6f2b77|old-office|down
f70ff985-a4ef-4643-bbbc-4a0ed4fc8415|blog|healthy'
denied_marker='You are not permitted to access this section of Cacti.'
cf_data_template='0a8e0fd46e03c70b32a5908fc0463e17'
cf_data_query='82e9f55ad870c876a825287744c2afbb'

export CF_SMOKE_PORT="${port}"
export CF_SMOKE_ADMIN_PASS="${admin_pass}"

dc=(docker compose -p "${project}" -f "${here}/compose.cacti.yml")
work="$(mktemp -d)"
captures="${work}/captures"
mkdir "${captures}"
step='setup'

teardown() {
	local rc=$?

	if [[ "${rc}" -ne 0 ]]; then
		printf 'FAIL: %s\n' "${step}"
	fi

	# A KEEP=1 stack would otherwise stay at DEBUG after a failed poll_debug.
	restore_verbosity >/dev/null 2>&1 || true

	if [[ "${KEEP:-0}" = '1' ]]; then
		printf 'KEEP=1: stack %s left running at %s/\n' "${project}" "${base}" >&2
	else
		"${dc[@]}" down --volumes --remove-orphans >/dev/null 2>&1 || true
	fi

	rm -rf "${work}"
	exit "${rc}"
}

if [[ -n "$("${dc[@]}" ps -q 2>/dev/null)" ]]; then
	printf 'FAIL: compose project %s is already running; stop it or set CF_SMOKE_PROJECT\n' "${project}" >&2
	rm -rf "${work}"
	exit 1
fi

trap teardown EXIT

pass() {
	printf 'PASS: %s\n' "${step}"
}

die() {
	printf '  %s\n' "$@" >&2
	exit 1
}

sql() {
	local query="$1"

	"${dc[@]}" exec -T db mariadb --user=cactiuser --password=cactiuser \
		--batch --skip-column-names cacti --execute="${query}"
}

plugin_manage() {
	"${dc[@]}" exec -T -u www-data web php cli/plugin_manage.php --plugin=cloudflare "$@"
}

# Before 1.2.31, cli/plugin_manage.php enables a plugin only as part of
# --install, so an installed one is enabled the way plugins.php does it.
plugin_enable() {
	# shellcheck disable=SC2016 # a literal line of plugin_manage.php.
	if "${dc[@]}" exec -T web grep -qF 'Enabling Plugin $plugin.' cli/plugin_manage.php; then
		plugin_manage --enable
	else
		"${dc[@]}" exec -T -u www-data web php -r \
			'chdir("/var/www/html/cacti"); include "./include/cli_check.php"; api_plugin_enable("cloudflare");'
	fi
}

plugin_status() {
	sql "SELECT COALESCE(MAX(status), 'none') FROM plugin_config WHERE directory = 'cloudflare'"
}

plugin_tables() {
	sql "SELECT table_name FROM information_schema.tables WHERE table_schema = 'cacti' AND table_name LIKE 'plugin!_cloudflare!_%' ESCAPE '!' ORDER BY table_name"
}

# The plugin's own data template is left out: install imports it and
# uninstall keeps it, which step 6 checks on its own.
core_counts() {
	sql "SELECT (SELECT COUNT(*) FROM host), (SELECT COUNT(*) FROM graph_local), (SELECT COUNT(*) FROM data_template WHERE hash != '${cf_data_template}')"
}

# Host template, data query, data template and the three graph templates,
# each by its md5('plugin_cloudflare/<key>') hash.
template_objects() {
	sql "SELECT (SELECT COUNT(*) FROM host_template WHERE hash = '7b64ac0c934b4a22601a2a8e03f0d4c3')
		+ (SELECT COUNT(*) FROM snmp_query WHERE hash = '82e9f55ad870c876a825287744c2afbb')
		+ (SELECT COUNT(*) FROM data_template WHERE hash = '${cf_data_template}')
		+ (SELECT COUNT(*) FROM graph_templates WHERE hash IN (MD5('plugin_cloudflare/graph_template/connectors'),
			MD5('plugin_cloudflare/graph_template/health'), MD5('plugin_cloudflare/graph_template/collection')))"
}

resource_matches() {
	"${dc[@]}" exec -T web cmp -s plugins/cloudflare/templates/resource/cloudflare_tunnels.xml \
		resource/script_server/cloudflare_tunnels.xml
}

# capture <name> <command...>: saves stdout and stderr under ${captures}.
# A failed command or empty output dies, so a dead container or a broken
# dump can never pass a later search as "no match".
capture() {
	local name="$1" rc=0
	shift

	"$@" >"${captures}/${name}" 2>&1 || rc=$?
	[[ "${rc}" -eq 0 ]] || die "capturing ${name} exited ${rc}" "$(tail -n 20 "${captures}/${name}")"
	[[ -s "${captures}/${name}" ]] || die "capture ${name} is empty"
}

# found <grep args...>: 0 on a match, 1 on none; grep errors die.
found() {
	local rc=0

	grep -q "$@" || rc=$?
	[[ "${rc}" -le 1 ]] || die "grep $* exited ${rc}"

	return "${rc}"
}

no_token() {
	local file="$1" label="$2"

	if found -F -- "${token}" "${file}"; then
		die "token leaked into ${label}"
	fi
}

# A CLI run that printed a PHP error did not succeed, whatever it exits with.
require_clean() {
	local output="$1"

	if grep -qiE 'PHP (Fatal|Parse|Warning|Notice|Deprecated)|Fatal error|Uncaught' <<<"${output}"; then
		die 'PHP error in output:' "${output}"
	fi
}

# http <jar> <out> <curl args...>; prints the final status code.
http() {
	local jar="$1" out="$2"
	shift 2
	curl -sS -L --max-time 30 -b "${jar}" -c "${jar}" -o "${out}" -w '%{http_code}' "$@"
}

csrf_from() {
	local file="$1"

	# sed rather than head picks the first match: sed drains its input, so
	# grep never dies of SIGPIPE, which pipefail would report as a failure.
	grep -oE "name=.__csrf_magic. value=\"[^\"]+\"" "${file}" | sed -n -E '1s/.*value="([^"]+)"/\1/p' || true
}

login() {
	local user="$1" pass="$2" jar="$3" token code

	code="$(http "${jar}" "${work}/login-form.html" "${base}/index.php")"
	[[ "${code}" = '200' ]] || die "login form returned ${code}"

	token="$(csrf_from "${work}/login-form.html")"
	[[ -n "${token}" ]] || die 'no __csrf_magic token on the login form'

	code="$(http "${jar}" "${work}/login-${user}.html" \
		--data-urlencode 'action=login' \
		--data-urlencode "login_username=${user}" \
		--data-urlencode "login_password=${pass}" \
		--data-urlencode 'realm=local' \
		--data-urlencode "__csrf_magic=${token}" \
		"${base}/index.php")"
	[[ "${code}" = '200' ]] || die "login POST for ${user} returned ${code}"

	if grep -q 'login_username' "${work}/login-${user}.html"; then
		die "${user} is still on the login form after posting credentials"
	fi
}

require_accounts_page() {
	local out="$1" code="$2"

	[[ "${code}" = '200' ]] || die "accounts page returned ${code}"
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

# Runs as the fake's own unprivileged user; its control files live on a
# tmpfs that only that user can write and no HTTP request can reach.
fake() {
	"${dc[@]}" exec -T -u www-data cloudflare-fake "$@"
}

fake_requests() {
	fake cat /run/cf-fake/requests.log
}

# Cacti's cron runs poller.php as the web user in this image, so smoke does too.
run_poller() {
	local out

	out="$("${dc[@]}" exec -T -u www-data web php poller.php --force 2>&1)" || die 'poller.php failed' "${out}"
	require_clean "${out}"
}

job_started() {
	sql "SELECT IFNULL(MAX(last_started_at), 0) FROM plugin_cloudflare_jobs WHERE account_id = ${account_id}"
}

# The collector is detached from poller.php, so wait for it to finish a
# run that began after $1, for at most 60 seconds.
wait_for_run() {
	local since="$1" i

	for ((i = 0; i < 60; i++)); do
		if [[ "$(sql "SELECT COUNT(*) FROM plugin_cloudflare_jobs WHERE account_id = ${account_id} AND last_started_at > ${since} AND lease_owner IS NULL AND last_finished_at >= last_started_at")" = '1' ]]; then
			return 0
		fi
		sleep 1
	done

	die "no collector run finished within 60 seconds" "$(sql "SELECT * FROM plugin_cloudflare_jobs")"
}

# There is no ps in the image, so read /proc. The pattern is matched here,
# so the scanning shell's own command line never matches it, and an empty
# listing dies rather than reading as "none running".
collectors_running() {
	local procs

	# A process can exit between the glob and the read; stderr is redirected
	# before the input so that failed open stays quiet.
	# shellcheck disable=SC2016 # $f belongs to the container's shell.
	procs="$("${dc[@]}" exec -T web sh -c 'for f in /proc/[0-9]*/cmdline; do tr "\0" " " 2>/dev/null < "$f"; echo; done')" \
		|| die 'could not list the web container processes'
	[[ -n "${procs}" ]] || die 'the web container process list is empty'
	grep -c 'poller_cloudflare\.php' <<<"${procs}" || true
}

tunnel_rows() {
	sql "SELECT * FROM plugin_cloudflare_tunnels ORDER BY id"
}

connector_rows() {
	sql "SELECT * FROM plugin_cloudflare_connectors ORDER BY id"
}

account_row() {
	sql "SELECT * FROM plugin_cloudflare_accounts WHERE id = ${account_id}"
}

log_size() {
	"${dc[@]}" exec -T web stat -c %s log/cacti.log
}

# cacti.log from byte offset $1 on.
log_since() {
	"${dc[@]}" exec -T web tail -c "+$(($1 + 1))" log/cacti.log
}

# exec_background() logs this line at DEBUG before it spawns
# (lib/poller.php:147 in 1.2.31); poller_cloudflare.php leaves no trace of
# its own when it exits at the enabled check.
spawn_pattern='About to Spawn a Remote Process \[CMD: [^]]*, ARGS: [^]]*poller_cloudflare\.php'

# Set while log_verbosity or data_source_trace is raised; teardown restores
# them too, because run_poller and log_since exit through die.
verbosity_pending=''
verbosity_saved=''
trace_saved=''

restore_setting() {
	local name="$1" value="$2"

	if [[ -n "${value}" ]]; then
		sql "REPLACE INTO settings (name, value) VALUES ('${name}', '${value}')"
	else
		sql "DELETE FROM settings WHERE name = '${name}'"
	fi
}

save_log_settings() {
	verbosity_saved="$(sql "SELECT IFNULL(MAX(value), '') FROM settings WHERE name = 'log_verbosity'")"
	trace_saved="$(sql "SELECT IFNULL(MAX(value), '') FROM settings WHERE name = 'data_source_trace'")"
	verbosity_pending=1
}

restore_verbosity() {
	[[ -n "${verbosity_pending}" ]] || return 0
	restore_setting log_verbosity "${verbosity_saved}"
	restore_setting data_source_trace "${trace_saved}"
	verbosity_pending=''
}

# poll_debug <log file>: one forced poll at log_verbosity DEBUG (5), with
# the setting put back afterwards; saves what cacti.log gained to $1.
poll_debug() {
	local file="$1" offset

	save_log_settings
	sql "REPLACE INTO settings (name, value) VALUES ('log_verbosity', '5')"
	offset="$(log_size)"
	run_poller
	log_since "${offset}" >"${file}"
	restore_verbosity
	# An empty capture would make the absence check in 5k vacuous.
	found -F 'DEBUG' "${file}" || die 'the DEBUG poll wrote no DEBUG lines to cacti.log'
}

job_result() {
	sql "SELECT CONCAT_WS('|', last_status, IFNULL(last_error_class, 'null')) FROM plugin_cloudflare_jobs WHERE account_id = ${account_id}"
}

# The graph sync runs in the collector after the job row is finished, so
# wait_for_run alone could return while it is still reindexing.
wait_for_collectors() {
	local i

	for ((i = 0; i < 60; i++)); do
		[[ "$(collectors_running)" = '0' ]] && return 0
		sleep 1
	done

	die 'poller_cloudflare.php still running after 60 seconds'
}

# One metrics poll: the job is made due so the collector runs after it.
# rrdtool refuses a second update to an RRD within the same second.
poll_due() {
	local started

	sql "UPDATE plugin_cloudflare_jobs SET next_run_at = 0 WHERE account_id = ${account_id}"
	started="$(job_started)"
	run_poller
	wait_for_run "${started}"
	wait_for_collectors
	sleep 2
}

# The RRD behind blog's connectors graph; all three of a tunnel's graphs
# share it.
blog_rrd() {
	sql "SELECT REPLACE(dtd.data_source_path, '<path_rra>', '/var/www/html/cacti/rra')
		FROM plugin_cloudflare_resources r
		JOIN plugin_cloudflare_tunnels t ON t.id = r.tunnel_id
		JOIN data_template_data dtd ON dtd.local_data_id = r.local_data_id
		WHERE r.account_id = ${account_id} AND r.kind = 'graph' AND t.name = 'blog'
		AND r.template_hash = MD5('plugin_cloudflare/graph_template/connectors')"
}

# rrd_column <ds> <output>: prints the values of the column named <ds> in
# `rrdtool lastupdate` or `rrdtool fetch` output, one per data row. The
# header line names the data sources; data rows lead with "timestamp:".
rrd_column() {
	local ds="$1" output="$2"

	awk -v ds="${ds}" '
		NR == 1 { for (i = 1; i <= NF; i++) if ($i == ds) col = i + 1; next }
		col && /^[0-9]+:/ { print $col }
		END { if (!col) exit 1 }
	' <<<"${output}" || die "no data source ${ds} in rrdtool output" "${output}"
}

# last_value <rrd> <ds>: the DS's last update, by name, never by position.
last_value() {
	local rrd="$1" ds="$2" out

	out="$("${dc[@]}" exec -T web rrdtool lastupdate "${rrd}" 2>&1)" || die "rrdtool lastupdate ${rrd} failed" "${out}"
	rrd_column "${ds}" "${out}"
}

admin_jar="${work}/admin.jar"
viewer_jar="${work}/viewer.jar"
# cut drains its input, so no stage of the pipeline dies of SIGPIPE.
token="cfsmoke_$(head -c 600 /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9' | cut -c 1-40)"

step='setup: stack up'
[[ "${#token}" -eq 48 ]] || die 'could not generate a test token'
"${dc[@]}" up -d --build --wait >"${work}/up.log" 2>&1 || die "$(tail -n 40 "${work}/up.log")"
# A device of our own, so the uninstall comparison covers a row this run
# created and not only what the installer seeded.
out="$("${dc[@]}" exec -T -u www-data web php cli/add_device.php --description=smoke-host --ip=192.0.2.10 --template=0 --avail=none --version=0 2>&1)" || die "${out}"
[[ "$(sql "SELECT COUNT(*) FROM host WHERE description = 'smoke-host'")" = '1' ]] || die 'seed device was not added' "${out}"
printf '  Cacti %s\n' "$(sql "SELECT cacti FROM version")"
pass

step='setup: only the web container trusts the test CA, and only for api.cloudflare.com'
[[ "$(docker network inspect -f '{{.Internal}}' "${project}_smoke")" = 'true' ]] || die 'the smoke network has a route out'
fake_ip="$("${dc[@]}" exec -T web getent hosts cloudflare-fake | awk '{print $1}')"
api_ip="$("${dc[@]}" exec -T web getent hosts api.cloudflare.com | awk '{print $1}')"
[[ -n "${fake_ip}" && "${api_ip}" = "${fake_ip}" ]] || die "api.cloudflare.com resolves to '${api_ip}', the fake is '${fake_ip}'"
# The plugin sets no CA option, so PHP curl must fall back to the system
# bundle that update-ca-certificates rebuilt. Same options as CurlTransport.
# shellcheck disable=SC2016 # PHP source; $argv is PHP's, not the shell's.
probe='$c = curl_init($argv[1]);
curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_NOPROXY => "*", CURLOPT_TIMEOUT => 10));
curl_exec($c);
printf("%s|%s|%d|%d", ini_get("curl.cainfo"), ini_get("openssl.cafile"), curl_errno($c), curl_getinfo($c, CURLINFO_RESPONSE_CODE));'
trust="$("${dc[@]}" exec -T -u www-data web php -r "${probe}" 'https://api.cloudflare.com/client/v4/')"
[[ "${trust}" = '||0|404' ]] || die "PHP curl to the fake: cainfo|cafile|errno|status = ${trust}"
# Negative control: the same CA, a name the leaf does not carry.
trust="$("${dc[@]}" exec -T -u www-data web php -r "${probe}" 'https://cloudflare-fake/client/v4/')"
[[ "${trust}" = '||60|0' ]] || die "a name outside the SAN was not refused: ${trust}"
# A base64 line of this run's CA identifies it in any bundle. The gateway is
# the only container with a route out, and it must not trust the CA.
ca_line="$("${dc[@]}" exec -T web sed -n 2p /run/cf-smoke-ca/ca.crt)"
[[ "${#ca_line}" -ge 40 ]] || die 'could not read the test CA'
"${dc[@]}" exec -T web grep -qF -- "${ca_line}" /etc/ssl/certs/ca-certificates.crt \
	|| die 'the test CA is not in the web container bundle'
if "${dc[@]}" exec -T gateway grep -rqsF -- "${ca_line}" /etc/ssl /usr/local/share/ca-certificates; then
	die 'the test CA is installed in the gateway'
fi
pass

step='1 install and enable through cli/plugin_manage.php'
out="$(plugin_manage --install --enable 2>&1)" || die "${out}"
require_clean "${out}"
grep -qF 'Plugin cloudflare installed successfully.' <<<"${out}" || die 'install not reported' "${out}"
grep -qF 'Plugin cloudflare enabled.' <<<"${out}" || die 'enable not reported' "${out}"
pass

step='2 plugin tables exist and plugin_config.status = 1'
tables="$(plugin_tables)"
[[ -n "${tables}" ]] || die 'no plugin_cloudflare_* tables'
[[ "$(plugin_status)" = '1' ]] || die "status is $(plugin_status)"
realms="$(sql "SELECT id, file FROM plugin_realms WHERE plugin = 'cloudflare' ORDER BY id")"
[[ "$(wc -l <<<"${realms}")" -eq 2 ]] || die 'expected two plugin realms' "${realms}"
pass

step='2b install imported the templates and copied the data query XML'
[[ "$(template_objects)" = '6' ]] || die "found $(template_objects) of 6 template objects"
resource_matches || die 'resource/script_server/cloudflare_tunnels.xml is missing or differs'
shipped_sha="$("${dc[@]}" exec -T web sha256sum plugins/cloudflare/templates/cloudflare_templates.xml | cut -d ' ' -f 1)"
[[ "${#shipped_sha}" -eq 64 ]] || die 'could not hash the shipped templates in the container'
recorded_sha="$(sql "SELECT value FROM settings WHERE name = 'cloudflare_templates_sha256'")"
[[ "${recorded_sha}" = "${shipped_sha}" ]] || die "recorded template hash '${recorded_sha}' is not ${shipped_sha}"
[[ "$(sql "SELECT COUNT(*) FROM plugin_cloudflare_metric_mappings")" = '6' ]] || die 'metric mappings were not recorded'
pass

step='3 disable, re-enable and reinstall keep the tables'
out="$(plugin_manage --disable 2>&1)" || die "${out}"
require_clean "${out}"
[[ "$(plugin_status)" = '4' ]] || die "status after disable is $(plugin_status)"
out="$(plugin_enable 2>&1)" || die "${out}"
require_clean "${out}"
[[ "$(plugin_status)" = '1' ]] || die "status after enable is $(plugin_status)"
# The CLI skips an installed plugin, so call the installer plugins.php uses.
out="$("${dc[@]}" exec -T -u www-data web php -r \
	'chdir("/var/www/html/cacti"); include "./include/cli_check.php"; api_plugin_install("cloudflare");' 2>&1)" || die "${out}"
require_clean "${out}"
[[ "$(plugin_status)" = '4' ]] || die "status after reinstall is $(plugin_status)"
out="$(plugin_enable 2>&1)" || die "${out}"
require_clean "${out}"
[[ "$(plugin_status)" = '1' ]] || die "status after reinstall and enable is $(plugin_status)"
[[ "$(plugin_tables)" = "${tables}" ]] || die 'tables changed' "$(plugin_tables)"
[[ "$(sql "SELECT id, file FROM plugin_realms WHERE plugin = 'cloudflare' ORDER BY id")" = "${realms}" ]] \
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
out="$(plugin_enable 2>&1)" || die "${out}"
code="$(http "${admin_jar}" "${work}/admin-reenabled.html" "${page}")"
require_accounts_page "${work}/admin-reenabled.html" "${code}"
pass

step='5 a user with only Cloudflare: View is denied the accounts page'
view_realm="$(sql "SELECT id + 100 FROM plugin_realms WHERE plugin = 'cloudflare' AND file = 'cloudflare.php'")"
[[ -n "${view_realm}" ]] || die 'no realm for cloudflare.php'
# shellcheck disable=SC2016 # PHP source; $argv is PHP's, not the shell's.
viewer_hash="$("${dc[@]}" exec -T web php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "${viewer_pass}")"
# Columns follow tests/e2e/docker/setup.sh in Cacti release/1.2.31. Realm 8
# (Console Access) lets the user log in; the plugin realm is the only other.
sql "INSERT INTO user_auth (username, password, realm, full_name, must_change_password, password_change, show_tree, show_list, show_preview, graph_settings, login_opts, policy_graphs, policy_trees, policy_hosts, policy_graph_templates, enabled, lastchange, lastlogin, password_history, locked, failed_attempts, lastfail, reset_perms)
	VALUES ('viewer', '${viewer_hash}', 0, 'Cloudflare Viewer', '', '', 'on', 'on', 'on', 'on', 1, 2, 2, 2, 2, 'on', -1, -1, '-1', '', 0, 0, 0)"
viewer_id="$(sql "SELECT id FROM user_auth WHERE username = 'viewer'")"
sql "INSERT INTO user_auth_realm (realm_id, user_id) VALUES (8, ${viewer_id}), (${view_realm}, ${viewer_id})"
login viewer "${viewer_pass}" "${viewer_jar}"
# Positive control: realm 8 grants the console, so the viewer's session is
# good and the denial below comes from the plugin realm, not from auth.
code="$(http "${viewer_jar}" "${work}/viewer-console.html" "${base}/index.php")"
[[ "${code}" = '200' ]] || die "viewer console returned ${code}"
if grep -qF "${denied_marker}" "${work}/viewer-console.html"; then
	die 'viewer is denied the console, so the realm 8 grant did not take'
fi
http "${viewer_jar}" "${work}/viewer-accounts.html" "${page}" >/dev/null
require_denied "${work}/viewer-accounts.html"
pass

step='5b verify with a file token reaches the fake over verified TLS and stores no token'
printf '%s' "${token}" | "${dc[@]}" exec -T web sh -c \
	'umask 077 && mkdir -p /var/lib/cf-smoke && cat > /var/lib/cf-smoke/token && chown -R www-data:www-data /var/lib/cf-smoke'
[[ "$("${dc[@]}" exec -T web stat -c '%a %U' /var/lib/cf-smoke/token)" = '600 www-data' ]] \
	|| die 'the token file is not 0600 and owned by the web and poller user'
# The fake compares the bearer credential with this copy and answers 401
# for anything else, so an ok status proves the file token was sent.
printf '%s' "${token}" | fake sh -c 'umask 077 && cat > /run/cf-fake/token'
http "${admin_jar}" "${work}/edit.html" "${page}?action=edit" >/dev/null
csrf="$(csrf_from "${work}/edit.html")"
[[ -n "${csrf}" ]] || die 'no __csrf_magic token on the edit form'
http "${admin_jar}" "${work}/save.html" \
	--data-urlencode 'action=save' \
	--data-urlencode 'save_component_account=1' \
	--data-urlencode 'id=0' \
	--data-urlencode 'name=smoke' \
	--data-urlencode "cf_account_id=${cf_account}" \
	--data-urlencode 'token_source=file' \
	--data-urlencode 'token_file=/var/lib/cf-smoke/token' \
	--data-urlencode 'enabled=on' \
	--data-urlencode 'collect_tunnels=on' \
	--data-urlencode 'discovery_interval=300' \
	--data-urlencode "__csrf_magic=${csrf}" \
	"${page}" >/dev/null
account_id="$(sql "SELECT id FROM plugin_cloudflare_accounts WHERE name = 'smoke'")"
[[ -n "${account_id}" ]] || die 'account was not saved' "$(head -c 600 "${work}/save.html")"
# The save redirects to a header=false fragment that carries no token, so
# take it from the list page the actions form is posted from.
http "${admin_jar}" "${work}/list.html" "${page}" >/dev/null
grep -qF "chk_${account_id}" "${work}/list.html" || die 'saved account is not on the list page'
csrf="$(csrf_from "${work}/list.html")"
[[ -n "${csrf}" ]] || die 'no __csrf_magic token on the list page'
http "${admin_jar}" "${work}/confirm.html" \
	--data-urlencode 'action=actions' \
	--data-urlencode 'drp_action=2' \
	--data-urlencode "chk_${account_id}=on" \
	--data-urlencode "__csrf_magic=${csrf}" \
	"${page}" >/dev/null
grep -qF 'Verify the following Cloudflare Account' "${work}/confirm.html" || die 'no verify confirmation page'
csrf="$(csrf_from "${work}/confirm.html")"
[[ -n "${csrf}" ]] || die 'no __csrf_magic token on the verify confirmation page'
http "${admin_jar}" "${work}/verify.html" \
	--data-urlencode 'action=actions' \
	--data-urlencode 'drp_action=2' \
	--data-urlencode "selected_items=a:1:{i:0;i:${account_id};}" \
	--data-urlencode "__csrf_magic=${csrf}" \
	"${page}" >/dev/null
status="$(sql "SELECT CONCAT(status, '|', IFNULL(last_checked_at, 'null')) FROM plugin_cloudflare_accounts WHERE id = ${account_id}")"
case "${status}" in
	ok\|null) die "verify did not record a check: ${status}" ;;
	ok\|*) ;;
	*) die "expected status ok, got ${status}" ;;
esac
found -F "GET /client/v4/accounts/${cf_account}/tokens/verify 200" <(fake_requests) || die 'the fake saw no verify' "$(fake_requests)"
pass

step='5c poller.php launches the collector, which stores the three fixture tunnels'
[[ "$(sql "SELECT COUNT(*) FROM plugin_cloudflare_tunnels")" = '0' ]] || die 'tunnels exist before the first poll'
# The seeded automation network would have poller.php scan 192.168.1.0/24,
# which logs PHP warnings on a network with no route and fails step 8.
sql "UPDATE automation_networks SET enabled = ''"
started="$(job_started)"
poll_debug "${work}/log-5c"
# Positive control for 5f: an enabled plugin's hook does log the spawn.
found -E "${spawn_pattern}" "${work}/log-5c" || die 'no exec_background line for poller_cloudflare.php at DEBUG'
wait_for_run "${started}"
job="$(job_result)"
[[ "${job}" = 'ok|null' ]] || die "discovery job ended ${job}"
[[ "$(sql "SELECT COUNT(*) FROM plugin_cloudflare_tunnels")" = '3' ]] || die 'expected exactly 3 tunnels' "$(tunnel_rows)"
stored="$(sql "SELECT CONCAT_WS('|', cf_tunnel_id, name, cf_status) FROM plugin_cloudflare_tunnels WHERE account_id = ${account_id} AND lifecycle = 'active' ORDER BY cf_tunnel_id")"
[[ "${stored}" = "${fixture_tunnels}" ]] || die 'stored tunnels differ from the fixture' "${stored}"
found -E "^GET /client/v4/accounts/${cf_account}/cfd_tunnel\\?[^ ]*is_deleted=false[^ ]* 200$" <(fake_requests) \
	|| die 'the fake saw no tunnel listing' "$(fake_requests)"
connections="/client/v4/accounts/${cf_account}/cfd_tunnel"
fake_requests >"${work}/requests-5c"
for expect in \
	"f70ff985-a4ef-4643-bbbc-4a0ed4fc8415/connections 200" \
	"0b6a8e4c-6c3b-4f64-9d6e-2f6c1f8a1d11/connections 200" \
	"7d1c3f52-91a4-4a39-8a55-3e1b0c6f2b77/connections 401"; do
	found -Fx "GET ${connections}/${expect}" "${work}/requests-5c" || die "the fake did not log ${expect}" "$(cat "${work}/requests-5c")"
done
# blog's one connector from tunnel-connections.json, and nothing for the others.
[[ "$(sql "SELECT COUNT(*) FROM plugin_cloudflare_connectors")" = '1' ]] || die 'expected exactly 1 connector' "$(connector_rows)"
connector="$(sql "SELECT CONCAT_WS('|', t.name, c.cf_connector_id, c.version, c.arch, c.connection_count) FROM plugin_cloudflare_connectors c JOIN plugin_cloudflare_tunnels t ON t.id = c.tunnel_id")"
[[ "${connector}" = 'blog|1bedc50d-42b3-473c-b108-ff3d10c0d925|2022.7.1|linux_amd64|1' ]] || die "stored connector is ${connector}"
# The 401 with code 1001 on old-office's connections means that tunnel is
# unknown, not that the token was revoked.
[[ "$(sql "SELECT lifecycle FROM plugin_cloudflare_tunnels WHERE name = 'old-office'")" = 'active' ]] \
	|| die 'old-office is no longer active after its connections 401'
[[ "$(sql "SELECT status FROM plugin_cloudflare_accounts WHERE id = ${account_id}")" = 'ok' ]] \
	|| die "account status is $(sql "SELECT status FROM plugin_cloudflare_accounts WHERE id = ${account_id}") after a connections 401"
pass

step='5d the Cloudflare: View user sees the three tunnels on the Tunnels page'
code="$(http "${viewer_jar}" "${work}/viewer-tunnels.html" "${viewer_page}?action=tunnels")"
[[ "${code}" = '200' ]] || die "tunnels page returned ${code}"
if grep -qF "${denied_marker}" "${work}/viewer-tunnels.html"; then
	die 'the viewer is denied the tunnels page'
fi
if grep -qF 'could not be read' "${work}/viewer-tunnels.html"; then
	die 'the tunnels page shows the unreadable notice'
fi
require_clean "$(cat "${work}/viewer-tunnels.html")"
for name in blog staging old-office; do
	grep -qF ">${name}<" "${work}/viewer-tunnels.html" || die "tunnels page lacks ${name}"
done
pass

step='5e a 503 from Cloudflare leaves the tunnels, connectors and account unchanged'
before_rows="$(tunnel_rows)"
before_connectors="$(connector_rows)"
before_account="$(account_row)"
[[ -n "${before_connectors}" ]] || die 'no connectors to compare'
before_requests="$(fake_requests | wc -l)"
fake touch /run/cf-fake/fail-503
# A run that wrongly reconciled would stamp a later last_seen_at.
sleep 2
# The job is not due for another discovery_interval; make it due now.
sql "UPDATE plugin_cloudflare_jobs SET next_run_at = 0 WHERE account_id = ${account_id}"
started="$(job_started)"
run_poller
wait_for_run "${started}"
job="$(job_result)"
[[ "${job}" = 'failed|transient' ]] || die "discovery job ended ${job}, expected failed|transient"
fake_requests | tail -n "+$((before_requests + 1))" >"${work}/requests-503"
found -E "^GET /client/v4/accounts/${cf_account}/cfd_tunnel\\?[^ ]* 503$" "${work}/requests-503" \
	|| die 'the collector did not reach the fake in 503 mode' "$(cat "${work}/requests-503")"
[[ "$(tunnel_rows)" = "${before_rows}" ]] || die 'stored tunnels changed after a 503' "${before_rows}" '---' "$(tunnel_rows)"
[[ "$(connector_rows)" = "${before_connectors}" ]] \
	|| die 'stored connectors changed after a 503' "${before_connectors}" '---' "$(connector_rows)"
[[ "$(account_row)" = "${before_account}" ]] || die 'the account row changed after a 503' "${before_account}" '---' "$(account_row)"
fake rm /run/cf-fake/fail-503
pass

step='5f Create Cacti Device makes the account device, and the edit form turns on graphs'
[[ "$(sql "SELECT COUNT(*) FROM graph_templates WHERE hash IN (MD5('plugin_cloudflare/graph_template/connectors'),
	MD5('plugin_cloudflare/graph_template/health'), MD5('plugin_cloudflare/graph_template/collection'))")" = '3' ]] \
	|| die 'the three graph templates are not all present'
resource_matches || die 'resource/script_server/cloudflare_tunnels.xml is missing or differs'
http "${admin_jar}" "${work}/list-device.html" "${page}" >/dev/null
csrf="$(csrf_from "${work}/list-device.html")"
[[ -n "${csrf}" ]] || die 'no __csrf_magic token on the list page'
http "${admin_jar}" "${work}/confirm-device.html" \
	--data-urlencode 'action=actions' \
	--data-urlencode 'drp_action=5' \
	--data-urlencode "chk_${account_id}=on" \
	--data-urlencode "__csrf_magic=${csrf}" \
	"${page}" >/dev/null
grep -qF 'create a Cacti device for the following Cloudflare Account' "${work}/confirm-device.html" \
	|| die 'no Create Cacti Device confirmation page' "$(head -c 600 "${work}/confirm-device.html")"
csrf="$(csrf_from "${work}/confirm-device.html")"
[[ -n "${csrf}" ]] || die 'no __csrf_magic token on the device confirmation page'
http "${admin_jar}" "${work}/device.html" \
	--data-urlencode 'action=actions' \
	--data-urlencode 'drp_action=5' \
	--data-urlencode "selected_items=a:1:{i:0;i:${account_id};}" \
	--data-urlencode "__csrf_magic=${csrf}" \
	"${page}" >/dev/null
require_clean "$(cat "${work}/device.html")"
host_id="$(sql "SELECT IFNULL(MAX(host_id), 0) FROM plugin_cloudflare_resources WHERE account_id = ${account_id} AND kind = 'device'")"
[[ "${host_id}" != '0' ]] || die 'no device was recorded for the account' "$(sql "SELECT * FROM plugin_cloudflare_resources")"
dq_id="$(sql "SELECT id FROM snmp_query WHERE hash = '${cf_data_query}'")"
[[ -n "${dq_id}" ]] || die 'the data query is missing'
[[ "$(sql "SELECT CONCAT_WS('|', h.deleted, ht.hash) FROM host h JOIN host_template ht ON ht.id = h.host_template_id WHERE h.id = ${host_id}")" = '|7b64ac0c934b4a22601a2a8e03f0d4c3' ]] \
	|| die "host ${host_id} is not a live device on the plugin's host template"
[[ "$(sql "SELECT reindex_method FROM host_snmp_query WHERE host_id = ${host_id} AND snmp_query_id = ${dq_id}")" = '0' ]] \
	|| die 'the device data query is not on reindex None'
http "${admin_jar}" "${work}/edit-graphs.html" "${page}?action=edit&id=${account_id}" >/dev/null
csrf="$(csrf_from "${work}/edit-graphs.html")"
[[ -n "${csrf}" ]] || die 'no __csrf_magic token on the account edit form'
grep -qF 'create_graphs' "${work}/edit-graphs.html" || die 'the edit form has no create_graphs field'
http "${admin_jar}" "${work}/save-graphs.html" \
	--data-urlencode 'action=save' \
	--data-urlencode 'save_component_account=1' \
	--data-urlencode "id=${account_id}" \
	--data-urlencode 'name=smoke' \
	--data-urlencode "cf_account_id=${cf_account}" \
	--data-urlencode 'token_source=file' \
	--data-urlencode 'token_file=/var/lib/cf-smoke/token' \
	--data-urlencode 'enabled=on' \
	--data-urlencode 'collect_tunnels=on' \
	--data-urlencode 'create_graphs=on' \
	--data-urlencode 'discovery_interval=300' \
	--data-urlencode "__csrf_magic=${csrf}" \
	"${page}" >/dev/null
[[ "$(sql "SELECT create_graphs FROM plugin_cloudflare_accounts WHERE id = ${account_id}")" = '1' ]] \
	|| die 'create_graphs was not saved' "$(head -c 600 "${work}/save-graphs.html")"
[[ "$(sql "SELECT COUNT(*) FROM graph_local WHERE host_id = ${host_id}")" = '0' ]] || die 'graphs exist before the sync'
pass

step='5g the next discovery reindexes the device and creates three graphs per active tunnel'
# Cacti logs a reindex only with data_source_trace on (lib/data_query.php:28),
# and the sync's summary is a MEDIUM line.
save_log_settings
sql "REPLACE INTO settings (name, value) VALUES ('log_verbosity', '3'), ('data_source_trace', 'on')"
offset="$(log_size)"
poll_due
restore_verbosity
log_since "${offset}" >"${work}/log-5g"
[[ "$(job_result)" = 'ok|null' ]] || die "discovery job ended $(job_result)"
found -F "Running Re-Index for Device[${host_id}], DQ[${dq_id}]" "${work}/log-5g" \
	|| die 'cacti.log shows no reindex of the device' "$(grep -F CLOUDFLARE "${work}/log-5g")"
found -F "CLOUDFLARE: account ${account_id} graphs created=9 adopted=0" "${work}/log-5g" \
	|| die 'cacti.log shows no graph sync' "$(grep -F CLOUDFLARE "${work}/log-5g")"
indexed="$(sql "SELECT GROUP_CONCAT(DISTINCT snmp_index ORDER BY snmp_index SEPARATOR ' ') FROM host_snmp_cache WHERE host_id = ${host_id} AND snmp_query_id = ${dq_id}")"
active="$(sql "SELECT GROUP_CONCAT(cf_tunnel_id ORDER BY cf_tunnel_id SEPARATOR ' ') FROM plugin_cloudflare_tunnels WHERE account_id = ${account_id} AND lifecycle = 'active'")"
[[ "${indexed}" = "${active}" ]] || die "indexed '${indexed}', active tunnels '${active}'"
per_tunnel="$(sql "SELECT CONCAT(snmp_index, ':', COUNT(*), ':', COUNT(DISTINCT graph_template_id)) FROM graph_local WHERE host_id = ${host_id} GROUP BY snmp_index ORDER BY snmp_index")"
[[ "${per_tunnel}" = "$(tr ' ' '\n' <<<"${active}" | sed 's/$/:3:3/')" ]] || die 'expected three graphs for each active tunnel' "${per_tunnel}"
[[ "$(sql "SELECT COUNT(*) FROM plugin_cloudflare_resources WHERE account_id = ${account_id} AND kind = 'graph'")" = '9' ]] \
	|| die 'expected 9 graph resource rows' "$(sql "SELECT * FROM plugin_cloudflare_resources")"
# Each row names a graph on the device, for its own tunnel and template, and
# a data source that graph draws from.
matched="$(sql "SELECT COUNT(DISTINCT r.id) FROM plugin_cloudflare_resources r
	JOIN plugin_cloudflare_tunnels t ON t.id = r.tunnel_id
	JOIN graph_local gl ON gl.id = r.local_graph_id AND gl.host_id = r.host_id AND gl.snmp_index = t.cf_tunnel_id
	JOIN graph_templates gt ON gt.id = gl.graph_template_id AND gt.hash = r.template_hash
	JOIN graph_templates_item gti ON gti.local_graph_id = gl.id
	JOIN data_template_rrd dtr ON dtr.id = gti.task_item_id AND dtr.local_data_id = r.local_data_id
	WHERE r.account_id = ${account_id} AND r.kind = 'graph' AND r.host_id = ${host_id}")"
[[ "${matched}" = '9' ]] || die "only ${matched} of 9 resource rows match graph_local"
[[ "$(sql "SELECT COUNT(DISTINCT local_data_id) FROM plugin_cloudflare_resources WHERE account_id = ${account_id} AND kind = 'graph'")" = '3' ]] \
	|| die 'expected one data source per tunnel'
[[ "$(sql "SELECT COUNT(*) FROM poller_item WHERE host_id = ${host_id}")" = '18' ]] \
	|| die 'the poller cache does not hold the six fields of each tunnel data source'
pass

step="5h two polls record blog's connectors and health in its RRD"
poll_due
poll_due
rrd="$(blog_rrd)"
[[ "${rrd}" = /var/www/html/cacti/rra/*.rrd ]] || die "unexpected RRD path '${rrd}'"
"${dc[@]}" exec -T web test -s "${rrd}" || die "the poller did not create ${rrd}"
blog_status="$(sql "SELECT cf_status FROM plugin_cloudflare_tunnels WHERE account_id = ${account_id} AND name = 'blog'")"
# shellcheck disable=SC2016 # PHP source; $argv is PHP's, not the shell's.
blog_health="$("${dc[@]}" exec -T web php -r 'require "plugins/cloudflare/includes/autoload.php";
	echo Cacti\PluginCloudflare\Metrics\Catalog::HEALTH[$argv[1]];' "${blog_status}")"
[[ "${blog_status}|${blog_health}" = 'healthy|3' ]] || die "blog is ${blog_status}, health ${blog_health}"
[[ "$(last_value "${rrd}" connectors)" = '1' ]] || die "connectors is $(last_value "${rrd}" connectors), expected 1"
[[ "$(last_value "${rrd}" connections)" = '1' ]] || die "connections is $(last_value "${rrd}" connections), expected 1"
[[ "$(last_value "${rrd}" health)" = "${blog_health}" ]] || die "health is $(last_value "${rrd}" health), expected ${blog_health}"
pass

step='5i during a Cloudflare 503 the state data sources record U, never 0'
fake touch /run/cf-fake/fail-503
poll_due
[[ "$(job_result)" = 'failed|transient' ]] || die "discovery job ended $(job_result), expected failed|transient"
# This poll reads the failed job.
poll_due
[[ "$(job_result)" = 'failed|transient' ]] || die "discovery job ended $(job_result), expected failed|transient"
for ds in connectors connections health; do
	[[ "$(last_value "${rrd}" "${ds}")" = 'U' ]] || die "${ds} is $(last_value "${rrd}" "${ds}") during the outage, expected U"
done
out="$("${dc[@]}" exec -T web rrdtool fetch "${rrd}" AVERAGE -s -900 2>&1)" || die 'rrdtool fetch failed' "${out}"
[[ -n "$(rrd_column connectors "${out}")" ]] || die 'rrdtool fetch returned no rows' "${out}"
if grep -qx '0.0000000000e+00' <<<"$(rrd_column connectors "${out}")"; then
	die 'connectors recorded 0 in the outage window' "${out}"
fi
pass

step='5j once Cloudflare answers again, the values return'
fake rm /run/cf-fake/fail-503
poll_due
[[ "$(job_result)" = 'ok|null' ]] || die "discovery job ended $(job_result) after the fake recovered"
poll_due
[[ "$(last_value "${rrd}" connectors)" = '1' ]] || die "connectors is $(last_value "${rrd}" connectors) after recovery, expected 1"
[[ "$(last_value "${rrd}" health)" = "${blog_health}" ]] || die "health is $(last_value "${rrd}" health) after recovery"
[[ "$(sql "SELECT COUNT(*) FROM graph_local WHERE host_id = ${host_id}")" = '9' ]] || die 'later syncs changed the graph count'
pass

step='5k with the plugin disabled, poller.php launches no collector'
out="$(plugin_manage --disable 2>&1)" || die "${out}"
require_clean "${out}"
[[ "$(plugin_status)" = '4' ]] || die "status after disable is $(plugin_status)"
# Due now, so a collector that did start would lease the job.
sql "UPDATE plugin_cloudflare_jobs SET next_run_at = 0 WHERE account_id = ${account_id}"
before_job="$(sql "SELECT * FROM plugin_cloudflare_jobs WHERE account_id = ${account_id}")"
before_requests="$(fake_requests | wc -l)"
poll_debug "${work}/log-5f"
if found -E "${spawn_pattern}" "${work}/log-5f"; then
	die 'poller.php spawned poller_cloudflare.php with the plugin disabled' "$(grep -E "${spawn_pattern}" "${work}/log-5f")"
fi
# 5c and 5e show a started collector leases the job within a second or two.
for ((i = 0; i < 10; i++)); do
	[[ "$(collectors_running)" = '0' ]] || die 'a poller_cloudflare.php process is running'
	sleep 1
done
[[ "$(sql "SELECT * FROM plugin_cloudflare_jobs WHERE account_id = ${account_id}")" = "${before_job}" ]] \
	|| die 'the job row changed while the plugin was disabled'
[[ "$(fake_requests | wc -l)" = "${before_requests}" ]] || die 'the fake was called while the plugin was disabled'
out="$(plugin_enable 2>&1)" || die "${out}"
require_clean "${out}"
pass

step='6 uninstall drops plugin tables and settings and leaves Cacti data alone'
# The plugin's device and graphs are Cacti data now, so they must survive.
counts_before="$(core_counts)"
sql "INSERT INTO settings (name, value) VALUES ('cloudflare_ui_timeout', '10') ON DUPLICATE KEY UPDATE value = VALUES(value)"
out="$(plugin_manage --disable --uninstall 2>&1)" || die "${out}"
require_clean "${out}"
[[ -z "$(plugin_tables)" ]] || die 'tables remain' "$(plugin_tables)"
[[ "$(sql "SELECT COUNT(*) FROM settings WHERE name LIKE 'cloudflare!_%' ESCAPE '!'")" = '0' ]] || die 'cloudflare_ settings remain'
[[ "$(plugin_status)" = 'none' ]] || die "plugin_config row remains with status $(plugin_status)"
[[ "$(sql "SELECT COUNT(*) FROM plugin_realms WHERE plugin = 'cloudflare'")" = '0' ]] || die 'plugin realms remain'
[[ "$(sql "SELECT COUNT(*) FROM user_auth_realm WHERE realm_id = ${view_realm}")" = '0' ]] || die 'viewer realm grant remains'
counts_after="$(core_counts)"
[[ "${counts_after}" = "${counts_before}" ]] || die "host/graph_local/data_template counts ${counts_before} became ${counts_after}"
[[ "$(sql "SELECT COUNT(*) FROM graph_local WHERE host_id = ${host_id}")" = '9' ]] || die "uninstall removed the device's graphs"
[[ "$(template_objects)" = '6' ]] || die "uninstall left $(template_objects) of 6 template objects"
resource_matches || die 'uninstall removed or changed the data query XML'
pass

step='7 the token appears in no log, page or database dump'
capture cacti-logs "${dc[@]}" exec -T web sh -c 'find log -type f -exec cat {} +'
found -F 'CLOUDFLARE' "${captures}/cacti-logs" || die 'cacti.log has no CLOUDFLARE lines, so the search below proves nothing'
no_token "${captures}/cacti-logs" 'the Cacti log directory'
capture container-logs "${dc[@]}" logs --no-color web gateway cloudflare-fake
no_token "${captures}/container-logs" 'the container logs'
capture fake-requests fake_requests
no_token "${captures}/fake-requests" 'the fake request log'
if found -rF --exclude-dir=captures -- "${token}" "${work}"; then
	die 'token leaked into a saved HTTP response'
fi
capture db-dump "${dc[@]}" exec -T db mariadb-dump --user=root --password=smoke-root --all-databases
found -F 'CREATE TABLE' "${captures}/db-dump" || die 'database dump has no CREATE TABLE'
no_token "${captures}/db-dump" 'the database'
pass

step='8 no PHP errors in cacti.log or the Apache error log'
capture cacti-logs-final "${dc[@]}" exec -T web cat log/cacti.log
if found -E 'ERROR PHP|PHP (Fatal|Parse|Warning|Notice|Deprecated)' "${captures}/cacti-logs-final"; then
	die 'cacti.log has PHP errors' "$(grep -E 'ERROR PHP|PHP (Fatal|Parse|Warning|Notice|Deprecated)' "${captures}/cacti-logs-final")"
fi
capture web-logs "${dc[@]}" logs --no-color web
if found -E 'PHP (Fatal|Parse|Warning|Notice|Deprecated)' "${captures}/web-logs"; then
	die 'the Apache error log has PHP errors' "$(grep -E 'PHP (Fatal|Parse|Warning|Notice|Deprecated)' "${captures}/web-logs")"
fi
pass
