#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Where does CODE live in a source file, and where does PROSE live?

WHY THIS EXISTS
---------------
Nine gates in this package decided a question about code by grepping the raw
bytes of a file. Prose is made of the same bytes, so every one of them failed
in BOTH directions at once — the shape first written down in #184:

    "a checker that greps a STRING LITERAL misses every constant and matches
     every comment — it fails BOTH ways at once."

Measured instances, all of them live in the fleet:

  #191  gate-48  a REMOVED COMMENT naming `#[NoCSRFRequired]` read as a
                 removed attribute — nldesign red for a docblock rewrite.
  #196  gate-5   a docblock sentence saying `#[NoAdminRequired]` is
                 deliberately NOT used SATISFIED the auth gate. A false
                 NEGATIVE on a security gate: prose switched the gate off.
  #220  gate-31  `` `<img>` `` inside a JSDoc comment in the <script> block
                 reported as an image with no alt (launchpad).
  #235  gate-31  same, three times, on openbuild. The finding text was the
                 tell: a real tag prints with attributes, these printed as
                 the bare four characters `<img>`.
  #224  gate-34  false RED on a comment saying the component avoids
                 window.confirm, AND false GREEN on `window['confirm']()`.
  #226  gate-3   a `run()` that delegates to one helper read as a stub, and
                 the gate was closable by an inert `$unused = 1;`.
  #230  gate-58  a comment WARNING AGAINST `networkidle` counted as a use of
                 it — the better the documentation, the redder the repo.
  #236  gate-12  `<NcSelect[^>]*>` truncated at the `>` of `option =>`.
  #236  gate-32  a comment describing the `<div @click>` an element replaced
                 scored as that `<div @click>`.
  #266  gate-41  a PHP comment mentioning `<html>` made a mount point read
                 as a page root.

THE SHAPE OF THE FIX
--------------------
Two precedents in this package already got it right, and this module is their
generalisation rather than a third dialect:

  * `check_apphost_autoload_prelude.strip_comments` (#184) — knows that `#`
    opens a PHP comment but `#[` opens an ATTRIBUTE.
  * `check_e2e_coverage._code_mask` (#249) — tokenises ONCE, blanks comments,
    string contents and regex literals, and PRESERVES OFFSETS so a line
    number computed on the mask still names the right line of the original.
    It deliberately KEEPS string delimiters, because "is argument 1 a string
    literal" is sometimes the only discriminator available.

That second one is the strongest available model, so `js_mask` below is it,
generalised by one keyword argument.

WHY GATE-19 STILL CARRIES ITS OWN COPY
--------------------------------------
`check_e2e_coverage._code_mask` was NOT deleted in favour of this one. Ripping
a 180-line tokeniser out from under the gate whose 60-assertion suite is the
only thing that proves it correct is a bad trade in a change already touching
nine gates. What replaces the deletion is a DRIFT TEST: the shared mask and
gate-19's are asserted byte-identical over a corpus in
`test_source_scope.py::TestSharedWithGate19`, including gate-19's own
fixtures. Two copies that are proven equal are a maintenance cost; two copies
that MIGHT differ are a defect. The test is mutation-checked — flip one branch
of either copy and it goes red — so this is a coupling, not a comment.

MASK, NOT STRIP
---------------
Every function here returns a string the SAME LENGTH as its input, with the
out-of-scope regions replaced by spaces and newlines kept. That is what lets
a gate:

  * report a line number computed on the mask, and
  * read a suppression marker (`e2e-networkidle exclude …`) out of the
    ORIGINAL text at the same line,

which matters because every suppression marker in this package lives in a
comment — the exact region the mask blanks.

TWO TRAPS THAT ALREADY BIT AGENTS ON THIS WORK
----------------------------------------------
1. A fixture written to DISPROVE a rule exempted itself with its own
   explanatory docblock — the very bug under repair. The fixtures in
   test_source_scope.py keep that trap deliberately.
2. When a gate ignores your planted true positive, suspect the PLANT. Two of
   an earlier agent's first plants were wrong and the gates were right.

Usage as a CLI (the bash gates call it this way):

    source_scope.py --mask php|js|js-comments|markup <file>   # masked text
    source_scope.py --mask <kind> -                           # from stdin
    source_scope.py --tags NcSelect,img <file>                # open tags
"""
from __future__ import annotations

import os
import re
import sys

# ---------------------------------------------------------------------------
# JavaScript / TypeScript
#
# Moved here from check_e2e_coverage.py (#249) so gate-19, gate-34 and gate-58
# share one tokeniser instead of three greps. Behaviour is unchanged; gate-19's
# suite is the proof.
# ---------------------------------------------------------------------------

# Keywords after which a `/` opens a regular expression rather than dividing.
_JS_REGEX_KEYWORDS = frozenset({
    "return", "typeof", "instanceof", "in", "of", "new", "delete", "void",
    "throw", "case", "do", "else", "yield", "await",
})


def _skip_string(text: str, i: int) -> int:
    """Index just past the quoted string whose opening quote is at *i*.

    An unterminated literal stops at the newline rather than swallowing the
    rest of the file — a lone apostrophe in a comment must not blank a suite.
    """
    quote = text[i]
    n = len(text)
    j = i + 1
    while j < n:
        c = text[j]
        if c == "\\":
            j += 2
            continue
        if c == quote:
            return j + 1
        if c == "\n":
            return j
        j += 1
    return n


def _skip_template(text: str, i: int) -> int:
    """Index just past the template literal whose backtick is at *i*.

    `${ … }` substitutions are walked (they may contain braces, quotes and
    further templates) but their contents are blanked along with the rest: a
    test declaration inside a template substitution is not a thing.
    """
    n = len(text)
    j = i + 1
    depth = 0                       # ${ } nesting inside this template
    while j < n:
        c = text[j]
        if c == "\\":
            j += 2
            continue
        if depth == 0:
            if c == "`":
                return j + 1
            if c == "$" and j + 1 < n and text[j + 1] == "{":
                depth += 1
                j += 2
                continue
            j += 1
            continue
        if c == "`":
            j = _skip_template(text, j)
            continue
        if c in "'\"":
            j = _skip_string(text, j)
            continue
        if c == "{":
            depth += 1
        elif c == "}":
            depth -= 1
        j += 1
    return n


def _skip_regex(text: str, i: int) -> int:
    """Index just past the regex literal starting at *i*, or -1 if it is not
    one. A regex literal cannot span a newline, which is the cheap and
    reliable disambiguator against division."""
    n = len(text)
    j = i + 1
    in_class = False
    while j < n:
        c = text[j]
        if c == "\\":
            j += 2
            continue
        if c == "\n":
            return -1
        if in_class:
            if c == "]":
                in_class = False
        elif c == "[":
            in_class = True
        elif c == "/":
            j += 1
            while j < n and (text[j].isalpha()):
                j += 1
            return j
        j += 1
    return -1


def _regex_can_start(prev_char: str, prev_word: str) -> bool:
    """Whether a `/` at this point opens a regex rather than dividing."""
    if prev_char == "":
        return True
    if prev_char in ")]":
        return False
    if prev_char in "'\"`":
        return False
    if prev_char.isalnum() or prev_char in "_$":
        return prev_word in _JS_REGEX_KEYWORDS
    return True


def js_mask(text: str, *, blank_strings: bool = True) -> str:
    """A same-length copy of *text* with the non-code regions blanked.

    Comments and regex literals always go. String / template CONTENTS go only
    when *blank_strings* — the two callers want opposite things and both are
    right:

      * gate-19 asks "is argument 1 a string literal", which needs the
        contents gone but the DELIMITERS kept, so
        `test.skip('name', fn)` (a switched-off declaration) stays
        distinguishable from `test.skip(cond, 'reason')` (a live statement).
      * gate-34 and gate-58 look FOR string literals — `window['confirm']`,
        `waitForLoadState('networkidle')`. Blanking the contents would delete
        the evidence and turn a fixed gate into a dead one.

    Either way the scanner must understand strings and regexes, because that
    is the only way to know where a comment really starts: the `//` inside
    `'http://x'` or inside `/[a-z]//` opens nothing.

    Newlines survive in both modes, so line numbers and offsets computed on
    the mask address the original text.
    """
    out = list(text)
    n = len(text)

    def blank(a: int, b: int) -> None:
        for k in range(max(a, 0), min(b, n)):
            if out[k] != "\n":
                out[k] = " "

    i = 0
    prev_char = ""          # last significant code character
    prev_word = ""          # identifier ending at prev_char, when it is one
    while i < n:
        c = text[i]
        if c == "/" and text.startswith("//", i):
            j = text.find("\n", i)
            j = n if j < 0 else j
            blank(i, j)
            i = j
            continue
        if c == "/" and text.startswith("/*", i):
            j = text.find("*/", i + 2)
            j = n if j < 0 else j + 2
            blank(i, j)
            i = j
            continue
        if c in "'\"":
            j = _skip_string(text, i)
            if blank_strings:
                blank(i + 1, j)
                if j - 1 > i and text[j - 1] == c:
                    out[j - 1] = c
            prev_char, prev_word = c, ""
            i = j
            continue
        if c == "`":
            j = _skip_template(text, i)
            if blank_strings:
                blank(i + 1, j)
                if j - 1 > i and text[j - 1] == "`":
                    out[j - 1] = "`"
            prev_char, prev_word = "`", ""
            i = j
            continue
        if c == "/" and _regex_can_start(prev_char, prev_word):
            j = _skip_regex(text, i)
            if j > 0:
                blank(i, j)
                prev_char, prev_word = ")", ""   # a regex literal is a value
                i = j
                continue
        if c.isalnum() or c in "_$":
            k = i
            while k < n and (text[k].isalnum() or text[k] in "_$"):
                k += 1
            prev_word = text[i:k]
            prev_char = text[k - 1]
            i = k
            continue
        if not c.isspace():
            prev_char, prev_word = c, ""
        i += 1
    return "".join(out)


def js_code_mask(text: str) -> str:
    """Gate-19's mask: comments, string CONTENTS and regex literals blanked."""
    return js_mask(text, blank_strings=True)


def js_comment_mask(text: str) -> str:
    """Comments and regex literals blanked; string literals left INTACT.

    For gates whose evidence IS a string literal (gate-34's
    `window['confirm']`, gate-58's `'networkidle'`).
    """
    return js_mask(text, blank_strings=False)


# ---------------------------------------------------------------------------
# PHP
# ---------------------------------------------------------------------------

def php_mask(text: str, *, blank_strings: bool = False) -> str:
    """A same-length copy of *text* with PHP comments blanked.

    `#[` opens a PHP 8 ATTRIBUTE, not a comment. Treating it as one would
    swallow the rest of the line — including `#[NoAdminRequired]`, which is
    the single most load-bearing token in this package. That distinction is
    #184's, kept verbatim; the only thing added here is offset preservation,
    so gate-5 can compute a line number on the mask and read a docblock tag
    out of the original at the same line.

    String contents are kept by default: `class_exists('OCA\\OpenRegister…')`
    is evidence about code, and blanking it would delete the evidence.
    """
    out = list(text)
    n = len(text)

    def blank(a: int, b: int) -> None:
        for k in range(max(a, 0), min(b, n)):
            if out[k] != "\n":
                out[k] = " "

    i = 0
    while i < n:
        c = text[i]
        nxt = text[i + 1] if i + 1 < n else ""
        if c == "'" or c == '"':
            quote = c
            j = i + 1
            while j < n:
                if text[j] == "\\":
                    j += 2
                    continue
                if text[j] == quote:
                    j += 1
                    break
                j += 1
            else:
                j = n
            if blank_strings:
                blank(i + 1, j - 1)
            i = j
            continue
        if c == "/" and nxt == "/":
            j = text.find("\n", i)
            j = n if j < 0 else j
            blank(i, j)
            i = j
            continue
        if c == "#" and nxt != "[":
            j = text.find("\n", i)
            j = n if j < 0 else j
            blank(i, j)
            i = j
            continue
        if c == "/" and nxt == "*":
            j = text.find("*/", i + 2)
            j = n if j < 0 else j + 2
            blank(i, j)
            i = j
            continue
        i += 1
    return "".join(out)


# ---------------------------------------------------------------------------
# Markup — .vue / .php / .html
# ---------------------------------------------------------------------------

_HTML_COMMENT = re.compile(r'<!--.*?-->', re.DOTALL)
# `<?php … ?>` and the short echo form. An unterminated opener runs to end of
# file, which is the language's own rule. Same pattern php_template_scope.py
# uses (#247); the two agree deliberately.
_PHP_BLOCK = re.compile(r'<\?(?:php\b|=)?.*?(?:\?>|\Z)', re.DOTALL | re.IGNORECASE)
# `<template>` NESTS. A slot is written `<template #default>` INSIDE the SFC's
# own template, and a non-greedy `<template…>(.*?)</template>` therefore ends
# the block at the first slot close — silently dropping everything after it.
# Measured while fixing gate-12: openconnector's EditMapping.vue has a real
# unlabelled `<NcSelect>` at line 376, below two nested slot templates, and the
# first cut of this function made it vanish. That is the fix over-applied, and
# it is the exact failure this whole change exists to prevent, so the block
# boundaries are found by DEPTH, not by a lazy quantifier.
_TEMPLATE_TAG = re.compile(r'<(/?)template\b[^>]*?(/?)>', re.IGNORECASE | re.DOTALL)
# ⚠️ THE END TAG MAY CARRY JUNK. `</script\s*>` does not match `</script bar>`
# or `</script\t\n foo>`, and an HTML parser ends the element at both — so the
# regex would run the "script body" on past the real close and blank markup
# that ships. Caught by CodeQL (py/bad-tag-filter, high) on this very change:
# a mask that over-blanks is a gate that reports nothing, which is the failure
# mode this whole change exists to remove.
_SCRIPT_BLOCK = re.compile(r'<script(\s[^>]*)?>(.*?)</script(?:\s[^>]*)?>', re.DOTALL | re.IGNORECASE)
_STYLE_BLOCK = re.compile(r'<style(\s[^>]*)?>(.*?)</style(?:\s[^>]*)?>', re.DOTALL | re.IGNORECASE)


def _blank_span(buf: list[str], a: int, b: int) -> None:
    for k in range(max(a, 0), min(b, len(buf))):
        if buf[k] != "\n":
            buf[k] = " "


def _top_level_template_spans(text: str) -> list[tuple[int, int]]:
    """(start, end) of each TOP-LEVEL `<template>` body, nesting respected.

    A self-closing `<template … />` opens nothing. An unbalanced open runs to
    end of file rather than being discarded — dropping it would blank the
    whole template, which is the same false-green a missing helper produces.
    """
    spans: list[tuple[int, int]] = []
    depth = 0
    start = -1
    for m in _TEMPLATE_TAG.finditer(text):
        closing, self_closing = m.group(1), m.group(2)
        if self_closing and not closing:
            continue
        if closing:
            if depth > 0:
                depth -= 1
                if depth == 0 and start >= 0:
                    spans.append((start, m.start()))
                    start = -1
        else:
            if depth == 0:
                start = m.end()
            depth += 1
    if depth > 0 and start >= 0:
        spans.append((start, len(text)))
    return spans


def vue_markup_mask(text: str) -> str:
    """The RENDERED markup of a `.vue` SFC, everything else blanked.

    Kept: the contents of every top-level `<template>` block, minus its HTML
    comments.
    Blanked: `<script>`, `<style>`, and anything outside a `<template>`.

    Nothing in `<script>` renders an `<img>`, and the three gates that read
    `.vue` files as one flat string (31, 32, 12) all reported JSDoc prose as
    markup because of it. On openbuild all three gate-31 findings were
    ``* @param {Event} e - The `<img>` `error` event`` — a docblock, in the
    wrong SFC block, about a tag the template does not contain.

    A commented-out element renders nothing, so `<!-- … -->` goes too. That
    direction matters as much: gate-32 could be CLEARED by describing the
    `<div @click>` an element replaced, which is the finding buying its own
    exemption.
    """
    buf = list(text)
    n = len(text)
    keep = _top_level_template_spans(text)
    if not keep:
        # No <template> block at all: nothing renders. `render()` functions in
        # <script> are out of scope for these gates by construction — they were
        # never matched by the greps this replaces either.
        _blank_span(buf, 0, n)
        return "".join(buf)
    cursor = 0
    for a, b in keep:
        _blank_span(buf, cursor, a)
        cursor = b
    _blank_span(buf, cursor, n)
    masked = "".join(buf)
    # HTML comments last, over the kept regions only (they are all that is
    # left to comment).
    out = list(masked)
    for m in _HTML_COMMENT.finditer(masked):
        _blank_span(out, m.start(), m.end())
    return "".join(out)


def php_markup_mask(text: str) -> str:
    """The markup a PHP template EMITS, everything else blanked.

    `<?php … ?>` regions are code, docblocks and `//` comments — none of it
    reaches the browser. `<!-- … -->` ships nothing. Inline `<script>` bodies
    are JS, not markup, and get their comments blanked so a JSDoc `<img>` in a
    template behaves the way it does in an SFC.

    This is `php_template_scope.emitted_markup` (#247) plus the script-block
    handling; that module answers a narrower question (does the template own
    the document) and keeps its own copy on purpose, because gate-38's wiring
    guard is written against it.
    """
    buf = list(text)
    for m in _PHP_BLOCK.finditer(text):
        _blank_span(buf, m.start(), m.end())
    masked = "".join(buf)
    out = list(masked)
    for m in _HTML_COMMENT.finditer(masked):
        _blank_span(out, m.start(), m.end())
    result = "".join(out)
    return _mask_script_bodies(result)


def html_markup_mask(text: str) -> str:
    """HTML comments blanked; `<script>` bodies comment-masked."""
    buf = list(text)
    for m in _HTML_COMMENT.finditer(text):
        _blank_span(buf, m.start(), m.end())
    return _mask_script_bodies("".join(buf))


def _mask_script_bodies(text: str) -> str:
    """Blank JS comments inside every `<script>` body, offsets preserved."""
    out = list(text)
    for m in _SCRIPT_BLOCK.finditer(text):
        body = text[m.start(2):m.end(2)]
        masked = js_comment_mask(body)
        out[m.start(2):m.end(2)] = list(masked)
    return "".join(out)


def markup_mask(text: str, path: str = "") -> str:
    """Dispatch on extension. Unknown extensions get the HTML treatment."""
    ext = os.path.splitext(path)[1].lower()
    if ext == ".vue":
        return vue_markup_mask(text)
    if ext == ".php":
        return php_markup_mask(text)
    return html_markup_mask(text)


def js_exec_mask(text: str, path: str = "") -> str:
    """Every region of *text* where JavaScript EXECUTES, string contents blanked.

    This is the anchor mask for gates that look for a call site. Blanking
    string contents means a sentence of documentation cannot be a call:

        const doc = 'do not use window.confirm here'   -> not a call site

    but it also blanks the evidence for `window['confirm']`, whose method
    name IS a string. That is fine, and is the reason every mask in this
    module preserves offsets: the caller ANCHORS on this mask (which answers
    "is this position code?") and then re-reads the ORIGINAL text at the same
    offset to answer "what does it say?". Two questions, two sources, one
    coordinate system.

    Regions kept, by file type:
      * `.js` / `.ts` / …  — the whole file.
      * `.vue`             — `<script>` bodies, plus the ATTRIBUTE text of
                             every template element. A Vue binding's value is
                             an expression; a template TEXT NODE is not, so
                             `<p>never use window.confirm</p>` is prose here
                             and is deliberately blanked.
      * `.php` / `.html`   — the same, over emitted markup.
    """
    ext = os.path.splitext(path)[1].lower()
    if ext in (".js", ".ts", ".mjs", ".cjs", ".jsx", ".tsx"):
        return js_code_mask(text)
    out = [" " if c != "\n" else "\n" for c in text]
    # <script> bodies.
    for m in _SCRIPT_BLOCK.finditer(text):
        body = text[m.start(2):m.end(2)]
        out[m.start(2):m.end(2)] = list(js_code_mask(body))
    # Template attribute regions, taken from the markup scope so a commented
    # or PHP-side occurrence never gets here.
    for tag in iter_open_tags(markup_mask(text, path)):
        a, b = tag.start, tag.end
        out[a:b] = list(text[a:b])
    return "".join(out)


def script_mask(text: str, path: str = "") -> str:
    """The SCRIPT side of a file, comment-masked, string literals intact.

    For gates that look for a JS call anywhere it can execute:
      * `.js` / `.ts`  — the whole file.
      * `.vue`         — `<script>` bodies AND the template (an inline
                          `@click="window.confirm(…)"` executes too), with
                          HTML comments blanked.
      * `.php`/`.html` — `<script>` bodies plus inline handler attributes,
                          with PHP regions and HTML comments blanked.
    """
    ext = os.path.splitext(path)[1].lower()
    if ext in (".js", ".ts", ".mjs", ".cjs", ".jsx", ".tsx"):
        return js_comment_mask(text)
    if ext == ".vue":
        # Blank HTML comments across the whole file, then comment-mask every
        # <script> and <style> body. What remains is template markup (whose
        # attribute values are live JS) plus script code.
        buf = list(text)
        for m in _HTML_COMMENT.finditer(text):
            _blank_span(buf, m.start(), m.end())
        step = "".join(buf)
        out = list(_mask_script_bodies(step))
        for m in _STYLE_BLOCK.finditer(step):
            _blank_span(out, m.start(2), m.end(2))
        return "".join(out)
    # PHP / HTML template.
    buf = list(text)
    if ext == ".php":
        for m in _PHP_BLOCK.finditer(text):
            _blank_span(buf, m.start(), m.end())
    step = "".join(buf)
    out = list(step)
    for m in _HTML_COMMENT.finditer(step):
        _blank_span(out, m.start(), m.end())
    return _mask_script_bodies("".join(out))


# ---------------------------------------------------------------------------
# Quote-aware element extraction
# ---------------------------------------------------------------------------

# A `>` inside a quoted attribute value does NOT end the element. `[^>]*`
# does not know that, and in Vue the first `>` in a tag is very often the
# arrow of `:reduce="option => option.value"` (#236) — so every prop written
# after the reducer was invisible and a correctly labelled <NcSelect> was
# reported as unnamed. Same failure as gate-9's `[^)]*` in #198.
_OPEN_TAG = re.compile(
    r'<(/?)([A-Za-z][A-Za-z0-9._:-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)(/?)>',
    re.DOTALL,
)


class Tag:
    """One opening element: its name, its attribute text and where it starts."""

    __slots__ = ("name", "attrs", "start", "end", "raw", "line")

    def __init__(self, name: str, attrs: str, start: int, end: int, raw: str, line: int):
        self.name = name
        self.attrs = attrs
        self.start = start
        self.end = end
        self.raw = raw
        self.line = line

    @property
    def flat(self) -> str:
        """The element on one line — the shape the bash gates logged."""
        return re.sub(r'\s+', ' ', self.raw).strip()


def iter_open_tags(masked: str, names: set[str] | None = None):
    """Every OPENING element in *masked*, optionally filtered by name.

    *masked* must already be markup scope (see markup_mask) — this function
    deliberately does no comment handling of its own, so a caller cannot
    accidentally scan prose by forgetting the mask.
    """
    for m in _OPEN_TAG.finditer(masked):
        if m.group(1):          # closing tag
            continue
        name = m.group(2)
        if names is not None and name not in names:
            continue
        line = masked.count("\n", 0, m.start()) + 1
        yield Tag(name, m.group(3) or "", m.start(), m.end(), m.group(0), line)


def read_text(path: str) -> str:
    with open(path, encoding="utf-8", errors="replace") as handle:
        return handle.read()


_MASKS = {
    "js": js_code_mask,
    "js-comments": js_comment_mask,
    "php": php_mask,
}


def main(argv: list[str]) -> int:
    if len(argv) >= 4 and argv[1] == "--mask":
        kind, target = argv[2], argv[3]
        fn = _MASKS.get(kind)
        if kind == "markup":
            src = sys.stdin.read() if target == "-" else read_text(target)
            sys.stdout.write(markup_mask(src, "" if target == "-" else target))
            return 0
        if kind == "script":
            src = sys.stdin.read() if target == "-" else read_text(target)
            sys.stdout.write(script_mask(src, "" if target == "-" else target))
            return 0
        if fn is None:
            print(f"source_scope: unknown mask kind {kind!r}", file=sys.stderr)
            return 2
        src = sys.stdin.read() if target == "-" else read_text(target)
        sys.stdout.write(fn(src))
        return 0
    if len(argv) >= 4 and argv[1] == "--tags":
        names = {n for n in argv[2].split(",") if n}
        for path in argv[3:]:
            try:
                src = read_text(path)
            except OSError:
                continue
            for tag in iter_open_tags(markup_mask(src, path), names or None):
                print(f"{path}:{tag.line}: {tag.flat}")
        return 0
    print(__doc__, file=sys.stderr)
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv))
