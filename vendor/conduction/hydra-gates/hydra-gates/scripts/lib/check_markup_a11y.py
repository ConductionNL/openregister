#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Gate-31 (img-alt) and gate-32 (semantic-controls), over MARKUP only.

WHY THIS MOVED OUT OF THE BASH GATES
------------------------------------
Both gates read a file like this::

    _flat=$(tr '\\n' ' ' < "${vue}")
    echo "${_flat}" | grep -oE '<img\\b[^>]*>'

Two defects in three lines, and both were measured live:

1. THE WHOLE FILE IS FLATTENED, so `<script>` and comments are scanned as
   markup. On openbuild ALL THREE gate-31 findings were JSDoc prose —
   ``* @param {Event} e - The `<img>` `error` event`` (#235). On launchpad the
   single finding was a docblock in `<script>` explaining that
   `CnDashboardIcon` resolves a URL to an `<img>` (#220). The finding text was
   the tell: a real tag prints with its attributes, these printed as the bare
   four characters `<img>`.

   gate-32 has the same hole with the opposite sign. On softwarecatalog the
   comment written ABOVE a repaired element — "role/tabindex/keydown rather
   than a bare `<div @click>`" — was itself scored as the bad element, and
   REWORDING THE COMMENT cleared the gate with the markup byte-identical
   (#236). A gate a comment can clear is a gate a comment can also defeat.

2. `[^>]*` ENDS THE ELEMENT AT THE FIRST `>`, which in Vue is very often the
   arrow of `:reduce="option => option.value"` — gate-12's #236 defect, and
   the same shape as gate-9's `[^)]*` in #198. It is fixed here for all three
   gates at once by extracting through `source_scope.iter_open_tags`.

WHAT DOES NOT CHANGE
--------------------
The RULES are untouched — the accepted alt spellings, the required
role/tabindex/key-handler trio, the `<a href>` and `@click.stop` exemptions
are all carried over verbatim from the bash blocks. This changes WHERE the
gate looks, not what it asks, so before/after counts stay comparable and a
drop in findings is attributable to prose leaving the scope.

Usage::

    check_markup_a11y.py --rule img-alt|semantic-controls <file> [<file>...]

Prints one finding per line in the shape the bash gates emitted, so the
runner still counts lines. Exits 0 always; the count is the answer and the
exit status is a status (#209 — gate-19 returned its finding count as an exit
byte and 266 was reported as 10).
"""
from __future__ import annotations

import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from source_scope import iter_open_tags, markup_mask, read_text  # noqa: E402

# --- gate-31 ---------------------------------------------------------------
# `alt=`, `:alt=`, `v-bind:alt=`, `alt-text=` (some Conduction components
# proxy the prop under that name). Carried over unchanged from the bash gate.
ALT_ATTR = re.compile(r'(^|[\s])(:?alt|v-bind:alt|alt-text)=')

# --- gate-32 ---------------------------------------------------------------
NON_SEMANTIC = {
    "div", "span", "a", "p", "li", "article", "section",
    "header", "footer", "aside", "main", "nav",
}
CLICK = re.compile(r'@click|v-on:click')
HREF = re.compile(r'(^|\s)(:?href|v-bind:href)=')
ROLE = re.compile(r'(^|[\s])(:?role|v-bind:role)=')
TABINDEX = re.compile(r'(^|[\s])(:?tabindex|v-bind:tabindex)=')
KEYHANDLER = re.compile(r'@keydown|@keyup|@keypress|v-on:keydown|v-on:keyup|v-on:keypress')
# `@click.stop` with no handler (or an empty one) is event management, not a
# user interaction. opencatalogi's PublicationCard wrappers are the fleet
# example; the bash gate already exempted them.
CLICK_WITH_HANDLER = re.compile(r'@click(\.[a-z]+)*\s*=\s*"[^"]+"|v-on:click(\.[a-z]+)*\s*=\s*"[^"]+"')
CLICK_STOP_BARE = re.compile(r'@click\.stop(\s|/|>|$|=\s*("\s*"|\'\s*\'))')


def _img_alt(path: str, masked: str) -> list[str]:
    out = []
    for tag in iter_open_tags(masked, {"img"}):
        if ALT_ATTR.search(tag.attrs):
            continue
        out.append(f"{path}:{tag.line}: {tag.flat}")
    return out


def _semantic_controls(path: str, masked: str) -> list[str]:
    out = []
    for tag in iter_open_tags(masked, NON_SEMANTIC):
        if not CLICK.search(tag.attrs):
            continue
        if tag.name == "a" and HREF.search(tag.attrs):
            continue
        if CLICK_STOP_BARE.search(tag.attrs) and not CLICK_WITH_HANDLER.search(tag.attrs):
            continue
        missing = []
        if not ROLE.search(tag.attrs):
            missing.append("role=")
        if not TABINDEX.search(tag.attrs):
            missing.append("tabindex=")
        if not KEYHANDLER.search(tag.attrs):
            missing.append("@keydown")
        if missing:
            out.append(f"{path}:{tag.line}: {tag.flat} rule=missing[{','.join(missing)}]")
    return out


RULES = {
    "img-alt": _img_alt,
    "semantic-controls": _semantic_controls,
}


def scan_source(rule: str, path: str, src: str) -> list[str]:
    return RULES[rule](path, markup_mask(src, path))


def main(argv: list[str]) -> int:
    if len(argv) < 4 or argv[1] != "--rule" or argv[2] not in RULES:
        print(
            "usage: check_markup_a11y.py --rule img-alt|semantic-controls <file>...",
            file=sys.stderr,
        )
        return 2
    rule = argv[2]
    for path in argv[3:]:
        try:
            src = read_text(path)
        except OSError:
            continue
        for line in scan_source(rule, path, src):
            print(line)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
