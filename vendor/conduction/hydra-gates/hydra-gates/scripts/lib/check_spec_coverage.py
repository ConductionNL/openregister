#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Gate-16 spec-coverage — diff-scoped @spec traceability enforcer.

Every public/protected backend method and every non-trivial frontend method
ADDED or MODIFIED in a PR must carry an ``@spec openspec/...`` reference in its
docblock (PHP) or JSDoc (JS/TS/Vue). This makes the code → docblock → spec
chain mandatory going forward (ADR-003) without bouncing PRs on the mountain of
pre-existing untagged legacy methods (ADR-020 — gates scope to the PR diff).

Diff scope
==========

The set of "changed methods" is derived from ``git diff -U0`` against
``$HYDRA_GATE_BASE_REF`` (default ``origin/development``). For each in-scope
file we collect the set of *added* line numbers (the right-hand side of the
diff) and only flag a method whose declaration line OR body overlaps one of
those added lines. A method that exists in the diff's context but was not
itself touched is never flagged — so untouched legacy methods stay green even
when a neighbouring method changes.

This runs against the CURRENT working directory's git repo (the script is
repo-agnostic — it lives in hydra but operates on whatever app is checked out
at cwd).

Scope rules
===========

Backend (``*.php``):
  In-scope dirs:   lib/Controller, lib/Service, lib/BackgroundJob,
                   lib/Command, lib/Cron, lib/Listener, lib/Repair.
  In-scope methods: ``public`` + ``protected`` functions.
  Exempt:          __construct, magic methods (__call/__get/__set/...),
                   simple accessors (get*/set*/is*/has* with a body of <=2
                   non-blank lines), and anything under lib/Db or
                   lib/Migration.

Frontend (``*.js`` / ``*.ts`` / ``*.vue``):
  In-scope: Vue SFC ``methods:`` / ``computed:`` functions, ``setup()`` bodies,
            composable exports under src/composables/, Pinia/Vuex actions under
            src/store*/, and exported functions under src/services, src/utils,
            src/lib, src/entities.
  Exempt:   trivial state-passthrough getters + lifecycle hooks (created/
            mounted/...) with empty or <=2-line bodies, src/main.js,
            src/bootstrap.js, *.spec.* / *.test.* / __tests__.

The rule
========

The docblock / JSDoc immediately above an in-scope, changed method must contain
a string matching ``@spec\\s+openspec/``. Missing → one finding::

    <path>::<method> — missing @spec

Exit code is the number of findings (0 == clean), mirroring the other
python-backed gates.

Usage::

    python3 scripts/lib/check_spec_coverage.py [app-dir]
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import sys
from pathlib import Path

SPEC_RE = re.compile(r"@spec\s+openspec/")
# `@spec exclude <reason>` — intentional, reason-required non-coverage marker.
# A bare `@spec exclude` (no reason) is NOT compliant — it would let a method
# silently hide a gap, so it is flagged like a missing @spec.
SPEC_EXCLUDE_RE = re.compile(r"@spec\s+exclude\b[ \t]*(?P<reason>.*?)\s*$")

# ---- backend ---------------------------------------------------------------

BACKEND_DIRS = (
    "lib/Controller",
    "lib/Service",
    "lib/BackgroundJob",
    "lib/Command",
    "lib/Cron",
    "lib/Listener",
    "lib/Repair",
)
BACKEND_EXEMPT_DIRS = ("lib/Db", "lib/Migration")

# `public function foo(` / `protected function foo(` — capture visibility+name.
PHP_METHOD_RE = re.compile(
    r"^\s*(?:final\s+|abstract\s+|static\s+)*"
    r"(?P<vis>public|protected)\s+"
    r"(?:static\s+)?function\s+(?P<name>[A-Za-z_][A-Za-z0-9_]*)\s*\(",
)
PHP_MAGIC_RE = re.compile(r"^__")
PHP_ACCESSOR_RE = re.compile(r"^(get|set|is|has)[A-Z0-9_]")

# ---- frontend --------------------------------------------------------------

FRONTEND_EXPORT_DIRS = (
    "src/composables",
    "src/services",
    "src/utils",
    "src/lib",
    "src/entities",
)
FRONTEND_STORE_RE = re.compile(r"(^|/)src/stores?(/|$)")
FRONTEND_EXEMPT_FILES = ("src/main.js", "src/main.ts", "src/bootstrap.js", "src/bootstrap.ts")
LIFECYCLE_HOOKS = {
    "beforeCreate", "created", "beforeMount", "mounted",
    "beforeUpdate", "updated", "beforeUnmount", "unmounted",
    "beforeDestroy", "destroyed", "activated", "deactivated",
}
# Vue framework state/render options — never business logic, always exempt
# regardless of body size (`data()` returns the component's state object,
# `render()` is a compile target). `setup()` stays IN scope (it holds real
# composable wiring per the gate spec).
VUE_FRAMEWORK_OPTIONS = {"data", "render"}
# An exported function declaration in a JS/TS module.
JS_EXPORT_FN_RE = re.compile(
    r"^\s*export\s+(?:default\s+)?(?:async\s+)?function\s*\*?\s*"
    r"(?P<name>[A-Za-z_$][A-Za-z0-9_$]*)?\s*\(",
)
JS_EXPORT_CONST_FN_RE = re.compile(
    r"^\s*export\s+(?:default\s+)?const\s+(?P<name>[A-Za-z_$][A-Za-z0-9_$]*)\s*="
    r"\s*(?:async\s+)?(?:\([^)]*\)|[A-Za-z_$][A-Za-z0-9_$]*)\s*=>",
)
# A method-shorthand or named function inside a Vue methods:/computed:/actions:
# block or setup(). e.g. `  fetchThings () {` or `  async save (id) {`.
VUE_METHOD_RE = re.compile(
    r"^(?P<indent>\s+)(?:async\s+)?(?P<name>[A-Za-z_$][A-Za-z0-9_$]*)\s*"
    r"\([^)]*\)\s*\{",
)


def _git(args: list[str], cwd: Path) -> str:
    try:
        out = subprocess.run(
            ["git", "-c", "safe.directory=*", *args],
            cwd=str(cwd),
            capture_output=True,
            text=True,
            check=False,
        )
        return out.stdout
    except OSError:
        return ""


def changed_lines(base_ref: str, cwd: Path) -> dict[str, set[int]]:
    """Return {relative_path: {added_line_numbers}} from ``git diff -U0``.

    Falls back from ``BASE...HEAD`` to ``BASE`` (working-tree diff) so the
    gate works both on a PR branch and on an uncommitted local checkout.
    """
    diff = _git(["diff", "-U0", "--diff-filter=ACMR", f"{base_ref}...HEAD"], cwd)
    if not diff.strip():
        diff = _git(["diff", "-U0", "--diff-filter=ACMR", base_ref], cwd)
    result: dict[str, set[int]] = {}
    current: str | None = None
    hunk_re = re.compile(r"^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@")
    for line in diff.splitlines():
        if line.startswith("+++ b/"):
            current = line[6:]
            result.setdefault(current, set())
        elif line.startswith("+++ /dev/null"):
            current = None
        elif line.startswith("@@") and current is not None:
            m = hunk_re.match(line)
            if m:
                start = int(m.group(1))
                count = int(m.group(2)) if m.group(2) is not None else 1
                for n in range(start, start + count):
                    result[current].add(n)
    return result


def spec_tags_removed(base_ref: str, cwd: Path) -> set[str]:
    """Return the files whose diff DELETES an ``@spec`` line.

    WHY THIS EXISTS (.github#271)
    -----------------------------
    ``_overlaps`` walks FORWARD from the declaration line through the method
    body. The docblock sits ABOVE the declaration, so it is outside the scope
    window entirely — and the docblock is the only place ``@spec`` can live.

    The consequence is that the one edit which REMOVES coverage is the one edit
    this gate cannot see. Measured 2026-08-08 on a two-file fixture: delete the
    ``@spec openspec/...`` line from a tagged method, leave the body
    byte-identical, and the helper prints ``# count=0`` and exits 0. Every
    ``@spec`` tag in a repository can be stripped and gate-16 stays green.

    Worse, ``run_gate`` skips a file whose ``added`` set is empty, and a pure
    deletion produces exactly that — so the file was never even opened.

    Same family as the ``filter_preexisting_methods.py`` defect that lets an
    auth-attribute removal be filed as pre-existing (gates 5/9/30): a
    body-shaped scope cannot see a change that is not in the body. Gate-16 does
    not route through that helper — its four call sites are gates 6, 7, 8 and
    30 — it arrives at the same blind spot by its own path.

    DELIBERATELY NARROW. This does NOT put every docblock edit in scope: fixing
    a typo in a legacy untagged method's docblock must not surface that method
    as a finding, because ADR-020 exists to stop inherited debt blocking
    unrelated work. It fires only when the diff actually TOOK A TAG AWAY, which
    is never inherited debt and is always the author's own doing.
    """
    removed: set[str] = set()
    for form in ([f"{base_ref}...HEAD"], [base_ref]):
        diff = _git(["diff", "-U0", "--diff-filter=ACMRD", *form], cwd)
        if not diff.strip():
            continue
        current: str | None = None
        for line in diff.splitlines():
            if line.startswith("--- a/"):
                current = line[6:]
            elif line.startswith("+++ b/"):
                # Prefer the new path for renames; fall back to the old one.
                current = line[6:]
            elif line.startswith("-") and not line.startswith("---"):
                if current and (SPEC_RE.search(line) or SPEC_EXCLUDE_RE.search(line)):
                    removed.add(current)
        if removed:
            break
    return removed


def _docblock_spec_status(lines: list[str], decl_idx: int) -> tuple[str, str | None]:
    """Classify the docblock immediately above ``decl_idx`` into one of:
      - ``("covered", None)``        — has ``@spec openspec/...``
      - ``("excluded", reason)``     — has ``@spec exclude <reason>`` (reason non-empty)
      - ``("exclude_noreason", None)`` — has a bare ``@spec exclude`` with no reason
      - ``("none", None)``           — neither (an uncovered gap)
    """
    block = _docblock_block(lines, decl_idx)
    if any(SPEC_RE.search(b) for b in block):
        return ("covered", None)
    for b in block:
        m = SPEC_EXCLUDE_RE.search(b)
        if m:
            reason = m.group("reason").strip().rstrip("*/").strip()
            return ("excluded", reason) if reason else ("exclude_noreason", None)
    return ("none", None)


def _docblock_block(lines: list[str], decl_idx: int) -> list[str]:
    """Return the lines of the ``/** ... */`` block immediately preceding the
    declaration on ``decl_idx`` (skipping PHP attributes + blank lines), or
    ``[]`` if there is no docblock directly above."""
    i = decl_idx - 1
    # Skip PHP attributes, blank lines, and intervening single-line comments
    # (e.g. `/* istanbul ignore next */`, `// eslint-disable-next-line`) that
    # commonly sit between a docblock and the declaration it documents. Without
    # this, a `/* istanbul ignore next */` line — which contains `*/` — is
    # mistaken for the docblock close and the real `@spec` docblock above is
    # never read (observed on openregister store `refreshXList` actions).
    while i >= 0:
        stripped = lines[i].strip()
        if stripped == "" or stripped.startswith("#[") or stripped.startswith("]"):
            i -= 1
            continue
        if stripped.startswith("//"):
            i -= 1
            continue
        # A single-line block comment that is NOT a docblock opener — including a
        # trailing line comment after it, e.g. `/* istanbul ignore next */ // note`.
        if stripped.startswith("/*") and not stripped.startswith("/**") and "*/" in stripped:
            after = stripped.split("*/", 1)[1].strip()
            if after == "" or after.startswith("//"):
                i -= 1
                continue
        break
    if i < 0:
        return []
    # The line just above should be the close of a block comment.
    if "*/" not in lines[i]:
        return []
    block: list[str] = []
    while i >= 0:
        block.append(lines[i])
        if "/**" in lines[i] or "/*" in lines[i]:
            break
        i -= 1
    return block


def _docblock_has_spec(lines: list[str], decl_idx: int) -> bool:
    """True if the docblock above ``decl_idx`` carries ``@spec openspec/`` OR a
    reason-bearing ``@spec exclude <reason>`` — i.e. the method is compliant
    (covered or intentionally excluded). A bare ``@spec exclude`` is NOT
    compliant."""
    status, _ = _docblock_spec_status(lines, decl_idx)
    return status in ("covered", "excluded")


def _body_line_count(lines: list[str], decl_idx: int) -> int:
    """Count non-blank lines in the brace-delimited body following the
    declaration on ``decl_idx``. Used to identify trivial accessors / hooks.
    Returns a large number if no brace is found (treat as non-trivial)."""
    depth = 0
    started = False
    count = 0
    n = len(lines)
    i = decl_idx
    while i < n:
        line = lines[i]
        opens = line.count("{")
        closes = line.count("}")
        if not started and opens > 0:
            started = True
        if started:
            # Count body lines (exclude the decl line itself and the closing).
            if i > decl_idx:
                stripped = line.strip()
                if stripped and stripped not in ("{", "}"):
                    count += 1
            depth += opens - closes
            if depth <= 0 and (opens or closes):
                break
        i += 1
        if i - decl_idx > 400:
            break
    return count


def _overlaps(decl_idx: int, lines: list[str], added: set[int]) -> bool:
    """True if the method's declaration or body (1-based lines) intersects the
    set of added line numbers."""
    # decl_idx is 0-based; convert to 1-based and walk the body span.
    depth = 0
    started = False
    i = decl_idx
    n = len(lines)
    while i < n:
        if (i + 1) in added:
            return True
        opens = lines[i].count("{")
        closes = lines[i].count("}")
        if not started and opens > 0:
            started = True
        if started:
            depth += opens - closes
            if depth <= 0 and (opens or closes):
                break
        i += 1
        if i - decl_idx > 400:
            break
    return False


def check_php_file(rel: str, text: str, added: set[int], findings: list[str]) -> None:
    lines = text.splitlines()
    for idx, line in enumerate(lines):
        m = PHP_METHOD_RE.match(line)
        if not m:
            continue
        name = m.group("name")
        if PHP_MAGIC_RE.match(name):
            continue
        if PHP_ACCESSOR_RE.match(name) and _body_line_count(lines, idx) <= 2:
            continue
        if not _overlaps(idx, lines, added):
            continue
        if _docblock_has_spec(lines, idx):
            continue
        findings.append(f"{rel}::{name} — missing @spec")


def _is_frontend_in_scope(rel: str) -> bool:
    if rel in FRONTEND_EXEMPT_FILES:
        return False
    if ".spec." in rel or ".test." in rel or "__tests__" in rel:
        return False
    if rel.endswith(".vue"):
        return rel.startswith("src/")
    if rel.endswith((".js", ".ts")):
        if rel.startswith(FRONTEND_EXPORT_DIRS):
            return True
        if FRONTEND_STORE_RE.search(rel):
            return True
    return False


def check_frontend_file(rel: str, text: str, added: set[int], findings: list[str]) -> None:
    lines = text.splitlines()
    is_vue = rel.endswith(".vue")
    is_module = rel.endswith((".js", ".ts"))
    in_store = bool(FRONTEND_STORE_RE.search(rel))
    in_export_dir = rel.startswith(FRONTEND_EXPORT_DIRS)

    for idx, line in enumerate(lines):
        name: str | None = None

        # Exported function / arrow-const in a JS/TS module.
        if is_module and (in_export_dir or in_store):
            jm = JS_EXPORT_FN_RE.match(line) or JS_EXPORT_CONST_FN_RE.match(line)
            if jm:
                name = jm.groupdict().get("name") or "default"

        # Vue method-shorthand (also covers composable/setup/store-action
        # functions written as method shorthand inside an object literal).
        if name is None:
            vm = VUE_METHOD_RE.match(line)
            if vm:
                cand = vm.group("name")
                # Skip control-flow keywords that look like a call.
                if cand in ("if", "for", "while", "switch", "catch", "function", "return"):
                    continue
                # Vue framework state/render options are never business logic.
                if cand in VUE_FRAMEWORK_OPTIONS:
                    continue
                # Lifecycle hooks with trivial bodies are exempt.
                if cand in LIFECYCLE_HOOKS and _body_line_count(lines, idx) <= 2:
                    continue
                # In a plain Vue SFC we only care about method-ish blocks; this
                # heuristic accepts any object-method shorthand and relies on
                # the diff-scope + trivial-getter exemption to stay quiet.
                if is_vue or in_store or in_export_dir:
                    # Trivial state-passthrough getter: `getFoo () { return ... }`
                    if _body_line_count(lines, idx) <= 2 and PHP_ACCESSOR_RE.match(cand):
                        continue
                    name = cand

        if name is None:
            continue
        if not _overlaps(idx, lines, added):
            continue
        if _docblock_has_spec(lines, idx):
            continue
        findings.append(f"{rel}::{name} — missing @spec")


def _iter_in_scope_files(app_dir: Path) -> list[str]:
    """All in-scope backend + frontend files in the repo (full-repo, no diff)."""
    rels: list[str] = []
    for d in BACKEND_DIRS:
        base = app_dir / d
        if base.is_dir():
            rels += [str(p.relative_to(app_dir)) for p in base.rglob("*.php")]
    src = app_dir / "src"
    if src.is_dir():
        for p in src.rglob("*"):
            if p.is_file():
                rel = str(p.relative_to(app_dir))
                if _is_frontend_in_scope(rel):
                    rels.append(rel)
    return sorted(set(rels))


def run_report(app_dir: Path) -> int:
    """Full-repo coverage report (NOT diff-scoped). Lists every uncovered
    in-scope method as JSON so AI tooling can act on the gaps directly —
    the mechanical replacement for the coverage-scan enumeration pass.
    Always exits 0 (a report is informational, never a build failure)."""
    uncovered: list[str] = []
    for rel in _iter_in_scope_files(app_dir):
        path = app_dir / rel
        try:
            text = path.read_text(encoding="utf-8")
        except OSError:
            continue
        # added = every line, so the diff-scope `_overlaps` check always passes
        # → every in-scope method is evaluated; @spec / @spec-exclude still skip.
        all_lines = set(range(1, len(text.splitlines()) + 2))
        if rel.endswith(".php"):
            if rel.startswith(BACKEND_EXEMPT_DIRS) or not rel.startswith(BACKEND_DIRS):
                continue
            check_php_file(rel, text, all_lines, uncovered)
        elif _is_frontend_in_scope(rel):
            check_frontend_file(rel, text, all_lines, uncovered)
    uncovered = sorted(set(uncovered))
    out = {
        "mode": "report",
        "uncovered_count": len(uncovered),
        "uncovered": [
            {"ref": u, "layer": "backend" if u.split("::", 1)[0].endswith(".php") else "frontend"}
            for u in uncovered
        ],
    }
    print(json.dumps(out, indent=2))
    return 0


def _git_show(base_ref: str, rel: str, cwd: Path) -> str:
    """The file as it was at ``base_ref``; empty string when it did not exist."""
    return _git(["show", f"{base_ref}:{rel}"], cwd)


def _uncovered_in_text(rel: str, text: str) -> set[str]:
    """Every in-scope method in ``text`` that carries no @spec, ignoring diff
    scope. Used to diff a file against its own base version so only the methods
    whose coverage CHANGED are reported (.github#271)."""
    if not text:
        return set()
    out: list[str] = []
    all_lines = set(range(1, len(text.splitlines()) + 2))
    if rel.endswith(".php"):
        if rel.startswith(BACKEND_EXEMPT_DIRS) or not rel.startswith(BACKEND_DIRS):
            return set()
        check_php_file(rel, text, all_lines, out)
    elif _is_frontend_in_scope(rel):
        check_frontend_file(rel, text, all_lines, out)
    return set(out)


def run_gate(app_dir: Path) -> int:
    base_ref = os.environ.get("HYDRA_GATE_BASE_REF", "origin/development")
    changed = changed_lines(base_ref, app_dir)
    # Files this diff STRIPPED an @spec tag from (.github#271). A pure deletion
    # produces an empty `added` set, which the loop below used to skip outright,
    # so the file was not even opened.
    stripped = spec_tags_removed(base_ref, app_dir)
    findings: list[str] = []

    for rel in sorted(set(changed) | stripped):
        added = changed.get(rel, set())
        path = app_dir / rel
        if not path.is_file():
            continue
        if rel in stripped:
            # WHAT THIS PR TOOK AWAY, AND ONLY THAT.
            #
            # Evaluating the whole file here would name every untagged method in
            # it, which on a legacy file is inherited debt the author did not
            # touch — the thing ADR-020 exists to keep out of a PR. So the file
            # is evaluated TWICE, once as it is and once as it was at the base,
            # with the same walkers and therefore the same exemptions, and the
            # findings are the DIFFERENCE. A file that lost one tag reports one
            # finding; a file that lost none reports none, however much
            # pre-existing debt it carries.
            before = _uncovered_in_text(rel, _git_show(base_ref, rel, app_dir))
            now = _uncovered_in_text(rel, path.read_text(encoding="utf-8"))
            findings.extend(sorted(now - before))
            if not added:
                continue
        elif not added:
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except OSError:
            continue

        if rel.endswith(".php"):
            if rel.startswith(BACKEND_EXEMPT_DIRS):
                continue
            if not rel.startswith(BACKEND_DIRS):
                continue
            check_php_file(rel, text, added, findings)
        elif _is_frontend_in_scope(rel):
            check_frontend_file(rel, text, added, findings)

    for line in sorted(set(findings)):
        print(line)
    # TERMINAL MARKER (.github#271) — see the note in
    # check_dashboard_antipattern.py. gate-16 decided its verdict with `wc -l`
    # over this helper's stdout after `2>/dev/null || true`, so a helper that
    # crashed produced an empty log and the gate reported PASS. Verified
    # 2026-08-08 by running the suite with a python3 stub that always exits 1:
    # gate-16 said PASS while nothing had been inspected.
    #
    # The exit code is now a STATUS (0 clean / 1 findings), not the count. It
    # used to be `len(set(findings))` — a count in one byte, which is #209:
    # openregister's own root-scoped sweep is well past 255.
    _count = len(set(findings))
    print(f"# count={_count}")
    return 1 if _count else 0


def main(argv: list[str]) -> int:
    mode = "gate"
    app = "."
    rest = argv[1:]
    i = 0
    while i < len(rest):
        if rest[i] == "--mode" and i + 1 < len(rest):
            mode = rest[i + 1]
            i += 2
            continue
        app = rest[i]
        i += 1
    app_dir = Path(app).resolve()
    if mode == "report":
        return run_report(app_dir)
    return run_gate(app_dir)


if __name__ == "__main__":
    sys.exit(main(sys.argv))
