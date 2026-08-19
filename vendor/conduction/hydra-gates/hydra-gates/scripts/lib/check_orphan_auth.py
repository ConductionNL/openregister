#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Gate-6 orphan-auth — access-control-aware orphaned-method scanner.

Historically this gate lived inline in ``scripts/run-hydra-gates.sh`` and
keyed *purely* on a name-verb prefix
(``is|requires?|validate|authorize|check|ensure|verify|assert``). Any
public method whose name started with one of those verbs and had zero
callers was flagged as a "dead auth method". That over-matched badly:
generic boolean predicates that are NOT access controls got flagged —

  * ``Iv3TaakveldList::isValidCode``        (reference-data lookup)
  * ``Iv3TaakveldList::isDeprecated``       (reference-data lookup)
  * ``Kcc\\SlaCalculator::isBreached``       (SLA deadline query)
  * ``WOOAnonymisationAssistService::isLlmAssistAvailable`` (UI availability)

None of those are authorization. Flagging them inflated the fleet
"dead auth" count with noise, and a noisy security gate gets ignored —
the exact failure mode that cost us with gates 56/57.

The fix keeps the verb prefix as the *candidate* filter but then
requires a genuine **authorization-domain signal** before a method
counts as access-control. A verb-prefixed method is in scope iff:

  ENFORCEMENT verb (authorize/require/requires/ensure/assert/validate)
    AND (the body throws an exception  OR  any auth signal S1..S5)
  OR
  PREDICATE verb (is/check/verify)
    AND at least one auth signal S1..S5

Auth signals:
  S1  subject parameter  — a parameter named/typed like an auth subject
                           (user, actor, principal, userId, uid, bsn,
                           role, group, member, owner, subject,
                           requester; IUser / IGroup / IUserSession types)
  S2  permission denial  — body throws/returns a permission-denied shape
                           (*Forbidden*, *Unauthorized*, *NotPermitted*,
                           *NotAuthorized*, *AccessDenied*,
                           *PermissionDenied*, STATUS_FORBIDDEN,
                           STATUS_UNAUTHORIZED)
  S3  authz-service touch — body references a permission/authorization/
                           rbac/acl/mandate/role service, isAdmin(),
                           getUserGroups(), ->groupManager, ->userSession
  S4  authz name token   — the method name itself contains an
                           authorization word (Auth, Permission, Access,
                           Grant, Forbid, Rbac, Acl, Mandate, Owner,
                           Admin, Allowed, Role, Privilege, Consent)
  S5  explicit marker    — an @authorization docblock tag or a PHP
                           authorization attribute (#[Authorize...])

A method that passes the access-control test but has at least one
external caller anywhere in ``lib/`` or ``src/`` is fine — the gate only
flags access-control methods that are *defined but never called*
(OWASP A01:2021 — an unwired guard is identical to no guard).

THE ROUTER IS ALSO A CALLER (.github#290)
-----------------------------------------
A routed controller action is reached by reflection from the route table,
so it has no ``->method(`` call site anywhere and the corpus above cannot
contain one. Such a method is therefore ALSO not an orphan when it is
named in ``appinfo/routes.php`` (or ``appinfo/routes/*.php``) as
``'<lowerCamelController>#<method>'``, or carries a ``#[Route]`` /
``#[ApiRoute]`` attribute. This is keyed on the exact declaration that
makes it reachable — see ``_is_routed`` — and applies to controllers only,
because a service is not routable.

Usage::

    python3 scripts/lib/check_orphan_auth.py <php-file> [<php-file> ...]

The argv files are the (diff-scoped) ``lib/Service/*.php`` +
``lib/Controller/*.php`` candidates to analyse. The caller corpus is
scanned repo-wide from the current working directory (``lib/`` + ``src/``)
so a caller in an unchanged file still counts. Prints one finding line
per orphan in the format the bash wrapper consumes::

    <file>:<line> method=<name> rule=defined-but-never-called

Exits 0 always; the bash gate counts the printed lines.
"""
from __future__ import annotations

import os
import re
import sys
from pathlib import Path


# ---------------------------------------------------------------------------
# Verb tiers (the candidate filter — unchanged from the original gate).
# ---------------------------------------------------------------------------
# Enforcement verbs read as imperative guards; predicate verbs read as
# boolean queries. The distinction matters because a throwing enforcement
# method is an enforcement point on its own, whereas a boolean predicate
# needs a stronger auth signal before we treat it as access control.
_ENFORCEMENT_VERBS = ("authorize", "requires", "require", "ensure", "assert", "validate")
_PREDICATE_VERBS = ("is", "check", "verify")

# public function <verb><PascalCaseRest>(
_METHOD_RE = re.compile(
    r"\bpublic\s+function\s+(?P<name>(?:"
    + "|".join(_ENFORCEMENT_VERBS + _PREDICATE_VERBS)
    + r")[A-Z][A-Za-z0-9_]*)\s*\(",
)


# ---------------------------------------------------------------------------
# Auth-domain signal patterns.
# ---------------------------------------------------------------------------
# S1 — a parameter that names/types an authorization subject.
_SUBJECT_PARAM_RE = re.compile(
    r"(?:\$)?(?:user(?:id|name)?|actor|principal|uid|bsn|role|group|member|"
    r"owner|subject|requeste?r)\b",
    re.IGNORECASE,
)
_SUBJECT_TYPE_RE = re.compile(r"\bI(?:User|Group)(?:Session|Manager)?\b")

# S2 — a permission-denial shape anywhere in the body.
_PERMISSION_DENIAL_RE = re.compile(
    r"\b\w*(?:Forbidden|Unauthorized|NotPermitted|NotAuthorized|"
    r"AccessDenied|PermissionDenied)\w*\b"
    r"|\bSTATUS_(?:FORBIDDEN|UNAUTHORIZED)\b",
)

# S3 — a call into an authorization / permission / role service.
_AUTHZ_SERVICE_RE = re.compile(
    r"->(?:permissions?|authoriz\w*|authz|rbac|acl|mandate|roles?|"
    r"groupManager|userSession|shareManager)\b"
    r"|\bisAdmin\s*\("
    r"|\bgetUserGroups\s*\("
    r"|->(?:can|may)[A-Z]\w*\s*\(",
)

# S4 — an authorization word inside the method name itself.
_AUTHZ_NAME_RE = re.compile(
    r"Auth|Permission|Access|Grant|Forbid|Rbac|Acl|Mandate|Owner|Admin|"
    r"Allowed|Role|Privilege|Consent",
)

# S5 — an explicit authorization marker in the method head.
_AUTHZ_MARKER_RE = re.compile(
    r"^\s*\*\s*@authoriz\w*\b|^\s*#\[Authoriz\w*",
    re.IGNORECASE | re.MULTILINE,
)

_THROW_RE = re.compile(r"\bthrow\b")


# ---------------------------------------------------------------------------
# String/comment stripper — preserves byte offsets so brace/paren counting
# on the cleaned copy lines up with the original text.
# ---------------------------------------------------------------------------
def _strip_strings_and_comments(src: str) -> str:
    out: list[str] = []
    i = 0
    n = len(src)
    while i < n:
        c = src[i]
        if c == "/" and i + 1 < n and src[i + 1] == "/":
            j = src.find("\n", i)
            if j == -1:
                j = n
            out.append(" " * (j - i))
            i = j
            continue
        if c == "/" and i + 1 < n and src[i + 1] == "*":
            j = src.find("*/", i + 2)
            j = n if j == -1 else j + 2
            out.append(" " * (j - i))
            i = j
            continue
        if c == "<" and src[i:i + 3] == "<<<":
            m = re.match(r"<<<['\"]?([A-Za-z_][A-Za-z0-9_]*)['\"]?\s*\n", src[i:])
            if m:
                label = m.group(1)
                end_pat = re.compile(rf"^\s*{re.escape(label)}\s*;?\s*$", re.MULTILINE)
                tail = src[i + m.end():]
                em = end_pat.search(tail)
                stop = i + m.end() + (em.end() if em else len(tail))
                out.append(" " * (stop - i))
                i = stop
                continue
        if c in ("'", '"'):
            quote = c
            j = i + 1
            while j < n:
                if src[j] == "\\" and j + 1 < n:
                    j += 2
                    continue
                if src[j] == quote:
                    j += 1
                    break
                j += 1
            out.append(" " * (j - i))
            i = j
            continue
        out.append(c)
        i += 1
    return "".join(out)


def _slice_balanced(cleaned: str, start: int, open_ch: str, close_ch: str) -> tuple[int, int]:
    """Return (open_index, close_index_exclusive) of the first balanced
    ``open_ch``..``close_ch`` pair at or after *start*. (-1, -1) on failure."""
    j = start
    n = len(cleaned)
    while j < n and cleaned[j] != open_ch:
        j += 1
    if j >= n:
        return (-1, -1)
    depth = 0
    k = j
    while k < n:
        ck = cleaned[k]
        if ck == open_ch:
            depth += 1
        elif ck == close_ch:
            depth -= 1
            if depth == 0:
                return (j, k + 1)
        k += 1
    return (-1, -1)


def _iter_methods(src: str):
    """Yield (name, line_no, head_text, param_text, body_text) for each
    public verb-prefixed method."""
    cleaned = _strip_strings_and_comments(src)
    starts = [(m.start(), m.group("name")) for m in _METHOD_RE.finditer(cleaned)]
    for idx, (start, name) in enumerate(starts):
        line_no = src.count("\n", 0, start) + 1
        p_open, p_close = _slice_balanced(cleaned, start, "(", ")")
        if p_open < 0:
            continue
        b_open, b_close = _slice_balanced(cleaned, p_close, "{", "}")
        if b_open < 0:
            continue
        prev_end = 0
        if idx > 0:
            pp_open, pp_close = _slice_balanced(cleaned, starts[idx - 1][0], "(", ")")
            if pp_close > 0:
                _, pb_close = _slice_balanced(cleaned, pp_close, "{", "}")
                if pb_close > 0:
                    prev_end = pb_close
        head = src[prev_end:start]
        params = src[p_open:p_close]
        body = src[b_open:b_close]
        yield (name, line_no, head, params, body)


def _is_access_control(name: str, head: str, params: str, body: str) -> bool:
    """True when a verb-prefixed method carries a genuine authorization
    signal (S1..S5), applying the enforcement/predicate verb tiers."""
    s1 = bool(_SUBJECT_PARAM_RE.search(params) or _SUBJECT_TYPE_RE.search(params))
    s2 = bool(_PERMISSION_DENIAL_RE.search(body))
    s3 = bool(_AUTHZ_SERVICE_RE.search(body))
    s4 = bool(_AUTHZ_NAME_RE.search(name))
    s5 = bool(_AUTHZ_MARKER_RE.search(head))
    any_signal = s1 or s2 or s3 or s4 or s5

    is_enforcement = any(
        name[: len(v)].lower() == v and (len(name) == len(v) or name[len(v)].isupper())
        for v in _ENFORCEMENT_VERBS
    )
    if is_enforcement:
        # A throwing enforcement method is an enforcement point in its own
        # right; otherwise it needs an explicit auth signal to distinguish
        # it from generic input validation (e.g. validateEmailFormat).
        return bool(_THROW_RE.search(body)) or any_signal
    # Predicate verb — require a real auth signal.
    return any_signal


# ---------------------------------------------------------------------------
# Caller corpus — repo-wide, from cwd. Mirrors the original gate's
# `grep -rnE -- "->method(" lib/ src/`; same-file callers are NOT excluded
# (a public helper called from another method in the same class is legit).
# ---------------------------------------------------------------------------
def _build_caller_corpus() -> str:
    chunks: list[str] = []
    roots = (
        (Path("lib"), (".php",)),
        (Path("src"), (".php", ".js", ".ts", ".vue")),
    )
    for root, exts in roots:
        if not root.is_dir():
            continue
        for path in root.rglob("*"):
            if not path.is_file() or path.suffix not in exts:
                continue
            try:
                chunks.append(path.read_text(encoding="utf-8", errors="ignore"))
            except OSError:
                continue
    return "\n".join(chunks)


# ---------------------------------------------------------------------------
# THE ROUTE TABLE IS A CALL SITE (.github#290)
# --------------------------------------------
# A ROUTED CONTROLLER ACTION HAS NO `->method(` ANYWHERE, EVER. Nextcloud's
# router invokes it by reflection from the `'name' => 'liveTile#validateSource'`
# entry in `appinfo/routes.php`, and the frontend calls the URL
# (`/apps/launchpad/api/livetile/validate-source`), not the PHP method name. So
# the `lib/` + `src/` corpus above — which is every caller this gate could see
# — cannot contain a reference to it by construction.
#
# The result was that any controller action whose name happens to start with a
# gate verb (`validate*`, `check*`, `is*`, `verify*`…) was reported
# `defined-but-never-called` while being routed, called by the frontend and
# unit-tested. Measured on launchpad: `LiveTileController::validateSource`,
# routed at appinfo/routes.php:557, called from src/services/liveTileClient.js,
# covered by tests/Unit/Controller/LiveTileControllerTest.php — reported as an
# orphan.
#
# That is worse than noise. This gate's whole framing is "an unwired guard is
# identical to no guard", so a false orphan is an accusation that a live
# security check is dead — and it makes the real findings harder to trust.
#
# The match is keyed on the EXACT declaration that makes the method reachable,
# so it is evidence, not a name heuristic: `<lowerCamelController>#<method>` in
# the route table, or a `#[Route]` / `#[ApiRoute]` attribute on the method
# itself (NC's attribute-routing form, which needs no routes.php entry).
# ---------------------------------------------------------------------------
_ROUTE_FILES = ("appinfo/routes.php",)
_ROUTE_DIRS = ("appinfo/routes",)

# `#[Route(...)]` / `#[ApiRoute(...)]` / `#[FrontpageRoute(...)]` — NC's
# attribute routing. Present on the method's head, so no route table is needed.
_ROUTE_ATTR_RE = re.compile(r"#\[\s*(?:\\?OCP\\AppFramework\\Http\\Attribute\\)?"
                            r"(?:Api|Frontpage)?Route\s*\(", re.IGNORECASE)


def _build_route_corpus() -> str:
    """Every route-table source in the app, concatenated."""
    chunks: list[str] = []
    for name in _ROUTE_FILES:
        p = Path(name)
        if p.is_file():
            try:
                chunks.append(p.read_text(encoding="utf-8", errors="ignore"))
            except OSError:
                pass
    for name in _ROUTE_DIRS:
        d = Path(name)
        if not d.is_dir():
            continue
        for p in sorted(d.rglob("*.php")):
            try:
                chunks.append(p.read_text(encoding="utf-8", errors="ignore"))
            except OSError:
                continue
    return "\n".join(chunks)


def _route_prefix(path: str) -> str | None:
    """`lib/Controller/LiveTileController.php` -> `liveTile`.

    Returns None for anything that is not a controller, so a SERVICE method is
    never cleared by the route table — a service is not routable, and letting
    one match here would be exactly the blanket this must not become.
    """
    m = re.search(r"(?:^|/)Controller/([A-Za-z0-9_]+)Controller\.php$",
                  path.replace("\\", "/"))
    if not m:
        return None
    cls = m.group(1)
    return cls[:1].lower() + cls[1:]


def _is_routed(path: str, method: str, head: str, route_corpus: str) -> bool:
    """True when the router — not a `->method(` call — reaches this method."""
    if _ROUTE_ATTR_RE.search(head):
        return True
    prefix = _route_prefix(path)
    if not prefix:
        return False
    # `'liveTile#validateSource'` / `"liveTile#validateSource"`, quote-agnostic
    # and whitespace-tolerant, but the controller AND the method must both
    # match — `#validateSource` alone would clear the method on any controller.
    pat = re.compile(r"['\"]" + re.escape(prefix) + r"\s*#\s*" + re.escape(method) + r"['\"]")
    return bool(pat.search(route_corpus))


def scan_files(files: list[str]) -> list[str]:
    """Return finding lines for the given candidate files."""
    corpus = _build_caller_corpus()
    route_corpus = _build_route_corpus()
    findings: list[str] = []
    for f in files:
        try:
            src = Path(f).read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        seen: set[str] = set()
        for name, line_no, head, params, body in _iter_methods(src):
            if not _is_access_control(name, head, params, body):
                continue
            if name in seen:
                continue
            seen.add(name)
            caller_re = re.compile(r"->" + re.escape(name) + r"\s*\(")
            if caller_re.search(corpus):
                continue
            # The router is a caller the `lib/`+`src/` corpus cannot contain.
            if _is_routed(f, name, head, route_corpus):
                continue
            findings.append(f"{f}:{line_no} method={name} rule=defined-but-never-called")
    return findings


def main(argv: list[str]) -> int:
    findings = scan_files(argv[1:])
    for line in findings:
        print(line)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
