#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
r"""Gate-43 table-headers — every `<th>` must declare `scope=` so screen
readers can associate data cells with their headers (WCAG 2.2 AA SC 1.3.1).

WHY THIS WAS REWRITTEN
----------------------
The previous implementation counted TABLES, and it counted them
at-least-one-wise:

    if re.search(r'<th\b[^>]*\bscope\s*=', body, re.IGNORECASE):
        continue                       # <- whole table accepted

A SINGLE `scope=` attribute anywhere in a table greened the entire table.
Proven by negative control: removing exactly one `scope=` from a passing
table still reported PASS. A table with six headers and one `scope=` — the
common shape when someone fixes the first column and stops — was
indistinguishable from a correct one, and the row headers screen-reader users
actually need were never asked for. See ConductionNL/.github#222.

WHAT IS COUNTED
---------------
One finding per `<table>`, NOT one per `<th>`, and the line says how many of
its headers are unscoped (`unscoped=2/6`). Two reasons: the number stays a
count of DEFECT SITES rather than of attributes, so it is comparable with the
number this gate reported before the fix; and a table is what a person
actually sits down and fixes. A finding count is not a defect count, and the
two should not be silently swapped while a gate is being repaired.

Accepted as a scope declaration: `scope=`, `:scope=`, `v-bind:scope=`. A
bound scope is a real one — `:scope="isRowHeader ? 'row' : 'col'"` produces
the attribute the browser reads.

A HEADER WITH NO NAME IS NOT A HEADER
-------------------------------------
Tightening the rule to per-header immediately produced 8 findings in
openconnector, a repo the old rule reported PASS — and ALL EIGHT were the
same false positive: the empty spacer column that carries a drag handle or a
row-actions menu.

    <th class="cn-rules-editor__col-handle" aria-hidden="true" />
    <th />

`scope=` declares which cells a header NAMES. A header with no accessible
name names nothing, so the attribute associates nothing and adding it is
remediation theatre — the same trap as gate-40's `aria-label` advice, where
the only way to close a finding was to ship a change that helped no one. So a
`<th>` is exempt when it has no accessible name of its own:

  * `aria-hidden="true"`, or `role="presentation"` / `role="none"`
  * empty content — self-closing, or a body that is whitespace only

A header WITH text and no `scope=` is untouched by this and still fails.

WHAT STILL FAILS, AND MUST
--------------------------
  * any NAMED `<th>` in the table without a scope declaration -> th-without-scope
  * a table with `<td>` rows and no `<th>` at all             -> table-without-th

Comments and `<script>`/`<style>` blocks are excluded: markup that does not
ship is not a table. Wrapper components (`<CnDataTable>`, `<NcTable>`) own
their own markup and are not in scope, as before.

Usage:
    check_table_headers.py <file.vue>...        # findings on stdout
"""
from __future__ import annotations

import re
import sys

COMMENT = re.compile(r'<!--.*?-->', re.DOTALL)
BLOCK = re.compile(r'<(script|style)\b[^>]*>.*?</\1\s*>', re.DOTALL | re.IGNORECASE)

TABLE = re.compile(r'<table\b([^>]*)>(.*?)</table\s*>', re.IGNORECASE | re.DOTALL)
# Quote-aware attribute run, so `:title="a > b"` inside a <th> does not end
# the tag early and hide the missing scope behind a parse error.
#
# The body is matched SEPARATELY, in _headers(), rather than as an optional
# group here. A `(?:(.*?)</th>)?` tail looks equivalent and is not: against
# `<th /><th scope="col">A</th>` the optional group happily reaches past the
# self-closed tag to the FIRST `</th>` in the file, swallowing the next
# header whole — two headers were counted as one.
TH_OPEN = re.compile(
    r'<th\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)(/)?>', re.IGNORECASE | re.DOTALL)
TH_CLOSE = re.compile(r'</th\s*>', re.IGNORECASE)
TD = re.compile(r'<td\b', re.IGNORECASE)
SCOPE = re.compile(r'(?:^|\s)(?::|v-bind:)?scope\s*=')
ARIA_HIDDEN_TRUE = re.compile(
    r'(?:^|\s)(?::|v-bind:)?aria-hidden\s*=\s*["\']\s*true\s*["\']')
PRESENTATIONAL_ROLE = re.compile(r'(?:^|\s)role\s*=\s*["\'](?:presentation|none)["\']')
ANY_TAG = re.compile(r'<[^>]*>', re.DOTALL)


def _has_accessible_name(attrs: str, body: str | None, self_closed: bool) -> bool:
    """Does this `<th>` name anything at all?

    A header with no name associates no cells, so `scope=` on it is inert and
    demanding it is remediation theatre. Mirrors gate-40's rule that an
    element named by its slot must not be told to add `aria-label`.
    """
    if ARIA_HIDDEN_TRUE.search(attrs) or PRESENTATIONAL_ROLE.search(attrs):
        return False
    if self_closed or body is None:
        return False
    # Strip nested markup; `{{ t('app', 'Property') }}` counts — it is the
    # translated visible header text.
    return bool(ANY_TAG.sub(' ', body).strip())


def _headers(inner: str) -> list[tuple[str, str | None, bool]]:
    """Every `<th>` in a table body as (attrs, body-or-None, self_closed)."""
    out: list[tuple[str, str | None, bool]] = []
    for m in TH_OPEN.finditer(inner):
        attrs, self_closed = m.group(1) or '', m.group(2) == '/'
        body: str | None = None
        if not self_closed:
            close = TH_CLOSE.search(inner, m.end())
            body = inner[m.end():close.start()] if close else ''
        out.append((attrs, body, self_closed))
    return out


def scan_source(fname: str, src: str) -> list[str]:
    body = BLOCK.sub(' ', COMMENT.sub(' ', src))
    findings: list[str] = []
    for m in TABLE.finditer(body):
        inner = m.group(2) or ''
        ths = _headers(inner)
        named = [a for a, b, sc in ths if _has_accessible_name(a, b, sc)]
        if named:
            unscoped = [a for a in named if not SCOPE.search(a)]
            if unscoped:
                # THE FIX: any unscoped NAMED header fails the table. The old
                # rule was "any scoped header passes it".
                findings.append(
                    f'{fname}: <table> unscoped={len(unscoped)}/{len(named)} '
                    f'rule=th-without-scope')
        elif not ths and TD.search(inner):
            findings.append(f'{fname}: <table> rule=table-without-th')
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
