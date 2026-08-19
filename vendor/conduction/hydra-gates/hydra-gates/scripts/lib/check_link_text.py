#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
r"""Gate-42 link-text-quality — a link whose text does not describe its
destination ("click here", "read more", or nothing at all) fails WCAG 2.2 AA
SC 2.4.4 (Link Purpose — In Context).

WHY THIS IS A FILE AND NOT A HEREDOC
------------------------------------
Until now gate-42 ran an inline `python3 - "$vue" <<'PYLQ' >> log 2>/dev/null`
ONCE PER FILE, and never looked at the exit status. Measured 2026-08-08 on
opencatalogi with a `python3` on PATH that exits 1 on every invocation:

    [gate-40] form-label-association: PASS      <- 13 real findings a run earlier
    [gate-42] link-text-quality:      PASS
    [gate-44] autocomplete-attr:      PASS

while gates 34, 37, 38, 39, 41 and 43 — the ones whose checker already lived in
a file behind a return-code guard — all correctly reported SKIPPED (wiring).
A crashed checker left an empty log, and an empty log was read as a clean
sheet. That is ConductionNL/.github#147 / #249 exactly, surviving in the three
a11y gates that still inlined their Python. `2>/dev/null` made the traceback
invisible on top of it.

Moving the logic here buys the wiring guard the runner already applies to
every other a11y helper: the findings are STDOUT, the exit code is a STATUS,
and a non-zero status is a wiring failure rather than a verdict.

WHAT IS FLAGGED
---------------
`<a>`, `<router-link>` and `<RouterLink>` whose visible body, after nested
markup is stripped, is empty or one of the known non-descriptive phrases.

WHAT IS NOT
-----------
  * a link carrying `aria-label` / `aria-labelledby`, bound or literal — it
    names itself, and the accessible name is what SC 2.4.4 is about
  * a link whose body contains a Vue interpolation `{{ … }}` — the text is
    computed and this checker cannot read it
  * anything inside an HTML comment or a `<script>`/`<style>` block: markup
    that does not ship is not a link

QUOTE AND `>` HANDLING
----------------------
The attribute run is matched quote-aware rather than with `[^>]*`. A `>`
inside an attribute value is not the end of a tag — the defect that hid 19
buttons across 6 apps from gate-39 (#259, #198, #236) — and
`<a :title="a > b" href="…">click here</a>` must still be read as one tag.

Usage:
    check_link_text.py <file>...        # findings on stdout, exit 0
"""
from __future__ import annotations

import re
import sys

COMMENT = re.compile(r'<!--.*?-->', re.DOTALL)
BLOCK = re.compile(r'<(script|style)\b[^>]*>.*?</\1\s*>', re.DOTALL | re.IGNORECASE)

# Quote-aware attribute run — see the docstring. `(?:"[^"]*"|'[^']*'|[^>"'])*?`
# consumes whole quoted values, so a `>` inside one cannot terminate the tag.
_ATTRS = r'((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)'
LINKS = [
    (re.compile(r'<a\b' + _ATTRS + r'>(.*?)</a\s*>', re.IGNORECASE | re.DOTALL), 'a'),
    (re.compile(r'<router-link\b' + _ATTRS + r'>(.*?)</router-link\s*>',
                re.IGNORECASE | re.DOTALL), 'router-link'),
    (re.compile(r'<RouterLink\b' + _ATTRS + r'>(.*?)</RouterLink\s*>',
                re.DOTALL), 'RouterLink'),
]

# A BOUND NAME IS STILL A NAME (#259). `:?` binds to the FIRST alternative
# only, which is how gate-39 came to read `:title` as missing for all 22 of
# openbuild's findings. The prefix group is factored out so every alternative
# accepts the bound form.
NAMED = re.compile(r'(?:^|\s)(?::|v-bind:)?(?:aria-label|aria-labelledby)\s*=')
BAD = re.compile(
    r'^(click\s*here|here|read\s*more|learn\s*more|more|continue|see\s*more|details)'
    r'[.!…]?$', re.IGNORECASE)
ANY_TAG = re.compile(r'<[^>]*>', re.DOTALL)


def scan_source(fname: str, src: str) -> list[str]:
    txt = BLOCK.sub(' ', COMMENT.sub(' ', src)).replace('\n', ' ')
    findings: list[str] = []
    for pat, tagname in LINKS:
        for m in pat.finditer(txt):
            attrs = m.group(1) or ''
            body = m.group(2) or ''
            if NAMED.search(attrs):
                continue
            if '{{' in body and '}}' in body:
                continue
            body_text = re.sub(r'\s+', ' ', ANY_TAG.sub('', body)).strip()
            if not body_text or BAD.match(body_text):
                findings.append(
                    f'{fname}: <{tagname}> body="{body_text}" '
                    f'rule=link-text-not-descriptive')
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
