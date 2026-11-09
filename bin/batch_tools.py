#!/usr/bin/env python3
"""Helpers for integrate.sh (copied from ~/sig-lanes/batch/batch_tools.py): shard the PHPUnit suite, sum shard results, find the PR behind a red tree."""
import argparse
import glob
import json
import os
import re
import subprocess
import sys

KEYS = ['Tests', 'Assertions', 'Errors', 'Failures', 'Warnings', 'Deprecations', 'PHPUnit Deprecations',
        'Notices', 'Risky', 'Incomplete', 'Skipped']


def shards(clone, count):
    """Write .batch-shard<i>.xml into the clone: the Unit suite split by file over equal measured time."""
    weights = {}
    try:
        weights.update(json.load(open(f'{clone}/.phpunit.cache/class-times.json')))
    except (OSError, ValueError):
        pass
    for cache in [f'{clone}/.phpunit.cache/test-results'] + glob.glob(f'{clone}/.phpunit.cache/shard*/test-results'):
        try:
            times = json.load(open(cache)).get('times', {})
        except (OSError, ValueError):
            continue
        per_class = {}
        for test, secs in times.items():
            short = test.split('::')[0].rsplit('\\', 1)[-1]
            per_class[short] = per_class.get(short, 0) + secs
        for short, secs in per_class.items():
            weights[short] = max(weights.get(short, 0), secs)
    files = []
    for root, _, names in os.walk(f'{clone}/tests/Unit'):
        files += [os.path.relpath(os.path.join(root, n), clone) for n in names if n.endswith('Test.php')]
    known = sorted(weights.values())
    default = known[len(known) // 2] if known else 1.0
    # Test files in one directory stay together: some use fixture classes declared in a sibling test file.
    # Architecture holds two thirds of the time and has no such pairs, so it is spread per file.
    units = {}
    for path in files:
        spread = path.startswith('tests/Unit/Architecture/')
        units.setdefault(path if spread else os.path.dirname(path), []).append(path)
    cost = lambda paths: sum(weights.get(os.path.basename(p)[:-4], default) for p in paths)
    bins = [[0.0, []] for _ in range(count)]
    for paths in sorted(units.values(), key=lambda u: -cost(u)):
        target = min(bins, key=lambda b: b[0])
        target[0] += cost(paths)
        target[1].extend(paths)
    source = open(f'{clone}/phpunit.xml').read()
    suite = re.compile(r'<testsuite name="Unit Tests">.*?</testsuite>', re.S)
    if not suite.search(source):
        sys.exit('no Unit Tests testsuite in phpunit.xml')
    for i, (secs, paths) in enumerate(bins, 1):
        entries = ''.join(f'\n            <file>{p}</file>' for p in sorted(paths))
        xml = suite.sub(lambda _: f'<testsuite name="Unit Tests">{entries}\n        </testsuite>', source, count=1)
        xml = re.sub(r'cacheDirectory="[^"]*"', f'cacheDirectory=".phpunit.cache/shard{i}"', xml, count=1)
        open(f'{clone}/.batch-shard{i}.xml', 'w').write(xml)
        print(f'shard {i}: {len(paths)} files, {secs:.0f}s measured')


def class_times(clone, out):
    """Write the measured seconds per test class of the last shard runs, for the next session's shards."""
    weights = {}
    for cache in glob.glob(f'{clone}/.phpunit.cache/shard*/test-results'):
        try:
            times = json.load(open(cache)).get('times', {})
        except (OSError, ValueError):
            continue
        for test, secs in times.items():
            short = test.split('::')[0].rsplit('\\', 1)[-1]
            weights[short] = round(weights.get(short, 0) + secs, 2)
    if weights:
        json.dump(weights, open(out, 'w'), sort_keys=True, indent=0)
    print(f'{len(weights)} classes')


def parse_result(log):
    """Return the summed counters of one PHPUnit log, or None when it printed no result line."""
    line = None
    for raw in open(log, errors='replace'):
        if raw.startswith('Tests: ') or raw.startswith('OK ('):
            line = raw.strip()
    if line is None:
        return None
    counts = dict.fromkeys(KEYS, 0)
    if line.startswith('OK ('):
        tests, assertions = re.match(r'OK \((\d+) tests?, (\d+) assertions?\)', line).groups()
        counts['Tests'], counts['Assertions'] = int(tests), int(assertions)
        return counts
    for part in line.rstrip('.').split(', '):
        key, _, value = part.partition(': ')
        counts[key] = counts.get(key, 0) + int(value)
    return counts


def aggregate(logs):
    """Print one PHPUnit-style Tests line over every shard; exit 1 when a shard has no result."""
    total = dict.fromkeys(KEYS, 0)
    for log in logs:
        counts = parse_result(log)
        if counts is None:
            print(f'{os.path.basename(log)}: no Tests line')
            sys.exit(1)
        for key, value in counts.items():
            total[key] = total.get(key, 0) + value
    parts = [f'Tests: {total["Tests"]}', f'Assertions: {total["Assertions"]}']
    parts += [f'{k}: {total[k]}' for k in KEYS[2:] if total[k]]
    print(', '.join(parts) + '.')


def phpunit_failures(logs):
    """Return (listed failing tests, failures plus errors the summaries report)."""
    found, reported = [], 0
    for log in logs:
        counts = parse_result(log) or {}
        reported += counts.get('Failures', 0) + counts.get('Errors', 0)
        section = False
        for raw in open(log, errors='replace'):
            line = raw.rstrip('\n')
            if re.match(r'There (was|were) \d+ (error|failure)s?:', line):
                section = True
            elif re.match(r'(There w|\d+ tests? triggered|--$|Tests: |FAILURES!|ERRORS!)', line):
                section = False
            elif section and re.match(r'\d+\) ', line):
                found.append(re.sub(r'^\d+\) ', '', line))
    return found, reported


def jest_failures(log):
    """Return the unique spec paths jest reported as FAIL."""
    ansi = re.compile(r'\x1b\[[0-9;]*m')
    specs = []
    for raw in open(log, errors='replace'):
        match = re.match(r'\s*FAIL\s+(\S+)', ansi.sub('', raw))
        if match and match.group(1) not in specs:
            specs.append(match.group(1))
    return specs


def stem(test):
    """Reduce a phpunit test id or a spec path to its file stem."""
    name = test.split(' ')[0].split('::')[0].rsplit('\\', 1)[-1]
    name = os.path.basename(name.split(':')[0])
    return re.sub(r'\.(spec|test)\.(js|ts)$', '', re.sub(r'\.php$', '', name))


def touches(test, changed):
    """True when the diff touches the failing test file or the class under test."""
    base = stem(test)
    subject = re.sub(r'(Golden)?Test$', '', base)
    for path in changed:
        name = os.path.basename(path)
        if name == f'{base}.php' or re.fullmatch(re.escape(base) + r'\.(spec|test)\.(js|ts)', name):
            return True
        if path.startswith('lib/') and name == f'{subject}.php':
            return True
        if path.startswith('src/') and re.fullmatch(re.escape(subject) + r'\.(js|ts|vue)', name):
            return True
    return False


def implicate(clone, included, logs, front_log):
    """Print 'pr sha test' when every failure implicates the same single PR; print nothing otherwise."""
    tests, reported = phpunit_failures(logs)
    if len(tests) != reported:
        return
    if front_log:
        specs = jest_failures(front_log)
        if not specs:
            return
        tests += specs
    if not tests:
        return
    prs = []
    for line in open(included):
        parts = line.split()
        if len(parts) >= 2:
            base = subprocess.run(['git', '-C', clone, 'merge-base', 'origin/development', parts[1]],
                                  capture_output=True, text=True).stdout.strip()
            changed = subprocess.run(['git', '-C', clone, 'diff', '--name-only', base, parts[1]],
                                     capture_output=True, text=True).stdout.split()
            prs.append((parts[0], parts[1], changed))
    blamed = set()
    for test in tests:
        hits = {pr for pr, _, changed in prs if touches(test, changed)}
        if not hits:
            return
        blamed |= hits
    if len(blamed) == 1:
        pr = blamed.pop()
        sha = next(s for p, s, _ in prs if p == pr)
        print(pr, sha, tests[0])


def main():
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest='cmd', required=True)
    p = sub.add_parser('shards'); p.add_argument('clone'); p.add_argument('count', type=int)
    p = sub.add_parser('times'); p.add_argument('clone'); p.add_argument('out')
    p = sub.add_parser('aggregate'); p.add_argument('logs', nargs='+')
    p = sub.add_parser('failures'); p.add_argument('logs', nargs='+')
    p = sub.add_parser('implicate'); p.add_argument('clone'); p.add_argument('included')
    p.add_argument('--front-log'); p.add_argument('logs', nargs='*')
    args = parser.parse_args()
    if args.cmd == 'shards':
        shards(args.clone, args.count)
    elif args.cmd == 'times':
        class_times(args.clone, args.out)
    elif args.cmd == 'aggregate':
        aggregate(args.logs)
    elif args.cmd == 'failures':
        print('\n'.join(phpunit_failures(args.logs)[0]))
    else:
        implicate(args.clone, args.included, args.logs, args.front_log)


if __name__ == '__main__':
    main()
