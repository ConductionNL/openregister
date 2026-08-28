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


def crlf_regression(tmp):
    """Property 4: a CRLF-line-ended source file keeps its CRLF endings verbatim.

    Regression for the procest finding (2026-07-16): Python's universal-newline
    read + default write normalised CRLF->LF, rewriting whole files and producing
    2462 non-@spec diff lines — a whitespace reformat wearing an anchor-repair hat.
    Only the @spec bytes may change; every other byte must survive untouched.
    """
    root = os.path.join(tmp, 'crlf-app')
    _write(os.path.join(root, 'openspec/specs/widget-registry/spec.md'),
           "# Widget Registry\n\n### Requirement: The system MUST register widgets by slug\n")
    _write(os.path.join(root, 'openspec/changes/archive/2026-05-01-retrofit-widgets/tasks.md'),
           "- [x] task-7: widget-registry#REQ-001 — The system MUST register widgets by slug\n")
    js_path = os.path.join(root, 'src/widget.js')
    os.makedirs(os.path.dirname(js_path), exist_ok=True)
    body = (
        "/**\r\n"
        " * @spec openspec/changes/retrofit-widgets/tasks.md#task-7\r\n"
        " */\r\n"
        "export function register(slug) {\r\n"
        "\treturn slug.length + 42\r\n"
        "}\r\n"
    )
    # newline='' so the fixture is written with genuine CRLF bytes on disk.
    with open(js_path, 'w', encoding='utf-8', newline='') as f:
        f.write(body)
    before = open(js_path, 'rb').read()
    assert before.count(b'\r\n') == 6, before

    sys.argv = ['repoint.py', root, '--apply']
    repoint.main()
    after = open(js_path, 'rb').read()

    # The anchor was repointed...
    assert b'openspec/specs/widget-registry/spec.md' in after, "CRLF file was not repointed"
    # ...and every line ending survived.
    assert after.count(b'\r\n') == 6, f"CRLF endings lost: {after!r}"
    assert b'\n' not in after.replace(b'\r\n', b''), "stray LF introduced"
    # Every non-@spec byte is identical.
    b_lines = before.split(b'\r\n')
    a_lines = after.split(b'\r\n')
    assert len(b_lines) == len(a_lines), "line count changed"
    for lb, la in zip(b_lines, a_lines):
        if lb != la:
            assert b'@spec' in lb and b'@spec' in la, f"non-@spec line changed: {lb!r} -> {la!r}"
    print("PASS: CRLF line endings preserved byte-exactly")


def trailing_punctuation_regression(tmp):
    """Property 5: a repointed anchor must satisfy gate-46 VERBATIM.

    Regression for the decidesk finding (2026-07-16): the @spec regex swallows a
    sentence-ending '.' into the fragment. The resolver matched on slugify(frag)
    but emitted the RAW frag, producing `...#requirement-foo-setting.` — which
    gate-46 (raw compare) still counts as broken. The repointer thus "fixed" an
    anchor into another broken anchor, and its own sanity check was blind to it
    because it used the same lenient comparison.
    """
    root = os.path.join(tmp, 'punct-app')
    _write(os.path.join(root, 'openspec/specs/admin-settings/spec.md'),
           "# Admin Settings\n\n### Requirement: REQ-ADM-001 Tenant setting\n")
    _write(os.path.join(root, 'openspec/changes/adopt-settings/specs/admin-settings/spec.md'),
           "# delta\n")
    php_path = os.path.join(root, 'lib/SettingsService.php')
    # Trailing '.' is part of the prose sentence, swallowed by the @spec regex.
    _write(php_path,
           "<?php\n"
           "/**\n"
           " * @spec openspec/changes/adopt-settings/specs/admin-settings/spec.md"
           "#requirement-req-adm-001-tenant-setting.\n"
           " */\n"
           "class SettingsService {}\n")

    sys.argv = ['repoint.py', root, '--apply']
    repoint.main()
    after = open(php_path, encoding='utf-8').read()

    # The emitted anchor must carry NO trailing dot...
    assert '#requirement-req-adm-001-tenant-setting.' not in after, \
        f"raw fragment (trailing '.') was emitted: {after!r}"
    assert '#requirement-req-adm-001-tenant-setting' in after, after

    # ...and, decisively, gate-46 must now see ZERO broken anchors in this app.
    import subprocess
    n = subprocess.run(
        [sys.executable, os.path.join(HERE, 'gate46_count.py'), root],
        capture_output=True, text=True).stdout.strip()
    assert n.endswith('broken=0'), f"gate-46 still flags the repointed anchor: {n}"
    print("PASS: repointed anchors satisfy gate-46 verbatim (no raw-fragment leak)")


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

        crlf_regression(tmp)
        trailing_punctuation_regression(tmp)
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == '__main__':
    sys.exit(main())
