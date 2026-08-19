#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Gate 51 helper — register-handler-resolution.

Every class/FQCN + method referenced from an OpenRegister register JSON
(``lib/Settings/**/*register*.json``, ``lib/Settings/register.d/*.json``) —
lifecycle guards (``requires``, ``guard``, ``save``, ``fallbackGuard``,
``preconditions``), and calculation/aggregation/notification ``handler``
entries — MUST resolve to a class that exists in the repo AND (when a
``::method`` suffix is present) a method that exists on it.

Observed 2026-07-13 on shillinq: 17 guard classes referenced from
register.d ``requires``/``guard``/``save``/``fallbackGuard`` entries did
not exist at all (``VatSubmissionGuard``, ``BcfSubmissionGuard``,
``GLReversalGuard``, ... — issue #425), and
``OCA\\Shillinq\\Lifecycle\\PeriodCloseGuard::trialBalanceVerifies``
referenced a real class but a method that was never written. Because
OpenRegister's ``LifecycleGuardRegistry::resolve()`` throws uncaught in
``LifecycleValidationListener``, every one of those 17 lifecycle
transitions hard-fails (HTTP 500) the first time a user attempts it — a
feature that looks "done" (spec ticked, unit tests pass by calling the
guard class directly) but is 100%% broken in the app, because nothing
mechanical ever verified the register.d STRING actually points at real
PHP.

Usage:
    check_register_handler_resolution.py <register.json> [<register.json> ...]

Prints one finding per line to stdout in the shape:
    <file>:<json.path> rule=<rule-id> target=<FQCN>[::<method>]
Always exits 0 — the calling gate (bash) counts the printed lines.

Suppression: a sibling key ``<key>Exclude`` (e.g. ``requiresExclude``) at
the SAME object level, holding a non-empty reason string of at least 10
characters, suppresses every handler reference declared under ``<key>`` in
that object. Mirrors the fleet's ``@spec exclude <reason>`` /
``@e2e exclude <reason>`` convention adapted to pure-JSON authoring (JSON
has no comments, so the reason lives in a sibling key instead of an
inline annotation).
"""

from __future__ import annotations

import json
import os
import re
import sys

# Keys whose STRING (or list-of-string) values are treated as class/method
# references. Object-shaped values under these keys (the declarative CEL
# `{"type":"precondition","conditions":[...]}` form) are NOT class
# references and are silently skipped — only string/list-of-string values
# are inspected.
HANDLER_KEYS = ("handler", "guard", "requires", "save", "fallbackGuard", "class", "preconditions")

# A bare FQCN or FQCN::method reference. Requires at least one namespace
# separator so plain words that happen to live under a HANDLER_KEYS key in
# some future schema (e.g. a bare role name) are never mistaken for a class
# reference.
_FQCN_RE = re.compile(
    r"^\\?[A-Za-z_][A-Za-z0-9_]*(?:\\[A-Za-z_][A-Za-z0-9_]*)+(?:::(?P<method>[A-Za-z_][A-Za-z0-9_]*))?$"
)

_CLASS_DEF_RE_CACHE: dict[str, re.Pattern] = {}


def _class_def_re(short_name: str) -> re.Pattern:
    """Line-anchored class-declaration matcher for *short_name*.

    Anchored to the start of a source line (allowing leading whitespace and
    an optional `final`/`abstract` modifier) so prose inside a docblock
    comment that happens to name the class (e.g. "see the VatSubmissionGuard
    class for details") is never mistaken for a real declaration — a
    docblock line always starts with `*` or `//` after the leading
    whitespace, never with the bare word `class`. Without this anchor the
    fallback search could silently treat a merely-MENTIONED class as
    "found", masking the exact fleet defect this gate exists to catch.

    ⚠️ IT ONLY MATCHED `class`, AND ONLY ONE MODIFIER (.github#276).

    The PSR-4 path guess above handles the conventional layout. This fallback
    walk is what finds a type living where PSR-4 does not predict — a
    DI-bound registration, a type not named after its file. That is exactly
    the shape gate-30 was caught mis-resolving: `AppHost\\Controller\\
    GenericHealth` PSR-4-maps to `lib/Controller/AppHost/Controller/…` while
    openregister DI-binds it to `lib/AppHost/Controller/`. So every
    declaration form the walk cannot match is a FALSE POSITIVE on a type that
    genuinely exists — and the action `guard-class-not-found` invites is to
    write the class a second time.

    Measured, each against a real declaration at a non-conventional path:

        enum ProbeState: string { … }        -> guard-class-not-found
        interface ProbeContract { … }        -> guard-class-not-found
        final readonly class ReadonlyProbe   -> guard-class-not-found

    The third is not exotic: `final readonly` is ordinary PHP 8.2, and the
    pattern allowed `final ` OR `abstract `, never two modifiers. Enums and
    interfaces belong here too — a `handler` may name an enum-backed strategy
    or an interface the container resolves to an implementation.

    Only the DECLARATION FORMS widen. The line anchor — the thing that keeps
    docblock prose out, and the reason this walk is trustworthy at all — is
    unchanged, and asserted in both directions by the test suite.
    """
    pat = _CLASS_DEF_RE_CACHE.get(short_name)
    if pat is None:
        pat = re.compile(
            r"^\s*(?:(?:final|abstract|readonly)\s+)*"
            r"(?:class|interface|trait|enum)\s+" + re.escape(short_name) + r"\b",
            re.MULTILINE,
        )
        _CLASS_DEF_RE_CACHE[short_name] = pat
    return pat


def _method_def_re(method: str) -> re.Pattern:
    return re.compile(r"function\s+" + re.escape(method) + r"\s*\(")


def resolve_fqcn(fqcn: str, method: str | None, app_root: str):
    """Resolve *fqcn* (optionally ``::method``) against *app_root*.

    Returns ``None`` when fully resolved, else one of
    ``"guard-class-not-found"`` / ``"guard-method-not-found"``.
    """
    segments = [s for s in fqcn.split("\\") if s]
    if not segments:
        return "guard-class-not-found"
    short_name = segments[-1]

    found_file = None

    # Conventional PSR-4 path: OCA\<App>\<Sub...>\<Class> -> lib/<Sub...>/<Class>.php
    if len(segments) >= 3:
        candidate = os.path.join(app_root, "lib", *segments[2:]) + ".php"
        if os.path.isfile(candidate):
            found_file = candidate

    # Fallback: search the whole lib/ tree for a matching class declaration.
    # Catches classes that live at an unconventional path (or a cross-app
    # reference) without producing a false positive purely from a layout
    # mismatch — the FLEET evidence (VatSubmissionGuard et al.) shows the
    # real failure mode is "class genuinely does not exist anywhere",
    # which this fallback still catches.
    if found_file is None:
        lib_dir = os.path.join(app_root, "lib")
        class_re = _class_def_re(short_name)
        if os.path.isdir(lib_dir):
            for dirpath, _dirnames, filenames in os.walk(lib_dir):
                for fn in filenames:
                    if not fn.endswith(".php"):
                        continue
                    fp = os.path.join(dirpath, fn)
                    try:
                        with open(fp, encoding="utf-8", errors="replace") as fh:
                            content = fh.read()
                    except OSError:
                        continue
                    if class_re.search(content):
                        found_file = fp
                        break
                if found_file:
                    break

    if found_file is None:
        return "guard-class-not-found"

    if method:
        try:
            with open(found_file, encoding="utf-8", errors="replace") as fh:
                content = fh.read()
        except OSError:
            return "guard-method-not-found"
        if not _method_def_re(method).search(content):
            return "guard-method-not-found"

    return None


def _walk(obj, path, file_path, app_root, findings):
    if isinstance(obj, dict):
        for key, value in obj.items():
            if key.endswith("Exclude"):
                # Exclude sibling keys are consumed by the owning HANDLER_KEYS
                # branch below, never recursed into as their own finding source.
                continue
            if key in HANDLER_KEYS:
                _check_handler_value(key, value, obj, f"{path}.{key}", file_path, app_root, findings)
                # Still recurse in case a handler-shaped key nests further
                # dict/list structure that itself carries handler refs (rare,
                # but keeps the walk total rather than key-name-specific).
                if isinstance(value, (dict, list)):
                    _walk(value, f"{path}.{key}", file_path, app_root, findings)
            else:
                _walk(value, f"{path}.{key}", file_path, app_root, findings)
    elif isinstance(obj, list):
        for i, item in enumerate(obj):
            _walk(item, f"{path}[{i}]", file_path, app_root, findings)


def _check_handler_value(key, value, parent_obj, path, file_path, app_root, findings):
    exclude_reason = parent_obj.get(f"{key}Exclude")
    if isinstance(exclude_reason, str) and len(exclude_reason.strip()) >= 10:
        return

    if isinstance(value, str):
        _check_one(value, path, file_path, app_root, findings)
    elif isinstance(value, list):
        for i, item in enumerate(value):
            if isinstance(item, str):
                _check_one(item, f"{path}[{i}]", file_path, app_root, findings)
    # dict (declarative CEL guard object) or other scalar types: not a class
    # reference, nothing to check.


def _check_one(value, path, file_path, app_root, findings):
    m = _FQCN_RE.match(value.strip())
    if not m:
        return
    fqcn_part = value.strip().split("::", 1)[0].lstrip("\\")
    method = m.group("method")
    status = resolve_fqcn(fqcn_part, method, app_root)
    if status is not None:
        target = fqcn_part + (f"::{method}" if method else "")
        findings.append(f"{file_path}:{path} rule={status} target={target}")


def check_file(file_path, app_root, findings):
    try:
        with open(file_path, encoding="utf-8", errors="replace") as fh:
            doc = json.load(fh)
    except (OSError, ValueError):
        return
    _walk(doc, "$", file_path, app_root, findings)


def app_root_for(file_path: str) -> str:
    """Derive the owning app root for *file_path* from the file's OWN path.

    A register JSON always lives at ``<app_root>/lib/Settings/**``, so the
    app root is discoverable from the file itself and must NEVER be taken
    from the process cwd. Deriving it from ``os.getcwd()`` made the gate
    report EVERY guard in a register as ``guard-class-not-found`` whenever
    it was invoked from anywhere other than the app root (a fleet-wide
    sweep from ``apps-extra/``, a reviewer's ad-hoc run, any harness that
    passes ``pipelinq/lib/Settings/x.json``): ``<cwd>/lib`` did not exist,
    so no class could ever resolve. Observed 2026-07-16 — 7 false
    positives on pipelinq's live, DI-registered POS money guards
    (PosTransactionConfirmGuard et al.), every one of which exists and is
    wired in ``lib/AppInfo/Application.php``. A gate that cries wolf on
    live money guards gets ignored, which destroys the true-positive value
    the gate exists for.

    Walks up from the file and returns the first ancestor holding
    ``appinfo/info.xml`` (the canonical NC app marker). Failing that, uses
    the parent of the nearest ``lib/`` component ON THE FILE'S OWN PATH —
    register JSONs live under ``<app_root>/lib/Settings/``, so that parent
    IS the app root. The ``lib/`` rule deliberately matches a path
    component of the file rather than "any ancestor that happens to contain
    a lib/ directory": the latter would match ``/`` on any Linux box (``/lib``
    exists), silently rooting the search at the filesystem root and walking
    ``/lib``.

    Falls back to the cwd-based legacy behaviour only when neither marker
    is found, so a caller that already chdir'd into the app root (as the
    gate's own harness does) keeps working unchanged.
    """
    directory = os.path.dirname(os.path.abspath(file_path))
    fallback = None
    while True:
        if os.path.isfile(os.path.join(directory, "appinfo", "info.xml")):
            return directory
        if fallback is None and os.path.basename(directory) == "lib":
            # Nearest `lib/` component of the file's path -> its parent is
            # the app root. Keep walking: an explicit appinfo/info.xml
            # higher up is the stronger signal and wins.
            fallback = os.path.dirname(directory)
        parent = os.path.dirname(directory)
        if parent == directory:
            break
        directory = parent
    if fallback is not None:
        return fallback
    return os.getcwd()


def main(argv):
    findings: list[str] = []
    for path in argv[1:]:
        check_file(path, app_root_for(path), findings)
    for line in findings:
        print(line)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
