#!/usr/bin/env python3
"""Unit size and duplication of one wave D lane's files, counted exactly as the scorer counts them.

    python3 measure_d.py --app . --lane D01 --scopes scopes-d.json [--json out.json]

Place this file next to check_sig_maintainability.py (the sig-measure directory of the brief branch
sig-lane-briefs/r12t); a tools/ directory beside it supplies the pinned lizard. Files the branch adds
under lib/ or src/ (git diff --diff-filter=A against origin/main) count toward the lane too, so a
helper class that is itself too long shows up.
"""
import argparse
import collections
import json
import os
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))


def scorer(scorer_dir=None):
    tools = os.path.join(HERE, 'tools')
    if os.path.isdir(tools):
        os.environ['HYDRA_GATE_TOOLS_DIR'] = tools
        os.environ['HYDRA_GATE_SIG_NO_INSTALL'] = '1'
    sys.path.insert(0, scorer_dir or HERE)
    import check_sig_maintainability as m  # noqa: E402
    return m


def added_files(app):
    p = subprocess.run(['git', '-C', app, 'diff', '--name-only', '--diff-filter=A', 'origin/main', '--'],
                       capture_output=True, text=True)
    return {f for f in p.stdout.split() if f.startswith(('lib/', 'src/'))}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--app', default='.')
    ap.add_argument('--lane', required=True)
    ap.add_argument('--scopes', required=True)
    ap.add_argument('--scorer-dir')
    ap.add_argument('--json', dest='json_out')
    args = ap.parse_args()
    m = scorer(args.scorer_dir)
    app = os.path.abspath(args.app)
    scopes = json.load(open(args.scopes))
    if args.lane not in scopes:
        raise SystemExit(f'lane {args.lane} is not in {args.scopes}')
    files = m.production_files(app, m.Scope(app))
    added = added_files(app)
    mine = (set(scopes[args.lane]) | added) & set(files)
    lz = m.load_lizard()
    lz = lz[0] if isinstance(lz, tuple) else lz
    if lz is None:
        raise SystemExit('lizard unavailable')
    reader = m.UnitReader(lz)
    units = []
    for rel in sorted(mine):
        units.extend(reader.units(rel, files[rel], m.read_text(os.path.join(app, rel))))
    lines_of, code = {}, {}
    for rel, lang in files.items():
        text = m.read_text(os.path.join(app, rel))
        lines_of[rel] = text.splitlines()
        code[rel] = m.code_lines(text, lang)
    seen = collections.defaultdict(dict)
    red = collections.defaultdict(set)
    pairs = collections.Counter()
    for rel in sorted(files):
        lang = files[rel]
        for i, key, nums in m.dup_windows(lines_of[rel], code[rel], lang):
            first = seen[lang].get(key)
            if first is not None and first != (rel, i):
                red[rel].update(nums)
                if rel in mine or first[0] in mine:
                    pairs[tuple(sorted((rel, first[0])))] += 1
            else:
                seen[lang].setdefault(key, (rel, i))
    now = dict(over15=sum(u['nloc'] for u in units if u['nloc'] > 15),
               over30=sum(u['nloc'] for u in units if u['nloc'] > 30),
               over60=sum(u['nloc'] for u in units if u['nloc'] > 60),
               redundant=sum(len(red[f]) for f in mine),
               new_units_over_2_params=[f"{u['file']}:{u['start']} {u['name']} params={u['params']}"
                                        for u in units if u['file'] in added and u['params'] > 2])
    out = dict(lane=args.lane, files=len(mine), now=now,
               units_over_15=[f"{u['file']}:{u['start']} {u['name']} nloc={u['nloc']}"
                              for u in sorted(units, key=lambda u: (-u['nloc'], u['file'], u['start'])) if u['nloc'] > 15][:300],
               duplicate_pairs=[f"{a} <-> {b} windows={n}" + ('' if a in mine and b in mine else ' (partner outside your scope)')
                                for (a, b), n in pairs.most_common(150)],
               skipped=reader.skipped)
    print(json.dumps(out, indent=1, ensure_ascii=False))
    if args.json_out:
        json.dump(out, open(args.json_out, 'w'), indent=1, ensure_ascii=False)


if __name__ == '__main__':
    main()
