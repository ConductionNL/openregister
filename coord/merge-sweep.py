#!/usr/bin/env python3
"""Merge the PRs cloud lanes queued as ready in COORDINATOR-QUEUE.md on the state branch.

Cloud sessions may not merge, so lanes write a line such as
  "<UTC> b-humaniq humaniq#1332 merge: ... local green ..."
and the coordinator merges. This sweep does that with the standing rules:
  - only ConductionNL repos, never zaakafhandelapp or Softwarecatalogus;
  - never openregister (main lands only through the integrator);
  - base must be development, or main for nextcloud-vue;
  - never chore(release) promotions, never PRs by people other than the owner or Dependabot;
  - a conflicting PR gets one comment handing it back; nothing is closed;
  - PRs listed in hold.txt (held by a decision) are never touched.
Handled PR numbers are remembered in handled.txt beside this script.

Usage: merge-sweep.py [--dry-run]
"""
import json
import os
import re
import subprocess
import sys

STATE = os.path.expanduser("~/sig-lanes/il3/state")
HANDLED = os.path.join(os.path.dirname(os.path.abspath(__file__)), "handled.txt")
HOLD = os.path.join(os.path.dirname(os.path.abspath(__file__)), "hold.txt")
SKIP_REPOS = {"zaakafhandelapp", "Softwarecatalogus", "softwarecatalogus", "openregister"}
AUTHORS = {"rubenvdlinde", "app/dependabot", "dependabot[bot]"}
LINE = re.compile(r"(?:ConductionNL/)?([A-Za-z][\w.-]*)#(\d+)((?:\s*(?:,|and|\+)?\s*#\d+)*)\s+merge\b")
DRY = "--dry-run" in sys.argv


def gh(*args):
    return subprocess.run(["gh", *args], capture_output=True, text=True)


def queued():
    subprocess.run(["git", "-C", STATE, "pull", "-q", "--no-rebase"], capture_output=True)
    found = []
    with open(os.path.join(STATE, "COORDINATOR-QUEUE.md")) as fh:
        for line in fh:
            if "question" in line.lower() or "for the or integrator" in line.lower():
                continue
            for m in LINE.finditer(line):
                repo = m.group(1)
                for n in [m.group(2), *re.findall(r"#(\d+)", m.group(3))]:
                    found.append((repo, int(n)))
    seen, out = set(), []
    for item in found:
        if item not in seen:
            seen.add(item)
            out.append(item)
    return out


def main():
    handled = set()
    if os.path.exists(HANDLED):
        handled = {l.strip() for l in open(HANDLED) if l.strip()}
    if os.path.exists(HOLD):
        hold = {l.split()[0] for l in open(HOLD) if l.strip() and not l.startswith("#")}
    else:
        hold = set()
    report = []
    for repo, n in queued():
        key = f"{repo}#{n}"
        if key in handled or key in hold or repo in SKIP_REPOS:
            continue
        r = gh("pr", "view", str(n), "-R", f"ConductionNL/{repo}", "--json",
               "state,isDraft,baseRefName,title,author,mergeable")
        if r.returncode != 0:
            report.append(f"{key}: not found")
            handled.add(key)
            continue
        pr = json.loads(r.stdout)
        base_ok = pr["baseRefName"] == "development" or (repo == "nextcloud-vue" and pr["baseRefName"] == "main")
        if pr["state"] != "OPEN":
            report.append(f"{key}: already {pr['state'].lower()}")
            handled.add(key)
            continue
        if not base_ok or pr["title"].startswith("chore(release)") or pr["author"]["login"] not in AUTHORS:
            report.append(f"{key}: skipped (base {pr['baseRefName']}, author {pr['author']['login']})")
            handled.add(key)
            continue
        if DRY:
            report.append(f"{key}: would merge ({pr['mergeable']})")
            continue
        if pr["isDraft"]:
            gh("pr", "ready", str(n), "-R", f"ConductionNL/{repo}")
        m = gh("pr", "merge", str(n), "-R", f"ConductionNL/{repo}", "--squash", "--admin")
        if m.returncode != 0:
            api = gh("api", "-X", "PUT", f"repos/ConductionNL/{repo}/pulls/{n}/merge", "-f", "merge_method=squash")
            if api.returncode != 0:
                if "conflict" in (m.stderr + api.stdout + api.stderr).lower():
                    if f"{key} commented" not in handled:
                        gh("pr", "comment", str(n), "-R", f"ConductionNL/{repo}", "-b",
                           "Ready to land, but it conflicts with development now. Merge development into this branch and resolve; the coordinator merges it on the next sweep.")
                        handled.add(f"{key} commented")
                    report.append(f"{key}: conflicts, handed back")
                    continue
                report.append(f"{key}: merge failed: {(m.stderr or api.stdout)[:120].strip()}")
                continue
        report.append(f"{key}: merged")
        handled.add(key)
    if not DRY:
        with open(HANDLED, "w") as fh:
            fh.write("\n".join(sorted(handled)) + "\n")
    print("\n".join(report) or "nothing queued")


if __name__ == "__main__":
    main()
