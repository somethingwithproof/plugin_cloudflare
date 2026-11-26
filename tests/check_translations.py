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
ALL_LOCALES = ("zh-CN", "hi-IN", "es-ES", "es-419", "ar-SA", "af-ZA", "am-ET", "ar-EG", "ar-MA", "bn-BD", "bs-BA", "de-DE", "fa-IR", "fil-PH", "fr-FR", "fr-MA", "fr-SN", "ha-NG", "he-IL", "hr-HR", "id-ID", "ig-NG", "it-IT", "ja-JP", "ko-KR", "mg-MG", "mr-IN", "ms-MY", "nl-NL", "pl-PL", "pt-BR", "ru-RU", "rw-RW", "so-SO", "sr-RS", "sv-SE", "sw-KE", "ta-IN", "te-IN", "th-TH", "tr-TR", "uk-UA", "ur-PK", "vi-VN", "xh-ZA", "yo-NG", "zh-TW", "zu-ZA")
NAVIGATION = re.compile(r"<!-- translations:start -->\n.*?<!-- translations:end -->\n\n?", re.S)
HEADER = re.compile(r"<!-- Source: ([^;]+); SHA256: ([a-f0-9]{64}) -->")
TOKENS = re.compile(r"%(?:\d+\$)?[-+ 0#]*\d*(?:\.\d+)?[sdfuxXcoeEgGi]|%%|<[^>]*>")


def messages(path):
    result = []
    for block in path.read_text(encoding="utf-8").split("\n\n"):
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


def fenced_sections(text: str) -> tuple[list[tuple[str, str]], str]:
    blocks = []
    prose = []
    fence = None
    content = []
    language = ""
    for line in text.splitlines():
        match = re.match(r"^[ \t]*(`{3,}|~{3,})(.*)$", line)
        if fence is None and match:
            fence, language = match[1], match[2].strip()
            content = []
            prose.append("")
        elif (fence is not None and match
              and match[1][0] == fence[0]
              and len(match[1]) >= len(fence)
              and not match[2].strip()):
            blocks.append((language, "\n".join(content)))
            fence = None
        elif fence is not None:
            content.append(line)
        else:
            prose.append(line)
    if fence is not None:
        raise ValueError("unclosed code fence")
    return blocks, "\n".join(prose)


def code_blocks(text):
    blocks, _ = fenced_sections(text)
    return [content for language, content in blocks if language != "mermaid"]


def links(text):
    # Fenced examples can contain literal Markdown and URLs that are not links.
    _, text = fenced_sections(text)
    result = re.findall(r"\]\(<?([^\s)>]+)>?(?:\s+[\"'][^\n]*?[\"'])?\)", text)
    result.extend(re.findall(r"(?:href|src|srcset)=[\"']([^\"']+)[\"']", text))
    return result


def heading_levels(text):
    _, text = fenced_sections(text)
    return [len(level) for level in re.findall(r"^(#{1,6})\s+", text, re.M)]


def validate_doc(root, locale, source, failures):
    original = root / source
    translated = root / "docs/i18n" / locale / source
    label = str(translated.relative_to(root))
    if not original.is_file() or not translated.is_file():
        failures.append("missing source or translation: " + label)
        return
    english = source_text(original.read_text(encoding="utf-8"))
    text = translated.read_text(encoding="utf-8")
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


def validate(root):
    failures = []
    manifest_path = root / "docs/i18n/manifest.json"
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    sources = manifest["sources"]
    if manifest["locales"] != list(LOCALES):
        failures.append("manifest locales must include both Spanish variants")
    if manifest.get("readme_locales") != list(ALL_LOCALES):
        failures.append("manifest readme_locales must match ALL_LOCALES")
    if "README.md" not in sources or len(sources) != len(set(sources)):
        failures.append("manifest needs README.md and unique source paths")
    template = messages(root / "locales/po/cacti.pot")
    doc_count = 0
    for locale in ALL_LOCALES:
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
                    values = [catalog.ngettext(original, entry["msgid_plural"], n) for n in (0, 1, 2, 3, 4, 11, 20, 100)]
                for value in values:
                    if sorted(TOKENS.findall(original)) != sorted(TOKENS.findall(value)) or edges(original) != edges(value):
                        failures.append("placeholder, markup or whitespace mismatch: " + locale + ": " + original)
        locale_sources = sources if locale in LOCALES else ["README.md"]
        for source in locale_sources:
            validate_doc(root, locale, source, failures)
            doc_count += 1
    if failures:
        print("\n".join(failures), file=sys.stderr)
        return 1
    print(f"Verified {len(ALL_LOCALES)} locale catalogs and {doc_count} translated guides")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(validate(Path(__file__).resolve().parents[1]))
    except (OSError, ValueError, KeyError) as error:
        print("Translation validation failed: " + str(error), file=sys.stderr)
        sys.exit(1)
