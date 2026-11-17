#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
"""Check required catalogs and translated user guides without third-party packages."""

import ast
import gettext
import hashlib
import json
from pathlib import Path
import re
import sys
from urllib.parse import unquote, urlsplit


LOCALES = ("zh-CN", "hi-IN", "es-ES", "es-419", "ar-SA")
NAVIGATION = re.compile(r"<!-- translations:start -->\n.*?<!-- translations:end -->\n\n?", re.S)
HEADER = re.compile(r"<!-- Source: ([^;]+); SHA256: ([a-f0-9]{64}) -->")
TOKENS = re.compile(r"%(?:\d+\$)?[-+ 0#]*\d*(?:\.\d+)?[sdfuxXcoeEgGi]|%%|<[^>]*>")


def messages(path):
    result = []
    for block in path.read_text().split("\n\n"):
        fields = {}
        current = None
        for line in block.splitlines():
            match = re.match(r'(msgid_plural|msgid) (".*")$', line)
            if match:
                current = match[1]
                fields[current] = ast.literal_eval(match[2])
            elif line.startswith('"') and current:
                fields[current] += ast.literal_eval(line)
            else:
                current = None
        if fields.get("msgid"):
            result.append(fields)
    return result


def edges(text):
    return (text[:len(text) - len(text.lstrip())], text[len(text.rstrip()):])


def source_text(text):
    return NAVIGATION.sub("", text)


def code_blocks(text):
    blocks = []
    fence = None
    content = []
    language = ""
    for line in text.splitlines():
        match = re.match(r"^\s*(`{3,}|~{3,})(.*)$", line)
        if fence is None and match:
            fence, language = match[1][0], match[2].strip()
            content = []
        elif fence is not None and match and match[1][0] == fence:
            if language != "mermaid":
                blocks.append("\n".join(content))
            fence = None
        elif fence is not None:
            content.append(line)
    if fence is not None:
        raise ValueError("unclosed code fence")
    return blocks


def links(text):
    # Fenced examples can contain literal Markdown and URLs that are not links.
    text = re.sub(r"(?ms)^\s*(```|~~~).*?^\s*\1\s*$", "", text)
    result = re.findall(r"\]\(<?([^\s)>]+)>?(?:\s+[\"'][^\n]*?[\"'])?\)", text)
    result.extend(re.findall(r"(?:href|src|srcset)=[\"']([^\"']+)[\"']", text))
    return result


def heading_levels(text):
    text = re.sub(r"(?ms)^\s*(```|~~~).*?^\s*\1\s*$", "", text)
    return [len(level) for level in re.findall(r"^(#{1,6})\s+", text, re.M)]


def validate(root):
    failures = []
    manifest_path = root / "docs/i18n/manifest.json"
    manifest = json.loads(manifest_path.read_text())
    sources = manifest["sources"]
    if manifest["locales"] != list(LOCALES):
        failures.append("manifest locales must include both Spanish variants")
    if "README.md" not in sources or len(sources) != len(set(sources)):
        failures.append("manifest needs README.md and unique source paths")
    template = messages(root / "locales/po/cacti.pot")
    for locale in LOCALES:
        for path in (root / "locales/po" / (locale + ".po"), root / "locales/LC_MESSAGES" / (locale + ".mo")):
            if not path.is_file():
                failures.append("missing catalog: " + str(path.relative_to(root)))
        mo = root / "locales/LC_MESSAGES" / (locale + ".mo")
        if mo.is_file():
            with mo.open("rb") as stream:
                catalog = gettext.GNUTranslations(stream)
            if catalog.info().get("language") != locale:
                failures.append("wrong compiled language header: " + locale)
            for entry in template:
                original = entry["msgid"]
                values = [catalog.gettext(original)]
                if "msgid_plural" in entry:
                    values = [catalog.ngettext(original, entry["msgid_plural"], n) for n in (0, 1, 2, 3, 11, 100)]
                for value in values:
                    if sorted(TOKENS.findall(original)) != sorted(TOKENS.findall(value)) or edges(original) != edges(value):
                        failures.append("placeholder, markup or whitespace mismatch: " + locale + ": " + original)
        for source in sources:
            original = root / source
            translated = root / "docs/i18n" / locale / source
            label = str(translated.relative_to(root))
            if not original.is_file() or not translated.is_file():
                failures.append("missing source or translation: " + label)
                continue
            english = source_text(original.read_text())
            text = translated.read_text()
            header = HEADER.search(text)
            digest = hashlib.sha256(english.encode()).hexdigest()
            if header is None or header[1] != source or header[2] != digest:
                failures.append("source changed; review and refresh translation: " + label)
            body = re.sub(r'^<a id="[^"]+"></a>\n', "", text, flags=re.M)
            if source_text(HEADER.sub("", body)).strip() == english.strip():
                failures.append("translation is an English copy: " + label)
            if heading_levels(english) != heading_levels(text):
                failures.append("heading structure differs from source: " + label)
            try:
                if code_blocks(english) != code_blocks(text):
                    failures.append("code examples differ from source: " + label)
            except ValueError as error:
                failures.append(str(error) + ": " + label)
            for link in links(text):
                parts = urlsplit(link)
                if parts.scheme or parts.netloc or not parts.path or parts.path.startswith("/"):
                    continue
                target = (translated.parent / unquote(parts.path)).resolve()
                if not target.is_relative_to(root.resolve()) or not target.exists():
                    failures.append("broken local link: " + label + " -> " + link)
    if failures:
        print("\n".join(failures), file=sys.stderr)
        return 1
    print(f"Verified {len(LOCALES)} locale catalogs and {len(sources) * len(LOCALES)} translated guides")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(validate(Path(__file__).resolve().parents[1]))
    except (OSError, ValueError, KeyError) as error:
        print("Translation validation failed: " + str(error), file=sys.stderr)
        sys.exit(1)
