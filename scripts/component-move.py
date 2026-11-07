#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Move the classes of one component into lib/<Component>/, keeping every old name working.

The class map is a CSV with the columns `file` (path under lib/) and `target_path`
(path under lib/ after the move), as in ARCH-PLAN-openregister.classmap.csv. Rows
whose `target_component` is the given component and whose target differs from the
file are moved. The script:

1. git-mvs each file and rewrites its namespace line;
2. adds `use` lines where a class referenced a former namespace neighbour without
   one (in moved files, and in files that stay behind), and fully qualifies
   namespace-relative names such as `Object\\SaveObject` in moved files;
3. rewrites every fully qualified old name (one, two or four backslashes, as in
   PHP, strings, JSON and NEON) in lib/, tests/, appinfo/, .sig/ and the root config
   and baseline files, and every `lib/<old path>` to `lib/<new path>`;
4. keeps `__DIR__`-relative paths and `dirname(__DIR__, n)` in moved files
   pointing at the same file, and refuses frozen classes (events, Workflow
   Engine operations, migrations, the classes kept at their name);
5. adds an entry per moved class to appinfo/class-aliases.json: `eager` when
   other apps reference the class (column `fleet_refs`), `service` for every class, so the
   container returns one shared instance under both names.

The approved map is scripts/component-map.csv. A class added after the map was
made gets a row with the target of the class it was split from. --skip leaves a
file of the component where it is for now, for one another branch is still changing.

Path lists kept outside the repository follow a move with scripts/follow-moved-paths.py.

Run it from the repository root on a clean tree, then review `git diff`:

    python3 scripts/component-move.py --classmap scripts/component-map.csv --component Search --dry-run
    python3 scripts/component-move.py --classmap scripts/component-map.csv --component Search

Baselines after a move. The script rewrites paths and names in
phpstan-baseline*.neon, psalm-baseline.xml and phpmd.baseline.xml, which keeps the
findings matched. If a tool still reports a moved finding as new, regenerate that
baseline in the same PR and check that the finding count did not change:

    vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon
    vendor/bin/psalm --set-baseline=psalm-baseline.xml
    vendor/bin/phpmd lib text phpmd.xml --generate-baseline

What the script reports but does not fix: class names built from strings (a
namespace prefix followed by concatenation), and directory paths in config files
that no longer exist.
"""
import argparse
import csv
import json
import os
import re
import subprocess
import sys

ROOT_NS = 'OCA\\OpenRegister'
TEXT_DIRS = ('lib', 'tests', 'appinfo', '.sig')
ROOT_FILES = re.compile(r'^(phpstan[\w.-]*\.neon|psalm[\w.-]*\.xml|phpmd[\w.-]*\.xml|phpunit[\w.-]*\.xml|phpcs\.xml|composer\.json)$')
TEXT_EXT = ('.php', '.xml', '.neon', '.json', '.yml', '.yaml', '.txt')
FQCN_TOKEN = re.compile(r'OCA((?:\\+[A-Za-z_][A-Za-z0-9_]*)+)')
ALIAS_FILE = 'appinfo/class-aliases.json'
# Never moved: event classes (listeners and dispatchTyped match the class name,
# and WebhookService builds event names from strings), Workflow Engine operations
# (Nextcloud stores their class in oc_flow_operations), migrations (recorded by
# version), and the classes kept at their name for other apps (move plan D3).
FROZEN_DIRS = ('Event/', 'WorkflowEngine/', 'Migration/', 'AppInfo/', 'Contract/', 'Kernel/')
FROZEN_FILES = {
    'Service/ObjectService.php', 'Db/SchemaMapper.php', 'Db/RegisterMapper.php',
    'Db/ObjectEntity.php', 'Db/Schema.php', 'Db/Register.php',
}
EVENT_CLASS = re.compile(r'\bextends\s+\\?(?:OCP\\EventDispatcher\\)?\w*Event\b')
DIR_PATH = re.compile(r"__DIR__\s*\.\s*(['\"])(/[^'\"]*)\1")
DIRNAME_DIR = re.compile(r'dirname\(\s*__DIR__\s*(?:,\s*(\d+)\s*)?\)')


def fqcn_of(path):
    """lib-relative path -> FQCN under the PSR-4 root."""
    return ROOT_NS + '\\' + path[:-4].replace('/', '\\')


def split_fqcn(fqcn):
    ns, _, short = fqcn.rpartition('\\')
    return ns, short


def strip_strings_and_comments(src):
    """Blank comments and string literals, keeping every offset and newline."""
    blank = lambda m: re.sub(r'[^\n]', ' ', m.group(0))
    pattern = r"/\*.*?\*/|//[^\n]*|#(?!\[)[^\n]*|'(?:[^'\\]|\\.)*'|\"(?:[^\"\\]|\\.)*\""
    return re.sub(pattern, blank, src, flags=re.S)


DOC_TYPE_TAG = re.compile(r'@(?:param|return|var|throws|property|method|template|extends|implements|mixin|psalm-|phpstan-)')


def reference_view(src):
    """Blank string literals and comments except the lines of a type tag, which name classes as code does."""
    def keep_tags(m):
        if m.group(0)[:1] in ('\'', '"'):
            return re.sub(r'[^\n]', ' ', m.group(0))
        return '\n'.join(ln if DOC_TYPE_TAG.search(ln) else re.sub(r'.', ' ', ln) for ln in m.group(0).split('\n'))
    pattern = r"/\*.*?\*/|//[^\n]*|#(?!\[)[^\n]*|'(?:[^'\\]|\\.)*'|\"(?:[^\"\\]|\\.)*\""
    return re.sub(pattern, keep_tags, src, flags=re.S)


def imported_names(src):
    """Short names a file imports or declares, so no `use` is added for them."""
    names = set()
    for imp in re.findall(r'^use\s+([^;]+);', src, re.M):
        group = re.match(r'(?:function\s+|const\s+)?([^{]*)\{([^}]*)\}', imp.strip())
        parts = [p.strip() for p in group.group(2).split(',')] if group else [imp.strip()]
        for part in parts:
            if not part:
                continue
            alias = part.split(' as ')
            names.add((alias[1] if len(alias) > 1 else alias[0].rsplit('\\', 1)[-1]).strip())
    names.update(re.findall(r'^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)', src, re.M))
    return names


def expand_group_uses(src, moved_old):
    """Rewrite `use A\\{B, C};` into single lines when a member moves."""
    def repl(match):
        prefix, members = match.group(1), match.group(2)
        full = [prefix + m.strip() for m in members.split(',') if m.strip()]
        if not any(f.split(' as ')[0].strip() in moved_old for f in full):
            return match.group(0)
        return '\n'.join(f'use {f};' for f in full)
    return re.sub(r'^use\s+([A-Za-z0-9_\\]+\\)\{([^}]*)\};', repl, src, flags=re.M)


def add_uses(src, fqcns):
    """Insert `use` lines after the existing top-level imports (or the namespace line)."""
    if not fqcns:
        return src
    lines = ''.join(f'use {f};\n' for f in sorted(fqcns))
    uses = list(re.finditer(r'^use\s+[^;]+;\n', src, re.M))
    head = re.search(r'^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s', src, re.M)
    uses = [u for u in uses if head is None or u.start() < head.start()]
    if uses:
        at = uses[-1].end()
        return src[:at] + lines + src[at:]
    ns = re.search(r'^namespace\s+[^;]+;\n', src, re.M)
    at = ns.end()
    return src[:at] + '\n' + lines + src[at:]


def rewrite_fqcns(text, old_to_new):
    def repl(match):
        seps = re.findall(r'\\+', match.group(1))
        sep = seps[0]
        if any(s != sep for s in seps):
            return match.group(0)
        name = 'OCA\\' + '\\'.join(re.split(r'\\+', match.group(1))[1:])
        new = old_to_new.get(name)
        return new.replace('\\', sep) if new else match.group(0)
    return FQCN_TOKEN.sub(repl, text)


def qualify_relative_names(src, old_ns, new_of, imported, moved_only=False):
    """`Object\\SaveObject` resolves against the namespace; make it absolute before the namespace changes.

    With moved_only, as for a file that stays, only names that resolve to a moving class change."""
    code_spans = strip_strings_and_comments(src)
    out, last = [], 0
    for match in re.finditer(r'(?<![\\\w$>:])([A-Z]\w*(?:\\[A-Z]\w*)+)\b', code_spans):
        line_start = code_spans.rfind('\n', 0, match.start()) + 1
        if re.match(r'\s*(use|namespace)\s', code_spans[line_start:match.start() + 1]):
            continue
        first = match.group(1).split('\\', 1)[0]
        if first in imported or first == 'OCA':
            continue
        old = old_ns + '\\' + match.group(1)
        if moved_only and old not in new_of:
            continue
        target = new_of.get(old, old)
        out.append(src[last:match.start()])
        out.append('\\' + target)
        last = match.end()
    out.append(src[last:])
    return ''.join(out)


def frozen_reason(rel, src):
    """Why a class may not move, or None."""
    if rel.startswith(FROZEN_DIRS) or rel in FROZEN_FILES:
        return 'frozen by the move plan'
    if EVENT_CLASS.search(src):
        return 'an event class'
    return None


def rebase_dir_paths(src, old_rel, new_rel, warnings):
    """Keep `__DIR__ . '/..'` and `dirname(__DIR__, n)` pointing at the same place after a move."""
    old_dir = os.path.dirname(os.path.join('lib', old_rel))
    new_dir = os.path.dirname(os.path.join('lib', new_rel))

    def path(match):
        target = os.path.normpath(os.path.join(old_dir, match.group(2).lstrip('/')))
        rel = os.path.relpath(target, new_dir)
        return f'__DIR__ . {match.group(1)}/{rel}{match.group(1)}'

    def dirname(match):
        levels = int(match.group(1) or 1)
        base = old_dir
        for _ in range(levels):
            base = os.path.dirname(base)
        rel = os.path.relpath(new_dir, base or '.')
        if rel.startswith('..'):
            warnings.append(f'lib/{new_rel}: dirname(__DIR__, {levels}) has no equivalent after the move')
            return match.group(0)
        depth = len(rel.split(os.sep)) if rel != '.' else 0
        return 'dirname(__DIR__)' if depth == 1 else f'dirname(__DIR__, {depth})'

    return DIRNAME_DIR.sub(dirname, DIR_PATH.sub(path, src))


def load_rows(classmap, component, lib, skip=()):
    rows = []
    with open(classmap, newline='') as handle:
        for row in csv.DictReader(handle):
            if row['target_component'] != component or row['target_path'] == row['file'] or row['file'] in skip:
                continue
            if not os.path.isfile(os.path.join(lib, row['file'])):
                sys.exit(f'not in lib/: {row["file"]}')
            reason = frozen_reason(row['file'], open(os.path.join(lib, row['file']), encoding='utf-8').read())
            if reason:
                sys.exit(f'refusing to move lib/{row["file"]}: {reason}')
            rows.append(row)
    return rows


def tracked_text_files():
    files = subprocess.run(['git', 'ls-files'], capture_output=True, text=True, check=True).stdout.split('\n')
    for path in files:
        if not path or path == ALIAS_FILE:
            continue
        top = path.split('/', 1)[0]
        if (top in TEXT_DIRS and path.endswith(TEXT_EXT)) or ('/' not in path and ROOT_FILES.match(path)):
            yield path



def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--classmap', required=True)
    parser.add_argument('--component', required=True)
    parser.add_argument('--dry-run', action='store_true')
    parser.add_argument('--skip', action='append', default=[], help='file under lib/ that stays for now')
    args = parser.parse_args()
    lib = 'lib'
    rows = load_rows(args.classmap, args.component, lib, set(args.skip))
    if not rows:
        sys.exit(f'nothing to move for {args.component}')

    moves = {r['file']: r['target_path'] for r in rows}
    new_of = {fqcn_of(old): fqcn_of(new) for old, new in moves.items()}
    fleet = {fqcn_of(r['file']): int(r.get('fleet_refs') or 0) for r in rows}
    all_classes = {}
    for dirpath, _dirs, names in os.walk(lib):
        for name in names:
            if name.endswith('.php'):
                rel = os.path.relpath(os.path.join(dirpath, name), lib)
                all_classes[fqcn_of(rel)] = rel
    by_ns = {}
    for fqcn in all_classes:
        ns, short = split_fqcn(fqcn)
        by_ns.setdefault(ns, {})[short] = fqcn
    affected_ns = {split_fqcn(old)[0] for old in new_of}

    print(f'{len(moves)} files move to lib/{args.component}/')
    if args.dry_run:
        for old, new in sorted(moves.items()):
            print(f'  lib/{old} -> lib/{new}')
        return

    warnings = []
    # Steps 1 and 2: per file in an affected namespace, namespace-relative fixes before anything moves.
    for fqcn, rel in sorted(all_classes.items()):
        old_ns = split_fqcn(fqcn)[0]
        if old_ns not in affected_ns:
            if fqcn not in new_of:
                path = os.path.join(lib, rel)
                src = open(path, encoding='utf-8').read()
                fixed = qualify_relative_names(src, old_ns, new_of, imported_names(src), moved_only=True)
                if fixed != src:
                    open(path, 'w', encoding='utf-8').write(fixed)
            continue
        path = os.path.join(lib, rel)
        src = open(path, encoding='utf-8').read()
        src = expand_group_uses(src, set(new_of))
        imported = imported_names(src)
        own_new_ns = split_fqcn(new_of.get(fqcn, fqcn))[0]
        src = qualify_relative_names(src, old_ns, new_of, imported, moved_only=fqcn not in new_of)
        needed = set()
        refs = reference_view(src)
        for short, neighbour in by_ns[old_ns].items():
            if neighbour == fqcn or short in imported:
                continue
            target = new_of.get(neighbour, neighbour)
            if split_fqcn(target)[0] == own_new_ns:
                continue
            if re.search(rf'(?<![\\\w$]){re.escape(short)}\b', refs):
                needed.add(target)
        clash = {split_fqcn(f)[1] for f in needed} & imported
        if clash:
            warnings.append(f'{rel}: short name clash {sorted(clash)}; add the use lines by hand')
            needed = {f for f in needed if split_fqcn(f)[1] not in clash}
        src = add_uses(src, needed)
        if fqcn in new_of:
            src = re.sub(r'^namespace\s+[^;]+;', lambda _m: f'namespace {own_new_ns};', src, count=1, flags=re.M)
            src = rebase_dir_paths(src, rel, moves[rel], warnings)
        open(path, 'w', encoding='utf-8').write(src)

    for old, new in sorted(moves.items()):
        os.makedirs(os.path.dirname(os.path.join(lib, new)), exist_ok=True)
        subprocess.run(['git', 'mv', os.path.join(lib, old), os.path.join(lib, new)], check=True)

    # Step 3: names and paths everywhere.
    path_map = {f'lib/{old}': f'lib/{new}' for old, new in moves.items()}
    path_re = re.compile('|'.join(re.escape(p) for p in sorted(path_map, key=len, reverse=True)))
    changed = 0
    for path in tracked_text_files():
        if not os.path.isfile(path):
            continue
        text = open(path, encoding='utf-8', errors='surrogateescape').read()
        new_text = path_re.sub(lambda m: path_map[m.group(0)], rewrite_fqcns(text, new_of))
        if new_text != text:
            open(path, 'w', encoding='utf-8', errors='surrogateescape').write(new_text)
            changed += 1
        for ns in affected_ns:
            for sep in ('\\', '\\\\'):
                if re.search(re.escape(ns.replace('\\', sep) + sep) + r"['\"]\s*\.", text):
                    warnings.append(f'{path}: builds a class name from the prefix {ns}\\')
    old_dirs = {os.path.dirname(f'lib/{old}') for old in moves}
    old_dirs = {d for d in old_dirs if not os.path.isdir(d)}
    for path in tracked_text_files():
        if '/' in path or not path.endswith(('.xml', '.neon')):
            continue
        text = open(path, encoding='utf-8', errors='surrogateescape').read()
        for directory in old_dirs:
            if re.search(re.escape(directory) + r'/?["<\s]', text):
                warnings.append(f'{path}: names the directory {directory}/, which no longer exists')

    # Step 4: alias map.
    data = json.load(open(ALIAS_FILE)) if os.path.isfile(ALIAS_FILE) else {'aliases': {}}
    for old, new in sorted(new_of.items()):
        data['aliases'][old] = {
            'class': new,
            'eager': fleet[old] > 0,
            # Every moved class is also a container alias: fetching it by its old
            # name must return the instance the new name returns, not a second one.
            'service': True,
        }
    with open(ALIAS_FILE, 'w') as handle:
        json.dump(data, handle, indent='\t', sort_keys=False)
        handle.write('\n')

    print(f'{changed} files rewritten, {len(new_of)} alias entries')
    for warning in sorted(set(warnings)):
        print('WARNING', warning)


if __name__ == '__main__':
    main()
