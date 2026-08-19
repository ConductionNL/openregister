#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_autocomplete (gate-44). Run with:

    python3 scripts/lib/test_check_autocomplete.py

THE CONTROL THAT NAMED THE DEFECT
---------------------------------
`test_a_single_quoted_name_is_the_same_defect` is the fixture experiment run
as an assertion: the double-quoted form fired in both a .vue app and a
PHP-template app, and the byte-for-byte equivalent single-quoted form reported
PASS in both, because the value regex read out of double quotes only.
"""
from __future__ import annotations

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_autocomplete as cac  # noqa: E402


def rules(markup: str, fname: str = "Component.vue") -> list[str]:
    return [line.rsplit("rule=", 1)[1]
            for line in cac.scan_source(fname, markup)]


class ItCatchesTheTextbookCase(unittest.TestCase):
    def test_a_semantic_input_without_autocomplete(self):
        self.assertEqual(rules('<input id="e" type="text" name="email">'),
                         ["semantic-input-without-autocomplete"])

    def test_every_semantic_noun(self):
        for name in ("email", "telephone", "phone", "firstname", "lastname",
                     "address", "street", "city", "postcode", "country",
                     "password", "username", "organization", "birthday"):
            with self.subTest(name=name):
                self.assertEqual(rules(f'<input type="text" name="{name}">'),
                                 ["semantic-input-without-autocomplete"], name)

    def test_a_php_template_input_is_the_same_defect(self):
        self.assertEqual(
            rules('<div id="x"><input type="text" name="email"></div>',
                  "templates/settings/admin.php"),
            ["semantic-input-without-autocomplete"])

    def test_a_single_quoted_name_is_the_same_defect(self):
        # THE MEASURED BLIND SPOT. Identical rendered DOM, identical defect;
        # the pre-fix regex matched double quotes only and reported PASS.
        self.assertEqual(
            rules("<input id='b44-tel' type='text' name='telephone'>"),
            ["semantic-input-without-autocomplete"])

    def test_the_double_quoted_positive_control(self):
        # Absence is what a wrong lookup manufactures for free: the same
        # markup in the quoting style that already worked must still fire, or
        # the assertion above could be passing for the wrong reason.
        self.assertEqual(
            rules('<input id="b44-tel" type="text" name="telephone">'),
            ["semantic-input-without-autocomplete"])


class ItLeavesCorrectAndIrrelevantInputsAlone(unittest.TestCase):
    def test_autocomplete_present(self):
        self.assertEqual(
            rules('<input type="email" name="email" autocomplete="email">'), [])

    def test_a_bound_autocomplete_is_still_an_autocomplete(self):
        for attr in (':autocomplete="mode"', 'v-bind:autocomplete="mode"'):
            with self.subTest(attr=attr):
                self.assertEqual(
                    rules(f'<input type="text" name="email" {attr}>'), [], attr)

    def test_types_with_nothing_to_autofill(self):
        for t in ("hidden", "submit", "button", "reset", "image", "file",
                  "checkbox", "radio", "color", "range"):
            with self.subTest(t=t):
                self.assertEqual(rules(f'<input type="{t}" name="email">'), [], t)

    def test_a_non_semantic_name(self):
        self.assertEqual(rules('<input type="text" name="publicationTitle">'), [])

    def test_an_input_with_no_name_id_or_model(self):
        self.assertEqual(rules('<input type="text">'), [])


class ItReadsOnlyMarkupThatShips(unittest.TestCase):
    def test_a_commented_out_input_is_not_a_control(self):
        self.assertEqual(
            rules('<!-- <input type="text" name="email"> was the old field -->'),
            [])

    def test_an_input_in_a_script_string_is_not_a_control(self):
        self.assertEqual(
            rules('<script>el.innerHTML = \'<input type="text" name="email">\'</script>'),
            [])

    def test_the_positive_control_for_both(self):
        self.assertEqual(rules('<input type="text" name="email"> was the old field'),
                         ["semantic-input-without-autocomplete"])


class TheTagBoundaryIsQuoteAware(unittest.TestCase):
    def test_a_gt_inside_an_attribute_does_not_end_the_tag(self):
        # `[^>]*` ended the tag at the `>` in the expression, so `name=` fell
        # outside the attribute run and the input read as nameless — the
        # false-NEGATIVE half of #259.
        self.assertEqual(
            rules('<input type="text" :class="n > 5 ? \'a\' : \'b\'" name="email">'),
            ["semantic-input-without-autocomplete"])

    def test_and_an_autocomplete_past_that_gt_is_still_honoured(self):
        self.assertEqual(
            rules('<input type="text" :class="n > 5 ? \'a\' : \'b\'" '
                  'name="email" autocomplete="email">'),
            [])


class TheMutantIsTheWholePreFixChecker(unittest.TestCase):
    """The pre-fix heredoc, verbatim from run-hydra-gates.sh, replayed over
    the fixtures above. It must DISAGREE with the current checker on every one
    of them — otherwise the rewrite changed nothing."""

    PRE_FIX_INPUTS = [
        "<input id='b44-tel' type='text' name='telephone'>",
        '<!-- <input type="text" name="email"> was the old field -->',
        '<input type="text" :class="n > 5 ? \'a\' : \'b\'" name="email">',
        # first-name-like-attribute-wins: `id="e"` is not semantic, `name` is
        '<input id="e" type="text" name="email">',
    ]

    def _pre_fix(self, markup: str) -> int:
        import re
        txt = markup.replace('\n', ' ')
        sem = re.compile(
            r'(email|tel(?:ephone)?|phone|firstname|lastname|fullname|address'
            r'|street|city|postal|postcode|zip|country|password|username'
            r'|organization|birthday|dob)', re.IGNORECASE)
        n = 0
        for m in re.finditer(r'<input\b([^>]*)>', txt, re.IGNORECASE):
            attrs = m.group(1) or ''
            if re.search(r'(^|\s)type\s*=\s*"(hidden|submit|button|reset|image'
                         r'|file|checkbox|radio|color|range)"', attrs,
                         re.IGNORECASE):
                continue
            if re.search(r'(^|\s)(:?autocomplete|v-bind:autocomplete)\s*=', attrs):
                continue
            nm = re.search(r'(^|\s)(?:name|id|:name|:id|v-model)\s*=\s*"([^"]+)"',
                           attrs)
            if not nm:
                continue
            if sem.search(nm.group(2)):
                n += 1
        return n

    def test_the_pre_fix_checker_answers_differently(self):
        differed = 0
        for markup in self.PRE_FIX_INPUTS:
            if self._pre_fix(markup) != len(rules(markup)):
                differed += 1
        self.assertEqual(
            differed, len(self.PRE_FIX_INPUTS),
            "the pre-fix implementation agrees with the current one on every "
            "fixture — the rewrite is unverified")


class SemanticTokenBoundaries(unittest.TestCase):
    """.github#319 — the SEMANTIC test was a bare substring alternation, so
    `city` matched inside `capacity` and `tel` inside `hotel`.

    But TOKEN BOUNDARIES ALONE DO NOT FIX IT, which is the part the issue's
    suggested `\\b` anchoring would have missed. All three nldesign findings
    have `email` as a WHOLE hyphen-delimited token:

        nldesign-email-footer-org-name
        nldesign-email-footer-accessibility-url
        nldesign-email-footer-privacy-url

    They are the email-FOOTER settings; the fields collect an organisation
    name and two URLs. What separates them from a real one is which token is
    the NOUN — an identifier names its subject at the end.

    Each false positive below is paired with the true positive it must not
    swallow, and the last class is the abuse control: qualifier tails.
    """

    # -- the false positives, gone ----------------------------------------
    def test_fp_nldesign_email_footer_fields(self):
        for name in ("nldesign-email-footer-org-name",
                     "nldesign-email-footer-accessibility-url",
                     "nldesign-email-footer-privacy-url"):
            with self.subTest(name=name):
                self.assertEqual(
                    rules(f'<input type="text" id="{name}">',
                          "templates/settings/admin.php"), [], name)

    def test_fp_capacity_is_not_city(self):
        for name in ("queue-edit-max-capacity", "queue-new-max-capacity",
                     "max-capacity", "opacity", "velocity", "simplicity",
                     "electricity"):
            with self.subTest(name=name):
                self.assertEqual(
                    rules(f'<input type="number" min="1" name="{name}">'),
                    [], name)

    def test_fp_gzip_is_not_zip_and_hotel_is_not_tel(self):
        for name in ("gzip", "unzip", "hotel", "hostel"):
            with self.subTest(name=name):
                self.assertEqual(rules(f'<input type="text" name="{name}">'),
                                 [], name)

    # -- THE TRUE POSITIVES THIS MUST NOT SWALLOW -------------------------
    def test_tp_a_trailing_semantic_noun_still_fires(self):
        # pipelinq's five real findings, verbatim.
        for name in ("portal-phone", "portal-address", "portal-change-email",
                     "form.granteeEmail", "portal-reset-email", "user-city"):
            with self.subTest(name=name):
                self.assertEqual(
                    rules(f'<input type="text" id="{name}">'),
                    ["semantic-input-without-autocomplete"], name)

    def test_tp_a_noun_followed_by_a_qualifier_still_fires(self):
        # nextcloud-vue CnExportWizard: `:id="fieldIdFor('emailRecipient')"` on
        # an `<input type="email">`. A strict noun-must-be-LAST rule dropped
        # this real finding; the two-token window is why it is back. If the
        # window is ever narrowed to one, this is the test that says so.
        self.assertEqual(
            rules("""<input :id="fieldIdFor('emailRecipient')"
                     v-model="formData.emailRecipient" type="email">"""),
            ["semantic-input-without-autocomplete"])

    def test_tp_camel_case_compounds_still_fire(self):
        for name in ("firstName", "lastName", "fullName", "userName",
                     "zipCode", "granteeEmail"):
            with self.subTest(name=name):
                self.assertEqual(
                    rules(f'<input type="text" name="{name}">'),
                    ["semantic-input-without-autocomplete"], name)

    # -- THE ABUSE CONTROL ------------------------------------------------
    # A generic tail word must let the noun one place back still count —
    # otherwise `postal-code` and `phone-number`, the plainest WCAG 1.3.5
    # fields there are, would go quiet. This is the arm that proves the
    # "noun must be last" rule did not become "anything with a suffix passes".
    def test_abuse_control_a_generic_tail_does_not_hide_the_noun(self):
        for name in ("postal-code", "post-code", "zip-code", "phone-number",
                     "tel-number", "street-address", "email-address"):
            with self.subTest(name=name):
                self.assertEqual(
                    rules(f'<input type="text" name="{name}">'),
                    ["semantic-input-without-autocomplete"], name)

    def test_abuse_control_a_generic_tail_over_a_NON_noun_stays_quiet(self):
        # The other side of the same rule: `org-name` and `privacy-url` have
        # exactly the shape above, and must NOT fire.
        for name in ("org-name", "privacy-url", "accessibility-url",
                     "project-name", "queue-id", "footer-text"):
            with self.subTest(name=name):
                self.assertEqual(rules(f'<input type="text" name="{name}">'),
                                 [], name)


if __name__ == "__main__":
    unittest.main(verbosity=2)
