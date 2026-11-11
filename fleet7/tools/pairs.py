#!/usr/bin/env python3
"""Duplicate file pairs and high fan-in modules of one app, counted the way the full report counts them.

    python3 pairs.py <clone> [--json out.json]

Keep this file next to check_sig_maintainability.py (fleet6/tools). Prints the redundant lines per
file, the duplicate pairs (two files sharing 6-line windows, or one file with itself) most windows
first, and every PHP module with fan-in over 10, largest first: the lines that module coupling counts.
"""
import argparse
import collections
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
tools = os.path.join(HERE, 'tools')
if os.path.isdir(tools):
    os.environ.setdefault('HYDRA_GATE_TOOLS_DIR', tools)
sys.path.insert(0, HERE)
import check_sig_maintainability as m  # noqa: E402


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('app')
    ap.add_argument('--json', dest='json_out')
    args = ap.parse_args()
    app = os.path.abspath(args.app)
    files = m.production_files(app, m.Scope(app))
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
                pairs[tuple(sorted((rel, first[0])))] += 1
            else:
                seen[lang].setdefault(key, (rel, i))
    php = {r: lang for r, lang in files.items() if lang == 'php'}
    deps = m.php_dependencies(app, php)
    incoming = collections.defaultdict(set)
    for src, targets in deps.items():
        for t in targets:
            incoming[t].add(src)
    loc = {r: len(m.code_lines(m.read_text(os.path.join(app, r)), 'php')) for r in deps}
    high = sorted((r for r in deps if len(incoming[r]) > 10), key=lambda r: (-loc[r], r))
    out = dict(
        redundant_lines=sum(len(s) for s in red.values()),
        redundant_by_file=[f'{f} {len(s)}' for f, s in sorted(red.items(), key=lambda x: (-len(x[1]), x[0]))[:150]],
        duplicate_pairs=[f'{a} <-> {b} windows={n}' if a != b else f'{a} (inside one file) windows={n}'
                         for (a, b), n in pairs.most_common(150)],
        php_module_lines=sum(loc.values()),
        fan_in_over_10_lines=sum(loc[r] for r in high),
        fan_in_over_10=[f'{r} fan-in {len(incoming[r])} lines {loc[r]}' for r in high],
        fan_in_callers={r: sorted(incoming[r]) for r in high[:15]},
    )
    print(json.dumps(out, indent=1, ensure_ascii=False))
    if args.json_out:
        json.dump(out, open(args.json_out, 'w'), indent=1, ensure_ascii=False)


if __name__ == '__main__':
    main()
