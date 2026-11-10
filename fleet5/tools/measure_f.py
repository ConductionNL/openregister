#!/usr/bin/env python3
"""Unit size, complexity, interfacing and duplication of one fleet lane's files, counted the way the
full report counts them.

    python3 measure_f.py --app <clone> --lane F01 --scopes scopes.json [--base origin/development] [--json out.json]

Keep this file next to check_sig_maintainability.py. Files the branch adds (git diff --diff-filter=A
against --base) count toward the lane too, so a helper class that is itself too long shows up.
When a lane covers more than one app, pass --name <app> to pick that app's entry.
"""
import argparse
import collections
import json
import os
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))


def scorer():
    tools = os.path.join(HERE, 'tools')
    if os.path.isdir(tools):
        os.environ.setdefault('HYDRA_GATE_TOOLS_DIR', tools)
    sys.path.insert(0, HERE)
    import check_sig_maintainability as m  # noqa: E402
    return m


def added_files(app, base):
    p = subprocess.run(['git', '-C', app, 'diff', '--name-only', '--diff-filter=A', base, '--'],
                       capture_output=True, text=True)
    return set(p.stdout.split())


def lane_entry(scopes, lane, name):
    entries = scopes[lane]['apps']
    if name:
        entries = [e for e in entries if e['app'] == name]
    if len(entries) != 1:
        raise SystemExit(f'lane {lane}: pass --name with one of {[e["app"] for e in scopes[lane]["apps"]]}')
    return entries[0]


def units_line(u):
    return f"{u['file']}:{u['start']} {u['name']} (nloc {u['nloc']}, ccn {u['ccn']}, params {u['params']})"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--app', default='.')
    ap.add_argument('--lane', required=True)
    ap.add_argument('--name')
    ap.add_argument('--scopes', required=True)
    ap.add_argument('--base')
    ap.add_argument('--json', dest='json_out')
    args = ap.parse_args()
    m = scorer()
    app = os.path.abspath(args.app)
    scopes = json.load(open(args.scopes))
    if args.lane not in scopes:
        raise SystemExit(f'lane {args.lane} is not in {args.scopes}')
    entry = lane_entry(scopes, args.lane, args.name)
    base = args.base or f"origin/{entry['base']}"
    files = m.production_files(app, m.Scope(app))
    added = added_files(app, base) & set(files)
    mine = (set(entry['files']) | added) & set(files)
    lz = m.load_lizard()
    lz = lz[0] if isinstance(lz, tuple) else lz
    if lz is None:
        raise SystemExit('lizard unavailable')
    reader = m.UnitReader(lz)
    units = []
    for rel in sorted(mine):
        units.extend(reader.units(rel, files[rel], m.read_text(os.path.join(app, rel))))
    seen = collections.defaultdict(dict)
    red = collections.defaultdict(set)
    pairs = collections.Counter()
    for rel in sorted(files):
        lang = files[rel]
        text = m.read_text(os.path.join(app, rel))
        for i, key, nums in m.dup_windows(text.splitlines(), m.code_lines(text, lang), lang):
            first = seen[lang].get(key)
            if first is not None and first != (rel, i):
                red[rel].update(nums)
                if rel in mine or first[0] in mine:
                    pairs[tuple(sorted((rel, first[0])))] += 1
            else:
                seen[lang].setdefault(key, (rel, i))

    def lines(pred):
        return sum(u['nloc'] for u in units if pred(u))
    now = dict(unit_loc=lines(lambda u: True),
               size_over15=lines(lambda u: u['nloc'] > 15), size_over30=lines(lambda u: u['nloc'] > 30),
               size_over60=lines(lambda u: u['nloc'] > 60),
               ccn_over5=lines(lambda u: u['ccn'] > 5), ccn_over10=lines(lambda u: u['ccn'] > 10),
               ccn_over25=lines(lambda u: u['ccn'] > 25),
               params_over2=lines(lambda u: u['params'] > 2), params_over4=lines(lambda u: u['params'] > 4),
               params_over6=lines(lambda u: u['params'] > 6),
               redundant=sum(len(red[f]) for f in mine),
               new_units_over_2_params=[units_line(u) for u in units if u['file'] in added and u['params'] > 2])
    out = dict(lane=args.lane, app=entry['app'], base=base, files=len(mine), now=now,
               units_over_15=[units_line(u) for u in sorted(units, key=lambda u: (-u['nloc'], u['file'], u['start']))
                              if u['nloc'] > 15][:300],
               units_over_ccn_5=[units_line(u) for u in sorted(units, key=lambda u: (-u['ccn'], u['file'], u['start']))
                                 if u['ccn'] > 5][:200],
               units_over_2_params=[units_line(u) for u in sorted(units, key=lambda u: (-u['params'], u['file'], u['start']))
                                    if u['params'] > 2][:200],
               duplicate_pairs=[f"{a} <-> {b} windows={n}" + ('' if a in mine and b in mine else ' (partner outside your scope)')
                                for (a, b), n in pairs.most_common(150)],
               skipped=reader.skipped)
    print(json.dumps(out, indent=1, ensure_ascii=False))
    if args.json_out:
        json.dump(out, open(args.json_out, 'w'), indent=1, ensure_ascii=False)


if __name__ == '__main__':
    main()
