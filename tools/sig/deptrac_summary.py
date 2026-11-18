#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Summarise a deptrac JSON report per pair of layers.

    python3 tools/sig/deptrac_summary.py REPORT.json [--out SUMMARY.json]
    python3 tools/sig/deptrac_summary.py REPORT.json --check BASELINE.json
    python3 tools/sig/deptrac_summary.py REPORT.json --write-baseline BASELINE.json

deptrac reports one violation per occurrence: every line on which a class names a class of a
layer it may not use. This summary counts those occurrences and the distinct class pairs per
"from -> to" layer pair, and writes the file paths relative to lib/ so a report made in a
temporary directory compares with one made in the working tree. The table goes to stdout.

A baseline lists every violating class pair as "A -> B: ClassX -> ClassY". --check exits 1 when a
pair is new. A listed pair that is gone is reported, so the change that removes it can lower the
baseline with --write-baseline.
"""
import collections
import json
import re
import sys

MESSAGE = re.compile(r"^(\S+) must not depend on (\S+) \((\w+) on (\w+)\)$")


def summarise(report):
    occurrences = collections.Counter()
    pairs = collections.defaultdict(set)
    for _path, entry in report.get("files", {}).items():
        for message in entry.get("messages", []):
            m = MESSAGE.match(message.get("message", ""))
            if m:
                # deptrac says "X must not depend on Y (A on B)": layer A depends on layer B.
                key = f"{m.group(3)} -> {m.group(4)}"
                occurrences[key] += 1
                pairs[key].add((m.group(1), m.group(2)))
    totals = {k: v for k, v in report.get("Report", {}).items()}
    rows = [{"layers": k, "occurrences": occurrences[k], "class_pairs": len(pairs[k]),
             "examples": [f"{a} -> {b}" for a, b in sorted(pairs[k])[:3]]}
            for k in sorted(occurrences, key=lambda k: -occurrences[k])]
    return {"report": totals, "class_pairs": sum(len(p) for p in pairs.values()), "by_layers": rows}


def violating_pairs(report):
    """Sorted "A -> B: X -> Y" strings, one per distinct class pair in violation."""
    out = set()
    for _path, entry in report.get("files", {}).items():
        for message in entry.get("messages", []):
            m = MESSAGE.match(message.get("message", ""))
            if m:
                out.add(f"{m.group(3)} -> {m.group(4)}: {m.group(1)} -> {m.group(2)}")
    return sorted(out)


def compare(baseline, pairs):
    """(failures for new pairs, notices for pairs that are gone)."""
    old, new = set(baseline), set(pairs)
    fail = [f"new deptrac violation: {p}" for p in sorted(new - old)]
    note = [f"{len(old - new)} deptrac violation(s) are gone: lower the baseline"] if old - new else []
    return fail, note


def table(summary):
    out = ["| From -> to | Occurrences | Class pairs |", "|---|---|---|"]
    out += [f"| {r['layers']} | {r['occurrences']} | {r['class_pairs']} |" for r in summary["by_layers"]]
    rep = summary["report"]
    out += ["", f"Violations {rep.get('Violations', 0)}, allowed {rep.get('Allowed', 0)}, "
                f"uncovered {rep.get('Uncovered', 0)}, distinct class pairs in violation {summary['class_pairs']}."]
    return "\n".join(out) + "\n"


def main(argv):
    if not argv:
        print(__doc__)
        return 2
    report = json.load(open(argv[0], encoding="utf-8"))
    summary = summarise(report)
    if "--write-baseline" in argv:
        with open(argv[argv.index("--write-baseline") + 1], "w", encoding="utf-8") as fh:
            json.dump(violating_pairs(report), fh, indent=0)
            fh.write("\n")
    if "--check" in argv:
        problems, notices = compare(json.load(open(argv[argv.index("--check") + 1], encoding="utf-8")),
                                    violating_pairs(report))
        for p in problems + notices:
            print(p)
        if problems:
            print("deptrac check FAILED. After a deliberate improvement: tools/sig/deptrac.sh areas --write-baseline")
            return 1
        print(f"deptrac check passed: {summary['class_pairs']} class pairs in violation, as recorded")
        return 0
    if "--out" in argv:
        with open(argv[argv.index("--out") + 1], "w", encoding="utf-8") as fh:
            json.dump(summary, fh, indent=1, sort_keys=True)
    sys.stdout.write(table(summary))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
