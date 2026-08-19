#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Gate-9 semantic-auth — brace-aware PHP method body scanner.

Closes W28 from the 2026-04-24 warnings list. The previous gate
implementation in ``scripts/run-hydra-gates.sh`` used a flat
``[^}]*`` regex between ``if (...isAdmin) {`` and the
``throw STATUS_FORBIDDEN`` / ``OCSForbiddenException`` match. Any
nested ``}`` inside the if-body — closure, array literal,
match-expression, anonymous class — terminates ``[^}]*`` before the
real throw, producing a false negative.

This module:

* Reads the PHP file as text.
* Walks each ``public function …(…) :…`` declaration, slicing the
  method body using a proper brace counter that respects strings and
  comments.
* Inspects the head (everything between the previous method's close
  and this method's open) for ``#[NoAdminRequired]`` / ``@NoAdminRequired``
  vs ``#[PublicPage]`` / ``@PublicPage``.
* Inspects the body for the contradictory shapes:

    - ``$this->requireAdmin()`` or bare ``requireAdmin()``
    - ``if (... !isAdmin ...) { ... STATUS_FORBIDDEN | OCSForbidden | 403 ...}``
    - ``if (... isAdmin === false ...) { ... STATUS_FORBIDDEN | OCSForbidden | 403 ...}``
    - PublicPage + ``Http::STATUS_UNAUTHORIZED|FORBIDDEN`` in a body that
      does not itself resolve a credential from the request. See
      ``_SELF_AUTH_RE``; the exemption is matched against the
      comment-stripped source, so only code can earn it.

Prints one line per violation in the same format as the bash gate so
``run-hydra-gates.sh`` can consume it unchanged.

Usage::

    python3 scripts/lib/check_semantic_auth.py <php-file> [<php-file> ...]

Exits 0 always; the bash gate counts the printed lines.
"""
from __future__ import annotations

import re
import sys


_METHOD_RE = re.compile(
    r"\bpublic\s+function\s+(?P<name>[A-Za-z_][A-Za-z0-9_]*)\s*\(",
)


def _strip_comments(src: str) -> str:
    """Replace comments with same-length whitespace, KEEPING string literals.

    Used to decide whether a method authenticates its own credential. That
    question is about executable code, and a docblock is not executable: a
    comment reading "callers must present a bearer token" would otherwise
    buy the self-auth exemption for a method containing no such check — the
    2026-08-06 gate-64 failure mode, where a commented-out call counted as
    a real one.

    String literals are deliberately preserved, unlike in
    :func:`_strip_strings_and_comments`. Several of the idioms in
    ``_SELF_AUTH_RE`` live inside literals by nature — the ``'Bearer '``
    prefix a handler strips, the ``'HTTP_AUTHORIZATION'`` key, the header
    name passed to ``getHeader()``. Blanking those would turn correct code
    into findings, which is the failure this gate already exists to avoid.

    Offsets are preserved so the result can be searched interchangeably with
    the raw slice.
    """
    out = []
    i = 0
    n = len(src)
    while i < n:
        c = src[i]
        # Single-line comment // ... \n
        if c == "/" and i + 1 < n and src[i + 1] == "/":
            j = src.find("\n", i)
            if j == -1:
                j = n
            out.append(" " * (j - i))
            i = j
            continue
        # Block comment / docblock /* ... */
        if c == "/" and i + 1 < n and src[i + 1] == "*":
            j = src.find("*/", i + 2)
            if j == -1:
                j = n
            else:
                j += 2
            out.append(" " * (j - i))
            i = j
            continue
        # A quoted string is copied through verbatim, but must still be
        # consumed here so that a `//` or `/*` inside it (a URL, a regex)
        # is not mistaken for the start of a comment.
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
            out.append(src[i:j])
            i = j
            continue
        out.append(c)
        i += 1
    return "".join(out)


def _strip_strings_and_comments(src: str) -> str:
    """Replace string literals + comments with same-length whitespace.

    Preserves byte offsets so brace positions in the cleaned string match
    the original.
    """
    out = []
    i = 0
    n = len(src)
    while i < n:
        c = src[i]
        # Single-line comment // ... \n
        if c == "/" and i + 1 < n and src[i + 1] == "/":
            j = src.find("\n", i)
            if j == -1:
                j = n
            out.append(" " * (j - i))
            i = j
            continue
        # Block comment / docblock /* ... */
        if c == "/" and i + 1 < n and src[i + 1] == "*":
            j = src.find("*/", i + 2)
            if j == -1:
                j = n
            else:
                j += 2
            out.append(" " * (j - i))
            i = j
            continue
        # Heredoc / nowdoc — bash one-liner false positives are unlikely;
        # treat them as strings starting after `<<<` or `<<<'`.
        if c == "<" and src[i:i + 3] == "<<<":
            # Find the label, then the matching closing label on a new line.
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
        # Single / double quoted string.
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


def _find_method_bodies(src: str):
    """Yield (name, head_start, body_start, body_end_exclusive, line_no)."""
    cleaned = _strip_strings_and_comments(src)
    method_starts: list[tuple[int, str, int]] = []
    for m in _METHOD_RE.finditer(cleaned):
        method_starts.append((m.start(), m.group("name"), src.count("\n", 0, m.start()) + 1))

    for idx, (start, name, line_no) in enumerate(method_starts):
        # Find the opening { after the parameter list.
        paren_depth = 0
        j = start
        while j < len(cleaned):
            c = cleaned[j]
            if c == "(":
                paren_depth += 1
            elif c == ")":
                paren_depth -= 1
            elif c == "{" and paren_depth == 0:
                break
            j += 1
        if j >= len(cleaned) or cleaned[j] != "{":
            continue
        body_start = j
        depth = 0
        k = j
        while k < len(cleaned):
            ck = cleaned[k]
            if ck == "{":
                depth += 1
            elif ck == "}":
                depth -= 1
                if depth == 0:
                    break
            k += 1
        if depth != 0:
            continue
        body_end = k + 1
        prev_end = method_starts[idx - 1][0] if idx > 0 else 0
        # Walk back from prev_end to find the previous method's `}` so the
        # head-slice excludes the previous method's annotations.
        head_start = prev_end
        if idx > 0:
            # Re-derive previous body end using the same logic — cheap, methods are few.
            pj = method_starts[idx - 1][0]
            pdepth = 0
            paren_d = 0
            while pj < len(cleaned):
                cj = cleaned[pj]
                if cj == "(":
                    paren_d += 1
                elif cj == ")":
                    paren_d -= 1
                elif cj == "{" and paren_d == 0:
                    break
                pj += 1
            pbody_start = pj
            pdepth = 0
            pk = pj
            while pk < len(cleaned):
                ck = cleaned[pk]
                if ck == "{":
                    pdepth += 1
                elif ck == "}":
                    pdepth -= 1
                    if pdepth == 0:
                        break
                pk += 1
            head_start = pk + 1
        yield (name, head_start, body_start, body_end, line_no)


# Anchored to PHPDoc-tag position (`* @Annotation`) or PHP-attribute position
# (`#[Annotation`) at the start of a line. Earlier flat-substring forms fired
# on prose like `(no @NoAdminRequired)` inside docblock explanations —
# observed 8/10 false positives on openregister#1419 commit 6b24be2
# (2026-05-07). Ported from the bash anchoring fix in commit 16a1bf0.
_NO_ADMIN_HEAD_RE = re.compile(
    r"^\s*\*\s*@NoAdminRequired\b|^\s*#\[NoAdminRequired\b",
    re.MULTILINE,
)
_PUBLIC_PAGE_HEAD_RE = re.compile(
    r"^\s*\*\s*@PublicPage\b|^\s*#\[PublicPage\b",
    re.MULTILINE,
)

_ADMIN_GATE_BODY_RE = re.compile(
    r"\$this->requireAdmin\s*\(|\brequireAdmin\s*\(\s*\)",
)
_FORBIDDEN_TOKEN_RE = re.compile(
    r"STATUS_FORBIDDEN|OCSForbiddenException|\b403\b",
)
# A #[PublicPage] method that depends on the SESSION is the real mismatch:
# NC middleware lets the request through without one, so the body's session
# test can only ever fail. This is the decidesk defect the rule was built for
# (`SettingsController::load()` annotated #[NoAdminRequired] while its body
# called `requireAdmin()`).
_PUBLIC_SESSION_AUTH_RE = re.compile(
    r"\brequireAdmin\s*\(|"
    r"userSession\s*->\s*getUser\s*\(\s*\)\s*===\s*null",
)

# Returning 401/403 is NOT, on its own, evidence of a session dependency.
# It is what a correctly self-authenticating public endpoint does when the
# credential in the REQUEST is missing, invalid or revoked.
_PUBLIC_DENY_STATUS_RE = re.compile(r"Http::STATUS_(UNAUTHORIZED|FORBIDDEN)")

# "The credential is in the request, not the session."
#
# This is Nextcloud's own idiom for public share links, and the fleet's for
# webhooks and portal endpoints: #[PublicPage] BECAUSE the caller is a remote
# server or an unauthenticated portal visitor with no local session, and the
# body authenticates the caller itself from a route token, a bearer header or
# an HMAC signature. 36 of gate-9's 39 fleet findings were this shape, every
# one of them correct code:
#
#   openconnector PaymentsController::webhook  — HMAC verify, constant-time
#                                                compare, timestamp tolerance
#   portaliq ContributionController::inbox +9  — portal subject() resolution
#   openregister FederationController ×6       — bearer share token in the URL
#   doriath, hermiq, launchpad, procest, …     — same
#
# There was NO correct action a developer could take on those findings. The
# advice said "remove #[PublicPage] or remove the body auth check": the first
# breaks the endpoint (middleware rejects the caller before the controller
# runs), the second removes its only authentication. A gate that can be
# closed only by weakening the code is worse than no gate.
#
# A username/password pair is the same idiom with a different credential
# shape, and the token-only list above missed it: a login endpoint is
# #[PublicPage] by necessity (the caller has no session yet — that is what
# it is asking for) and answers 401 when the password is wrong. Observed on
# openconnector UserController::login (2026-08-07), which resolves
# `$username`/`$password` from the request and calls
# `$this->userManager->checkPassword(...)` — the credential IS named, in the
# body, and the gate still reported it unsourced. Same class of finding as
# the 36 above, so it is listed here rather than argued with in the app.
_SELF_AUTH_RE = re.compile(
    r"hash_hmac\s*\(|"
    r"hash_equals\s*\(|"
    r"\bBearer\b|"
    r"->\s*getHeader\s*\(|"
    r"\$_SERVER\s*\[\s*['\"]HTTP_|"
    r"\bsignature\b|"
    r"\bhmac\b|"
    r"[Tt]oken\s*\)|"
    r"\$\w*[Tt]oken\b|"
    # Any call whose NAME carries the credential, not just the six verbs
    # that used to be listed. hermiq EgressAuthorizeController::authorize
    # (2026-08-07) resolves its credential with `$this->bearerToken()` and
    # was matching only on the word "bearer" in its own docblock — so it
    # went from exempt to flagged the moment comments stopped counting,
    # despite being textbook-correct code.
    r"->\s*\w*[Tt]oken\w*\s*\(|"
    # PHP named argument: `verify(token: ...)`, `assert(apiKey: ...)`.
    r"\b(token|apiKey|credential|password|signature)\s*:\s*|"
    r"->\s*subject\s*\(|"
    r"[Cc]apability\s*[Tt]oken|"
    # Username/password credentials presented in the request.
    r"password_verify\s*\(|"
    r"->\s*checkPassword\s*\(|"
    r"\$\w*[Pp]assword\b|"
    r"\$\w*[Cc]redentials?\b|"
    r"->\s*(resolve|validate|verify|check)\w*[Cc]redentials?\s*\(|"
    # THE SESSION IS A CREDENTIAL IN THE REQUEST (ConductionNL/.github#221).
    #
    # `#[PublicPage]` in Nextcloud means "a login is NOT REQUIRED". It does
    # not mean "there is no session" — a logged-in user hitting a PublicPage
    # route arrives with their session cookie intact. So the progressive
    # shape
    #
    #     $user = $this->session->getUser();
    #     if ($user === null) { ...policy... return 401; }   // anonymous
    #     ...                                                 // authenticated
    #
    # resolves a real credential from the request and denies on a stated
    # policy. doriath ApplicationController::create is exactly this: admin
    # auto-approves, an authenticated non-admin gets a pending row, and an
    # anonymous caller is admitted ONLY when the app-config opt-in
    # `anonymous_application_registration_enabled` is set — otherwise 401.
    # The gate called that "denying on something the annotation guarantees is
    # absent". The annotation guarantees no such thing.
    #
    # ⚠️ This does NOT soften rule 1 (`_PUBLIC_SESSION_AUTH_RE`). That rule is
    # tested FIRST and this branch is its `elif`, so `requireAdmin()` under
    # #[PublicPage] — the decidesk defect the gate was built for — still
    # fires. What survives here is the shape with no credential of any kind:
    # a #[PublicPage] method that returns 401/403 off a config read, a
    # feature flag or nothing at all.
    r"->\s*getUser\s*\(\s*\)|"
    r"->\s*getUID\s*\(|"
    r"->\s*getUserId\s*\(|"
    r"\bIUserSession\b",
    re.IGNORECASE,
)


_ANY_METHOD_RE = re.compile(
    r"\bfunction\s+(?P<name>[A-Za-z_][A-Za-z0-9_]*)\s*\(",
)

# `$this->helper(`, `self::helper(`, `static::helper(` — a call to a sibling
# method of the same class.
_SIBLING_CALL_RE = re.compile(
    r"(?:\$this\s*->|self\s*::|static\s*::)\s*(?P<name>[A-Za-z_][A-Za-z0-9_]*)\s*\(",
)


def _all_method_bodies(src: str) -> dict[str, str]:
    """Every method in the file, at any visibility, as {name: body}.

    ``_find_method_bodies`` deliberately only walks ``public function`` —
    those are the routed entry points the rules judge. This one exists for a
    different question: WHERE the credential is resolved, which is not
    required to be in the entry point itself.
    """
    cleaned = _strip_strings_and_comments(src)
    out: dict[str, str] = {}
    for m in _ANY_METHOD_RE.finditer(cleaned):
        start = m.start()
        paren = 0
        j = start
        while j < len(cleaned):
            c = cleaned[j]
            if c == "(":
                paren += 1
            elif c == ")":
                paren -= 1
            elif c == "{" and paren == 0:
                break
            elif c == ";" and paren == 0:
                # Abstract / interface declaration — no body.
                j = len(cleaned)
                break
            j += 1
        if j >= len(cleaned) or cleaned[j] != "{":
            continue
        depth = 0
        k = j
        while k < len(cleaned):
            ck = cleaned[k]
            if ck == "{":
                depth += 1
            elif ck == "}":
                depth -= 1
                if depth == 0:
                    break
            k += 1
        if depth != 0:
            continue
        # Slice from the ORIGINAL source: the exemption is tested against real
        # text (with strings intact), exactly as the caller's `head + body` is.
        out.setdefault(m.group("name"), src[j:k + 1])
    return out


def _credential_surface(src: str, head: str, body: str, all_bodies: dict[str, str]) -> str:
    """The text in which "does this endpoint name its credential?" is decided.

    THE CREDENTIAL IS OFTEN ONE FRAME DOWN (ConductionNL/.github#221)
    ----------------------------------------------------------------
    A controller that authenticates several endpoints the same way does not
    repeat the resolution in each — it writes it once, in a private helper,
    and every action calls that:

        #[PublicPage]
        public function show(): JSONResponse {
            $userId = $this->resolveUserId();
            if ($userId === null) { return ...STATUS_UNAUTHORIZED; }
            ...
        }

        private function resolveUserId(): ?string {
            return $this->session->getUser()?->getUID();
        }

    Reading only the entry point, the gate sees a 401 and no credential, and
    reports "unsourced denial". The credential is right there, one call away,
    in the same file. Extracting a helper is the refactor every other quality
    gate in this suite asks for; it must not manufacture a security finding.

    ONE FRAME, and only sibling methods of the same file. Not transitive:
    following the call graph arbitrarily deep would eventually reach a service
    that touches a token for unrelated reasons and exempt everything. Depth 1
    is the shape actually observed, and it keeps the "no credential ANYWHERE
    near this endpoint" positive control intact.
    """
    surface = [head, body]
    for call in _SIBLING_CALL_RE.finditer(_strip_strings_and_comments(body)):
        helper = all_bodies.get(call.group("name"))
        if helper is not None:
            surface.append(helper)
    return "\n".join(surface)


# Keywords whose `{ ... }` block does NOT necessarily execute when the
# enclosing block is entered: branches, loops, catch handlers and closure
# bodies. `try` is absent on purpose — a try body runs unconditionally.
_CONDITIONAL_KWS = {
    "if", "elseif", "else", "switch", "match",
    "for", "foreach", "while", "do", "catch", "function", "fn",
}


def _unconditional_part(block: str) -> str:
    """The part of *block* that runs whenever *block* is entered.

    Nested blocks introduced by a branch / loop / catch / closure are blanked;
    everything else (including a `try` body) is kept and descended into.

    WHY THIS EXISTS — gate-9's dominant false positive (2026-08-08).
    `_has_admin_if_with_throw` searched the `!isAdmin` block for a denial token
    AT ANY DEPTH. That conflates two opposite postures:

        if ($isAdmin === false) {          // ADMIN REQUIRED — the deny is the
            return $this->forbidden();     // unconditional consequence of not
        }                                  // being an admin. True positive.

        if ($isAdmin === false) {          // ADMIN NOT REQUIRED — non-admins
            $r = $this->svc->get($id);     // simply take the tighter per-owner
            if ($r['initiatorUserId']      // path. A non-admin OWNER proceeds.
                !== $uid) {                // `@NoAdminRequired` is CORRECT.
                return $this->forbidden(); // False positive.
            }
        }

    Live on docudesk `SigningController::cancelRequest` (docudesk#100's own WF1
    fix). The remedy gate-9 printed for it — "remove @NoAdminRequired ... or use
    #[AuthorizedAdminSetting]" — would have made a per-user cancel endpoint
    admin-only and deleted the owner check's reason to exist. That is the
    failure mode where a security gate's advice is itself the regression.
    """
    out: list[str] = []
    i, n = 0, len(block)
    while i < n:
        if block[i] != "{":
            out.append(block[i])
            i += 1
            continue
        depth, j = 0, i
        while j < n:
            if block[j] == "{":
                depth += 1
            elif block[j] == "}":
                depth -= 1
                if depth == 0:
                    break
            j += 1
        if j >= n:
            out.append(block[i:])
            break
        head = block[:i]
        k = len(head) - 1
        while k >= 0 and head[k].isspace():
            k -= 1
        if k >= 0 and head[k] == ")":  # step back over a balanced (...)
            d = 0
            while k >= 0:
                if head[k] == ")":
                    d += 1
                elif head[k] == "(":
                    d -= 1
                    if d == 0:
                        break
                k -= 1
            k -= 1
        while k >= 0 and head[k].isspace():
            k -= 1
        kw_m = re.search(r"(\w+)\s*$", head[: k + 1]) if k >= 0 else None
        keyword = kw_m.group(1) if kw_m else ""
        if keyword in _CONDITIONAL_KWS:
            out.append(" " * (j - i + 1))
        else:
            out.append("{")
            out.append(_unconditional_part(block[i + 1 : j]))
            out.append("}")
        i = j + 1
    return "".join(out)


def _has_admin_if_with_throw(body: str) -> bool:
    """True if body contains `if (...!isAdmin...) { throw/return STATUS_FORBIDDEN ... }`.

    Brace-aware: walks every `if (` whose condition tests `isAdmin` (negated
    or `=== false`), then checks the body of the if for a forbidden token — but
    only where that denial is UNCONDITIONAL within the block (see
    `_unconditional_part` for the escalation-branch false positive this closes).
    """
    for if_match in re.finditer(r"\bif\s*\(", body):
        if_start = if_match.start()
        # Find matching close paren.
        depth = 1
        i = if_match.end()
        while i < len(body) and depth > 0:
            if body[i] == "(":
                depth += 1
            elif body[i] == ")":
                depth -= 1
            i += 1
        if depth != 0:
            continue
        cond = body[if_match.end():i - 1]
        # Negated isAdmin or isAdmin === false. Character class must
        # include `$` (`$this->`), `>` (`->`), and word chars to span
        # `$this->isAdmin` / `$user->getUID()->isAdmin` etc.
        # `cond` is already bounded by the matching close paren of the `if`,
        # so `.*?` cannot run past the condition. It must not be `[^)]*`:
        # the call being tested usually HAS arguments, and
        # `isAdmin($this->userId) === false` puts a `)` between the name and
        # the comparison — the same shape of over-restrictive character class
        # that made the old `[^}]*` body regex miss real throws (W28).
        if not (
            re.search(r"!\s*[\w\$\->]*isAdmin\b", cond) or
            re.search(r"\bisAdmin\b.*?===\s*false", cond, re.DOTALL) or
            re.search(r"false\s*===.*?\bisAdmin\b", cond, re.DOTALL)
        ):
            continue
        # Body of the if.
        while i < len(body) and body[i] != "{":
            i += 1
        if i >= len(body):
            continue
        body_start = i
        depth = 0
        while i < len(body):
            if body[i] == "{":
                depth += 1
            elif body[i] == "}":
                depth -= 1
                if depth == 0:
                    i += 1
                    break
            i += 1
        # Only the UNCONDITIONAL part counts: a denial nested under a further
        # condition makes this an escalation branch, not an admin requirement.
        if_body = _unconditional_part(body[body_start:i])
        if "throw" in if_body or "return" in if_body:
            if _FORBIDDEN_TOKEN_RE.search(if_body):
                return True
    return False


def scan_file(path: str) -> int:
    try:
        with open(path, encoding="utf-8") as f:
            src = f.read()
    except OSError:
        return 0
    violations = 0
    # Computed once per file, not per method: the unsourced-denial rule needs
    # the bodies of the private helpers a public action delegates to.
    all_bodies = _all_method_bodies(src)
    for name, head_start, body_start, body_end, line_no in _find_method_bodies(src):
        head = src[head_start:body_start]
        body = src[body_start:body_end]
        if _NO_ADMIN_HEAD_RE.search(head):
            if _ADMIN_GATE_BODY_RE.search(body) or _has_admin_if_with_throw(body):
                print(
                    f"{path}:{line_no} method={name} "
                    f"rule=no-admin-required-annotation-with-admin-body — "
                    f"remove @NoAdminRequired (if REST endpoint) or use "
                    f"#[AuthorizedAdminSetting(Application::APP_ID)] (if settings panel)"
                )
                violations += 1
        if _PUBLIC_PAGE_HEAD_RE.search(head):
            # Session-derived check under #[PublicPage] — the real mismatch.
            if _PUBLIC_SESSION_AUTH_RE.search(body):
                print(
                    f"{path}:{line_no} method={name} "
                    f"rule=public-page-annotation-with-session-auth-body — "
                    f"this method is #[PublicPage], so Nextcloud middleware admits the "
                    f"request WITHOUT a session, yet the body tests the session "
                    f"(requireAdmin()/IUserSession). That test can only ever fail for the "
                    f"callers the annotation admits. Decide which is true: if the endpoint "
                    f"needs a logged-in user, drop #[PublicPage] and keep the check; if it "
                    f"is genuinely public, authenticate the caller from the REQUEST "
                    f"(route token, bearer header, HMAC signature) instead of the session. "
                    f"Do NOT simply delete the check."
                )
                violations += 1
            # Comments stripped before asking "does this authenticate its own
            # credential?" — the exemption has to be earned by code, not by a
            # docblock describing a check the body does not perform.
            elif (
                _PUBLIC_DENY_STATUS_RE.search(body)
                and not _SELF_AUTH_RE.search(
                    _strip_comments(_credential_surface(src, head, body, all_bodies))
                )
            ):
                print(
                    f"{path}:{line_no} method={name} "
                    f"rule=public-page-annotation-with-unsourced-denial — "
                    f"this method is #[PublicPage] and returns 401/403, but nothing in it "
                    f"resolves a credential from the request (no route token, bearer header, "
                    f"HMAC signature or portal subject). Either it is denying on something "
                    f"the annotation guarantees is absent, or the credential it checks is "
                    f"not visible here. Name the credential the endpoint authenticates "
                    f"with. Do NOT remove #[PublicPage] (middleware would reject the "
                    f"caller before the controller runs) and do NOT remove the denial."
                )
                violations += 1
    return violations


def main(argv: list[str]) -> int:
    total = 0
    for path in argv[1:]:
        total += scan_file(path)
    return 0  # exit 0 always — caller counts printed lines


if __name__ == "__main__":
    sys.exit(main(sys.argv))
