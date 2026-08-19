#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_link_text (gate-42). Run with:

    python3 scripts/lib/test_check_link_text.py

EVERY RELAXATION SHIPS WITH THE TRUE POSITIVE IT MUST NOT SWALLOW. gate-42 is
the highest-false-positive gate in the a11y family — "Read more" can be
descriptive in context — so each exemption below is paired with the
non-descriptive link that must still fail through it.
"""
from __future__ import annotations

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_link_text as clt  # noqa: E402


def rules(markup: str, fname: str = "Component.vue") -> list[str]:
    return [line.rsplit("rule=", 1)[1]
            for line in clt.scan_source(fname, markup)]


class ItCatchesTheTextbookCase(unittest.TestCase):
    def test_click_here(self):
        self.assertEqual(rules('<a href="/docs">click here</a>'),
                         ["link-text-not-descriptive"])

    def test_read_more_and_friends(self):
        for body in ("Read more", "Learn more", "Details", "Here", "more",
                     "See more", "Continue"):
            with self.subTest(body=body):
                self.assertEqual(rules(f'<a href="/x">{body}</a>'),
                                 ["link-text-not-descriptive"], body)

    def test_an_empty_link_body(self):
        self.assertEqual(rules('<a href="/x"><span class="icon-arrow" /></a>'),
                         ["link-text-not-descriptive"])

    def test_router_link_forms(self):
        self.assertEqual(rules('<router-link to="/x">click here</router-link>'),
                         ["link-text-not-descriptive"])
        self.assertEqual(rules('<RouterLink to="/x">click here</RouterLink>'),
                         ["link-text-not-descriptive"])

    def test_trailing_punctuation_does_not_rescue_it(self):
        self.assertEqual(rules('<a href="/x">Click here!</a>'),
                         ["link-text-not-descriptive"])

    def test_a_php_template_link_is_the_same_defect(self):
        # The whole reason #225/#261 exists: WCAG does not care which
        # templating language produced the DOM.
        self.assertEqual(
            rules('<div id="x"><a href="/docs">click here</a></div>',
                  "templates/settings/admin.php"),
            ["link-text-not-descriptive"])


class ItLeavesDescriptiveLinksAlone(unittest.TestCase):
    def test_descriptive_text(self):
        self.assertEqual(rules('<a href="/docs">Read the publication guide</a>'),
                         [])

    def test_aria_label_names_the_link(self):
        self.assertEqual(
            rules('<a href="/x" aria-label="Open the publication guide">more</a>'),
            [])

    def test_a_BOUND_aria_label_is_still_a_name(self):
        # The `:?`-binds-to-the-first-alternative defect that produced all 22
        # of openbuild's gate-39 findings (#259). Every alternative must
        # accept the bound form, not just the first one.
        for attr in (':aria-label="t(\'app\', \'Open guide\')"',
                     'v-bind:aria-label="label"',
                     ':aria-labelledby="labelId"',
                     'v-bind:aria-labelledby="labelId"'):
            with self.subTest(attr=attr):
                self.assertEqual(rules(f'<a href="/x" {attr}>more</a>'), [], attr)

    def test_an_interpolated_body_is_not_readable_here(self):
        self.assertEqual(rules('<a href="/x">{{ publication.title }}</a>'), [])


class ItReadsOnlyMarkupThatShips(unittest.TestCase):
    def test_a_commented_out_link_is_not_a_link(self):
        # gate-64's defect (#184), the one gate-38 (#247) and gate-41 (#266)
        # each had to ship a fix for: a checker that greps raw text matches
        # every comment. The heredoc this replaced did exactly that.
        self.assertEqual(
            rules('<!-- <a href="/x">click here</a> was the old copy -->'), [])

    def test_a_link_inside_a_script_block_is_not_a_link(self):
        self.assertEqual(
            rules('<script>const s = \'<a href="/x">click here</a>\'</script>'),
            [])

    def test_the_positive_control_for_both(self):
        # Absence is what a wrong lookup manufactures for free. Same two
        # inputs with the wrapper removed MUST fire, or the two assertions
        # above prove nothing.
        self.assertEqual(rules('<a href="/x">click here</a> was the old copy'),
                         ["link-text-not-descriptive"])


class TheTagBoundaryIsQuoteAware(unittest.TestCase):
    def test_a_gt_inside_an_attribute_does_not_end_the_tag(self):
        # `[^>]*` ended a tag at the `>` inside `pin.length >= 6` and hid 19
        # buttons across 6 apps from gate-39 (#259). Same class of parse, same
        # trap.
        self.assertEqual(
            rules('<a href="/x" :title="count > 5 ? a : b">click here</a>'),
            ["link-text-not-descriptive"])

    def test_and_it_still_honours_an_aria_label_past_that_gt(self):
        self.assertEqual(
            rules('<a href="/x" :class="n > 5 ? \'a\' : \'b\'" '
                  'aria-label="Open the guide">more</a>'),
            [])


class TheMutantIsTheWholePreFixChecker(unittest.TestCase):
    """The honest mutant for a repeated defect is the pre-fix implementation.

    gate-42's pre-fix body, verbatim from run-hydra-gates.sh: raw text, no
    comment or script stripping, `[^>]*` attribute runs. Replaying it over the
    fixtures above must produce a DIFFERENT answer from the current checker —
    otherwise the rewrite changed nothing and these tests are decoration.
    """

    PRE_FIX_INPUTS = [
        '<!-- <a href="/x">click here</a> was the old copy -->',
        '<a href="/x" :title="count > 5 ? a : b">click here</a>',
    ]

    def _pre_fix(self, markup: str) -> int:
        import re
        txt = markup.replace('\n', ' ')
        bad = re.compile(
            r'^(click\s*here|here|read\s*more|learn\s*more|more|continue'
            r'|see\s*more|details)\.?$', re.IGNORECASE)
        n = 0
        for m in re.finditer(r'<a\b([^>]*)>(.*?)</a>', txt,
                             re.IGNORECASE | re.DOTALL):
            attrs, body = m.group(1) or '', m.group(2) or ''
            if re.search(r'(:?aria-label|aria-labelledby)\s*=', attrs):
                continue
            if '{{' in body and '}}' in body:
                continue
            body_text = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', body)).strip()
            if not body_text or bad.match(body_text):
                n += 1
        return n

    def test_the_pre_fix_checker_answers_differently(self):
        differed = 0
        for markup in self.PRE_FIX_INPUTS:
            if self._pre_fix(markup) != len(rules(markup)):
                differed += 1
        self.assertEqual(
            differed, len(self.PRE_FIX_INPUTS),
            "the pre-fix implementation agrees with the current one on "
            "every fixture — the rewrite is unverified")


if __name__ == "__main__":
    unittest.main(verbosity=2)
