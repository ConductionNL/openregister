#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Tests for gate-52 helper check_custom_widget_ratchet.py.

Covers the JS/TS/Vue registry-entry extraction (key forms, comment/string
masking, built-in-key exclusion, `_note` detection), the diff-scoped
justification check + app-wide count ratchet (ADR-020 / ADR-049), the no-op
pass when a PR touches no widget registry, the documented-exception marker,
and the gate-52 wiring in run-hydra-gates.sh (end-to-end fixture run +
helper-absent WARN-skip).
"""

from __future__ import annotations

import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import check_custom_widget_ratchet as g  # noqa: E402

GATES_SH = Path(__file__).resolve().parent.parent / "run-hydra-gates.sh"


def _registry(entries):
    """Render a registry file with the given raw entry blocks."""
    body = "\n".join(entries)
    return (
        "// SPDX-License-Identifier: EUPL-1.2\n"
        "import Widget from './Widget.vue'\n"
        "\n"
        "const registry = {\n"
        f"{body}\n"
        "}\n"
        "\n"
        "export default registry\n"
    )


NOTED = (
    "\tcasesOverview: {\n"
    "\t\tkind: 'widget',\n"
    "\t\tcomponent: Widget,\n"
    "\t\t_note: 'Open cases list — self-fetching via objectStore.',\n"
    "\t},"
)
NOTELESS = (
    "\tdealHeatmap: {\n"
    "\t\tkind: 'widget',\n"
    "\t\tcomponent: Widget,\n"
    "\t},"
)
NOTED_2 = (
    "\t'chat-panel': {\n"
    "\t\tkind: 'widget',\n"
    "\t\tcomponent: Widget,\n"
    "\t\t_note: 'Real-time chat surface; no built-in equivalent.',\n"
    "\t},"
)
BUILTIN = (
    "\t'object-table': {\n"
    "\t\tkind: 'widget',\n"
    "\t\tcomponent: Widget,\n"
    "\t},"
)
PAGE_ENTRY = (
    "\tMyWorkView: {\n"
    "\t\tkind: 'page',\n"
    "\t\tcomponent: Widget,\n"
    "\t},"
)
EXCLUDED = (
    "\t// @custom-widget-ratchet exclude bespoke analytics canvas, "
    "no built-in fits (hydra#71)\n"
    "\tanalyticsCanvas: {\n"
    "\t\tkind: 'widget',\n"
    "\t\tcomponent: Widget,\n"
    "\t\t_note: 'Bespoke analytics canvas.',\n"
    "\t},"
)


class ParseEntriesTest(unittest.TestCase):
    def _keys(self, text):
        return [e.key for e in g.parse_entries(text)]

    def test_key_forms(self):
        text = _registry([NOTED, NOTED_2]) + (
            "registry[\"deal-heatmap\"] = { kind: \"widget\", "
            "component: Widget }\n"
            "registry.myWidget = { kind: 'widget', component: Widget }\n"
        )
        self.assertEqual(
            self._keys(text),
            ["casesOverview", "chat-panel", "deal-heatmap", "myWidget"],
        )

    def test_page_entries_and_comments_ignored(self):
        text = _registry([
            PAGE_ENTRY,
            "\t// old: { kind: 'widget', component: Widget },",
            "\t/* legacy: { kind: 'widget' }, */",
            NOTED,
        ])
        self.assertEqual(self._keys(text), ["casesOverview"])

    def test_kind_widget_inside_string_ignored(self):
        text = _registry([
            "\tsomePage: {\n\t\tkind: 'page',\n"
            "\t\t_note: \"not a kind: 'widget' entry\",\n\t},",
        ])
        self.assertEqual(self._keys(text), [])

    def test_note_and_exclude_detection(self):
        entries = g.parse_entries(_registry([NOTED, NOTELESS, EXCLUDED]))
        by_key = {e.key: e for e in entries}
        self.assertTrue(by_key["casesOverview"].has_note)
        self.assertFalse(by_key["dealHeatmap"].has_note)
        self.assertFalse(by_key["dealHeatmap"].has_exclude)
        self.assertTrue(by_key["analyticsCanvas"].has_note)
        self.assertTrue(by_key["analyticsCanvas"].has_exclude)

    def test_bare_exclude_marker_without_reason_does_not_count(self):
        text = _registry([
            "\t// @custom-widget-ratchet exclude\n"
            "\tbareMarker: {\n\t\tkind: 'widget',\n"
            "\t\tcomponent: Widget,\n\t},",
        ])
        (entry,) = g.parse_entries(text)
        self.assertFalse(entry.has_exclude)

    def test_spread_meta_entry_parses(self):
        text = _registry([
            "\t'audit-trail': {\n"
            "\t\tkind: 'widget',\n"
            "\t\tcomponent: Widget,\n"
            "\t\t...PANEL_WIDGET_META,\n"
            "\t\tallowedSlots: ['body', 'sidebar'],\n"
            "\t\t_note: 'Object change-log card.',\n"
            "\t},",
        ])
        (entry,) = g.parse_entries(text)
        self.assertEqual(entry.key, "audit-trail")
        self.assertTrue(entry.has_note)


class ScopedRunTest(unittest.TestCase):
    """Diff-scoped helper runs inside a synthetic fixture git repo."""

    def _git(self, *args):
        subprocess.run(["git", *args], cwd=self.repo, check=True,
                       capture_output=True, text=True)

    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.repo = self._tmp.name
        os.makedirs(os.path.join(self.repo, "src"))
        self.reg = os.path.join(self.repo, "src", "registry.js")
        self._git("init", "-q")  # default branch name irrelevant; we pin "base"
        self._git("config", "user.email", "t@t.tld")
        self._git("config", "user.name", "t")
        # Base: one noted custom widget, one built-in key, one page entry.
        Path(self.reg).write_text(_registry([NOTED, BUILTIN, PAGE_ENTRY]))
        self._git("add", ".")
        self._git("commit", "-qm", "base")
        self._git("branch", "base")

    def tearDown(self):
        self._tmp.cleanup()

    def _run(self, base="base", extra_env=None):
        env = {**os.environ, "HYDRA_GATE_BASE_REF": base}
        if extra_env:
            env.update(extra_env)
        proc = subprocess.run(
            [sys.executable, g.__file__, "src/registry.js"],
            cwd=self.repo, env=env, capture_output=True, text=True,
        )
        return proc.returncode, proc.stdout

    def _commit(self, entries, msg="head"):
        Path(self.reg).write_text(_registry(entries))
        self._git("add", ".")
        self._git("commit", "-qm", msg)

    def test_new_widget_without_note_fails_justification_and_ratchet(self):
        self._commit([NOTED, BUILTIN, PAGE_ENTRY, NOTELESS])
        rc, out = self._run()
        # THE COUNT COMES OFF STDOUT, NOT THE EXIT BYTE (#209).
        #
        # This asserted `rc == 2` — the helper returned its finding count as
        # its exit status. That is the same channel the interpreter uses to
        # report that the helper never finished, so a traceback (exit 1) was
        # indistinguishable from one finding, and the gate duly printed
        # "FAIL — 1 custom-widget finding(s)" over a crash. The count was also
        # clamped to 99 to fit in a byte.
        #
        # Exit status is now boolean and the count is a line. Both are checked:
        # asserting only the boolean would be weaker than what this test had.
        self.assertEqual(rc, 1, out)
        self.assertIn("[custom-widget-ratchet] findings=2", out)
        self.assertIn(
            'registry["dealHeatmap"] is kind:"widget" without a _note',
            out,
        )
        self.assertIn("ADR-049 built-in-first rule", out)
        self.assertIn("base=1 head=2 delta=+1", out)

    def test_new_noted_widget_still_fails_ratchet(self):
        self._commit([NOTED, BUILTIN, PAGE_ENTRY, NOTED_2])
        rc, out = self._run()
        self.assertEqual(rc, 1, out)
        self.assertNotIn("without a _note", out)
        self.assertIn(
            "custom-widget count grew: base=1 head=2 delta=+1", out
        )
        self.assertIn("ratchet forbids growth", out)

    def test_count_stable_with_note_passes(self):
        # Replace one justified custom widget with another: delta=0.
        self._commit([NOTED_2, BUILTIN, PAGE_ENTRY])
        rc, out = self._run()
        self.assertEqual(rc, 0, out)
        self.assertIn("base=1 head=1 delta=0", out)

    def test_count_shrinks_passes_and_reports_delta(self):
        self._commit([BUILTIN, PAGE_ENTRY])
        rc, out = self._run()
        self.assertEqual(rc, 0, out)
        self.assertIn("base=1 head=0 delta=-1", out)

    def test_builtin_keys_ignored(self):
        # Add another BUILT-IN key registration: not counted, no findings.
        self._commit([NOTED, BUILTIN, PAGE_ENTRY,
                      "\t'stats-block': {\n\t\tkind: 'widget',\n"
                      "\t\tcomponent: Widget,\n\t},"])
        rc, out = self._run()
        self.assertEqual(rc, 0, out)
        self.assertIn("base=1 head=1 delta=0", out)

    def test_untouched_registry_is_noop(self):
        # PR changes an unrelated non-registry line in another file.
        other = os.path.join(self.repo, "src", "other.js")
        Path(other).write_text("export const x = 1\n")
        self._git("add", ".")
        self._git("commit", "-qm", "unrelated")
        env = {**os.environ, "HYDRA_GATE_BASE_REF": "base"}
        proc = subprocess.run(
            [sys.executable, g.__file__, "src/registry.js", "src/other.js"],
            cwd=self.repo, env=env, capture_output=True, text=True,
        )
        self.assertEqual(proc.returncode, 0, proc.stdout)
        # The no-op must not COMPUTE OR REPORT THE RATCHET — that is the claim.
        # It was written as `stdout == ""`, which also forbade the helper from
        # saying it had finished. Since #209 the `findings=` line is how a
        # caller tells a clean run from a helper that died, so a truly silent
        # success is now indistinguishable from a crash. The assertion is on
        # the ratchet and the findings, which is what the sentence meant.
        self.assertNotIn("base=", proc.stdout)
        self.assertNotIn("delta=", proc.stdout)
        self.assertNotIn("ADR-049", proc.stdout)
        self.assertEqual(proc.stdout.strip(),
                         "[custom-widget-ratchet] findings=0",
                         "no-op pass must not compute/report the ratchet")

    def test_legacy_noteless_entry_untouched_not_flagged(self):
        # Base already carries a note-less legacy widget; the PR only adds a
        # page entry to the same file. Diff-scoping must not flag the legacy
        # entry (count also stays flat).
        self._commit([NOTED, NOTELESS, BUILTIN], msg="rebase-fixture")
        self._git("branch", "-f", "base")
        self._commit([NOTED, NOTELESS, BUILTIN, PAGE_ENTRY])
        rc, out = self._run()
        self.assertEqual(rc, 0, out)
        self.assertNotIn("dealHeatmap", out)
        self.assertIn("base=2 head=2 delta=0", out)

    def test_modified_noteless_entry_retriggers_justification(self):
        self._commit([NOTED, NOTELESS, BUILTIN], msg="rebase-fixture")
        self._git("branch", "-f", "base")
        modified = NOTELESS.replace(
            "component: Widget,", "component: Widget,\n\t\tprops: { limit: 5 },"
        )
        self._commit([NOTED, modified, BUILTIN])
        rc, out = self._run()
        self.assertEqual(rc, 1, out)
        self.assertIn('registry["dealHeatmap"] is kind:"widget" without a _note', out)
        self.assertIn("delta=0", out)

    def test_documented_exception_allows_growth(self):
        self._commit([NOTED, BUILTIN, PAGE_ENTRY, EXCLUDED])
        rc, out = self._run()
        self.assertEqual(rc, 0, out)
        self.assertIn("base=1 head=2 delta=+1 (1 ratchet-excluded)", out)
        self.assertNotIn("ratchet forbids growth", out)

    def test_deleted_registry_file_shrinks_base_side(self):
        # Move the only custom widget into a second file on base, then
        # delete that file: base=2 head=1 delta=-1.
        second = os.path.join(self.repo, "src", "widgets.js")
        Path(second).write_text(_registry([NOTED_2]))
        self._git("add", ".")
        self._git("commit", "-qm", "second-file")
        self._git("branch", "-f", "base")
        os.unlink(second)
        self._git("add", "-A")
        self._git("commit", "-qm", "delete-second-file")
        rc, out = self._run()
        self.assertEqual(rc, 0, out)
        self.assertIn("base=2 head=1 delta=-1", out)

    def test_full_repo_mode_flags_every_noteless_entry(self):
        self._commit([NOTED, NOTELESS, BUILTIN])
        env = {**os.environ}
        env.pop("HYDRA_GATE_BASE_REF", None)
        proc = subprocess.run(
            [sys.executable, g.__file__, "src/registry.js"],
            cwd=self.repo, env=env, capture_output=True, text=True,
        )
        self.assertEqual(proc.returncode, 1, proc.stdout)
        self.assertIn('registry["dealHeatmap"]', proc.stdout)
        self.assertIn("full-repo mode", proc.stdout)


class GateWiringTest(unittest.TestCase):
    """End-to-end run of run-hydra-gates.sh gate 29 against a fixture repo."""

    def _git(self, *args):
        subprocess.run(["git", *args], cwd=self.repo, check=True,
                       capture_output=True, text=True)

    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.repo = self._tmp.name
        os.makedirs(os.path.join(self.repo, "src"))
        self.reg = os.path.join(self.repo, "src", "registry.js")
        self._git("init", "-q")  # default branch name irrelevant; we pin "base"
        self._git("config", "user.email", "t@t.tld")
        self._git("config", "user.name", "t")
        Path(self.reg).write_text(_registry([NOTED, BUILTIN]))
        self._git("add", ".")
        self._git("commit", "-qm", "base")
        self._git("branch", "base")

    def tearDown(self):
        self._tmp.cleanup()

    def _run_gates(self, script=GATES_SH):
        proc = subprocess.run(
            ["bash", str(script), "--scope-to-diff", "--base", "base",
             self.repo],
            capture_output=True, text=True,
        )
        return proc

    def test_gate29_fails_on_growth_and_reports_counts(self):
        Path(self.reg).write_text(_registry([NOTED, BUILTIN, NOTELESS]))
        self._git("add", ".")
        self._git("commit", "-qm", "add noteless widget")
        proc = self._run_gates()
        self.assertIn("[gate-52] custom-widget-ratchet: base=1 head=2 delta=+1",
                      proc.stdout)
        self.assertIn("[gate-52] custom-widget-ratchet: FAIL", proc.stdout)
        self.assertIn("2 custom-widget finding(s)", proc.stdout)

    def test_gate29_passes_when_count_stable(self):
        Path(self.reg).write_text(_registry([NOTED_2, BUILTIN]))
        self._git("add", ".")
        self._git("commit", "-qm", "swap widget")
        proc = self._run_gates()
        self.assertIn("[gate-52] custom-widget-ratchet: base=1 head=1 delta=0",
                      proc.stdout)
        self.assertIn("[gate-52] custom-widget-ratchet: PASS", proc.stdout)

    def test_helper_absent_reports_skipped_not_pass(self):
        # Copy the gate script into a scripts/ dir with an EMPTY lib/ so the
        # helper is missing.
        #
        # This test used to assert the OPPOSITE: a WARN on stderr plus
        # "custom-widget-ratchet: PASS" on stdout. That codified a dead gate.
        # The fixture below contains a NOTELESS widget — a real finding the
        # gate reports as FAIL when the helper is present (see
        # test_gate29_fails_on_growth_and_reports_counts, same fixture). With
        # the helper absent the old wiring turned that FAIL into a PASS, and
        # the WARN that said so went to stderr where no `^\[gate-` consumer
        # reads it. An empty findings log because the helper never ran was
        # byte-identical to an empty log because there was nothing to find.
        #
        # The gate must now SKIP: it inspected nothing, so it may not claim a
        # verdict, and it must land in the summary's DID-NOT-RUN list rather
        # than being counted toward the green.
        with tempfile.TemporaryDirectory() as d:
            scripts = Path(d) / "scripts"
            (scripts / "lib").mkdir(parents=True)
            shutil.copy(GATES_SH, scripts / "run-hydra-gates.sh")
            Path(self.reg).write_text(_registry([NOTED, BUILTIN, NOTELESS]))
            self._git("add", ".")
            self._git("commit", "-qm", "add noteless widget")
            proc = self._run_gates(script=scripts / "run-hydra-gates.sh")
            self.assertIn("[gate-52] custom-widget-ratchet: SKIPPED",
                          proc.stdout)
            self.assertIn("check_custom_widget_ratchet.py not found",
                          proc.stdout)
            # The verdict it must NOT claim.
            self.assertNotIn("[gate-52] custom-widget-ratchet: PASS",
                             proc.stdout)
            # And the coverage accounting must name it, not fold it away.
            self.assertIn("gate-52 custom-widget-ratchet",
                          proc.stdout)


if __name__ == "__main__":
    unittest.main()
