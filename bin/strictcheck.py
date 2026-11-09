#!/usr/bin/env python3
"""Exit 1 when a PR adds a strict_types lib file while removing code from a lib file that declares none.

Same rule as ~/sig-lanes/shared/closeout/strictcheck.py, read over git instead of the REST API:
moved code changes behaviour in that case (coercion becomes TypeError). Any error exits non-zero, which
the caller treats as "review" (fail closed).
Usage: strictcheck.py <openregister clone> <head sha>
"""
import subprocess
import sys


def git(clone, *args):
    return subprocess.run(['git', '-C', clone, *args], capture_output=True, text=True, check=True).stdout


def main(clone, head):
    base = git(clone, 'rev-parse', 'origin/development').strip()
    fork = git(clone, 'merge-base', base, head).strip()
    status = {}
    for line in git(clone, 'diff', '-M', '--name-status', fork, head).splitlines():
        parts = line.split('\t')
        status[parts[-1]] = parts[0][0]
    deletions = {}
    for line in git(clone, 'diff', '-M', '--numstat', fork, head).splitlines():
        added, deleted, path = line.split('\t')[:3]
        deletions[path] = int(deleted) if deleted.isdigit() else 0
    php = [p for p in status if p.startswith('lib/') and p.endswith('.php')]
    strict_added = []
    for path in php:
        if status[path] != 'A':
            continue
        patch = git(clone, 'diff', fork, head, '--', path)
        if '+declare(strict_types=1);' in patch:
            strict_added.append(path)
    if not strict_added:
        return 0
    lenient = []
    for path in php:
        if status[path] != 'M' or deletions.get(path, 0) < 15:
            continue
        try:
            raw = git(clone, 'show', f'{base}:{path}')
        except subprocess.CalledProcessError:
            continue
        if raw and 'declare(strict_types=1)' not in raw:
            lenient.append(path)
    if lenient:
        print(f'STRICT-MOVE: strict new {len(strict_added)} file(s), code removed from non-strict {", ".join(lenient[:5])}')
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1], sys.argv[2]))
