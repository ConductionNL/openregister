#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
"""Tests for check_unclosable_gate (gate 59). Run with:

    python3 scripts/lib/test_check_unclosable_gate.py

WHY THIS FILE DID NOT EXIST UNTIL NOW, AND WHAT WRITING IT FOUND (.github#276)
-----------------------------------------------------------------------------
Gate 59 shipped with no helper suite at all. Measured against docudesk with a
planted true positive, it caught the textbook case — and then failed in BOTH
directions on the very next mutation, which is #184's signature:

  false GREEN     a COMMENTED-OUT setter counted as a write.

                      // TODO: $c->setValueString('app','configuration_version',$v);

                  and the finding vanished. That is the single most likely
                  comment to sit beside a key nobody writes, because it is
                  what someone types when they notice the gap and defer it.
                  The gate whose whole subject is "this guard never closes"
                  was itself closed by a comment promising to close a guard.

  false GREEN     `"key"` — the key in double quotes — was invisible on both
                  sides, so the whole gate silently stopped existing for any
                  app using that quote style.

  false POSITIVE  read `'key'`, write `"key"` — code that closes its gate
                  correctly was reported as never closing it, and the only
                  remedy available to the app was to change its quote style.

  false GREEN     `private const CONFIG_KEY = '…'` + `self::CONFIG_KEY` was
                  invisible — and a constant is the IDIOMATIC way to write a
                  key used in two places, which is exactly the shape a
                  CLOSABLE gate has. The best-written apps were the ones this
                  gate could say nothing about.

Every case below is an arm of that matrix, each with its control, plus the
mutant that proves the mask is load-bearing.
"""
from __future__ import annotations

import io
import os
import shutil
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_unclosable_gate as gate  # noqa: E402

KEY = "configuration_version"

READ = "$seen = $this->cfg->getValueString(%s, %s, '');"
WRITE = "$this->cfg->setValueString(%s, %s, '1.0.0');"


def _app(root: Path, body: str, *, head: str = "") -> None:
    path = root / "lib" / "Service" / "Initializer.php"
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        "<?php\nnamespace OCA\\Leaf\\Service;\n"
        "class Initializer {\n" + head + "\n"
        "    public function initialize(): void {\n"
        "        " + body + "\n"
        "        $this->importEverything();\n"
        "    }\n"
        "    private function importEverything(): void {}\n"
        "}\n",
        encoding="utf-8",
    )


def _run(root: Path) -> tuple[int, str]:
    buf = io.StringIO()
    with redirect_stdout(buf):
        argv, sys.argv = sys.argv, ["check_unclosable_gate.py", str(root)]
        try:
            rc = gate.main()
        finally:
            sys.argv = argv
    return rc, buf.getvalue()


class GateCase(unittest.TestCase):
    def setUp(self) -> None:
        self.dir = Path(tempfile.mkdtemp(prefix="unclosable-gate-"))

    def tearDown(self) -> None:
        shutil.rmtree(self.dir, ignore_errors=True)

    def assertFinding(self, msg: str = "") -> None:
        rc, out = _run(self.dir)
        self.assertEqual(rc, 1, msg or out)
        self.assertIn(KEY, out)

    def assertClean(self, msg: str = "") -> None:
        rc, out = _run(self.dir)
        self.assertEqual(rc, 0, msg or out)


class TruePositiveTest(GateCase):
    """The gate must be able to fail before anything else is worth asserting."""

    def test_read_with_no_write_anywhere(self):
        _app(self.dir, READ % ("'leaf'", f"'{KEY}'"))
        self.assertFinding()

    def test_a_real_write_closes_it(self):
        _app(
            self.dir,
            READ % ("'leaf'", f"'{KEY}'") + "\n        " + WRITE % ("'leaf'", f"'{KEY}'"),
        )
        self.assertClean()

    def test_an_ordinary_setting_is_not_a_gate(self):
        """A read-only config key is normal — it is a setting with a default.
        Only keys SHAPED like a done-marker are gates (GATE_SHAPE)."""
        _app(self.dir, READ % ("'leaf'", "'default_page_size'"))
        self.assertClean()

    def test_nextcloud_managed_keys_are_not_gates(self):
        _app(self.dir, READ % ("'leaf'", "'installed_version'"))
        self.assertClean()


class CommentIsNotAWriteTest(GateCase):
    """A COMMENT IS NOT A WRITE — the false GREEN, and the dangerous one."""

    def test_commented_out_setter_does_not_close_the_gate(self):
        _app(
            self.dir,
            READ % ("'leaf'", f"'{KEY}'")
            + "\n        // TODO: " + WRITE % ("'leaf'", f"'{KEY}'"),
        )
        self.assertFinding("a `// TODO:` setter is a promise, not a write")

    def test_docblock_setter_does_not_close_the_gate(self):
        _app(
            self.dir,
            READ % ("'leaf'", f"'{KEY}'"),
            head="    /**\n     * Callers should " + WRITE % ("'leaf'", f"'{KEY}'")
                 + "\n     */",
        )
        self.assertFinding("prose describing a write is not a write")

    def test_hash_comment_setter_does_not_close_the_gate(self):
        _app(
            self.dir,
            READ % ("'leaf'", f"'{KEY}'")
            + "\n        # " + WRITE % ("'leaf'", f"'{KEY}'"),
        )
        self.assertFinding()

    def test_a_php8_attribute_is_not_a_comment(self):
        """`#[` opens an attribute. Blanking it as a `#` comment would eat the
        rest of the line — the distinction php_mask exists to keep."""
        _app(self.dir, READ % ("'leaf'", f"'{KEY}'"), head="    #[SomeAttribute]")
        self.assertFinding()

    def test_a_real_write_with_a_trailing_comment_still_counts(self):
        """The control for the mask: blanking comment REGIONS must not eat the
        code in front of them."""
        _app(
            self.dir,
            READ % ("'leaf'", f"'{KEY}'")
            + "\n        " + WRITE % ("'leaf'", f"'{KEY}'") + " // close the gate",
        )
        self.assertClean()

    def test_the_mutant_the_mask_is_load_bearing(self):
        """Remove the mask; the commented-out setter must silence the gate
        again. Without this the arms above could be green for another reason.
        """
        original = gate.php_mask
        self.assertTrue(callable(original))
        _app(
            self.dir,
            READ % ("'leaf'", f"'{KEY}'")
            + "\n        // TODO: " + WRITE % ("'leaf'", f"'{KEY}'"),
        )
        try:
            gate.php_mask = lambda text: text  # the pre-fix behaviour
            rc, _ = _run(self.dir)
            self.assertEqual(
                rc, 0,
                "without the comment mask the commented-out setter must close "
                "the finding — if it does not, this suite proves nothing",
            )
        finally:
            gate.php_mask = original
        self.assertFinding("and with the mask restored the finding returns")


class QuoteStyleTest(GateCase):
    """PHP treats 'k' and "k" identically. The gate must too — and it was
    wrong in BOTH directions at once, which is why both arms are here."""

    def test_double_quoted_read_with_no_write_is_still_a_finding(self):
        _app(self.dir, READ % ('"leaf"', f'"{KEY}"'))
        self.assertFinding("a double-quoted key is the same key")

    def test_single_quoted_read_closed_by_a_double_quoted_write(self):
        _app(
            self.dir,
            READ % ("'leaf'", f"'{KEY}'") + "\n        " + WRITE % ('"leaf"', f'"{KEY}"'),
        )
        self.assertClean("quote style is not a defect — this was a FALSE POSITIVE")

    def test_double_quoted_read_closed_by_a_single_quoted_write(self):
        _app(
            self.dir,
            READ % ('"leaf"', f'"{KEY}"') + "\n        " + WRITE % ("'leaf'", f"'{KEY}'"),
        )
        self.assertClean()


class ClassConstantKeyTest(GateCase):
    """A constant is how a key used twice is normally written."""

    CONST = f"    private const CONFIG_KEY = '{KEY}';"

    def test_constant_read_with_no_write_is_a_finding(self):
        _app(self.dir, READ % ("'leaf'", "self::CONFIG_KEY"), head=self.CONST)
        self.assertFinding()

    def test_constant_on_both_sides_closes_it(self):
        _app(
            self.dir,
            READ % ("'leaf'", "self::CONFIG_KEY")
            + "\n        " + WRITE % ("'leaf'", "self::CONFIG_KEY"),
            head=self.CONST,
        )
        self.assertClean("read and write must resolve to the SAME key")

    def test_constant_read_closed_by_a_literal_write(self):
        _app(
            self.dir,
            READ % ("'leaf'", "self::CONFIG_KEY")
            + "\n        " + WRITE % ("'leaf'", f"'{KEY}'"),
            head=self.CONST,
        )
        self.assertClean()

    def test_literal_read_closed_by_a_constant_write(self):
        _app(
            self.dir,
            READ % ("'leaf'", f"'{KEY}'")
            + "\n        " + WRITE % ("'leaf'", "self::CONFIG_KEY"),
            head=self.CONST,
        )
        self.assertClean()

    def test_an_unresolvable_constant_is_not_invented(self):
        """ANTI-WIDENING. A `self::` reference to a constant this app does not
        define must not be turned into a key — a resolver that guesses would
        manufacture findings nobody can act on."""
        _app(self.dir, READ % ("'leaf'", "self::SOME_OTHER_CONST"))
        self.assertClean()

    def test_a_constant_defined_in_a_comment_is_not_a_definition(self):
        _app(
            self.dir,
            READ % ("'leaf'", "self::CONFIG_KEY"),
            head=f"    // private const CONFIG_KEY = '{KEY}';",
        )
        self.assertClean("a commented-out const defines nothing")


class SuppressionTest(GateCase):
    """The suppression is authored AS a comment, so it is the one thing that
    must keep reading raw text — the mask would otherwise delete it."""

    def test_reason_bearing_suppression_is_honoured(self):
        _app(
            self.dir,
            f"// unclosable-gate exclude '{KEY}' is written by the OpenRegister "
            "importer, not by this app\n        " + READ % ("'leaf'", f"'{KEY}'"),
        )
        self.assertClean("the marker lives in a comment and must survive the mask")

    def test_suppression_naming_a_different_key_does_not_apply(self):
        _app(
            self.dir,
            "// unclosable-gate exclude 'some_other_version' is externally set\n"
            "        " + READ % ("'leaf'", f"'{KEY}'"),
        )
        self.assertFinding("a suppression is per-key, not per-file")


class NoSubjectTest(GateCase):
    def test_an_app_with_no_lib_is_clean(self):
        self.assertClean()

    def test_an_app_with_lib_but_no_config_access_is_clean(self):
        path = self.dir / "lib" / "Service" / "Plain.php"
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("<?php\nclass Plain { public function x(): void {} }\n")
        self.assertClean()


if __name__ == "__main__":
    unittest.main()
