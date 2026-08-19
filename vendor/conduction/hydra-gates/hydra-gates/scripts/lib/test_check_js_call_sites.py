#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for check_js_call_sites (gate-34 window-confirm, gate-58 networkidle).

Run with:  python3 scripts/lib/test_check_js_call_sites.py

The four arms of #224 are reproduced literally, because the issue reported
them as a control set and losing either control makes the fix unfalsifiable:
arm 2 is what proves arm 1's finding was the comment, and arm 4 is what proves
the probe can still fire after arm 3 is fixed.
"""
from __future__ import annotations

import io
import os
import sys
import unittest
from contextlib import redirect_stdout

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import check_js_call_sites as gate  # noqa: E402


def dialog(src: str, path: str = "src/components/X.vue") -> list[str]:
    return gate.scan_source("native-dialog", path, src)


def idle(src: str, path: str = "tests/e2e/a.spec.ts") -> list[str]:
    return gate.scan_source("networkidle", path, src)


class TestNativeDialogTheFourArms(unittest.TestCase):
    ARM1 = """<template>
	<div>
		<!-- This component deliberately avoids window.confirm() and uses NcDialog. -->
		<NcDialog :open="open" />
	</div>
</template>
"""
    ARM2 = """<template>
	<div>
		<NcDialog :open="open" />
	</div>
</template>
"""
    ARM3 = """<script>
export default {
	methods: {
		destroyAll() {
			if (!window['confirm']('Delete everything?')) {
				return
			}
			this.destroy()
		},
	},
}
</script>
"""
    ARM4 = ARM3.replace("window['confirm']", "window.confirm")

    def test_arm1_comment_only_is_not_a_finding(self):
        self.assertEqual(dialog(self.ARM1), [])

    def test_arm2_control_the_same_file_without_the_comment(self):
        """If arm 2 ever produced a finding, arm 1's zero would mean nothing."""
        self.assertEqual(dialog(self.ARM2), [])

    def test_arm3_bracket_access_is_a_native_dialog(self):
        """The false GREEN. On doriath this hid a cascading delete."""
        found = dialog(self.ARM3)
        self.assertEqual(len(found), 1, found)
        self.assertIn("window['confirm']", found[0])

    def test_arm4_control_the_dotted_spelling_still_fires(self):
        self.assertEqual(len(dialog(self.ARM4)), 1)

    def test_arm1_plus_a_real_call_reports_only_the_call(self):
        """The two halves together: the comment stays silent, the code does not."""
        src = self.ARM1 + self.ARM3
        found = dialog(src)
        self.assertEqual(len(found), 1, found)
        self.assertIn("confirm", found[0])


class TestNativeDialogSpellings(unittest.TestCase):
    def test_destructuring_from_window(self):
        src = "<script>const { confirm } = window\nconfirm('x')\n</script>"
        self.assertEqual(len(dialog(src)), 1)

    def test_double_quoted_bracket_access(self):
        src = '<script>window["prompt"]("name")</script>'
        self.assertEqual(len(dialog(src)), 1)

    def test_alias_without_a_call_is_still_the_native_api(self):
        src = "<script>const c = window.confirm\n</script>"
        self.assertEqual(len(dialog(src)), 1)

    def test_a_feature_detection_guard_is_not_a_second_dialog(self):
        """MEASURED ON openbuild BEFORE THIS LANDED.

        Every native dialog in that repo is written with a guard on the line
        above the call. The first cut of this rule accepted a bare reference,
        so 7 defects were reported as 14 findings — a count that is not a
        defect count (#254). A guard is a truthiness test, not a use; only an
        ALIAS (a binding, whose call site is elsewhere) counts without a call.
        """
        src = (
            "<script>\n"
            "const ok = typeof window !== 'undefined' && window.confirm\n"
            "\t? window.confirm(t('openbuild', 'Delete this automation?'))\n"
            "\t: true\n"
            "</script>\n"
        )
        found = dialog(src)
        self.assertEqual(len(found), 1, found)
        self.assertIn(":3:", found[0], "the CALL is the finding, not the guard")

    def test_the_guard_alone_with_no_call_is_not_reported(self):
        src = "<script>const has = typeof window !== 'undefined' && window.confirm\n</script>"
        self.assertEqual(dialog(src), [])

    def test_a_call_assigned_to_a_variable_is_reported_once(self):
        src = "<script>const r = window.confirm('x')\n</script>"
        self.assertEqual(len(dialog(src)), 1)

    def test_an_aliased_bracket_access_counts(self):
        src = "<script>const c = window['confirm']\nc('x')\n</script>"
        self.assertEqual(len(dialog(src)), 1)

    def test_a_component_method_named_confirm_is_not_reported(self):
        """The gate's own remedy is a `confirm()` method on an NcDialog
        wrapper. Reporting it would make the gate unclosable."""
        src = "<script>await this.confirm('Delete?')\nawait dialog.confirm()\n</script>"
        self.assertEqual(dialog(src), [])

    def test_a_string_containing_the_spelling_is_not_a_call(self):
        src = "<script>const doc = 'do not use window.confirm here'\n</script>"
        self.assertEqual(dialog(src), [])

    def test_inline_handler_in_a_template_is_a_call(self):
        src = '<template><button @click="window.confirm(\'x\') && go()">g</button></template>'
        self.assertEqual(len(dialog(src)), 1)

    def test_a_php_template_inline_script_is_in_scope(self):
        """#225: a native dialog from a PHP template breaks theming too."""
        src = "<?php // window.confirm is never used here ?>\n<script>window.confirm('x')</script>\n"
        found = dialog(src, "templates/settings/admin.php")
        self.assertEqual(len(found), 1, found)

    def test_a_php_comment_naming_it_is_not_a_call(self):
        src = "<?php // window.confirm is never used here ?>\n<div id=x></div>\n"
        self.assertEqual(dialog(src, "templates/settings/admin.php"), [])

    def test_prose_in_a_template_text_node_is_not_a_call(self):
        """A text node is not an expression; only attribute values are."""
        src = "<template><p>Never use window.confirm in this app.</p></template>"
        self.assertEqual(dialog(src), [])

    def test_line_number_addresses_the_original_file(self):
        src = "<script>\n\n\nwindow.confirm('x')\n</script>\n"
        self.assertTrue(dialog(src)[0].startswith("src/components/X.vue:4:"))

    def test_one_construct_is_reported_once(self):
        """`window[` matches the anchor once; a double count would inflate a
        security-adjacent number, which is how gate-22 shipped 3 findings for
        one defect (#254)."""
        src = "<script>window['confirm']('a')\n</script>"
        self.assertEqual(len(dialog(src)), 1)


class TestNetworkidle(unittest.TestCase):
    LARPINGAPP = """// ADR-074 rule 4: `networkidle` never settles on Nextcloud — the
// notification poll keeps the network permanently busy. This was the LAST
// live `waitForLoadState('networkidle')` in the suite; every other mention
// is a comment warning against it.
await page.waitForLoadState('domcontentloaded')
"""

    def test_the_larpingapp_comment_block_reports_nothing(self):
        self.assertEqual(idle(self.LARPINGAPP), [])

    def test_the_same_file_with_one_live_call_reports_it(self):
        """Anti-widening control on the real file shape."""
        src = self.LARPINGAPP + "await page.waitForLoadState('networkidle')\n"
        found = idle(src)
        self.assertEqual(len(found), 1, found)
        self.assertIn("networkidle", found[0])

    def test_a_live_call_with_a_trailing_comment_still_matches(self):
        """A line-position filter (the cheap fix #230 sketched) loses this."""
        src = "await page.waitForLoadState('networkidle') // TODO: remove\n"
        self.assertEqual(len(idle(src)), 1)

    def test_a_mention_after_code_on_the_same_line_is_not_a_call(self):
        src = "const x = 1 // waitForLoadState('networkidle') is banned\n"
        self.assertEqual(idle(src), [])

    def test_a_block_comment_interior_line_is_not_a_call(self):
        """This line starts with a letter, so `grep -v '^[0-9]+:\\s*(//|\\*)'`
        would have counted it."""
        src = "/*\nwaitForLoadState('networkidle') must never be used.\n*/\nawait f()\n"
        self.assertEqual(idle(src), [])

    def test_wait_until_form_is_matched(self):
        src = "await page.goto(u, { waitUntil: 'networkidle' })\n"
        self.assertEqual(len(idle(src)), 1)

    def test_the_exclude_marker_still_suppresses(self):
        src = "await page.waitForLoadState('networkidle') // e2e-networkidle exclude legacy upload probe\n"
        self.assertEqual(idle(src), [])

    def test_the_exclude_marker_on_a_neighbouring_line_does_not_suppress(self):
        src = ("// e2e-networkidle exclude something else entirely\n"
               "await page.waitForLoadState('networkidle')\n")
        self.assertEqual(len(idle(src)), 1)

    def test_the_pattern_inside_a_string_literal_is_not_a_call(self):
        """A helper that documents the banned call in a message string."""
        src = "throw new Error(\"waitForLoadState('networkidle') is banned by ADR-074\")\n"
        self.assertEqual(idle(src), [])

    def test_line_number_addresses_the_original_file(self):
        src = "\n\n\nawait page.waitForLoadState('networkidle')\n"
        self.assertTrue(idle(src)[0].startswith("tests/e2e/a.spec.ts:4:"))


class TestCli(unittest.TestCase):
    def test_unknown_rule_is_an_error_not_an_empty_answer(self):
        buf = io.StringIO()
        with redirect_stdout(buf):
            rc = gate.main(["check_js_call_sites.py", "--rule", "nope", "x.ts"])
        self.assertEqual(rc, 2)
        self.assertEqual(buf.getvalue(), "")

    def test_an_unreadable_file_is_skipped_not_fatal(self):
        buf = io.StringIO()
        with redirect_stdout(buf):
            rc = gate.main(["check_js_call_sites.py", "--rule", "networkidle", "/nope.ts"])
        self.assertEqual(rc, 0)
        self.assertEqual(buf.getvalue(), "")


if __name__ == "__main__":
    unittest.main()
