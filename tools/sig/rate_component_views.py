#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Estimated modularity ratings of the backend under five component definitions.

    python3 tools/sig/check_sig_maintainability.py report . --json /tmp/base.json
    python3 tools/sig/rate_component_views.py --report /tmp/base.json [--json OUT]

The report gives every property that does not depend on the component definition (unit size,
duplication, the JavaScript structure and so on). For the PHP backend this script recomputes the
structure with the scorer's own structure() for each definition, puts it in a copy of the report
and runs the scorer's rating_estimate() on it:

- A `lib/` as one component (the top-level directories, as sigrid.yaml declares);
- B every directory under `lib/` (the scorer's automatic choice without a `components:` block);
- C the ten functional areas of sigrid.components.yaml (ADR-012);
- D as C, with Core merged into Kernel: the object store and access control as a shared foundation;
- E as D, without the references from the foundation to the other areas. This is a simulation of
  a foundation that is only depended on, not a measurement of existing code.

For C the script also splits the exposed code by kind of file (exposed_by_kind) and by component
(exposed_by_component), and for D it lists the foundation's references to each other component
(foundation_backrefs). `targets` gives, per rating, the largest exposed share and violation degree
that still earn it, from the scorer's own rating scale.

All ratings are estimates on the scorer's 0.5 to 5.5 scale; SIG publishes only the 4-star limits.
"""
import argparse
import collections
import copy
import json
import os
import re
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
APP = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, HERE)
import check_sig_maintainability as tool  # noqa: E402

FOUNDATION = "Kernel"


def one_component_yaml():
    """A `components:` block that puts every file under lib/ in one component."""
    return 'components:\n  - name: Backend\n    include:\n      - ".*/lib/.*"\n'


def foundation_yaml(components_text, merge=("Core",)):
    """sigrid.components.yaml with the named components renamed to the foundation. The first
    matching component owns a file, so a shared name merges them without reordering."""
    names = "|".join(re.escape(n) for n in merge)
    return re.sub(rf"(?m)^(\s*- name: )(?:{names})\s*$", rf"\g<1>{FOUNDATION}", components_text)


def without_foundation_backrefs(deps, comp):
    """deps without the edges from a foundation file to a file of another component."""
    return {src: {d for d in targets if not (comp(src) == FOUNDATION and comp(d) != FOUNDATION)}
            for src, targets in deps.items()}


def kind_of(rel, text):
    """The kind of a PHP file, by its declaration and name: interface, trait, exception, event,
    entity, mapper or concrete class. An entity that does not extend Entity directly counts as a
    concrete class."""
    name = os.path.basename(rel)
    if re.search(r"^\s*interface\s", text, re.M):
        return "interface"
    if re.search(r"^\s*trait\s", text, re.M):
        return "trait"
    if name.endswith("Exception.php") or re.search(r"extends \\?\w*Exception\b", text):
        return "exception"
    if name.endswith("Event.php"):
        return "event"
    if "/Db/" in rel and re.search(r"extends \\?(OCP\\AppFramework\\Db\\)?Entity\b", text):
        return "entity"
    if name.endswith("Mapper.php"):
        return "mapper"
    return "concrete class"


def exposed_by_kind(app, deps, comp):
    """{kind: {"files": n, "loc": lines}} of the files another component references."""
    incoming = collections.defaultdict(set)
    for src, targets in deps.items():
        for t in targets:
            incoming[t].add(src)
    out = collections.defaultdict(lambda: {"files": 0, "loc": 0})
    for rel in deps:
        if any(comp(s) != comp(rel) for s in incoming[rel]):
            text = tool.read_text(os.path.join(app, rel))
            row = out[kind_of(rel, text)]
            row["files"] += 1
            row["loc"] += len(tool.code_lines(text, "php"))
    return dict(sorted(out.items(), key=lambda kv: -kv[1]["loc"]))


def exposed_by_component(app, deps, comp):
    """{component: {"loc": lines, "exposed_loc": lines another component references}}."""
    incoming = collections.defaultdict(set)
    for src, targets in deps.items():
        for t in targets:
            incoming[t].add(src)
    out = collections.defaultdict(lambda: {"loc": 0, "exposed_loc": 0})
    for rel in deps:
        lines = len(tool.code_lines(tool.read_text(os.path.join(app, rel)), "php"))
        row = out[comp(rel)]
        row["loc"] += lines
        if any(comp(s) != comp(rel) for s in incoming[rel]):
            row["exposed_loc"] += lines
    return dict(sorted(out.items(), key=lambda kv: -kv[1]["loc"]))


def max_value_for(kind, rating):
    """The largest value of an exposed share or violation degree that still rates `rating`."""
    caps = tool.rating_caps(kind)
    lo, hi = 0.0, 1.0
    for _ in range(50):
        mid = (lo + hi) / 2
        if tool.interpolate(mid, caps) >= rating:
            lo = mid
        else:
            hi = mid
    return round(lo, 4)


def targets(ratings=(4.0, 4.5, 5.0, 5.5)):
    """{rating: {"exposed_share": max, "violation_degree": max}}."""
    return {str(r): {"exposed_share": max_value_for("component_independence", r),
                     "violation_degree": max_value_for("component_entanglement", r)} for r in ratings}


def foundation_backrefs(deps, comp):
    """{component: file references from the foundation to it}, heaviest first."""
    out = collections.Counter(comp(d) for src, targets in deps.items() if comp(src) == FOUNDATION
                              for d in targets if comp(d) != FOUNDATION)
    return dict(out.most_common())


def rate(report, structure):
    rep = copy.deepcopy(report)
    rep.setdefault("structure", {})["php"] = structure
    rating = tool.rating_estimate(rep)
    ci, ce = structure["component_independence"], structure["component_entanglement"]
    return {
        "components": len(structure["components"]),
        "hidden_share": ci["hidden_share"],
        "violation_degree": ce["violation_degree"],
        "violations": ce["violations"],
        "independence": rating["properties"]["component_independence"]["rating"],
        "entanglement": rating["properties"]["component_entanglement"]["rating"],
        "modularity": rating["subcharacteristics"]["modularity"],
        "overall": rating["overall"]["range"],
    }


def views(report, app=APP):
    files = {rel: lang for rel, lang in tool.production_files(app, tool.Scope(app)).items() if lang == "php"}
    deps = tool.php_dependencies(app, files)
    components = open(os.path.join(app, "sigrid.components.yaml"), encoding="utf-8").read()
    out, saved = {}, os.environ.get("SIG_COMPONENTS_YAML")
    with tempfile.TemporaryDirectory() as tmp:
        one, foundation = os.path.join(tmp, "one.yaml"), os.path.join(tmp, "foundation.yaml")
        open(one, "w", encoding="utf-8").write(one_component_yaml())
        open(foundation, "w", encoding="utf-8").write(foundation_yaml(components))
        try:
            for key, path in (("A", one), ("B", os.path.join(tmp, "none.yaml")),
                              ("C", os.path.join(app, "sigrid.components.yaml")), ("D", foundation)):
                os.environ["SIG_COMPONENTS_YAML"] = path
                out[key] = rate(report, tool.structure(app, files, deps))
                _, comp = tool.component_of_factory(sorted(deps), app)
                if key == "C":
                    out[key]["exposed_by_kind"] = exposed_by_kind(app, deps, comp)
                    out[key]["exposed_by_component"] = exposed_by_component(app, deps, comp)
                if key == "D":
                    out[key]["foundation_backrefs"] = foundation_backrefs(deps, comp)
            out["E"] = rate(report, tool.structure(app, files, without_foundation_backrefs(deps, comp)))
        finally:
            if saved is None:
                os.environ.pop("SIG_COMPONENTS_YAML", None)
            else:
                os.environ["SIG_COMPONENTS_YAML"] = saved
    return out


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--report", required=True, help="JSON written by check_sig_maintainability.py report")
    ap.add_argument("--json", help="write the results here")
    args = ap.parse_args(argv)
    out = views(json.load(open(args.report, encoding="utf-8")))
    out["targets"] = targets()
    for key, v in out.items():
        if key == "targets":
            continue
        lo, hi = v["overall"]
        print(f"{key}: {v['components']} components, hidden {v['hidden_share']:.1%}, "
              f"violation degree {v['violation_degree']:.4f}; independence {v['independence']}, "
              f"entanglement {v['entanglement']}, modularity {v['modularity']}, overall {lo} to {hi}")
    if args.json:
        with open(args.json, "w", encoding="utf-8") as fh:
            json.dump(out, fh, indent=2)
    return 0


if __name__ == "__main__":
    sys.exit(main())
