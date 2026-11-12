#!/usr/bin/env python3
"""Merge gate for one fleet PR head, run before a GREEN PR is merged into development.

    python3 bin/merge_gate.py <app-clone> <pr-number> [<head-sha>] [--base development]

Three checks, each on the PR head against its merge base with the base branch:
  1. SBOM: a head that changes composer.lock or package-lock.json must bring a root
     sbom.cdx.json that matches them (bin/regen-sbom.sh --check, next to this file).
  2. Checker floor: no Common Ground checker criterion or score may get worse against the
     .checker-floor.json committed at the merge base (checker_floor.py compare).
  3. Maintainability floor: no rating may drop more than 0.01 against the merge base
     (floor_check.py compare).
checker_floor.py and floor_check.py come from ConductionNL/.github main (checker_floor.py
from its feat/checker-floor branch while main lacks it), fetched over git, no API calls.

Last line: `GATE OK|RED|UNMEASURED <reason>`. Exit 0 OK (merge), 1 RED (record it, do not
merge), 2 UNMEASURED (do not merge, record why). A gate that cannot run never passes.
"""
import argparse, os, subprocess, sys, tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
CACHE = os.path.expanduser("~/.cache/hydra-gates")
DOTGITHUB = os.path.join(CACHE, "dotgithub")
LIB = "hydra-gates/scripts/lib"
REGEN = os.path.join(HERE, "regen-sbom.sh")
LOCKFILES = ("composer.lock", "package-lock.json")


def run(cmd, cwd=None):
    return subprocess.run(cmd, cwd=cwd, capture_output=True, text=True)


def git(cwd, *a):
    return run(["git", *a], cwd=cwd)


def tail(p):
    lines = (p.stdout + p.stderr).strip().splitlines()
    return lines, (lines[-1] if lines else "")


def tools():
    """(floor_check.py, checker_floor.py) paths, either None when unavailable."""
    if not os.path.isdir(os.path.join(DOTGITHUB, ".git")):
        os.makedirs(CACHE, exist_ok=True)
        git(CACHE, "clone", "-q", "--depth", "1", "--filter=blob:none", "--sparse", "--no-checkout",
            "https://github.com/ConductionNL/.github", DOTGITHUB)
        git(DOTGITHUB, "sparse-checkout", "set", LIB)
    if git(DOTGITHUB, "fetch", "-q", "--depth", "1", "origin", "main").returncode == 0:
        git(DOTGITHUB, "checkout", "-q", "--detach", "FETCH_HEAD")
    floor = os.path.join(DOTGITHUB, LIB, "floor_check.py")
    floor = floor if os.path.isfile(floor) else None
    checker = os.path.join(DOTGITHUB, LIB, "checker_floor.py")
    if os.path.isfile(checker):
        return floor, checker
    side = os.path.join(CACHE, "checker-floor-branch")
    os.makedirs(side, exist_ok=True)
    if git(DOTGITHUB, "fetch", "-q", "--depth", "1", "origin", "feat/checker-floor").returncode:
        return floor, None
    for name in ("checker_floor.py", "checker_scoring.json"):
        r = git(DOTGITHUB, "show", f"FETCH_HEAD:{LIB}/{name}")
        if r.returncode:
            return floor, None
        with open(os.path.join(side, name), "w") as fh:
            fh.write(r.stdout)
    return floor, os.path.join(side, "checker_floor.py")


def sbom(head, base):
    names = git(head, "diff", "--name-only", f"{base}...HEAD").stdout.split()
    locks = [f for f in LOCKFILES if f in names]
    if not locks:
        return "OK", "no lockfile change"
    if "sbom.cdx.json" not in names:
        return "RED", f"sbom: {', '.join(locks)} changed without sbom.cdx.json"
    p = run(["bash", REGEN, "--check", head])
    _, last = tail(p)
    if p.returncode == 0:
        return "OK", last
    if p.returncode == 1:
        return "RED", "sbom: sbom.cdx.json does not match " + ", ".join(locks)
    return "UNMEASURED", f"regen-sbom exit {p.returncode}: {last}"


def checker(tool, head, base):
    p = run([sys.executable, tool, "compare", head, "--base-ref", base])
    lines, last = tail(p)
    if p.returncode in (0, 3, 4):
        return "OK", last
    if p.returncode == 1:
        worse = "; ".join(l.strip()[6:] for l in lines if l.strip().startswith("WORSE "))
        return "RED", "checker floor: " + (worse or last or "worse")
    return "UNMEASURED", f"checker_floor exit {p.returncode}: {last}"


def floor(tool, head, base):
    p = run([sys.executable, tool, "compare", head, "--base-ref", base])
    lines, last = tail(p)
    if p.returncode in (0, 4):
        return "OK", last
    if p.returncode == 1:
        drops = "; ".join(l.strip()[5:] for l in lines if l.strip().startswith("DROP "))
        return "RED", "floor: " + (drops or last or "lowered")
    return "UNMEASURED", f"floor_check exit {p.returncode}: {last}"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("clone")
    ap.add_argument("pr")
    ap.add_argument("sha", nargs="?")
    ap.add_argument("--base", default="development")
    a = ap.parse_args()
    c = os.path.abspath(a.clone)
    if git(c, "fetch", "-q", "origin", f"+refs/heads/{a.base}:refs/remotes/origin/{a.base}",
           f"+refs/pull/{a.pr}/head:refs/gate/{a.pr}").returncode:
        return "UNMEASURED", "fetch failed"
    head_sha = git(c, "rev-parse", f"refs/gate/{a.pr}").stdout.strip()
    if a.sha and not head_sha.startswith(a.sha[:12]):
        return "UNMEASURED", f"head moved to {head_sha[:10]}"
    base = git(c, "rev-parse", f"origin/{a.base}").stdout.strip()
    floor_tool, checker_tool = tools()
    if not floor_tool or not checker_tool:
        return "UNMEASURED", "floor_check.py or checker_floor.py not available"
    with tempfile.TemporaryDirectory(prefix="merge-gate-") as tmp:
        head = os.path.join(tmp, "head")
        if git(tmp, "clone", "-q", "--shared", "--no-checkout", c, head).returncode or \
                git(head, "checkout", "-q", "--detach", head_sha).returncode:
            return "UNMEASURED", f"cannot check out {head_sha[:10]}"
        git(head, "fetch", "-q", c, f"+refs/remotes/origin/{a.base}:refs/remotes/origin/{a.base}")
        for check in (lambda: sbom(head, base), lambda: checker(checker_tool, head, base),
                      lambda: floor(floor_tool, head, base)):
            v, why = check()
            if v != "OK":
                return v, why
    return "OK", "sbom, checker floor and maintainability floor hold"


if __name__ == "__main__":
    verdict, reason = main()
    print(f"GATE {verdict} {reason}")
    sys.exit({"OK": 0, "RED": 1}.get(verdict, 2))
