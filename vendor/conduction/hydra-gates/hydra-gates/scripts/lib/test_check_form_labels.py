#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_form_labels (gate-40). Run with:

    python3 scripts/lib/test_check_form_labels.py

BOTH WAYS, EVERY TIME
---------------------
Gate-40 was measured at 1,211 findings across 21 repos, 57% of them false.
The false positives were not merely noise: the ONLY way to clear the
`NcCheckboxRadioSwitch` shape under the old implementation was to add
`aria-label` to a control that already names itself from its default slot,
and `aria-label` OVERRIDES the visible text. Clearing the gate meant
shipping an accessibility regression to satisfy an accessibility gate.

So every relaxation below is paired, in the same class, with the case it
must NOT swallow. The fixtures are the real fleet markup: app-versions'
`<label>`-wrapped safe-mode checkbox, docudesk's self-closed anonymiser
switch (a genuine unnamed control, still flagged), decidesk's
`<label><span>…</span><input></label>` publication settings.
"""
from __future__ import annotations

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_form_labels as cfl  # noqa: E402


def rules(markup: str) -> list[str]:
    """Rule names reported for a `<template>` body."""
    src = "<template>\n" + markup + "\n</template>\n"
    return [line.rsplit("rule=", 1)[1]
            for line in cfl.scan_source("Component.vue", src)]


# --------------------------------------------------------------------------
# Mode 1 — implicit <label> wrapping. 268 fleet findings.
# --------------------------------------------------------------------------
class ImplicitLabelWrapping(unittest.TestCase):
    def test_fp_a_wrapped_checkbox_is_named(self):
        # app-versions/src/App.vue, verbatim shape.
        self.assertEqual(rules("""
            <label :class="$style.safeMode">
                <input v-model="safeModeEnabled" type="checkbox" :disabled="busy">
                <span>Safe mode (block downgrades)</span>
            </label>
        """), [])

    def test_fp_label_text_before_the_input_also_counts(self):
        # decidesk/src/views/settings/PublicationSettings.vue shape.
        self.assertEqual(rules("""
            <label class="field">
                <span>{{ t('decidesk', 'Target OpenCatalogi catalog') }}</span>
                <input v-model="config.catalog" type="text">
            </label>
        """), [])

    def test_tp_an_unwrapped_input_is_still_reported(self):
        self.assertEqual(rules('<div><input v-model="x" type="checkbox"></div>'),
                         ["input-without-label"])

    def test_tp_an_input_after_the_label_closes_is_still_reported(self):
        # The wrap must be real containment, not adjacency.
        self.assertEqual(rules("""
            <label><span>Safe mode</span></label>
            <input v-model="x" type="checkbox">
        """), ["input-without-label"])

    def test_tp_a_label_whose_for_matches_nothing_does_not_name_an_input(self):
        self.assertEqual(rules("""
            <label for="other-field">Name</label>
            <input v-model="x" type="text" id="this-field">
        """), ["input-without-label"])

    def test_tp_wrapping_does_not_leak_to_the_next_sibling(self):
        self.assertEqual(rules("""
            <label><input type="text"><span>A</span></label>
            <label><input type="text"><span>B</span></label>
            <input type="text">
        """), ["input-without-label"])


# --------------------------------------------------------------------------
# Mode 2 — NcCheckboxRadioSwitch default-slot labels. 463 fleet findings,
# and the one whose remediation advice was an a11y regression.
# --------------------------------------------------------------------------
class CheckboxRadioSwitchDefaultSlot(unittest.TestCase):
    def test_fp_default_slot_text_is_the_label(self):
        # app-versions/src/components/DiscoverPanel.vue, verbatim.
        self.assertEqual(rules("""
            <NcCheckboxRadioSwitch v-model="installedOnly" data-testid="discover-installed-only">
                {{ t('app_versions', 'Installed apps only') }}
            </NcCheckboxRadioSwitch>
        """), [])

    def test_fp_plain_slot_text_is_the_label(self):
        self.assertEqual(rules(
            '<NcCheckboxRadioSwitch v-model="x">Enable OCR</NcCheckboxRadioSwitch>'), [])

    def test_tp_a_self_closed_switch_with_no_slot_is_still_reported(self):
        # docudesk/src/views/settings/Settings.vue:41 — a real unnamed
        # control. It must survive the relaxation, or the relaxation has
        # deleted the gate.
        self.assertEqual(rules("""
            <NcCheckboxRadioSwitch
                :model-value="false"
                type="switch"
                @update:modelValue="resetAnonymiserWarning" />
        """), ["nccheckboxradioswitch-without-label-prop"])

    def test_tp_an_empty_slot_is_not_a_label(self):
        self.assertEqual(rules(
            '<NcCheckboxRadioSwitch v-model="x">   </NcCheckboxRadioSwitch>'),
            ["nccheckboxradioswitch-without-label-prop"])

    def test_tp_an_unclosed_switch_is_reported_not_assumed_labelled(self):
        # An open tag with no closing tag proves NOTHING about its slot.
        # Assuming it labelled would make malformed markup the cheapest way
        # to silence the gate.
        self.assertEqual(rules('<NcCheckboxRadioSwitch v-model="x">'),
                         ["nccheckboxradioswitch-without-label-prop"])

    def test_regression_the_label_prop_still_satisfies_it(self):
        self.assertEqual(rules(
            '<NcCheckboxRadioSwitch v-model="x" :label="t(\'app\', \'OCR\')" />'), [])


# --------------------------------------------------------------------------
# Mode 3 — dynamic :id / :for. 56 fleet findings.
# --------------------------------------------------------------------------
class DynamicIdForPairs(unittest.TestCase):
    def test_fp_matching_bound_expressions_associate(self):
        self.assertEqual(rules("""
            <label :for="`field-${row.id}`">{{ row.name }}</label>
            <input :id="`field-${row.id}`" v-model="row.value" type="text">
        """), [])

    def test_fp_whitespace_differences_do_not_break_the_pair(self):
        self.assertEqual(rules("""
            <label :for="'field-' + row.id">{{ row.name }}</label>
            <input :id="'field-'  +  row.id" v-model="row.value" type="text">
        """), [])

    def test_tp_a_mismatched_expression_is_still_reported(self):
        # Same shape, different expression. If the gate matched on "is bound
        # at all" instead of on the expression, this would go quiet — and
        # every mis-wired :for/:id pair in the fleet with it.
        self.assertEqual(rules("""
            <label :for="`field-${row.id}`">{{ row.name }}</label>
            <input :id="`other-${row.id}`" v-model="row.value" type="text">
        """), ["input-without-label"])

    def test_regression_literal_id_for_pairs_still_associate(self):
        self.assertEqual(rules("""
            <label for="catalog">Catalog</label>
            <input id="catalog" type="text">
        """), [])


class InputIdOnAWrapperComponent(unittest.TestCase):
    """`input-id` is how an Nc* wrapper exposes the <input> it renders, and
    the documented way to point an EXTERNAL <label for> at it. That is the
    same real HTML association already accepted for a native element, so it
    is an accessible name by the same evidence.

    Before this, a field written the way the shared components document it
    was reported unlabelled, and the only remedies were a redundant
    aria-label overriding the visible label, or a second visible one.
    """

    def test_fp_an_external_label_for_a_bound_input_id_associates(self):
        # openconnector SyncConfigWidget, verbatim in shape.
        self.assertEqual(rules("""
            <label :for="filePathId" class="sync-config__label">
                {{ t('openconnector', 'File path or glob') }}
            </label>
            <NcTextField :input-id="filePathId" :model-value="sourceIdValue" />
        """), [])

    def test_fp_a_literal_input_id_associates_too(self):
        self.assertEqual(rules("""
            <label for="sync-file-path">File path or glob</label>
            <NcTextField input-id="sync-file-path" :model-value="v" />
        """), [])

    def test_tp_an_input_id_no_label_points_at_is_still_reported(self):
        # The control. If the exemption ever degrades into "has an input-id
        # at all", this goes quiet — and with it every wrapper whose label
        # was deleted, renamed, or never written.
        self.assertEqual(rules("""
            <label :for="someOtherId">File path or glob</label>
            <NcTextField :input-id="filePathId" :model-value="v" />
        """), ["nctextfield-without-label-prop"])

    def test_tp_an_input_id_with_no_label_anywhere_is_still_reported(self):
        self.assertEqual(rules("""
            <NcTextField :input-id="filePathId" :model-value="v" />
        """), ["nctextfield-without-label-prop"])


class PlainIdOnAWrapperComponent(unittest.TestCase):
    """.github#310 — `id` is the attribute that reaches the `<input>` on the
    NcTextField / NcInputField family, so `<label for="x">` + `<NcTextField
    id="x">` is a correct association and must not be reported.

    Evidence is the published package, which is the authority on props:
    nc-vue 9.9.0's NcInputField renders `id: __props.id` onto its `<input>`;
    8.39.0's is `inheritAttrs: false` and computes
    `computedId() { return this.$attrs.id ? this.$attrs.id : this.inputName }`.
    Neither declares `inputId` at all — `grep -ril inputid` across
    `dist/components/NcTextField/` and `dist/components/NcInputField/`
    returns nothing on 9.9.0, and 8.39.0 has zero occurrences.

    The reason this one matters more than its count: the two edits that
    closed the finding before were a no-op `input-id` attribute and an
    `aria-label` that OVERRIDES the visible label (WCAG 2.5.3 Label in Name).
    A gate whose only remediations are a no-op and a regression is a gate
    that teaches the fleet to ship regressions.
    """

    def test_fp_a_literal_id_matching_a_label_for_associates(self):
        # openregister, verbatim shape — 11 findings of exactly this.
        self.assertEqual(rules("""
            <label for="dolphin-endpoint">Dolphin API Endpoint</label>
            <NcTextField id="dolphin-endpoint" v-model="fileSettings.dolphinApiEndpoint" />
        """), [])

    def test_fp_a_bound_id_matching_a_bound_label_for_associates(self):
        self.assertEqual(rules("""
            <label :for="`f-${uid}`">Endpoint</label>
            <NcInputField :id="`f-${uid}`" :model-value="v" />
        """), [])

    # -- the true positives this must not swallow -------------------------
    def test_tp_an_id_no_label_points_at_is_still_reported(self):
        # THE ABUSE CONTROL. If the acceptance ever degrades into "has an id
        # at all", every wrapper whose label was deleted, renamed or never
        # written goes quiet — and `id` is on almost every control in the
        # fleet, so that degradation would be a blanket, not a narrowing.
        self.assertEqual(rules("""
            <label for="some-other-field">Endpoint</label>
            <NcTextField id="dolphin-endpoint" :model-value="v" />
        """), ["nctextfield-without-label-prop"])

    def test_tp_an_id_with_no_label_anywhere_is_still_reported(self):
        self.assertEqual(rules("""
            <NcTextField id="dolphin-endpoint" :model-value="v" />
        """), ["nctextfield-without-label-prop"])

    def test_tp_a_label_for_pointing_at_nothing_names_nothing(self):
        # A `<label for>` with no control carrying that id is a dangling
        # association; the unnamed control beside it is still unnamed.
        self.assertEqual(rules("""
            <label for="dolphin-endpoint">Dolphin API Endpoint</label>
            <NcTextField :model-value="v" />
        """), ["nctextfield-without-label-prop"])

    def test_tp_a_bare_wrapper_with_no_naming_source_is_still_reported(self):
        self.assertEqual(rules('<NcTextField :model-value="v" />'),
                         ["nctextfield-without-label-prop"])


class NotInTheAccessibilityTree(unittest.TestCase):
    """.github#273 — an element out of the accessibility tree has no
    accessible name, so `aria-label` on it is inert and the finding cannot be
    closed honestly. Same principle `check_table_headers.py` already applies
    to `<th aria-hidden="true">`.

    #273 keyed this on `aria-hidden`/`tabindex`. Measured fleet-wide, that
    spelling matches NOTHING: `aria-hidden="true"` appears on 0 of gate-40's
    471 findings and `display:none` on 8 — ALL 8 of them `type="file"`. So the
    exemption is written for the spelling that actually occurs, and the
    `display:none` arm is bounded to `type="file"` because a style, unlike an
    ARIA attribute, can be toggled by JS.

    Four arms, after the model of openbuild's gate-54 verification — the third
    is the one that proves the exemption stays narrow rather than becoming a
    blanket.
    """

    # arm 1 — the canonical hidden picker is exempt
    def test_fp_a_display_none_file_picker_is_exempt(self):
        # openregister ImportRegister.vue / nldesign admin.php, verbatim shape.
        self.assertEqual(rules(
            '<input ref="fileInput" type="file" accept=".json" '
            'style="display: none" @change="handleFileUpload">'), [])

    def test_fp_an_aria_hidden_input_is_exempt(self):
        # #273's own fixture shape.
        self.assertEqual(rules(
            '<input ref="picker" type="file" :aria-hidden="true" '
            'tabindex="-1" @change="onPick">'), [])

    # arm 2 — remove the hiding attribute and it must fail again
    def test_tp_the_same_file_input_visible_is_still_reported(self):
        self.assertEqual(rules(
            '<input ref="fileInput" type="file" accept=".json" '
            '@change="handleFileUpload">'), ["input-without-label"])

    # arm 3 — THE ABUSE CONTROL. `display:none` must not become a blanket
    # silencer for any control an author would rather not name. Only
    # `type="file"` earns it; a hidden text field is a control that may be
    # shown at any moment by the same JS that hid it.
    def test_tp_display_none_on_a_non_file_input_is_still_reported(self):
        self.assertEqual(rules(
            '<input type="text" v-model="q" style="display: none">'),
            ["input-without-label"])

    def test_tp_display_none_on_a_textarea_is_still_reported(self):
        self.assertEqual(rules(
            '<textarea v-model="q" style="display: none"></textarea>'),
            ["textarea-without-label"])

    # arm 4 — a DYNAMIC aria-hidden proves nothing at scan time
    def test_tp_a_bound_dynamic_aria_hidden_is_not_an_exemption(self):
        self.assertEqual(rules(
            '<input type="text" v-model="q" :aria-hidden="isCollapsed">'),
            ["input-without-label"])

    def test_tp_aria_hidden_false_is_not_an_exemption(self):
        self.assertEqual(rules(
            '<input type="text" v-model="q" aria-hidden="false">'),
            ["input-without-label"])


# --------------------------------------------------------------------------
# Mode 4 — markup that does not ship.
# --------------------------------------------------------------------------
class NonShippingMarkup(unittest.TestCase):
    def test_fp_a_commented_out_input_is_not_a_control(self):
        self.assertEqual(rules('<!-- <input v-model="x" type="text"> -->'), [])

    def test_tp_the_same_input_outside_a_comment_is_reported(self):
        self.assertEqual(rules('<input v-model="x" type="text">'),
                         ["input-without-label"])

    def test_fp_markup_inside_a_string_in_script_is_not_a_control(self):
        src = ("<template>\n<div />\n</template>\n"
               "<script>\nconst tpl = '<input type=\"text\">'\n</script>\n")
        self.assertEqual(cfl.scan_source("Component.vue", src), [])

    def test_tp_the_template_block_is_still_scanned(self):
        src = ('<template>\n<input type="text">\n</template>\n'
               "<script>\nexport default {}\n</script>\n")
        self.assertEqual(len(cfl.scan_source("Component.vue", src)), 1)


# --------------------------------------------------------------------------
# Behaviour that must survive every relaxation above.
# --------------------------------------------------------------------------
class PreservedBehaviour(unittest.TestCase):
    def test_hidden_and_button_inputs_are_exempt(self):
        for t in ("hidden", "submit", "button", "reset", "image"):
            with self.subTest(type=t):
                self.assertEqual(rules(f'<input type="{t}" value="x">'), [])

    def test_a_type_less_input_defaults_to_text_and_is_checked(self):
        self.assertEqual(rules('<input v-model="x">'), ["input-without-label"])

    def test_aria_label_still_satisfies_it(self):
        self.assertEqual(rules('<input type="text" aria-label="Search">'), [])
        self.assertEqual(rules('<input type="text" :aria-label="t(\'a\',\'b\')">'), [])
        self.assertEqual(rules('<input type="text" aria-labelledby="h1">'), [])

    def test_nctextfield_needs_its_label_prop(self):
        self.assertEqual(rules('<NcTextField v-model="x" />'),
                         ["nctextfield-without-label-prop"])
        self.assertEqual(rules('<NcTextField v-model="x" :label="l" />'), [])
        # NcTextField has no default-slot label: slot content must NOT excuse it.
        self.assertEqual(rules('<NcTextField v-model="x">Name</NcTextField>'),
                         ["nctextfield-without-label-prop"])

    def test_textarea_is_checked_and_wrapping_names_it(self):
        self.assertEqual(rules('<textarea v-model="x"></textarea>'),
                         ["textarea-without-label"])
        self.assertEqual(rules(
            '<label><span>Notes</span><textarea v-model="x"></textarea></label>'), [])

    def test_an_attribute_value_containing_a_gt_does_not_split_the_tag(self):
        # `:class="a > b"` used to terminate the tag early for the old
        # flatten-and-regex implementation, corrupting the attribute set.
        self.assertEqual(rules(
            '<input type="text" :class="count > 3 ? \'a\' : \'b\'" aria-label="N">'), [])


class GateIsNotBlind(unittest.TestCase):
    """A directly-asserted floor: a template full of genuinely unnamed
    controls must produce one finding per control. If a future change makes
    the scanner return early — an exception swallowed, a regex that stops
    matching, a template block it fails to find — every ``assertEqual([])``
    above still passes and only this test fails."""

    def test_unnamed_controls_are_all_reported(self):
        found = rules("""
            <div>
                <input v-model="a" type="text">
                <input v-model="b" type="email">
                <textarea v-model="c"></textarea>
                <NcTextField v-model="d" />
                <NcInputField v-model="e" />
                <NcCheckboxRadioSwitch v-model="f" />
            </div>
        """)
        self.assertEqual(sorted(found), sorted([
            "input-without-label",
            "input-without-label",
            "textarea-without-label",
            "nctextfield-without-label-prop",
            "ncinputfield-without-label-prop",
            "nccheckboxradioswitch-without-label-prop",
        ]))


if __name__ == "__main__":
    unittest.main(verbosity=2)
