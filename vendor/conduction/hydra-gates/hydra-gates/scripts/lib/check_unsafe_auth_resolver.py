#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""gate-8 unsafe-auth-resolver — `catch (\\Throwable) { return null; }` in an
authorization resolver.

THE DEFECT THIS GATE EXISTS FOR
-------------------------------
decidesk#45 (2026-04-21): `DecisionApprovalService::getAuthorizationService()`
returned null on Throwable, and the caller guarded the role check with
`if ($auth !== null)`. A brief outage of the auth service therefore meant "check
skipped", not "deny" — CWE-863 / OWASP A01:2021, fail-open.

WHY THIS REPLACES THE AWK
-------------------------
The bash implementation extracted the method body with

    awk 'NR >= start { print; if (NR > start && /^    \\}/) exit }'

and the catch block with `inblk && /^        \\}/`. Both terminators are
HARD-CODED INDENTATION — four spaces for the method's closing brace, eight for
the catch's. That is not a property of PHP; it is a property of one house style.
Measured 2026-08-08 on a tab-indented file:

  * `/^    \\}/` never matches, so "the body" ran to END OF FILE and swallowed
    every later method. A file whose `getAuthorizationService()` correctly
    RETHROWS was reported as a fail-open, because an unrelated
    `getCachedLabel()` further down returned null from its own catch — a cache
    miss reported as a broken authorization gate. FALSE POSITIVE on correct
    code, which is how a security gate loses its audience (#153).
  * The apparent "detection" of tab-indented fail-opens was the same
    over-capture by luck, not a check.

Braces are the language's own block delimiter, so this walks them, over a
comment-masked copy (#184) so a docblock DESCRIBING the anti-pattern — as the
fixed decidesk code now does — is not itself a finding. String contents are
kept: they are evidence about code, and blanking them would delete it.

WHAT IS DELIBERATELY UNCHANGED
------------------------------
Still only `catch (\\Throwable ...)`, and still only a `return null` INSIDE that
catch. The two documented false positives this must keep clearing (procest
ZgwService, 2026-05-26) are methods whose catch returns a 403 / `[]` while a
NORMAL path returns null — those are fail-CLOSED and balanced extraction
excludes them by construction rather than by indentation luck.

Usage:  check_unsafe_auth_resolver.py <php-file> [<php-file>...]
Prints `path:line method=<name> rule=throwable-caught-returns-null`.
"""
from __future__ import annotations

import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

try:
    from source_scope import php_mask, read_text
except Exception:  # pragma: no cover
    php_mask = None
    read_text = None

# Methods whose NAME says they resolve authorization.
_METHOD_RX = re.compile(
    r"^[ \t]*(?:public|private|protected)(?:\s+static)?\s+function\s+"
    r"([A-Za-z0-9_]*(?:[Aa]uthori[sz]ation|[Aa]uth|[Pp]ermission|[Rr]ole|[Gg]uard)"
    r"[A-Za-z0-9_]*)\s*\(",
    re.M,
)
_CATCH_RX = re.compile(r"\bcatch\s*\(\s*\\?Throwable\b")
_RETURN_NULL_RX = re.compile(r"\breturn\s+null\s*;")


def _block_after(masked: str, i: int) -> tuple[int, int] | None:
    """Span of the `{...}` block starting at or after offset *i*."""
    n = len(masked)
    while i < n and masked[i] != "{":
        if masked[i] == ";":  # abstract / interface declaration, no body
            return None
        i += 1
    if i >= n:
        return None
    depth = 0
    j = i
    while j < n:
        if masked[j] == "{":
            depth += 1
        elif masked[j] == "}":
            depth -= 1
            if depth == 0:
                return (i, j + 1)
        j += 1
    return None


def scan_file(path: str) -> list[str]:
    try:
        src = read_text(path)
    except OSError:
        return []
    masked = php_mask(src)

    out: list[str] = []
    for m in _METHOD_RX.finditer(masked):
        name = m.group(1)
        # Step past the parameter list to the body.
        p = masked.find("(", m.end() - 1)
        depth, k = 0, p
        n = len(masked)
        while k < n:
            if masked[k] == "(":
                depth += 1
            elif masked[k] == ")":
                depth -= 1
                if depth == 0:
                    break
            k += 1
        span = _block_after(masked, k + 1)
        if span is None:
            continue
        body = masked[span[0] : span[1]]
        for c in _CATCH_RX.finditer(body):
            cspan = _block_after(body, c.end())
            if cspan is None:
                continue
            if _RETURN_NULL_RX.search(body[cspan[0] : cspan[1]]):
                line = masked.count("\n", 0, m.start()) + 1
                out.append(
                    f"{path}:{line} method={name} rule=throwable-caught-returns-null"
                )
                break
    return out


def main(argv: list[str]) -> int:
    if php_mask is None or read_text is None:
        print(
            "check_unsafe_auth_resolver: source_scope.py could not be imported; "
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
