#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Tests for php_template_scope (gate-38's page-root / fragment classifier).

    python3 scripts/lib/test_php_template_scope.py

WHY EACH CASE IS HERE
---------------------
gate-38's PHP arm reported 8 templates across 6 apps, and every one was a
fragment whose page — and whose skip link — belongs to Nextcloud core. The
narrowing that removes those findings is the single question "does this file
emit a document?", so this suite is where that question is held honest.

The comment cases are not hypothetical padding. The FIRST implementation was
`grep -iE '<(html|body)\\b'`, and the first fixture it met defeated it: the
fixture's own explanatory comment said `<html>`, so a bare mount point
classified as a page root and the whole fix silently did nothing. Both
comment shapes are pinned below.
"""
from __future__ import annotations

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import php_template_scope as pts  # noqa: E402


class Fragments(unittest.TestCase):
    """Everything here is rendered INTO a page core already built."""

    def test_the_fleet_mount_point(self):
        # procest/templates/settings/admin.php, verbatim shape. This is the
        # single most common template in the fleet.
        self.assertFalse(pts.owns_document(
            "<?php\nscript('procest', 'admin');\n?>\n"
            '<div id="procest-settings"></div>\n'))

    def test_a_long_fragment_with_real_markup_is_still_a_fragment(self):
        # nldesign/templates/settings/admin.php is 502 lines of real controls
        # and still owns no document.
        self.assertFalse(pts.owns_document(
            '<div id="nldesign-settings" class="section">\n'
            '  <h2>NL Design System Theme</h2>\n'
            '  <label for="ts">Design token set</label>\n'
            '  <select id="ts"><option>a</option></select>\n'
            '</div>\n'))

    def test_a_php_comment_naming_html_does_not_make_it_a_page_root(self):
        # THE REGRESSION THAT MOTIVATED THIS HELPER. A `//` comment inside a
        # PHP block explaining that core emitted the <html> element must not
        # be read as this file emitting one.
        self.assertFalse(pts.owns_document(
            "<?php\n"
            "// Core emitted this page's <html> and <body> long before this\n"
            "// file's first byte; this template is only a mount point.\n"
            "?>\n"
            '<div id="fixture-settings"></div>\n'))

    def test_a_docblock_naming_body_does_not_make_it_a_page_root(self):
        self.assertFalse(pts.owns_document(
            "<?php\n/**\n * Substituted into core's <body>.\n */\n?>\n"
            '<div id="x"></div>\n'))

    def test_commented_out_markup_ships_nothing(self):
        self.assertFalse(pts.owns_document(
            '<!-- <html><body>an older, abandoned standalone page</body></html> -->\n'
            '<div id="x"></div>\n'))

    def test_an_all_php_file_owns_no_document(self):
        self.assertFalse(pts.owns_document("<?php\nreturn ['a' => 1];\n"))

    def test_empty_file(self):
        self.assertFalse(pts.owns_document(''))


class PageRoots(unittest.TestCase):
    """A template that emits the document is in scope and must stay in scope.

    If these ever go false the gate has stopped asking anyone the question,
    and gate-38's PHP arm is decoration.
    """

    def test_a_standalone_document(self):
        self.assertTrue(pts.owns_document(
            '<!DOCTYPE html>\n<html lang="en">\n<head><title>x</title></head>\n'
            '<body><div id="app"></div></body>\n</html>\n'))

    def test_body_alone_is_enough(self):
        # Some standalone templates are included after a partial header.
        self.assertTrue(pts.owns_document('<body class="public">\n<p>hi</p>\n'))

    def test_markup_after_a_closed_php_block_is_still_seen(self):
        # The PHP-stripper must not eat the rest of the file. A `?>` closes
        # exactly one block.
        self.assertTrue(pts.owns_document(
            "<?php\n$title = 'x';\n?>\n<html lang=\"en\"><body>hi</body></html>\n"))

    def test_html_between_two_php_blocks(self):
        self.assertTrue(pts.owns_document(
            "<?php $a = 1; ?>\n<html lang=\"en\">\n<?php echo $a; ?>\n"
            "<body>hi</body></html>\n"))

    def test_uppercase_tags(self):
        self.assertTrue(pts.owns_document('<HTML LANG="en"><BODY>hi</BODY></HTML>'))

    def test_a_real_comment_next_to_a_real_document(self):
        # Stripping comments must not strip the document with them.
        self.assertTrue(pts.owns_document(
            '<!-- a standalone public page; core does not wrap this -->\n'
            '<html lang="nl"><body><p>hi</p></body></html>\n'))


class UnterminatedPhp(unittest.TestCase):
    def test_an_unclosed_php_opener_swallows_the_rest(self):
        # PHP's own rule, and the common shape of a pure-logic template.
        self.assertFalse(pts.owns_document("<?php\n// <html> mentioned here\n$x = 1;\n"))


class HtmlLang(unittest.TestCase):
    """gate-41 (SC 3.1.1), #266 — the same defect gate-38 shipped a fix for.

    The reproduction from the issue is a mount point whose PHP comment
    mentions the tag. Both directions are asserted, because a checker that
    greps raw text fails both ways at once: it fires on prose, and it is
    SATISFIED by prose.
    """

    FRAGMENT = (
        "<?php\n"
        "// This mount point is substituted into core's page. Core emitted the\n"
        "// <html> element for it, with its lang attribute, long before this file.\n"
        "?>\n"
        '<div id="app-settings"></div>\n'
    )

    def test_a_php_comment_naming_the_tag_is_not_the_tag(self):
        self.assertEqual(pts.html_lang_findings('templates/fragment.php', self.FRAGMENT), [])

    def test_a_template_that_really_emits_an_unlangged_html_is_reported(self):
        """The positive control. Without it the fix is indistinguishable from
        deleting the gate — and gate-41 is quiet across the fleet today
        because all 30 app templates are fragments, so 'no findings' proves
        nothing on its own."""
        src = "<?php // standalone ?>\n<html>\n<body>hi</body>\n</html>\n"
        self.assertEqual(len(pts.html_lang_findings('templates/page.php', src)), 1)

    def test_a_lang_attribute_satisfies_it(self):
        src = '<html lang="en">\n<body>hi</body>\n</html>\n'
        self.assertEqual(pts.html_lang_findings('templates/page.php', src), [])

    def test_a_commented_out_lang_does_not_vouch_for_a_real_tag(self):
        """The other direction #266 names, and the more dangerous one."""
        src = '<!-- <html lang="en"> -->\n<html>\n<body>hi</body>\n</html>\n'
        self.assertEqual(len(pts.html_lang_findings('templates/page.php', src)), 1)

    def test_an_html_tag_written_only_inside_a_php_string_is_not_emitted(self):
        src = "<?php echo '<html>'; ?>\n<div id=x></div>\n"
        self.assertEqual(pts.html_lang_findings('templates/x.php', src), [])

    def test_every_emitted_html_tag_is_checked_not_just_the_first(self):
        src = '<html lang="en"></html>\n<html></html>\n'
        self.assertEqual(len(pts.html_lang_findings('templates/two.php', src)), 1)

    def test_a_greater_than_in_an_attribute_value_does_not_end_the_tag(self):
        src = '<html data-expr="a > b" lang="en">\n<body>x</body>\n</html>\n'
        self.assertEqual(pts.html_lang_findings('templates/page.php', src), [])

    def test_html_lang_cli_reports_an_unreadable_file_rather_than_passing_it(self):
        self.assertEqual(pts.main(['x', '--html-lang', '/nope/does-not-exist.php']), 2)


class Cli(unittest.TestCase):
    """The bash caller keys on the EXIT STATUS, so the status is a contract.

    Gate-19 returned a finding COUNT as an exit status and lost 256 findings
    to a byte; an exit status this gate reads must be tested as an interface,
    not assumed.
    """

    def _write(self, tmp, name, content):
        p = os.path.join(tmp, name)
        with open(p, 'w', encoding='utf-8') as f:
            f.write(content)
        return p

    def test_exit_codes(self):
        import tempfile
        with tempfile.TemporaryDirectory() as tmp:
            root = self._write(tmp, 'root.php', '<html lang="en"><body>hi</body></html>')
            frag = self._write(tmp, 'frag.php', '<div id="x"></div>')
            self.assertEqual(pts.main(['x', '--owns-document', root]), 0)
            self.assertEqual(pts.main(['x', '--owns-document', frag]), 1)
            # A file that cannot be read is NOT silently "fragment" — that
            # would drop it from scope and read as a pass.
            self.assertEqual(
                pts.main(['x', '--owns-document', os.path.join(tmp, 'nope.php')]), 2)


if __name__ == '__main__':
    unittest.main(verbosity=2)
