#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_license_triangle (gate-28). Run with:

    python3 scripts/lib/test_check_license_triangle.py

BOTH WAYS, EVERY TIME
---------------------
gate-28 passed in all 28 app repos, which is exactly why its two defects
mattered: 174 files carried an AGPL claim behind that green. Each fix below
ships with the case it must not swallow — an actually-drifted header still
fails, and a file whose ONLY non-EUPL identifiers are test data still
passes.

The NUL fixture is a real 0x00 byte, not the two characters `\\0`. Writing
the escape instead of the byte is how this defect stayed invisible: the
escape parses fine and proves nothing.
"""
from __future__ import annotations

import os
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_license_triangle as clt  # noqa: E402

EUPL = "EUPL-1.2"

CLEAN = """<?php
/**
 * Inventory valuation report service.
 *
 * @copyright Copyright (c) 2026 Conduction B.V.
 * @license   EUPL-1.2
 */
namespace OCA\\Shillinq\\Service;
"""

BOTH_LICENCES = """<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
/**
 * @copyright Copyright (c) 2026 Conduction B.V.
 * @license   EUPL-1.2
 */
namespace OCA\\Launchpad\\Service;
"""

DRIFTED = """<?php
/**
 * @license MIT
 */
namespace OCA\\Thing\\Service;
"""

# nldesign/tests/.../MarianneFontTest.php shape: identifiers as ASSERTION
# DATA, in string literals. Changing them breaks the tests.
TEST_DATA = """<?php
/**
 * @license EUPL-1.2
 */
class MarianneFontTest extends TestCase
{
    public function testRejectsProprietaryFont(): void
    {
        $this->assertSame('SPDX-License-Identifier: LicenseRef-Marianne', $meta);
        $this->assertStringContainsString('@license AGPL-3.0-or-later', $body);
    }
}
"""


def _scan(body: str, composer: str = EUPL, name: str = "Service.php") -> list[str]:
    with tempfile.TemporaryDirectory() as d:
        p = Path(d) / name
        p.write_text(body, encoding="utf-8")
        return clt.scan_files([str(p)], composer)


def _scan_bytes(body: bytes, composer: str = EUPL) -> list[str]:
    with tempfile.TemporaryDirectory() as d:
        p = Path(d) / "Service.php"
        p.write_bytes(body)
        return clt.scan_files([str(p)], composer)


def _rules(findings: list[str]) -> list[str]:
    return [f.rsplit("rule=", 1)[1].split()[0] for f in findings]


# --------------------------------------------------------------------------
# Defect 1 — only the FIRST @license was read; SPDX was invisible.
# --------------------------------------------------------------------------
class SecondLicenceInTheSameFile(unittest.TestCase):
    def test_tp_a_file_declaring_eupl_and_agpl_is_now_reported(self):
        rules = _rules(_scan(BOTH_LICENCES))
        self.assertIn("license-internal-conflict", rules)
        self.assertIn("license-triangle-drift", rules)

    def test_tp_the_agpl_value_is_named_in_the_finding(self):
        self.assertTrue(any("AGPL-3.0-or-later" in f for f in _scan(BOTH_LICENCES)))

    def test_the_old_implementation_would_have_passed_this_file(self):
        # The reason this class exists: the FIRST declaration in reading
        # order is the SPDX line, but the old grep only matched the PHPDoc
        # `* @license` shape and took its head -1 — so it saw EUPL-1.2 and
        # stopped. Asserting the ordering makes the regression visible if
        # anyone reintroduces a first-match-wins read.
        self.assertEqual(clt.declarations(BOTH_LICENCES),
                         ["AGPL-3.0-or-later", "EUPL-1.2"])

    def test_fp_a_clean_file_still_passes(self):
        self.assertEqual(_scan(CLEAN), [])

    def test_fp_matching_spdx_and_license_tags_agree(self):
        body = ("<?php\n// SPDX-License-Identifier: EUPL-1.2\n"
                "/**\n * @license EUPL-1.2\n */\n")
        self.assertEqual(_scan(body), [])

    def test_tp_a_drifted_single_declaration_still_fails(self):
        self.assertEqual(_rules(_scan(DRIFTED)), ["license-triangle-drift"])

    def test_a_dual_licensed_package_accepts_either(self):
        self.assertEqual(_scan(DRIFTED, composer="EUPL-1.2|MIT"), [])


# --------------------------------------------------------------------------
# Defect 2 — a raw NUL byte made grep go binary and the gate read the FILE
# PATH as the licence value: a false RED on a correct file.
# --------------------------------------------------------------------------
class NulByteInSource(unittest.TestCase):
    def test_fp_a_correct_header_with_an_embedded_nul_passes(self):
        # shillinq/lib/Service/InventoryValuationReportService.php:515.
        body = CLEAN.encode() + b"\n// composite key: sku\x00warehouse\n"
        self.assertEqual(_scan_bytes(body), [])

    def test_tp_a_drifted_header_with_an_embedded_nul_still_fails(self):
        # The pairing that matters: NUL-tolerance must not become
        # NUL-blindness. A file that is BOTH binary-ish AND wrong is still
        # wrong.
        body = DRIFTED.encode() + b"\n// composite key: sku\x00warehouse\n"
        self.assertEqual(_rules(_scan_bytes(body)), ["license-triangle-drift"])

    def test_a_nul_immediately_before_the_tag_does_not_hide_it(self):
        body = b"<?php\n/**\n * key: a\x00b\n * @license MIT\n */\n"
        self.assertEqual(_rules(_scan_bytes(body)), ["license-triangle-drift"])

    def test_a_path_shaped_licence_value_is_reported_as_a_parser_fault(self):
        body = "<?php\n/**\n * @license lib/Service/Thing.php\n */\n"
        rules = _rules(_scan(body))
        self.assertEqual(rules, ["license-value-is-a-path"])
        # ...and NOT as a licence drift, which would send someone to edit a
        # header that is already correct.
        self.assertNotIn("license-triangle-drift", rules)

    def test_a_comment_terminator_is_trimmed_off_the_value(self):
        # Both HTML5 terminators, and the C-style one. A pattern that knows
        # only `-->` leaves `EUPL-1.2--!` as the licence and reports drift on
        # a correct header.
        for body, expected in (
            ("<?php\n/** @license EUPL-1.2*/\n", ["EUPL-1.2"]),
            ("<!--SPDX-License-Identifier: EUPL-1.2-->\n", ["EUPL-1.2"]),
            ("<!--SPDX-License-Identifier: EUPL-1.2--!>\n", ["EUPL-1.2"]),
        ):
            with self.subTest(body=body):
                self.assertEqual(clt.declarations(body), expected)
                self.assertEqual(_scan(body), [])

    def test_a_wrong_licence_is_still_wrong_after_trimming(self):
        self.assertEqual(_rules(_scan("<!--SPDX-License-Identifier: MIT--!>\n")),
                         ["license-triangle-drift"])

    def test_looks_like_a_path_directly(self):
        self.assertTrue(clt._looks_like_a_path("lib/Service/Thing.php"))
        self.assertTrue(clt._looks_like_a_path("./Thing.php"))
        self.assertFalse(clt._looks_like_a_path("EUPL-1.2"))
        self.assertFalse(clt._looks_like_a_path("AGPL-3.0-or-later"))


# --------------------------------------------------------------------------
# The documented exception: identifiers as test DATA.
# --------------------------------------------------------------------------
class LicenceIdentifiersAsTestData(unittest.TestCase):
    def test_fp_assertion_strings_are_not_declarations(self):
        self.assertEqual(_scan(TEST_DATA, name="MarianneFontTest.php"), [])

    def test_declarations_ignores_quoted_identifiers(self):
        self.assertEqual(clt.declarations(TEST_DATA), ["EUPL-1.2"])

    def test_tp_a_real_header_in_a_test_file_is_still_judged(self):
        # The exception is about STRING LITERALS, not about the tests/
        # directory. A test file whose own header drifted still fails.
        body = TEST_DATA.replace("@license EUPL-1.2", "@license AGPL-3.0-or-later")
        self.assertEqual(_rules(_scan(body, name="MarianneFontTest.php")),
                         ["license-triangle-drift"])


class GateIsNotBlind(unittest.TestCase):
    """A direct floor. If `declarations()` ever returns nothing — a regex
    that stops matching, a read that silently fails — every `assertEqual([])`
    above passes and only this fails."""

    def test_every_declaration_shape_is_seen(self):
        for body, expected in (
            ("<?php\n/**\n * @license EUPL-1.2\n */\n", ["EUPL-1.2"]),
            ("<?php\n// SPDX-License-Identifier: EUPL-1.2\n", ["EUPL-1.2"]),
            ("<?php\n# SPDX-License-Identifier: EUPL-1.2\n", ["EUPL-1.2"]),
            ("/* SPDX-License-Identifier: EUPL-1.2 */\n", ["EUPL-1.2"]),
            ("<!-- SPDX-License-Identifier: EUPL-1.2 -->\n", ["EUPL-1.2"]),
            ("<?php\n/** @license   MIT */\n", ["MIT"]),
        ):
            with self.subTest(body=body):
                self.assertEqual(clt.declarations(body), expected)

    def test_a_wrong_licence_is_reported_in_every_declaration_shape(self):
        for body in (
            "<?php\n/**\n * @license AGPL-3.0-or-later\n */\n",
            "<?php\n// SPDX-License-Identifier: AGPL-3.0-or-later\n",
        ):
            with self.subTest(body=body):
                self.assertEqual(_rules(_scan(body)), ["license-triangle-drift"])


# ==========================================================================
# APPENDED for ConductionNL/.github#178 — the helper's HALF of the
# applicability decision.
#
# #178: gate-28 failed every PHP-free PR in a PHP repo, because scope came
# from the DIFF while "subject matter exists" was judged from the REPO. #182
# fixed the runner. Nothing here tested the helper side of that contract, and
# the contract is narrow enough to break silently:
#
#   * The runner decides PASS vs `structural` on ONE number — the
#     `declared_files=N` line this module writes. `declared_file_count()` had
#     no test at all, in either direction.
#   * It reaches the runner over a STREAM SPLIT: findings on stdout, the count
#     on stderr, separated by `2>&1 >>log |`. A helper that printed the count
#     to stdout instead would leave every assertion above green, put the count
#     line into the findings log, and make `wc -l` report a finding that does
#     not exist — a gate that FAILS a clean repo. The `main()` tests below pin
#     which stream each half arrives on.
#
# The empty-list cases are the ones #178 turns on: with zero files in scope
# the helper must report zero compared files and — the half that is easy to
# get wrong — ZERO FINDINGS. A helper that manufactured a finding from an
# empty scope would move the false red from the coverage block into the
# failure count, which is worse, not better.
# ==========================================================================
import contextlib  # noqa: E402
import io  # noqa: E402


class DeclaredFileCount(unittest.TestCase):
    """`declared_file_count()` is the runner's entire PASS-vs-structural
    evidence. Both directions, because a function that always returns 0 and a
    function that always returns len(files) each satisfy half of this."""

    @staticmethod
    def _write(d: str, name: str, body: str) -> str:
        p = Path(d) / name
        p.write_text(body, encoding="utf-8")
        return str(p)

    def test_empty_scope_counts_zero(self):
        # #178's case: nothing was in scope, so nothing was compared.
        self.assertEqual(clt.declared_file_count([]), 0)

    def test_empty_scope_produces_no_findings(self):
        # ...and must not invent one. An empty scope is not a defect.
        self.assertEqual(clt.scan_files([], EUPL), [])

    def test_a_tagged_file_counts_one(self):
        with tempfile.TemporaryDirectory() as d:
            self.assertEqual(
                clt.declared_file_count([self._write(d, "A.php", CLEAN)]), 1)

    def test_an_untagged_file_counts_zero(self):
        # The `structural` signal: files WERE in scope and declared nothing.
        # This is the state that must keep failing, and the one an
        # over-broad #178 fix would have swallowed.
        with tempfile.TemporaryDirectory() as d:
            body = "<?php\nnamespace OCA\\Fixture;\nclass Untagged {}\n"
            self.assertEqual(
                clt.declared_file_count([self._write(d, "A.php", body)]), 0)

    def test_mixed_scope_counts_only_the_declaring_files(self):
        with tempfile.TemporaryDirectory() as d:
            files = [
                self._write(d, "Tagged.php", CLEAN),
                self._write(d, "Untagged.php", "<?php\nclass U {}\n"),
                self._write(d, "Drifted.php", DRIFTED),
            ]
            # Tagged and Drifted both DECLARE; only Drifted is wrong. The count
            # is "how many were compared", not "how many passed" — conflating
            # those would make a wholly-drifted diff report structural and skip
            # instead of fail.
            self.assertEqual(clt.declared_file_count(files), 2)
            self.assertEqual(_rules(clt.scan_files(files, EUPL)),
                             ["license-triangle-drift"])

    def test_an_unreadable_path_counts_zero_and_does_not_raise(self):
        # A path the runner listed but the helper cannot open must not be
        # counted as compared — that is the falsely-GREEN shape #172 opened on.
        missing = str(Path(tempfile.gettempdir()) / "gate28-does-not-exist.php")
        self.assertEqual(clt.declared_file_count([missing]), 0)
        self.assertEqual(clt.scan_files([missing], EUPL), [])


class MainStreamContract(unittest.TestCase):
    """The runner parses `declared_files=` off STDERR and treats every STDOUT
    line as a finding (`wc -l`). Pin both."""

    @staticmethod
    def _main(argv: list[str]) -> tuple[int, str, str]:
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            rc = clt.main(argv)
        return rc, out.getvalue(), err.getvalue()

    def test_empty_scope_prints_no_stdout_and_a_zero_count_on_stderr(self):
        rc, out, err = self._main(["check_license_triangle.py", EUPL])
        self.assertEqual(rc, 0)
        # Every stdout line is counted as a finding by the runner. One stray
        # line here fails a repo that has nothing wrong with it.
        self.assertEqual(out, "")
        self.assertIn("declared_files=0", err)

    def test_the_count_is_on_stderr_not_stdout(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / "A.php"
            p.write_text(CLEAN, encoding="utf-8")
            rc, out, err = self._main(
                ["check_license_triangle.py", EUPL, str(p)])
        self.assertEqual(rc, 0)
        self.assertEqual(out, "")                    # clean file: no findings
        self.assertIn("declared_files=1", err)
        self.assertNotIn("declared_files=", out)     # the stream split holds

    def test_a_finding_goes_to_stdout_while_the_count_stays_on_stderr(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / "A.php"
            p.write_text(DRIFTED, encoding="utf-8")
            rc, out, err = self._main(
                ["check_license_triangle.py", EUPL, str(p)])
        self.assertEqual(rc, 0)
        self.assertEqual(len(out.strip().splitlines()), 1)
        self.assertIn("license-triangle-drift", out)
        self.assertIn("declared_files=1", err)
        self.assertNotIn("license-triangle-drift", err)

    def test_the_return_code_is_a_status_not_a_finding_count(self):
        # #209: gate-19 returned its finding COUNT as an exit status, so 266
        # findings reported as 10 and 256 would have reported PASS. This helper
        # must never adopt that shape — the count travels as text on stderr.
        with tempfile.TemporaryDirectory() as d:
            files = []
            for i in range(3):
                p = Path(d) / f"D{i}.php"
                p.write_text(DRIFTED, encoding="utf-8")
                files.append(str(p))
            rc, out, err = self._main(
                ["check_license_triangle.py", EUPL, *files])
        self.assertEqual(len(out.strip().splitlines()), 3)   # 3 findings...
        self.assertEqual(rc, 0)                              # ...status still 0
        self.assertIn("declared_files=3", err)


if __name__ == "__main__":
    unittest.main(verbosity=2)
