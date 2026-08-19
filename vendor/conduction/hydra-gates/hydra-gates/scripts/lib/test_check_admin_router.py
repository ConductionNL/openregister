#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Self-test for check_admin_router.py (gate-11).

The gate this covers was DEAD fleet-wide: it read four hard-coded router paths
and fourteen of fifteen apps have a file at none of them, so they were handed
PASS with zero bytes inspected. These assertions pin both halves of the repair —
that the doriath defect is detected wherever the router actually lives, and that
the ADR-079 hand-off which LOOKS like it is not reported.

Run: python3 scripts/lib/test_check_admin_router.py   (exit 0 = green)
"""
from __future__ import annotations

import os
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from check_admin_router import scan_file  # noqa: E402

_FAILED: list[str] = []


def check(name: str, cond: bool, detail: str = "") -> None:
    if cond:
        print(f"PASS — {name}")
    else:
        print(f"FAIL — {name}{(': ' + detail) if detail else ''}")
        _FAILED.append(name)


def run(source: str, suffix: str = ".js") -> list[str]:
    fd, path = tempfile.mkstemp(suffix=suffix)
    try:
        with os.fdopen(fd, "w") as fh:
            fh.write(source)
        return scan_file(path)
    finally:
        os.unlink(path)


# --- the defect this gate exists for ---------------------------------------
DORIATH = """
import AdminRoot from './views/AdminRoot.vue'
const routes = []
routes.push({ path: '/settings', component: AdminRoot })
const router = createRouter({ routes })
"""
f = run(DORIATH)
check("doriath /settings -> AdminRoot is reported", len(f) >= 1, repr(f))
check(
    "the finding names the rule",
    any("admin-path-renders-in-app" in x or "admin-component-imported" in x for x in f),
    repr(f),
)

# --- the import arm ---------------------------------------------------------
f = run("import AdminSettings from '../components/AdminSettings.vue'\n")
check("import of an Admin*.vue component is reported", len(f) == 1, repr(f))

f = run("import Foo from '../views/settings/Foo.vue'\n")
check("import from views/settings/ is reported", len(f) == 1, repr(f))

# --- ANTI-WIDENING: the ADR-079 hand-off is the REMEDIATION, not the defect --
OPENCONNECTOR = """
routes.push({
    path: '/settings',
    beforeEnter: () => {
        window.location.href = generateUrl('/settings/admin/openconnector')
        return false
    },
    component: RoutePageRenderer,
})
"""
f = run(OPENCONNECTOR)
check("ADR-079 beforeEnter hand-off is NOT reported", f == [], repr(f))

f = run("routes.push({ path: '/settings', redirect: '/dashboard' })\n")
check("a /settings route that only redirects is NOT reported", f == [], repr(f))

f = run("routes.push({ path: '/settings' })\n")
check("a /settings route rendering nothing is NOT reported", f == [], repr(f))

# --- ANTI-WIDENING: prose is not code (#184) --------------------------------
COMMENTED = """
// Removed in c7c72e9:
//   routes.push({ path: '/settings', component: AdminRoot })
// Admin settings are rendered by AdminSettings.php instead.
routes.push({ path: '/dashboard', component: Dashboard })
"""
f = run(COMMENTED)
check("a COMMENT describing the removed route is NOT reported", f == [], repr(f))

BLOCK_COMMENT = """
/**
 * import AdminRoot from './views/AdminRoot.vue'
 */
routes.push({ path: '/dashboard', component: Dashboard })
"""
f = run(BLOCK_COMMENT)
check("a block comment quoting the import is NOT reported", f == [], repr(f))

# --- ordinary routers stay clean -------------------------------------------
CLEAN = """
const routes = manifest.pages.map((page) => ({
    path: page.route,
    component: RoutePageRenderer,
}))
routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })
"""
f = run(CLEAN)
check("a manifest-driven router is clean", f == [], repr(f))

# --- MUTATION CHECK: the anti-widening guard must be load-bearing -----------
# If `_LEAVES_RX` stopped exempting hand-offs, the openconnector fixture would
# be reported. Assert the guard is present in the source BEFORE claiming the
# mutation means anything (an unapplied mutation looks exactly like a survivor).
_SRC = open(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "check_admin_router.py")
).read()
check(
    "the hand-off exemption is actually in the source",
    "_LEAVES_RX.search(obj)" in _SRC,
    "guard missing — the openconnector assertion above proves nothing",
)
check(
    "the render requirement is actually in the source",
    "_RENDERS_RX.search(obj)" in _SRC,
    "guard missing",
)

print()
if _FAILED:
    print(f"FAILED: {len(_FAILED)} — {_FAILED}")
    sys.exit(1)
print("ALL check_admin_router assertions passed")
