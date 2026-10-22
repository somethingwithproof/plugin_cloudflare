#!/usr/bin/env bash
# Builds the release archive from HEAD with git archive, so .gitattributes
# export-ignore decides what ships, then checks the archive's contents.
# Writes build/cloudflare-<version>.tar.gz; run through `make package`.

set -euo pipefail

root="$(git rev-parse --show-toplevel)"
cd "${root}"

version="$(sed -n -E 's/^version[[:space:]]*=[[:space:]]*([0-9A-Za-z.-]+)[[:space:]]*$/\1/p' INFO)"
[[ -n "${version}" ]] || { printf 'FAIL: no version in INFO\n' >&2; exit 1; }

mkdir -p build
archive="build/cloudflare-${version}.tar.gz"
git archive --format=tar.gz --prefix=cloudflare/ -o "${archive}" HEAD

listing="$(tar -tzf "${archive}")"
status=0

for path in cloudflare/setup.php cloudflare/INFO cloudflare/LICENSE cloudflare/cloudflare.php cloudflare/cloudflare_accounts.php cloudflare/poller_cloudflare.php \
    cloudflare/ss_cloudflare.php cloudflare/src/ cloudflare/includes/ cloudflare/js/ cloudflare/docs/images/; do
    if ! grep -qxF -- "${path}" <<<"${listing}"; then
        printf 'FAIL: %s is missing from %s\n' "${path}" "${archive}" >&2
        status=1
    fi
done

for prefix in cloudflare/tests/ cloudflare/features/ cloudflare/vendor/ cloudflare/docs/superpowers/ \
    cloudflare/.superpowers/ cloudflare/.github/ cloudflare/composer.json cloudflare/composer.lock \
    cloudflare/Makefile cloudflare/phpunit.xml.dist cloudflare/phpstan.neon.dist \
    cloudflare/.php-cs-fixer.dist.php cloudflare/.php-cs-fixer.cacti.php \
    cloudflare/behat.yml.dist cloudflare/sonar-project.properties; do
    if awk -v p="${prefix}" 'index($0, p) == 1 { found = 1 } END { exit !found }' <<<"${listing}"; then
        printf 'FAIL: %s is in %s\n' "${prefix}" "${archive}" >&2
        status=1
    fi
done

[[ "${status}" -eq 0 ]] || exit "${status}"

printf '%s: %s entries\n' "${archive}" "$(wc -l <<<"${listing}" | tr -d ' ')"
