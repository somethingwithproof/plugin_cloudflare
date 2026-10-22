#!/usr/bin/env bash
# Captures the README screenshots from the `make smoke-up` stack (project
# cf-smoke on CF_SMOKE_PORT, default 8090) into docs/images/. It installs
# and enables the plugin, creates a vault key, adds "Example account" with
# the fixture account ID and a random token, runs Verify, and polls once.
# The stack has no route to the internet; cloudflare-fake answers as
# api.cloudflare.com from tests/fixtures/cloudflare, so the pages show the
# three fixture tunnels.
#
# Needs Node for `npx playwright`; run through `make screenshots`.

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/../.." && pwd)"
port="${CF_SMOKE_PORT:-8090}"
admin_pass="${CF_SMOKE_ADMIN_PASS:-smoke-admin}"
playwright="${CF_PLAYWRIGHT:-playwright@1.63.0}"
base="http://127.0.0.1:${port}/cacti"
page="${base}/plugins/cloudflare/cloudflare_accounts.php"
viewer="${base}/plugins/cloudflare/cloudflare.php"
images="${root}/docs/images"
account_name='Example account'
account_id='699d98642c564d2e855e9661899b7252'

dc=(docker compose -p cf-smoke -f "${here}/compose.cacti.yml")
work="$(mktemp -d)"
jar="${work}/admin.jar"

# The storage state holds a live session cookie, so it never outlives the run.
trap 'rm -rf "${work}"' EXIT

die() {
    printf 'FAIL: %s\n' "$@" >&2
    exit 1
}

sql() {
    local query="$1"

    "${dc[@]}" exec -T db mariadb --user=cactiuser --password=cactiuser \
        --batch --skip-column-names cacti --execute="${query}"
}

http() {
    local out="$1"
    shift
    curl -sS -L --max-time 30 -b "${jar}" -c "${jar}" -o "${out}" -w '%{http_code}' "$@"
}

csrf_from() {
    local file="$1"

    grep -oE "name=.__csrf_magic. value=\"[^\"]+\"" "${file}" | sed -n -E '1s/.*value="([^"]+)"/\1/p' || true
}

require_csrf() {
    local file="$1" value

    value="$(csrf_from "${file}")"
    [[ -n "${value}" ]] || die "no __csrf_magic token in $(basename "${file}")"
    printf '%s' "${value}"
}

[[ -n "$("${dc[@]}" ps -q web 2>/dev/null)" ]] || die 'the cf-smoke stack is not running; use make screenshots'

out="$("${dc[@]}" exec -T -u www-data web php cli/plugin_manage.php --plugin=cloudflare --install --enable 2>&1)" || die "${out}"
[[ "$(sql "SELECT status FROM plugin_config WHERE directory = 'cloudflare'")" = '1' ]] || die 'plugin is not enabled' "${out}"

# The key is random and dies with the stack; it only makes the vault available.
"${dc[@]}" exec -T web sh -c \
    'mkdir -p /etc/cacti && umask 077 && head -c 32 /dev/urandom | od -An -tx1 | tr -d " \n" > /etc/cacti/cloudflare.key && chown www-data:www-data /etc/cacti/cloudflare.key && chmod 0400 /etc/cacti/cloudflare.key'

sql "DELETE FROM plugin_cloudflare_accounts WHERE cf_account_id = '${account_id}'"

code="$(http "${work}/login-form.html" "${base}/index.php")"
[[ "${code}" = '200' ]] || die "login form returned ${code}"
csrf="$(require_csrf "${work}/login-form.html")"
http "${work}/login.html" \
    --data-urlencode 'action=login' \
    --data-urlencode 'login_username=admin' \
    --data-urlencode "login_password=${admin_pass}" \
    --data-urlencode 'realm=local' \
    --data-urlencode "__csrf_magic=${csrf}" \
    "${base}/index.php" >/dev/null
if grep -q 'login_username' "${work}/login.html"; then
    die 'admin is still on the login form'
fi

token="$(head -c 600 /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9' | cut -c 1-40)"
[[ "${#token}" -eq 40 ]] || die 'could not generate a placeholder token'
# The fake accepts only this bearer credential.
printf '%s' "${token}" | "${dc[@]}" exec -T -u www-data cloudflare-fake sh -c 'umask 077 && cat > /run/cf-fake/token'

http "${work}/edit.html" "${page}?action=edit" >/dev/null
csrf="$(require_csrf "${work}/edit.html")"
http "${work}/save.html" \
    --data-urlencode 'action=save' \
    --data-urlencode 'save_component_account=1' \
    --data-urlencode 'id=0' \
    --data-urlencode "name=${account_name}" \
    --data-urlencode "cf_account_id=${account_id}" \
    --data-urlencode 'token_source=vault' \
    --data-urlencode "token=${token}" \
    --data-urlencode "token_confirm=${token}" \
    --data-urlencode 'enabled=on' \
    --data-urlencode 'collect_tunnels=on' \
    --data-urlencode 'discovery_interval=300' \
    --data-urlencode "__csrf_magic=${csrf}" \
    "${page}" >/dev/null
id="$(sql "SELECT id FROM plugin_cloudflare_accounts WHERE cf_account_id = '${account_id}'")"
[[ -n "${id}" ]] || die 'the example account was not saved' "$(grep -o "<div id='message'.*" "${work}/save.html" | head -c 400)"

http "${work}/list.html" "${page}" >/dev/null
csrf="$(require_csrf "${work}/list.html")"
http "${work}/confirm.html" \
    --data-urlencode 'action=actions' \
    --data-urlencode 'drp_action=2' \
    --data-urlencode "chk_${id}=on" \
    --data-urlencode "__csrf_magic=${csrf}" \
    "${page}" >/dev/null
csrf="$(require_csrf "${work}/confirm.html")"
http "${work}/verify.html" \
    --data-urlencode 'action=actions' \
    --data-urlencode 'drp_action=2' \
    --data-urlencode "selected_items=a:1:{i:0;i:${id};}" \
    --data-urlencode "__csrf_magic=${csrf}" \
    "${page}" >/dev/null
status="$(sql "SELECT status FROM plugin_cloudflare_accounts WHERE id = ${id}")"
[[ "${status}" = 'ok' ]] || die "expected status ok after Verify, got ${status}"

# The seeded automation network would have poller.php scan a LAN that does
# not exist here. The View realm is not granted to admin on install.
sql "UPDATE automation_networks SET enabled = ''"
sql "INSERT IGNORE INTO user_auth_realm (realm_id, user_id) SELECT id + 100, 1 FROM plugin_realms WHERE plugin = 'cloudflare' AND file = 'cloudflare.php'"
out="$("${dc[@]}" exec -T -u www-data web php poller.php --force 2>&1)" || die "${out}"
# The collector runs detached from poller.php.
for ((i = 0; i < 60; i++)); do
    [[ "$(sql "SELECT COUNT(*) FROM plugin_cloudflare_jobs WHERE account_id = ${id} AND last_status = 'ok'")" = '1' ]] && break
    sleep 1
done
[[ "$(sql "SELECT COUNT(*) FROM plugin_cloudflare_tunnels WHERE account_id = ${id}")" = '3' ]] \
    || die 'discovery did not store the three fixture tunnels'

# curl's jar is the Netscape format; Playwright wants its storage-state JSON.
awk -F '\t' '
    BEGIN { printf "{\"cookies\":[" }
    /^#HttpOnly_/ { sub(/^#HttpOnly_/, ""); http = "true" }
    /^#/ || NF < 7 { next }
    {
        if (n++) printf ","
        printf "{\"name\":\"%s\",\"value\":\"%s\",\"domain\":\"%s\",\"path\":\"%s\",\"expires\":%s,\"httpOnly\":%s,\"secure\":%s,\"sameSite\":\"Lax\"}", \
            $6, $7, $1, $3, ($5 == 0 ? -1 : $5), (http == "true" ? "true" : "false"), ($4 == "TRUE" ? "true" : "false")
        http = ""
    }
    END { printf "],\"origins\":[]}\n" }
' "${jar}" >"${work}/state.json"

shoot() {
    local url="$1" file="$2" marker="$3"

    code="$(http "${work}/check.html" "${url}")"
    [[ "${code}" = '200' ]] || die "${url} returned ${code}"
    grep -qF -- "${marker}" "${work}/check.html" || die "${url} lacks \"${marker}\""

    npx -y "${playwright}" screenshot --browser=chromium --viewport-size=1280,800 \
        --load-storage="${work}/state.json" --wait-for-timeout=1500 \
        "${url}" "${images}/${file}" >/dev/null
    [[ -s "${images}/${file}" ]] || die "no image written for ${file}"
    printf 'wrote docs/images/%s\n' "${file}"
}

mkdir -p "${images}"
shoot "${page}" 'accounts-list.png' "${account_name}"
shoot "${page}?action=edit&id=${id}" 'account-edit.png' 'Connection Status'
shoot "${base}/settings.php?tab=cloudflare" 'settings-cloudflare.png' 'Token Vault Key File'
shoot "${viewer}" 'overview.png' "${account_name}"
shoot "${viewer}?action=tunnels" 'tunnels-list.png' 'old-office'

for png in "${images}"/*.png; do
    if command -v oxipng >/dev/null 2>&1; then
        oxipng -q -o 4 --strip safe "${png}"
    elif command -v pngquant >/dev/null 2>&1; then
        pngquant --force --skip-if-larger --output "${png}" -- "${png}" || true
    fi
done
