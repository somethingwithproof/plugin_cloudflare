#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
# SPDX-License-Identifier: GPL-2.0-or-later
#
# This file is part of plugin_cloudflare, a plugin for Cacti.
# https://github.com/somethingwithproof/plugin_cloudflare
#
# This program is free software; you can redistribute it and/or modify it
# under the terms of the GNU General Public License as published by the Free
# Software Foundation; either version 2 of the License, or (at your option)
# any later version.
#
# This program is distributed in the hope that it will be useful, but WITHOUT
# ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
# FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
# more details.

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
    cloudflare/ss_cloudflare.php cloudflare/ss_cloudflare_zones.php cloudflare/cloudflare_operator.php cloudflare/templates/ cloudflare/src/ cloudflare/includes/ cloudflare/js/ cloudflare/schema/ cloudflare/schema/cloudflare.schema.yaml cloudflare/docs/images/; do
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

# The template, the sources and the compiled catalogs Cacti loads.
for locale in af-ZA am-ET ar-EG ar-MA ar-SA bn-BD bs-BA de-DE es-419 es-ES fa-IR fil-PH fr-FR fr-MA fr-SN ha-NG he-IL hi-IN hr-HR id-ID ig-NG it-IT ja-JP ko-KR mg-MG mr-IN ms-MY nl-NL pl-PL pt-BR ru-RU rw-RW so-SO sr-RS sv-SE sw-KE ta-IN te-IN th-TH tr-TR uk-UA ur-PK vi-VN xh-ZA yo-NG zh-CN zh-TW zu-ZA; do
    for path in "cloudflare/locales/po/${locale}.po" "cloudflare/locales/LC_MESSAGES/${locale}.mo"; do
        if ! grep -qxF -- "${path}" <<<"${listing}"; then
            printf 'FAIL: %s is missing from %s\n' "${path}" "${archive}" >&2
            status=1
        fi
    done
done

if ! grep -qxF -- cloudflare/locales/po/cacti.pot <<<"${listing}"; then
    printf 'FAIL: cloudflare/locales/po/cacti.pot is missing from %s\n' "${archive}" >&2
    status=1
fi

[[ "${status}" -eq 0 ]] || exit "${status}"

printf '%s: %s entries\n' "${archive}" "$(wc -l <<<"${listing}" | tr -d ' ')"
