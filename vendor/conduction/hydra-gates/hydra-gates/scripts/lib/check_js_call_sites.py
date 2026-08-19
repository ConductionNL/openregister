#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Gate-34 (window-confirm) and gate-58 (e2e-networkidle), over CODE only.

Both gates grepped raw file text for a fixed spelling, and both failed in the
two directions #184 named:

GATE-34 (#224), measured in four arms on doriath
------------------------------------------------
  arm 1  a comment saying the component "deliberately avoids
         window.confirm() and uses NcDialog"           -> FAIL, false RED
  arm 2  the same file with that comment deleted       -> PASS  (the control
         proving arm 1's finding IS the comment)
  arm 3  `if (!window['confirm']('Delete everything?'))` -> PASS, false GREEN
  arm 4  the same code written `window.confirm(...)`   -> FAIL  (the control
         proving the probe can fire)

Arm 1 punishes the code that did the right thing and teaches people not to
write down why. Arm 3 is the serious one: the native call it hid on doriath
guarded a CASCADING DELETE. `window['confirm']` is not a contrived bypass —
it is what several minifiers and some lint autofixes emit, and
`const { confirm } = window` is ordinary style.

GATE-58 (#230), measured on larpingapp
--------------------------------------
The gate reported one finding. The line was::

    // live `waitForLoadState('networkidle')` in the suite; every other mention

i.e. a comment explaining that the LAST live call had been removed. The repo
has zero live calls; all ten occurrences under tests/ are comments. This is
doubly perverse — the more carefully a repo documents the ADR-074 rule 4
removal, the more findings it accrues — and unfixable in the leaf, because
the only remedies are deleting the explanation or putting an `exclude` marker
on a comment, which is comment-satisfaction.

WHY A MASK AND NOT A LINE FILTER
--------------------------------
#230 suggested dropping lines that START with `//`. That would still count::

    const wait = 1 // waitForLoadState('networkidle') is banned

and would still fire on a block comment's interior lines that begin with a
letter. The mask blanks the comment REGIONS, whatever column they start in,
and leaves a real call with a trailing comment intact — asserted both ways.

STRING LITERALS ARE KEPT. Gate-19's mask blanks string CONTENTS, because for
gate-19 "is argument 1 a string" is the discriminator. Here the string IS the
evidence: `'networkidle'` and `window['confirm']` are both string literals.
Using the wrong mask would have turned a fixed gate into a dead one — the
failure mode this package has already shipped eleven times.

OFFSETS SURVIVE, WHICH IS WHAT MAKES THE SUPPRESSION MARKER WORK. The
`e2e-networkidle exclude <reason>` marker lives IN a comment — exactly what
the mask blanks — so the match is found in the mask and the marker is read
out of the ORIGINAL text at the same line number.

Usage::

    check_js_call_sites.py --rule native-dialog|networkidle <file> [<file>...]

One finding per line, `path:line: <text>`; exits 0 always (#209).
"""
from __future__ import annotations

import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from source_scope import js_code_mask, js_exec_mask, read_text  # noqa: E402

_DIALOGS = "confirm|alert|prompt"

# ANCHOR, THEN READ THE ORIGINAL.
#
# The anchor patterns run against a mask whose STRING CONTENTS are blanked,
# so a sentence of documentation is not a call site::
#
#     const doc = 'do not use window.confirm here'
#
# But the evidence for the two defects being fixed IS a string literal —
# `window['confirm']`, `'networkidle'`. Both cannot be true of one mask, so
# they are two questions asked of two texts at ONE coordinate: the anchor says
# "this offset is code", and the full pattern is then matched against the
# ORIGINAL text at that same offset. Every mask in source_scope preserves
# offsets precisely so this is available.
# ⚠️ The `=` alternative is a LOOKAHEAD, so it consumes only the `=` itself.
# Written as `=\s*window\s*[.\[]` it swallowed the `window` that follows, and
# `finditer` (which returns NON-OVERLAPPING matches) then never offered the
# `window` anchor — so `const r = window.confirm('x')` matched the alias rule,
# failed it because a `(` follows, and reported NOTHING. A real call, silently
# dropped by an anchor that was one character too greedy.
DIALOG_ANCHOR = re.compile(
    r"(?<![.\w$])window\s*[.\[]"
    r"|=(?=\s*window\s*[.\[])"
    r"|\{[^}\n]*\}\s*=\s*window\b"
)
NETWORKIDLE_ANCHOR = re.compile(r"waitForLoadState\s*\(|waitUntil\s*:")

# `window.confirm(`, `window . confirm (`, and the bracket form. Anchored on
# `window` so a component's own `this.confirm()` — an NcDialog wrapper, which
# is the REMEDY this gate asks for — is not reported.
# A USE, not a mention of the API.
#
# ⚠️ MEASURED BEFORE LANDING. The first cut accepted any `window.confirm`
# reference, called or not. On openbuild that took the gate from 7 findings to
# 14 — every native dialog there is written as
#
#     const ok = typeof window !== 'undefined' && window.confirm
#         ? window.confirm(t('openbuild', 'Delete this automation?'))
#         : true
#
# so the FEATURE-DETECTION GUARD and the call it guards were reported
# separately. Seven defects, fourteen findings: a count that is not a defect
# count, which is #254's lesson in another gate. A guard is not a second
# native dialog, and inflating a security-adjacent number is its own kind of
# false report.
#
# So a reference counts only when it is an ALIAS — bound to a name, where the
# call site is elsewhere and invisible:
#     const c = window.confirm            counts (aliased)
#     const { confirm } = window          counts (destructured)
#     x && window.confirm ? … : …         does NOT count (a truthiness test)
NATIVE_DIALOG = re.compile(
    rf"""
    (?<![.\w$])window\s*\.\s*(?:{_DIALOGS})\s*\(              # window.confirm(
  | (?<![.\w$])window\s*\[\s*(['"])(?:{_DIALOGS})\1\s*\]\s*\( # window['confirm'](
  | =\s*window\s*\.\s*(?:{_DIALOGS})\b(?!\s*\()               # const c = window.confirm
  | =\s*window\s*\[\s*(['"])(?:{_DIALOGS})\2\s*\](?!\s*\()    # const c = window['confirm']
  | \{{[^}}\n]*\b(?:{_DIALOGS})\b[^}}\n]*\}}\s*=\s*window\b     # const {{confirm}} = window
    """,
    re.VERBOSE,
)

NETWORKIDLE = re.compile(
    r"""waitForLoadState\(\s*['"]networkidle['"]"""
    r"""|waitUntil:\s*['"]networkidle['"]"""
)

NETWORKIDLE_EXCLUDE = "e2e-networkidle exclude"


def _hits(src: str, masked: str, anchor: re.Pattern, full: re.Pattern) -> list[int]:
    """Line numbers where *anchor* sits at a code position in *masked* AND
    *full* matches the ORIGINAL text starting there.

    Overlapping anchors on one construct (`window[` matches once) cannot
    double-count, because the offsets are de-duplicated by line and start.
    """
    seen: set[int] = set()
    out: list[int] = []
    for m in anchor.finditer(masked):
        start = m.start()
        if not full.match(src, start):
            continue
        if start in seen:
            continue
        seen.add(start)
        out.append(src.count("\n", 0, start) + 1)
    return out


def _native_dialog(path: str, src: str) -> list[str]:
    masked = js_exec_mask(src, path)
    original = src.splitlines()
    out = []
    for line_no in _hits(src, masked, DIALOG_ANCHOR, NATIVE_DIALOG):
        text = original[line_no - 1].strip() if line_no <= len(original) else ""
        out.append(f"{path}:{line_no}:{text}")
    return out


def _networkidle(path: str, src: str) -> list[str]:
    masked = js_code_mask(src)
    original = src.splitlines()
    out = []
    for line_no in _hits(src, masked, NETWORKIDLE_ANCHOR, NETWORKIDLE):
        text = original[line_no - 1] if line_no <= len(original) else ""
        # The suppression marker is written in a comment, which the mask
        # blanked — so it is read from the ORIGINAL line.
        if NETWORKIDLE_EXCLUDE in text:
            continue
        out.append(f"{path}:{line_no}:{text.strip()}")
    return out


RULES = {
    "native-dialog": _native_dialog,
    "networkidle": _networkidle,
}


def scan_source(rule: str, path: str, src: str) -> list[str]:
    return RULES[rule](path, src)


def main(argv: list[str]) -> int:
    if len(argv) < 4 or argv[1] != "--rule" or argv[2] not in RULES:
        print("usage: check_js_call_sites.py --rule native-dialog|networkidle <file>...",
              file=sys.stderr)
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
