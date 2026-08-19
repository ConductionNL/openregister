#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_table_headers (gate-43). Run with:

    python3 scripts/lib/test_check_table_headers.py

THE NEGATIVE CONTROL THAT NAMED THE DEFECT
------------------------------------------
gate-43 accepted a whole table on the strength of ONE `scope=` anywhere in
it. Removing exactly one `scope=` from a passing table still reported PASS
(#222). `test_removing_exactly_one_scope_flips_the_verdict` below IS that
experiment, run as an assertion: it builds a table that passes, deletes a
single attribute, and requires the verdict to change. If the
at-least-one-wise rule is ever reintroduced, that test is the one that fails.
"""
from __future__ import annotations

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_table_headers as cth  # noqa: E402


def findings(markup: str) -> list[str]:
    src = "<template>\n" + markup + "\n</template>\n"
    return cth.scan_source("Component.vue", src)


def rules(markup: str) -> list[str]:
    return [line.rsplit("rule=", 1)[1] for line in findings(markup)]


PASSING_TABLE = """
<table class="rules">
    <thead>
        <tr>
            <th scope="col">Key</th>
            <th scope="col">Value</th>
            <th scope="col">Actions</th>
        </tr>
    </thead>
    <tbody>
        <tr><td>a</td><td>1</td><td>x</td></tr>
    </tbody>
</table>
"""


class TheNegativeControl(unittest.TestCase):
    def test_a_fully_scoped_table_passes(self):
        self.assertEqual(rules(PASSING_TABLE), [])

    def test_removing_exactly_one_scope_flips_the_verdict(self):
        # THE EXPERIMENT FROM #222, as an assertion. One attribute, one
        # occurrence, and the table must stop passing.
        mutated = PASSING_TABLE.replace('<th scope="col">Value</th>',
                                        '<th>Value</th>', 1)
        self.assertNotEqual(mutated, PASSING_TABLE, "the mutation did not apply")
        self.assertEqual(rules(mutated), ["th-without-scope"])

    def test_the_finding_says_how_many_headers_are_unscoped(self):
        mutated = PASSING_TABLE.replace('<th scope="col">Value</th>',
                                        '<th>Value</th>', 1)
        self.assertIn("unscoped=1/3", findings(mutated)[0])

    def test_a_table_where_only_the_first_column_was_fixed(self):
        # The shape this defect hid: someone scopes one header and stops.
        self.assertEqual(rules("""
            <table>
                <tr><th scope="col">Name</th><th>Size</th><th>Modified</th></tr>
                <tr><td>a</td><td>1</td><td>x</td></tr>
            </table>
        """), ["th-without-scope"])
        self.assertIn("unscoped=2/3", findings("""
            <table>
                <tr><th scope="col">Name</th><th>Size</th><th>Modified</th></tr>
                <tr><td>a</td><td>1</td><td>x</td></tr>
            </table>
        """)[0])


class StillCaught(unittest.TestCase):
    def test_no_scope_anywhere(self):
        self.assertEqual(rules(
            '<table><tr><th>A</th><th>B</th></tr>'
            '<tr><td>1</td><td>2</td></tr></table>'), ["th-without-scope"])

    def test_a_data_table_with_no_headers_at_all(self):
        self.assertEqual(rules(
            '<table><tr><td>1</td><td>2</td></tr></table>'), ["table-without-th"])

    def test_row_headers_in_the_body_are_asked_too(self):
        # The scoped <th> is in the head; the unscoped ones are row headers in
        # the body — precisely the association a screen-reader user needs.
        self.assertEqual(rules("""
            <table>
                <thead><tr><th scope="col">Metric</th><th scope="col">Value</th></tr></thead>
                <tbody>
                    <tr><th>Uptime</th><td>99%</td></tr>
                    <tr><th>Errors</th><td>3</td></tr>
                </tbody>
            </table>
        """), ["th-without-scope"])

    def test_each_table_is_reported_separately(self):
        self.assertEqual(rules(
            '<table><tr><th>A</th></tr><tr><td>1</td></tr></table>'
            '<table><tr><td>1</td></tr></table>'),
            ["th-without-scope", "table-without-th"])


class NotFindings(unittest.TestCase):
    def test_a_bound_scope_is_a_real_scope(self):
        # `:scope="isRow ? 'row' : 'col'"` produces the attribute the browser
        # reads. Refusing it would demand a literal for no a11y benefit.
        self.assertEqual(rules("""
            <table>
                <tr><th :scope="headerScope">A</th><th scope="col">B</th></tr>
                <tr><td>1</td><td>2</td></tr>
            </table>
        """), [])

    def test_a_layout_table_with_neither_th_nor_td(self):
        self.assertEqual(rules('<table><caption>x</caption></table>'), [])

    def test_commented_out_markup_ships_nothing(self):
        self.assertEqual(rules(
            '<!-- <table><tr><th>A</th></tr><tr><td>1</td></tr></table> -->'), [])

    def test_a_wrapper_component_owns_its_own_markup(self):
        self.assertEqual(rules('<CnDataTable :rows="rows" />'), [])

    def test_a_greater_than_inside_a_th_attribute_does_not_hide_the_scope(self):
        # A `[^>]*` attribute run would end the tag at the expression's `>`
        # and report a correctly-scoped header as unscoped.
        self.assertEqual(rules("""
            <table>
                <tr><th :class="a > b ? 'x' : 'y'" scope="col">A</th></tr>
                <tr><td>1</td></tr>
            </table>
        """), [])

    def test_self_closing_th_with_scope(self):
        self.assertEqual(rules(
            '<table><tr><th scope="col" /></tr><tr><td>1</td></tr></table>'), [])


class UnnamedHeadersAreNotHeaders(unittest.TestCase):
    """Tightening to per-header produced 8 findings in openconnector, a repo
    the old rule passed — and all 8 were this same spacer column. `scope=` on
    a header with no name associates nothing."""

    def test_fp_an_aria_hidden_spacer_column(self):
        # openconnector MappingRulesEditor.vue, verbatim: the drag-handle column.
        self.assertEqual(rules("""
            <table>
                <thead><tr>
                    <th class="col-handle" aria-hidden="true" />
                    <th scope="col">Target property</th>
                    <th scope="col">Template</th>
                </tr></thead>
                <tbody><tr><td>a</td><td>b</td><td>c</td></tr></tbody>
            </table>
        """), [])

    def test_fp_an_empty_self_closed_actions_column(self):
        # openconnector ApprovalsIndex.vue / SyncDeadLetterPage.vue shape.
        self.assertEqual(rules("""
            <table>
                <tr><th scope="col">Name</th><th /></tr>
                <tr><td>a</td><td><button>x</button></td></tr>
            </table>
        """), [])

    def test_fp_an_empty_paired_th(self):
        self.assertEqual(rules("""
            <table>
                <tr><th scope="col">Name</th><th></th></tr>
                <tr><td>a</td><td>b</td></tr>
            </table>
        """), [])

    def test_fp_role_presentation(self):
        self.assertEqual(rules("""
            <table>
                <tr><th scope="col">Name</th><th role="presentation">x</th></tr>
                <tr><td>a</td><td>b</td></tr>
            </table>
        """), [])

    # ---- the paired true positives ----------------------------------------
    def test_tp_the_same_column_WITH_a_name_is_still_asked(self):
        # One word of content is the whole difference.
        self.assertEqual(rules("""
            <table>
                <tr><th scope="col">Name</th><th>Actions</th></tr>
                <tr><td>a</td><td>b</td></tr>
            </table>
        """), ["th-without-scope"])

    def test_tp_a_header_named_by_an_interpolation_is_still_asked(self):
        self.assertEqual(rules("""
            <table>
                <tr><th scope="col">A</th><th>{{ t('app', 'Size') }}</th></tr>
                <tr><td>1</td><td>2</td></tr>
            </table>
        """), ["th-without-scope"])

    def test_a_table_of_only_unnamed_headers_is_not_reported_as_headerless(self):
        # It HAS <th> elements, so `table-without-th` would be a wrong
        # diagnosis; and none of them can carry a useful scope.
        self.assertEqual(rules(
            '<table><tr><th /><th /></tr><tr><td>1</td><td>2</td></tr></table>'), [])

    def test_the_count_only_counts_named_headers(self):
        mutated = """
            <table>
                <tr><th aria-hidden="true" /><th scope="col">A</th><th>B</th></tr>
                <tr><td>1</td><td>2</td><td>3</td></tr>
            </table>
        """
        self.assertIn("unscoped=1/2", findings(mutated)[0])


class ScriptBlocks(unittest.TestCase):
    def test_a_table_in_a_script_string_is_not_markup(self):
        src = ('<template><p>hi</p></template>\n<script>\n'
               "const t = '<table><tr><th>A</th></tr><tr><td>1</td></tr></table>'\n"
               '</script>\n')
        self.assertEqual(cth.scan_source("C.vue", src), [])


if __name__ == '__main__':
    unittest.main(verbosity=2)
