#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_spec_coverage. Run with:

    python3 scripts/lib/test_check_spec_coverage.py
"""
from __future__ import annotations

import io
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_spec_coverage as csc  # noqa: E402


def _all_lines(text: str) -> set[int]:
    """Mark every line of a file as added (full-file diff scope)."""
    return set(range(1, text.count("\n") + 2))


class BackendTest(unittest.TestCase):
    def test_covered_method_passes(self):
        text = """<?php
class FooService {
    /**
     * Do a thing.
     *
     * @spec openspec/changes/x/tasks.md#task-1
     */
    public function doThing(string $id): string
    {
        return $id;
    }
}
"""
        findings: list[str] = []
        csc.check_php_file("lib/Service/FooService.php", text, _all_lines(text), findings)
        self.assertEqual(findings, [])

    def test_uncovered_method_flagged(self):
        text = """<?php
class FooService {
    /**
     * Do a thing — no spec tag here.
     */
    public function doThing(string $id): string
    {
        return $id;
    }
}
"""
        findings: list[str] = []
        csc.check_php_file("lib/Service/FooService.php", text, _all_lines(text), findings)
        self.assertEqual(len(findings), 1)
        self.assertIn("FooService.php::doThing", findings[0])
        self.assertIn("missing @spec", findings[0])

    def test_spec_exclude_with_reason_passes(self):
        text = """<?php
class FooService {
    /**
     * Debug-only dump endpoint.
     *
     * @spec exclude debug-only endpoint, never shipped to users
     */
    public function debugDump(): string
    {
        return var_export($this, true);
    }
}
"""
        findings: list[str] = []
        csc.check_php_file("lib/Service/FooService.php", text, _all_lines(text), findings)
        self.assertEqual(findings, [], "a reason-bearing @spec exclude is compliant")

    def test_spec_exclude_through_intervening_comment(self):
        # A `/* istanbul ignore next */` (and a trailing-`//` variant) between the
        # docblock and the declaration must not hide the @spec exclude tag.
        text = """<?php
class FooService {
    /**
     * @spec exclude thin passthrough
     */
    /* istanbul ignore next */
    public function refreshA(): void {}

    /**
     * @spec exclude thin passthrough
     */
    /* istanbul ignore next */ // ignore in Jest until extracted
    public function refreshB(): void {}
}
"""
        findings: list[str] = []
        csc.check_php_file("lib/Service/FooService.php", text, _all_lines(text), findings)
        self.assertEqual(findings, [], "intervening single-line comments must not hide @spec")

    def test_spec_exclude_bare_flagged(self):
        text = """<?php
class FooService {
    /**
     * @spec exclude
     */
    public function sneaky(string $id): string
    {
        return $id;
    }
}
"""
        findings: list[str] = []
        csc.check_php_file("lib/Service/FooService.php", text, _all_lines(text), findings)
        self.assertEqual(len(findings), 1, "a bare @spec exclude (no reason) is NOT compliant")
        self.assertIn("::sneaky", findings[0])

    def test_docblock_status_classification(self):
        covered = ["    /**", "     * @spec openspec/changes/x/tasks.md#task-1", "     */", "    public function a() {"]
        self.assertEqual(csc._docblock_spec_status(covered, 3), ("covered", None))
        excluded = ["    /**", "     * @spec exclude vendor shim", "     */", "    public function b() {"]
        self.assertEqual(csc._docblock_spec_status(excluded, 3), ("excluded", "vendor shim"))
        bare = ["    /**", "     * @spec exclude", "     */", "    public function c() {"]
        self.assertEqual(csc._docblock_spec_status(bare, 3), ("exclude_noreason", None))
        none = ["    /**", "     * Just a description.", "     */", "    public function d() {"]
        self.assertEqual(csc._docblock_spec_status(none, 3), ("none", None))

    def test_protected_method_in_scope(self):
        text = """<?php
class FooService {
    protected function helper(): void
    {
        $x = 1;
        $y = 2;
        echo $x + $y;
    }
}
"""
        findings: list[str] = []
        csc.check_php_file("lib/Service/FooService.php", text, _all_lines(text), findings)
        self.assertEqual(len(findings), 1)
        self.assertIn("::helper", findings[0])

    def test_constructor_exempt(self):
        text = """<?php
class FooService {
    public function __construct(private readonly Bar $bar)
    {
    }
}
"""
        findings: list[str] = []
        csc.check_php_file("lib/Service/FooService.php", text, _all_lines(text), findings)
        self.assertEqual(findings, [])

    def test_simple_accessor_exempt(self):
        text = """<?php
class FooService {
    public function getName(): string
    {
        return $this->name;
    }
}
"""
        findings: list[str] = []
        csc.check_php_file("lib/Service/FooService.php", text, _all_lines(text), findings)
        self.assertEqual(findings, [])

    def test_accessor_with_logic_is_in_scope(self):
        # A get*-named method with a real (>2 line) body is NOT a trivial
        # accessor — it must carry @spec.
        text = """<?php
class FooService {
    public function getComputed(): int
    {
        $a = $this->a;
        $b = $this->b;
        $c = $this->c;
        return $a + $b + $c;
    }
}
"""
        findings: list[str] = []
        csc.check_php_file("lib/Service/FooService.php", text, _all_lines(text), findings)
        self.assertEqual(len(findings), 1)
        self.assertIn("::getComputed", findings[0])


class FrontendTest(unittest.TestCase):
    def test_vue_method_uncovered_flagged(self):
        text = """<script>
export default {
    methods: {
        async fetchThings (id) {
            const r = await api.get(id)
            this.things = r.data
            return r
        },
    },
}
</script>
"""
        findings: list[str] = []
        csc.check_frontend_file("src/views/Things.vue", text, _all_lines(text), findings)
        self.assertTrue(any("::fetchThings" in f for f in findings), findings)

    def test_vue_method_covered_passes(self):
        text = """<script>
export default {
    methods: {
        /**
         * Fetch things.
         * @spec openspec/changes/x/tasks.md#task-2
         */
        async fetchThings (id) {
            const r = await api.get(id)
            this.things = r.data
            return r
        },
    },
}
</script>
"""
        findings: list[str] = []
        csc.check_frontend_file("src/views/Things.vue", text, _all_lines(text), findings)
        self.assertEqual([f for f in findings if "fetchThings" in f], [])

    def test_lifecycle_hook_trivial_exempt(self):
        text = """<script>
export default {
    mounted () {
        this.load()
    },
}
</script>
"""
        findings: list[str] = []
        csc.check_frontend_file("src/views/Things.vue", text, _all_lines(text), findings)
        self.assertEqual(findings, [])

    def test_exported_service_fn_flagged(self):
        text = """export function buildPayload (input) {
    const out = { ...input }
    out.normalized = true
    return out
}
"""
        findings: list[str] = []
        csc.check_frontend_file("src/services/payload.js", text, _all_lines(text), findings)
        self.assertTrue(any("::buildPayload" in f for f in findings), findings)


class DiffScopeFullRunTest(unittest.TestCase):
    """End-to-end: a real git repo where only one method is in the diff."""

    def setUp(self):
        self.dir = Path(tempfile.mkdtemp())
        self._run("git", "init", "-q")
        self._run("git", "config", "user.email", "t@t.nl")
        self._run("git", "config", "user.name", "t")

    def tearDown(self):
        shutil.rmtree(self.dir, ignore_errors=True)

    def _run(self, *args: str) -> None:
        subprocess.run(args, cwd=str(self.dir), check=True,
                       capture_output=True, text=True)

    def _write(self, rel: str, content: str) -> None:
        p = self.dir / rel
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(content, encoding="utf-8")

    def test_only_changed_method_flagged(self):
        # Baseline commit: one already-untagged legacy method.
        self._write("lib/Service/FooService.php", """<?php
class FooService {
    public function legacyUntagged(): int
    {
        $a = 1;
        $b = 2;
        return $a + $b;
    }
}
""")
        self._run("git", "add", "-A")
        self._run("git", "commit", "-q", "-m", "base")
        base = subprocess.run(["git", "rev-parse", "HEAD"], cwd=str(self.dir),
                              capture_output=True, text=True).stdout.strip()

        # New commit adds a NEW untagged method but leaves legacy untouched.
        self._write("lib/Service/FooService.php", """<?php
class FooService {
    public function legacyUntagged(): int
    {
        $a = 1;
        $b = 2;
        return $a + $b;
    }

    public function brandNewMethod(): int
    {
        $x = 10;
        $y = 20;
        return $x + $y;
    }
}
""")
        self._run("git", "add", "-A")
        self._run("git", "commit", "-q", "-m", "feat")

        os.environ["HYDRA_GATE_BASE_REF"] = base
        try:
            buf = io.StringIO()
            with redirect_stdout(buf):
                rc = csc.main(["check_spec_coverage.py", str(self.dir)])
        finally:
            del os.environ["HYDRA_GATE_BASE_REF"]

        out = buf.getvalue()
        # The legacy method (untouched) must NOT be flagged; only the new one.
        self.assertIn("brandNewMethod", out)
        self.assertNotIn("legacyUntagged", out)
        self.assertEqual(rc, 1)

    def _spec_removal_fixture(self):
        """A tagged method plus an untagged legacy one, committed as the base.
        Returns the base sha."""
        self._write("lib/Service/BarService.php", """<?php
class BarService {
    /**
     * Does the thing.
     *
     * @spec openspec/specs/things/spec.md
     */
    public function doThing(string $id): array
    {
        return [$id];
    }

    /**
     * Legacy, never tagged.
     */
    public function legacyThing(string $id): array
    {
        return [$id, 'legacy'];
    }
}
""")
        self._run("git", "add", "-A")
        self._run("git", "commit", "-q", "-m", "base")
        return subprocess.run(["git", "rev-parse", "HEAD"], cwd=str(self.dir),
                              capture_output=True, text=True).stdout.strip()

    def _gate(self, base):
        os.environ["HYDRA_GATE_BASE_REF"] = base
        try:
            buf = io.StringIO()
            with redirect_stdout(buf):
                rc = csc.main(["check_spec_coverage.py", str(self.dir)])
        finally:
            del os.environ["HYDRA_GATE_BASE_REF"]
        return buf.getvalue(), rc

    def test_deleting_an_at_spec_tag_is_caught(self):
        # .github#271 — `_overlaps` walks FORWARD from the declaration, so the
        # docblock is outside the scope window, and the docblock is the only
        # place @spec can live. Deleting the tag left the body byte-identical:
        # the helper printed `# count=0` and exited 0. Every @spec tag in a repo
        # could be stripped and this gate stayed green — the one edit that
        # removes coverage was the one it could not see.
        #
        # Worse, run_gate skipped any file whose `added` set was empty, and a
        # pure deletion produces exactly that, so the file was never opened.
        base = self._spec_removal_fixture()
        text = (self.dir / "lib/Service/BarService.php").read_text()
        old = "     *\n     * @spec openspec/specs/things/spec.md\n"
        self.assertIn(old, text, "fixture premise: the @spec line must be there to delete")
        self._write("lib/Service/BarService.php", text.replace(old, ""))
        self._run("git", "add", "-A")
        self._run("git", "commit", "-q", "-m", "strip the tag")

        out, rc = self._gate(base)
        self.assertIn("doThing", out, "the method that LOST its @spec must be named")
        self.assertEqual(rc, 1)
        # ...and ONLY that method. Naming every untagged method in the file
        # would surface inherited debt the author never touched, which is what
        # ADR-020 exists to keep out of a PR.
        self.assertNotIn("legacyThing", out)

    def test_an_unrelated_docblock_edit_does_not_surface_legacy_debt(self):
        # The anti-widening control for the arm above. The fix must key on
        # "a tag was TAKEN AWAY", not on "a docblock was touched" — otherwise a
        # typo fix in a legacy untagged method's docblock becomes a finding.
        base = self._spec_removal_fixture()
        text = (self.dir / "lib/Service/BarService.php").read_text()
        old = "     * Legacy, never tagged.\n"
        self.assertIn(old, text)
        self._write("lib/Service/BarService.php",
                    text.replace(old, "     * Legacy, never tagged. Typo fixed.\n"))
        self._run("git", "add", "-A")
        self._run("git", "commit", "-q", "-m", "typo")

        out, rc = self._gate(base)
        self.assertEqual(out, "# count=0\n", f"expected a clean run, got: {out}")
        self.assertEqual(rc, 0)


if __name__ == "__main__":
    unittest.main()
