#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_nc_select_labels (gate-12). Run with:

    python3 scripts/lib/test_check_nc_select_labels.py

BOTH WAYS, EVERY TIME
---------------------
The bug this helper replaces made the gate ANTI-CORRELATED with its own
subject: an `NcSelect` that named itself after a `:reduce` prop was
reported, and deleting the label prop changed nothing while deleting
`:reduce` cleared it. So the arrow-function fixtures below are paired,
element for element, with the same markup minus the label prop — which
must still be reported. A relaxation that cannot be shown to still fire is
indistinguishable from switching the gate off.

Fixtures are the real fleet markup: scholiq's ConferenceScheduleBoard
(`:reduce` then `id`+`:input-label`), its LessonComposer (`:reduce` then
both label props), and a genuinely unnamed select next to a hand-written
`<label for=…>`, which is the shape the rule exists for.
"""
from __future__ import annotations

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_nc_select_labels as c  # noqa: E402


def scan(src: str) -> list[str]:
    return c.scan_source('x.vue', src)


class ArrowFunctionAttributes(unittest.TestCase):
    """A `>` inside a quoted attribute value must not end the element."""

    REDUCE_THEN_LABEL = '''
    <template>
      <NcSelect id="csb-round"
        v-model="selectedRoundId"
        :options="roundOptions"
        :reduce="(o) => o.id"
        :input-label="t('scholiq', 'Round')" />
    </template>
    '''

    def test_label_after_reduce_is_seen(self):
        self.assertEqual(scan(self.REDUCE_THEN_LABEL), [])

    def test_same_element_without_the_label_still_fires(self):
        src = self.REDUCE_THEN_LABEL.replace(
            ":input-label=\"t('scholiq', 'Round')\"", '')
        found = scan(src)
        self.assertEqual(len(found), 1, found)
        self.assertIn('NcSelect', found[0])

    def test_both_label_props_after_reduce(self):
        src = '''
        <template>
          <NcSelect
            v-model="block.materialId"
            :options="materialOptions"
            :reduce="(opt) => opt.id"
            :input-label="t('scholiq', 'Material')"
            :aria-label-combobox="t('scholiq', 'Material')" />
        </template>
        '''
        self.assertEqual(scan(src), [])

    def test_aria_label_combobox_alone_after_reduce(self):
        src = '''
        <template>
          <NcSelect :reduce="(o) => o.id"
                    :aria-label-combobox="t('a', 'Teacher')" />
        </template>
        '''
        self.assertEqual(scan(src), [])

    def test_two_selects_one_named_one_not(self):
        """The greedy-match trap: a fix that runs past the first element
        would swallow the second and report neither."""
        src = '''
        <template>
          <NcSelect :reduce="(o) => o.id" :input-label="t('a', 'One')" />
          <NcSelect :reduce="(o) => o.id" :options="two" />
        </template>
        '''
        found = scan(src)
        self.assertEqual(len(found), 1, found)
        self.assertIn(':options="two"', found[0])


class StillFails(unittest.TestCase):
    """Everything the rule exists for is still reported."""

    def test_bare_select(self):
        self.assertEqual(len(scan('<template><NcSelect /></template>')), 1)

    def test_manual_label_element_does_not_count(self):
        src = '''
        <template>
          <label for="cohort">Cohort</label>
          <NcSelect id="cohort" :options="opts" />
        </template>
        '''
        self.assertEqual(len(scan(src)), 1)

    def test_aria_label_is_not_an_accepted_name(self):
        """Unchanged remit: the bash gate accepted only the four props, and
        widening it here would make the before/after numbers incomparable."""
        src = '<template><NcSelect aria-label="Cohort" /></template>'
        self.assertEqual(len(scan(src)), 1)

    def test_unclosed_angle_bracket_does_not_swallow_the_file(self):
        src = '<template><NcSelect :options="a"><div>x</div></template>'
        found = scan(src)
        self.assertEqual(len(found), 1, found)
        self.assertNotIn('div', found[0])


class Spellings(unittest.TestCase):
    def test_camel_case(self):
        self.assertEqual(scan('<template><NcSelect :inputLabel="x" /></template>'), [])

    def test_v_bind_prefix(self):
        self.assertEqual(
            scan('<template><NcSelect v-bind:input-label="x" /></template>'), [])

    def test_unbound_literal(self):
        self.assertEqual(scan('<template><NcSelect input-label="Cohort" /></template>'), [])

    def test_a_prop_that_merely_ends_in_label_is_not_enough(self):
        """`my-input-label` must not satisfy the rule by substring."""
        self.assertEqual(
            len(scan('<template><NcSelect :my-input-label="x" /></template>')), 1)


class Comments(unittest.TestCase):
    def test_commented_out_select_is_not_a_finding(self):
        src = '<template><!-- <NcSelect :options="a" /> --></template>'
        self.assertEqual(scan(src), [])

    def test_a_real_select_after_a_comment_is_still_found(self):
        src = '<template><!-- old --><NcSelect :options="a" /></template>'
        self.assertEqual(len(scan(src)), 1)


class OtherComponents(unittest.TestCase):
    def test_ncselectableitem_is_not_ncselect(self):
        self.assertEqual(scan('<template><NcSelectableItem /></template>'), [])

    def test_closing_tag_is_not_an_element(self):
        src = '<template><NcSelect :input-label="x">slot</NcSelect></template>'
        self.assertEqual(scan(src), [])


class ScriptBlockIsNotMarkup(unittest.TestCase):
    """Convergence onto source_scope.markup_mask (#220 / #235).

    The helper's first cut stripped `<!-- … -->` only, so an `NcSelect`
    written in a JSDoc line — the exact shape that put three false gate-31
    findings on openbuild — would still have been scanned as an element.
    """

    JSDOC = '''<template>
  <NcSelect :options="o" input-label="Round" />
</template>

<script>
/**
 * The picker is a `<NcSelect>` whose name comes from `input-label`; a
 * hand-written `<label for>` next to it would associate with nothing.
 */
export default { name: 'X' }
</script>
'''

    def test_a_select_named_in_a_docblock_is_not_an_element(self):
        self.assertEqual(scan(self.JSDOC), [])

    def test_an_unnamed_select_in_the_template_still_fires(self):
        """Anti-widening control for the case above."""
        src = self.JSDOC.replace(' input-label="Round"', '')
        found = scan(src)
        self.assertEqual(len(found), 1, found)
        self.assertIn('NcSelect', found[0])


if __name__ == '__main__':
    unittest.main(verbosity=2)
