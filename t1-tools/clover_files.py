#!/usr/bin/env python3
"""Per-file statement coverage from a PHPUnit clover report.

Usage:
  clover_files.py <clover.xml> [--files a.php b.php ...] [--json out.json] [--total]
Paths are printed relative to the app root (the part from `lib/` on).
With --files, prints those files only plus their combined total, which is how a
lane reports before and after for its own scope.
"""
import json, sys, xml.etree.ElementTree as ET

args = sys.argv[1:]
clover = args[0]
want = None
if '--files' in args:
    i = args.index('--files') + 1
    want = []
    while i < len(args) and not args[i].startswith('--'):
        want.append(args[i]); i += 1
out = args[args.index('--json') + 1] if '--json' in args else None

rows = {}
for f in ET.parse(clover).getroot().iter('file'):
    name = f.get('name')
    k = name.find('/lib/')
    rel = name[k + 1:] if k >= 0 else name
    m = f.find('metrics')
    if m is None:
        continue
    st, cov = int(m.get('statements', 0)), int(m.get('coveredstatements', 0))
    rows[rel] = (st, cov)

if want is not None:
    tot_s = tot_c = 0
    for w in want:
        st, cov = rows.get(w, (None, None))
        if st is None:
            print(f'{w}\tnot in report (no statements, or not run)')
            continue
        tot_s += st; tot_c += cov
        print(f'{w}\t{cov}/{st}\t{(100*cov/st if st else 100):.1f}%')
    print(f'TOTAL\t{tot_c}/{tot_s}\t{(100*tot_c/tot_s if tot_s else 100):.1f}%')
else:
    s = sum(v[0] for v in rows.values()); c = sum(v[1] for v in rows.values())
    print(f'files {len(rows)} statements {s} covered {c} {100*c/s:.2f}%')
if out:
    json.dump({k: {'statements': v[0], 'covered': v[1]} for k, v in rows.items()}, open(out, 'w'))
