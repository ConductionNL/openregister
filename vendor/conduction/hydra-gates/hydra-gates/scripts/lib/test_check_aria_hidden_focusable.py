#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_aria_hidden_focusable (gate-37). Run with:

    python3 scripts/lib/test_check_aria_hidden_focusable.py

BOTH ARMS, EVERY TIME
---------------------
gate-37 counted `tabindex="-1"` as focusable — the one attribute value that
proves an element is NOT in the tab order. Its remediation advice was
therefore to either expose an unnamed control to screen readers, or to put a
control screen readers cannot see back into the tab order: the exact defect
this gate exists to catch. Both regress accessibility (#222).

The relaxation is narrow — negative tabindex, and nothing else — so every
test below that asserts "not flagged" is paired with the near-identical case
that must still be flagged. A gate that stops catching its own subject is
worse than no gate, because it reports PASS while doing it.
"""
from __future__ import annotations

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_aria_hidden_focusable as ahf  # noqa: E402


def rules(markup: str) -> list[str]:
    src = "<template>\n" + markup + "\n</template>\n"
    return [line.rsplit("rule=", 1)[1]
            for line in ahf.scan_source("Component.vue", src)]


FLAG = ["aria-hidden-on-focusable-element"]


class NegativeTabindexIsNotFocusable(unittest.TestCase):
    """THE FIX. `tabindex="-1"` removes the element from sequential
    navigation — for native controls too, which is why it overrides the
    native-tag signal."""

    def test_fp_the_canonical_hidden_file_input(self):
        # @conduction/nextcloud-vue CnFilesWidget.vue, verbatim shape. Hidden
        # from AT, out of the tab order, driven by a visible <button>.
        self.assertEqual(rules("""
            <input ref="fileInput"
                   type="file"
                   multiple
                   :aria-hidden="true"
                   tabindex="-1"
                   @change="onFileInputChange">
        """), [])

    def test_fp_a_programmatically_focused_panel(self):
        # CnContextMenu.vue shape: focused by script on open, never tabbed to.
        self.assertEqual(rules(
            '<div class="panel" role="menu" tabindex="-1" aria-hidden="true" />'), [])

    def test_fp_a_drag_handle_taken_out_of_the_order(self):
        # openconnector MappingRulesEditor.vue: the row itself is tabbable,
        # the handle inside it deliberately is not.
        self.assertEqual(rules(
            '<button type="button" aria-hidden="true" tabindex="-1">x</button>'), [])

    def test_fp_a_bound_negative_literal_reads_the_same(self):
        self.assertEqual(rules('<div :tabindex="-1" aria-hidden="true" />'), [])

    def test_fp_any_negative_value(self):
        self.assertEqual(rules('<div tabindex="-2" aria-hidden="true" />'), [])

    # ---- the paired true positives ----------------------------------------
    def test_tp_tabindex_zero_is_still_flagged(self):
        # ONE CHARACTER from the fixture above, and it must flip.
        self.assertEqual(rules('<div tabindex="0" aria-hidden="true" />'), FLAG)

    def test_tp_positive_tabindex_is_still_flagged(self):
        self.assertEqual(rules('<div tabindex="3" aria-hidden="true" />'), FLAG)

    def test_tp_a_file_input_WITHOUT_the_negative_tabindex_is_still_flagged(self):
        # The negative control for the canonical pattern: same element, minus
        # the one attribute that takes it out of the tab order.
        self.assertEqual(rules("""
            <input ref="fileInput" type="file" multiple :aria-hidden="true"
                   @change="onFileInputChange">
        """), FLAG)

    def test_tp_a_bound_unreadable_tabindex_is_still_flagged(self):
        # Unknown is not safe. Keeps the previous behaviour deliberately.
        self.assertEqual(rules('<div :tabindex="idx" aria-hidden="true" />'), FLAG)


class NativeFocusable(unittest.TestCase):
    def test_tp_a_button(self):
        self.assertEqual(rules('<button aria-hidden="true">x</button>'), FLAG)

    def test_tp_an_anchor_with_href(self):
        self.assertEqual(rules('<a href="/x" aria-hidden="true">x</a>'), FLAG)

    def test_fp_an_anchor_without_href_is_not_focusable(self):
        self.assertEqual(rules('<a aria-hidden="true">x</a>'), [])

    def test_tp_an_anchor_with_a_bound_href(self):
        self.assertEqual(rules('<a :href="url" aria-hidden="true">x</a>'), FLAG)

    def test_tp_a_select(self):
        self.assertEqual(rules('<select aria-hidden="true"><option>a</option></select>'), FLAG)


class InteractiveRoles(unittest.TestCase):
    def test_tp_role_button(self):
        self.assertEqual(rules('<div role="button" aria-hidden="true" />'), FLAG)

    def test_fp_a_presentational_div(self):
        self.assertEqual(rules('<div class="decoration" aria-hidden="true" />'), [])

    def test_fp_role_presentation(self):
        self.assertEqual(rules('<div role="presentation" aria-hidden="true" />'), [])

    def test_fp_a_decorative_icon_span(self):
        # By far the most common correct use of aria-hidden. Must stay quiet.
        self.assertEqual(rules('<span class="icon-star" aria-hidden="true" />'), [])


class ScopeAndParsing(unittest.TestCase):
    def test_pascal_case_components_are_the_components_problem(self):
        self.assertEqual(rules('<NcAvatar tabindex="0" aria-hidden="true" />'), [])

    def test_commented_out_markup_ships_nothing(self):
        self.assertEqual(rules('<!-- <div tabindex="0" aria-hidden="true" /> -->'), [])

    def test_script_blocks_are_not_markup(self):
        src = ('<template><p>hi</p></template>\n<script>\n'
               'const s = \'<div tabindex="0" aria-hidden="true">\'\n</script>\n')
        self.assertEqual(
            [l.rsplit("rule=", 1)[1] for l in ahf.scan_source("C.vue", src)], [])

    def test_a_greater_than_inside_an_attribute_does_not_end_the_tag(self):
        # The old `[^>]*` grep ended the tag at the `>` in the expression and
        # could not see the tabindex that followed.
        self.assertEqual(
            rules('<div :title="a > b" aria-hidden="true" tabindex="0" />'), FLAG)

    def test_aria_hidden_false_is_not_hidden(self):
        self.assertEqual(rules('<div tabindex="0" aria-hidden="false" />'), [])

    def test_multiple_findings_in_one_file_are_reported_separately(self):
        self.assertEqual(rules("""
            <div tabindex="0" aria-hidden="true" />
            <button aria-hidden="true">x</button>
            <input type="file" aria-hidden="true" tabindex="-1">
        """), FLAG * 2)


if __name__ == '__main__':
    unittest.main(verbosity=2)
