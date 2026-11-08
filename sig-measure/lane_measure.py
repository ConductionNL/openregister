#!/usr/bin/env python3
"""Measure a tree with the SIG scorer, overall or for one lane's scope.

Library for gen.py and the command a lane runs before it finishes:

    python3 lane_measure.py --app . --lane H07 --scopes scopes.json

Finds check_sig_maintainability.py next to this file, or uses --scorer-dir. When a tools/ directory
sits next to this file the pinned lizard is taken from there and nothing is installed.
"""
import argparse
import collections
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
OFF_LIMITS = re.compile(r'lib/(Event|Contract|Kernel|Migration|AppInfo)/')
BUNDLE_FILE = re.compile(r'(Services|Stores|Platform|Handlers|Bundle)\.php$')


def scorer(scorer_dir=None):
    tools = os.path.join(HERE, 'tools')
    if os.path.isdir(tools):
        os.environ['HYDRA_GATE_TOOLS_DIR'] = tools
        os.environ['HYDRA_GATE_SIG_NO_INSTALL'] = '1'
    sys.path.insert(0, scorer_dir or HERE)
    import check_sig_maintainability as m  # noqa: E402
    return m


def measure(app, m):
    """Units, dependencies, components, LOC and Vue redundancy of a tree, as the scorer counts them."""
    scope = m.Scope(app)
    files = m.production_files(app, scope)
    lz = m.load_lizard()
    if isinstance(lz, tuple):
        if lz[0] is None:
            raise SystemExit(f'lizard unavailable: {lz[1]}')
        lz = lz[0]
    reader = m.UnitReader(lz)
    units = []
    for rel, lang in sorted(files.items()):
        if lang in ('php', 'vue'):
            units.extend(reader.units(rel, lang, m.read_text(os.path.join(app, rel))))
    php = {k: v for k, v in files.items() if v == 'php'}
    deps = m.php_dependencies(app, php)
    _, comp = m.component_of_factory(sorted(deps))
    loc = {f: len(m.code_lines(m.read_text(os.path.join(app, f)), 'php')) for f in deps}
    vue = [k for k, v in files.items() if v == 'vue']
    lines_of, code = {}, {}
    for rel in vue:
        text = m.read_text(os.path.join(app, rel))
        lines_of[rel] = text.splitlines()
        code[rel] = m.code_lines(text, 'vue')
    seen, red = {}, collections.defaultdict(set)
    windows = collections.defaultdict(set)
    for rel in sorted(vue):
        for i, key, nums in m.dup_windows(lines_of[rel], code[rel], 'vue'):
            windows[key].add(rel)
            if key in seen and seen[key] != (rel, i):
                red[rel].update(nums)
            else:
                seen.setdefault(key, (rel, i))
    return dict(units=units, deps=deps, loc=loc, comp={f: comp(f) for f in deps},
                red={k: len(v) for k, v in red.items()}, vueloc={k: len(v) for k, v in code.items()},
                windows={k: sorted(v) for k, v in windows.items() if len(v) > 1})


def incoming(deps):
    inc = collections.defaultdict(set)
    for s, ts in deps.items():
        for t in ts:
            inc[t].add(s)
    return inc


def exposed(data):
    inc = incoming(data['deps'])
    comp = data['comp']
    return {f for f in data['deps'] if any(comp[s] != comp[f] for s in inc[f])}


def wide_units(data, files=None):
    wanted = set(files) if files is not None else None
    out = []
    for u in data['units']:
        if u['lang'] == 'php' and u['file'].startswith('lib/') and u['params'] > 4 and (wanted is None or u['file'] in wanted):
            out.append(u)
    return out


def totals(data):
    wide = wide_units(data)
    expo = exposed(data)
    red = sum(data['red'].values())
    vloc = sum(data['vueloc'].values())
    return dict(gt4=sum(u['nloc'] for u in wide), gt6=sum(u['nloc'] for u in wide if u['params'] > 6),
                ctor4=sum(u['nloc'] for u in wide if u['name'].endswith('__construct')),
                exposed=sum(data['loc'][f] for f in expo), php_loc=sum(data['loc'].values()),
                vue_red=red, vue_loc=vloc, vue_pct=round(100.0 * red / vloc, 2) if vloc else 0.0)


def lane_report(data, scope):
    """Current numbers for one lane's scope, against the before and target recorded at generation."""
    pf = set(scope.get('p_files', []))
    wide = wide_units(data, pf)
    gt4 = sum(u['nloc'] for u in wide)
    gt6 = sum(u['nloc'] for u in wide if u['params'] > 6)
    expo = exposed(data)
    itargets = scope.get('i_targets', [])
    still = sorted(f for f in itargets if f in expo)
    exposed_loc = sum(data['loc'].get(f, 0) for f in still)
    vf = scope.get('vue_files', [])
    red = sum(data['red'].get(f, 0) for f in vf)
    now = dict(gt4=gt4, gt6=gt6, exposed=exposed_loc, vue_red=red)
    target = scope.get('target', {})
    reached = {k: now[k] <= target[k] for k in target if k in now}
    left = dict(units=[f"{u['file']}:{u['start']} {u['name']} params={u['params']} nloc={u['nloc']}" for u in sorted(wide, key=lambda x: (-x['nloc'], x['file']))],
                exposed_files=still, vue_files=sorted((f for f in vf if data['red'].get(f, 0)), key=lambda f: -data['red'].get(f, 0)))
    return dict(lane=scope['id'], before=scope.get('before', {}), target=target, now=now, reached=reached, done=all(reached.values()) if reached else True, left=left)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--app', default='.')
    ap.add_argument('--lane')
    ap.add_argument('--scopes', default=os.path.join(HERE, 'scopes.json'))
    ap.add_argument('--scorer-dir')
    ap.add_argument('--json', dest='json_out')
    args = ap.parse_args()
    m = scorer(args.scorer_dir)
    app = os.path.abspath(args.app)
    data = measure(app, m)
    if not args.lane:
        out = totals(data)
    else:
        scopes = json.load(open(args.scopes))
        if args.lane not in scopes:
            raise SystemExit(f'lane {args.lane} is not in {args.scopes}')
        out = lane_report(data, scopes[args.lane])
    print(json.dumps(out, indent=1, ensure_ascii=False))
    if args.json_out:
        json.dump(out, open(args.json_out, 'w'), indent=1, ensure_ascii=False)
    if args.lane:
        print('\nDONE' if out['done'] else '\nNOT DONE: list every remaining item in your final message with its reason')


if __name__ == '__main__':
    main()
