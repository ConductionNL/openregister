#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_button_name (gate-39). Run with:

    python3 scripts/lib/test_check_button_name.py

BOTH ARMS, EVERY TIME
---------------------
gate-39's accepted-attribute regex was

    r'(^|\\s)(:?aria-label|aria-labelledby|v-bind:aria-label|title)\\s*='

where `:?` binds to the FIRST alternative only. `:aria-label` was accepted;
`:title`, `v-bind:title` and `:aria-labelledby` were not. Vue binds nearly
every user-visible string because it has to pass through `t()`, so ALL 22 of
openbuild's findings were a correctly translated `:title` read as missing.

Accepting a bound attribute widens the gate, and widening is how a gate goes
quiet. So each accepted shape below sits next to the genuinely-unnamed button
it must not swallow.
"""
from __future__ import annotations

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_button_name as cbn  # noqa: E402


def rules(markup: str) -> list[str]:
    src = "<template>\n" + markup + "\n</template>\n"
    return [line.rsplit("rule=", 1)[1]
            for line in cbn.scan_source("Component.vue", src)]


FLAG = ["icon-only-button-without-accessible-name"]


class TheUnnamedButton(unittest.TestCase):
    """THE POSITIVE CONTROL. If these stop firing the gate is decoration."""

    def test_tp_an_icon_only_ncbutton(self):
        # procest TaskCreateDialog.vue, verbatim shape.
        self.assertEqual(rules(
            '<NcButton type="tertiary" @click="$emit(\'close\')">'
            '<CloseIcon :size="20" /></NcButton>'), FLAG)

    def test_tp_an_icon_only_native_button(self):
        self.assertEqual(rules(
            '<button type="button" class="close" @click="close">'
            '<CloseIcon /></button>'), FLAG)

    def test_tp_an_empty_button(self):
        self.assertEqual(rules('<button @click="go"></button>'), FLAG)

    def test_tp_a_single_character_body_is_not_a_name(self):
        # An icon ligature or a stray bullet. The >= 2 threshold is kept.
        self.assertEqual(rules('<button @click="go">×</button>'), FLAG)

    def test_tp_a_lookalike_attribute_does_not_name_it(self):
        # `data-title` is not `title`; the `(?:^|\s)` anchor is load-bearing.
        self.assertEqual(rules(
            '<button data-title="Remove" @click="go"><Icon /></button>'), FLAG)


class BoundNamesAreNames(unittest.TestCase):
    """THE FIX. Each of these was reported as unnamed."""

    def test_fp_bound_title(self):
        # openbuild SettingsPageEditor.vue, verbatim. All 22 of openbuild's
        # findings were this shape.
        self.assertEqual(rules("""
            <button type="button"
                    class="settings-page-editor__remove"
                    :title="t('openbuild', 'Remove tab')"
                    @click="removeTab(index)">
                <CloseIcon :size="16" />
            </button>
        """), [])

    def test_fp_v_bind_title(self):
        self.assertEqual(rules(
            '<button v-bind:title="label" @click="go"><Icon /></button>'), [])

    def test_fp_bound_aria_labelledby(self):
        self.assertEqual(rules(
            '<button :aria-labelledby="headingId" @click="go"><Icon /></button>'), [])

    def test_fp_bound_aria_label_still_works(self):
        # Accepted before; must not regress.
        self.assertEqual(rules(
            '<button :aria-label="t(\'app\', \'Close\')" @click="go"><Icon /></button>'), [])

    def test_fp_static_title_still_works(self):
        self.assertEqual(rules('<button title="Close" @click="go"><Icon /></button>'), [])

    def test_fp_the_camelcase_prop_form(self):
        # NcButton's published prop name.
        self.assertEqual(rules(
            '<NcButton :ariaLabel="label" @click="go"><Icon /></NcButton>'), [])

    # ---- the paired true positive -----------------------------------------
    def test_tp_the_same_button_with_the_binding_REMOVED_is_still_flagged(self):
        # One attribute is the whole difference from test_fp_bound_title.
        self.assertEqual(rules("""
            <button type="button"
                    class="settings-page-editor__remove"
                    @click="removeTab(index)">
                <CloseIcon :size="16" />
            </button>
        """), FLAG)


class NamedByContent(unittest.TestCase):
    def test_fp_literal_text(self):
        self.assertEqual(rules('<NcButton @click="go">Save changes</NcButton>'), [])

    def test_fp_an_interpolation(self):
        self.assertEqual(rules(
            '<NcButton @click="go">{{ t(\'app\', \'Save\') }}</NcButton>'), [])

    def test_fp_an_explicit_default_slot(self):
        # `<template #icon>` + `<template #default>` is the NcButton idiom;
        # the default slot renders the visible label.
        self.assertEqual(rules("""
            <NcButton @click="go">
                <template #icon><PlusIcon :size="20" /></template>
                <template #default>Add a field</template>
            </NcButton>
        """), [])

    def test_tp_an_icon_slot_ALONE_is_not_a_name(self):
        # The negative control for the slot rule: an icon slot names nothing.
        self.assertEqual(rules("""
            <NcButton @click="go">
                <template #icon><PlusIcon :size="20" /></template>
            </NcButton>
        """), FLAG)


class Parsing(unittest.TestCase):
    def test_a_greater_than_inside_an_attribute_does_not_end_the_tag(self):
        # The old `[^>]*` run ended the tag at the expression's `>`, so the
        # `:title` that followed was never seen.
        self.assertEqual(rules(
            '<button :class="a > b ? \'x\' : \'y\'" :title="label" @click="go">'
            '<Icon /></button>'), [])

    def test_commented_out_markup_ships_nothing(self):
        self.assertEqual(rules('<!-- <button @click="go"><Icon /></button> -->'), [])

    def test_a_button_in_a_script_string_is_not_markup(self):
        src = ('<template><p>hi</p></template>\n<script>\n'
               "const s = '<button><i></i></button>'\n</script>\n")
        self.assertEqual(cbn.scan_source("C.vue", src), [])

    def test_each_unnamed_button_is_reported(self):
        self.assertEqual(rules("""
            <button @click="a"><Icon /></button>
            <button :title="t('app', 'B')" @click="b"><Icon /></button>
            <button @click="c"><Icon /></button>
        """), FLAG * 2)


if __name__ == '__main__':
    unittest.main(verbosity=2)
