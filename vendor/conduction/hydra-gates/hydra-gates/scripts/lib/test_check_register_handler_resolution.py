#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_register_handler_resolution (gate-56). Run with:

    python3 scripts/lib/test_check_register_handler_resolution.py

or via pytest:

    python3 -m pytest scripts/lib/test_check_register_handler_resolution.py
"""
from __future__ import annotations

import io
import json
import os
import re
import sys
import tempfile
import unittest
from contextlib import redirect_stdout

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_register_handler_resolution as rhr  # noqa: E402


class _AppFixture:
    """Builds a throwaway app root (lib/... tree) and chdirs into it for the
    duration of the `with` block, restoring cwd on exit."""

    def __init__(self):
        self._tmp = tempfile.TemporaryDirectory()
        self._old_cwd = None

    def __enter__(self):
        self._old_cwd = os.getcwd()
        os.chdir(self._tmp.name)
        return self._tmp.name

    def __exit__(self, *exc):
        os.chdir(self._old_cwd)
        self._tmp.cleanup()

    def write(self, rel_path: str, content: str):
        full = os.path.join(self._tmp.name, rel_path)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "w", encoding="utf-8") as fh:
            fh.write(content)
        return full


def _run_main(register_path: str) -> list[str]:
    buf = io.StringIO()
    with redirect_stdout(buf):
        rhr.main(["check_register_handler_resolution.py", register_path])
    return [ln for ln in buf.getvalue().splitlines() if ln.strip()]


class MissingClassTest(unittest.TestCase):
    def test_missing_class_flagged(self):
        with _AppFixture() as root:
            reg = os.path.join(root, "register.json")
            with open(reg, "w", encoding="utf-8") as fh:
                json.dump(
                    {
                        "transitions": {
                            "issue": {
                                "requires": "OCA\\Fixture\\Guard\\VatSubmissionGuard::requireApproval"
                            }
                        }
                    },
                    fh,
                )
            out = _run_main(reg)
            self.assertEqual(len(out), 1, out)
            self.assertIn("rule=guard-class-not-found", out[0])
            self.assertIn("VatSubmissionGuard::requireApproval", out[0])


class MissingMethodTest(unittest.TestCase):
    def test_existing_class_missing_method_flagged(self):
        with _AppFixture() as root:
            os.makedirs(os.path.join(root, "lib", "Lifecycle"), exist_ok=True)
            with open(
                os.path.join(root, "lib", "Lifecycle", "PeriodCloseGuard.php"),
                "w",
                encoding="utf-8",
            ) as fh:
                fh.write(
                    "<?php\nnamespace OCA\\Fixture\\Lifecycle;\nclass PeriodCloseGuard {\n"
                    "    public function periodOpen(): bool { return true; }\n}\n"
                )
            reg = os.path.join(root, "register.json")
            with open(reg, "w", encoding="utf-8") as fh:
                json.dump(
                    {
                        "transitions": {
                            "close": {
                                "preconditions": [
                                    "OCA\\Fixture\\Lifecycle\\PeriodCloseGuard::trialBalanceVerifies"
                                ]
                            }
                        }
                    },
                    fh,
                )
            out = _run_main(reg)
            self.assertEqual(len(out), 1, out)
            self.assertIn("rule=guard-method-not-found", out[0])
            self.assertIn("trialBalanceVerifies", out[0])


class ResolvedTest(unittest.TestCase):
    def test_class_and_method_both_exist_not_flagged(self):
        with _AppFixture() as root:
            os.makedirs(os.path.join(root, "lib", "Guard"), exist_ok=True)
            with open(
                os.path.join(root, "lib", "Guard", "RealGuard.php"), "w", encoding="utf-8"
            ) as fh:
                fh.write(
                    "<?php\nnamespace OCA\\Fixture\\Guard;\nclass RealGuard {\n"
                    "    public function check(array $data): bool { return true; }\n}\n"
                )
            os.makedirs(os.path.join(root, "lib", "Service"), exist_ok=True)
            with open(
                os.path.join(root, "lib", "Service", "ReportGenerator.php"), "w", encoding="utf-8"
            ) as fh:
                fh.write(
                    "<?php\nnamespace OCA\\Fixture\\Service;\nclass ReportGenerator {\n"
                    "    public function submit(): void {}\n}\n"
                )
            reg = os.path.join(root, "register.json")
            with open(reg, "w", encoding="utf-8") as fh:
                json.dump(
                    {
                        "transitions": {
                            "issue": {"requires": "OCA\\Fixture\\Guard\\RealGuard::check"}
                        },
                        "notifications": {
                            "handler": "OCA\\Fixture\\Service\\ReportGenerator::submit"
                        },
                    },
                    fh,
                )
            out = _run_main(reg)
            self.assertEqual(out, [])

    def test_bare_class_no_method_resolves(self):
        with _AppFixture() as root:
            os.makedirs(os.path.join(root, "lib", "Guard"), exist_ok=True)
            with open(
                os.path.join(root, "lib", "Guard", "RealGuard.php"), "w", encoding="utf-8"
            ) as fh:
                fh.write("<?php\nnamespace OCA\\Fixture\\Guard;\nclass RealGuard {}\n")
            reg = os.path.join(root, "register.json")
            with open(reg, "w", encoding="utf-8") as fh:
                json.dump({"class": "OCA\\Fixture\\Guard\\RealGuard"}, fh)
            out = _run_main(reg)
            self.assertEqual(out, [])


class DeclarationFormTest(unittest.TestCase):
    """THE FALLBACK WALK ONLY RECOGNISED `class` (.github#276).

    The PSR-4 path guess handles the conventional layout. This fallback walk
    over lib/ is what finds a type living where PSR-4 does NOT predict — a
    DI-bound registration, a type not named after its file. That is the shape
    gate-30 was caught mis-resolving: `AppHost\\Controller\\GenericHealth`
    PSR-4-maps to `lib/Controller/AppHost/Controller/…` while openregister
    DI-binds it to `lib/AppHost/Controller/`.

    So every declaration form the walk cannot match is a FALSE POSITIVE on a
    type that genuinely exists — and the action `guard-class-not-found`
    invites is to write the class a second time. Measured, each against a
    real declaration at a non-conventional path (file `bundle.php`, class
    names unrelated to it):

        enum ProbeState: string { … }        -> guard-class-not-found
        interface ProbeContract { … }        -> guard-class-not-found
        trait ProbeTrait { … }               -> guard-class-not-found
        final readonly class ReadonlyProbe   -> guard-class-not-found

    `final readonly` is ordinary PHP 8.2; the pattern allowed `final ` OR
    `abstract `, never two modifiers.
    """

    BUNDLE = (
        "<?php\nnamespace OCA\\Fixture\\Health;\n"
        "enum ProbeState: string { case Ok = 'ok';"
        " public function check(): bool { return true; } }\n"
        "interface ProbeContract { public function check(): bool; }\n"
        "trait ProbeTrait { public function check(): bool { return true; } }\n"
        "final readonly class ReadonlyProbe"
        " { public function check(): bool { return true; } }\n"
    )

    def _probe(self, fqcn: str) -> list[str]:
        with _AppFixture() as root:
            os.makedirs(os.path.join(root, "lib", "Health"), exist_ok=True)
            # NOTE the filename: PSR-4 would look for <ClassName>.php, so
            # every resolution below can only come from the fallback walk.
            with open(os.path.join(root, "lib", "Health", "bundle.php"),
                      "w", encoding="utf-8") as fh:
                fh.write(self.BUNDLE)
            os.makedirs(os.path.join(root, "lib", "Docs"), exist_ok=True)
            with open(os.path.join(root, "lib", "Docs", "notes.php"),
                      "w", encoding="utf-8") as fh:
                fh.write(
                    "<?php\nnamespace OCA\\Fixture\\Docs;\n"
                    "/**\n * See the MentionedGuard class for details;\n"
                    " * interface MentionedGuard is planned.\n */\n"
                    "class Notes {}\n"
                )
            reg = os.path.join(root, "register.json")
            with open(reg, "w", encoding="utf-8") as fh:
                json.dump({"transitions": {"s": {"guard": fqcn}}}, fh)
            return _run_main(reg)

    def test_enum_at_an_unconventional_path_resolves(self):
        self.assertEqual(self._probe("OCA\\Fixture\\Health\\ProbeState::check"), [])

    def test_interface_at_an_unconventional_path_resolves(self):
        self.assertEqual(self._probe("OCA\\Fixture\\Health\\ProbeContract::check"), [])

    def test_trait_at_an_unconventional_path_resolves(self):
        self.assertEqual(self._probe("OCA\\Fixture\\Health\\ProbeTrait::check"), [])

    def test_final_readonly_class_resolves(self):
        self.assertEqual(self._probe("OCA\\Fixture\\Health\\ReadonlyProbe::check"), [])

    # --- ANTI-WIDENING. The gate must still be able to fail. --------------
    def test_a_genuinely_absent_class_is_still_not_found(self):
        out = self._probe("OCA\\Fixture\\Health\\NotARealGuard::may")
        self.assertEqual(len(out), 1, out)
        self.assertIn("guard-class-not-found", out[0])

    def test_a_docblock_mention_is_still_not_a_declaration(self):
        """The line anchor is why this walk is trustworthy at all: a
        merely-MENTIONED class counted as found would mask the exact fleet
        defect the gate exists for (17 nonexistent guards on shillinq). The
        fixture's docblock says both "MentionedGuard class" and "interface
        MentionedGuard", so it exercises the widened alternation too."""
        out = self._probe("OCA\\Fixture\\Docs\\MentionedGuard::may")
        self.assertEqual(len(out), 1, out)
        self.assertIn("guard-class-not-found", out[0])

    def test_a_missing_method_on_a_real_enum_is_still_reported(self):
        out = self._probe("OCA\\Fixture\\Health\\ProbeState::nosuchmethod")
        self.assertEqual(len(out), 1, out)
        self.assertIn("guard-method-not-found", out[0])

    def test_the_mutant_the_widened_forms_are_load_bearing(self):
        """Restore the pre-fix pattern; all four must go back to not-found.
        Without this, the four assertions above could be green because the
        types were resolving by some other route."""
        original = rhr._class_def_re
        try:
            def pre_fix(short_name):
                return re.compile(
                    r"^\s*(?:final\s+|abstract\s+)?class\s+"
                    + re.escape(short_name) + r"\b", re.MULTILINE)
            rhr._class_def_re = pre_fix
            for fqcn in ("ProbeState", "ProbeContract", "ProbeTrait", "ReadonlyProbe"):
                out = self._probe(f"OCA\\Fixture\\Health\\{fqcn}::check")
                self.assertEqual(
                    len(out), 1,
                    f"the pre-fix pattern must reproduce the false positive on "
                    f"{fqcn} — if it does not, this suite is measuring nothing",
                )
        finally:
            rhr._class_def_re = original


class ExcludeAnnotationTest(unittest.TestCase):
    def test_sibling_exclude_key_suppresses_finding(self):
        with _AppFixture() as root:
            reg = os.path.join(root, "register.json")
            with open(reg, "w", encoding="utf-8") as fh:
                json.dump(
                    {
                        "transitions": {
                            "issue": {
                                "requires": "OCA\\Fixture\\Guard\\FutureGuard::check",
                                "requiresExclude": "Ships in follow-up PR, tracked in issue #123",
                            }
                        }
                    },
                    fh,
                )
            out = _run_main(reg)
            self.assertEqual(out, [])

    def test_short_exclude_reason_does_not_suppress(self):
        with _AppFixture() as root:
            reg = os.path.join(root, "register.json")
            with open(reg, "w", encoding="utf-8") as fh:
                json.dump(
                    {
                        "transitions": {
                            "issue": {
                                "requires": "OCA\\Fixture\\Guard\\FutureGuard::check",
                                "requiresExclude": "todo",
                            }
                        }
                    },
                    fh,
                )
            out = _run_main(reg)
            self.assertEqual(len(out), 1, out)


class NonHandlerStringsIgnoredTest(unittest.TestCase):
    def test_role_strings_and_declarative_guard_objects_not_flagged(self):
        with _AppFixture() as root:
            reg = os.path.join(root, "register.json")
            with open(reg, "w", encoding="utf-8") as fh:
                json.dump(
                    {
                        "transitions": {
                            "activate": {
                                "roles": ["period-closer"],
                                "guard": {
                                    "type": "precondition",
                                    "conditions": [{"expression": "x != null"}],
                                },
                            }
                        }
                    },
                    fh,
                )
            out = _run_main(reg)
            self.assertEqual(out, [])


class ForeignCwdTest(unittest.TestCase):
    """Regression fixtures for the 2026-07-16 cwd false-positive.

    `main()` used to derive the app root from `os.getcwd()`, so invoking the
    gate from anywhere other than the app root (a fleet sweep from
    `apps-extra/`, a reviewer's ad-hoc run) made EVERY guard in the register
    unresolvable -> 7 false positives on pipelinq's live POS money guards.
    Every test above chdirs INTO the app root via `_AppFixture`, which is
    exactly why the bug survived: the buggy assumption was baked into the
    fixtures. These two run from a foreign cwd instead, and assert BOTH
    directions -- the true positive must still fail, the live guard must pass.
    """

    def _build_app(self, tmp: str, with_guard_class: bool) -> str:
        """Write an app root (appinfo + register) under *tmp*; return the
        register path. The guard class is written only when requested."""
        app = os.path.join(tmp, "pipelinq")
        os.makedirs(os.path.join(app, "appinfo"), exist_ok=True)
        with open(os.path.join(app, "appinfo", "info.xml"), "w", encoding="utf-8") as fh:
            fh.write("<?xml version='1.0'?>\n<info><id>pipelinq</id></info>\n")
        if with_guard_class:
            os.makedirs(os.path.join(app, "lib", "Lifecycle"), exist_ok=True)
            with open(
                os.path.join(app, "lib", "Lifecycle", "PosTransactionConfirmGuard.php"),
                "w",
                encoding="utf-8",
            ) as fh:
                fh.write(
                    "<?php\nnamespace OCA\\Pipelinq\\Lifecycle;\n"
                    "class PosTransactionConfirmGuard {\n"
                    "    public function allows(): bool { return true; }\n}\n"
                )
        reg_dir = os.path.join(app, "lib", "Settings")
        os.makedirs(reg_dir, exist_ok=True)
        reg = os.path.join(reg_dir, "pipelinq_register.json")
        with open(reg, "w", encoding="utf-8") as fh:
            json.dump(
                {
                    "transitions": {
                        "confirm": {
                            "requires": "OCA\\Pipelinq\\Lifecycle\\PosTransactionConfirmGuard::allows"
                        }
                    }
                },
                fh,
            )
        return reg

    def test_live_guard_passes_from_foreign_cwd(self):
        """NOW-PASSES fixture: a real, resolvable guard must NOT be flagged
        when the gate runs from outside the app root. This failed before the
        fix (rule=guard-class-not-found on a class that plainly exists)."""
        with tempfile.TemporaryDirectory() as tmp:
            reg = self._build_app(tmp, with_guard_class=True)
            old = os.getcwd()
            os.chdir(tempfile.gettempdir())  # deliberately NOT the app root
            try:
                self.assertEqual(_run_main(reg), [])
            finally:
                os.chdir(old)

    def test_missing_guard_still_flagged_from_foreign_cwd(self):
        """STILL-FAILS fixture: the shillinq defect class (guard named in the
        register, class absent from the repo) must survive the fix. This is
        the true positive the gate exists for -- it must not be traded away
        for silence."""
        with tempfile.TemporaryDirectory() as tmp:
            reg = self._build_app(tmp, with_guard_class=False)
            old = os.getcwd()
            os.chdir(tempfile.gettempdir())
            try:
                out = _run_main(reg)
                self.assertEqual(len(out), 1, out)
                self.assertIn("rule=guard-class-not-found", out[0])
                self.assertIn("PosTransactionConfirmGuard::allows", out[0])
            finally:
                os.chdir(old)

    def test_app_root_is_derived_from_file_not_cwd(self):
        """The root cause, asserted directly: app_root_for() must key off the
        register file's own path, never the process cwd."""
        with tempfile.TemporaryDirectory() as tmp:
            reg = self._build_app(tmp, with_guard_class=True)
            old = os.getcwd()
            os.chdir(tempfile.gettempdir())
            try:
                self.assertEqual(
                    os.path.realpath(rhr.app_root_for(reg)),
                    os.path.realpath(os.path.join(tmp, "pipelinq")),
                )
            finally:
                os.chdir(old)


if __name__ == "__main__":
    unittest.main()
