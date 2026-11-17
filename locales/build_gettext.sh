#!/bin/sh
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
#
# Regenerate locales/po/cacti.pot from every PHP file at depth 2 or less outside tests and vendor, the
# scan the Cacti plugin gettext builds use, merge it into each catalog
# locales/po/<locale>.po, and compile each catalog to
# locales/LC_MESSAGES/<locale>.mo, where Cacti loads plugin translations.
#
# With --check, change nothing and fail when the template is out of date, a
# catalog misses or has a fuzzy entry, a format string does not match, or a
# compiled catalog differs from its source.

set -eu

BASE_PATH=$(cd "$(dirname "$0")/.." && pwd)
POT=locales/po/cacti.pot

cd "$BASE_PATH"
mkdir -p locales/po

command -v xgettext >/dev/null 2>&1 || { echo "ERROR: Unable to locate xgettext (install GNU gettext)" >&2; exit 1; }

VERSION=$(sed -n 's/^version *= *//p' INFO)

generate() {
	# shellcheck disable=SC2046
	xgettext --no-wrap --add-location=file --from-code=UTF-8 --copyright-holder="Thomas Vincent" \
		--package-name="cloudflare" --package-version="$VERSION" --msgid-bugs-address="https://github.com/somethingwithproof/plugin_cloudflare/issues" \
		-F -L PHP -k__gettext -k__ -k__n:1,2 -k__x:1c,2 -k__xn:1c,2,3 -k__esc -k__esc_n:1,2 -k__esc_x:1c,2 -k__esc_xn:1c,2,3 -k__date \
		-o "$1" $(find . -maxdepth 2 -name '*.php' -not -path './tests/*' -not -path './vendor/*' -not -path './build/*' | sort)

	sed -e 's/FULL NAME <EMAIL@ADDRESS>/Thomas Vincent <thomasvincent@gmail.com>/' \
		-e 's/LANGUAGE <LL@li.org>/Thomas Vincent <thomasvincent@gmail.com>/' \
		-e 's/CHARSET/UTF-8/' \
		-e '/^"POT-Creation-Date:/d' "$1" > "$1.tmp" && mv "$1.tmp" "$1"
}

if [ "${1:-}" = "--check" ]; then
	tmp=$(mktemp)
	generate "$tmp"

	if ! diff -u "$POT" "$tmp"; then
		rm -f "$tmp"
		echo "ERROR: $POT is out of date; run locales/build_gettext.sh and commit the result" >&2
		exit 1
	fi

	rm -f "$tmp"
	echo "$POT is up to date"

	command -v msgunfmt >/dev/null 2>&1 || { echo "ERROR: Unable to locate msgunfmt (install GNU gettext)" >&2; exit 1; }
	rc=0

	for po in locales/po/*.po; do
		[ -f "$po" ] || continue
		locale=$(basename "$po" .po)
		mo=locales/LC_MESSAGES/$locale.mo

		# Every template entry translated, none fuzzy, formats intact.
		msgfmt --check -o /dev/null "$po" || { echo "ERROR: $po does not compile" >&2; rc=1; continue; }
		msgcmp "$po" "$POT" || { echo "ERROR: $po misses entries of $POT; run locales/build_gettext.sh" >&2; rc=1; }
		[ -z "$(msgattrib --only-fuzzy --no-obsolete "$po")" ] || { echo "ERROR: $po has fuzzy entries" >&2; rc=1; }
		[ -z "$(msgattrib --untranslated --no-obsolete "$po")" ] || { echo "ERROR: $po has untranslated entries" >&2; rc=1; }

		# The compiled catalog holds the same messages as its source; compared
		# decompiled, because gettext versions lay the binary out differently.
		if [ ! -f "$mo" ] || [ "$(msgunfmt "$mo")" != "$(msgfmt -o - "$po" | msgunfmt)" ]; then
			echo "ERROR: $mo is missing or out of date; run locales/build_gettext.sh" >&2
			rc=1
		fi
	done

	python3 tests/check_translations.py || rc=1

	[ $rc -eq 0 ] && echo "catalogs are complete and compiled"
	exit $rc
fi

generate "$POT"
echo "Updated $POT"

mkdir -p locales/LC_MESSAGES

for po in locales/po/*.po; do
	[ -f "$po" ] || continue
	msgmerge --quiet --update --no-wrap --backup=none "$po" "$POT"
	msgfmt -o "locales/LC_MESSAGES/$(basename "$po" .po).mo" "$po"
	echo "Compiled $po"
done
