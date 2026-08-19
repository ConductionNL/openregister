#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_markup_a11y (gate-31 img-alt, gate-32 semantic-controls).

Run with:  python3 scripts/lib/test_check_markup_a11y.py

Every case that must NOT fire has a neighbour built from the same fixture, one
edit apart, that MUST. A gate that has only ever been observed passing is
indistinguishable from a gate that cannot fail — and the whole reason these
two gates needed repair is that they were firing on prose, which is exactly
the failure a careless relaxation converts into firing on nothing.
"""
from __future__ import annotations

import io
import os
import sys
import unittest
from contextlib import redirect_stdout

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_markup_a11y as gate  # noqa: E402


def scan(rule: str, src: str, path: str = "src/components/X.vue") -> list[str]:
    return gate.scan_source(rule, path, src)


# The launchpad file that produced `[gate-31] img-alt: FAIL — 1 <img> tag(s)`
# with no `<img>` anywhere in the component (#220).
LAUNCHPAD = """<template>
  <div class="org-nav-item">
    <CnDashboardIcon :icon="item.icon" />
    <span>{{ item.label }}</span>
  </div>
</template>

<script>
/**
 * OrgNavigationItem
 *
 * `CnDashboardIcon` resolves any value the icon picker emits — a URL
 * (→ `<img>`), an SVG string, or a Material icon name.
 */
export default { name: 'OrgNavigationItem' }
</script>
"""

# openbuild's IconUploadSection shape (#235): two REAL images that already
# carry `:alt`, plus two JSDoc mentions that were reported as images.
OPENBUILD = """<template>
  <div>
    <img v-if="iconLightUrl" :src="iconLightUrl" :alt="t('openbuild', 'Light icon')">
    <img v-if="iconDarkUrl" :src="iconDarkUrl" :alt="t('openbuild', 'Dark icon')">
  </div>
</template>

<script>
export default {
  methods: {
    /**
     * @param {Event} e - The `<img>` `error` event fired when the light icon
     *                    fails to load.
     */
    onLightError(e) { this.iconLightUrl = null },
    /**
     * @param {Event} e - The `<img>` `error` event fired when the dark icon
     *                    fails to load.
     */
    onDarkError(e) { this.iconDarkUrl = null },
  },
}
</script>
"""


class TestImgAlt(unittest.TestCase):
    def test_launchpad_component_reports_nothing(self):
        self.assertEqual(scan("img-alt", LAUNCHPAD), [])

    def test_launchpad_component_with_one_real_bad_image_reports_one(self):
        """Anti-widening control. Same file, one tag added."""
        src = LAUNCHPAD.replace(
            '<span>{{ item.label }}</span>',
            '<img src="/badge.png"><span>{{ item.label }}</span>')
        found = scan("img-alt", src)
        self.assertEqual(len(found), 1, found)
        self.assertIn("/badge.png", found[0])

    def test_openbuild_three_findings_become_zero(self):
        self.assertEqual(scan("img-alt", OPENBUILD, "src/dialogs/IconUploadSection.vue"), [])

    def test_openbuild_with_alt_deleted_from_one_image_reports_that_one(self):
        """The measurement that separates 'fixed' from 'switched off'."""
        src = OPENBUILD.replace(' :alt="t(\'openbuild\', \'Dark icon\')"', '')
        found = scan("img-alt", src, "src/dialogs/IconUploadSection.vue")
        self.assertEqual(len(found), 1, found)
        self.assertIn("iconDarkUrl", found[0])

    def test_a_finding_names_the_tag_with_its_attributes(self):
        """The bare `<img>` in a log was the tell that a comment was scored."""
        src = '<template><img src="/a.png" class="x"></template>'
        found = scan("img-alt", src)
        self.assertEqual(len(found), 1)
        self.assertIn('src="/a.png"', found[0])
        self.assertNotEqual(found[0].split(": ", 1)[1], "<img>")

    def test_alt_written_after_an_arrow_function_is_seen(self):
        """`[^>]*` ended the tag at the arrow and lost every later prop."""
        src = ('<template><img :src="items.find(i => i.id === id).url" '
               ':alt="t(\'app\', \'Item\')"></template>')
        self.assertEqual(scan("img-alt", src), [])

    def test_the_same_tag_without_alt_still_fires(self):
        src = '<template><img :src="items.find(i => i.id === id).url"></template>'
        self.assertEqual(len(scan("img-alt", src)), 1)

    def test_commented_out_image_is_not_an_image(self):
        src = '<template><!-- <img src="/old.png"> --></template>'
        self.assertEqual(scan("img-alt", src), [])

    def test_php_template_image_is_in_scope(self):
        """#225: WCAG does not care which templating language made the DOM."""
        src = "<?php // renders an <img> when set ?>\n<img src=\"/logo.png\">\n"
        found = scan("img-alt", src, "templates/settings/admin.php")
        self.assertEqual(len(found), 1, found)
        self.assertIn("/logo.png", found[0])

    def test_php_comment_mentioning_img_is_not_an_image(self):
        src = "<?php // this template renders no <img> at all ?>\n<div id=x></div>\n"
        self.assertEqual(scan("img-alt", src, "templates/settings/admin.php"), [])

    def test_alt_empty_is_accepted(self):
        """Decorative images are declared with alt=\"\" — unchanged rule."""
        self.assertEqual(scan("img-alt", '<template><img src="/d.png" alt=""></template>'), [])

    def test_line_number_addresses_the_original_file(self):
        src = '<template>\n\n  <img src="/a.png">\n</template>\n'
        self.assertTrue(scan("img-alt", src)[0].startswith("src/components/X.vue:3:"))


class TestSemanticControls(unittest.TestCase):
    # softwarecatalog's repaired element plus the comment that described what
    # it replaced. Pre-fix, gate-32 scored the COMMENT and rewording it
    # cleared the gate with the markup byte-identical (#236).
    REPAIRED = """<template>
  <!-- role/tabindex/keydown rather than a bare <div @click>: picking the
       merge target is the consequential choice in this dialog, so it has to
       be reachable from the keyboard. -->
  <div v-for="obj in availableObjects"
       :key="obj.id"
       role="option"
       tabindex="0"
       @click="selectTargetObject(obj)"
       @keydown.enter.prevent="selectTargetObject(obj)">
    {{ obj.title }}
  </div>
</template>
"""

    def test_the_repaired_element_reports_nothing(self):
        self.assertEqual(scan("semantic-controls", self.REPAIRED), [])

    def test_the_comment_alone_reports_nothing(self):
        src = "<template>\n  <!-- a bare <div @click> is what this replaced -->\n  <p>x</p>\n</template>\n"
        self.assertEqual(scan("semantic-controls", src), [])

    def test_a_real_bare_click_div_below_that_comment_still_fires(self):
        """Anti-widening control, and the direction that matters most: a bad
        element must not be explainable away by a comment above it."""
        src = ("<template>\n"
               "  <!-- a bare <div @click> is what this replaced -->\n"
               '  <div @click="pick(obj)">x</div>\n'
               "</template>\n")
        found = scan("semantic-controls", src)
        self.assertEqual(len(found), 1, found)
        self.assertIn("role=", found[0])
        self.assertIn("tabindex=", found[0])
        self.assertIn("@keydown", found[0])

    def test_removing_one_of_the_trio_from_the_repaired_element_fires(self):
        src = self.REPAIRED.replace('       tabindex="0"\n', "")
        found = scan("semantic-controls", src)
        self.assertEqual(len(found), 1, found)
        self.assertIn("missing[tabindex=]", found[0])

    def test_anchor_with_href_is_exempt(self):
        src = '<template><a href="/x" @click="go">x</a></template>'
        self.assertEqual(scan("semantic-controls", src), [])

    def test_anchor_without_href_is_not_exempt(self):
        src = '<template><a @click="go">x</a></template>'
        self.assertEqual(len(scan("semantic-controls", src)), 1)

    def test_click_stop_with_no_handler_is_event_management(self):
        src = "<template><div @click.stop><span/></div></template>"
        self.assertEqual(scan("semantic-controls", src), [])

    def test_click_stop_with_a_real_handler_is_not_exempt(self):
        src = '<template><div @click.stop="pick(o)"><span/></div></template>'
        self.assertEqual(len(scan("semantic-controls", src)), 1)

    def test_click_written_after_an_arrow_function_is_seen(self):
        """The truncation hid violations too — this is a finding the pre-fix
        gate could not report at all."""
        src = ('<template><div :title="opts.find(o => o.id === id).label" '
               '@click="pick()">x</div></template>')
        self.assertEqual(len(scan("semantic-controls", src)), 1)

    def test_component_wrappers_are_out_of_scope(self):
        src = '<template><NcButton @click="go">x</NcButton></template>'
        self.assertEqual(scan("semantic-controls", src), [])


class TestCli(unittest.TestCase):
    def test_unknown_rule_is_an_error_not_an_empty_answer(self):
        buf = io.StringIO()
        with redirect_stdout(buf):
            rc = gate.main(["check_markup_a11y.py", "--rule", "nonsense", "x.vue"])
        self.assertEqual(rc, 2)
        self.assertEqual(buf.getvalue(), "")

    def test_missing_arguments_is_an_error(self):
        self.assertEqual(gate.main(["check_markup_a11y.py"]), 2)

    def test_an_unreadable_file_is_skipped_not_fatal(self):
        buf = io.StringIO()
        with redirect_stdout(buf):
            rc = gate.main(["check_markup_a11y.py", "--rule", "img-alt", "/nope/x.vue"])
        self.assertEqual(rc, 0)
        self.assertEqual(buf.getvalue(), "")


if __name__ == "__main__":
    unittest.main()
