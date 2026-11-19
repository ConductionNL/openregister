#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""The fleet surface of OpenRegister: what other apps use besides its published contract.

    tools/sig/fleet_checkout.sh DIR                      # lib/-only checkouts of the fleet apps
    python3 tools/sig/fleet_surface.py DIR               # print the numbers
    python3 tools/sig/fleet_surface.py DIR --write-baseline
    python3 tools/sig/fleet_surface.py DIR --check

hydra ADR-119 Decision 15. Other apps should depend on OpenRegister's published contract
(`OCA\\OpenRegister\\Contract\\`, ADR-084) and on its events (Decision 11). Every other OpenRegister
class an app names is a reference to a concrete class. The script reads each app's lib/ under DIR,
strips comments, and records every distinct pair "app/lib/File.php -> OCA\\OpenRegister\\X\\Y" found
in a `use` import, a fully qualified name or a class-name string.

--check fails when a pair is new against tools/sig/fleet-surface-baseline.json. A pair that is gone
is reported; record it with --write-baseline. The checkouts are not in CI, so the check runs where
they are (tools/sig/fleet_checkout.sh makes them from each app's development branch).
"""
import argparse
import collections
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
APP = os.path.dirname(os.path.dirname(HERE))
BASELINE = os.path.join(HERE, "fleet-surface-baseline.json")
ALLOWED = ("Contract\\", "Event\\")
NAME = re.compile(r"OCA\\{1,2}OpenRegister\\{1,2}((?:[A-Za-z_][A-Za-z0-9_]*\\{1,2})*[A-Za-z_][A-Za-z0-9_]*)")
COMMENTS = re.compile(r"/\*.*?\*/|//[^\n]*|#(?!\[)[^\n]*", re.S)


def references(text):
    """The OpenRegister class names a PHP file names outside comments, without the allowed ones."""
    names = set()
    for match in NAME.finditer(COMMENTS.sub("", text)):
        rel = match.group(1).replace("\\\\", "\\")
        if not rel.startswith(ALLOWED):
            names.add("OCA\\OpenRegister\\" + rel)
    return names


def measure(root):
    """Sorted "app/lib/File.php -> OCA\\OpenRegister\\..." pairs for every app directory under root."""
    pairs = []
    for app in sorted(os.listdir(root)):
        lib = os.path.join(root, app, "lib")
        if app == "openregister" or not os.path.isdir(lib):
            continue
        for directory, _dirs, files in os.walk(lib):
            for name in files:
                if not name.endswith(".php"):
                    continue
                path = os.path.join(directory, name)
                rel = os.path.relpath(path, root).replace(os.sep, "/")
                with open(path, encoding="utf-8", errors="replace") as fh:
                    pairs += [f"{rel} -> {cls}" for cls in references(fh.read())]
    return sorted(set(pairs))


def summary(pairs):
    """({app: pairs}, {class: pairs})."""
    by_app, by_class = collections.Counter(), collections.Counter()
    for pair in pairs:
        src, cls = pair.split(" -> ")
        by_app[src.split("/")[0]] += 1
        by_class[cls] += 1
    return by_app, by_class


def compare(baseline, pairs):
    """(failures for new pairs, notices for pairs that are gone)."""
    old, new = set(baseline), set(pairs)
    fail = [f"new reference to a concrete OpenRegister class: {p}" for p in sorted(new - old)]
    note = [f"{len(old - new)} fleet reference(s) are gone: lower the baseline"] if old - new else []
    return fail, note


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("root", help="directory holding one checkout per fleet app")
    ap.add_argument("--check", action="store_true")
    ap.add_argument("--write-baseline", action="store_true")
    ap.add_argument("--baseline", default=BASELINE)
    args = ap.parse_args(argv)
    pairs = measure(args.root)
    by_app, by_class = summary(pairs)
    print(f"{len(pairs)} references from {len(by_app)} apps to {len(by_class)} concrete OpenRegister classes")
    for cls, n in by_class.most_common(5):
        print(f"  {n:5d}  {cls}")
    if args.write_baseline:
        with open(args.baseline, "w", encoding="utf-8") as fh:
            json.dump(pairs, fh, indent=0)
            fh.write("\n")
        return 0
    if args.check:
        with open(args.baseline, encoding="utf-8") as fh:
            fail, note = compare(json.load(fh), pairs)
        for line in fail + note:
            print(line)
        return 1 if fail else 0
    return 0


if __name__ == "__main__":
    sys.exit(main())
