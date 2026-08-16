#!/usr/bin/env python3
"""Standalone gate-46 (spec-anchor-existence) counter — byte-for-byte the same
resolution logic as hydra scripts/run-hydra-gates.sh gate 46, but repo-wide
(not diff-scoped) so we can measure before/after totals per app.

Usage: gate46_count.py <app-root>
Prints: <count of unresolved @spec targets>
"""
import os, re, sys

TAG = re.compile(r'@spec\s+(openspec/[^\s`\'"]+)')


def slugify(text):
    t = text.strip().lower()
    t = re.sub(r'[^a-z0-9]+', '-', t).strip('-')
    return t


def has_anchor(md_path, fragment, _cache={}):
    if md_path not in _cache:
        hs = set()
        try:
            with open(md_path, encoding='utf-8', errors='replace') as f:
                for line in f:
                    m = re.match(r'^\s*#{1,6}\s+(.+?)\s*$', line)
                    if m:
                        hs.add(slugify(m.group(1)))
        except OSError:
            pass
        _cache[md_path] = hs
    return fragment in _cache[md_path]


def iter_files(root):
    for base in ('lib', 'src'):
        bdir = os.path.join(root, base)
        if not os.path.isdir(bdir):
            continue
        for dp, dns, fns in os.walk(bdir):
            if any(x in dp for x in ('/vendor/', '/node_modules/', '/dist/', '/build/')):
                continue
            for fn in fns:
                if fn.endswith(('.php', '.vue', '.js', '.ts', '.md')):
                    yield os.path.join(dp, fn)


def main():
    root = os.path.abspath(sys.argv[1])
    fails = 0
    total = 0
    for fp in iter_files(root):
        try:
            src = open(fp, encoding='utf-8', errors='replace').read()
        except OSError:
            continue
        for m in TAG.finditer(src):
            total += 1
            target = m.group(1)
            if '#' in target:
                path, frag = target.split('#', 1)
            else:
                path, frag = target, None
            absf = os.path.join(root, path)
            if not os.path.exists(absf):
                fails += 1
                continue
            if frag and absf.endswith('.md') and not has_anchor(absf, frag):
                fails += 1
    print(f'{os.path.basename(root)}\ttags={total}\tbroken={fails}')


if __name__ == '__main__':
    main()
