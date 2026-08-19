#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Self-test for check_initial_state.py (gate-10).

Run: python3 scripts/lib/test_check_initial_state.py   (exit 0 = green)
"""
from __future__ import annotations

import os
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from check_initial_state import scan_file  # noqa: E402

_FAILED: list[str] = []


def check(name: str, cond: bool, detail: str = "") -> None:
    if cond:
        print(f"PASS - {name}")
    else:
        print(f"FAIL - {name}{(': ' + detail) if detail else ''}")
        _FAILED.append(name)


def run(src: str, suffix: str = ".js") -> list[str]:
    fd, path = tempfile.mkstemp(suffix=suffix)
    try:
        with os.fdopen(fd, "w") as fh:
            fh.write(src)
        return scan_file(path)
    finally:
        os.unlink(path)


# --- the doriath defect, one-step ------------------------------------------
f = run("export const v = document.getElementById('d-settings').dataset.version\n")
check("one-step getElementById().dataset is reported", len(f) == 1, repr(f))

# --- the shapes the old single-line grep could not see ----------------------
f = run("const el = document.getElementById('d-settings')\nexport const v = el.dataset.version\n")
check("TWO-STEP getElementById -> .dataset is reported", len(f) == 1, repr(f))

f = run("export const v = document.querySelector('#d').dataset.version\n")
check("querySelector().dataset is reported", len(f) == 1, repr(f))

f = run("export const v = document.getElementById('d').getAttribute('data-version')\n")
check("getAttribute('data-*') is reported", len(f) == 1, repr(f))

f = run("var s = document.getElementById('d')\nvar t = JSON.parse(s.getAttribute('data-token-sets') || '[]')\n")
check("the nldesign js/admin.js shape is reported", len(f) == 1, repr(f))

# --- ANTI-WIDENING ----------------------------------------------------------
f = run("import { loadState } from '@nextcloud/initial-state'\nexport const v = loadState('a','version','x')\n")
check("loadState() is clean", f == [], repr(f))

f = run("function onClick (event) { return event.target.dataset.id }\n")
check("event.target.dataset is NOT reported", f == [], repr(f))

f = run("const rowId = this.$refs.row.dataset.id\n")
check("this.$refs....dataset is NOT reported", f == [], repr(f))

f = run("// document.getElementById('c').dataset.version  <- removed, see ADR-004\n")
check("a COMMENT describing the removed read is NOT reported", f == [], repr(f))

# A dataset key the same file WRITES is the component's own bookkeeping.
f = run("var el = document.getElementById('root')\nif (el.dataset.mounted === '1') { }\nel.dataset.mounted = '1'\n")
check("a dataset key the file also WRITES is NOT reported", f == [], repr(f))

# The NC request token is not IInitialState data (its accessor is getRequestToken()).
f = run("const t = document.querySelector('head[data-requesttoken]').getAttribute('data-requesttoken')\n")
check("data-requesttoken is NOT reported", f == [], repr(f))

# SCOPE-BLINDNESS: one `var btn = document.getElementById(...)` must not put
# every callback parameter named `btn` into scope. Measured on nldesign
# js/admin.js: one binding at line 1114 produced four false positives.
SHADOW = """
var settingsEl = document.getElementById('nld-settings')
var tokenSets = JSON.parse(settingsEl.getAttribute('data-token-sets') || '[]')
var btn = document.getElementById('nld-save-btn')
root.querySelectorAll('.b').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var view = btn.getAttribute('data-view')
        console.log(view)
    })
})
"""
f = run(SHADOW)
check(
    "a name that is ALSO a callback parameter is dropped as ambiguous",
    len(f) == 1 and "data-token-sets" in f[0],
    repr(f),
)

# --- MUTATION CHECK ---------------------------------------------------------
_SRC = open(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "check_initial_state.py")
).read()
check("the ambiguous-name guard is in the source", "_ambiguous_names" in _SRC)
check("the self-written-key guard is in the source", "_DATASET_WRITE_RX" in _SRC)
check("the requesttoken scope boundary is in the source", "_NOT_INITIAL_STATE" in _SRC)

# The runner must enumerate js/ as well as src/ — the nldesign na blackout.
_RUNNER = open(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "run-hydra-gates.sh")
).read()
check(
    "gate-10's surface includes js/, not just src/",
    "_enum_tracked '\\.(vue|js|ts)$' src js" in _RUNNER,
)

print()
if _FAILED:
    print(f"FAILED: {len(_FAILED)} - {_FAILED}")
    sys.exit(1)
print("ALL check_initial_state assertions passed")
