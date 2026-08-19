#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Does a Nextcloud PHP template OWN THE DOCUMENT, or is it a fragment?

WHY THIS EXISTS
---------------
Nextcloud's `OCP\\Template` renderer SUBSTITUTES an app template into core's
own page. A template that emits no `<html>` / `<body>` is a fragment inside a
document core already built — core emitted that page's landmarks, its `lang`
attribute and its skip link long before the template's first byte.

gate-38 was asking every `templates/settings/*.php` in the fleet for a skip
link. Measured: NOT ONE of the 30 app templates in this fleet owns a document
— the typical body is literally `<div id="procest-settings"></div>`, a Vue
mount point — so the finding was universal and its only remedy was to inject
a SECOND "skip to content" anchor ahead of core's real one. A WCAG 2.4.1
regression demanded by a WCAG 2.4.1 gate (#214, #216, #227).

WHY A HELPER AND NOT A GREP
---------------------------
The first cut of this check was `grep -iE '<(html|body)\\b'`, and the very
first fixture it ran against defeated it: the fixture's own explanatory
comment contained the words `<html>`, so a bare mount point was classified as
a page root. That is the gate-64 defect verbatim — a checker that greps a
string literal misses every constant and matches every comment, failing both
ways at once — so the classification is done on EMITTED MARKUP only:

  * `<?php … ?>` regions are removed. Anything inside them is code, a
    docblock or a `//` comment; none of it is markup the browser receives.
  * `<!-- … -->` HTML comments are removed. Commented-out markup ships
    nothing.

GATE-41 SHARES THIS, IT DOES NOT ADD A THIRD ANSWER (#266)
----------------------------------------------------------
gate-41 (html-lang, WCAG 3.1.1) asked the same question of the same files
with `re.search(r'<html\\b([^>]*)>', txt)` over the RAW text, and had gate-38's
pre-fix defect verbatim:

    <?php
    // This mount point is substituted into core's page. Core emitted the
    // <html> element for it, with its lang attribute, long before this file.
    ?>
    <div id="app-settings"></div>

    [gate-41] html-lang: FAIL — 1 <html> tag(s) without lang=

The file contains no `<html>` element; the gate matched the comment
explaining that it doesn't. It fails the other way too — a commented-out
`<html lang="en">` would VOUCH for a template that really does emit an
unlangged one. `--html-lang` below answers it from `emitted_markup`, so the
two gates cannot drift into disagreeing about what a template emits.

Usage:
    php_template_scope.py --owns-document <file>   # exit 0 = owns, 1 = fragment
    php_template_scope.py --classify <file>...     # "<path>: page-root|fragment"
    php_template_scope.py --html-lang <file>...    # SC 3.1.1 findings
"""
from __future__ import annotations

import re
import sys

# `<?php … ?>` and the short echo form `<?= … ?>`. An unterminated opener runs
# to end of file, which is the language's own rule and the common shape of a
# template whose PHP header is followed by nothing but more PHP.
PHP_BLOCK = re.compile(r'<\?(?:php\b|=)?.*?(?:\?>|\Z)', re.DOTALL | re.IGNORECASE)
HTML_COMMENT = re.compile(r'<!--.*?-->', re.DOTALL)

DOCUMENT_TAG = re.compile(r'<(?:html|body)\b', re.IGNORECASE)
# The opening `<html>` element and its attribute text. Quote-aware, so a `>`
# inside an attribute value does not end the tag — the `[^>]*` shape it
# replaces is the same character-class-as-delimiter bug as gate-9's `[^)]*`
# (#198) and gate-12's (#236).
HTML_TAG = re.compile(
    r'<html\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>', re.IGNORECASE | re.DOTALL)
LANG_ATTR = re.compile(r'(^|\s)(?:xml:)?lang\s*=', re.IGNORECASE)


def emitted_markup(src: str) -> str:
    """The template's output shape: PHP regions and HTML comments removed.

    Order matters. Comments are stripped AFTER php blocks, because a `?>`
    inside an HTML comment really does close the block in PHP, and pretending
    otherwise would swallow markup that does ship.
    """
    return HTML_COMMENT.sub(' ', PHP_BLOCK.sub(' ', src))


def owns_document(src: str) -> bool:
    """True when the template emits `<html>` or `<body>` itself.

    Only such a template is rendered outside core's shell, and only such a
    template can carry a bypass mechanism (SC 2.4.1) or a `lang` attribute
    (SC 3.1.1) of its own.
    """
    return bool(DOCUMENT_TAG.search(emitted_markup(src)))


def html_lang_findings(path: str, src: str) -> list[str]:
    """SC 3.1.1 findings for one template: an emitted `<html>` with no `lang`.

    Asked of EMITTED MARKUP, so neither a PHP comment naming the tag nor a
    commented-out `<html lang="en">` can decide it. Every emitted `<html>` is
    checked, not just the first — a template with two would previously have
    been judged by whichever came first.
    """
    out = []
    for m in HTML_TAG.finditer(emitted_markup(src)):
        if LANG_ATTR.search(m.group(1) or ''):
            continue
        out.append(f'{path}: <html> rule=html-tag-without-lang')
    return out


def _read(path: str) -> str:
    with open(path, encoding='utf-8', errors='replace') as f:
        return f.read()


def main(argv: list[str]) -> int:
    if len(argv) >= 3 and argv[1] == '--owns-document':
        try:
            return 0 if owns_document(_read(argv[2])) else 1
        except OSError:
            # Unreadable is NOT "fragment". Refuse rather than answer.
            print(f'php_template_scope: cannot read {argv[2]}', file=sys.stderr)
            return 2
    if len(argv) >= 3 and argv[1] == '--classify':
        for path in argv[2:]:
            try:
                kind = 'page-root' if owns_document(_read(path)) else 'fragment'
            except OSError:
                kind = 'unreadable'
            print(f'{path}: {kind}')
        return 0
    if len(argv) >= 3 and argv[1] == '--html-lang':
        for path in argv[2:]:
            try:
                src = _read(path)
            except OSError:
                # Unreadable is NOT clean. Say so on stderr and exit non-zero
                # so the caller reports a wiring failure rather than a pass.
                print(f'php_template_scope: cannot read {path}', file=sys.stderr)
                return 2
            for line in html_lang_findings(path, src):
                print(line)
        return 0
    print(__doc__, file=sys.stderr)
    return 2


if __name__ == '__main__':
    sys.exit(main(sys.argv))
