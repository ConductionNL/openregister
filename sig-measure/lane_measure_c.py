#!/usr/bin/env python3
"""Component independence and entanglement of a tree, overall and for one r12c lane.

    python3 lane_measure_c.py --app . --lane K01a1 [--scopes scopes-c.json] [--json out.json]

Two views, both with the dependency reader of check_sig_maintainability.py (gate 122):
  sigrid view      the components Sigrid rates today: sigrid.yaml as it is in the tree
                   (`component_base_dirs`, or a `components:` block once ADR-012 flips it);
  functional view  the ADR-012 `components:` block: <app>/sigrid.components.yaml when the tree
                   has it, else components.yaml next to this script (or --components).
For the lane: exposed LOC of its scope files in both views (a file moved into lib/Kernel or
lib/Support is followed by its basename), the module edges on the communication lines the lane
owns (sigrid view), and the violation degree now and with those edges gone.
Needs only python3; reads no unit metrics, so lizard is not used.
"""
import argparse
import collections
import contextlib
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import check_sig_maintainability as m  # noqa: E402

KERNEL_DIRS = ('lib/Kernel/', 'lib/Support/', 'lib/Contract/')


@contextlib.contextmanager
def env(key, value):
    old = os.environ.get(key)
    if value is None:
        os.environ.pop(key, None)
    else:
        os.environ[key] = value
    try:
        yield
    finally:
        if old is None:
            os.environ.pop(key, None)
        else:
            os.environ[key] = old


def graph(app):
    """{module: deps}, {module: incoming}, {module: loc} for lib/ PHP as gate 122 reads it."""
    files = m.production_files(app, m.Scope(app))
    php = {f: lg for f, lg in files.items() if lg == 'php' and f.startswith('lib/')}
    deps = m.php_dependencies(app, php)
    incoming = collections.defaultdict(set)
    for s, ts in deps.items():
        for t in ts:
            incoming[t].add(s)
    loc = {f: len(m.code_lines(m.read_text(os.path.join(app, f)), 'php')) for f in deps}
    return deps, incoming, loc


def view(app, deps, incoming, loc, components_yaml):
    """Component function and totals of one view. components_yaml None = sigrid.yaml as in the tree."""
    mods = sorted(deps)
    with env('SIG_COMPONENTS_YAML', components_yaml):
        root, comp = m.component_of_factory(mods, app)
    total = sum(loc.values()) or 1
    exposed = {x for x in mods if any(comp(s) != comp(x) for s in incoming[x])}
    lines = collections.Counter()
    for t in mods:
        for s in incoming[t]:
            if comp(s) != comp(t):
                lines[(comp(s), comp(t))] += 1
    degree, kinds, _ex = m.violation_degree(dict(lines))
    exp_loc = sum(loc[x] for x in exposed)
    share = exp_loc / total
    return dict(comp=comp, root=root, exposed=exposed, lines=lines, total=total, exposed_loc=exp_loc,
                share=share, degree=degree, kinds=kinds,
                ind=m.interpolate(share, m.rating_caps('component_independence')),
                ent=m.interpolate(degree, m.rating_caps('component_entanglement')),
                remainder=sum(1 for x in mods if comp(x) == 'Remainder'),
                components=collections.Counter(comp(x) for x in mods))


def functional_yaml(app, given):
    if given:
        return os.path.abspath(given)
    own = os.path.join(app, 'sigrid.components.yaml')
    if os.path.isfile(own):
        return own
    return os.path.join(HERE, 'components.yaml')


def resolve(app, files, deps):
    """Scope path -> current path (moved files are found by basename under lib/Kernel, lib/Support, lib/Contract)."""
    by_base = collections.defaultdict(list)
    for f in deps:
        if f.startswith(KERNEL_DIRS):
            by_base[os.path.basename(f)].append(f)
    out = {}
    for f in files:
        if f in deps:
            out[f] = f
        elif not f.startswith('lib/'):
            out[f] = f if os.path.exists(os.path.join(app, f)) else None
        else:
            hit = by_base.get(os.path.basename(f), [])
            out[f] = hit[0] if len(hit) == 1 else None
    return out


def fmt(v):
    return f"exposed {v['exposed_loc']:,} of {v['total']:,} LOC ({100 * v['share']:.2f}%, independence {v['ind']:.2f}), " \
           f"lines {len(v['lines'])}, violation degree {v['degree']:.4f} (entanglement {v['ent']:.2f}), remainder files {v['remainder']}"


def lane_report(app, lane, sc, deps, incoming, loc, sv, fv, qowned):
    files = sc.get('files', [])
    cur = resolve(app, files, deps)
    rows, missing = [], []
    for f in files:
        c = cur.get(f)
        if c is None:
            missing.append(f)
            continue
        if c not in deps:
            continue
        foreign = sorted(s for s in incoming[c] if fv['comp'](s) != fv['comp'](c))
        rows.append(dict(file=f, now=c, loc=loc[c], fcomp=fv['comp'](c), scomp=sv['comp'](c),
                         f_exposed=c in fv['exposed'], s_exposed=c in sv['exposed'], foreign=foreign,
                         q_blocked=[s for s in foreign if s in qowned]))
    out = dict(lane=lane, files=len(files), measured=len(rows), missing=missing)
    out['functional_exposed_loc'] = sum(r['loc'] for r in rows if r['f_exposed'])
    out['functional_exposed_outside_kernel'] = sum(r['loc'] for r in rows if r['f_exposed'] and r['fcomp'] != 'Kernel')
    out['functional_blocked_by_q'] = sum(r['loc'] for r in rows if r['f_exposed'] and r['fcomp'] != 'Kernel' and r['q_blocked'])
    out['sigrid_exposed_loc'] = sum(r['loc'] for r in rows if r['s_exposed'])
    out['in_kernel_files'] = sum(1 for r in rows if r['fcomp'] == 'Kernel')
    out['rows'] = rows
    # owned communication lines and the module edges on them that touch the scope.
    # `lines` are lines of the sigrid view, `flines` lines of the functional view.
    scope_now = {r['now'] for r in rows}
    out['views'] = {}
    for key, v in (('lines', sv), ('flines', fv)):
        owned = [tuple(x) for x in sc.get(key, [])]
        if not owned:
            continue
        edges = []
        for t in deps:
            for s in incoming[t]:
                a, b = v['comp'](s), v['comp'](t)
                if (a, b) in owned and (s in scope_now or t in scope_now):
                    edges.append((a, b, s, t))
        reduced = collections.Counter(v['lines'])
        for a, b, _s, _t in edges:
            reduced[(a, b)] -= 1
        reduced = {k: n for k, n in reduced.items() if n > 0}
        out['views'][key] = dict(
            owned_lines={f"{a}->{b}": dict(weight=v['lines'].get((a, b), 0),
                                           scope_edges=sum(1 for e in edges if e[:2] == (a, b))) for a, b in owned},
            scope_edges=len(edges), degree_now=v['degree'],
            degree_without_scope_edges=m.violation_degree(reduced)[0],
            edge_list=[f"{s} -> {t} ({a}->{b})" for a, b, s, t in sorted(edges)])
    return out


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--app', default='.')
    ap.add_argument('--lane')
    ap.add_argument('--scopes', default=os.path.join(HERE, 'scopes-c.json'))
    ap.add_argument('--components', help='functional components block (default: <app>/sigrid.components.yaml, else components.yaml here)')
    ap.add_argument('--qowned', default=os.path.join(HERE, 'q-owned.txt'))
    ap.add_argument('--json')
    a = ap.parse_args(argv)
    app = os.path.abspath(a.app)
    deps, incoming, loc = graph(app)
    fy = functional_yaml(app, a.components)
    sv = view(app, deps, incoming, loc, None)
    fv = view(app, deps, incoming, loc, fy)
    head = (m.git(app, 'rev-parse', '--short=12', 'HEAD')[1] or '').strip()
    print(f"tree {app} at {head}")
    print(f"SIGRID VIEW (sigrid.yaml as in the tree, root {sv['root']}): {fmt(sv)}")
    print(f"FUNCTIONAL VIEW ({os.path.relpath(fy, app) if fy.startswith(app) else fy}): {fmt(fv)}")
    print("  functional components (files): " + ', '.join(f"{k} {v}" for k, v in fv['components'].most_common()))
    print(f"  Kernel LOC {sum(loc[x] for x in deps if fv['comp'](x) == 'Kernel'):,}; exposed outside Kernel "
          f"{sum(loc[x] for x in fv['exposed'] if fv['comp'](x) != 'Kernel'):,} LOC")
    result = dict(head=head, sigrid=dict((k, sv[k]) for k in ('exposed_loc', 'total', 'share', 'degree', 'ind', 'ent', 'remainder')),
                  functional=dict((k, fv[k]) for k in ('exposed_loc', 'total', 'share', 'degree', 'ind', 'ent', 'remainder')),
                  functional_yaml=fy)
    if a.lane:
        scopes = json.load(open(a.scopes))
        if a.lane not in scopes:
            raise SystemExit(f"lane {a.lane} not in {a.scopes}")
        sc = scopes[a.lane]
        qowned = set(x.strip() for x in open(a.qowned)) if os.path.isfile(a.qowned) else set()
        r = lane_report(app, a.lane, sc, deps, incoming, loc, sv, fv, qowned)
        result['lane'] = {k: v for k, v in r.items() if k != 'rows'}
        print(f"LANE {a.lane}: {r['files']} scope files, {r['measured']} measured, {len(r['missing'])} not found, {r['in_kernel_files']} now in the Kernel component")
        print(f"  functional view: scope exposed {r['functional_exposed_loc']:,} LOC, outside Kernel {r['functional_exposed_outside_kernel']:,} "
              f"(of which {r['functional_blocked_by_q']:,} LOC is referenced from a wave-Q file: that blocks a fix only when it needs an edit there, not a move with an alias)")
        print(f"  sigrid view: scope exposed {r['sigrid_exposed_loc']:,} LOC")
        for key, name in (('lines', 'sigrid view'), ('flines', 'functional view')):
            vr = r['views'].get(key)
            if not vr:
                continue
            print(f"  owned lines ({name}): " + '; '.join(f"{k} weight {v['weight']}, {v['scope_edges']} touch your files" for k, v in vr['owned_lines'].items()))
            print(f"  module edges on owned lines touching your files ({name}): {vr['scope_edges']} (target 0); "
                  f"violation degree {vr['degree_now']:.4f} now, {vr['degree_without_scope_edges']:.4f} with them gone")
            for e in vr['edge_list'][:60]:
                print(f"    {e}")
            if len(vr['edge_list']) > 60:
                print(f"    and {len(vr['edge_list']) - 60} more")
        todo = [x for x in r['rows'] if x['f_exposed'] and x['fcomp'] != 'Kernel']
        if todo:
            print(f"  REMAINING (scope files exposed outside the Kernel, functional view; {len(todo)}):")
            for x in sorted(todo, key=lambda x: -x['loc'])[:80]:
                q = f" [Q-owned referrers: {len(x['q_blocked'])}]" if x['q_blocked'] else ''
                print(f"    {x['now']} ({x['fcomp']}, {x['loc']} LOC) from {len(x['foreign'])}: {', '.join(x['foreign'][:3])}{q}")
        for f in r['missing']:
            print(f"  not found on this tree: {f}")
    if a.json:
        json.dump(result, open(a.json, 'w'), indent=1, default=list)


if __name__ == '__main__':
    main()
