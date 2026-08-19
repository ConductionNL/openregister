#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
r"""Gate-37 aria-hidden-focusable — an element hidden from assistive tech must
not also be in the tab order (WCAG 2.2 AA SC 4.1.2; axe-core `aria-hidden-focus`).

The failure this catches is real and serious: keyboard focus lands on a
control that screen readers do not announce, so the user is on "nothing".

WHY THIS WAS REWRITTEN
----------------------
The previous implementation treated `tabindex` **with any value** as proof of
focusability:

    echo "${tag}" | grep -qE '(^|[[:space:]])(:?tabindex|v-bind:tabindex)[[:space:]]*=' && _focusable=1

`tabindex="-1"` is the attribute that **REMOVES** an element from the tab
order. It is the one value that proves the opposite of what the gate concluded.
So the gate's own subject — "hidden from AT *and still reachable by keyboard*"
— was inverted for every element that had already been fixed.

The canonical hidden-file-input pattern trips it exactly:

    <input ref="fileInput" type="file" :aria-hidden="true" tabindex="-1"
           @change="onFileInputChange">            (nc-vue CnFilesWidget)

That input is correct. It is hidden from AT, removed from the tab order, and
driven by a visible `<button>` next to it. The gate's advice was to remove
`aria-hidden` (exposing a control with no name to screen readers) or to remove
`tabindex="-1"` (putting a control screen readers cannot see BACK in the tab
order — the very defect this gate exists to catch). Both remediations regress
accessibility. See ConductionNL/.github#222.

WHAT STILL FAILS, AND MUST
--------------------------
Everything genuinely reachable:

  * `tabindex="0"` (or any non-negative value) + `aria-hidden="true"`
  * a native focusable element (`<a href>`, `<button>`, `<input>`, `<select>`,
    `<textarea>`, `<details>`, `<summary>`, `<iframe>`) with `aria-hidden="true"`
    and no negative `tabindex` to take it out of the order
  * an interactive `role=` + `aria-hidden="true"`
  * a BOUND `:tabindex="expr"` whose value cannot be read here — unknown is not
    "safe", and the previous behaviour (flag it) is kept.

The negative-`tabindex` exemption is deliberately the ONLY relaxation, and it
overrides the native-tag signal, because that is what the attribute does in
every browser: `tabindex="-1"` takes ANY element, native or not, out of
sequential navigation.

Usage:
    check_aria_hidden_focusable.py <file.vue>...     # findings on stdout
"""
from __future__ import annotations

import re
import sys

# Opening tags, quote-aware so a `>` inside an attribute value does not end
# the tag early (`:title="a > b"`). The old grep used `[^>]*` and did.
TAG = re.compile(
    r'<([a-zA-Z][a-zA-Z0-9-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)/?>',
    re.DOTALL,
)
COMMENT = re.compile(r'<!--.*?-->', re.DOTALL)
BLOCK = re.compile(r'<(script|style)\b[^>]*>.*?</\1\s*>', re.DOTALL | re.IGNORECASE)

# `aria-hidden` truthy, literal or bound: aria-hidden="true", :aria-hidden="true".
ARIA_HIDDEN_TRUE = re.compile(
    r'(?:^|\s)(?::|v-bind:)?aria-hidden\s*=\s*["\']\s*true\s*["\']')

NATIVE_FOCUSABLE = {'a', 'button', 'input', 'select', 'textarea',
                    'details', 'summary', 'iframe', 'audio', 'video'}

INTERACTIVE_ROLE = re.compile(
    r'(?:^|\s)role\s*=\s*"(?:button|link|menuitem|tab|checkbox|radio|switch|'
    r'option|treeitem|gridcell|columnheader|rowheader|slider|spinbutton|'
    r'searchbox|combobox|textbox)"')

ANY_TABINDEX = re.compile(r'(?:^|\s)(?::|v-bind:)?tabindex\s*=')
# A tabindex whose value is a readable integer literal, bound or not.
# `:tabindex="-1"` is as static as `tabindex="-1"`.
TABINDEX_LITERAL = re.compile(
    r'(?:^|\s)(?::|v-bind:)?tabindex\s*=\s*["\']\s*(-?\d+)\s*["\']')

HREF = re.compile(r'(?:^|\s)(?::|v-bind:)?href\s*=')


def _tabindex_value(attrs: str) -> int | None:
    """The element's tabindex as an int, or None when absent or unreadable."""
    m = TABINDEX_LITERAL.search(attrs)
    return int(m.group(1)) if m else None


def is_focusable(name: str, attrs: str) -> bool:
    """Can a keyboard user reach this element by tabbing to it?"""
    ti = _tabindex_value(attrs)
    # THE FIX. A negative tabindex removes the element from sequential
    # navigation, whatever it is — native control, ARIA widget or div. It is
    # the correct half of the canonical hidden-input pattern, and treating it
    # as focusable inverted this gate's own subject.
    if ti is not None and ti < 0:
        return False
    if ti is not None:          # 0 or positive: explicitly IN the tab order.
        return True
    # An unreadable bound value (`:tabindex="foo"`) could be anything; unknown
    # is not safe, so the pre-existing behaviour is kept.
    if ANY_TABINDEX.search(attrs):
        return True
    if name in NATIVE_FOCUSABLE:
        # `<a>` is focusable only with an href.
        return name != 'a' or bool(HREF.search(attrs))
    return bool(INTERACTIVE_ROLE.search(attrs))


def scan_source(fname: str, src: str) -> list[str]:
    body = BLOCK.sub(' ', COMMENT.sub(' ', src))
    findings: list[str] = []
    for m in TAG.finditer(body):
        name, attrs = m.group(1), m.group(2) or ''
        # Vue/NC component shells (<NcAvatar>, <RouterLink>) are component
        # invocations; their internal a11y wiring is the component's problem,
        # not the consumer's. Unchanged from the previous implementation.
        if name[:1].isupper():
            continue
        if not ARIA_HIDDEN_TRUE.search(attrs):
            continue
        if not is_focusable(name, attrs):
            continue
        tag = re.sub(r'\s+', ' ', m.group(0)).strip()
        if len(tag) > 200:
            tag = tag[:197] + '...'
        findings.append(f'{fname}: {tag} rule=aria-hidden-on-focusable-element')
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
