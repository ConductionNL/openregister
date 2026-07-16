#!/usr/bin/env python3
"""Unit test for the deterministic @spec anchor repointer.

Proves three properties on a synthetic fixture:
  1. A moved/archived anchor whose task line names a canonical capability +
     requirement heading is repointed to the exact canonical anchor.
  2. An anchor that cannot be resolved unambiguously is LEFT dangling (never guessed).
  3. The rewrite is comment-only: only the @spec line changes; PHP logic is byte-identical.

Run:  python3 test_repoint.py   (exit 0 = pass)
"""
import os, sys, tempfile, shutil, subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import resolver
import repoint


def _write(path, content):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)


def build_fixture(root):
    # Canonical capability spec with a real requirement heading.
    _write(os.path.join(root, 'openspec/specs/widget-registry/spec.md'),
           "# Widget Registry\n\n## Requirements\n\n"
           "### Requirement: The system MUST register widgets by slug\n\n"
           "The system MUST register widgets by slug.\n\n"
           "#### Scenario: register\n- WHEN registered\n- THEN listed\n")
    # Archived change (date-prefixed) whose task line encodes cap#REQ + the heading text.
    _write(os.path.join(root, 'openspec/changes/archive/2026-05-01-retrofit-widgets/tasks.md'),
           "# Tasks\n\n"
           "- [x] task-7: widget-registry#REQ-001 — The system MUST register widgets by slug (retroactive annotation)\n"
           "- [x] task-8: ghost-capability#REQ-002 — Something in a capability that does not exist (retroactive annotation)\n")
    # Source file: one repointable @spec, one genuinely-dangling @spec, plus real logic.
    php = (
        "<?php\n"
        "class WidgetService {\n"
        "    /**\n"
        "     * @spec openspec/changes/retrofit-widgets/tasks.md#task-7\n"
        "     */\n"
        "    public function register(string $slug): int {\n"
        "        return strlen($slug) + 42;\n"
        "    }\n"
        "    /**\n"
        "     * @spec openspec/changes/retrofit-widgets/tasks.md#task-8\n"
        "     */\n"
        "    public function ghost(): void {}\n"
        "}\n"
    )
    _write(os.path.join(root, 'lib/WidgetService.php'), php)
    return os.path.join(root, 'lib/WidgetService.php')


def main():
    tmp = tempfile.mkdtemp(prefix='spec-anchor-test-')
    try:
        php_path = build_fixture(tmp)
        before = open(php_path, encoding='utf-8').read()

        # Property 1 + 2: resolver classification.
        r7 = resolver.resolve(tmp, 'openspec/changes/retrofit-widgets/tasks.md', 'task-7')
        assert r7['category'] == 'REPOINT_ANCHOR', r7
        assert r7['new_target'] == (
            'openspec/specs/widget-registry/spec.md'
            '#requirement-the-system-must-register-widgets-by-slug'), r7['new_target']

        r8 = resolver.resolve(tmp, 'openspec/changes/retrofit-widgets/tasks.md', 'task-8')
        assert r8['category'] == 'DANGLING', r8   # ghost-capability has no canonical spec

        # Apply the repointer.
        sys.argv = ['repoint.py', tmp, '--apply']
        repoint.main()
        after = open(php_path, encoding='utf-8').read()

        # Property 1: task-7 anchor now points at the canonical spec.
        assert r7['new_target'] in after, "repointed anchor missing"
        # Property 2: task-8 left dangling, untouched.
        assert 'retrofit-widgets/tasks.md#task-8' in after, "dangling anchor was wrongly changed"

        # Property 3: comment-only. Every differing line must contain '@spec';
        # all non-@spec (logic) lines byte-identical.
        b_lines = before.splitlines()
        a_lines = after.splitlines()
        assert len(b_lines) == len(a_lines), "line count changed"
        for lb, la in zip(b_lines, a_lines):
            if lb != la:
                assert '@spec' in lb and '@spec' in la, f"non-@spec line changed: {lb!r} -> {la!r}"
        # Logic lines are identical.
        assert 'return strlen($slug) + 42;' in after
        assert 'public function register(string $slug): int' in after

        print("PASS: repoint anchor + leave dangling + comment-only proof")
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == '__main__':
    sys.exit(main())
