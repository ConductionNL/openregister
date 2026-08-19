#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""gate-10 initial-state — server data must not be read out of the DOM.

THE RULE
--------
Server-side data reaches the frontend via `IInitialState::provideInitialState()`
in PHP and `loadState()` from `@nextcloud/initial-state` in JS. Reading it back
out of a DOM data-attribute instead breaks on CSP-hardened instances and
bypasses the canonical pattern (ADR-004). Observed 2026-04-30 on doriath, where
`AdminRoot.vue` read `document.getElementById('doriath-settings').dataset.version`.

WHY THIS REPLACES THE GREP
--------------------------
The gate was one expression:

    getElementById\\s*\\([^)]+\\)[^.]*\\.dataset\\b

which requires the lookup and the read to be ADJACENT ON ONE LINE. Measured
2026-08-08, all of these reported PASS:

    const el = document.getElementById('x')     ← the TWO-STEP form. Splitting
    const v  = el.dataset.version                 one line in two switched the
                                                  gate off.
    document.querySelector('#x').dataset.version  ← a different lookup, the
                                                    identical defect.
    document.getElementById('x').getAttribute('data-version')
                                                  ← the same read spelled the
                                                    older way.

The two-step form is the one that matters most: it is what the doriath line
becomes the first time anyone refactors it, and nothing would have reported it.

ANTI-WIDENING — WHY NOT JUST GREP `.dataset`
--------------------------------------------
Because `.dataset` is overwhelmingly LEGITIMATE in Vue: `event.target.dataset.id`
in a click handler is reading back a value the same component wrote, not
smuggling server state. Flagging it would bury the real finding under fleet-wide
noise, which is the failure that cost gates 56/57 their audience.

So a finding requires a DOM LOOKUP to be tied to the read:

  * one-step  — `<lookup>(...).dataset.X` / `<lookup>(...).getAttribute('data-X')`
  * two-step  — a variable ASSIGNED from a lookup in the same file, then read
                via `.dataset.X` / `.getAttribute('data-X')`

`event.target`, `this.$refs.foo` and friends are never lookups, so they never
start a chain.

TWO MORE EXCLUSIONS, EACH MEASURED (fleet churn from this change: ZERO)
-----------------------------------------------------------------------
Running the widened rules across sixteen repos produced five findings. Both
clusters were wrong, and both are excluded by a rule rather than a waiver:

1. A dataset key the SAME FILE ALSO WRITES is the component's own bookkeeping,
   not server state. openregister `src/user-dashboard.js`:

       if (el.dataset.openregisterMounted === '1') { return }   // line 50
       el.dataset.openregisterMounted = '1'                     // line 53

   That is a mount-idempotency flag. Nothing put it there but this script, so
   `loadState()` has nothing to load. Excluded when an assignment to the same
   key exists in the file.

2. `data-requesttoken` is NOT IInitialState data. Nextcloud core renders it into
   `<head>` itself, and its canonical accessor is `getRequestToken()` from
   `@nextcloud/auth` — NOT `loadState()`. Three openconnector modals read it.
   Reporting them would print a remedy that does not apply to the thing being
   reported, which is how gate-9 came to advise changes that broke endpoints.
   This is a SCOPE boundary, not a suppression: the CSRF token belongs to
   whichever gate owns `@nextcloud/auth` adoption, and gate-10 owns app state.

Comments are blanked first (#184) — a comment explaining that a component no
longer reads the DOM is prose about code.

Usage:  check_initial_state.py <file> [<file>...]
"""
from __future__ import annotations

import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

try:
    from source_scope import js_comment_mask, read_text, script_mask
except Exception:  # pragma: no cover
    js_comment_mask = None
    read_text = None
    script_mask = None

# A DOM lookup that yields a server-rendered element.
_LOOKUP = r"(?:document|window\.document)\s*\.\s*(?:getElementById|querySelector)\s*\("
# Reading server data off it.
_READ = r"(?:\.\s*dataset\s*\.\s*(?P<key>\w+)|\.\s*getAttribute\s*\(\s*['\"]data-(?P<attr>[\w-]+)['\"]\s*\))"

# Not IInitialState data — see the module docstring.
_NOT_INITIAL_STATE = {"requesttoken"}

# `el.dataset.foo = ...` — the file writes this key itself.
_DATASET_WRITE_RX = re.compile(r"\.\s*dataset\s*\.\s*(\w+)\s*=(?!=)")


def _excluded(m: "re.Match[str]", written: set[str]) -> bool:
    key = m.groupdict().get("key")
    attr = m.groupdict().get("attr")
    if attr is not None and attr.replace("-", "").lower() in _NOT_INITIAL_STATE:
        return True
    if key is not None and key in written:
        return True
    return False

_ONE_STEP_RX = re.compile(_LOOKUP + r"[^)]*\)\s*[!?]?" + _READ)
# `const el = document.getElementById('x')` — capture the bound name.
_ASSIGN_RX = re.compile(
    r"(?:const|let|var)\s+(\w+)\s*=\s*" + _LOOKUP,
)
_THIS_ASSIGN_RX = re.compile(r"this\s*\.\s*(\w+)\s*=\s*" + _LOOKUP)

# A name that is ALSO a function/arrow parameter somewhere in the file.
#
# The two-step rule matches by NAME across the whole file, which is scope-blind.
# Measured on nldesign `js/admin.js`: line 1114 binds
# `var btn = document.getElementById('nldesign-save-btn')`, and that one line
# put EVERY `btn` in a 1700-line file into scope — including the three
# `forEach(function (btn) { ... })` callbacks whose `btn` is the clicked element,
# i.e. the component's own markup, not server state. Four false positives from
# one binding.
#
# Rather than implement JS scoping, an AMBIGUOUS NAME IS DROPPED: if the
# identifier is ever a parameter, this file cannot tell the two apart and
# declines to guess. That can only UNDER-report, which is the correct direction
# for a rule whose false positives would otherwise bury the real finding.
_PARAM_RX = re.compile(
    r"(?:function\s*\**\s*\w*\s*\(([^)]*)\)|\(([^)]*)\)\s*=>|(?:^|[^\w.])(\w+)\s*=>)",
    re.M,
)


def _ambiguous_names(masked: str) -> set[str]:
    out: set[str] = set()
    for m in _PARAM_RX.finditer(masked):
        for group in m.groups():
            if not group:
                continue
            for part in group.split(","):
                name = part.strip().split("=")[0].strip().lstrip(".").strip()
                if re.fullmatch(r"\w+", name):
                    out.add(name)
    return out


def scan_file(path: str) -> list[str]:
    try:
        src = read_text(path)
    except OSError:
        return []
    if path.endswith(".vue") and script_mask is not None:
        masked = script_mask(src, path)
    else:
        masked = js_comment_mask(src)
    lines = src.splitlines()

    written = {m.group(1) for m in _DATASET_WRITE_RX.finditer(masked)}

    hits: set[int] = set()
    for m in _ONE_STEP_RX.finditer(masked):
        if _excluded(m, written):
            continue
        hits.add(masked.count("\n", 0, m.start()) + 1)

    # Two-step: a name bound to a DOM lookup, then read for server data.
    # Names that are also parameters somewhere are ambiguous — see _PARAM_RX.
    ambiguous = _ambiguous_names(masked)
    names = {m.group(1) for m in _ASSIGN_RX.finditer(masked)} - ambiguous
    this_names = {m.group(1) for m in _THIS_ASSIGN_RX.finditer(masked)} - ambiguous
    for name in names:
        rx = re.compile(r"(?<![\w.])" + re.escape(name) + r"\s*[!?]?" + _READ)
        for m in rx.finditer(masked):
            if _excluded(m, written):
                continue
            hits.add(masked.count("\n", 0, m.start()) + 1)
    for name in this_names:
        rx = re.compile(r"this\s*\.\s*" + re.escape(name) + r"\s*[!?]?" + _READ)
        for m in rx.finditer(masked):
            if _excluded(m, written):
                continue
            hits.add(masked.count("\n", 0, m.start()) + 1)

    out = []
    for line in sorted(hits):
        text = lines[line - 1].strip() if line - 1 < len(lines) else ""
        out.append(f"{path}:{line}: {text[:180]}")
    return out


def main(argv: list[str]) -> int:
    if read_text is None or js_comment_mask is None:
        print(
            "check_initial_state: source_scope.py could not be imported; "
            "NOTHING was inspected",
            file=sys.stderr,
        )
        return 2
    if len(argv) < 2:
        print(__doc__, file=sys.stderr)
        return 2
    for path in argv[1:]:
        for finding in scan_file(path):
            print(finding)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
