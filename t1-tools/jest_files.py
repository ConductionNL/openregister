#!/usr/bin/env python3
"""Per-file line coverage from a jest json-summary report.

Usage:
  jest_files.py <coverage-summary.json> [--lane T18 [--scopes scopes.json]] [--files a.vue b.js ...]
With --lane, reads that lane's files from scopes.json (default: next to this script).
Prints each file's covered/total lines and the combined total for the scope.
"""
import json, os, sys

args = sys.argv[1:]
summary = json.load(open(args[0]))
want = []
if '--lane' in args:
    lane = args[args.index('--lane') + 1]
    sc = args[args.index('--scopes') + 1] if '--scopes' in args else os.path.join(os.path.dirname(os.path.abspath(__file__)), 'scopes.json')
    want = json.load(open(sc))[lane]['files']
if '--files' in args:
    i = args.index('--files') + 1
    while i < len(args) and not args[i].startswith('--'):
        want.append(args[i]); i += 1

rows = {}
for k, v in summary.items():
    if k == 'total':
        continue
    i = k.find('/src/')
    rows[k[i + 1:] if i >= 0 else k] = (v['lines']['total'], v['lines']['covered'])

if not want:
    t = summary['total']['lines']
    print(f"total {t['covered']}/{t['total']} {t['pct']}%")
    sys.exit(0)
tl = tc = 0
for w in want:
    if w not in rows:
        print(f'{w}\tnot in report (add it to --collectCoverageFrom)')
        continue
    n, c = rows[w]; tl += n; tc += c
    print(f'{w}\t{c}/{n}\t{(100*c/n if n else 100):.1f}%')
print(f'TOTAL\t{tc}/{tl}\t{(100*tc/tl if tl else 100):.1f}%')
