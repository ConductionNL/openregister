#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
r"""Gate-39 button-name — every button must have an accessible name
(WCAG 2.2 AA SC 4.1.2 Name, Role, Value; axe-core `button-name`).

An icon-only button with no name is announced as just "button". The finding
is real and worth keeping.

WHY THIS WAS REWRITTEN
----------------------
The accepted-attribute regex was:

    r'(^|\s)(:?aria-label|aria-labelledby|v-bind:aria-label|title)\s*='

`:?` binds to the FIRST alternative only, so `:aria-label` was accepted while
`:title`, `v-bind:title` and `:aria-labelledby` were not. Vue templates bind
almost every user-visible string, because it has to go through `t()`:

    <button type="button"
            class="settings-page-editor__remove"
            :title="t('openbuild', 'Remove tab')"
            @click="removeTab(index)">

That button HAS a name. All 22 of openbuild's findings were this exact shape
— a correctly translated bound title read as a missing one. The only way to
close them was to add a second, redundant name (#222 family; see
ConductionNL/.github#225 for the sibling scope defect).

An accessible name that is BOUND is still an accessible name. What matters is
whether the attribute reaches the DOM, and `:title` reaches it exactly as
`title` does. Note the gate already accepted static `title=`; accepting the
bound form is a consistency fix, not a new claim about how strong a name
`title` is.

Also accepted, on the same principle — the name arrives, whatever the syntax:
  * `aria-label`, `aria-labelledby`, `title`, each bound or literal
  * `ariaLabel` / `aria-label` as NcButton's published prop (camelCase form)
  * a default-slot `<template #default>` carrying text

WHAT STILL FAILS, AND MUST
--------------------------
A button whose body is nothing but an icon component and which declares no
name at all:

    <NcButton type="tertiary" @click="$emit('close')"><CloseIcon /></NcButton>

Usage:
    check_button_name.py <file.vue>...          # findings on stdout
"""
from __future__ import annotations

import re
import sys

COMMENT = re.compile(r'<!--.*?-->', re.DOTALL)
BLOCK = re.compile(r'<(script|style)\b[^>]*>.*?</\1\s*>', re.DOTALL | re.IGNORECASE)

# THE FIX. The optional bound prefix applies to EVERY name attribute, not just
# the first alternative. `(?:^|\s)` still anchors so `data-title=` never counts.
NAME_ATTR = re.compile(
    r'(?:^|\s)(?::|v-bind:)?(?:aria-label|arialabel|aria-labelledby|'
    r'arialabelledby|title|name)\s*=',
    re.IGNORECASE)

# A button element and its body. Quote-aware attribute run so `:title="a > b"`
# does not end the tag early.
BUTTONS = (
    re.compile(r'<(NcButton)\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)>(.*?)</NcButton\s*>',
               re.DOTALL),
    re.compile(r'<(button)\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)>(.*?)</button\s*>',
               re.DOTALL | re.IGNORECASE),
)
TAG = re.compile(r'<[^>]*>', re.DOTALL)
DEFAULT_SLOT = re.compile(r'<template\s+(?:#default|v-slot:default)\b', re.IGNORECASE)


def _named(attrs: str, body: str) -> bool:
    if NAME_ATTR.search(attrs):
        return True
    # A Vue interpolation is dynamic visible text — the button's own label.
    if '{{' in body and '}}' in body:
        return True
    # An explicit default slot renders into the button's label.
    if DEFAULT_SLOT.search(body):
        return True
    # Literal text content. One character is not a name (an icon ligature or a
    # stray bullet); the previous threshold of >= 2 is kept deliberately.
    return len(re.sub(r'\s+', '', TAG.sub('', body))) >= 2


def scan_source(fname: str, src: str) -> list[str]:
    body_src = BLOCK.sub(' ', COMMENT.sub(' ', src))
    findings: list[str] = []
    for pat in BUTTONS:
        for m in pat.finditer(body_src):
            tagname, attrs, body = m.group(1), m.group(2) or '', m.group(3) or ''
            if _named(attrs, body):
                continue
            opening = re.sub(r'\s+', ' ', f'<{tagname}{attrs}>').strip()
            if len(opening) > 200:
                opening = opening[:197] + '...'
            findings.append(
                f'{fname}: {opening} rule=icon-only-button-without-accessible-name')
    return findings


def scan_files(files: list[str]) -> list[str]:
    out: list[str] = []
    for fname in files:
        try:
            with open(fname, encoding='utf-8', errors='replace') as f:
                src = f.read()
        except OSError:
            continue
        out.extend(scan_source(fname, src))
    return out


def main(argv: list[str]) -> int:
    for line in scan_files(argv[1:]):
        print(line)
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
