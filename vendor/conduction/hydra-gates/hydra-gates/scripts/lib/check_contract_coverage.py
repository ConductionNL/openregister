#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Gate-25 contract-coverage — diff-scoped new-public-endpoint contract-test enforcer.

A controller method ADDED in the PR diff that is **registered as a route** in
``appinfo/routes.php`` AND is publicly reachable (``#[PublicPage]`` /
``#[NoAdminRequired]`` / ``@PublicPage`` / ``@NoAdminRequired``) is a new
network-facing endpoint. It MUST be covered by an automated contract test —
either a Newman/Postman collection assertion under
``tests/integration/*.postman_collection.json`` that hits its route, OR a
PHPUnit controller test under ``tests/**`` that exercises the controller method
— OR carry a reason-bearing ``@contract exclude <reason>`` in its docblock.

This is the API-layer companion to gate-19 (e2e-coverage, UI layer) and gate-16
(spec-coverage, code↔spec). Together they close the loop so a newly-exposed
endpoint can never merge without an automated proof that its wire contract holds.

Diff scope
==========

The set of ADDED methods is derived from ``git diff -U0`` against
``$HYDRA_GATE_BASE_REF`` (default ``origin/development``): only a controller
method whose **declaration line** falls on an added line is considered new.
Pre-existing endpoints (untouched legacy debt) are never flagged — ADR-020.

What counts as coverage
=======================

A new endpoint ``<controller>#<method>`` (route slug) is considered covered when:

1. A ``*.postman_collection.json`` file under ``tests/integration/`` references
   the endpoint's URL path. We resolve the path from the matching route entry's
   ``'url'`` and look for the literal path segment (minus ``{placeholders}``) in
   any collection request ``raw`` URL. A looser fallback also matches the method
   name appearing in a request ``name``.
2. OR a PHPUnit test file under ``tests/`` (``*Test.php``) references the
   controller method — either ``->method(`` (calling it) or naming the
   controller class plus the method anywhere in the file.
3. OR the method's docblock carries ``@contract <ref>`` (an explicit pointer to
   a Newman collection / test) or a reason-bearing ``@contract exclude <reason>``.

A bare ``@contract exclude`` (no reason) is non-compliant — flagged like a
missing test, mirroring gate-16/gate-19's exclude rules.

Usage::

    HYDRA_GATE_BASE_REF=origin/development python3 scripts/lib/check_contract_coverage.py [app-dir]
    python3 scripts/lib/check_contract_coverage.py [app-dir] --mode report
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import sys
from pathlib import Path

GATE_NUM = 25

# ---------------------------------------------------------------------------
# AN EXIT CODE IS A STATUS. THE COUNT GOES ON STDOUT.
# ---------------------------------------------------------------------------
# Same convention gate-19 settled on after returning its finding count as an
# exit status (.github#209): a byte cannot carry a count, and a count cannot
# carry a status. It carries a status; the number is printed.
#
# EMPTY_SCOPE exists because PASS and "I inspected nothing" used to be the same
# 0, which is why --require-full-coverage — whose whole job is to notice gates
# that did not run — could not see this one. (.github#242)
EXIT_PASS = 0
EXIT_FAIL = 1
EXIT_ERROR = 2
EXIT_EMPTY_SCOPE = 3      # scope resolved, selected nothing -> runner _skip `na` (.github#268)
EXIT_NOT_APPLICABLE = 4   # subject matter absent entirely   -> runner _skip na

# A routed name: 'controller#method' (snake_case controller, camelCase method,
# Settings\Foo namespaced controllers allowed).
_ROUTE_NAME_RE = re.compile(
    r"'name'\s*=>\s*'([A-Za-z][A-Za-z0-9_\\]*#[A-Za-z0-9_]+)'"
)
# A full route entry — name + url, used to recover the URL path for a route.
_ROUTE_ENTRY_RE = re.compile(
    r"\[(?P<body>[^\[\]]*?'name'\s*=>\s*'(?P<name>[A-Za-z][A-Za-z0-9_\\]*#[A-Za-z0-9_]+)'[^\[\]]*?)\]",
    re.DOTALL,
)
_ROUTE_URL_RE = re.compile(r"'url'\s*=>\s*'([^']+)'")

# public function foo( — capture name. Only public methods can be endpoints.
_PHP_PUBLIC_METHOD_RE = re.compile(
    r"^\s*(?:final\s+|abstract\s+|static\s+)*public\s+(?:static\s+)?function\s+"
    r"(?P<name>[A-Za-z_][A-Za-z0-9_]*)\s*\(",
)

_PUBLIC_AUTH_RE = re.compile(
    r"#\[(?:PublicPage|NoAdminRequired)\b|@(?:PublicPage|NoAdminRequired)\b"
)

_CONTRACT_REF_RE = re.compile(r"@contract\s+(?!exclude\b)(?P<ref>\S+)")
_CONTRACT_EXCLUDE_RE = re.compile(r"@contract\s+exclude\b[ \t]*(?P<reason>.*?)\s*$")


# ---------------------------------------------------------------------------
# Git helpers
# ---------------------------------------------------------------------------


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
    """Return {relative_path: {added_line_numbers}} from ``git diff -U0``."""
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


# ---------------------------------------------------------------------------
# Route table parsing
# ---------------------------------------------------------------------------


def _slug_for_controller(rel_path: str) -> str:
    """Derive a route-slug controller name from a controller file path.

    lib/Controller/FooController.php          -> foo
    lib/Controller/Settings/BarController.php -> Settings\\bar
    """
    short = rel_path
    short = re.sub(r"^lib/Controller/", "", short)
    short = re.sub(r"Controller\.php$", "", short)
    if "/" in short:
        ns, last = short.rsplit("/", 1)
        last = last[:1].lower() + last[1:]
        return f"{ns}\\{last}"
    return short[:1].lower() + short[1:]


def parse_routes(routes_path: Path) -> dict[str, str]:
    """Return {route-name: url-path} for every entry in appinfo/routes.php.

    route-name is the ``'controller#method'`` slug; url-path is the route's
    ``'url'`` value (or ``""`` when the entry is a resource/implicit route).
    """
    try:
        text = routes_path.read_text(encoding="utf-8")
    except OSError:
        return {}
    routes: dict[str, str] = {}
    for m in _ROUTE_ENTRY_RE.finditer(text):
        name = m.group("name")
        url_m = _ROUTE_URL_RE.search(m.group("body"))
        routes[name] = url_m.group(1) if url_m else ""
    # Catch any names the entry regex missed (multi-line entries with nested
    # brackets) — record them with an empty url.
    for m in _ROUTE_NAME_RE.finditer(text):
        routes.setdefault(m.group(1), "")
    return routes


# ---------------------------------------------------------------------------
# Controller method scanning
# ---------------------------------------------------------------------------


def _method_is_public_endpoint(lines: list[str], decl_idx: int) -> bool:
    """True if the method at ``decl_idx`` carries a PublicPage / NoAdminRequired
    attribute or docblock tag in the ~20 lines above its declaration."""
    start = max(0, decl_idx - 20)
    head = "\n".join(lines[start : decl_idx + 1])
    return bool(_PUBLIC_AUTH_RE.search(head))


def _docblock_block(lines: list[str], decl_idx: int) -> list[str]:
    """Return the /** ... */ block immediately above ``decl_idx`` (skipping PHP
    attributes + blanks), or [] when absent."""
    i = decl_idx - 1
    while i >= 0:
        stripped = lines[i].strip()
        if stripped == "" or stripped.startswith("#[") or stripped.startswith("]"):
            i -= 1
            continue
        break
    if i < 0 or "*/" not in lines[i]:
        return []
    block: list[str] = []
    while i >= 0:
        block.append(lines[i])
        if "/**" in lines[i] or "/*" in lines[i]:
            break
        i -= 1
    return block


def _contract_status(lines: list[str], decl_idx: int) -> tuple[str, str | None]:
    """Classify the docblock above ``decl_idx``:
      ("ref", None)            — has @contract <ref> (explicit pointer)
      ("excluded", reason)     — has @contract exclude <reason> (reason set)
      ("exclude_noreason", None) — bare @contract exclude
      ("none", None)           — neither
    """
    block = _docblock_block(lines, decl_idx)
    for b in block:
        if _CONTRACT_REF_RE.search(b):
            return ("ref", None)
    for b in block:
        m = _CONTRACT_EXCLUDE_RE.search(b)
        if m:
            reason = m.group("reason").strip().rstrip("*/").strip()
            return ("excluded", reason) if reason else ("exclude_noreason", None)
    return ("none", None)


def scan_new_endpoints(
    app_dir: Path, changed: dict[str, set[int]], routes: dict[str, str]
) -> list[dict]:
    """Return new public endpoints ADDED in the diff that are registered routes.

    Each dict: {ref, controller_path, method, url, contract_status, reason}.
    """
    out: list[dict] = []
    for rel, added in changed.items():
        if not rel.startswith("lib/Controller/") or not rel.endswith("Controller.php"):
            continue
        cfile = app_dir / rel
        if not cfile.is_file():
            continue
        try:
            lines = cfile.read_text(encoding="utf-8").splitlines()
        except OSError:
            continue
        slug = _slug_for_controller(rel)
        for idx, line in enumerate(lines):
            m = _PHP_PUBLIC_METHOD_RE.match(line)
            if not m:
                continue
            decl_line_no = idx + 1  # 1-based
            if decl_line_no not in added:
                continue  # method declaration not added in this PR
            method = m.group("name")
            ref = f"{slug}#{method}"
            if ref not in routes:
                continue  # not a registered route → not a public endpoint
            if not _method_is_public_endpoint(lines, idx):
                continue  # admin-only / no public attribute → out of scope
            status, reason = _contract_status(lines, idx)
            out.append(
                {
                    "ref": ref,
                    "controller_path": rel,
                    "method": method,
                    "url": routes.get(ref, ""),
                    "contract_status": status,
                    "reason": reason,
                }
            )
    return out


# ---------------------------------------------------------------------------
# Coverage scanning (Newman + PHPUnit)
# ---------------------------------------------------------------------------


def _newman_paths(app_dir: Path) -> str:
    """Concatenated text of every Newman/Postman collection under tests/integration."""
    buf: list[str] = []
    root = app_dir / "tests" / "integration"
    if not root.is_dir():
        return ""
    for p in root.rglob("*.postman_collection.json"):
        try:
            buf.append(p.read_text(encoding="utf-8"))
        except OSError:
            continue
    return "\n".join(buf)


def _phpunit_text(app_dir: Path) -> str:
    """Concatenated text of every *Test.php under tests/."""
    buf: list[str] = []
    root = app_dir / "tests"
    if not root.is_dir():
        return ""
    for p in root.rglob("*Test.php"):
        try:
            buf.append(p.read_text(encoding="utf-8"))
        except OSError:
            continue
    return "\n".join(buf)


def _url_signature(url: str) -> str:
    """Reduce a route url to a stable, placeholder-free path fragment for
    substring matching in Newman collections. ``/api/foo/{id}`` -> ``/api/foo``."""
    if not url:
        return ""
    u = url.split("?")[0]
    # Drop trailing {placeholder} segments and any segment containing one.
    parts = [seg for seg in u.split("/") if seg and "{" not in seg]
    return "/".join(parts)


def is_covered(ep: dict, newman: str, phpunit: str) -> bool:
    """True if the endpoint is covered by Newman OR PHPUnit OR a @contract ref."""
    if ep["contract_status"] in ("ref", "excluded"):
        return True
    method = ep["method"]
    sig = _url_signature(ep["url"])
    # Newman: URL path fragment present, or the method name appears as a request name.
    if newman:
        if sig and sig in newman:
            return True
        if re.search(rf'"name"\s*:\s*"[^"]*\b{re.escape(method)}\b', newman):
            return True
    # PHPUnit: method call or a clear textual reference to the controller method.
    if phpunit:
        if re.search(rf"->\s*{re.escape(method)}\s*\(", phpunit):
            return True
    return False


# ---------------------------------------------------------------------------
# Modes
# ---------------------------------------------------------------------------


def _collect(app_dir: Path, base_ref: str) -> list[dict]:
    routes_path = app_dir / "appinfo" / "routes.php"
    if not routes_path.is_file():
        return []
    routes = parse_routes(routes_path)
    changed = changed_lines(base_ref, app_dir)
    return scan_new_endpoints(app_dir, changed, routes)


def _collect_from(app_dir: Path, changed: dict[str, set[int]]) -> list[dict]:
    """``_collect`` with the line map supplied rather than derived from a diff.

    Lets run_gate decide the scope — diff or whole tree — instead of the scope
    being hardcoded to "diff" inside the collector, which is what hid 32
    uncovered endpoints on openconnector behind a PASS (.github#242).
    """
    routes_path = app_dir / "appinfo" / "routes.php"
    if not routes_path.is_file():
        return []
    return scan_new_endpoints(app_dir, changed, parse_routes(routes_path))


def _all_controller_lines(app_dir: Path) -> dict[str, set[int]]:
    """Every line of every controller — the full-tree equivalent of a diff.

    ``scan_new_endpoints`` asks "was this method's declaration line ADDED?".
    A full-tree audit answers yes for every line, which makes every registered
    public endpoint a candidate exactly as it would be on the commit that first
    introduced it.
    """
    out: dict[str, set[int]] = {}
    cdir = app_dir / "lib" / "Controller"
    if not cdir.is_dir():
        return out
    for cfile in cdir.rglob("*Controller.php"):
        try:
            n = len(cfile.read_text(encoding="utf-8").splitlines())
        except OSError:
            continue
        out[str(cfile.relative_to(app_dir))] = set(range(1, n + 1))
    return out


def run_gate(app_dir: Path) -> int:
    """Audit wire-contract coverage. Returns a status; the COUNT is printed.

    SCOPE IS THE CALLER'S DECISION, AND IT IS NOT DEFAULTED (.github#242)
    --------------------------------------------------------------------
    This used to diff against ``HYDRA_GATE_BASE_REF`` UNCONDITIONALLY, with the
    ref defaulted to ``origin/development`` even when the caller had asked for
    no scoping at all. On a full-tree run the diff came back empty and the gate
    printed ``PASS — no new public endpoints in diff`` having opened nothing.

    Because the narrowing happened HERE — inside the helper, below the runner's
    base resolution — the runner could not tell a full-tree request had been
    reduced to nothing, and because the verdict was PASS rather than a skip,
    ``--require-full-coverage`` could not see it either.

    Measured on openconnector 2026-08-08: **PASS** as the runner invoked it,
    **32** public endpoints with no contract test against the root commit.
    """
    base_ref = os.environ.get("HYDRA_GATE_BASE_REF")

    if not (app_dir / "appinfo" / "routes.php").is_file():
        print(
            f"[gate-{GATE_NUM}] contract-coverage: NOT APPLICABLE — no "
            f"appinfo/routes.php, so this app exposes no routed endpoint whose "
            f"wire contract could be tested."
        )
        return EXIT_NOT_APPLICABLE

    if base_ref:
        changed = changed_lines(base_ref, app_dir)
        # The scope that matters is CONTROLLER files, not "any file". A diff of
        # a hundred docs commits still opens no controller, and reporting PASS
        # for it claims a wire contract was checked when none was read.
        changed = {
            rel: lines for rel, lines in changed.items()
            if rel.startswith("lib/Controller/") and rel.endswith("Controller.php")
        }
        if not changed:
            print(
                f"[gate-{GATE_NUM}] contract-coverage: EMPTY SCOPE — "
                f"diff-scoped against '{base_ref}' and NO controller file was "
                f"touched, so no endpoint was inspected. Wire-contract coverage "
                f"is UNVERIFIED by this run. This is not a pass. Audit the whole "
                f"tree by running without HYDRA_GATE_BASE_REF, or with "
                f"--scope-to-diff --base <root-commit>."
            )
            return EXIT_EMPTY_SCOPE
        endpoints = _collect_from(app_dir, changed)
    else:
        all_lines = _all_controller_lines(app_dir)
        if not all_lines:
            print(
                f"[gate-{GATE_NUM}] contract-coverage: NOT APPLICABLE — "
                f"appinfo/routes.php exists but there is no "
                f"lib/Controller/*Controller.php for a route to reach."
            )
            return EXIT_NOT_APPLICABLE
        endpoints = _collect_from(app_dir, all_lines)

    if not endpoints:
        scope_desc = (
            f"the diff against '{base_ref}'" if base_ref else "the whole tree"
        )
        print(
            f"[gate-{GATE_NUM}] contract-coverage: PASS — "
            f"{scope_desc} contains no new public endpoint"
        )
        return EXIT_PASS
    newman = _newman_paths(app_dir)
    phpunit = _phpunit_text(app_dir)
    findings: list[str] = []
    for ep in endpoints:
        if ep["contract_status"] == "exclude_noreason":
            findings.append(f"{ep['ref']} — @contract exclude without reason (reason required)")
            continue
        if not is_covered(ep, newman, phpunit):
            findings.append(
                f"{ep['ref']} — new public endpoint (url={ep['url'] or '?'}) "
                f"missing Newman/PHPUnit contract test or @contract exclude"
            )
    for line in sorted(set(findings)):
        print(line)
    count = len(set(findings))
    if count == 0:
        print(
            f"[gate-{GATE_NUM}] contract-coverage: PASS — "
            f"{len(endpoints)} new endpoint(s), all covered"
        )
        return EXIT_PASS
    print(
        f"[gate-{GATE_NUM}] contract-coverage: FAIL — "
        f"{count} new public endpoint(s) without a contract test"
    )
    # A STATUS, not the count. Returning the count meant 256 findings exited 0
    # and read as PASS — the same byte-width bug gate-19 shipped (.github#209).
    # The honest number is the one printed above, and the runner reads it there.
    return EXIT_FAIL


def run_report(app_dir: Path) -> int:
    base_ref = os.environ.get("HYDRA_GATE_BASE_REF", "origin/development")
    endpoints = _collect(app_dir, base_ref)
    newman = _newman_paths(app_dir)
    phpunit = _phpunit_text(app_dir)
    covered = uncovered = excluded = 0
    rows = []
    for ep in endpoints:
        if ep["contract_status"] in ("ref", "excluded"):
            excluded += 1
            state = "excluded"
        elif is_covered(ep, newman, phpunit):
            covered += 1
            state = "covered"
        else:
            uncovered += 1
            state = "uncovered"
        rows.append({"ref": ep["ref"], "url": ep["url"], "state": state})
    out = {
        "mode": "report",
        "gate": GATE_NUM,
        "app": app_dir.name,
        "totals": {
            "new_endpoints": len(endpoints),
            "covered": covered,
            "excluded": excluded,
            "uncovered": uncovered,
        },
        "endpoints": rows,
    }
    print(json.dumps(out, indent=2))
    return 0


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
