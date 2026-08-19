#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""gate-11 admin-router — admin settings components must not be routable.

WHY THIS EXISTS
---------------
Nextcloud renders admin settings through its OWN settings framework
(`lib/Settings/*AdminSettings*.php`), which authorizes the request SERVER-SIDE
before the component is ever mounted. Registering that same component in the
in-app vue-router creates a second, UNAUTHORIZED way to reach it: a plain
frontend URL. ADR-004 hard rule. Observed 2026-04-30 on doriath, whose router
carried `/settings -> AdminRoot` (removed in c7c72e9).

THE GATE WAS DEAD (measured 2026-08-08)
---------------------------------------
Until this helper landed, gate-11 read four hard-coded paths:

    src/router/index.js  src/router/index.ts  src/router.js  src/router.ts

Across the fleet, ONE app of fifteen (softwarecatalog) has a file at any of
them. Every other app builds its router in `src/main.js`. So fourteen apps
received `[gate-11] admin-router: PASS` having had ZERO BYTES inspected — the
nldesign shape, where a glob that matches nothing is indistinguishable from a
clean tree.

Proof it was dead, not merely unexercised: the doriath defect was re-planted
verbatim into larpingapp's real router —

    routes.push({ path: '/settings', component: AdminRoot })

— and gate-11 reported PASS. The same line in `src/router.js` reported FAIL.
The detection logic was fine; the gate simply never opened the file.

So routers are DISCOVERED — any tracked `.js`/`.ts`/`.mjs` under `src/` that
CONSTRUCTS one (`createRouter(` / `new VueRouter(`) — and the four legacy paths
are kept so softwarecatalog does not regress. A repo with no router at all is
`na`, never PASS: "this app has no client-side router" and "this app's router
is clean" are different facts and only one of them was ever checked.

WHY THE PATH RULE IS NOT A BARE GREP (anti-widening)
----------------------------------------------------
`path: '/settings'` alone is NOT evidence of this defect. Six fleet apps ship a
legitimate in-app settings page on exactly that route, and one — openconnector,
under ADR-079 — uses it as the REMEDIATION:

    routes.push({
        path: '/settings',
        beforeEnter: () => {
            window.location.href = generateUrl('/settings/admin/openconnector')
            return false
        },
        component: RoutePageRenderer,
    })

That route renders nothing in-app; it leaves the SPA for the server-authorized
settings framework. Reporting it would flag the fix as the bug, and a security
gate that reds correct code trains readers to skip the tier (#153).

So the path arm resolves the ENCLOSING ROUTE OBJECT by balanced braces and
fires only when the route actually RENDERS IN-APP: it declares a
`component`/`components` AND does not navigate away via `redirect` or
`beforeEnter`. The import arm (`Admin*.vue` / `views/settings/`) is the strong
signal and is unchanged — it produces zero findings fleet-wide today.

Comments are blanked before either rule runs (#184): a comment explaining why a
route was REMOVED is prose about code, not code. String CONTENTS are kept,
because `'/settings'` is the evidence.

Usage:  check_admin_router.py <router-file> [<router-file>...]
Prints one `path:line:rule=... detail` finding per violation; exit status is 0
regardless (the caller counts lines, never an exit byte).
"""
from __future__ import annotations

import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

try:
    from source_scope import js_comment_mask, read_text
except Exception:  # pragma: no cover - wiring failure is the caller's problem
    js_comment_mask = None
    read_text = None


# An import whose SPECIFIER names an admin component or the settings view dir.
_IMPORT_RX = re.compile(
    r"""from\s+['"][^'"]*(?:/Admin[A-Z][A-Za-z0-9_]*\.vue|views/settings/)""",
)

# A `path:` entry pointing at the admin/settings surface.
_PATH_RX = re.compile(r"""path\s*:\s*['"](/(?:settings|admin)\b[^'"]*)['"]""")

# Does this route object render something in-app?
_RENDERS_RX = re.compile(r"\bcomponents?\s*:")
# ...or does it navigate away / hand off instead?
_LEAVES_RX = re.compile(r"\b(?:redirect|beforeEnter)\s*:")


def _enclosing_object(masked: str, idx: int) -> str:
    """Return the balanced `{...}` route object containing offset *idx*.

    Walks back to the nearest unmatched `{`, then forward to its partner. Falls
    back to the line itself when the braces do not balance — a malformed router
    must not make the gate throw, and a single line is the conservative scope
    (it can only ever UNDER-report, never invent a finding).
    """
    depth = 0
    start = -1
    for i in range(idx, -1, -1):
        ch = masked[i]
        if ch == "}":
            depth += 1
        elif ch == "{":
            if depth == 0:
                start = i
                break
            depth -= 1
    if start < 0:
        return masked[masked.rfind("\n", 0, idx) + 1 : masked.find("\n", idx)]

    depth = 0
    for j in range(start, len(masked)):
        ch = masked[j]
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                return masked[start : j + 1]
    return masked[start:]


def scan_file(path: str) -> list[str]:
    try:
        src = read_text(path)
    except OSError:
        return []
    masked = js_comment_mask(src)

    out: list[str] = []
    for m in _IMPORT_RX.finditer(masked):
        line = masked.count("\n", 0, m.start()) + 1
        out.append(
            f"{path}:{line}: rule=admin-component-imported-into-router "
            f"{src.splitlines()[line - 1].strip()[:160]}"
        )

    for m in _PATH_RX.finditer(masked):
        line = masked.count("\n", 0, m.start()) + 1
        obj = _enclosing_object(masked, m.start())
        # Anti-widening: only an IN-APP RENDER is the defect. A route that
        # redirects or hands off to the server-authorized settings framework is
        # the remediation, not the bug (ADR-079 / openconnector).
        if not _RENDERS_RX.search(obj):
            continue
        if _LEAVES_RX.search(obj):
            continue
        out.append(
            f"{path}:{line}: rule=admin-path-renders-in-app "
            f"route '{m.group(1)}' renders a component in the in-app router; "
            f"register it via lib/Settings/*AdminSettings*.php instead"
        )
    return out


def main(argv: list[str]) -> int:
    if js_comment_mask is None or read_text is None:
        print(
            "check_admin_router: source_scope.py could not be imported; "
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
