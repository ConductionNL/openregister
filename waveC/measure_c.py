#!/usr/bin/env python3
"""Unit complexity of one wave C lane's files, counted exactly as the scorer counts them.

    python3 measure_c.py --app . --lane C01 --scopes scopes-c.json [--json out.json]

Place this file next to check_sig_maintainability.py (the sig-measure directory of the brief branch
sig-lane-briefs/r12t); a tools/ directory beside it supplies the pinned lizard. Files the branch adds
under lib/ or src/ (git diff --diff-filter=A against origin/main) count toward the lane too, so a
helper class that is itself too complex shows up. Units are McCabe (lizard cyclomatic complexity).
"""
import argparse
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


def git_lines(app, *args):
    p = subprocess.run(['git', '-C', app, *args], capture_output=True, text=True)
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
    added = git_lines(app, 'diff', '--name-only', '--diff-filter=A', 'origin/main', '--')
    changed = git_lines(app, 'diff', '--name-only', 'origin/main', '--')
    mine = (set(scopes[args.lane]) | added) & set(files)
    lz = m.load_lizard()
    lz = lz[0] if isinstance(lz, tuple) else lz
    if lz is None:
        raise SystemExit('lizard unavailable')
    reader = m.UnitReader(lz)
    units = []
    for rel in sorted(mine):
        units.extend(reader.units(rel, files[rel], m.read_text(os.path.join(app, rel))))

    def tag(u):
        return f"{u['file']}:{u['start']} {u['name']} ccn={u['ccn']} nloc={u['nloc']} params={u['params']}"
    base = {}
    for rel in sorted(changed & set(files)):
        p = subprocess.run(['git', '-C', app, 'show', f'origin/main:{rel}'], capture_output=True, text=True)
        if p.returncode == 0:
            base[rel] = {(u['name'], u['params']) for u in reader.units(rel, files[rel], p.stdout)}
    # A unit is new when its name/arity did not exist in that file on origin/main (helpers you extracted).
    new = [u for u in units if u['file'] in added or (u['file'] in base and (u['name'], u['params']) not in base[u['file']])]
    now = dict(unit_loc=sum(u['nloc'] for u in units),
               over5=sum(u['nloc'] for u in units if u['ccn'] > 5),
               over10=sum(u['nloc'] for u in units if u['ccn'] > 10),
               over25=sum(u['nloc'] for u in units if u['ccn'] > 25),
               new_units_over_limits=[tag(u) for u in new if u['ccn'] > 5 or u['nloc'] > 15 or u['params'] > 2])
    out = dict(lane=args.lane, files=len(mine), now=now,
               units_over_5=[tag(u) for u in sorted(units, key=lambda u: (-u['ccn'], -u['nloc'], u['file'], u['start']))
                             if u['ccn'] > 5][:400],
               skipped=reader.skipped)
    print(json.dumps(out, indent=1, ensure_ascii=False))
    if args.json_out:
        json.dump(out, open(args.json_out, 'w'), indent=1, ensure_ascii=False)


if __name__ == '__main__':
    main()
