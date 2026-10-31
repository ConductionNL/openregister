#!/usr/bin/env python3
"""Golden test for the @spec anchor repointer.

Runs repoint.py --apply over a fixture app that exercises every resolver shape
and every dangling cause, and compares the JSON report, the dangling review list
and the rewritten source files with golden.json next to this file.

Run:  python3 test_golden.py            (exit 0 = match)
      UPDATE_GOLDEN=1 python3 test_golden.py   (rewrite golden.json)
"""
import json
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
GOLDEN = os.path.join(HERE, 'golden.json')
APP = 'golden-app'


def _write(root, rel, content):
    path = os.path.join(root, rel)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w', encoding='utf-8', newline='') as f:
        f.write(content)


SPECS = {
    'openspec/specs/widget-registry/spec.md':
        "# Widget Registry\n\n## Requirements\n\n"
        "### Requirement: The system MUST register widgets by slug\n\n"
        "### Requirement: Listing\n\n#### Scenario: Mixed Case.\n",
    'openspec/specs/catalog/spec.md': "# Catalog\n\n### Requirement: Catalog items\n\n### Plain heading\n",
    'openspec/specs/sectioned/spec.md': "# Sectioned\n\n### Requirement: Section heading wins\n",
}

TASKS = {
    'openspec/changes/archive/2026-05-01-retrofit-widgets/tasks.md':
        "# Tasks\n\n"
        "- [x] task-1: widget-registry#REQ-001 — The system MUST register widgets by slug (retroactive annotation)\n"
        "- [x] task-2: ghost-capability#REQ-002 — Something gone (retroactive annotation)\n"
        "- [x] **task-3**: widget-registry#REQ-003 — \"No such heading here\"\n"
        "- [ ] task-4: catalog#CAT-1 quoted \"Catalog items\" wrapped\n"
        "  over a continuation line\n"
        "## sectioned\n"
        "- [x] task-5: Section heading wins — no inline cap\n"
        "- [x] task-6 (no cap and no dash)\n"
        "- [x] task-7: catalog#C-7 — Plain heading\n"
        "- [x] task-8: catalog#C-8 \"Catalog items\"\n\n  wrapped after a blank line\n"
        "# Footer\n",
    'openspec/changes/live-change/tasks.md':
        "## catalog\n- [x] task-1: — Catalog items (cross-cap with widgets)\n"
        "- [x] task-2: catalog#REQ-9\n  - [ ] nested sub item\n",
    'openspec/archive/old-change/tasks.md': "- [x] task-1: widget-registry#R-1 — Listing\n",
    'openspec/changes/archive/2026-01-01-twin/tasks.md': "- [x] task-1: catalog#X — Catalog items\n",
    'openspec/changes/archive/2026-02-01-twin/tasks.md': "- [x] task-1: catalog#X — Catalog items\n",
}

REFS = [
    'openspec/changes/retrofit-widgets/tasks.md#task-1',
    'openspec/changes/retrofit-widgets/tasks.md#task-2',
    'openspec/changes/retrofit-widgets/tasks.md#task-3',
    'openspec/changes/retrofit-widgets/tasks.md#task-4',
    'openspec/changes/retrofit-widgets/tasks.md#task-5',
    'openspec/changes/retrofit-widgets/tasks.md#task-6',
    'openspec/changes/retrofit-widgets/tasks.md#task-99',
    'openspec/changes/retrofit-widgets/tasks.md#task-7',
    'openspec/changes/live-change/tasks.md#task-1',
    'openspec/changes/live-change/tasks.md#task-2',
    'openspec/changes/old-change/tasks.md#task-1',
    'openspec/changes/twin/tasks.md#task-1',
    'openspec/changes/missing-change/tasks.md#task-1',
    'openspec/changes/catalog/tasks.md',
    'openspec/changes/not-a-cap/tasks.md',
    'openspec/changes/retrofit-widgets/tasks.md#notatask',
    'openspec/changes/x/specs/widget-registry/spec.md',
    'openspec/changes/x/specs/widget-registry/spec.md#Listing.',
    'openspec/changes/x/specs/widget-registry/spec.md#gone-anchor',
    'openspec/changes/x/specs/nowhere/spec.md#a',
    'openspec/specs/widget-registry/spec.md#Requirement-Listing.',
    'openspec/specs/widget-registry/spec.md#totally-gone',
    'openspec/specs/widget-registry/spec.md#requirement-listing',
    'openspec/changes/y/design.md#d',
    'openspec/missing/file.md',
]


def build(root):
    for rel, text in {**SPECS, **TASKS}.items():
        _write(root, rel, text)
    php = "<?php\nclass A {\n" + ''.join(
        f"    /**\n     * @spec {ref}\n     */\n    public function m{i}(): int {{ return {i}; }}\n"
        for i, ref in enumerate(REFS)) + "}\n"
    _write(root, 'lib/A.php', php)
    _write(root, 'src/b.js', "// @spec openspec/changes/retrofit-widgets/tasks.md#task-1\r\nexport const b = 1;\r\n")
    _write(root, 'src/deep/c.vue', "<!-- @spec openspec/changes/twin/tasks.md#task-1 -->\n")
    _write(root, 'src/vendor/skip.js', "// @spec openspec/changes/retrofit-widgets/tasks.md#task-1\n")
    with open(os.path.join(root, 'lib/latin1.php'), 'wb') as f:
        f.write(b"<?php // @spec openspec/missing/file.md \xe9\n")
    for i in range(12):
        _write(root, f'lib/Many{i}.php', f"<?php // @spec openspec/changes/y/proposal.md#p{i}\n")


def _stable(text):
    """Order-independent view of a dangling list: os.walk order differs per filesystem."""
    out, items = [], []
    for line in text.split('\n') + ['## end']:
        if line.startswith('- '):
            items.append(line)
            continue
        more = [i for i in items if i.startswith('- …')]
        if more:
            out.append(f'{len(items) - 1} items, {more[0]}')
        else:
            out.extend(sorted(items))
        items = []
        out.append(line)
    return out


def run(root, apply):
    args = [sys.executable, os.path.join(HERE, 'repoint.py'), root] + (['--apply'] if apply else [])
    out = subprocess.run(args, check=False, capture_output=True, text=True)
    base = os.path.join(HERE, APP)
    stdout = json.loads(out.stdout) if out.returncode == 0 else out.stdout
    result = {'exit': out.returncode, 'stdout': json.loads(json.dumps(stdout).replace(root, '<root>')), 'stderr': out.stderr}
    for ext in ('.report.json', '.dangling.md'):
        with open(base + ext, encoding='utf-8') as f:
            result[ext] = f.read().replace(root, '<root>')
        os.remove(base + ext)
    report = json.loads(result['.report.json'])
    report['changed_files'] = sorted(report['changed_files'])
    result['.report.json'] = report
    result['.dangling.md'] = _stable(result['.dangling.md'])
    files = {}
    for dp, _, fns in os.walk(root):
        for fn in fns:
            p = os.path.join(dp, fn)
            if '/openspec/' in p:
                continue
            with open(p, 'rb') as f:
                files[os.path.relpath(p, root)] = f.read().decode('latin-1')
    result['files'] = dict(sorted(files.items()))
    return result


def direct(root):
    """resolver.resolve() for every fixture ref, and the parsed task blocks of every tasks.md."""
    sys.path.insert(0, HERE)
    import resolver
    out = {'resolve': {}, 'blocks': {}}
    for ref in REFS:
        path, _, frag = ref.partition('#')
        out['resolve'][ref] = resolver.resolve(root, path, frag or None)
    for rel in TASKS:
        blocks = resolver._task_blocks(os.path.join(root, rel))
        out['blocks'][rel] = {str(k): list(v) for k, v in sorted(blocks.items())}
    return out


def main():
    tmp = tempfile.mkdtemp(prefix='spec-anchor-golden-')
    try:
        actual = {}
        for mode, apply in (('dry-run', False), ('apply', True)):
            root = os.path.join(tmp, mode, APP)
            build(root)
            actual[mode] = run(root, apply)
        root = os.path.join(tmp, 'direct', APP)
        build(root)
        actual['direct'] = direct(root)
    finally:
        shutil.rmtree(tmp)
    actual = json.loads(json.dumps(actual))
    if os.environ.get('UPDATE_GOLDEN') == '1':
        with open(GOLDEN, 'w', encoding='utf-8') as f:
            json.dump(actual, f, indent=1, sort_keys=True)
            f.write('\n')
    with open(GOLDEN, encoding='utf-8') as f:
        golden = json.load(f)
    if golden != actual:
        for mode in actual:
            for key in actual[mode]:
                if golden.get(mode, {}).get(key) != actual[mode][key]:
                    print(f'DIFF in {mode}/{key}')
        print('FAIL: output differs from golden.json')
        return 1
    print('PASS: repoint output matches golden.json')
    return 0


if __name__ == '__main__':
    sys.exit(main())
