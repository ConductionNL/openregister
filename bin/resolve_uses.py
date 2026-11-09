#!/usr/bin/env python3
"""Resolve merge conflicts that consist ONLY of PHP `use` lines or map entries; refuse everything else.

Usage: resolve_uses.py <file>...   (files hold diff3 conflict markers: git -c merge.conflictStyle=diff3 merge ...)
Per hunk, every non-blank line on the ours, base and theirs sides must be a `use` import or a map entry
(`X::class => Y::class,`, `'key' => X::class,`, `X::class,`). Then:
  use lines   -> union of ours and theirs (an unused import is harmless, a missing one is not)
  map entries -> union minus the entries a side deliberately deleted from base; a key bound to two values is refused
Exit 0 when every file was resolved and rewritten; exit 1 (files untouched) when any hunk is outside that shape.
"""
import re
import sys

USE = re.compile(r'^use (function |const )?\\?[A-Za-z_][\w\\]*( as \w+)?;\s*$')
ENTRY = re.compile(r"""^\s*(?P<key>(['"][^'"]+['"]|\\?[A-Za-z_][\w\\]*::class))\s*=>\s*(?P<val>(\\?[A-Za-z_][\w\\]*::class|['"][^'"]+['"]))\s*,?\s*$""")
LIST = re.compile(r'^\s*\\?[A-Za-z_][\w\\]*::class\s*,?\s*$')


def kind(line):
    if not line.strip():
        return 'blank'
    if USE.match(line):
        return 'use'
    if ENTRY.match(line) or LIST.match(line):
        return 'map'
    return None


def norm(line):
    return line.strip().rstrip(',')


def merge_hunk(ours, base, theirs):
    kinds = {kind(l) for l in ours + base + theirs} - {'blank'}
    if not kinds or None in kinds or len(kinds) > 1:
        return None
    if kinds == {'use'}:
        out, seen = [], set()
        for line in ours + theirs:
            if line.strip() and norm(line) not in seen:
                seen.add(norm(line)); out.append(line)
        return out
    deleted = {norm(l) for l in base if l.strip()} - ({norm(l) for l in ours} & {norm(l) for l in theirs})
    out, seen, keys = [], set(), {}
    for line in ours + theirs:
        n = norm(line)
        if not line.strip() or n in seen or n in deleted:
            continue
        m = ENTRY.match(line)
        if m:
            if keys.get(m.group('key'), m.group('val')) != m.group('val'):
                return None
            keys[m.group('key')] = m.group('val')
        seen.add(n)
        # Keep the trailing comma: in a PHP array the union must stay a valid list.
        out.append(line if line.rstrip().endswith(',') else line.rstrip('\n') + ',\n')
    return out


def resolve(text):
    lines = text.splitlines(keepends=True)
    out, i = [], 0
    while i < len(lines):
        if not lines[i].startswith('<<<<<<< '):
            out.append(lines[i]); i += 1; continue
        ours, base, theirs, part = [], [], [], 'ours'
        i += 1
        while i < len(lines) and not lines[i].startswith('>>>>>>> '):
            line = lines[i]
            if line.startswith('||||||| '):
                part = 'base'
            elif line.startswith('=======') and line.strip() == '=======':
                part = 'theirs'
            else:
                {'ours': ours, 'base': base, 'theirs': theirs}[part].append(line)
            i += 1
        if i >= len(lines):
            return None
        i += 1
        merged = merge_hunk(ours, base, theirs)
        if merged is None:
            return None
        out.extend(merged)
    return ''.join(out)


def main(paths):
    results = {}
    for path in paths:
        text = open(path).read()
        if '<<<<<<< ' not in text:
            print(f'REFUSE {path}: no conflict markers (binary, delete/modify or rename conflict)')
            return 1
        merged = resolve(text)
        if merged is None or '<<<<<<< ' in merged or '>>>>>>> ' in merged:
            print(f'REFUSE {path}: a conflict hunk holds more than use lines or map entries')
            return 1
        results[path] = merged
    for path, merged in results.items():
        open(path, 'w').write(merged)
        print(f'RESOLVED {path}')
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
