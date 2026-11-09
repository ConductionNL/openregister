#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Rewrite `lib/...php` paths in plain path lists after classes moved.

The old and new names come from appinfo/class-aliases.json, so one run catches up
with every move so far and a second run changes nothing. scripts/component-move.py
rewrites the lists inside the repository itself; this script is for lists kept
elsewhere, such as a lane's five-star-files.txt:

    python3 scripts/follow-moved-paths.py [--dry-run] <list> [<list> ...]

Only whole paths change, so `lib/Db/Foo.php` never matches inside `lib/Db/FooBar.php`.
"""
import argparse
import json
import os
import re
import sys

ROOT_NS = 'OCA\\OpenRegister\\'
ALIAS_FILE = 'appinfo/class-aliases.json'
LIB_PATH = re.compile(r'(?<![\w/.-])lib/[\w/.-]+\.php(?![\w/.-])')


def path_of(fqcn):
    """FQCN under the PSR-4 root -> repo-relative path."""
    return 'lib/' + fqcn[len(ROOT_NS):].replace('\\', '/') + '.php'


def path_map(alias_file):
    aliases = json.load(open(alias_file, encoding='utf-8'))['aliases']
    return {path_of(old): path_of(entry['class']) for old, entry in aliases.items()}


def follow(text, moved):
    """Return the text with moved paths replaced, and how many changed."""
    count = 0

    def repl(match):
        nonlocal count
        new = moved.get(match.group(0))
        if new is None:
            return match.group(0)
        count += 1
        return new

    return LIB_PATH.sub(repl, text), count


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('lists', nargs='+')
    parser.add_argument('--aliases', default=ALIAS_FILE)
    parser.add_argument('--dry-run', action='store_true')
    args = parser.parse_args()
    moved = path_map(args.aliases)
    total = 0
    for name in args.lists:
        with open(name, encoding='utf-8') as handle:
            text = handle.read()
        new_text, count = follow(text, moved)
        total += count
        print(f'{name}: {count} path(s) moved')
        if count and not args.dry_run:
            scratch = f'{name}.follow.{os.getpid()}'
            with open(scratch, 'w', encoding='utf-8') as handle:
                handle.write(new_text)
            os.replace(scratch, name)
    print(f"{total} path(s) in all")
    return 0


if __name__ == '__main__':
    sys.exit(main())
