#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Verification for manifest_diff_scope.py (gate-53 ADR-020 scoping).

Builds a throwaway git repo with a base manifest and a fragment, commits it,
then edits ONE page and asserts that only that page's token is emitted. The
reverse direction is asserted too: with nothing edited the token set is empty,
and with an untracked new fragment the helper returns ``ALL``.

Why the reverse direction matters here specifically: this helper's whole job is
to make a set SMALLER. A bug that makes it emit nothing would make every gate-53
finding "pre-existing" and turn the gate off entirely — and the symptom would be
an unbroken run of green PRs. So every "it narrowed correctly" assertion is
paired with one proving the narrowing can still produce a hit.

Run: python3 scripts/lib/test_manifest_diff_scope.py   (exit 0 = pass)
"""

import json
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
HELPER = os.path.join(HERE, "manifest_diff_scope.py")

_fails = []


def ok(cond, label):
    print(("PASS — " if cond else "FAIL — ") + label)
    if not cond:
        _fails.append(label)


def git(repo, *args):
    return subprocess.run(
        ["git", "-C", repo] + list(args),
        capture_output=True, text=True, check=False,
    )


def page(pid, title="T"):
    return {"id": pid, "route": "/" + pid.lower(), "type": "index", "title": title}


def write_json(path, obj):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(obj, fh, indent=2)
        fh.write("\n")


def run_helper(repo, base, *files):
    env = dict(os.environ)
    env["HYDRA_GATE_BASE_REF"] = base
    proc = subprocess.run(
        [sys.executable, HELPER] + list(files),
        cwd=repo, capture_output=True, text=True, check=False, env=env,
    )
    return sorted(l for l in proc.stdout.split("\n") if l.strip())


def main():
    repo = tempfile.mkdtemp(prefix="gate53-scope-")
    try:
        git(repo, "init", "-q", ".")
        git(repo, "config", "user.email", "ci@example.invalid")
        git(repo, "config", "user.name", "hydra-gates ci")

        base_manifest = {
            "$schema": "x",
            "version": "1.0.0",
            "menu": [
                {"id": "Alpha", "label": "Alpha", "route": "Alpha"},
                {"id": "Group", "label": "Group", "children": [
                    {"id": "Nested", "label": "Nested", "route": "Nested"},
                ]},
            ],
            "pages": [page("Alpha"), page("Beta"), page("Gamma")],
        }
        write_json(os.path.join(repo, "src", "manifest.json"), base_manifest)
        write_json(os.path.join(repo, "src", "manifest.d", "10-extra.json"),
                   {"pages": [page("Delta")]})
        git(repo, "add", "-A")
        git(repo, "commit", "-qm", "base")
        base_sha = git(repo, "rev-parse", "HEAD").stdout.strip()

        # --- nothing changed --------------------------------------------------
        tokens = run_helper(repo, base_sha, "src/manifest.json")
        ok(tokens == [], "unchanged manifest → no tokens (nothing is in scope)")

        # --- one page edited --------------------------------------------------
        base_manifest["pages"][1]["title"] = "Beta renamed"
        write_json(os.path.join(repo, "src", "manifest.json"), base_manifest)
        git(repo, "commit", "-aqm", "touch Beta")
        tokens = run_helper(repo, base_sha, "src/manifest.json")
        ok("page:Beta" in tokens, "the edited page IS in scope")
        ok("page:Alpha" not in tokens and "page:Gamma" not in tokens,
           "untouched sibling pages are NOT in scope")

        # --- a nested menu child ---------------------------------------------
        base_manifest["menu"][1]["children"][0]["label"] = "Nested renamed"
        write_json(os.path.join(repo, "src", "manifest.json"), base_manifest)
        git(repo, "commit", "-aqm", "touch nested menu child")
        tokens = run_helper(repo, base_sha, "src/manifest.json")
        ok("menu:Nested" in tokens, "an edited nested menu child IS in scope")
        ok("menu:Alpha" not in tokens, "an untouched top-level menu entry is NOT in scope")

        # --- a fragment, not the base ----------------------------------------
        frag_path = os.path.join(repo, "src", "manifest.d", "10-extra.json")
        write_json(frag_path, {"pages": [page("Delta", "Delta renamed")]})
        git(repo, "commit", "-aqm", "touch Delta in a fragment")
        tokens = run_helper(repo, base_sha, "src/manifest.d/10-extra.json")
        ok("page:Delta" in tokens, "a page edited inside a FRAGMENT is in scope")

        # --- menu-layout is whole-menu ---------------------------------------
        write_json(os.path.join(repo, "src", "menu-layout.json"), {"removals": ["Alpha"]})
        git(repo, "add", "-A")
        git(repo, "commit", "-qm", "add menu-layout")
        tokens = run_helper(repo, base_sha, "src/menu-layout.json")
        ok(tokens == ["ALLMENU"],
           "menu-layout.json → ALLMENU (relocations/removals restructure the whole menu)")

        # --- unknowable scope → ALL -------------------------------------------
        write_json(os.path.join(repo, "src", "manifest.d", "20-new.json"),
                   {"pages": [page("Epsilon")]})
        tokens = run_helper(repo, base_sha, "src/manifest.d/20-new.json")
        ok(tokens == ["ALL"],
           "a manifest input untracked at base → ALL (fail toward enforcement)")

        tokens = run_helper(repo, base_sha, "lib/Settings/fixture_register.json")
        ok(tokens == ["ALL"],
           "a changed register JSON → ALL (a register edit can orphan any page)")

        # --- no base ref at all ------------------------------------------------
        proc = subprocess.run(
            [sys.executable, HELPER, "src/manifest.json"],
            cwd=repo, capture_output=True, text=True, check=False,
            env={k: v for k, v in os.environ.items() if k != "HYDRA_GATE_BASE_REF"},
        )
        ok(proc.stdout.strip() == "ALL", "no HYDRA_GATE_BASE_REF → ALL (a full-repo run scopes nothing)")
    finally:
        shutil.rmtree(repo, ignore_errors=True)

    print()
    if _fails:
        print(f"{len(_fails)} manifest_diff_scope assertion(s) FAILED")
        return 1
    print("ALL manifest_diff_scope assertions PASSED")
    return 0


if __name__ == "__main__":
    sys.exit(main())
