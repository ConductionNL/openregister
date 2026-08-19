#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Gate-12 nc-input-labels — every `<NcSelect>` must name itself.

A manual `<label>` next to an `NcSelect` does not associate with the
component's internal combobox input, so the accessible name has to come
from the component's own API: `input-label` (rendered as the visible
label) or `aria-label-combobox`. WCAG 2.2 AA SC 1.3.1 / 4.1.2, ADR-004
hard rule. Observed 2026-04-30 on doriath.

WHY THIS MOVED OUT OF THE BASH GATE
-----------------------------------
The gate used to flatten the file's newlines and run::

    grep -oE '<NcSelect[^>]*>'

`[^>]*` ends the element at the FIRST `>` character in the source. In an
`NcSelect` that is almost never the end of the tag, because the idiomatic
vue-select usage puts an arrow function in an attribute value::

    <NcSelect
        v-model="selectedRoundId"
        :options="roundOptions"
        :reduce="(o) => o.id"
        :input-label="t('scholiq', 'Round')"
        :aria-label-combobox="t('scholiq', 'Round')" />
                  ^
                  the `>` of `=>` — extraction stops here

Everything after `:reduce` is invisible to the gate, so the very props it
is looking for are cut off and the element is reported as unnamed.
Measured on scholiq 2026-08-08: **18 findings, 18 of them false** — every
flagged `NcSelect` already carried `:input-label` AND
`:aria-label-combobox`, in every case written after the `:reduce` prop.

`:reduce` is not exotic. It is how vue-select maps an option object to the
stored value, so a project that uses object options at all hits this on
most of its selects. The gate was anti-correlated with its own subject in
those files: adding the label prop could not clear it, and deleting
`:reduce` could.

The fix is to end the tag at a `>` that is not inside a quoted attribute
value. That is the same tag pattern ``check_form_labels.py`` already uses,
so the two a11y gates now agree on where an element stops.

WHAT STILL FAILS, AND MUST
--------------------------
An `NcSelect` carrying none of `input-label` / `inputLabel` /
`aria-label-combobox` / `ariaLabelCombobox` is still reported, including
when it sits next to a hand-written `<label for=…>` — that is the whole
point of the rule. The accepted prop set is unchanged from the bash gate
on purpose, so the before/after numbers stay comparable: this change fixes
where an element ENDS, not what counts as a name.

Markup inside an HTML comment is not scanned. A commented-out `NcSelect`
renders nothing and can carry no accessible name. Neither is an `NcSelect`
written inside the `<script>` block — a JSDoc line naming the component is
prose about markup, which is what put three false findings on openbuild's
gate-31 (#235) and one on launchpad's (#220). Both scopes come from
`source_scope.markup_mask`, so gates 12, 31 and 32 agree on what "markup"
means instead of each carrying its own answer.

Usage::

    check_nc_select_labels.py <vue-file> [<vue-file> ...]

Prints one `path: <tag…>` line per violation, the same shape the bash gate
emitted, so the runner counts lines unchanged. Exits 0 always.
"""
from __future__ import annotations

import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from source_scope import iter_open_tags, markup_mask  # noqa: E402

# The name-bearing props NcSelect publishes. Both the kebab and camel
# spellings, bare or bound (`:input-label`, `v-bind:inputLabel`).
LABEL_PROP = re.compile(
    r'(^|\s)(:|v-bind:)?'
    r'(input-label|inputLabel|aria-label-combobox|ariaLabelCombobox)\s*='
)


def scan_source(fname: str, src: str) -> list[str]:
    """Return one finding line per unnamed `<NcSelect>` in *src*."""
    out: list[str] = []
    for tag in iter_open_tags(markup_mask(src, fname), {'NcSelect'}):
        if LABEL_PROP.search(tag.attrs):
            continue
        # Report the element as one line, the way the bash gate did, so an
        # existing consumer of the log still reads it.
        out.append(f'{fname}: {tag.flat}')
    return out


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
