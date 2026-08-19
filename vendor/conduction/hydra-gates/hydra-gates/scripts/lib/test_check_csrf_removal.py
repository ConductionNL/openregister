#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_csrf_removal (gate-48).

Run with:  python3 scripts/lib/test_check_csrf_removal.py

#191 asks for both arms explicitly, and says why: "Arm 2 is the one that
proves the fix did not simply disable the gate." A security gate that stopped
firing looks exactly like a security gate with nothing to report.
"""
from __future__ import annotations

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_csrf_removal as gate  # noqa: E402

# The nldesign line, verbatim from the issue.
NLDESIGN = "- * (#[PublicPage] + #[NoCSRFRequired]) and the response contract are owned by"


class TestMustStayGreen(unittest.TestCase):
    def test_the_nldesign_docblock_sentence(self):
        self.assertEqual(gate.removals(NLDESIGN), [])

    def test_a_whole_docblock_rewrite_around_it(self):
        diff = "\n".join([
            "--- a/lib/Controller/PageController.php",
            "+++ b/lib/Controller/PageController.php",
            "@@ -8,4 +8,4 @@",
            "- * The auth posture of this endpoint",
            NLDESIGN,
            "- * the AppHost generic in openregister.",
            "+ * Auth posture and response contract are owned upstream.",
        ])
        self.assertEqual(gate.removals(diff), [])

    def test_a_prose_line_naming_the_tag_mid_sentence(self):
        self.assertEqual(gate.removals("-     * we removed the @NoCSRFRequired tag once"), [])

    def test_a_line_comment_naming_the_attribute(self):
        self.assertEqual(gate.removals("-    // #[NoCSRFRequired] was never needed here"), [])

    def test_the_diff_file_header_is_not_a_removal(self):
        self.assertEqual(gate.removals("--- a/lib/Controller/X.php"), [])

    def test_an_added_line_is_not_a_removal(self):
        self.assertEqual(gate.removals("+    #[NoCSRFRequired]"), [])


class TestMustGoRed(unittest.TestCase):
    """Arm 2 of #191 — the arm that proves the gate was fixed, not disabled."""

    def test_a_real_attribute_line(self):
        line = "-    #[NoCSRFRequired]"
        self.assertEqual(gate.removals(line), [line])

    def test_a_fully_qualified_attribute(self):
        """FOUND BY MEASUREMENT, not by the issue.

        The pre-fix regex alternated on the literal `#[NoCSRFRequired]`, so
        the fully-qualified spelling — which is what an app that does not
        import the attribute writes, and what several fleet controllers use —
        matched NOTHING. Running arm 2 end-to-end through the runner reported
        PASS on a genuine removal. The old gate had a false NEGATIVE of its
        own hiding behind the false positive #191 reported.
        """
        line = "-    #[\\OCP\\AppFramework\\Http\\Attribute\\NoCSRFRequired]"
        self.assertEqual(gate.removals(line), [line])

    def test_a_grouped_attribute_list(self):
        line = "-    #[NoAdminRequired, NoCSRFRequired]"
        self.assertEqual(gate.removals(line), [line])

    def test_a_legacy_docblock_tag(self):
        line = "-     * @NoCSRFRequired"
        self.assertEqual(gate.removals(line), [line])

    def test_a_docblock_tag_without_the_star(self):
        line = "-@NoCSRFRequired"
        self.assertEqual(gate.removals(line), [line])

    def test_a_real_removal_inside_a_docblock_rewrite_is_still_seen(self):
        """The mixed case: prose AND a real deletion in one diff. Reporting
        zero here would be the fix over-applied."""
        diff = "\n".join([
            NLDESIGN,
            "-    #[NoCSRFRequired]",
            "-     * still more prose about #[NoCSRFRequired]",
        ])
        self.assertEqual(gate.removals(diff), ["-    #[NoCSRFRequired]"])

    def test_an_attribute_with_arguments_before_the_name_does_not_match(self):
        """`#[Route('/x', name: 'NoCSRFRequired')]` is a route name, not the
        attribute. The bracket-bounded class is what keeps them apart."""
        self.assertEqual(gate.removals("-    #[Route('/x')] // NoCSRFRequired"), [])


class TestANetZeroMoveIsNotARemoval(unittest.TestCase):
    """A `-` paired with an identical `+` is a MOVE.

    Measured on larpingapp: #297 relocated one docblock line and gate-48 kept
    `Hydra Gates` red on that repo's `development` from then on, over a commit
    that changed no auth posture at all.
    """

    MOVED = "     * @NoCSRFRequired removed to close the CSRF-forgery surface (closes #206)."

    def test_the_larpingapp_297_diff(self):
        diff = "\n".join([
            "--- a/lib/Controller/SettingsController.php",
            "+++ b/lib/Controller/SettingsController.php",
            "@@ -280 +284,16 @@",
            "-" + self.MOVED,
            "+     * instance-wide configuration write needs. `@NoCSRFRequired` was removed",
            "@@ -301,0 +321,32 @@",
            "+" + self.MOVED,
        ])
        self.assertEqual(gate.removals(diff), [])

    def test_a_moved_attribute_line(self):
        diff = "\n".join([
            "+++ b/lib/Controller/X.php",
            "-    #[NoCSRFRequired]",
            "+    #[NoCSRFRequired]",
        ])
        self.assertEqual(gate.removals(diff), [])

    def test_removed_twice_restored_once_still_reports_one(self):
        """Multiset cancellation. Two deletions and one restoration is one
        net deletion; reporting zero here would be the fix over-applied."""
        diff = "\n".join([
            "+++ b/lib/Controller/X.php",
            "-    #[NoCSRFRequired]",
            "-    #[NoCSRFRequired]",
            "+    #[NoCSRFRequired]",
        ])
        self.assertEqual(gate.removals(diff), ["-    #[NoCSRFRequired]"])

    def test_a_move_ACROSS_files_is_still_a_removal(self):
        """Deleted from one controller, added to another. The first controller
        genuinely lost the annotation, so cancelling across files would hide a
        real posture change."""
        diff = "\n".join([
            "+++ b/lib/Controller/A.php",
            "-    #[NoCSRFRequired]",
            "+++ b/lib/Controller/B.php",
            "+    #[NoCSRFRequired]",
        ])
        self.assertEqual(gate.removals(diff), ["-    #[NoCSRFRequired]"])

    def test_a_genuine_removal_alongside_an_unrelated_move_still_reports(self):
        diff = "\n".join([
            "+++ b/lib/Controller/X.php",
            "-     * @NoCSRFRequired",
            "+     * @NoCSRFRequired",
            "-    #[NoCSRFRequired]",
        ])
        self.assertEqual(gate.removals(diff), ["-    #[NoCSRFRequired]"])

    def test_whitespace_is_not_normalised_away(self):
        """A re-indented line is NOT the same line. Treating it as a move would
        let a reformat swallow a genuine deletion."""
        diff = "\n".join([
            "+++ b/lib/Controller/X.php",
            "-    #[NoCSRFRequired]",
            "+        #[NoCSRFRequired]",
        ])
        self.assertEqual(gate.removals(diff), ["-    #[NoCSRFRequired]"])

    def test_the_file_header_is_not_read_as_an_addition(self):
        """`+++ b/...` starts with `+`. If it were pooled as an added line the
        path would poison the cancellation set."""
        diff = "\n".join([
            "--- a/lib/Controller/X.php",
            "+++ b/lib/Controller/X.php",
            "-    #[NoCSRFRequired]",
        ])
        self.assertEqual(gate.removals(diff), ["-    #[NoCSRFRequired]"])


class TestCli(unittest.TestCase):
    def test_arguments_are_rejected(self):
        self.assertEqual(gate.main(["check_csrf_removal.py", "extra"]), 2)


if __name__ == "__main__":
    unittest.main()
