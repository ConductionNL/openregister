#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""The modularity ratchet of the backend, in the functional areas of sigrid.components.yaml.

    python3 tools/sig/modularity_check.py --check            # exit 1 when a number differs from the baseline
    python3 tools/sig/modularity_check.py --write-baseline   # record today's numbers
    python3 tools/sig/modularity_check.py                    # print the numbers

It reads the module graph the way tools/sig/check_sig_maintainability.py does and records, in
tools/sig/modularity-baseline.json:

- `exposed_loc`: per area, the lines of the files another area references;
- `kernel_loc`: the size of Kernel, so nothing is hidden by moving it there;
- `violation_degree`: the scorer's violation degree between the areas;
- `against_order`: every file reference from an area to an area of a higher rank in
  tools/sig/area-order.json (hydra ADR-119 Decision 8), as "lib/A.php -> lib/B.php".

--check fails when a number rises or a reference against the order is new. It also fails when a
number went down or a listed reference is gone, so the change that lowers it records that in the
same change: run --write-baseline and commit the file.
"""
import argparse
import collections
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
APP = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, HERE)
import check_sig_maintainability as tool  # noqa: E402

BASELINE = os.path.join(HERE, "modularity-baseline.json")
ORDER_FILE = os.path.join(HERE, "area-order.json")
DEGREE_TOLERANCE = 0.00005


def read_order(path=ORDER_FILE):
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)["order"]


def against_order(deps, comp, order):
    """Sorted "src -> dst" pairs where dst's area ranks above src's area."""
    rank = {area: i for i, area in enumerate(order)}
    out = []
    for src, targets in deps.items():
        a = comp(src)
        for dst in targets:
            b = comp(dst)
            if a != b and rank.get(b, -1) > rank.get(a, len(order)):
                out.append(f"{src} -> {dst}")
    return sorted(out)


def exposure(deps, comp, loc):
    """({area: exposed lines}, {area: lines})."""
    incoming = collections.defaultdict(set)
    for src, targets in deps.items():
        for t in targets:
            incoming[t].add(src)
    exposed, size = collections.Counter(), collections.Counter()
    for rel in deps:
        size[comp(rel)] += loc[rel]
        if any(comp(s) != comp(rel) for s in incoming[rel]):
            exposed[comp(rel)] += loc[rel]
    return exposed, size


def measure(app=APP, order=None):
    order = order or read_order()
    saved = os.environ.get("SIG_COMPONENTS_YAML")
    os.environ["SIG_COMPONENTS_YAML"] = os.path.join(app, "sigrid.components.yaml")
    try:
        files = {rel: lang for rel, lang in tool.production_files(app, tool.Scope(app)).items() if lang == "php"}
        deps = tool.php_dependencies(app, files)
        _, comp = tool.component_of_factory(sorted(deps), app)
    finally:
        if saved is None:
            os.environ.pop("SIG_COMPONENTS_YAML", None)
        else:
            os.environ["SIG_COMPONENTS_YAML"] = saved
    loc = {m: len(tool.code_lines(tool.read_text(os.path.join(app, m)), "php")) for m in deps}
    exposed, size = exposure(deps, comp, loc)
    lines = collections.Counter()
    for src, targets in deps.items():
        for dst in targets:
            if comp(src) != comp(dst):
                lines[(comp(src), comp(dst))] += 1
    degree, _, _ = tool.violation_degree(dict(lines))
    return {
        "exposed_loc": {area: exposed.get(area, 0) for area in order},
        "kernel_loc": size.get("Kernel", 0),
        "total_loc": sum(size.values()),
        "violation_degree": round(degree, 4),
        "against_order": against_order(deps, comp, order),
    }


def compare(base, now):
    """Messages for every difference; empty when the numbers match the baseline."""
    out = []
    for area in sorted(set(base["exposed_loc"]) | set(now["exposed_loc"])):
        old, new = base["exposed_loc"].get(area, 0), now["exposed_loc"].get(area, 0)
        if new > old:
            out.append(f"exposed lines of {area} rose from {old} to {new}")
        elif new < old:
            out.append(f"exposed lines of {area} went down from {old} to {new}: lower the baseline")
    if now["kernel_loc"] > base["kernel_loc"]:
        out.append(f"Kernel grew from {base['kernel_loc']} to {now['kernel_loc']} lines")
    elif now["kernel_loc"] < base["kernel_loc"]:
        out.append(f"Kernel shrank from {base['kernel_loc']} to {now['kernel_loc']} lines: lower the baseline")
    if now["violation_degree"] > base["violation_degree"] + DEGREE_TOLERANCE:
        out.append(f"violation degree rose from {base['violation_degree']} to {now['violation_degree']}")
    elif now["violation_degree"] < base["violation_degree"] - DEGREE_TOLERANCE:
        out.append(f"violation degree went down from {base['violation_degree']} to {now['violation_degree']}: lower the baseline")
    old_refs, new_refs = set(base["against_order"]), set(now["against_order"])
    for ref in sorted(new_refs - old_refs):
        out.append(f"new reference against the area order: {ref}")
    gone = len(old_refs - new_refs)
    if gone:
        out.append(f"{gone} reference(s) against the area order are gone: lower the baseline")
    return out


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--check", action="store_true")
    ap.add_argument("--write-baseline", action="store_true")
    ap.add_argument("--baseline", default=BASELINE)
    args = ap.parse_args(argv)
    now = measure()
    summary = (f"exposed {sum(now['exposed_loc'].values())} of {now['total_loc']} lines, Kernel {now['kernel_loc']}, "
               f"violation degree {now['violation_degree']}, {len(now['against_order'])} references against the order")
    if args.write_baseline:
        with open(args.baseline, "w", encoding="utf-8") as fh:
            json.dump(now, fh, indent=1, sort_keys=True)
            fh.write("\n")
        print(f"wrote {os.path.relpath(args.baseline, APP)}: {summary}")
        return 0
    if args.check:
        with open(args.baseline, encoding="utf-8") as fh:
            problems = compare(json.load(fh), now)
        for p in problems:
            print(p)
        if problems:
            print("modularity check FAILED. After a deliberate improvement: "
                  "python3 tools/sig/modularity_check.py --write-baseline")
            return 1
        print(f"modularity check passed: {summary}")
        return 0
    print(summary)
    return 0


if __name__ == "__main__":
    sys.exit(main())
