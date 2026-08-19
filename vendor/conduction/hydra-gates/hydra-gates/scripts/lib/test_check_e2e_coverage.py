#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_e2e_coverage. Run with:

    python3 scripts/lib/test_check_e2e_coverage.py
"""
from __future__ import annotations

import io
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_e2e_coverage as cec  # noqa: E402


def _write(root: Path, rel: str, content: str) -> Path:
    p = root / rel
    p.parent.mkdir(parents=True, exist_ok=True)
    p.write_text(content, encoding="utf-8")
    return p


# ---------------------------------------------------------------------------
# Slug tests
# ---------------------------------------------------------------------------

class SlugTest(unittest.TestCase):
    def test_simple(self):
        self.assertEqual(cec._slugify("Widget receives context on a detail page"),
                         "widget-receives-context-on-a-detail-page")

    def test_punctuation_stripped(self):
        self.assertEqual(cec._slugify("OR is not installed or unreachable"),
                         "or-is-not-installed-or-unreachable")

    def test_leading_trailing_stripped(self):
        self.assertEqual(cec._slugify("  Hello World  "), "hello-world")

    def test_special_chars(self):
        self.assertEqual(cec._slugify("Valid schema declaration present"),
                         "valid-schema-declaration-present")


# ---------------------------------------------------------------------------
# Spec parsing tests
# ---------------------------------------------------------------------------

BASIC_SPEC = """\
# my-spec Specification

## Purpose

This spec covers things.

### Requirement: Foo behaviour

Foo shall do bar.

#### Scenario: Foo does bar

- WHEN foo is called
- THEN bar happens

#### Scenario: Foo handles error

- WHEN foo fails
- THEN error is logged
"""

SPEC_WITH_EXCLUSION = """\
# my-spec Specification

## Purpose

### Requirement: Plumbing

#### Scenario: Internal wiring

@e2e exclude pure plumbing, verified by PHPUnit

- WHEN the wiring is set up
- THEN it connects

#### Scenario: Another covered

- WHEN something visible happens
- THEN it shows in the UI
"""

SPEC_WITH_BARE_EXCLUDE = """\
# my-spec Specification

## Purpose

### Requirement: Sneaky

#### Scenario: Hidden

@e2e exclude

- WHEN hidden
- THEN nothing
"""

WHOLE_SPEC_EXCLUDED = """\
# backend-spec Specification

## Purpose

@e2e exclude pure-backend API contract, covered by Newman

### Requirement: API endpoint

#### Scenario: Returns 200 on success

- WHEN the API is called
- THEN status 200 is returned

#### Scenario: Returns 404 when not found

- WHEN the resource does not exist
- THEN status 404 is returned
"""

REQUIREMENT_LEVEL_EXCLUDE = """\
# my-spec Specification

## Purpose

### Requirement: Background job

@e2e exclude background-only, not UI-observable

#### Scenario: Job runs at midnight

- WHEN the cron triggers
- THEN the job executes

#### Scenario: Job logs output

- WHEN the job finishes
- THEN a log entry is created
"""

# ---- Format B (alternative numbered scenario format) ----

ALT_FORMAT_BASIC = """\
# method-decomp Specification

## Purpose

Decompose complex methods.

### REQ-DECOMP-001: SettingsController Decomposition

The controller MUST be decomposed.

**Scenarios:**

1. **GIVEN** the controller has >10 deps **WHEN** decomposed **THEN** handlers are created.

2. **GIVEN** handlers exist **WHEN** tests run **THEN** all tests pass.

### REQ-DECOMP-002: EventListener Decomposition

The listener MUST be split.

**Scenarios:**

1. **GIVEN** the listener is monolithic **WHEN** decomposed **THEN** three handlers created.
"""

ALT_FORMAT_WITH_EXCLUSION = """\
# method-decomp Specification

## Purpose

### REQ-DECOMP-001: SettingsController Decomposition

**Scenarios:**

1. **GIVEN** something **WHEN** done **THEN** result.
@e2e exclude pure backend refactoring, no UI surface

2. **GIVEN** something else **WHEN** done **THEN** visible result.
"""

ALT_FORMAT_BARE_EXCLUDE = """\
# method-decomp Specification

## Purpose

### REQ-DECOMP-001: SettingsController Decomposition

**Scenarios:**

1. **GIVEN** something **WHEN** done **THEN** result.
@e2e exclude
"""

ALT_FORMAT_REQUIREMENT_LEVEL_EXCLUDE = """\
# method-decomp Specification

## Purpose

### REQ-DECOMP-001: Background Processor

@e2e exclude background-only, not UI-observable

**Scenarios:**

1. **GIVEN** cron fires **WHEN** job runs **THEN** log entry created.

2. **GIVEN** job finishes **WHEN** checked **THEN** status is done.
"""

ALT_FORMAT_WHOLE_SPEC_EXCLUDED = """\
# backend-spec Specification

## Purpose

@e2e exclude pure-backend, covered by Newman

### REQ-DECOMP-001: API Endpoint

**Scenarios:**

1. **GIVEN** the API is called **WHEN** valid **THEN** 200 returned.

2. **GIVEN** the API is called **WHEN** missing **THEN** 404 returned.
"""

ALT_FORMAT_MIXED = """\
# mixed-spec Specification

## Purpose

### Requirement: Classic requirement

#### Scenario: Classic scenario one

- WHEN classic
- THEN result

### REQ-ALT-001: Alt format requirement

The system MUST do things.

**Scenarios:**

1. **GIVEN** alt setup **WHEN** alt action **THEN** alt result.

2. **GIVEN** another alt **WHEN** another action **THEN** another result.
"""


class ParseSpecTest(unittest.TestCase):
    def _parse(self, content: str, spec_name: str = "my-spec") -> list[dict]:
        root = Path(tempfile.mkdtemp())
        try:
            p = _write(root, f"openspec/specs/{spec_name}/spec.md", content)
            return cec.parse_spec_scenarios(p)
        finally:
            shutil.rmtree(root, ignore_errors=True)

    def test_basic_two_scenarios(self):
        scenarios = self._parse(BASIC_SPEC)
        self.assertEqual(len(scenarios), 2)
        self.assertEqual(scenarios[0]["slug"], "foo-does-bar")
        self.assertEqual(scenarios[1]["slug"], "foo-handles-error")
        self.assertEqual(scenarios[0]["ref"], "my-spec::foo-does-bar")
        for s in scenarios:
            self.assertFalse(s["excluded"])
            self.assertFalse(s["bare_exclude"])

    def test_scenario_exclusion_with_reason(self):
        scenarios = self._parse(SPEC_WITH_EXCLUSION)
        self.assertEqual(len(scenarios), 2)
        hidden = next(s for s in scenarios if s["slug"] == "internal-wiring")
        visible = next(s for s in scenarios if s["slug"] == "another-covered")
        self.assertTrue(hidden["excluded"])
        self.assertFalse(hidden["bare_exclude"])
        self.assertEqual(hidden["exclude_reason"], "pure plumbing, verified by PHPUnit")
        self.assertFalse(visible["excluded"])

    def test_bare_exclude_is_noncompliant(self):
        scenarios = self._parse(SPEC_WITH_BARE_EXCLUDE)
        self.assertEqual(len(scenarios), 1)
        s = scenarios[0]
        self.assertTrue(s["excluded"])
        self.assertTrue(s["bare_exclude"])
        self.assertIsNone(s["exclude_reason"])

    def test_whole_spec_exclusion(self):
        scenarios = self._parse(WHOLE_SPEC_EXCLUDED, spec_name="backend-spec")
        self.assertEqual(len(scenarios), 2)
        for s in scenarios:
            self.assertTrue(s["excluded"], f"scenario {s['slug']} should be excluded")
            self.assertFalse(s["bare_exclude"])

    def test_requirement_level_exclusion(self):
        scenarios = self._parse(REQUIREMENT_LEVEL_EXCLUDE)
        self.assertEqual(len(scenarios), 2)
        for s in scenarios:
            self.assertTrue(s["excluded"])
            self.assertFalse(s["bare_exclude"])

    # ------------------------------------------------------------------ #
    # Format B — numbered **Scenarios:** list
    # ------------------------------------------------------------------ #

    def test_alt_format_basic_counted(self):
        """Alt-format numbered scenarios are counted (not silently zero)."""
        scenarios = self._parse(ALT_FORMAT_BASIC, spec_name="method-decomp")
        self.assertEqual(len(scenarios), 3)
        slugs = [s["slug"] for s in scenarios]
        self.assertIn("req-decomp-001-settingscontroller-decomposition-scenario-1", slugs)
        self.assertIn("req-decomp-001-settingscontroller-decomposition-scenario-2", slugs)
        self.assertIn("req-decomp-002-eventlistener-decomposition-scenario-1", slugs)
        for s in scenarios:
            self.assertFalse(s["excluded"])
            self.assertFalse(s["bare_exclude"])

    def test_alt_format_slug_convention(self):
        """Slug is <parent-req-slug>-scenario-<n>, deterministic from req heading."""
        scenarios = self._parse(ALT_FORMAT_BASIC, spec_name="method-decomp")
        s1 = next(s for s in scenarios if "scenario-1" in s["slug"]
                  and "settingscontroller" in s["slug"])
        self.assertEqual(
            s1["slug"],
            "req-decomp-001-settingscontroller-decomposition-scenario-1",
        )
        self.assertEqual(
            s1["ref"],
            "method-decomp::req-decomp-001-settingscontroller-decomposition-scenario-1",
        )

    def test_alt_format_scenario_level_exclusion(self):
        """@e2e exclude inside an alt-format numbered item excludes only that item."""
        scenarios = self._parse(ALT_FORMAT_WITH_EXCLUSION, spec_name="method-decomp")
        self.assertEqual(len(scenarios), 2)
        excluded_s = next(s for s in scenarios if s["slug"].endswith("-scenario-1"))
        visible_s = next(s for s in scenarios if s["slug"].endswith("-scenario-2"))
        self.assertTrue(excluded_s["excluded"])
        self.assertFalse(excluded_s["bare_exclude"])
        self.assertEqual(excluded_s["exclude_reason"], "pure backend refactoring, no UI surface")
        self.assertFalse(visible_s["excluded"])

    def test_alt_format_bare_exclude_noncompliant(self):
        """A bare @e2e exclude inside an alt-format item is non-compliant."""
        scenarios = self._parse(ALT_FORMAT_BARE_EXCLUDE, spec_name="method-decomp")
        self.assertEqual(len(scenarios), 1)
        s = scenarios[0]
        self.assertTrue(s["excluded"])
        self.assertTrue(s["bare_exclude"])
        self.assertIsNone(s["exclude_reason"])

    def test_alt_format_requirement_level_exclusion(self):
        """@e2e exclude on a ### REQ-... heading inherits to all its numbered scenarios."""
        scenarios = self._parse(ALT_FORMAT_REQUIREMENT_LEVEL_EXCLUDE, spec_name="method-decomp")
        self.assertEqual(len(scenarios), 2)
        for s in scenarios:
            self.assertTrue(s["excluded"], f"{s['slug']} should be excluded")
            self.assertFalse(s["bare_exclude"])

    def test_alt_format_whole_spec_exclusion(self):
        """Whole-spec @e2e exclude covers alt-format numbered scenarios."""
        scenarios = self._parse(ALT_FORMAT_WHOLE_SPEC_EXCLUDED, spec_name="backend-spec")
        self.assertEqual(len(scenarios), 2)
        for s in scenarios:
            self.assertTrue(s["excluded"], f"{s['slug']} should be excluded")
            self.assertFalse(s["bare_exclude"])

    def test_mixed_format_both_counted(self):
        """A spec may use both Format A (#### Scenario:) and Format B in different requirements."""
        scenarios = self._parse(ALT_FORMAT_MIXED, spec_name="mixed-spec")
        self.assertEqual(len(scenarios), 3)
        slugs = [s["slug"] for s in scenarios]
        # Format A scenario present
        self.assertIn("classic-scenario-one", slugs)
        # Format B scenarios present
        self.assertIn("req-alt-001-alt-format-requirement-scenario-1", slugs)
        self.assertIn("req-alt-001-alt-format-requirement-scenario-2", slugs)


# ---------------------------------------------------------------------------
# Covered-ref collection
# ---------------------------------------------------------------------------

class CoveredRefTest(unittest.TestCase):
    def setUp(self):
        self.root = Path(tempfile.mkdtemp())

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)

    def test_long_form_annotation(self):
        _write(self.root, "tests/e2e/foo.spec.ts",
               "// @e2e openspec/specs/my-spec/spec.md#foo-does-bar\ntest('x', async ({ page }) => { await expect(page).toHaveTitle(/x/) })\n")
        refs = cec.collect_covered_refs(self.root)
        self.assertIn("my-spec::foo-does-bar", refs)

    def test_short_form_annotation(self):
        _write(self.root, "tests/e2e/foo.spec.ts",
               "// @e2e my-spec::foo-does-bar\ntest('x', async ({ page }) => { await expect(page).toHaveTitle(/x/) })\n")
        refs = cec.collect_covered_refs(self.root)
        self.assertIn("my-spec::foo-does-bar", refs)

    def test_both_forms_same_file(self):
        _write(self.root, "tests/e2e/bar.spec.js",
               "// @e2e my-spec::foo-does-bar\n// @e2e openspec/specs/my-spec/spec.md#foo-handles-error\n")
        refs = cec.collect_covered_refs(self.root)
        self.assertIn("my-spec::foo-does-bar", refs)
        self.assertIn("my-spec::foo-handles-error", refs)

    def test_subdirectory_e2e(self):
        _write(self.root, "tests/e2e/spec-coverage/deep.spec.ts",
               "// @e2e my-spec::foo-does-bar\n")
        refs = cec.collect_covered_refs(self.root)
        self.assertIn("my-spec::foo-does-bar", refs)

    def test_non_spec_file_ignored(self):
        _write(self.root, "tests/e2e/helpers.ts",
               "// @e2e my-spec::foo-does-bar\n")
        refs = cec.collect_covered_refs(self.root)
        # helpers.ts is not *.spec.ts / *.test.ts → should not be scanned
        self.assertNotIn("my-spec::foo-does-bar", refs)

    def test_no_e2e_dir(self):
        refs = cec.collect_covered_refs(self.root)
        self.assertEqual(refs, set())


# ---------------------------------------------------------------------------
# Report mode
# ---------------------------------------------------------------------------

class ReportModeTest(unittest.TestCase):
    def setUp(self):
        self.root = Path(tempfile.mkdtemp())

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)

    def test_report_counts(self):
        _write(self.root, "openspec/specs/my-spec/spec.md", BASIC_SPEC)
        # Cover the first scenario
        _write(self.root, "tests/e2e/foo.spec.ts",
               "// @e2e my-spec::foo-does-bar\ntest('x', async ({ page }) => { await expect(page).toHaveTitle(/x/) })\n")

        buf = io.StringIO()
        with redirect_stdout(buf):
            rc = cec.run_report(self.root)
        self.assertEqual(rc, 0)
        data = json.loads(buf.getvalue())
        self.assertEqual(data["mode"], "report")
        self.assertEqual(data["totals"]["scenarios"], 2)
        self.assertEqual(data["totals"]["covered"], 1)
        self.assertEqual(data["totals"]["uncovered"], 1)
        self.assertEqual(data["totals"]["excluded"], 0)
        self.assertEqual(len(data["uncovered"]), 1)
        self.assertEqual(data["uncovered"][0]["ref"], "my-spec::foo-handles-error")

    def test_report_with_exclusion(self):
        _write(self.root, "openspec/specs/my-spec/spec.md", SPEC_WITH_EXCLUSION)
        _write(self.root, "tests/e2e/foo.spec.ts",
               "// @e2e my-spec::another-covered\ntest('x', async ({ page }) => { await expect(page).toHaveTitle(/x/) })\n")

        buf = io.StringIO()
        with redirect_stdout(buf):
            rc = cec.run_report(self.root)
        self.assertEqual(rc, 0)
        data = json.loads(buf.getvalue())
        self.assertEqual(data["totals"]["excluded"], 1)
        self.assertEqual(data["totals"]["covered"], 1)
        self.assertEqual(data["totals"]["uncovered"], 0)

    def test_report_no_specs_dir(self):
        buf = io.StringIO()
        with redirect_stdout(buf):
            rc = cec.run_report(self.root)
        self.assertEqual(rc, 0)
        data = json.loads(buf.getvalue())
        self.assertEqual(data["totals"]["scenarios"], 0)


# ---------------------------------------------------------------------------
# Gate mode (full integration with a real git repo)
# ---------------------------------------------------------------------------

class GateModeTest(unittest.TestCase):
    def setUp(self):
        self.root = Path(tempfile.mkdtemp())
        self._run("git", "init", "-q")
        self._run("git", "config", "user.email", "t@t.nl")
        self._run("git", "config", "user.name", "t")

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)

    def _run(self, *args: str) -> None:
        subprocess.run(args, cwd=str(self.root), check=True,
                       capture_output=True, text=True)

    def _commit(self, msg: str = "commit") -> str:
        self._run("git", "add", "-A")
        self._run("git", "commit", "-q", "-m", msg)
        return subprocess.run(
            ["git", "rev-parse", "HEAD"],
            cwd=str(self.root), capture_output=True, text=True
        ).stdout.strip()

    def test_no_specs_in_the_repo_at_all_is_NOT_APPLICABLE_not_a_pass(self):
        """A repo with no specs has nothing to trace — say so, don't claim a pass.

        This test used to assert PASS, which is the .github#242 defect written
        down as an expectation: "I inspected nothing" and "I inspected
        everything and it was fine" cannot share a verdict, because
        --require-full-coverage has to be able to tell them apart.
        """
        _write(self.root, "src/index.ts", "export const x = 1\n")
        base = self._commit("base")
        _write(self.root, "src/index.ts", "export const x = 2\n")
        self._commit("change")

        os.environ["HYDRA_GATE_BASE_REF"] = base
        try:
            buf = io.StringIO()
            with redirect_stdout(buf):
                rc = cec.run_gate(self.root)
        finally:
            del os.environ["HYDRA_GATE_BASE_REF"]

        self.assertEqual(rc, cec.EXIT_NOT_APPLICABLE)
        self.assertIn("NOT APPLICABLE", buf.getvalue())
        self.assertNotIn("PASS", buf.getvalue())

    def _gate_with_n_scenarios(self, n: int):
        _write(self.root, "README.md", "# app\n")
        base = self._commit("base")
        spec = ["# S\n\n## Requirements\n\n### Requirement: R\n"]
        for i in range(n):
            spec.append(f"\n#### Scenario: scenario number {i}\n\n- **WHEN** x happens\n")
        _write(self.root, "openspec/specs/s/spec.md", "".join(spec))
        self._commit("add spec")

        os.environ["HYDRA_GATE_BASE_REF"] = base
        try:
            buf = io.StringIO()
            with redirect_stdout(buf):
                rc = cec.run_gate(self.root)
        finally:
            del os.environ["HYDRA_GATE_BASE_REF"]
        return rc, buf.getvalue()

    def test_the_status_never_wraps_to_zero_while_findings_exist(self):
        # An exit status is one byte. Returning the raw count meant 266
        # findings left as 10 — and 256 findings left as 0, which the bash
        # gate reads as PASS. Any multiple of 256 was a silent green.
        rc, out = self._gate_with_n_scenarios(256)
        self.assertNotEqual(rc, 0, "256 findings must not exit 0")
        self.assertLessEqual(rc, 255, "an exit status is one byte")
        # The TRUE number still has to reach the reader, which is why the
        # bash gate reports the printed summary rather than the status.
        self.assertIn("256 scenario(s) without a running e2e test", out)

    def test_a_300_finding_run_does_not_exit_0_and_prints_300(self):
        # THE OTHER HALF OF THE SIGNALLING BUG. The clamp that stopped the
        # wrap made the byte carry NEITHER the count NOR a status: a 404
        # finding run exited 255 while stdout said 404. Two numbers for one
        # measurement means one came through a lossy channel.
        rc, out = self._gate_with_n_scenarios(300)
        self.assertEqual(rc, cec.EXIT_FAIL,
                         "the exit code is a STATUS, not a count")
        self.assertNotEqual(rc, 0, "300 findings must never read as PASS")
        self.assertIn("300 scenario(s) without a running e2e test", out,
                      "the COUNT belongs on stdout")

    def test_an_unreadable_app_dir_is_an_ERROR_not_a_pass(self):
        # A crash must not read as PASS, and must not read as a finding count
        # it never measured.
        buf = io.StringIO()
        with redirect_stdout(buf):
            rc = cec.main(["check_e2e_coverage.py", str(self.root / "nope"),
                           "--mode", "boom"])
        # A non-existent dir used to be indistinguishable from an empty one, so
        # this asserted only "some valid status". It is an ERROR now (#242):
        # "there are no specs here" and "I could not look" produce the same
        # empty set, and reporting the second as a benign verdict would retire
        # the gate on the strength of a typo in a path.
        self.assertEqual(rc, cec.EXIT_ERROR)
        self.assertIn("ERROR", buf.getvalue())
        self.assertNotIn("PASS", buf.getvalue())

    def test_run_gate_raising_is_reported_as_ERROR(self):
        # A spec must exist, or the gate answers NOT APPLICABLE before it ever
        # reaches the diff helper this test is monkeypatching.
        _write(self.root, "openspec/specs/s/spec.md",
               "# s Spec\n## Purpose\n### Requirement: R\n#### Scenario: One\n- WHEN a\n- THEN b\n")
        self._commit("spec so the diff helper is reached")
        original = cec.changed_spec_files
        cec.changed_spec_files = lambda *_a, **_k: (_ for _ in ()).throw(
            RuntimeError("git exploded"))
        # The diff helper is only consulted when a base ref is set — an unscoped
        # run audits the whole tree and never calls it (#242). Set one, or this
        # test monkeypatches a function the run never reaches and passes for a
        # reason that has nothing to do with what it claims to check.
        os.environ["HYDRA_GATE_BASE_REF"] = "HEAD"
        try:
            buf = io.StringIO()
            with redirect_stdout(buf):
                rc = cec.main(["check_e2e_coverage.py", str(self.root)])
        finally:
            cec.changed_spec_files = original
            del os.environ["HYDRA_GATE_BASE_REF"]
        self.assertEqual(rc, cec.EXIT_ERROR)
        self.assertIn("ERROR", buf.getvalue())
        self.assertNotIn("PASS", buf.getvalue())

    def test_the_clamp_does_not_turn_a_clean_spec_into_a_failure(self):
        # THE CONTROL for the clamp.
        _write(self.root, "README.md", "# app\n")
        base = self._commit("base")
        _write(self.root, "openspec/specs/s/spec.md",
               "# S\n\n## Requirements\n\n### Requirement: R\n\n"
               "#### Scenario: only one\n\n- **WHEN** x happens\n"
               "- @e2e exclude backend only — covered by PHPUnit\n")
        self._commit("add spec")

        os.environ["HYDRA_GATE_BASE_REF"] = base
        try:
            with redirect_stdout(io.StringIO()):
                rc = cec.run_gate(self.root)
        finally:
            del os.environ["HYDRA_GATE_BASE_REF"]
        self.assertEqual(rc, 0)

    def test_fail_uncovered_scenario_in_diff(self):
        # Baseline: nothing
        _write(self.root, "README.md", "# app\n")
        base = self._commit("base")
        # PR adds a spec with two scenarios and no e2e tests
        _write(self.root, "openspec/specs/my-spec/spec.md", BASIC_SPEC)
        self._commit("add spec")

        os.environ["HYDRA_GATE_BASE_REF"] = base
        try:
            buf = io.StringIO()
            with redirect_stdout(buf):
                rc = cec.run_gate(self.root)
        finally:
            del os.environ["HYDRA_GATE_BASE_REF"]

        # The STATUS is 1 (fail). The COUNT is 2, and it is on stdout.
        self.assertEqual(rc, cec.EXIT_FAIL)
        out = buf.getvalue()
        self.assertIn("missing @e2e", out)
        self.assertIn("FAIL", out)
        self.assertIn("2 scenario(s)", out)

    def test_pass_when_all_scenarios_covered(self):
        _write(self.root, "README.md", "# app\n")
        base = self._commit("base")
        _write(self.root, "openspec/specs/my-spec/spec.md", BASIC_SPEC)
        _write(self.root, "tests/e2e/my.spec.ts",
               "// @e2e my-spec::foo-does-bar\n// @e2e my-spec::foo-handles-error\ntest('x', async ({ page }) => { await expect(page).toHaveTitle(/x/) })\n")
        self._commit("add spec + tests")

        os.environ["HYDRA_GATE_BASE_REF"] = base
        try:
            buf = io.StringIO()
            with redirect_stdout(buf):
                rc = cec.run_gate(self.root)
        finally:
            del os.environ["HYDRA_GATE_BASE_REF"]

        self.assertEqual(rc, 0)
        self.assertIn("PASS", buf.getvalue())

    def test_fail_bare_exclude_is_noncompliant(self):
        _write(self.root, "README.md", "# app\n")
        base = self._commit("base")
        _write(self.root, "openspec/specs/my-spec/spec.md", SPEC_WITH_BARE_EXCLUDE)
        self._commit("add spec with bare exclude")

        os.environ["HYDRA_GATE_BASE_REF"] = base
        try:
            buf = io.StringIO()
            with redirect_stdout(buf):
                rc = cec.run_gate(self.root)
        finally:
            del os.environ["HYDRA_GATE_BASE_REF"]

        self.assertEqual(rc, 1)
        out = buf.getvalue()
        self.assertIn("exclude without reason", out)

    def test_pass_exclude_with_reason(self):
        _write(self.root, "README.md", "# app\n")
        base = self._commit("base")
        _write(self.root, "openspec/specs/my-spec/spec.md", SPEC_WITH_EXCLUSION)
        # Only the non-excluded scenario needs coverage
        _write(self.root, "tests/e2e/my.spec.ts",
               "// @e2e my-spec::another-covered\ntest('x', async ({ page }) => { await expect(page).toHaveTitle(/x/) })\n")
        self._commit("add spec + test for visible scenario")

        os.environ["HYDRA_GATE_BASE_REF"] = base
        try:
            buf = io.StringIO()
            with redirect_stdout(buf):
                rc = cec.run_gate(self.root)
        finally:
            del os.environ["HYDRA_GATE_BASE_REF"]

        self.assertEqual(rc, 0)
        self.assertIn("PASS", buf.getvalue())

    def test_whole_spec_exclude_passes_all_scenarios(self):
        _write(self.root, "README.md", "# app\n")
        base = self._commit("base")
        _write(self.root, "openspec/specs/backend-spec/spec.md", WHOLE_SPEC_EXCLUDED)
        self._commit("add backend-only spec")

        os.environ["HYDRA_GATE_BASE_REF"] = base
        try:
            buf = io.StringIO()
            with redirect_stdout(buf):
                rc = cec.run_gate(self.root)
        finally:
            del os.environ["HYDRA_GATE_BASE_REF"]

        self.assertEqual(rc, 0)
        self.assertIn("PASS", buf.getvalue())

    def test_diff_scope_only_changed_spec_flagged(self):
        """A spec not touched in the diff must NOT be flagged even if uncovered."""
        # Baseline: existing uncovered spec
        _write(self.root, "openspec/specs/old-spec/spec.md",
               "# old-spec Spec\n## Purpose\n### Requirement: Old\n#### Scenario: Old one\n- WHEN old\n- THEN nothing\n")
        base = self._commit("base with uncovered spec")

        # PR only touches an unrelated file
        _write(self.root, "src/index.ts", "export const x = 1\n")
        self._commit("add source file")

        os.environ["HYDRA_GATE_BASE_REF"] = base
        try:
            buf = io.StringIO()
            with redirect_stdout(buf):
                rc = cec.run_gate(self.root)
        finally:
            del os.environ["HYDRA_GATE_BASE_REF"]

        # THE ADR-020 INVARIANT, UNCHANGED: an untouched legacy spec is never
        # flagged, so this PR is not blocked by debt it did not create.
        self.assertNotIn("old-spec", buf.getvalue())
        # THE #242 CHANGE: the gate opened no spec, so it reports an EMPTY
        # SCOPE rather than a PASS. A skip does not fail a run — but unlike a
        # PASS it is visible to --require-full-coverage, which is the whole
        # point. 407 uncovered scenarios on openconnector sat behind exactly
        # this PASS.
        self.assertEqual(rc, cec.EXIT_EMPTY_SCOPE)
        self.assertIn("EMPTY SCOPE", buf.getvalue())


# ---------------------------------------------------------------------------
# A PERMANENTLY-SKIPPED TEST IS NOT COVERAGE
#
# decidesk carried four tests with EMPTY BODIES and a hardcoded
# `test.skip(true, ...)`, each tagged `@e2e`, each counted as traceability,
# together asserting nothing. A gate that accepts a switched-off test as proof
# is a dead gate by construction.
#
# The discriminator is the ARGUMENT, not the call: `test.skip(true)` is a test
# someone turned off; `test.skip(browserName === 'firefox')` is a real test
# with a runtime guard, and it runs everywhere else. Both ways, in one class.
# ---------------------------------------------------------------------------
class SkippedTestIsNotCoverageTest(unittest.TestCase):
    def setUp(self):
        self.root = Path(tempfile.mkdtemp())

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)

    def _refs(self, body: str) -> set:
        _write(self.root, "tests/e2e/foo.spec.ts", body)
        return cec.collect_covered_refs(self.root)

    # --- dead: must NOT count -------------------------------------------
    def test_hardcoded_skip_true_with_empty_body_does_not_count(self):
        # The decidesk shape, verbatim.
        self.assertEqual(self._refs(
            "// @e2e my-spec::foo-does-bar\n"
            "test('minutes are published', async ({ page }) => {\n"
            "  test.skip(true, 'pending backend work')\n"
            "})\n"), set())

    def test_skip_modifier_does_not_count(self):
        self.assertEqual(self._refs(
            "// @e2e my-spec::foo-does-bar\n"
            "test.skip('minutes are published', async ({ page }) => {\n"
            "  await expect(page).toHaveTitle(/x/)\n"
            "})\n"), set())

    def test_xit_does_not_count(self):
        self.assertEqual(self._refs(
            "// @e2e my-spec::foo-does-bar\n"
            "xit('minutes are published', async () => { await go() })\n"), set())

    def test_fixme_does_not_count(self):
        self.assertEqual(self._refs(
            "// @e2e my-spec::foo-does-bar\n"
            "test.fixme('broken', async () => { await go() })\n"), set())

    def test_empty_body_does_not_count(self):
        self.assertEqual(self._refs(
            "// @e2e my-spec::foo-does-bar\n"
            "test('minutes are published', async ({ page }) => {})\n"), set())

    def test_a_body_of_only_comments_does_not_count(self):
        self.assertEqual(self._refs(
            "// @e2e my-spec::foo-does-bar\n"
            "test('later', async () => {\n"
            "  // TODO: write this once the endpoint lands\n"
            "  /* nothing here yet */\n"
            "})\n"), set())

    def test_argumentless_skip_does_not_count(self):
        self.assertEqual(self._refs(
            "// @e2e my-spec::foo-does-bar\n"
            "test('off', async ({ page }) => {\n"
            "  test.skip()\n"
            "  await expect(page).toHaveTitle(/x/)\n"
            "})\n"), set())

    # --- live: must STILL count -----------------------------------------
    def test_a_real_test_counts(self):
        self.assertIn("my-spec::foo-does-bar", self._refs(
            "// @e2e my-spec::foo-does-bar\n"
            "test('minutes are published', async ({ page }) => {\n"
            "  await expect(page.getByRole('heading')).toBeVisible()\n"
            "})\n"))

    def test_a_runtime_conditional_skip_still_counts(self):
        # THE anti-blindness pair. This test RUNS on every browser but one.
        # Refusing it would swap the gate's old blindness for a new one.
        self.assertIn("my-spec::foo-does-bar", self._refs(
            "// @e2e my-spec::foo-does-bar\n"
            "test('minutes are published', async ({ page, browserName }) => {\n"
            "  test.skip(browserName === 'firefox', 'flaky on gecko')\n"
            "  await expect(page.getByRole('heading')).toBeVisible()\n"
            "})\n"))

    def test_an_env_conditional_skip_still_counts(self):
        self.assertIn("my-spec::foo-does-bar", self._refs(
            "// @e2e my-spec::foo-does-bar\n"
            "test('needs a fixture', async ({ page }) => {\n"
            "  test.skip(!process.env.CI, 'needs a CI fixture')\n"
            "  await page.goto('/')\n"
            "})\n"))

    def test_one_live_reference_rescues_a_skipped_sibling(self):
        # A scenario proven by a real test is covered even if some other
        # skipped test also names it. The gate reports UNCOVERED, not
        # "you have a skipped test".
        self.assertIn("my-spec::foo-does-bar", self._refs(
            "// @e2e my-spec::foo-does-bar\n"
            "test.skip('old version', async () => { await go() })\n"
            "// @e2e my-spec::foo-does-bar\n"
            "test('new version', async ({ page }) => {\n"
            "  await expect(page).toHaveTitle(/x/)\n"
            "})\n"))

    def test_a_file_level_tag_with_no_enclosing_test_still_counts(self):
        # This gate is fixing tests that were switched OFF. It must not
        # invent a structural requirement it never had.
        self.assertIn("my-spec::foo-does-bar", self._refs(
            "// @e2e my-spec::foo-does-bar\n"
            "import { test, expect } from '@playwright/test'\n"))

    def test_dead_refs_are_reported_with_a_reason(self):
        _write(self.root, "tests/e2e/foo.spec.ts",
               "// @e2e my-spec::foo-does-bar\n"
               "test('x', async () => { test.skip(true, 'later') })\n")
        live, dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, set())
        self.assertIn("my-spec::foo-does-bar", dead)
        self.assertIn("never runs", dead["my-spec::foo-does-bar"])

    # --- a member call named `test` is not a test -----------------------
    def test_a_regexp_test_call_is_not_mistaken_for_the_enclosing_test(self):
        # openconnector dead-letter-replay.spec.ts, verbatim in shape: a
        # console-filter helper sits between the file-level @e2e tags and the
        # real tests, and it calls RegExp.prototype.test. The forward search
        # landed on `rx.test(text)`, found no body, and reported all 11 refs
        # as "referenced only by a test that never runs" — about a file whose
        # tests run fine.
        self.assertIn("my-spec::foo-does-bar", self._refs(
            "// @e2e my-spec::foo-does-bar\n"
            "const IGNORED = [/Deprecation/i]\n"
            "function spy(page) {\n"
            "  page.on('console', (msg) => {\n"
            "    if (IGNORED.some((rx) => rx.test(msg.text()))) return\n"
            "  })\n"
            "}\n"
            "test('the view mounts', async ({ page }) => {\n"
            "  await expect(page).toHaveTitle(/x/)\n"
            "})\n"))

    def test_an_identifier_merely_ending_in_test_is_not_a_test(self):
        # `latest(` / `submit(` end in `it`/`test` and must not open a block.
        self.assertIn("my-spec::foo-does-bar", self._refs(
            "// @e2e my-spec::foo-does-bar\n"
            "const v = latest(versions)\n"
            "test('the view mounts', async ({ page }) => {\n"
            "  await expect(page).toHaveTitle(/x/)\n"
            "})\n"))

    def test_a_member_call_does_not_rescue_a_genuinely_skipped_test(self):
        # THE CONTROL. Ignoring `rx.test(...)` must not make the gate skip
        # forward past a real skipped test and find a live one instead — the
        # skipped test still owns this tag, and it must still be dead.
        _write(self.root, "tests/e2e/foo.spec.ts",
               "// @e2e my-spec::foo-does-bar\n"
               "const ok = /x/.test('x')\n"
               "test('x', async () => { test.skip(true, 'later') })\n"
               "test('unrelated', async ({ page }) => {\n"
               "  await expect(page).toHaveTitle(/x/)\n"
               "})\n")
        live, dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, set())
        self.assertIn("my-spec::foo-does-bar", dead)


# ---------------------------------------------------------------------------
# A SWITCHED-OFF ANCESTOR TAKES THE TAG WITH IT  (#210)
#
# `_enclosing_block` searched FORWARD only. A tag written where the convention
# says to write it — immediately above the `test()` it annotates — resolved to
# that inner, un-skipped test, and the `test.describe.skip` wrapping both was
# never consulted. The ref counted as coverage while nothing ran.
#
# A second, wider defect was found while writing these: `_TEST_DECL_RE` could
# not match `test.describe.skip(` AT ALL. At `test` the modifier group finds
# `.describe` instead of `.skip` so the required `(` fails; at `describe` the
# `(?<![.\w$])` lookbehind sees the preceding dot and refuses. So the
# "correctly dead" case in #210's own reproduction — the tag ABOVE the skipped
# describe — was in fact reported LIVE too. Both are covered below.
#
# EVERY dead assertion here is paired with a live one. `describe.only`,
# `describe.serial`, a live `describe`, and a sibling that merely follows a
# closed skipped block must all keep counting: refusing them would trade this
# gate's blindness for the opposite blindness, which is the same defect with
# the sign flipped.
# ---------------------------------------------------------------------------
class SkippedAncestorTest(unittest.TestCase):
    def setUp(self):
        self.root = Path(tempfile.mkdtemp())

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)

    def _refs(self, body: str) -> set:
        _write(self.root, "tests/e2e/foo.spec.ts", body)
        return cec.collect_covered_refs(self.root)

    # --- dead: an ancestor that never runs ------------------------------
    def test_tag_INSIDE_a_skipped_describe_does_not_count(self):
        # THE #210 DEFECT, verbatim. The tag sits where the docstring says to
        # put it and the forward search lands on the live inner `test()`.
        self.assertEqual(self._refs(
            "test.describe.skip('outer B', () => {\n"
            "  // @e2e demo::inside\n"
            "  test('inner b', async ({ page }) => {\n"
            "    await expect(page).toBeTruthy()\n"
            "  })\n"
            "})\n"), set())

    def test_tag_ABOVE_a_skipped_describe_does_not_count(self):
        # #210 believed this case already worked. It did not: the namespaced
        # `test.describe.skip(` was invisible to the declaration regex, so the
        # forward search stepped straight over it onto the inner test.
        self.assertEqual(self._refs(
            "// @e2e demo::above\n"
            "test.describe.skip('outer A', () => {\n"
            "  test('inner a', async ({ page }) => {\n"
            "    await expect(page).toBeTruthy()\n"
            "  })\n"
            "})\n"), set())

    def test_bare_describe_skip_ancestor_does_not_count(self):
        self.assertEqual(self._refs(
            "describe.skip('outer', () => {\n"
            "  // @e2e demo::inside\n"
            "  it('inner', async () => { await go() })\n"
            "})\n"), set())

    def test_xdescribe_ancestor_does_not_count(self):
        self.assertEqual(self._refs(
            "xdescribe('outer', () => {\n"
            "  // @e2e demo::inside\n"
            "  it('inner', async () => { await go() })\n"
            "})\n"), set())

    def test_describe_fixme_ancestor_does_not_count(self):
        self.assertEqual(self._refs(
            "test.describe.fixme('outer', () => {\n"
            "  // @e2e demo::inside\n"
            "  test('inner', async ({ page }) => { await expect(page).toBeTruthy() })\n"
            "})\n"), set())

    def test_a_live_describe_nested_in_a_skipped_one_does_not_count(self):
        # The INNERMOST enclosing block runs, and it still never executes.
        self.assertEqual(self._refs(
            "test.describe.skip('outer', () => {\n"
            "  test.describe('inner group', () => {\n"
            "    // @e2e demo::deep\n"
            "    test('t', async ({ page }) => { await expect(page).toBeTruthy() })\n"
            "  })\n"
            "})\n"), set())

    def test_the_scholiq_shape_repeated_tags_inside_the_block(self):
        # scholiq peer-and-self-assessment.spec.ts: the header tags above the
        # `describe.skip` were dead, and the SAME refs repeated inside the
        # block resurrected them. Both copies must now be dead.
        self.assertEqual(self._refs(
            "// @e2e demo::a\n"
            "// @e2e demo::b\n"
            "test.describe.skip('needs an isolated instance', () => {\n"
            "  // @e2e demo::a\n"
            "  test('one', async ({ page }) => { await expect(page).toBeTruthy() })\n"
            "  // @e2e demo::b\n"
            "  test('two', async ({ page }) => { await expect(page).toBeTruthy() })\n"
            "})\n"), set())

    # --- live: must STILL count -----------------------------------------
    def test_tag_inside_a_LIVE_describe_still_counts(self):
        # THE CONTROL for every assertion above. If this ever fails, the
        # ancestor walk has stopped discriminating and is simply killing
        # everything nested.
        self.assertIn("demo::inside", self._refs(
            "test.describe('outer', () => {\n"
            "  // @e2e demo::inside\n"
            "  test('inner', async ({ page }) => {\n"
            "    await expect(page).toBeTruthy()\n"
            "  })\n"
            "})\n"))

    def test_describe_only_is_not_switched_off(self):
        # `.only` RUNS — it suppresses everything else, which is the opposite
        # of being skipped.
        self.assertIn("demo::inside", self._refs(
            "test.describe.only('outer', () => {\n"
            "  // @e2e demo::inside\n"
            "  test('inner', async ({ page }) => { await expect(page).toBeTruthy() })\n"
            "})\n"))

    def test_describe_serial_is_not_switched_off(self):
        self.assertIn("demo::inside", self._refs(
            "test.describe.serial('outer', () => {\n"
            "  // @e2e demo::inside\n"
            "  test('inner', async ({ page }) => { await expect(page).toBeTruthy() })\n"
            "})\n"))

    def test_describe_configure_is_not_a_declaration(self):
        # `test.describe.configure({...})` is a settings call, not a block. It
        # must neither open a block nor be mistaken for one by the forward
        # search that follows a file-level tag.
        self.assertIn("demo::top", self._refs(
            "// @e2e demo::top\n"
            "test.describe.configure({ mode: 'parallel' })\n"
            "test('real', async ({ page }) => { await expect(page).toBeTruthy() })\n"))

    def test_a_sibling_AFTER_a_closed_skipped_describe_still_counts(self):
        # The span check must be "encloses", not "appears earlier". A skipped
        # block that has already closed is a sibling and must not poison what
        # follows it.
        self.assertIn("demo::after", self._refs(
            "test.describe.skip('dead group', () => {\n"
            "  test('x', async ({ page }) => { await expect(page).toBeTruthy() })\n"
            "})\n"
            "// @e2e demo::after\n"
            "test('live one', async ({ page }) => {\n"
            "  await expect(page).toBeTruthy()\n"
            "})\n"))

    def test_a_runtime_conditional_skip_inside_a_live_describe_still_counts(self):
        # The gate's original anti-blindness pair, now with an ancestor in the
        # picture: this runs on every browser but one.
        self.assertIn("demo::inside", self._refs(
            "test.describe('outer', () => {\n"
            "  // @e2e demo::inside\n"
            "  test('inner', async ({ page, browserName }) => {\n"
            "    test.skip(browserName === 'firefox', 'flaky on gecko')\n"
            "    await expect(page).toBeTruthy()\n"
            "  })\n"
            "})\n"))

    def test_a_live_sibling_rescues_a_ref_dead_inside_a_skipped_describe(self):
        # Same rule the module already applies to skipped tests: one running
        # reference is coverage. The gate reports UNCOVERED, not "you have a
        # skipped describe".
        self.assertIn("demo::both", self._refs(
            "test.describe.skip('dead group', () => {\n"
            "  // @e2e demo::both\n"
            "  test('x', async ({ page }) => { await expect(page).toBeTruthy() })\n"
            "})\n"
            "// @e2e demo::both\n"
            "test('live one', async ({ page }) => {\n"
            "  await expect(page).toBeTruthy()\n"
            "})\n"))

    # --- the regex must still reject what it always rejected -------------
    def test_a_member_call_named_test_is_still_not_a_declaration(self):
        # The namespace segment added for `test.describe` must not have
        # widened into "any member call". `rx.test(` is RegExp.prototype.test.
        self.assertIn("demo::live", self._refs(
            "// @e2e demo::live\n"
            "const IGNORED = [/Deprecation/i]\n"
            "function spy(page) {\n"
            "  page.on('console', (msg) => {\n"
            "    if (IGNORED.some((rx) => rx.test(msg.text()))) return\n"
            "  })\n"
            "}\n"
            "test('the view mounts', async ({ page }) => {\n"
            "  await expect(page).toBeTruthy()\n"
            "})\n"))

    # --- ownership of an unconditional skip -----------------------------
    def test_a_nested_tests_own_skip_does_not_kill_the_whole_group(self):
        # launchpad spec-coverage.spec.ts, in shape: a file-level tag now
        # resolves to the enclosing `test.describe`, and ONE nested test in
        # that group guards itself with `test.skip(true, …)`. The other tests
        # run. Condemning the group is the gate's blindness with the sign
        # flipped.
        self.assertIn("demo::header", self._refs(
            "// @e2e demo::header\n"
            "test.describe('sidebar', () => {\n"
            "  test('a', async ({ page }) => {\n"
            "    await expect(page).toBeTruthy()\n"
            "  })\n"
            "  test('b', async ({ page }) => {\n"
            "    test.skip(true, 'not available in this environment')\n"
            "    await expect(page).toBeTruthy()\n"
            "  })\n"
            "})\n"))

    def test_a_group_level_unconditional_skip_still_kills_the_group(self):
        # THE CONTROL. Playwright's `test.skip()` called directly in a describe
        # body skips every test in the group, and that must still be dead.
        self.assertEqual(self._refs(
            "// @e2e demo::header\n"
            "test.describe('sidebar', () => {\n"
            "  test.skip()\n"
            "  test('a', async ({ page }) => {\n"
            "    await expect(page).toBeTruthy()\n"
            "  })\n"
            "})\n"), set())

    def test_a_tests_own_unconditional_skip_still_kills_that_test(self):
        # The decidesk shape must not be rescued by the ownership rule.
        self.assertEqual(self._refs(
            "test.describe('group', () => {\n"
            "  // @e2e demo::inner\n"
            "  test('minutes are published', async ({ page }) => {\n"
            "    test.skip(true, 'pending backend work')\n"
            "  })\n"
            "})\n"), set())

    def test_the_four_case_fixture_from_the_issue(self):
        # #210's minimal reproduction, whole, in one file — the shape the fix
        # is measured against.
        _write(self.root, "tests/e2e/a.spec.ts",
               "import { test, expect } from '@playwright/test'\n"
               "\n"
               "// @e2e demo::tag-above-a-skipped-describe\n"
               "test.describe.skip('outer A', () => {\n"
               "  test('inner a', async ({ page }) => { await expect(page).toBeTruthy() })\n"
               "})\n"
               "\n"
               "test.describe.skip('outer B', () => {\n"
               "  // @e2e demo::tag-inside-a-skipped-describe\n"
               "  test('inner b', async ({ page }) => { await expect(page).toBeTruthy() })\n"
               "})\n"
               "\n"
               "// @e2e demo::plain-skipped-test\n"
               "test.skip('plain skipped', async ({ page }) => { await expect(page).toBeTruthy() })\n"
               "\n"
               "// @e2e demo::genuinely-live\n"
               "test('live one', async ({ page }) => { await expect(page).toBeTruthy() })\n")
        live, dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, {"demo::genuinely-live"})
        self.assertEqual(set(dead), {
            "demo::plain-skipped-test",
            "demo::tag-above-a-skipped-describe",
            "demo::tag-inside-a-skipped-describe",
        })


# ---------------------------------------------------------------------------
# #234 — A TRAILING COMMA BEFORE THE CLOSING PAREN
#
# The body was found by stepping back from `)` over whitespace and requiring a
# `}`. Prettier's default and ESLint's `comma-dangle: always-multiline` put a
# `,` at exactly that position, so `body` stayed "" and the empty-body rule
# condemned a real, asserting test.
#
# Measured on softwarecatalog `tests/e2e/org-archimate-export.spec.ts:303`,
# which drives a combobox, toggles checkboxes, clicks a button and asserts the
# outgoing request shape.
# ---------------------------------------------------------------------------
class TrailingCommaTest(unittest.TestCase):
    def setUp(self):
        self.root = Path(tempfile.mkdtemp())

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)

    def test_a_trailing_comma_test_with_a_real_body_is_LIVE(self):
        _write(self.root, "tests/e2e/a.spec.ts",
               "// @e2e demo::trailing-comma\n"
               "test(\n"
               "  'name',\n"
               "  async ({ page }) => {\n"
               "    await expect(page).toBeTruthy()\n"
               "  },\n"
               ")\n")
        live, dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, {"demo::trailing-comma"})
        self.assertEqual(dead, {})

    def test_THE_CONTROL_a_trailing_comma_test_with_an_EMPTY_body_is_DEAD(self):
        # The empty-body rule must survive the fix. Without this assertion the
        # fix could be "call everything live", which is the failure mode this
        # gate exists to prevent.
        _write(self.root, "tests/e2e/a.spec.ts",
               "// @e2e demo::trailing-comma-empty\n"
               "test(\n"
               "  'name',\n"
               "  async ({ page }) => {\n"
               "    // nothing here\n"
               "  },\n"
               ")\n")
        live, dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, set())
        self.assertIn("demo::trailing-comma-empty", dead)

    def test_THE_CONTROL_a_trailing_comma_test_SKIP_is_DEAD(self):
        _write(self.root, "tests/e2e/a.spec.ts",
               "// @e2e demo::trailing-comma-skip\n"
               "test.skip(\n"
               "  'name',\n"
               "  async ({ page }) => {\n"
               "    await expect(page).toBeTruthy()\n"
               "  },\n"
               ")\n")
        live, dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, set())
        self.assertIn("demo::trailing-comma-skip", dead)

    def test_the_no_trailing_comma_case_is_unchanged(self):
        _write(self.root, "tests/e2e/a.spec.ts",
               "// @e2e demo::no-trailing-comma\n"
               "test(\n"
               "  'name',\n"
               "  async ({ page }) => {\n"
               "    await expect(page).toBeTruthy()\n"
               "  }\n"
               ")\n")
        live, _dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, {"demo::no-trailing-comma"})


# ---------------------------------------------------------------------------
# #239 — A CONDITIONAL `test.skip(true, reason)` INSIDE AN `if` GUARD
#
# `test.skip(true, reason)` is ALSO the correct Playwright spelling for a
# conditional skip written inside a guard: the `true` is the API's "skip from
# this point" shape and the CALL SITE carries the condition. Counted with the
# gate's own patterns: 111 guarded call sites in the fleet against 4 genuinely
# unconditional ones.
#
# Live confirmation: ConductionNL/procest#765 flagged req-zak-004a/b on a test
# whose only skip is inside `if (!response)`. The E2E job on that same commit
# reported 87 passed / 0 failed; this test was one of the 87.
#
# It is worse than an ordinary false positive: the remedy the gate prints is
# "replace the tag with a reason-bearing @e2e exclude", so complying DELETES a
# true coverage claim.
# ---------------------------------------------------------------------------
class ConditionalSkipTest(unittest.TestCase):
    def setUp(self):
        self.root = Path(tempfile.mkdtemp())

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)

    def test_the_reproduction_from_the_issue_both_ways(self):
        _write(self.root, "tests/e2e/demo.spec.ts",
               "// @e2e demo::s1-guarded-skip-inside-an-if\n"
               "test('guarded: runs whenever the app is reachable', async ({ page }) => {\n"
               "\tconst response = await page.goto('/app').catch(() => null)\n"
               "\tif (!response) {\n"
               "\t\ttest.skip(true, 'app not reachable')\n"
               "\t\treturn\n"
               "\t}\n"
               "\tawait expect(page.locator('body')).not.toContainText('Internal Server Error')\n"
               "})\n"
               "\n"
               "// @e2e demo::s2-no-skip-at-all\n"
               "test('clean: no skip anywhere', async ({ page }) => {\n"
               "\tawait page.goto('/app')\n"
               "\tawait expect(page.locator('body')).not.toContainText('Internal Server Error')\n"
               "})\n")
        live, dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, {"demo::s1-guarded-skip-inside-an-if",
                                "demo::s2-no-skip-at-all"})
        self.assertEqual(dead, {})

    def test_THE_CONTROL_a_top_of_body_unconditional_skip_is_still_DEAD(self):
        # The rule the original comment was written for. Without this, the
        # #239 fix would be a blanket amnesty for every `test.skip(true, …)`.
        _write(self.root, "tests/e2e/demo.spec.ts",
               "// @e2e demo::permanently-off\n"
               "test('turned off', async ({ page }) => {\n"
               "\ttest.skip(true, 'broken since March, see #123')\n"
               "\tawait expect(page.locator('body')).toBeVisible()\n"
               "})\n")
        live, dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, set())
        self.assertIn("demo::permanently-off", dead)

    def test_a_BRACELESS_guard_is_still_conditional(self):
        _write(self.root, "tests/e2e/demo.spec.ts",
               "// @e2e demo::braceless-guard\n"
               "test('guarded', async ({ page }) => {\n"
               "\tconst ok = await page.goto('/app')\n"
               "\tif (!ok) test.skip(true, 'not reachable')\n"
               "\tawait expect(page.locator('body')).toBeVisible()\n"
               "})\n")
        live, _dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, {"demo::braceless-guard"})

    def test_a_skip_inside_a_catch_is_still_conditional(self):
        _write(self.root, "tests/e2e/demo.spec.ts",
               "// @e2e demo::catch-guard\n"
               "test('guarded', async ({ page }) => {\n"
               "\ttry {\n"
               "\t\tawait page.goto('/app')\n"
               "\t} catch (e) {\n"
               "\t\ttest.skip(true, 'unreachable')\n"
               "\t}\n"
               "\tawait expect(page.locator('body')).toBeVisible()\n"
               "})\n")
        live, _dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, {"demo::catch-guard"})

    def test_a_group_level_unconditional_skip_still_kills_the_group(self):
        # Playwright's `test.skip()` called directly in a describe body skips
        # every test in the group. Brace depth 0, no guard — still dead.
        _write(self.root, "tests/e2e/demo.spec.ts",
               "test.describe('group', () => {\n"
               "\ttest.skip(true, 'whole group is off')\n"
               "\t// @e2e demo::inside-a-skipped-group\n"
               "\ttest('a', async ({ page }) => { await expect(page).toBeTruthy() })\n"
               "})\n")
        live, dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, set())
        self.assertIn("demo::inside-a-skipped-group", dead)

    def test_a_GUARDED_group_level_skip_does_NOT_kill_the_group(self):
        _write(self.root, "tests/e2e/demo.spec.ts",
               "test.describe('group', () => {\n"
               "\tif (!process.env.CI) {\n"
               "\t\ttest.skip(true, 'needs CI fixtures')\n"
               "\t}\n"
               "\t// @e2e demo::inside-a-guarded-group\n"
               "\ttest('a', async ({ page }) => { await expect(page).toBeTruthy() })\n"
               "})\n")
        live, _dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, {"demo::inside-a-guarded-group"})


# ---------------------------------------------------------------------------
# #244 — A TAG WRITTEN INSIDE THE `test(` ARGUMENT LIST
#
# WHAT IT ACTUALLY WAS. The issue guessed "the search runs forward, so it
# either finds the next test's declaration or runs off the end". The first
# half is exactly right and it is the whole mechanism: nldesign writes every
# tag BETWEEN the open paren and the title, so a forward-only search binds
# each tag to the NEXT test in the file. On nldesign that mis-binding then met
# #234 on the test it landed on — every one of those declarations ends `},\n)`
# — so the wrong test also read as an empty body, and 34 findings came out.
#
# Two defects, one symptom. Which is why the fixture asserts the mis-binding
# directly (below) and not just the count.
# ---------------------------------------------------------------------------
class TagInsideTheDeclarationTest(unittest.TestCase):
    def setUp(self):
        self.root = Path(tempfile.mkdtemp())

    def tearDown(self):
        shutil.rmtree(self.root, ignore_errors=True)

    NLDESIGN_SHAPE = (
        "import { test, expect } from '@playwright/test'\n"
        "\n"
        "const THEMING_URL = '/settings/admin/theming'\n"
        "\n"
        "test.describe('admin-settings', () => {\n"
        "\n"
        "\ttest(\n"
        "\t\t// @e2e openspec/specs/admin-settings/spec.md#settings-panel-appears-in-admin-area\n"
        "\t\t'Settings panel appears in admin area',\n"
        "\t\tasync ({ page }) => {\n"
        "\t\t\tawait page.goto(THEMING_URL)\n"
        "\t\t\tconst heading = page.locator('h2:has-text(\"NL Design System Theme\")')\n"
        "\t\t\tawait expect(heading).toBeVisible()\n"
        "\t\t},\n"
        "\t)\n"
        "\n"
        "\ttest(\n"
        "\t\t// @e2e openspec/specs/admin-settings/spec.md#dropdown-populated-with-token-sets\n"
        "\t\t'Dropdown populated with token sets',\n"
        "\t\tasync ({ page }) => {\n"
        "\t\t\tawait page.goto(THEMING_URL)\n"
        "\t\t\tawait expect(page.locator('select')).toBeVisible()\n"
        "\t\t},\n"
        "\t)\n"
        "})\n"
    )

    def test_the_nldesign_shape_is_LIVE(self):
        _write(self.root, "tests/e2e/admin-settings.spec.ts", self.NLDESIGN_SHAPE)
        live, dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, {
            "admin-settings::settings-panel-appears-in-admin-area",
            "admin-settings::dropdown-populated-with-token-sets",
        })
        self.assertEqual(dead, {})

    def test_the_tag_binds_to_ITS_OWN_test_not_the_next_one(self):
        # The mis-binding, asserted directly. A count-only assertion would go
        # green if the tags bound to the wrong (but live) test.
        doc = cec._TestFile(self.NLDESIGN_SHAPE)
        pos = self.NLDESIGN_SHAPE.index("#settings-panel-appears-in-admin-area")
        owner = doc.owner(pos)
        self.assertIsNotNone(owner)
        self.assertIn("Settings panel appears in admin area",
                      self.NLDESIGN_SHAPE[owner.start:owner.close])
        self.assertNotIn("Dropdown populated with token sets",
                         self.NLDESIGN_SHAPE[owner.start:owner.close])

    def test_THE_CONTROL_a_tag_inside_a_declaration_that_is_SKIPPED_is_DEAD(self):
        _write(self.root, "tests/e2e/a.spec.ts",
               "test.skip(\n"
               "\t// @e2e demo::inside-a-skipped-declaration\n"
               "\t'name',\n"
               "\tasync ({ page }) => {\n"
               "\t\tawait expect(page).toBeTruthy()\n"
               "\t},\n"
               ")\n")
        live, dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, set())
        self.assertIn("demo::inside-a-skipped-declaration", dead)

    def test_THE_CONTROL_a_tag_inside_a_declaration_with_an_EMPTY_body_is_DEAD(self):
        _write(self.root, "tests/e2e/a.spec.ts",
               "test(\n"
               "\t// @e2e demo::inside-an-empty-declaration\n"
               "\t'name',\n"
               "\tasync ({ page }) => {\n"
               "\t},\n"
               ")\n")
        live, dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, set())
        self.assertIn("demo::inside-an-empty-declaration", dead)

    def test_a_tag_inside_the_BODY_still_binds_to_its_own_test(self):
        # The third position in fleet use. A forward search from here escaped
        # the body and bound the tag to the NEXT test.
        src = ("test('first', async ({ page }) => {\n"
               "\t// @e2e demo::tag-in-the-body\n"
               "\tawait expect(page).toBeTruthy()\n"
               "})\n"
               "\n"
               "test.skip('second', async ({ page }) => {\n"
               "\tawait expect(page).toBeTruthy()\n"
               "})\n")
        _write(self.root, "tests/e2e/a.spec.ts", src)
        live, _dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, {"demo::tag-in-the-body"})

    def test_the_conventional_ABOVE_position_is_unchanged(self):
        _write(self.root, "tests/e2e/a.spec.ts",
               "// @e2e demo::above\n"
               "test('t', async ({ page }) => { await expect(page).toBeTruthy() })\n")
        live, _dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, {"demo::above"})

    def test_a_tag_in_a_DESCRIBE_header_annotates_the_describe_not_its_first_child(self):
        # The header branch of owner(), isolated. Everything else about #244
        # is carried by "the search must not escape the containing node", and
        # a mutation run proved this branch had NO test that could see it:
        # with the branch deleted the whole suite still passed, because a
        # test() header has no children so the fallback returned the same
        # node. A describe header does have children, so it discriminates.
        src = ("test.describe(\n"
               "\t// @e2e demo::describe-header-tag\n"
               "\t'group',\n"
               "\t() => {\n"
               "\t\ttest('inner', async ({ page }) => { await expect(page).toBeTruthy() })\n"
               "\t},\n"
               ")\n")
        doc = cec._TestFile(src)
        owner = doc.owner(src.index("@e2e") + 4)
        self.assertIsNotNone(owner)
        self.assertEqual(owner.fn, "describe")

    def test_a_tag_at_the_END_of_a_body_does_not_bind_to_the_NEXT_test(self):
        # The escape, isolated. The old resolver searched forward from the tag
        # across the whole file, so a tag with no test after it inside its own
        # body bound to the next SIBLING — a different test entirely.
        src = ("test('first', async ({ page }) => {\n"
               "\tawait expect(page).toBeTruthy()\n"
               "\t// @e2e demo::at-the-end-of-a-body\n"
               "})\n"
               "\n"
               "test.skip('second', async ({ page }) => {\n"
               "\tawait expect(page).toBeTruthy()\n"
               "})\n")
        doc = cec._TestFile(src)
        owner = doc.owner(src.index("@e2e") + 4)
        self.assertIsNotNone(owner)
        self.assertIn("'first'", src[owner.start:owner.close])
        _write(self.root, "tests/e2e/a.spec.ts", src)
        live, _dead = cec.collect_ref_status(self.root)
        self.assertEqual(live, {"demo::at-the-end-of-a-body"})


# ---------------------------------------------------------------------------
# The declaration recogniser, directly. These are the unit-level counterparts
# of the behaviour above: `test.describe.skip(` being recognised AT ALL is the
# precondition for every dead assertion in the class above, and `rx.test(` NOT
# being recognised is the precondition for the live ones.
#
# This used to poke `_TEST_DECL_RE` and read its `mod` group. There is no such
# regex any more — a declaration is now a node in a parse of the file — so the
# same questions are asked of the parse.
# ---------------------------------------------------------------------------
class DeclarationRecogniserTest(unittest.TestCase):
    def _decl(self, src: str):
        """(fn, switched_off) of the FIRST declaration in src, or None."""
        doc = cec._TestFile(src)
        if not doc.nodes:
            return None
        nd = doc.nodes[0]
        return (nd.fn, nd.switched_off)

    def test_namespaced_describe_skip_matches(self):
        self.assertEqual(self._decl("test.describe.skip('a', () => {})"),
                         ("describe", True))

    def test_namespaced_describe_matches_and_is_live(self):
        self.assertEqual(self._decl("test.describe('a', () => {})"),
                         ("describe", False))

    def test_bare_forms_still_match(self):
        self.assertEqual(self._decl("test('a', () => {})"), ("test", False))
        self.assertEqual(self._decl("describe('a', () => {})"), ("describe", False))
        self.assertEqual(self._decl("test.skip('a', () => {})"), ("test", True))

    def test_serial_and_only_are_not_modifiers(self):
        self.assertEqual(self._decl("test.describe.serial('a', () => {})"),
                         ("describe", False))
        self.assertEqual(self._decl("test.describe.only('a', () => {})"),
                         ("describe", False))

    def test_member_calls_are_still_rejected(self):
        for src in ("rx.test(msg)", "foo.it(1)", "latest(versions)", "submit(form)"):
            self.assertIsNone(self._decl(src), src)

    def test_hooks_and_config_calls_are_not_declarations(self):
        for src in ("test.beforeEach(async () => {})",
                    "test.use({ locale: 'nl' })",
                    "test.step('x', async () => {})",
                    "test.setTimeout(120000)",
                    "test.slow()",
                    "test.describe.configure({ mode: 'parallel' })"):
            self.assertIsNone(self._decl(src), src)

    def test_a_skip_STATEMENT_is_not_a_declaration(self):
        # `test.skip(cond, 'reason')` is a call inside a running test.
        # `test.skip('title', fn)` declares a skipped test. The first argument
        # is the only thing that tells them apart, which is why the code mask
        # keeps string DELIMITERS.
        self.assertIsNone(self._decl("test.skip(true, 'off')"))
        self.assertIsNone(self._decl("test.skip(browserName === 'firefox', 'x')"))
        self.assertIsNone(self._decl("test.skip()"))
        self.assertEqual(self._decl("test.skip('title', async () => {})"),
                         ("test", True))


# ---------------------------------------------------------------------------
# THE LEXER — the thing all three of #234 / #239 / #244 were symptoms of.
#
# Reading JS with regexes fails on the constructs a tokeniser exists to see.
# These assert the mask itself, so a regression shows up here rather than as a
# mystery finding on a repo.
# ---------------------------------------------------------------------------
class CodeMaskTest(unittest.TestCase):
    def test_the_mask_preserves_length_and_newlines(self):
        src = "const a = 'xx' // c\nconst b = `yy`\n/* z */\n"
        mask = cec._code_mask(src)
        self.assertEqual(len(mask), len(src))
        self.assertEqual(mask.count("\n"), src.count("\n"))

    def test_string_contents_are_blanked_but_delimiters_kept(self):
        mask = cec._code_mask("const a = 'test('")
        self.assertNotIn("test(", mask)
        self.assertEqual(mask.count("'"), 2)

    def test_a_test_call_inside_a_string_is_not_a_declaration(self):
        doc = cec._TestFile("const s = \"test('fake', () => {})\"\n")
        self.assertEqual(doc.nodes, [])

    def test_a_test_call_inside_a_comment_is_not_a_declaration(self):
        doc = cec._TestFile("// test('fake', () => {})\n/* test('x', fn) */\n")
        self.assertEqual(doc.nodes, [])

    def test_a_brace_inside_a_string_does_not_unbalance_a_body(self):
        # The old paren/brace walk counted every character, so a `}` in a
        # string could end a body early or a `(` could never close.
        src = ("// @e2e demo::braces-in-a-string\n"
               "test('t', async ({ page }) => {\n"
               "  await expect(page.locator('a)')).toHaveText('}')\n"
               "})\n")
        doc = cec._TestFile(src)
        self.assertEqual(len(doc.nodes), 1)
        self.assertFalse(doc.body_is_empty(doc.nodes[0]))
        self.assertTrue(cec._ref_is_live(doc, src.index("@e2e") + 4))

    def test_a_regex_literal_containing_a_brace_does_not_unbalance(self):
        src = ("// @e2e demo::regex-with-braces\n"
               "test('t', async ({ page }) => {\n"
               "  expect('a').toMatch(/^[a-z]{1,3}$/)\n"
               "})\n")
        doc = cec._TestFile(src)
        self.assertEqual(len(doc.nodes), 1)
        self.assertTrue(cec._ref_is_live(doc, src.index("@e2e") + 4))

    def test_a_division_is_not_mistaken_for_a_regex(self):
        # `total / 2` followed by more code — if `/` opened a "regex" the rest
        # of the line would be blanked and the body could read as empty.
        src = ("// @e2e demo::division\n"
               "test('t', async ({ page }) => {\n"
               "  const half = (total) / 2\n"
               "  await expect(half).toBe(1)\n"
               "})\n")
        doc = cec._TestFile(src)
        self.assertTrue(cec._ref_is_live(doc, src.index("@e2e") + 4))

    def test_a_template_literal_with_substitutions_is_blanked_whole(self):
        src = ("// @e2e demo::template\n"
               "test('t', async ({ page }) => {\n"
               "  await page.goto(`/apps/${app}/x?y=${ {a: 1}.a }`)\n"
               "})\n")
        doc = cec._TestFile(src)
        self.assertEqual(len(doc.nodes), 1)
        self.assertTrue(cec._ref_is_live(doc, src.index("@e2e") + 4))


# ---------------------------------------------------------------------------
# .github#308 — a file the Playwright config never runs is as dead as
# `describe.skip`, and this gate could not see it.
#
# The config below is openregister's real one, reduced: a top-level
# `testIgnore` carrying `**/api-direct/**`, a default project that REPEATS it
# (a project-level `testIgnore` REPLACES the top-level one — Playwright does
# not merge them, which is why every fleet config repeats the entry), and two
# opt-in projects that pull `visual/**` and `docs-screenshots.spec.ts` BACK IN
# via `testMatch`.
#
# That last part is the whole false-positive surface. `**/visual/**` and
# `**/docs-screenshots.spec.ts` appear in a `testIgnore` in fourteen of the
# fleet's configs; treating "named in some testIgnore" as dead would strip
# coverage credit from every visual and docs spec in the fleet. Validated
# against all 21 real configs: 0 dead files anywhere except the api-direct
# trees that are excluded on purpose (openregister 25, openconnector 6).
_FLEET_CONFIG = """
import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
\ttestDir: './tests/e2e',
\tprojects: [
\t\t{
\t\t\tname: 'chromium',
\t\t\t// NOTE: a project-level testIgnore REPLACES the top-level testIgnore
\t\t\t// for this project, so the api-direct exclusion must be repeated here.
\t\t\ttestIgnore: [
\t\t\t\t'**/docs-screenshots.spec.ts',
\t\t\t\t'**/api-direct/**',
\t\t\t\t'**/visual/**',
\t\t\t],
\t\t\tuse: { ...devices['Desktop Chrome'] },
\t\t},
\t\t{
\t\t\tname: 'docs-capture',
\t\t\ttestMatch: /docs-screenshots\\.spec\\.ts$/,
\t\t},
\t\t{
\t\t\tname: 'visual',
\t\t\ttestMatch: /visual\\/.*\\.visual\\.spec\\.ts$/,
\t\t\ttestIgnore: [],
\t\t},
\t],
\ttestIgnore: [
\t\t'**/node_modules/**',
\t\t// API-direct specs are HTTP-contract assertions covered by Newman,
\t\t// not UI-driving Playwright tests.
\t\t'**/api-direct/**',
\t],
})
"""

_SPEC_MD = """# Saved search views

#### Scenario: Presentation of a saved view

- **WHEN** a user opens a saved view
- **THEN** the columns are rendered in the stored order
"""


class PlaywrightConfigScopeTest(unittest.TestCase):
    """A scenario proved only by a file no project runs is NOT covered."""

    def setUp(self):
        self.root = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.root, True)
        _write(self.root, "playwright.config.ts", _FLEET_CONFIG)
        _write(self.root, "openspec/specs/saved-search-views/spec.md", _SPEC_MD)
        self.ref = "saved-search-views::presentation-of-a-saved-view"
        self.tag = ("// @e2e openspec/specs/saved-search-views/spec.md"
                    "#presentation-of-a-saved-view\n"
                    "test('renders', async ({ page }) => {\n"
                    "  await page.goto('/apps/openregister/views')\n"
                    "})\n")

    def test_a_tag_in_an_IGNORED_directory_does_not_cover_the_scenario(self):
        # The measured shape: openregister's
        # tests/e2e/api-direct/search-views-presentation.spec.ts.
        _write(self.root, "tests/e2e/api-direct/search-views-presentation.spec.ts",
               self.tag)
        live, dead = cec.collect_ref_status(self.root)
        self.assertNotIn(self.ref, live)
        self.assertIn(self.ref, dead)
        self.assertIn("no Playwright project runs", dead[self.ref])

    def test_the_SAME_tag_in_a_run_directory_DOES_cover_it(self):
        # Anti-widening control. Same tag, same file contents, one directory
        # over. If this ever goes red the gate has stopped counting real tests.
        _write(self.root, "tests/e2e/search-views-presentation.spec.ts", self.tag)
        live, _dead = cec.collect_ref_status(self.root)
        self.assertIn(self.ref, live)

    def test_a_VISUAL_spec_still_covers_although_the_default_project_ignores_it(self):
        # `**/visual/**` is in the chromium project's testIgnore; the `visual`
        # project's testMatch runs it. Fourteen fleet configs have this shape.
        _write(self.root, "tests/e2e/visual/views.visual.spec.ts", self.tag)
        live, _dead = cec.collect_ref_status(self.root)
        self.assertIn(self.ref, live)

    def test_a_DOCS_SCREENSHOT_spec_still_covers_for_the_same_reason(self):
        _write(self.root, "tests/e2e/docs-screenshots.spec.ts", self.tag)
        live, _dead = cec.collect_ref_status(self.root)
        self.assertIn(self.ref, live)

    def test_one_live_reference_rescues_a_ref_also_named_in_an_ignored_file(self):
        # A ref is dead only when NOTHING live references it.
        _write(self.root, "tests/e2e/api-direct/search-views.spec.ts", self.tag)
        _write(self.root, "tests/e2e/search-views.spec.ts", self.tag)
        live, dead = cec.collect_ref_status(self.root)
        self.assertIn(self.ref, live)
        self.assertNotIn(self.ref, dead)

    def test_NO_config_means_every_file_runs(self):
        # Uncertainty resolves to LIVE — a repo whose config cannot be read
        # behaves exactly as it did before #308.
        (self.root / "playwright.config.ts").unlink()
        _write(self.root, "tests/e2e/api-direct/search-views.spec.ts", self.tag)
        live, _dead = cec.collect_ref_status(self.root)
        self.assertIn(self.ref, live)

    def test_an_UNPARSABLE_config_means_every_file_runs(self):
        _write(self.root, "playwright.config.ts",
               "export default defineConfig(loadFromSomewhere())\n")
        _write(self.root, "tests/e2e/api-direct/search-views.spec.ts", self.tag)
        live, _dead = cec.collect_ref_status(self.root)
        self.assertIn(self.ref, live)

    def test_a_testIgnore_quoted_INSIDE_A_COMMENT_is_not_configuration(self):
        # Every fleet config explains the replace-not-merge rule in prose that
        # contains the word `testIgnore:`. Parsing the explanation instead of
        # the setting is #294's mistake in a different file.
        _write(self.root, "playwright.config.ts",
               "export default defineConfig({\n"
               "\ttestDir: './tests/e2e',\n"
               "\t// testIgnore: ['**/*.spec.ts'],  <- historical, do not use\n"
               "})\n")
        _write(self.root, "tests/e2e/search-views.spec.ts", self.tag)
        live, _dead = cec.collect_ref_status(self.root)
        self.assertIn(self.ref, live)


class GlobTranslationTest(unittest.TestCase):
    def test_double_star_slash_matches_zero_directories(self):
        r = cec._glob_to_re("**/api-direct/**")
        self.assertTrue(r.match("api-direct/x.spec.ts"))
        self.assertTrue(r.match("deep/nest/api-direct/x.spec.ts"))

    def test_single_star_does_not_cross_a_separator(self):
        r = cec._glob_to_re("*.spec.ts")
        self.assertTrue(r.match("a.spec.ts"))
        self.assertFalse(r.match("dir/a.spec.ts"))

    def test_extglob_and_braces_are_refused_rather_than_guessed(self):
        self.assertIsNone(cec._glob_to_re("**/*.@(spec|test).ts"))
        self.assertIsNone(cec._glob_to_re("**/{a,b}/**"))


# ---------------------------------------------------------------------------
# .github#331 — the gate read a DIFFERENT config than CI executes.
#
# The shared workflow does:
#
#     CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
#     if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
#       CONFIG="playwright.config.ts"
#     fi
#     npx playwright test --config="$CONFIG"
#
# so a config under `playwright-test-path` WINS. The gate read the root one
# unconditionally, and scored a suite CI never runs.
#
# The fixture below is openregister's real layout: a root config whose
# `testDir` spans all of `tests/e2e`, and a `tests/e2e/ci/playwright.config.ts`
# whose `testDir: '.'` means `tests/e2e/ci` — its OWN directory, not the repo
# root. Measured on that repo: 63 spec files under `tests/e2e`, 4 under
# `tests/e2e/ci`, 205 `@e2e` anchors, and ZERO of them in the executed path.
_ROOT_CONFIG = """
import { defineConfig } from '@playwright/test'
export default defineConfig({
\ttestDir: './tests/e2e',
\tprojects: [{ name: 'chromium' }],
})
"""

# `testDir: '.'` is relative to THIS FILE's directory.
_CI_CONFIG = """
import { defineConfig } from '@playwright/test'
export default defineConfig({
\ttestDir: '.',
\tprojects: [{ name: 'chromium' }],
})
"""

_CALLER_WF = """
name: Code Quality
on: [push]
jobs:
  quality:
    uses: ConductionNL/.github/.github/workflows/quality.yml@main
    with:
      app-name: demo
      enable-playwright: true
      playwright-test-path: tests/e2e/ci
"""


class PlaywrightConfigResolutionTest(unittest.TestCase):
    """The gate must score the config CI runs, not the one at the root."""

    def setUp(self):
        self.root = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.root, True)
        self._env = os.environ.pop("PLAYWRIGHT_TEST_PATH", None)
        self.addCleanup(self._restore_env)
        _write(self.root, "openspec/specs/saved-search-views/spec.md", _SPEC_MD)
        self.ref = "saved-search-views::presentation-of-a-saved-view"
        self.tag = ("// @e2e openspec/specs/saved-search-views/spec.md"
                    "#presentation-of-a-saved-view\n"
                    "test('renders', async ({ page }) => {\n"
                    "  await page.goto('/apps/openregister/views')\n"
                    "})\n")

    def _restore_env(self):
        os.environ.pop("PLAYWRIGHT_TEST_PATH", None)
        if self._env is not None:
            os.environ["PLAYWRIGHT_TEST_PATH"] = self._env

    def _two_configs(self):
        _write(self.root, "playwright.config.ts", _ROOT_CONFIG)
        _write(self.root, "tests/e2e/ci/playwright.config.ts", _CI_CONFIG)
        _write(self.root, ".github/workflows/code-quality.yml", _CALLER_WF)

    # -- the defect ---------------------------------------------------------
    def test_an_anchor_OUTSIDE_the_executed_config_does_NOT_cover(self):
        # openregister's shape: the anchor sits in tests/e2e/, which the ROOT
        # config runs and the CI config does not. 205 real anchors are here.
        self._two_configs()
        _write(self.root, "tests/e2e/search-views.spec.ts", self.tag)
        live, dead = cec.collect_ref_status(self.root)
        self.assertNotIn(self.ref, live)
        self.assertIn(self.ref, dead)
        self.assertIn("tests/e2e/ci/playwright.config.ts", dead[self.ref])

    # -- the reverse --------------------------------------------------------
    def test_the_same_anchor_INSIDE_the_executed_config_DOES_cover(self):
        self._two_configs()
        _write(self.root, "tests/e2e/ci/search-views.spec.ts", self.tag)
        live, _dead = cec.collect_ref_status(self.root)
        self.assertIn(self.ref, live)

    # -- one config or two, per the coordinator's don't-double-count note ----
    def test_a_repo_that_COLLAPSED_to_one_root_config_still_covers(self):
        # launchpad#85 / openregister#2410 remove the second config. After
        # that, tests/e2e IS the executed path and the anchor must count.
        _write(self.root, "playwright.config.ts", _ROOT_CONFIG)
        _write(self.root, ".github/workflows/code-quality.yml", _CALLER_WF)
        _write(self.root, "tests/e2e/search-views.spec.ts", self.tag)
        live, _dead = cec.collect_ref_status(self.root)
        self.assertIn(self.ref, live)

    def test_no_workflow_at_all_falls_back_to_the_root_config(self):
        _write(self.root, "playwright.config.ts", _ROOT_CONFIG)
        _write(self.root, "tests/e2e/search-views.spec.ts", self.tag)
        live, _dead = cec.collect_ref_status(self.root)
        self.assertIn(self.ref, live)

    # -- resolution details -------------------------------------------------
    def test_testDir_dot_means_the_configs_OWN_directory_not_the_repo_root(self):
        self._two_configs()
        scope = cec._PlaywrightScope(self.root)
        self.assertEqual(scope.config_rel, "tests/e2e/ci/playwright.config.ts")
        self.assertEqual(scope.test_dir, "tests/e2e/ci")

    def test_the_declared_path_is_read_from_the_caller_workflow(self):
        self._two_configs()
        self.assertEqual(cec._declared_test_path(self.root), "tests/e2e/ci")

    def test_a_QUOTED_declared_path_is_read(self):
        # hermiq writes it as `playwright-test-path: "tests/e2e/spec-coverage"`.
        _write(self.root, ".github/workflows/code-quality.yml",
               'jobs:\n  q:\n    with:\n'
               '      playwright-test-path: "tests/e2e/spec-coverage"\n')
        self.assertEqual(cec._declared_test_path(self.root),
                         "tests/e2e/spec-coverage")

    def test_a_COMMENTED_OUT_declaration_is_not_configuration(self):
        # Several fleet workflows explain this input at length right above it.
        _write(self.root, ".github/workflows/code-quality.yml",
               "jobs:\n  q:\n    with:\n"
               "      # playwright-test-path: tests/e2e/ci  <- historical\n"
               "      app-name: demo\n")
        self.assertEqual(cec._declared_test_path(self.root), "tests/e2e")

    def test_the_env_var_wins_over_the_workflow(self):
        self._two_configs()
        os.environ["PLAYWRIGHT_TEST_PATH"] = "tests/e2e"
        self.assertEqual(cec._declared_test_path(self.root), "tests/e2e")

    def test_the_default_is_the_shared_workflows_own_default(self):
        self.assertEqual(cec._declared_test_path(self.root), "tests/e2e")

    def test_a_config_at_the_DEFAULT_path_beats_the_root_one(self):
        # opencatalogi / openconnector / doriath / softwarecatalog / larpingapp
        # all carry tests/e2e/playwright.config.ts alongside a root config and
        # declare no explicit path.
        _write(self.root, "playwright.config.ts", _ROOT_CONFIG)
        _write(self.root, "tests/e2e/playwright.config.ts",
               "export default { testDir: '.', projects: [{ name: 'chromium' }] }\n")
        scope = cec._PlaywrightScope(self.root)
        self.assertEqual(scope.config_rel, "tests/e2e/playwright.config.ts")
        self.assertEqual(scope.test_dir, "tests/e2e")


if __name__ == "__main__":
    unittest.main()
