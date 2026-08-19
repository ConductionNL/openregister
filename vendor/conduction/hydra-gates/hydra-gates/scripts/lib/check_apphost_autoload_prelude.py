#!/usr/bin/env python3
"""Detect AppHost adoption without the OpenRegister autoload prelude (ADR-040).

THE DEFECT
----------
`OC_App::getEnabledApps()` does `sort($apps)` (lib/private/legacy/OC_App.php),
and `Coordinator::registerApps()` walks THAT sorted list calling
`OC_App::registerAutoloading($appId, $path)` and then `$application->register()`
for ONE APP AT A TIME.

So every app's `register()` runs BEFORE the PSR-4 prefix of every
alphabetically-LATER app exists. Any leaf whose app id sorts before
`openregister` reaches `register()` while `OCA\\OpenRegister\\` is not
autoloadable — on a completely healthy instance, with OpenRegister enabled.

It has two shapes, and both are quiet:

  guarded     `class_exists('OCA\\OpenRegister\\AppHost\\...')` answers FALSE,
              so the generic AppHost plumbing is skipped. Classes that exist
              ONLY as Bootstrap DI aliases (Controller\\HealthController aliasing
              AppHost\\Controller\\GenericHealthController, ...) then fail to
              resolve and their endpoints return 500 — not 404.

  unguarded   the resulting \\Error aborts the ENTIRE register(). Every
              registerEventListener below it never runs. Coordinator catches the
              Throwable, logs an `emergency` and `continue`s, so the app stays
              enabled and keeps serving: nothing in the UI says half its wiring
              is missing.

WHY IT NEEDS A GATE
-------------------
This bug class is invisible to ordinary testing because it depends on WHICH
APPS HAPPEN TO BE INSTALLED. Any app that pulls OpenRegister's autoloader in
registers the prefix PROCESS-WIDE, masking the defect for every app that
registers after it. On a dev instance with such an app present, everything
resolves; in CI with a minimal app set, it does not. A masking app makes the
failure vanish exactly where you would test for it.

Measured twice, independently:
  * doriath   — the audit listener recorded ZERO dispatched events, because the
                unguarded Bootstrap reference aborted register() before it.
  * openconnector — its own source records `class_exists at register(): false`
                on a clean install; removing the guard took the flow-node
                registry from 10 nodes to 12.

THE REQUIRED PRELUDE
--------------------
    try {
        $orPath = \\OCP\\Server::get(\\OCP\\App\\IAppManager::class)->getAppPath('openregister');
        \\OC_App::registerAutoloading('openregister', $orPath);
    } catch (\\Throwable) {
        // OpenRegister absent/disabled — fall through to the degraded path.
    }

`registerAutoloading()` touches ONLY the autoloader and is idempotent (it
early-returns on an `$alreadyRegistered` key). `IAppManager::loadApp()` is NOT
an acceptable substitute: it sets `loadedApps[..]=true` and calls
`Coordinator::bootApp()`, booting OpenRegister before its own register() has
run — so this gate rejects it explicitly rather than accepting it as a prelude.

WHAT FAILS
----------
Anything under lib/AppInfo/ that, without the prelude:
  1. references `OCA\\OpenRegister\\AppHost\\Bootstrap` (import, FQCN or quoted
     string), or
  2. calls `class_exists()` on a literal `OCA\\OpenRegister\\AppHost\\...` name.

Both are resolved DURING register(). Lazy service closures that merely mention
an AppHost class are NOT flagged: their bodies run at resolution time, long
after every app has registered.

OpenRegister itself is exempt — it owns AppHost.

Suppress a deliberate case with a reason-bearing comment:
    apphost-prelude exclude <reason>
A bare annotation with no reason fails, same as gate 16's `@spec exclude`.

Exit 0 when clean, 1 when an app adopts AppHost without the prelude.

SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
"""

from __future__ import annotations

import os
import re
import sys

# The prelude: registerAutoloading(...) whose arguments name 'openregister'.
# Tolerant of named arguments (app: 'openregister') and of `OC_App::` /
# `\OC_App::` / an imported alias, because all three appear in this fleet.
PRELUDE = re.compile(r"registerAutoloading\s*\(([^)]*)\)", re.S)
OPENREGISTER_LITERAL = re.compile(r"""['"]openregister['"]""")

# A composition root may pass the app id as a CLASS CONSTANT rather than a
# quoted literal — `const OPENREGISTER_APP_ID = 'openregister';` then
# `registerAutoloading(self::OPENREGISTER_APP_ID, $path)`. That is the same
# prelude and must not be read as its absence.
#
# MEASURED, not hypothetical: doriath ships exactly this shape
# (lib/AppInfo/OpenRegisterAutoloader.php) and called it correctly, BEFORE the
# Bootstrap reference in Application::register(). Matching only the literal
# reported a compliant composition root as an ADR-040 violation — a gate
# failing an app for the way it spells a constant.
APPID_CONST = re.compile(
    r"""const\s+([A-Za-z_][A-Za-z0-9_]*)\s*=\s*['"]openregister['"]"""
)

# loadApp('openregister') is a WRONG fix, not a prelude — it boots OpenRegister
# before its own register() has run. Named so the reader is told why.
LOAD_APP = re.compile(r"""loadApp\s*\(\s*['"]openregister['"]\s*\)""")

# Rule 1 — any reference to the AppHost Bootstrap entry point. Covers
# `use OCA\OpenRegister\AppHost\Bootstrap;`, `\OCA\OpenRegister\AppHost\Bootstrap::`
# and the escaped string form 'OCA\\OpenRegister\\AppHost\\Bootstrap'.
BOOTSTRAP_REF = re.compile(r"OpenRegister\\{1,2}AppHost\\{1,2}Bootstrap")

# Rule 2 — class_exists() on an AppHost name. This is the probe that answers
# FALSE on a healthy instance.
#
# ⚠️ IT ONLY SAW A QUOTED STRING (.github#276). PHP has three ways to name a
# class and this pattern recognised one of them:
#
#     class_exists('OCA\OpenRegister\AppHost\Controller\GenericHealthController')
#     class_exists(\OCA\OpenRegister\AppHost\Controller\GenericHealthController::class)
#     use OCA\OpenRegister\AppHost\Controller\GenericHealthController;
#       … class_exists(GenericHealthController::class)
#     private const AH = 'OCA\OpenRegister\AppHost\…';  class_exists(self::AH)
#
# Only the first failed. The other three were injected into larpingapp's
# register() and the gate reported OK for all of them — an ADR-040 adoption
# that is invisible because of the way its author spells a class name. This is
# #184's lesson in the same file it was learned in: a checker that greps a
# STRING LITERAL misses every constant.
#
# `Foo::class` itself does NOT autoload — it is resolved at compile time. It is
# `class_exists()` that triggers the autoloader, which is why the CALL is what
# is matched, in every spelling of its argument, and why a bare
# `use OCA\OpenRegister\AppHost\…;` on its own is deliberately NOT a finding.
CLASS_EXISTS_ARG = re.compile(r"class_exists\s*\(\s*([^,)]+)", re.S)
_APPHOST_FQCN = re.compile(r"OpenRegister\\{1,2}AppHost\\{1,2}")
QUOTED = re.compile(r"""^(['"])\\{0,2}([^'"]*)\1""")
# `use A\B\C;` / `use A\B\C as Alias;` — how a short name is bound in this file.
USE_IMPORT = re.compile(
    r"use\s+\\?([A-Za-z_][A-Za-z0-9_\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;"
)
# `const AH = 'OCA\OpenRegister\AppHost\…';` — a constant holding an FQCN.
CONST_STR = re.compile(
    r"""const\s+([A-Za-z_][A-Za-z0-9_]*)\s*=\s*['"]\\{0,2}([A-Za-z_][A-Za-z0-9_\\]*)['"]"""
)

# A class_exists() probe on ANY OCA\OpenRegister class, not just AppHost. The
# autoloader mechanism is identical — during register() the OCA\OpenRegister\
# PSR-4 prefix does not exist for any app whose id sorts earlier — so the guard
# answers FALSE and everything inside it silently never happens.
#
# REPORTED AS A NON-BLOCKING NOTE, NOT A FAILURE, and the reason is measured:
# gate-64 is not diff-scoped, so promoting this to a FAIL turns every PR in
# six repos permanently red for pre-existing code the PR did not touch. That
# is the trap this package already documented in check_store_and_settings_
# surface.py ("blocked EVERY manifest-touching PR in that repo, permanently").
#
# Measured 2026-08-08 across apps-extra, lib/AppInfo/, comment-stripped, no
# prelude present:
#     larpingapp  3   'OCA\OpenRegister\Event\{DeepLinkRegistration,
#                      ObjectCreating,ObjectUpdating}Event'  — LIVE-EXPOSED,
#                      and ObjectCreating/ObjectUpdating carry larpingapp's
#                      server-authoritative skill-requirement / XP-budget
#                      enforcement on character writes.
#     hermiq      2   flow-node + shareable-config registration
#     nldesign    1   shareable-config registration
# openconnector independently recorded `class_exists at register(): false` for
# the same mechanism, and removing its guard took the flow-node registry from
# 10 nodes to 12.
#
# The NOTE exists so a green gate-64 stops being read as evidence about this.
_OPENREGISTER_FQCN = re.compile(r"(?:^|\\)OCA\\{1,2}OpenRegister\\{1,2}")
REGISTER_FN = re.compile(r"function\s+register\s*\(")

# OpenRegister owns AppHost; it cannot need a prelude for its own namespace.
OWN_NAMESPACE = re.compile(r"namespace\s+OCA\\OpenRegister\b")

# The reason must be on the SAME LINE as the annotation. `\s` would match a
# newline, which let a BARE `apphost-prelude exclude` swallow the next line of
# source as its "reason" — a suppression that explains nothing, accepted.
SUPPRESS = re.compile(r"apphost-prelude[ \t]+exclude[ \t]*(?P<reason>[^\n]*)")


def _sources(app_dir: str) -> dict[str, str]:
    """Every PHP file under lib/AppInfo/, path -> text."""
    out: dict[str, str] = {}
    root_dir = os.path.join(app_dir, "lib", "AppInfo")
    for root, _dirs, files in os.walk(root_dir):
        for name in sorted(files):
            if not name.endswith(".php"):
                continue
            path = os.path.join(root, name)
            try:
                with open(path, encoding="utf-8", errors="replace") as handle:
                    out[path] = handle.read()
            except OSError:
                continue
    return out


def strip_comments(text: str) -> str:
    """PHP source with comments removed, string literals preserved.

    EVERY pattern below is evidence about CODE. Scanning raw source made
    comments count as source, in both directions:

      * `// Deliberately NOT IAppManager::loadApp('openregister')` — a comment
        explaining why the WRONG fix was avoided — was reported as that wrong
        fix. Measured on doriath, where both `loadApp` occurrences under
        lib/AppInfo/ are prose saying the code does not do it.
      * a commented-OUT `registerAutoloading('openregister', …)` would have
        counted as a prelude that no longer runs — a false GREEN, the more
        dangerous direction.

    Newlines are preserved so any line-based reporting stays aligned.

    `#[` opens a PHP 8 attribute, not a comment; treating it as one would
    swallow the rest of the line, including `#[NoAdminRequired]`.
    """
    out: list[str] = []
    i, n = 0, len(text)
    state: str | None = None
    while i < n:
        c = text[i]
        nxt = text[i + 1] if i + 1 < n else ""
        if state is None:
            if c == "'":
                state, _ = "sq", out.append(c)
                i += 1
            elif c == '"':
                state, _ = "dq", out.append(c)
                i += 1
            elif c == "/" and nxt == "/":
                state, i = "line", i + 2
            elif c == "#" and nxt != "[":
                state, i = "line", i + 1
            elif c == "/" and nxt == "*":
                state, i = "block", i + 2
            else:
                out.append(c)
                i += 1
        elif state in ("sq", "dq"):
            quote = "'" if state == "sq" else '"'
            if c == "\\" and i + 1 < n:
                out.append(c)
                out.append(text[i + 1])
                i += 2
                continue
            out.append(c)
            if c == quote:
                state = None
            i += 1
        elif state == "line":
            if c == "\n":
                out.append(c)
                state = None
            i += 1
        else:  # block
            if c == "*" and nxt == "/":
                state, i = None, i + 2
            else:
                if c == "\n":
                    out.append(c)
                i += 1
    return "".join(out)


def has_prelude(text: str) -> bool:
    """True when text registers OpenRegister's autoloader.

    Accepts the app id as a quoted literal OR as a constant defined in the
    same composition root to that literal — both are the same prelude.
    """
    const_names = set(APPID_CONST.findall(text))
    for m in PRELUDE.finditer(text):
        args = m.group(1)
        if OPENREGISTER_LITERAL.search(args):
            return True
        for name in const_names:
            if re.search(r"\b" + re.escape(name) + r"\b", args):
                return True
    return False


def class_exists_targets(text: str) -> list[str]:
    """Every class name reached by a class_exists() call in *text*.

    *text* must be comment-stripped. PHP offers three ways to write the same
    class name and all three reach the autoloader through class_exists(), so
    all three are resolved to a comparable FQCN string:

      quoted FQCN     class_exists('OCA\\OpenRegister\\AppHost\\X')
      ::class         class_exists(\\OCA\\OpenRegister\\AppHost\\X::class)
                      class_exists(X::class)  with `use …AppHost\\X;` above
      class constant  const AH = 'OCA\\OpenRegister\\AppHost\\X';
                      class_exists(self::AH)

    Reading only the first is what let an injected AppHost probe pass. Every
    caller below asks its question of this ONE resolver, so a spelling that
    escapes it escapes both the hard rule and the note — rather than escaping
    whichever of them happened to be written second.
    """
    imports: dict[str, str] = {}
    for m in USE_IMPORT.finditer(text):
        fqcn = m.group(1)
        alias = m.group(2) or fqcn.rsplit("\\", 1)[-1].rsplit("\\\\", 1)[-1]
        imports[alias] = fqcn
    constants = dict(CONST_STR.findall(text))

    out: list[str] = []
    for m in CLASS_EXISTS_ARG.finditer(text):
        arg = m.group(1).strip()
        q = QUOTED.match(arg)
        if q:
            out.append(q.group(2))
            continue
        if "::" not in arg:
            continue
        left, right = arg.split("::", 1)
        left, right = left.strip().lstrip("\\"), right.strip()
        if right.startswith("class"):
            # `Foo\Bar::class` — an FQCN written inline, or an imported alias.
            out.append(imports.get(left, left))
        elif right in constants:
            out.append(constants[right])
    return out


def apphost_class_exists(text: str) -> bool:
    """True when *text* calls class_exists() on an AppHost class, any spelling."""
    return any(_APPHOST_FQCN.search(t) for t in class_exists_targets(text))


_CLOSURE_START = re.compile(r"(?<![A-Za-z0-9_$])(?:static\s+)?(?:function\s*\(|fn\s*\()")


def blank_closure_bodies(text: str) -> str:
    """*text* with the bodies of ANONYMOUS functions blanked, offsets kept.

    THE EXEMPTION WAS DOCUMENTED AND NEVER IMPLEMENTED (.github#276).

    This module's header has always said:

        Lazy service closures that merely MENTION an AppHost class are
        deliberately NOT flagged: their bodies run at resolution time, long
        after every app has registered. Only register()-time resolution is
        the defect.

    Both rules ran over the whole file, so they did not. Measured on
    launchpad — whose entire composition root is built on resolving
    OpenRegister lazily inside closures, which is the documented leaf
    pattern and the reason launchpad is green today:

        $context->registerService('Lazy', function ($c) {
            return new \\OCA\\OpenRegister\\AppHost\\Bootstrap($c);
        });

    reported byte-identically to an eager `Bootstrap::register($context, …)`
    at the top of register(). The gate would have failed the one repo doing
    it correctly, for doing it correctly, and the only remedy available is to
    stop writing the lazy form — i.e. to introduce the defect.

    NAMED methods are NOT blanked: `public function register(` has an
    identifier between `function` and `(`, so the pattern cannot match it.
    An eager reference AFTER a closure is still seen (asserted).
    """
    out = list(text)
    n = len(text)

    def blank(a: int, b: int) -> None:
        for k in range(max(a, 0), min(b, n)):
            if out[k] != "\n":
                out[k] = " "

    for m in _CLOSURE_START.finditer(text):
        i, depth = m.end() - 1, 0
        while i < n:
            if text[i] == "(":
                depth += 1
            elif text[i] == ")":
                depth -= 1
                if depth == 0:
                    break
            i += 1
        j = i + 1
        if m.group(0).lstrip().startswith("fn"):
            # `fn (...) => <expr>` — blank to the first `,`/`;` or the
            # enclosing closing bracket, at the expression's own depth.
            d = 0
            while j < n:
                c = text[j]
                if c in "([{":
                    d += 1
                elif c in ")]}":
                    if d == 0:
                        break
                    d -= 1
                elif c in ",;" and d == 0:
                    break
                j += 1
            blank(i + 1, j)
            continue
        while j < n and text[j] not in "{;":
            j += 1
        if j >= n or text[j] == ";":
            continue  # a signature with no body
        d, k = 0, j
        while k < n:
            if text[k] == "{":
                d += 1
            elif text[k] == "}":
                d -= 1
                if d == 0:
                    break
            k += 1
        blank(j + 1, k)
    return "".join(out)


def register_body(text: str) -> str:
    """The balanced body of `function register(...)`, or '' when absent.

    Only register() matters for the OCA\\OpenRegister\\ note: boot() runs after
    every app has registered, so a probe there resolves correctly and is not a
    defect. Reporting it would be the false positive that gets a note ignored.
    """
    m = REGISTER_FN.search(text)
    if m is None:
        return ""
    i = text.find("{", m.end())
    if i < 0:
        return ""
    depth, j, n = 0, i, len(text)
    while j < n:
        if text[j] == "{":
            depth += 1
        elif text[j] == "}":
            depth -= 1
            if depth == 0:
                return text[i:j + 1]
        j += 1
    return text[i:]


def openregister_probes(text: str) -> list[str]:
    """Non-AppHost OCA\\OpenRegister\\ class_exists() probes inside register().

    Resolves imports and constants against the WHOLE file (that is where `use`
    and `const` live) but only reports probes whose call site is in register().
    """
    body = register_body(text)
    if not body:
        return []
    header = text[:text.find(body)] if body in text else text
    in_body = set(class_exists_targets(header + body)) - set(class_exists_targets(header))
    out = []
    for target in sorted(in_body):
        if not _OPENREGISTER_FQCN.search("\\" + target.lstrip("\\")):
            continue
        if _APPHOST_FQCN.search(target):
            continue  # already a hard failure above
        out.append(target)
    return out


def suppression_reason(text: str) -> str | None:
    """Return the reason of a reason-bearing suppression, else None.

    A BARE `apphost-prelude exclude` with no reason is not a suppression — it
    is an unexplained one, and it fails like any other unguarded adoption.
    """
    m = SUPPRESS.search(text)
    if m is None:
        return None
    reason = m.group("reason").strip().strip("*/ ").strip()
    return reason or None


def scan_app(app_dir: str) -> list[tuple[str, str]]:
    """Return [(path, why)] for AppHost adoptions with no prelude."""
    sources = _sources(app_dir)
    if not sources:
        return []

    # Every rule below is evidence about CODE, so it reads comment-stripped
    # source. The SUPPRESSION is the one exception — it is authored AS a
    # comment — so it keeps reading the raw text.
    raw_blob = "\n".join(sources.values())
    code = {path: strip_comments(text) for path, text in sources.items()}

    # OpenRegister owns AppHost — exempt by namespace, not by directory name,
    # so a checkout under any directory name is still recognised.
    if any(OWN_NAMESPACE.search(text) for text in code.values()):
        return []

    blob = "\n".join(code.values())

    # The prelude may legitimately live in a sibling composition-root file that
    # register() calls, so look for it across the whole of lib/AppInfo/.
    if has_prelude(blob):
        return []

    if suppression_reason(raw_blob):
        return []

    findings: list[tuple[str, str]] = []
    for path, text in sorted(code.items()):
        # Only EAGER references are the defect. A mention inside an anonymous
        # function or arrow function resolves when that closure is CALLED,
        # long after every app has registered — this module's header has said
        # so since it was written and nothing implemented it (.github#276).
        # See blank_closure_bodies() for what launchpad measured.
        text = blank_closure_bodies(text)
        if BOOTSTRAP_REF.search(text):
            findings.append(
                (path, "references OCA\\OpenRegister\\AppHost\\Bootstrap")
            )
        elif apphost_class_exists(text):
            findings.append(
                (path, "calls class_exists() on an OCA\\OpenRegister\\AppHost\\ class")
            )

    if findings and LOAD_APP.search(blob):
        findings.append(
            (
                os.path.join(app_dir, "lib", "AppInfo"),
                "uses IAppManager::loadApp('openregister'), which is NOT a prelude: "
                "it marks OpenRegister loaded and calls Coordinator::bootApp(), "
                "booting it before its own register() has run",
            )
        )

    return findings


def scan_notes(app_dir: str) -> list[tuple[str, list[str]]]:
    """[(path, [probed FQCN, ...])] for non-AppHost register()-time probes.

    Non-blocking. Same mechanism as the hard rules, different blast radius —
    see CLASS_EXISTS_OR_LITERAL for why this is a note and what it measured.
    """
    sources = _sources(app_dir)
    if not sources:
        return []
    code = {path: strip_comments(text) for path, text in sources.items()}
    if any(OWN_NAMESPACE.search(text) for text in code.values()):
        return []
    if has_prelude("\n".join(code.values())):
        return []
    if suppression_reason("\n".join(sources.values())):
        return []
    out = []
    for path, text in sorted(code.items()):
        # Eager only, for the same reason as the hard rules above.
        probes = openregister_probes(blank_closure_bodies(text))
        if probes:
            out.append((path, probes))
    return out


APP_ID = re.compile(r"<id>\s*([a-z0-9_\-]+)\s*</id>", re.I)


def app_id(target: str) -> str:
    """The app's real id, from appinfo/info.xml, falling back to the dir name.

    The id — not the checkout directory — is what Nextcloud sorts on, so it is
    what decides whether this app is live-exposed or merely latent.
    """
    info = os.path.join(target, "appinfo", "info.xml")
    try:
        with open(info, encoding="utf-8", errors="replace") as handle:
            m = APP_ID.search(handle.read())
        if m:
            return m.group(1)
    except OSError:
        pass
    return os.path.basename(os.path.abspath(target))


def main(argv: list[str] | None = None) -> int:
    argv = list(sys.argv if argv is None else argv)
    targets = argv[1:] or ["."]
    failures = 0

    for target in targets:
        app = app_id(target)
        # Nextcloud sorts app ids with sort(), so a plain string comparison is
        # the same question it asks. Sorting after `openregister` means the
        # prefix is already on the autoloader by the time register() runs.
        live = app < "openregister"
        if live:
            severity = (
                f"LIVE-EXPOSED: '{app}' sorts BEFORE 'openregister', so this "
                f"resolves on a healthy instance to FALSE (guarded — the plumbing "
                f"is skipped and Bootstrap-aliased endpoints 500) or throws and "
                f"aborts the whole register() (unguarded)"
            )
        else:
            severity = (
                f"LATENT: '{app}' sorts AFTER 'openregister', so this happens to "
                f"work today — by alphabet alone, not by design. Renaming the app, "
                f"or moving this code into one that sorts earlier, breaks it "
                f"silently"
            )
        for path, why in scan_app(target):
            rel = os.path.relpath(path, target)
            print(
                f"FAIL {app}: {rel} {why}, but nothing under lib/AppInfo/ registers "
                f"OpenRegister's autoloader first. {severity}. Apps register in "
                f"sorted order: getEnabledApps() sort()s the list and "
                f"Coordinator::registerApps() calls registerAutoloading() then "
                f"register() one app at a time. Add the ADR-040 prelude: "
                f"$p = \\OCP\\Server::get(\\OCP\\App\\IAppManager::class)"
                f"->getAppPath('openregister'); \\OC_App::registerAutoloading("
                f"'openregister', $p); wrapped in try/catch(\\Throwable). Or add a "
                f"comment 'apphost-prelude exclude <reason>'."
            )
            failures += 1

        for path, probes in scan_notes(target):
            rel = os.path.relpath(path, target)
            print(
                f"NOTE {app}: {rel} calls class_exists() inside register() on "
                f"{len(probes)} non-AppHost OpenRegister class(es) "
                f"({', '.join(probes)}) with no ADR-040 prelude. Same mechanism, "
                f"outside this gate's hard rule: the OCA\\OpenRegister\\ prefix is "
                f"not on the autoloader yet, so each guard answers FALSE and "
                f"everything inside it silently never happens. {severity}. NOT a "
                f"failure — this gate is not diff-scoped, so failing it would "
                f"block every PR in this repo on code the PR did not touch. "
                f"Add the prelude, or move the probe into boot()."
            )

    if failures:
        print(f"\n{failures} AppHost adoption(s) without the autoload prelude.")
        return 1

    print("apphost-autoload-prelude: OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
