#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Dependency analysis of the OpenRegister backend, in two component views.

    python3 tools/sig/analyse_dependencies.py [--rev REV] [--components FILE] [--out DIR]

The module graph is the one tools/sig/check_sig_maintainability.py builds: one node per PHP file
under lib/ (lib/Migration excepted, which Sigrid leaves out by default), and an edge from a file to
every OpenRegister class it names in a `use` import, a fully qualified name or a bare name in its
own namespace. Comments are stripped first. Lines of code are counted the same way as there.

On that graph the script reports:

- two component views, each with hidden code (the share of code no other component references),
  communication lines and violation degree, computed with the scorer's own functions:
  the directory view (every directory under lib/ is a component) and the functional view
  (the `components:` block of sigrid.components.yaml, ADR-012);
- layering: every file gets a role from its directory (entry point, service, persistence or
  shared type) and every edge between two roles is counted as downward, same-level or upward;
- cycles: the strongly connected sets of files, the largest one by directory and area, and the
  upward edges inside it;
- per functional area: internal and outgoing references, exposed code, and the area pairs that
  reference each other in both directions.

--rev measures a commit instead of the working tree: lib/ of that commit is exported with
`git archive` into a temporary directory. The functional definition is always read from
--components (default: sigrid.components.yaml in the working tree), so an older commit is
measured against today's definition. A file the definition does not match (it moved or was
deleted since) is assigned the area that most files in its directory have, and the report counts
those files.

--out writes results.json, report.md and areas.dot to DIR. Without --out only report.md is printed.
"""
import argparse
import collections
import json
import os
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, HERE)
import check_sig_maintainability as tool  # noqa: E402

# Layer of a directory name, from outer (3) to inner (0). The first directory on the path below
# lib/ that is named here decides the role, so lib/Files/Controller/X.php is an entry point and
# lib/Search/Db/View.php is persistence.
ROLES = {
    "Controller": ("entry point", 3), "Command": ("entry point", 3), "Listener": ("entry point", 3),
    "BackgroundJob": ("entry point", 3), "Repair": ("entry point", 3), "Middleware": ("entry point", 3),
    "Settings": ("entry point", 3), "Sections": ("entry point", 3), "Dashboard": ("entry point", 3),
    "Capabilities": ("entry point", 3),
    "Service": ("service", 2),
    "Db": ("persistence", 1),
    "Event": ("shared type", 0), "Exception": ("shared type", 0), "Kernel": ("shared type", 0),
    "Contract": ("shared type", 0), "Dto": ("shared type", 0), "Support": ("shared type", 0),
    "Formats": ("shared type", 0),
}
# lib/AppInfo wires every component together and is not a layer.
BOOTSTRAP = "AppInfo"


def export_rev(rev, dest):
    """Write lib/ of commit `rev` into `dest`; return the full commit hash."""
    full = subprocess.run(["git", "-C", REPO, "rev-parse", rev], check=True, capture_output=True, text=True).stdout.strip()
    archive = subprocess.run(["git", "-C", REPO, "archive", full, "lib"], check=True, capture_output=True).stdout
    subprocess.run(["tar", "-x", "-C", dest], input=archive, check=True)
    return full


def load_graph(app):
    """({module: set(modules)}, {module: loc}) for lib/ of `app`, lib/Migration left out."""
    rels = []
    for root, _dirs, files in os.walk(os.path.join(app, "lib")):
        for name in files:
            rel = os.path.relpath(os.path.join(root, name), app)
            if name.endswith(".php") and not rel.startswith("lib/Migration/"):
                rels.append(rel)
    deps = tool.php_dependencies(app, sorted(rels))
    loc = {m: len(tool.code_lines(tool.read_text(os.path.join(app, m)), "php")) for m in deps}
    return deps, loc


def role_of(module):
    """(role, rank) of a module, or None for the bootstrap and for unlayered directories."""
    parts = module.split("/")[1:-1]
    if parts and parts[0] == BOOTSTRAP:
        return None
    for part in parts:
        if part in ROLES:
            return ROLES[part]
    return None


def directory_component(module):
    parts = module.split("/")
    return parts[1] if len(parts) > 2 else "(root)"


def functional_components(modules, components_file):
    """({module: area}, [modules placed by directory majority]) from a `components:` block."""
    os.environ["SIG_COMPONENTS_YAML"] = components_file
    comps = tool.pattern_components(REPO)
    if not comps:
        raise SystemExit(f"no components: block in {components_file}")
    match = tool.pattern_component_of_factory(comps)
    area = {m: match(m) for m in modules}
    votes = collections.defaultdict(collections.Counter)
    for m, a in area.items():
        if a != "Remainder":
            parts = m.split("/")
            for k in range(2, len(parts)):
                votes["/".join(parts[:k])][a] += 1
    placed = []
    for m, a in area.items():
        if a != "Remainder":
            continue
        parts = m.split("/")
        for k in range(len(parts) - 1, 1, -1):
            if votes["/".join(parts[:k])]:
                area[m] = votes["/".join(parts[:k])].most_common(1)[0][0]
                placed.append(m)
                break
    return area, sorted(placed)


def incoming_of(deps):
    incoming = collections.defaultdict(set)
    for src, targets in deps.items():
        for t in targets:
            incoming[t].add(src)
    return incoming


def component_view(deps, loc, comp):
    """Hidden code, communication lines and violation degree, as the scorer computes them."""
    incoming = incoming_of(deps)
    total = sum(loc.values()) or 1
    hidden = sum(loc[m] for m in loc if all(comp[s] == comp[m] for s in incoming[m]))
    lines = collections.Counter()
    for t, srcs in incoming.items():
        for s in srcs:
            if comp[s] != comp[t]:
                lines[(comp[s], comp[t])] += 1
    degree, kinds, _examples = tool.violation_degree(dict(lines))
    sizes = collections.Counter()
    for m in loc:
        sizes[comp[m]] += loc[m]
    share = round(hidden / total, 4)
    return {
        "components": len(sizes),
        "largest_component": sizes.most_common(1)[0][0],
        "largest_share": round(sizes.most_common(1)[0][1] / total, 4),
        "hidden_share": share,
        "hidden_stars_estimate": tool.stars_of("component_independence", share),
        "communication_lines": len(lines),
        "violation_degree": degree,
        "violation_stars_estimate": tool.stars_of("component_entanglement", degree),
        "violations": kinds,
        "loc_per_component": dict(sizes.most_common()),
    }


def layering(deps):
    """Edges between two layered roles, split into downward, same-level and upward."""
    counts = collections.Counter()
    upward = collections.defaultdict(list)
    for src, targets in deps.items():
        rs = role_of(src)
        for t in targets:
            rt = role_of(t)
            if rs is None or rt is None:
                counts["unlayered"] += 1
                continue
            if rs[1] > rt[1]:
                counts["downward"] += 1
            elif rs[1] == rt[1]:
                counts["same level"] += 1
            else:
                counts["upward"] += 1
                upward[f"{rs[0]} -> {rt[0]}"].append(f"{src} -> {t}")
    return counts, {k: sorted(v) for k, v in sorted(upward.items(), key=lambda kv: -len(kv[1]))}


def strongly_connected(deps):
    """Strongly connected sets of modules (Tarjan, iterative), largest first."""
    index, low, on_stack, stack, out = {}, {}, set(), [], []
    counter = 0
    for start in sorted(deps):
        if start in index:
            continue
        work = [(start, iter(sorted(deps[start])))]
        index[start] = low[start] = counter
        counter += 1
        stack.append(start)
        on_stack.add(start)
        while work:
            node, it = work[-1]
            advanced = False
            for nxt in it:
                if nxt not in deps:
                    continue
                if nxt not in index:
                    index[nxt] = low[nxt] = counter
                    counter += 1
                    stack.append(nxt)
                    on_stack.add(nxt)
                    work.append((nxt, iter(sorted(deps[nxt]))))
                    advanced = True
                    break
                if nxt in on_stack:
                    low[node] = min(low[node], index[nxt])
            if advanced:
                continue
            work.pop()
            if work:
                low[work[-1][0]] = min(low[work[-1][0]], low[node])
            if low[node] == index[node]:
                group = set()
                while True:
                    m = stack.pop()
                    on_stack.discard(m)
                    group.add(m)
                    if m == node:
                        break
                out.append(group)
    return sorted(out, key=lambda g: (-len(g), sorted(g)[0]))


def cycles(deps, loc, area):
    groups = [g for g in strongly_connected(deps) if len(g) > 1]
    if not groups:
        return {"cyclic_sets": 0}
    big = groups[0]
    up = []
    for src in sorted(big):
        rs = role_of(src)
        for t in sorted(deps[src] & big):
            rt = role_of(t)
            if rs and rt and rs[1] < rt[1]:
                up.append(f"{src} -> {t}")
    hubs = sorted(big, key=lambda m: -len(deps[m] & big))[:10]
    return {
        "cyclic_sets": len(groups),
        "files_in_cyclic_sets": sum(len(g) for g in groups),
        "set_sizes": [len(g) for g in groups[:10]],
        "largest": {
            "files": len(big),
            "loc": sum(loc[m] for m in big),
            "loc_share": round(sum(loc[m] for m in big) / (sum(loc.values()) or 1), 4),
            "by_directory": dict(collections.Counter("/".join(m.split("/")[1:3]) if m.count("/") > 2 else m.split("/")[1] for m in big).most_common()),
            "by_area": dict(collections.Counter(area[m] for m in big).most_common()),
            "most_outgoing_inside": [f"{m} ({len(deps[m] & big)} references inside the set)" for m in hubs],
            "upward_edges_inside": up,
            "members": sorted(big),
        },
    }


def areas(deps, loc, area):
    incoming = incoming_of(deps)
    stats = {a: {"loc": 0, "exposed_loc": 0, "internal_refs": 0, "outgoing_refs": 0, "incoming_refs": 0} for a in set(area.values())}
    pairs = collections.Counter()
    targets = collections.defaultdict(collections.Counter)
    for m in loc:
        stats[area[m]]["loc"] += loc[m]
        if any(area[s] != area[m] for s in incoming[m]):
            stats[area[m]]["exposed_loc"] += loc[m]
    for src, ts in deps.items():
        for t in ts:
            a, b = area[src], area[t]
            if a == b:
                stats[a]["internal_refs"] += 1
            else:
                stats[a]["outgoing_refs"] += 1
                stats[b]["incoming_refs"] += 1
                pairs[(a, b)] += 1
                targets[(a, b)][t] += 1
    both = []
    for (a, b), w in pairs.most_common():
        if a < b and (b, a) in pairs:
            w2 = pairs[(b, a)]
            light = (a, b) if w <= w2 else (b, a)
            both.append({"pair": [a, b], "refs": [w, w2], "lighter": f"{light[0]} -> {light[1]}",
                         "lighter_targets": [t for t, _n in targets[light].most_common(5)]})
    both.sort(key=lambda p: -min(p["refs"]))
    return stats, both, pairs


def pct(x):
    return f"{100 * x:.1f}%"


def report_md(r):
    out = [f"# Dependency analysis of lib/ at {r['commit']}", "",
           f"{r['modules']} PHP files, {r['loc']} lines of code, {r['references']} references between files "
           f"(lib/Migration left out). Produced by `tools/sig/analyse_dependencies.py`.", "",
           "## Component views", "",
           "| View | Components | Largest | Hidden code | Communication lines | Violation degree |",
           "|---|---|---|---|---|---|"]
    for name, v in r["views"].items():
        out.append(f"| {name} | {v['components']} | {v['largest_component']} ({pct(v['largest_share'])}) | "
                   f"{pct(v['hidden_share'])} | {v['communication_lines']} | {v['violation_degree']} |")
    if r["placed_by_directory"]:
        out += ["", f"{len(r['placed_by_directory'])} files are not matched by the functional definition "
                    "and were placed by directory majority (see results.json)."]
    lay = r["layering"]["counts"]
    layered = lay.get("downward", 0) + lay.get("same level", 0) + lay.get("upward", 0)
    out += ["", "## Layering", "",
            f"Of {layered} references between layered files, {lay.get('downward', 0)} go downward "
            f"({pct(lay.get('downward', 0) / (layered or 1))}), {lay.get('same level', 0)} stay on the same level and "
            f"{lay.get('upward', 0)} go upward ({pct(lay.get('upward', 0) / (layered or 1))}). "
            f"{lay.get('unlayered', 0)} references touch the bootstrap or an unlayered directory.", "",
            "| Upward reference | Count | Example |", "|---|---|---|"]
    for kind, edges in r["layering"]["upward"].items():
        out.append(f"| {kind} | {len(edges)} | `{edges[0]}` |")
    c = r["cycles"]
    if c.get("cyclic_sets"):
        big = c["largest"]
        out += ["", "## Cycles", "",
                f"{c['cyclic_sets']} sets of files reference each other in a cycle, {c['files_in_cyclic_sets']} files in all. "
                f"The largest holds {big['files']} files and {big['loc']} lines ({pct(big['loc_share'])} of lib/).", "",
                "By directory: " + ", ".join(f"{k} {v}" for k, v in big["by_directory"].items()), "",
                "By area: " + ", ".join(f"{k} {v}" for k, v in big["by_area"].items()), "",
                "Files with the most references inside the set:", ""]
        out += [f"- `{h}`" for h in big["most_outgoing_inside"]]
        out += ["", f"{len(big['upward_edges_inside'])} of the references inside the set go upward a layer; results.json lists them."]
    out += ["", "## Functional areas", "",
            "| Area | Lines | Exposed | Internal refs | Outgoing refs | Incoming refs |", "|---|---|---|---|---|---|"]
    for a, s in sorted(r["areas"].items(), key=lambda kv: -kv[1]["loc"]):
        out.append(f"| {a} | {s['loc']} | {pct(s['exposed_loc'] / (s['loc'] or 1))} | {s['internal_refs']} | "
                   f"{s['outgoing_refs']} | {s['incoming_refs']} |")
    out += ["", "Area pairs that reference each other in both directions, heaviest lighter direction first:", "",
            "| Pair | References | Lighter direction | Most referenced in the lighter direction |", "|---|---|---|---|"]
    for p in r["two_way_pairs"][:15]:
        out.append(f"| {p['pair'][0]} / {p['pair'][1]} | {p['refs'][0]} / {p['refs'][1]} | {p['lighter']} | "
                   + ", ".join(f"`{t[4:]}`" for t in p["lighter_targets"][:3]) + " |")
    return "\n".join(out) + "\n"


def areas_dot(pairs, stats):
    out = ["digraph areas {", "  rankdir=LR;", "  node [shape=box];"]
    for a, s in sorted(stats.items()):
        out.append(f'  "{a}" [label="{a}\\n{s["loc"]} lines"];')
    for (a, b), w in sorted(pairs.items()):
        style = ', color="red"' if (b, a) in pairs else ""
        out.append(f'  "{a}" -> "{b}" [label="{w}", penwidth={1 + min(w, 2000) / 400:.1f}{style}];')
    return "\n".join(out + ["}"]) + "\n"


def analyse(app, components_file, commit):
    deps, loc = load_graph(app)
    area, placed = functional_components(sorted(loc), components_file)
    counts, upward = layering(deps)
    stats, both, pairs = areas(deps, loc, area)
    return {
        "commit": commit,
        "functional_definition": os.path.relpath(components_file, REPO),
        "modules": len(loc),
        "loc": sum(loc.values()),
        "references": sum(len(t) for t in deps.values()),
        "views": {
            "directories under lib/": component_view(deps, loc, {m: directory_component(m) for m in loc}),
            "functional areas": component_view(deps, loc, area),
        },
        "placed_by_directory": placed,
        "layering": {"counts": dict(counts), "upward": upward},
        "cycles": cycles(deps, loc, area),
        "areas": stats,
        "two_way_pairs": both,
    }, pairs


def main(argv):
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--rev", help="measure this commit instead of the working tree")
    ap.add_argument("--components", default=os.path.join(REPO, "sigrid.components.yaml"),
                    help="file with the functional `components:` block")
    ap.add_argument("--out", help="directory for results.json, report.md and areas.dot")
    args = ap.parse_args(argv)
    components = os.path.abspath(args.components)
    with tempfile.TemporaryDirectory() as tmp:
        if args.rev:
            commit, app = export_rev(args.rev, tmp), tmp
        else:
            commit = subprocess.run(["git", "-C", REPO, "rev-parse", "HEAD"], capture_output=True, text=True).stdout.strip() or "working tree"
            app = REPO
        result, pairs = analyse(app, components, commit)
    md = report_md(result)
    if args.out:
        os.makedirs(args.out, exist_ok=True)
        with open(os.path.join(args.out, "results.json"), "w", encoding="utf-8") as fh:
            json.dump(result, fh, indent=1, sort_keys=True)
        with open(os.path.join(args.out, "report.md"), "w", encoding="utf-8") as fh:
            fh.write(md)
        with open(os.path.join(args.out, "areas.dot"), "w", encoding="utf-8") as fh:
            fh.write(areas_dot(pairs, result["areas"]))
    sys.stdout.write(md)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
