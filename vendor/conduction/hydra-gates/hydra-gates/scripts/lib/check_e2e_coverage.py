#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Gate-19 e2e-coverage — diff-scoped @e2e scenario traceability enforcer.

Two scenario formats are supported:

**Format A — heading-based (classic):**

    #### Scenario: <title>

    <prose / WHEN-THEN bullet list>

**Format B — numbered list under a bold marker (alternative):**

    ### REQ-DECOMP-001: <title>
    <prose>

    **Scenarios:**

    1. **GIVEN** ... **WHEN** ... **THEN** ...
    2. **GIVEN** ... **WHEN** ... **THEN** ...

Both formats must appear inside ``openspec/specs/<spec-name>/spec.md`` files
that are ADDED or MODIFIED in a PR. Every such scenario must be referenced by
at least one Playwright e2e test file under ``tests/e2e/**`` (``*.spec.ts``,
``*.spec.js``, ``*.test.ts``, ``*.test.js``). This closes the loop between the
*what-should-happen* (the scenario in the spec) and the *automated proof* (the
e2e test that asserts it in a browser).

The gate is the e2e companion to gate-16 (``check_spec_coverage.py``), which
enforces that code methods carry ``@spec`` back-references. Together they give a
spec → code → test traceability chain.

Annotation convention
======================

In an e2e test file, reference a scenario with **either** form (in a comment or
inside a test title/describe string):

    // @e2e openspec/specs/<spec-name>/spec.md#<scenario-slug>
    // @e2e <spec-name>::<scenario-slug>

**Format A slug:** kebab-case of the ``#### Scenario:`` heading text
(lower-case, punctuation stripped, words joined with ``-``).

**Format B slug:** ``<parent-req-slug>-scenario-<n>`` where ``parent-req-slug``
is the kebab-case of the enclosing ``### REQ-...:`` or ``### Requirement:``
heading (text after the colon, or the full heading if no colon), and ``<n>`` is
the 1-based number of the item under ``**Scenarios:**``.  The slug is
deterministic regardless of prose content so renaming scenario text does not
break existing ``@e2e`` annotations.

Example::

    // @e2e ai-chat-companion::widget-receives-context-on-a-detail-page
    // @e2e openspec/specs/ai-chat-companion/spec.md#widget-receives-context-on-a-detail-page
    // @e2e method-decomposition::req-decomp-001-settingscontroller-decomposition-scenario-1

Exclusions
==========

A scenario can be excluded from e2e-coverage enforcement by placing
``@e2e exclude <reason>`` in the spec's scenario block or its parent requirement
block (reason required — a bare ``@e2e exclude`` is non-compliant, just like
gate-16's ``@spec exclude`` rule).

For Format B (numbered scenarios): an ``@e2e exclude`` anywhere in the numbered
item's text, or on/under the parent ``### REQ-...:`` heading, excludes that
numbered scenario.

A **whole-spec** can be excluded by placing ``@e2e exclude <reason>`` on a line
directly after the spec's title / ``## Purpose`` section header. This is the
correct mechanism for pure-backend or API-contract specs that are covered by
Newman/PHPUnit instead of Playwright.

Diff scope
==========

In gate mode (default), the gate is diff-scoped via ``HYDRA_GATE_BASE_REF``
(default ``origin/development``): only scenarios in spec files that are ADDED or
MODIFIED in the PR are checked. Scenarios in untouched spec files are never
flagged.

Exit code is a STATUS, not a count: ``0`` pass, ``1`` fail, ``2`` error. The
number of findings is on stdout, in the ``FAIL — <n> scenario(s)`` summary
line. Read stdout; an exit status is one byte and this gate has already
returned a count through it twice.

Report mode (``--mode report``) scans the entire ``openspec/specs/`` tree and
emits a JSON summary — not diff-scoped, always exits 0.

Usage::

    # Gate mode (diff-scoped):
    HYDRA_GATE_BASE_REF=origin/development python3 scripts/lib/check_e2e_coverage.py [app-dir]

    # Report mode (full-repo):
    python3 scripts/lib/check_e2e_coverage.py [app-dir] --mode report
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import sys
from pathlib import Path

# ---------------------------------------------------------------------------
# Slug helpers
# ---------------------------------------------------------------------------

_SLUG_STRIP_RE = re.compile(r"[^a-z0-9\s-]")
_SLUG_SPACES_RE = re.compile(r"[\s_]+")


def _slugify(text: str) -> str:
    """Convert a scenario heading to a kebab-case slug.

    Matches the convention used by spec authors: lower-case, strip
    punctuation (except hyphens), collapse whitespace to single ``-``.
    """
    t = text.lower()
    t = _SLUG_STRIP_RE.sub("", t)
    t = _SLUG_SPACES_RE.sub("-", t.strip())
    t = re.sub(r"-{2,}", "-", t)
    return t.strip("-")


# ---------------------------------------------------------------------------
# Spec parsing
# ---------------------------------------------------------------------------

# Headings — Format A (classic)
_SCENARIO_RE = re.compile(r"^#{4}\s+Scenario:\s*(.+)", re.IGNORECASE)
# Headings — any ### heading that may parent scenarios (Requirement: OR REQ-*: patterns)
_REQUIREMENT_RE = re.compile(r"^#{3}\s+(?:Requirement:|REQ-[A-Z0-9_-]+:)\s*(.*)", re.IGNORECASE)
_PURPOSE_RE = re.compile(r"^(#{1,2}\s+(?:Purpose|.*Specification))", re.IGNORECASE)

# Format B — bold **Scenarios:** / **Scenario:** marker line
_ALT_SCENARIOS_MARKER_RE = re.compile(r"^\*\*Scenarios?:\*\*\s*$", re.IGNORECASE)
# Format B — numbered scenario item: starts with "N. **GIVEN**" or "N. **WHEN**"
_ALT_SCENARIO_ITEM_RE = re.compile(
    r"^(?P<n>\d+)\.\s+\*\*(?:GIVEN|WHEN)\b", re.IGNORECASE
)

# Exclusion marker: `@e2e exclude <reason>` (inline or on its own line)
_EXCLUDE_RE = re.compile(r"@e2e\s+exclude\b[ \t]*(?P<reason>.*?)\s*$")

# Whole-spec exclusion must be a STANDALONE directive line: the `@e2e exclude`
# token is the dominant content of the line, optionally prefixed by markdown
# bullet / blockquote / heading markers. Prose that merely *mentions*
# `@e2e exclude` mid-sentence (e.g. a Purpose paragraph "...scenarios annotated
# @e2e exclude below") must NOT exclude the entire spec.
_WHOLE_SPEC_EXCLUDE_RE = re.compile(
    r"^[ \t>*#\-]*@e2e\s+exclude\b[ \t]*(?P<reason>.*?)\s*$"
)


def _parse_exclusion(text: str) -> tuple[bool, str | None]:
    """Check whether ``text`` carries an ``@e2e exclude`` marker (inline OK).

    Returns ``(excluded, reason_or_None)``.
    ``reason`` is ``None`` when the marker is bare (non-compliant).
    Used for requirement-level and scenario-level exclusion, where an inline
    `@e2e exclude <reason>` appended to a heading or bullet is intentional.
    """
    m = _EXCLUDE_RE.search(text)
    if not m:
        return False, None
    reason = m.group("reason").strip()
    return True, reason if reason else None


def _parse_whole_spec_exclusion(text: str) -> tuple[bool, str | None]:
    """Whole-spec exclusion — only fires on a STANDALONE `@e2e exclude` line.

    Unlike :func:`_parse_exclusion` (which searches anywhere in the line), this
    anchors the directive to the line start (after optional markdown markers),
    so a descriptive sentence that happens to contain the phrase does not
    silently exclude the whole spec.
    """
    m = _WHOLE_SPEC_EXCLUDE_RE.match(text)
    if not m:
        return False, None
    reason = m.group("reason").strip()
    return True, reason if reason else None


def _make_scenario_entry(
    spec_name: str,
    scenario_label: str,
    slug: str,
    whole_spec_excluded: bool,
    whole_spec_reason: str | None,
    whole_spec_bare: bool,
    current_req_excluded: bool,
    current_req_bare: bool,
    scen_block_lines: list[str],
) -> dict:
    """Build a scenario result dict applying the exclusion priority chain."""
    scen_excluded = False
    scen_reason: str | None = None
    scen_bare = False
    for bl in scen_block_lines:
        exc, reason = _parse_exclusion(bl)
        if exc:
            scen_excluded = True
            scen_reason = reason
            scen_bare = reason is None
            break

    # Priority: whole-spec > requirement-level > scenario-level
    if whole_spec_excluded:
        final_excluded = True
        final_reason = whole_spec_reason
        final_bare = whole_spec_bare
    elif current_req_excluded:
        final_excluded = True
        final_reason = None if current_req_bare else "<inherited from requirement>"
        final_bare = current_req_bare
    elif scen_excluded:
        final_excluded = True
        final_reason = scen_reason
        final_bare = scen_bare
    else:
        final_excluded = False
        final_reason = None
        final_bare = False

    return {
        "spec": spec_name,
        "scenario": scenario_label,
        "slug": slug,
        "ref": f"{spec_name}::{slug}",
        "excluded": final_excluded,
        "exclude_reason": final_reason,
        "bare_exclude": final_bare,
    }


def parse_spec_scenarios(spec_path: Path) -> list[dict]:
    """Parse a spec.md and return a list of scenario dicts.

    Two scenario formats are recognised:

    **Format A (classic heading)**::

        #### Scenario: <title>
        <body lines>

    Slug: ``_slugify(title)``

    **Format B (numbered list under bold Scenarios: marker)**::

        ### REQ-XYZ-001: <req title>
        <prose>

        **Scenarios:**

        1. **GIVEN** ... **WHEN** ... **THEN** ...
        2. **GIVEN** ... **WHEN** ... **THEN** ...

    Slug: ``<parent-req-slug>-scenario-<n>`` where ``parent-req-slug`` is
    derived from the enclosing ``### REQ-...:`` or ``### Requirement:`` heading
    and ``<n>`` is the 1-based item number.

    Each returned dict::

        {
            "spec": str,         # spec-name (dir name)
            "scenario": str,     # human-readable label
            "slug": str,         # kebab slug
            "ref": str,          # "<spec>::<slug>"
            "excluded": bool,
            "exclude_reason": str | None,   # None means bare (non-compliant)
            "bare_exclude": bool,           # True when excluded but no reason
        }
    """
    spec_name = spec_path.parent.name
    try:
        lines = spec_path.read_text(encoding="utf-8").splitlines()
    except OSError:
        return []

    results: list[dict] = []

    # ---- detect a whole-spec exclusion: @e2e exclude before the first ### heading
    whole_spec_excluded = False
    whole_spec_reason: str | None = None
    whole_spec_bare = False
    for line in lines:
        if _REQUIREMENT_RE.match(line):
            break
        excluded, reason = _parse_whole_spec_exclusion(line)
        if excluded:
            whole_spec_excluded = True
            whole_spec_reason = reason
            whole_spec_bare = reason is None
            break

    # ---- walk the spec collecting scenarios (both formats) ----
    current_req_excluded = False
    current_req_bare = False
    current_req_slug = ""          # slug of the current ### heading (for Format B)

    # Format A state
    current_scenario_a: str | None = None
    scenario_a_lines: list[str] = []
    in_scenario_a = False

    # Format B state
    in_alt_scenarios_block = False   # True after seeing **Scenarios:**
    # pending numbered item being accumulated
    current_alt_n: int | None = None
    current_alt_lines: list[str] = []

    def _flush_scenario_a() -> None:
        nonlocal current_scenario_a, scenario_a_lines, in_scenario_a
        if current_scenario_a is None:
            return
        slug = _slugify(current_scenario_a)
        results.append(_make_scenario_entry(
            spec_name, current_scenario_a, slug,
            whole_spec_excluded, whole_spec_reason, whole_spec_bare,
            current_req_excluded, current_req_bare,
            scenario_a_lines,
        ))
        current_scenario_a = None
        scenario_a_lines = []
        in_scenario_a = False

    def _flush_alt_item() -> None:
        nonlocal current_alt_n, current_alt_lines
        if current_alt_n is None:
            return
        n = current_alt_n
        slug = f"{current_req_slug}-scenario-{n}" if current_req_slug else f"scenario-{n}"
        label = f"{current_req_slug} scenario {n}" if current_req_slug else f"scenario {n}"
        results.append(_make_scenario_entry(
            spec_name, label, slug,
            whole_spec_excluded, whole_spec_reason, whole_spec_bare,
            current_req_excluded, current_req_bare,
            current_alt_lines,
        ))
        current_alt_n = None
        current_alt_lines = []

    for line in lines:
        # ---- ### requirement heading (both formats share this) ----
        req_m = _REQUIREMENT_RE.match(line)
        if req_m:
            _flush_scenario_a()
            _flush_alt_item()
            in_scenario_a = False
            in_alt_scenarios_block = False
            current_req_excluded = False
            current_req_bare = False
            # Build slug from the text after the colon in the heading label.
            # The regex captures the text after the colon in group 1.
            heading_text = req_m.group(1).strip()
            # Also include any prefix before the captured group to form a full slug.
            # E.g. "### REQ-DECOMP-001: SettingsController Decomposition"
            # → full heading line minus leading hashes → "REQ-DECOMP-001: SettingsController..."
            # We want to slugify the whole meaningful part.
            full_heading = re.sub(r"^#{3}\s+", "", line).strip()
            current_req_slug = _slugify(full_heading)
            # Check the heading line itself for inline @e2e exclude
            exc, reason = _parse_exclusion(line)
            if exc:
                current_req_excluded = True
                current_req_bare = reason is None
            continue

        # ---- Format A: #### Scenario: heading ----
        scen_m = _SCENARIO_RE.match(line)
        if scen_m:
            _flush_scenario_a()
            _flush_alt_item()
            in_alt_scenarios_block = False
            current_scenario_a = scen_m.group(1).strip()
            in_scenario_a = True
            continue

        # ---- Format B: **Scenarios:** / **Scenario:** marker ----
        if _ALT_SCENARIOS_MARKER_RE.match(line):
            _flush_scenario_a()
            in_scenario_a = False
            in_alt_scenarios_block = True
            continue

        # ---- Format B: numbered item inside **Scenarios:** block ----
        if in_alt_scenarios_block:
            item_m = _ALT_SCENARIO_ITEM_RE.match(line)
            if item_m:
                _flush_alt_item()
                current_alt_n = int(item_m.group("n"))
                current_alt_lines = [line]
                continue
            # continuation line for the current numbered item
            if current_alt_n is not None:
                # Stop collecting if we hit a new ### heading (handled above)
                # or a blank line after the item content (next item will pick up)
                current_alt_lines.append(line)
                continue
            # blank/prose line while in alt block but no active item
            continue

        # ---- accumulate Format A scenario body ----
        if in_scenario_a:
            scenario_a_lines.append(line)
        else:
            # requirement body — check for requirement-level @e2e exclude
            exc, reason = _parse_exclusion(line)
            if exc and not current_req_excluded:
                current_req_excluded = True
                current_req_bare = reason is None

    _flush_scenario_a()
    _flush_alt_item()
    return results


# ---------------------------------------------------------------------------
# E2e test scanning
# ---------------------------------------------------------------------------

# Accept either annotation form:
#   @e2e openspec/specs/<spec>/<anything>spec.md#<slug>
#   @e2e <spec>::<slug>
# Both may appear in comments, test titles, describe strings — anywhere.
_E2E_PATH_RE = re.compile(
    r"@e2e\s+openspec/specs/(?P<spec>[^/]+)/[^\s#]*#(?P<slug>[A-Za-z0-9_-]+)"
)
_E2E_SHORT_RE = re.compile(
    r"@e2e\s+(?P<spec>[A-Za-z0-9_-]+)::(?P<slug>[A-Za-z0-9_-]+)"
)


# ---------------------------------------------------------------------------
# A PERMANENTLY-SKIPPED TEST IS NOT COVERAGE — AND READING ONE IS A PARSE
# ---------------------------------------------------------------------------
#
# Observed on decidesk: four tests with EMPTY BODIES and a hardcoded
# `test.skip(true, ...)`. Each carried an `@e2e` tag, each was counted as
# traceability, and together they asserted NOTHING. That is a dead gate by
# construction — the tag says a scenario is proven, the test proves nothing,
# and the gate cannot tell the difference. That rule stays.
#
# What is dead:
#   test.skip('name', ...)      the modifier form — declares a skipped test
#   it.skip(...) / xit / xtest / test.fixme(...)
#   describe.skip(...)          takes every test inside it with it
#   test.skip(true)             an UNCONDITIONAL skip, as a DIRECT STATEMENT
#                               of the body it belongs to
#   test.skip()                 argument-less, same thing
#   an empty body               nothing but whitespace and comments
#
# What is NOT dead, and must keep counting:
#   test.skip(browserName === 'firefox', 'flaky on gecko')
#   test.skip(!process.env.CI, 'needs a CI fixture')
#   if (!reachable) { test.skip(true, 'app not reachable') }
#
# WHY THIS IS NOW A PARSER (#234, #239, #244)
# -------------------------------------------
# Every previous version of this section read JavaScript with regular
# expressions and a hand-rolled paren walk. Three separate false REDs came out
# of that one decision, and all three were reported as the same sentence —
# "referenced only by a test that never runs" — about tests that ran and
# PASSED in the same CI run:
#
#   #234  A TRAILING COMMA before the closing paren. The body was found by
#         stepping back from `)` over whitespace and requiring a `}`. Prettier
#         and `comma-dangle: always-multiline` put a `,` there, so `body`
#         stayed "" and the empty-body rule fired on a real, asserting test.
#
#   #239  A CONDITIONAL `test.skip(true, reason)` INSIDE AN `if` GUARD. The
#         old discriminator was the ARGUMENT alone, so the single most common
#         defensive idiom in the fleet (111 call sites vs 4 genuinely
#         unconditional ones) read as a permanent skip. Worse, the gate's
#         suggested remedy is to replace the tag with `@e2e exclude`, i.e. to
#         DELETE a true coverage claim.
#
#   #244  A TAG WRITTEN INSIDE THE `test(` ARGUMENT LIST:
#
#             test(
#                 // @e2e openspec/specs/admin-settings/spec.md#…
#                 'Settings panel appears in admin area',
#                 async ({ page }) => { … },
#             )
#
#         The tag resolution only ever searched FORWARD, so a tag written
#         inside its own test's header resolved to the NEXT test in the file
#         (or ran off the end). On nldesign that mis-binding, compounded by
#         #234 on the test it landed on, produced 34 of 190 findings.
#
# So: tokenise the file once (strings, template literals, regex literals and
# comments are blanked, delimiters kept), then build the real tree of
# test/describe calls with their header and body ranges. Structure questions
# are answered from that tree instead of from a pattern that happens to look
# like the code.
#
# The identifier rules the regexes earned are kept, because they were right:
#   * `rx.test(text)` is JavaScript's RegExp.prototype.test, not Playwright's
#     test() — a member call is never a declaration (openconnector,
#     `dead-letter-replay.spec.ts`, 11 refs).
#   * `latest(` / `submit(` merely END in a declaration name.
#   * `test.describe.skip(` is Playwright's canonical spelling and MUST be
#     matched — a hand-written alternation could not see it at all (#210).
#   * `.only` / `.serial` / `.parallel` are not switched-off markers; a
#     `describe.only` runs (and suppresses everything else), so it stays live.
#   * anything else after the root — `test.beforeEach(`, `test.use(`,
#     `test.step(`, `test.setTimeout(`, `test.describe.configure(` — is not a
#     declaration at all.

# Keywords after which a `/` opens a regular expression rather than dividing.
_JS_REGEX_KEYWORDS = frozenset({
    "return", "typeof", "instanceof", "in", "of", "new", "delete", "void",
    "throw", "case", "do", "else", "yield", "await",
})

# A call whose callee is a dotted chain rooted at one of Playwright's/Jest's
# declaration names. Matched against the CODE MASK, so a `test(` inside a
# string, a comment or a regex literal is not a candidate at all.
_CALL_RE = re.compile(
    r"(?<![.\w$])(?P<root>test|it|describe|xit|xtest|xdescribe)"
    r"(?P<segs>(?:\s*\.\s*[A-Za-z_$][A-Za-z0-9_$]*)*)\s*\(",
)
_IDENT_RE = re.compile(r"[A-Za-z_$][A-Za-z0-9_$]*")

_OFF_SEGMENTS = frozenset({"skip", "fixme", "failing"})
_NEUTRAL_SEGMENTS = frozenset({"only", "serial", "parallel", "concurrent"})
# Guards whose body may be written WITHOUT braces: `if (x) test.skip(true, …)`
_GUARDS_WITH_PAREN = frozenset({"if", "while", "for", "catch"})
_GUARDS_BARE = frozenset({"else", "do", "try"})


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


def _code_mask(text: str) -> str:
    """A same-length copy of *text* with every non-code character blanked.

    Comments, string contents, template contents and regex literals become
    spaces; newlines survive so offsets and line numbers still line up with
    the original, which is what lets `@e2e` tags (found in the ORIGINAL text,
    inside comments) be located in the structure built from the mask.

    String and template DELIMITERS are deliberately kept. "Is the first
    argument a string literal" is the whole difference between

        test.skip('name', fn)      a declaration that is switched off
        test.skip(cond, 'reason')  a statement inside a running test

    and that question has to survive the blanking.
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
            blank(i + 1, j)
            if j - 1 > i and text[j - 1] == c:
                out[j - 1] = c
            prev_char, prev_word = c, ""
            i = j
            continue
        if c == "`":
            j = _skip_template(text, i)
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


def _match_paren(mask: str, open_paren: int) -> int | None:
    """Index of the `)` matching the `(` at *open_paren* in the CODE MASK."""
    depth = 0
    i = open_paren
    n = len(mask)
    while i < n:
        c = mask[i]
        if c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
            if depth == 0:
                return i
        i += 1
    return None


def _first_arg(mask: str, open_paren: int, close: int) -> str:
    """Masked text of the first top-level argument, stripped."""
    depth = 0
    i = open_paren + 1
    start = i
    while i < close:
        c = mask[i]
        if c in "([{":
            depth += 1
        elif c in ")]}":
            depth -= 1
        elif c == "," and depth == 0:
            break
        i += 1
    return mask[start:i].strip()


def _is_unconditional_arg(first: str) -> bool:
    """`test.skip()`, `test.skip(true, …)`, `test.skip(1)` — no runtime
    condition. Anything else is a guard and the test runs somewhere."""
    return first in ("", "true", "1")


class _TestNode:
    """One `test(...)` / `describe(...)` declaration and where its parts are."""

    __slots__ = ("fn", "segments", "switched_off", "start", "open", "close",
                 "body", "header", "parent", "children")

    def __init__(self, fn: str, segments: list[str], switched_off: bool,
                 start: int, open_paren: int, close: int) -> None:
        self.fn = fn
        self.segments = segments
        self.switched_off = switched_off
        self.start = start
        self.open = open_paren
        self.close = close
        self.body: tuple[int, int] | None = None
        self.header: tuple[int, int] = (open_paren + 1, close)
        self.parent: "_TestNode | None" = None
        self.children: list["_TestNode"] = []


class _TestFile:
    """The declaration tree of one e2e test file.

    Built once per file and queried per `@e2e` tag, so a file with 17 tagged
    tests is tokenised once rather than 17 times.
    """

    def __init__(self, text: str) -> None:
        self.text = text
        self.mask = _code_mask(text)
        self.nodes: list[_TestNode] = []
        self.roots: list[_TestNode] = []
        # (start, is_unconditional) for `test.skip(...)` / `test.fixme(...)`
        # written as a STATEMENT rather than as a declaration.
        self.skips: list[tuple[int, bool]] = []
        self._build()

    # -- construction -------------------------------------------------------

    def _build(self) -> None:
        mask = self.mask
        for m in _CALL_RE.finditer(mask):
            open_paren = m.end() - 1
            close = _match_paren(mask, open_paren)
            if close is None:
                continue
            root = m.group("root")
            segs = _IDENT_RE.findall(m.group("segs"))
            if root[0] == "x":
                if segs:
                    continue                       # xit.something( — not ours
                fn, switched_off = root[1:], True
            else:
                fn = root
                if fn in ("test", "it") and segs and segs[0] == "describe":
                    fn = "describe"
                    segs = segs[1:]
                if any(s not in _OFF_SEGMENTS and s not in _NEUTRAL_SEGMENTS
                       for s in segs):
                    # test.beforeEach( / test.use( / test.step( /
                    # test.setTimeout( / test.describe.configure( / test.info(
                    continue
                switched_off = any(s in _OFF_SEGMENTS for s in segs)
            first = _first_arg(mask, open_paren, close)
            if (switched_off and fn != "describe"
                    and not first.startswith(("'", '"', "`"))):
                # `test.skip(cond, 'reason')` — a statement inside a body, not
                # a declaration of a skipped test. Its conditionality is
                # decided by the ARGUMENT; whether it switches anything off is
                # decided later by WHERE it is written (#239).
                self.skips.append((m.start(), _is_unconditional_arg(first)))
                continue
            node = _TestNode(fn, segs, switched_off, m.start(), open_paren, close)
            self._attach_body(node)
            self.nodes.append(node)

        self.nodes.sort(key=lambda nd: nd.start)
        stack: list[_TestNode] = []
        for nd in self.nodes:
            while stack and stack[-1].close < nd.start:
                stack.pop()
            nd.parent = stack[-1] if stack else None
            if nd.parent is not None:
                nd.parent.children.append(nd)
            else:
                self.roots.append(nd)
            stack.append(nd)

    def _attach_body(self, node: _TestNode) -> None:
        """Find the callback body: the last brace-balanced group in the call.

        Scanning back from the closing paren skips whitespace AND a trailing
        comma. `comma-dangle: always-multiline` — Prettier's default and
        ESLint's recommended setting — puts a `,` exactly there, and requiring
        a `}` at that position reported every such test as an empty body
        (#234).

        The body cannot be found by searching FORWARD for the first `{`
        either: in `test('n', async ({ page }) => { … })` the first brace opens
        the fixture destructuring.
        """
        mask = self.mask
        k = node.close - 1
        while k > node.open and (mask[k].isspace() or mask[k] == ","):
            k -= 1
        if k > node.open and mask[k] == "}":
            depth = 0
            j = k
            while j > node.open:
                if mask[j] == "}":
                    depth += 1
                elif mask[j] == "{":
                    depth -= 1
                    if depth == 0:
                        break
                j -= 1
            if depth == 0 and mask[j] == "{":
                node.body = (j, k)
                node.header = (node.open + 1, j)
                return
        node.body = None
        node.header = (node.open + 1, node.close)

    # -- queries ------------------------------------------------------------

    def owner(self, pos: int) -> _TestNode | None:
        """The declaration an `@e2e` tag at *pos* annotates, or None.

        Three positions are all in fleet use and all mean the same thing:

            // @e2e a::b            tag ABOVE the declaration (the convention
            test('name', fn)        this module documents)

            test(                   tag INSIDE the argument list, between the
                // @e2e a::b        open paren and the title (#244 — nldesign
                'name', fn,         writes every one of its tests this way)
            )

            test('name', async () => {
                // @e2e a::b        tag INSIDE the body
                …
            })

        Returning None means "no declaration owns this tag" — a file-level
        annotation, which stays live: this function exists to find tests that
        were switched OFF, not to invent a structural requirement.
        """
        containing: _TestNode | None = None
        for nd in self.nodes:
            if nd.start > pos:
                break
            if nd.close >= pos:
                containing = nd          # sorted by start ⇒ last one is innermost
        if containing is not None and containing.header[0] <= pos <= containing.header[1]:
            return containing
        siblings = containing.children if containing is not None else self.roots
        for ch in siblings:
            if ch.start >= pos:
                return ch
        return containing

    def _innermost_body_owner(self, pos: int) -> _TestNode | None:
        found: _TestNode | None = None
        for nd in self.nodes:
            if nd.start > pos:
                break
            if nd.body is not None and nd.body[0] < pos < nd.body[1]:
                found = nd
        return found

    def _brace_depth(self, a: int, b: int) -> int:
        region = self.mask[a:b]
        return region.count("{") - region.count("}")

    def _is_guarded(self, start: int, limit: int) -> bool:
        """True when the statement at *start* is the braceless body of a guard.

        `if (!response) test.skip(true, 'unreachable')` has brace depth 0 in
        its enclosing test body, but it is still conditional.
        """
        mask = self.mask
        k = start - 1
        while k >= limit and mask[k].isspace():
            k -= 1
        if k < limit:
            return False
        if mask[k] == ")":
            depth = 0
            j = k
            while j >= limit:
                if mask[j] == ")":
                    depth += 1
                elif mask[j] == "(":
                    depth -= 1
                    if depth == 0:
                        break
                j -= 1
            if j < limit or depth != 0:
                return False
            j -= 1
            while j >= limit and mask[j].isspace():
                j -= 1
            end = j + 1
            while j >= limit and (mask[j].isalnum() or mask[j] in "_$"):
                j -= 1
            return mask[j + 1:end] in _GUARDS_WITH_PAREN
        end = k + 1
        j = k
        while j >= limit and (mask[j].isalnum() or mask[j] in "_$"):
            j -= 1
        return mask[j + 1:end] in _GUARDS_BARE

    def has_own_unconditional_skip(self, node: _TestNode) -> bool:
        """An unconditional skip that belongs to THIS body, not to a child and
        not to a guard.

        Three things disown a `test.skip(true, …)`:

        * it lives inside a NESTED declaration — one nested test guarding
          itself must not condemn its whole describe (launchpad
          `spec-coverage.spec.ts`, where a single nested skip at :185 killed
          the header tag at :15);
        * it is inside a braced block — `if (!reachable) { test.skip(true,
          'app not reachable') }` is the fleet's standard defensive idiom, 111
          call sites against 4 genuinely unconditional ones (#239);
        * it is the braceless body of a guard, same reason.

        What survives is what the rule was written for: a `test.skip(true)` as
        a direct statement at the top of a body, which is a test someone
        turned off. Playwright's group-level `test.skip()` — called directly
        in a describe body — skips every test in the group, so it counts too.
        """
        if node.body is None:
            return False
        b0, b1 = node.body
        for start, unconditional in self.skips:
            if not unconditional or not (b0 < start < b1):
                continue
            if self._innermost_body_owner(start) is not node:
                continue
            if self._brace_depth(b0 + 1, start) != 0:
                continue
            if self._is_guarded(start, b0 + 1):
                continue
            return True
        return False

    def body_is_empty(self, node: _TestNode) -> bool:
        if node.body is None:
            return True
        return not self.mask[node.body[0] + 1:node.body[1]].strip()


def _ref_is_live(doc: _TestFile, pos: int) -> bool:
    """Does the test that owns the `@e2e` tag at *pos* actually assert
    anything?

    Order matters. A switched-off ANCESTOR takes everything inside it with it
    (#210), so no amount of body in the inner test can rescue the ref.
    """
    node = doc.owner(pos)
    if node is None:
        # No declaration owns this tag — a file-level annotation. Live: this
        # function exists to catch tests that were switched OFF, not to invent
        # a structural requirement the gate never had.
        return True
    n: _TestNode | None = node
    while n is not None:
        if n.switched_off:
            return False
        n = n.parent
    if doc.body_is_empty(node):
        return False
    n = node
    while n is not None:
        if doc.has_own_unconditional_skip(n):
            return False
        n = n.parent
    return True


# ---------------------------------------------------------------------------
# Playwright config scope — WHICH FILES DOES CI ACTUALLY RUN? (.github#308)
# ---------------------------------------------------------------------------
# This gate counted every `*.spec.ts` under `tests/e2e/**` as a running test.
# Playwright does not. A file excluded by `testIgnore` — or living outside
# `testDir`, or not matched by any project's `testMatch` — is never executed,
# and a scenario referenced ONLY from such a file has no automated proof at all.
#
# Measured 2026-08-09 by planting an `@e2e` anchor in a CI-ignored directory:
# the uncovered count dropped 271 → 270 and the scenario was reported COVERED.
# The gate could see `describe.skip` (#239) but not the config that silently
# does the same thing to a whole directory.
#
# The live shape in the fleet is openregister's
# `tests/e2e/api-direct/search-views-presentation.spec.ts`, which carries
# `@e2e openspec/specs/saved-search-views/spec.md` and sits under
# `**/api-direct/**` — excluded at top level AND repeated in every project's
# own `testIgnore`, because a project-level `testIgnore` REPLACES the top-level
# one rather than merging with it. openconnector's config says so in a comment.
#
# WHY A PARSER AND NOT A GLOB LIST
# --------------------------------
# `**/visual/**` and `**/docs-screenshots.spec.ts` are ignored by the default
# project and pulled BACK IN by the `visual` / `docs-capture` projects via
# `testMatch`. Treating "appears in some testIgnore" as dead would kill both,
# in every repo that has them — the largest false-positive surface here. A file
# is dead only when NO project would run it.
#
# CONSERVATIVE BY CONSTRUCTION. This reads a TypeScript literal with regexes;
# it is not a TS evaluator. Every uncertainty resolves to LIVE (i.e. to the
# pre-existing behaviour): no config, an unparsable config, a `testMatch` that
# is not a plain regex/string literal, a `testDir` that is not a literal. The
# gate therefore only ever loses coverage credit for an exclusion it could
# actually read, and a repo whose config it cannot parse behaves exactly as
# before this change.
_PW_CONFIGS = ("playwright.config.ts", "playwright.config.js",
               "playwright.config.mts", "playwright.config.cjs")

_STR_RE = re.compile(r"""['"]([^'"]*)['"]""")


def _strip_ts_comments(text: str) -> str:
    """Blank out `//` and `/* */` comments, preserving offsets and newlines.

    Not cosmetic. These configs explain themselves at length, and the
    explanations quote the very keys being parsed — openregister and
    openconnector both carry a `NOTE: a project-level testIgnore REPLACES the
    top-level testIgnore` comment. Parsing that sentence as configuration is
    the #294 mistake in a different file.
    """
    out: list[str] = []
    i, n = 0, len(text)
    while i < n:
        c = text[i]
        if c in "\"'`":
            j = i + 1
            while j < n and text[j] != c:
                j += 2 if text[j] == "\\" else 1
            out.append(text[i:min(j + 1, n)])
            i = j + 1
        elif text.startswith("//", i):
            j = text.find("\n", i)
            j = n if j < 0 else j
            out.append(" " * (j - i))
            i = j
        elif text.startswith("/*", i):
            j = text.find("*/", i + 2)
            j = n if j < 0 else j + 2
            out.append("".join(ch if ch == "\n" else " " for ch in text[i:j]))
            i = j
        else:
            out.append(c)
            i += 1
    return "".join(out)


def _glob_to_re(glob: str) -> re.Pattern[str] | None:
    """Minimatch-style glob → regex, for the subset Playwright configs use.

    `**/` matches zero or more directories (so `**/api-direct/**` matches
    `api-direct/x.spec.ts`), `**` matches anything, `*` matches within one
    segment, `?` matches one character. Anything with brace/extglob syntax
    returns None — unparsable means LIVE, never a guess.
    """
    if any(ch in glob for ch in "{}()[]!+@"):
        return None
    out, i, n = [], 0, len(glob)
    while i < n:
        if glob.startswith("**/", i):
            out.append(r"(?:[^/]+/)*")
            i += 3
        elif glob.startswith("**", i):
            out.append(r".*")
            i += 2
        elif glob[i] == "*":
            out.append(r"[^/]*")
            i += 1
        elif glob[i] == "?":
            out.append(r"[^/]")
            i += 1
        else:
            out.append(re.escape(glob[i]))
            i += 1
    try:
        return re.compile("^" + "".join(out) + "$")
    except re.error:
        return None


def _match_bracket(text: str, start: int, open_ch: str, close_ch: str) -> int | None:
    depth = 0
    for i in range(start, len(text)):
        if text[i] == open_ch:
            depth += 1
        elif text[i] == close_ch:
            depth -= 1
            if depth == 0:
                return i
    return None


def _value_after(region: str, key: str) -> str | None:
    """The raw source of `<key>: <value>` inside *region*, at any depth."""
    m = re.search(r"\b" + key + r"\s*:\s*", region)
    if not m:
        return None
    i = m.end()
    if i >= len(region):
        return None
    if region[i] == "[":
        end = _match_bracket(region, i, "[", "]")
        return region[i:end + 1] if end is not None else None
    end = region.find("\n", i)
    return region[i:end if end >= 0 else len(region)].rstrip().rstrip(",")


def _patterns(raw: str | None) -> list[str] | None:
    """String literals from a `testIgnore`/`testMatch` value, or None."""
    if raw is None:
        return None
    found = _STR_RE.findall(raw)
    return found if found else None


def _regexes(raw: str | None) -> list[re.Pattern[str]] | None:
    """`testMatch` as compiled regexes. A `/…/` literal is a JS regex; a
    quoted value is a glob. Returns None when neither shape is recognised —
    which the caller reads as "no constraint", i.e. LIVE."""
    if raw is None:
        return None
    out: list[re.Pattern[str]] = []
    for lit in re.findall(r"/((?:[^/\\\n]|\\.)+)/[gimsuy]*", raw):
        try:
            out.append(re.compile(lit))
        except re.error:
            return None
    for s in _STR_RE.findall(raw):
        r = _glob_to_re(s)
        if r is None:
            return None
        out.append(r)
    return out or None


_WF_TEST_PATH_RE = re.compile(
    r"^[^\S\n]*playwright-test-path[^\S\n]*:[^\S\n]*(['\"]?)([^'\"#\n]+)\1",
    re.MULTILINE)


def _declared_test_path(app_dir: Path) -> str:
    """The `playwright-test-path` the app's CI actually declares.

    Resolution mirrors how the value reaches the shared workflow:

      1. ``$PLAYWRIGHT_TEST_PATH`` — set by a caller or a local run
      2. the ``playwright-test-path:`` input in the app's own caller workflow
      3. ``tests/e2e`` — the shared workflow's declared default

    Read out of the caller workflow rather than plumbed through a new env var
    on purpose: the gate has to agree with the file that decides the
    behaviour, and a second source of truth would drift from it exactly the
    way the root config drifted from the CI config.
    """
    env = os.environ.get("PLAYWRIGHT_TEST_PATH", "").strip()
    if env:
        return env.strip("/")
    wf_dir = app_dir / ".github" / "workflows"
    if wf_dir.is_dir():
        for wf in sorted(wf_dir.glob("*.y*ml")):
            try:
                text = wf.read_text(encoding="utf-8")
            except OSError:
                continue
            for m in _WF_TEST_PATH_RE.finditer(text):
                value = m.group(2).strip().strip("/")
                # `#` starts a YAML comment, so a commented-out or
                # documentation line yields nothing usable. Several fleet
                # workflows explain this input at length directly above it.
                if value and not value.startswith("#"):
                    return value
    return "tests/e2e"


def _resolve_config(app_dir: Path) -> Path | None:
    """The config CI executes — NOT necessarily the one at the repo root.

    THE GATE READ A DIFFERENT FILE THAN CI RAN (.github#331)
    -------------------------------------------------------
    The shared workflow does exactly this:

        CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
        if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
          CONFIG="playwright.config.ts"
        fi
        npx playwright test --config="$CONFIG"

    so a config under `playwright-test-path` WINS over the root one. This gate
    read the root config unconditionally, so in every repo carrying both it
    scored a suite CI never executes.

    Measured 2026-08-10 on openregister, which declares
    `playwright-test-path: tests/e2e/ci`:

        root config   testDir './tests/e2e'  -> 63 spec files
        CI config     testDir '.'            ->  4 spec files
        @e2e anchors  205 under tests/e2e, and ZERO of them under tests/e2e/ci

    Every "covered" verdict gate-19 ever produced on that repo came from a
    file CI does not run.

    `.ts` is tried first at both locations because that is the literal the
    workflow builds; the other extensions are a fallback for repos that do not
    go through the shared workflow at all.
    """
    sub = _declared_test_path(app_dir)
    if sub:
        cand = app_dir / sub / "playwright.config.ts"
        if cand.is_file():
            return cand
    cand = app_dir / "playwright.config.ts"
    if cand.is_file():
        return cand
    for name in _PW_CONFIGS:
        cand = app_dir / name
        if cand.is_file():
            return cand
    return None


class _PlaywrightScope:
    """Answers: would ANY project run this test file?"""

    def __init__(self, app_dir: Path) -> None:
        self.parsed = False
        self.test_dir = ""
        self.config_dir = ""
        self.config_rel = ""
        self.projects: list[tuple[list[str] | None, list[re.Pattern[str]] | None]] = []
        cfg = _resolve_config(app_dir)
        if cfg is None:
            return
        try:
            self.config_rel = os.path.relpath(str(cfg), str(app_dir)).replace(os.sep, "/")
        except ValueError:
            self.config_rel = cfg.name
        # `testDir` is relative to the CONFIG's own directory, not to the repo
        # root. openregister's CI config says `testDir: '.'` and means
        # `tests/e2e/ci`, not the whole repository — reading it as repo-relative
        # is what makes a 4-file suite look like a 63-file one.
        try:
            self.config_dir = os.path.relpath(str(cfg.parent), str(app_dir)).replace(os.sep, "/")
        except ValueError:
            return
        if self.config_dir == ".":
            self.config_dir = ""
        try:
            self._parse(_strip_ts_comments(cfg.read_text(encoding="utf-8")))
        except OSError:
            return

    def _parse(self, text: str) -> None:
        m = re.search(r"\bprojects\s*:\s*\[", text)
        blocks: list[str] = []
        if m:
            end = _match_bracket(text, m.end() - 1, "[", "]")
            if end is None:
                return
            arr, top = text[m.end():end], text[:m.start()] + text[end + 1:]
            i = 0
            while i < len(arr):
                if arr[i] == "{":
                    close = _match_bracket(arr, i, "{", "}")
                    if close is None:
                        return
                    blocks.append(arr[i:close + 1])
                    i = close + 1
                else:
                    i += 1
        else:
            top = text

        # `testDir` is resolved against the CONFIG's directory (#331). An absent
        # `testDir` means the config's own directory, which is Playwright's
        # documented default and NOT "the whole repository".
        td = _value_after(top, "testDir")
        raw = ""
        if td:
            lit = _STR_RE.findall(td)
            if lit:
                raw = lit[0]
        joined = os.path.normpath(os.path.join(self.config_dir or ".", raw or "."))
        self.test_dir = "" if joined in (".", "") else joined.replace(os.sep, "/").strip("/")

        top_ignore = _patterns(_value_after(top, "testIgnore"))
        top_match = _regexes(_value_after(top, "testMatch"))

        # A project's OWN testIgnore/testMatch REPLACES the top-level one —
        # Playwright does not merge them. Both openregister and openconnector
        # carry that fact as a comment because they were bitten by it.
        for b in blocks or [""]:
            ign = _patterns(_value_after(b, "testIgnore")) if b else None
            mat = _regexes(_value_after(b, "testMatch")) if b else None
            if b and re.search(r"\btestIgnore\s*:\s*\[\s*\]", b):
                ign = []          # an explicit empty list clears the top-level one
            self.projects.append((
                top_ignore if ign is None else ign,
                top_match if mat is None else mat,
            ))
        self.parsed = True

    def runs(self, rel_to_app: str) -> bool:
        """True unless the config PROVES no project executes this file."""
        if not self.parsed or not self.projects:
            return True
        # OUTSIDE `testDir` IS NOT RUN, AND THAT IS THE WHOLE POINT (#331).
        #
        # This used to return True here — "outside a testDir we may have
        # mis-read, never accuse". That caution was sound while the gate was
        # reading the ROOT config, whose `testDir` spans everything anyway. It
        # is exactly wrong once the CI config is resolved: openregister's CI
        # config confines the suite to `tests/e2e/ci`, and 59 of its 63 spec
        # files are outside it. Treating "outside testDir" as run is what made
        # 205 anchors in never-executed files count as proof.
        #
        # The safety property is kept where it belongs — `test_dir` is only
        # non-empty when a literal was parsed, and an absent `testDir` leaves
        # it at the config's own directory, per Playwright's default.
        if self.test_dir and not rel_to_app.startswith(self.test_dir + "/"):
            return False
        rel_to_dir = (rel_to_app[len(self.test_dir) + 1:]
                      if self.test_dir and rel_to_app.startswith(self.test_dir + "/")
                      else rel_to_app)
        for ignore, match in self.projects:
            if match is not None and not any(
                r.search(rel_to_dir) or r.search(rel_to_app) for r in match
            ):
                continue
            if ignore:
                compiled = [_glob_to_re(g) for g in ignore]
                if any(c is None for c in compiled):
                    return True   # an unreadable glob is not evidence
                if any(c.match(rel_to_dir) or c.match(rel_to_app)
                       for c in compiled if c is not None):
                    continue
            return True
        return False


def collect_covered_refs(app_dir: Path) -> set[str]:
    """Return the set of ``<spec>::<slug>`` refs found in any e2e test file.

    Only refs whose enclosing test actually runs are returned; see
    ``collect_ref_status`` for the dead ones and why.
    """
    live, _dead = collect_ref_status(app_dir)
    return live


def collect_ref_status(app_dir: Path) -> tuple[set[str], dict[str, str]]:
    """(live refs, {dead ref: reason}) across the app's e2e suite.

    A ref is live if ANY test referencing it runs. One skipped copy alongside
    a real one is not a regression, so the dead map only keeps refs with no
    live reference at all.
    """
    live: set[str] = set()
    dead: dict[str, str] = {}
    e2e_dir = app_dir / "tests" / "e2e"
    if not e2e_dir.is_dir():
        return live, dead
    scope = _PlaywrightScope(app_dir)
    for p in e2e_dir.rglob("*"):
        if not p.is_file():
            continue
        if not (p.suffix in (".ts", ".js") and (
            p.stem.endswith(".spec") or p.stem.endswith(".test")
            or ".spec." in p.name or ".test." in p.name
        )):
            continue
        try:
            text = p.read_text(encoding="utf-8")
        except OSError:
            continue
        # A FILE THE CONFIG NEVER RUNS IS AS DEAD AS `describe.skip` (#308).
        #
        # Checked per file rather than per tag: `testIgnore` switches off the
        # whole file, so every ref in it is unproven for the same reason, and
        # the reason names the mechanism so the finding is actionable.
        rel = str(p.relative_to(app_dir))
        file_runs = scope.runs(rel)
        # Tokenise ONCE per file, then ask it per tag. nldesign's
        # admin-settings.spec.ts carries 17 tags; the old code re-scanned the
        # whole file for each of them.
        doc = _TestFile(text)
        for rex in (_E2E_PATH_RE, _E2E_SHORT_RE):
            for m in rex.finditer(text):
                ref = f"{m.group('spec')}::{m.group('slug')}"
                if file_runs and _ref_is_live(doc, m.end()):
                    live.add(ref)
                    dead.pop(ref, None)
                elif ref not in live:
                    dead[ref] = (
                        f"referenced only by a file no Playwright project "
                        f"runs — {rel} is outside testDir or excluded by "
                        f"testIgnore/testMatch in {scope.config_rel or 'playwright.config.ts'}, "
                        f"which is the config CI executes"
                        if not file_runs else
                        f"referenced only by a test that never runs ({rel})"
                    )
    return live, dead


# ---------------------------------------------------------------------------
# Git helpers
# ---------------------------------------------------------------------------


def _git(args: list[str], cwd: Path) -> str:
    try:
        out = subprocess.run(
            ["git", "-c", "safe.directory=*", *args],
            cwd=str(cwd),
            capture_output=True,
            text=True,
            check=False,
        )
        return out.stdout
    except OSError:
        return ""


def changed_spec_files(base_ref: str, app_dir: Path) -> set[str]:
    """Return relative paths of spec.md files touched in the PR diff."""
    diff = _git(["diff", "-U0", "--diff-filter=ACMR", "--name-only",
                 f"{base_ref}...HEAD"], app_dir)
    if not diff.strip():
        diff = _git(["diff", "-U0", "--diff-filter=ACMR", "--name-only",
                     base_ref], app_dir)
    paths: set[str] = set()
    for line in diff.splitlines():
        line = line.strip()
        if line.startswith("openspec/specs/") and line.endswith("spec.md"):
            paths.add(line)
    return paths


# ---------------------------------------------------------------------------
# Gate number for self-identification in output lines
# ---------------------------------------------------------------------------
GATE_NUM = 19

# ---------------------------------------------------------------------------
# AN EXIT CODE IS A STATUS. THE COUNT GOES ON STDOUT.
# ---------------------------------------------------------------------------
# This gate has now got the signalling wrong twice, in two different ways, and
# both were only visible because someone compared two numbers for one
# measurement:
#
#   * It returned the finding COUNT as its exit status. An exit status is one
#     byte, so 266 findings left as 10 — and 256 findings would have left as
#     0, which the runner reads as PASS. (.github#209)
#   * The clamp that fixed the wrap made the byte carry NEITHER: a 404-finding
#     run exited 255 while stdout said 404. A reader who trusted the byte got
#     a number that was not the count and was not a status either. (#242)
#
# So the byte is a status now and nothing else. Two numbers for one
# measurement means one of them came through a lossy channel; there is only
# one number, and it is printed.
EXIT_PASS = 0
EXIT_FAIL = 1
EXIT_ERROR = 2
# A gate that inspected NOTHING must not answer with the same byte as a gate
# that inspected everything and liked it. PASS and "empty scope" were the same
# 0, so `--require-full-coverage` — whose entire job is to notice gates that did
# not run — could not see this one. (.github#242)
EXIT_EMPTY_SCOPE = 3      # scope resolved, selected nothing -> runner _skip `na` (.github#268)
EXIT_NOT_APPLICABLE = 4   # subject matter absent entirely   -> runner _skip na


# ---------------------------------------------------------------------------
# Report mode
# ---------------------------------------------------------------------------


def run_report(app_dir: Path) -> int:
    """Full-repo scan. Emits JSON, always exits 0."""
    spec_root = app_dir / "openspec" / "specs"
    if not spec_root.is_dir():
        out = {
            "mode": "report",
            "app": app_dir.name,
            "totals": {"scenarios": 0, "covered": 0, "excluded": 0, "uncovered": 0},
            "uncovered": [],
            "coverage_pct": None,
        }
        print(json.dumps(out, indent=2))
        return 0

    covered_refs = collect_covered_refs(app_dir)

    all_scenarios: list[dict] = []
    for spec_md in sorted(spec_root.glob("*/spec.md")):
        all_scenarios.extend(parse_spec_scenarios(spec_md))

    totals = {"scenarios": len(all_scenarios), "covered": 0, "excluded": 0, "uncovered": 0}
    uncovered: list[dict] = []

    for s in all_scenarios:
        if s["excluded"] and not s["bare_exclude"]:
            totals["excluded"] += 1
        elif s["ref"] in covered_refs:
            totals["covered"] += 1
        else:
            totals["uncovered"] += 1
            uncovered.append({"ref": s["ref"], "spec": s["spec"], "scenario": s["scenario"]})

    denominator = totals["scenarios"] - totals["excluded"]
    coverage_pct = round(totals["covered"] / denominator * 100, 1) if denominator > 0 else None

    out = {
        "mode": "report",
        "app": app_dir.name,
        "totals": totals,
        "uncovered": uncovered,
        "coverage_pct": coverage_pct,
    }
    print(json.dumps(out, indent=2))
    return 0


# ---------------------------------------------------------------------------
# Gate mode (diff-scoped)
# ---------------------------------------------------------------------------


def run_gate(app_dir: Path) -> int:
    """Audit @e2e traceability. Returns a status; the COUNT is printed.

    SCOPE IS THE CALLER'S DECISION, AND IT IS NOT DEFAULTED (.github#242)
    --------------------------------------------------------------------
    This function used to diff against ``HYDRA_GATE_BASE_REF`` UNCONDITIONALLY,
    defaulting the ref to ``origin/development`` when the caller had not asked
    for diff scoping at all. So on a full-tree run — the mode a fleet audit uses
    — the default ref resolved, the diff came back empty, and the gate printed
    ``PASS — no spec files in diff`` over a repository it had never opened.

    Two things made that invisible. The scoping happened HERE, inside the
    helper, BELOW the runner's base resolution, so the runner could not tell
    that a full-tree request had been quietly narrowed to nothing. And the
    verdict was ``PASS``, not a skip, so ``--require-full-coverage`` — the one
    assertion built to catch gates that did not run — had nothing to catch.

    Measured on openconnector 2026-08-08: **5** findings as the runner invoked
    it, **412** against the root commit. 407 uncovered scenarios behind a green
    line.

      HYDRA_GATE_BASE_REF set    diff-scoped (ADR-020). An empty diff is an
                                 EMPTY SCOPE and reports as a skip, never a pass.
      HYDRA_GATE_BASE_REF unset  full-tree audit of every spec in the repo.
    """
    # AN UNREADABLE APP DIR IS AN ERROR, NOT AN ABSENCE. "There is no
    # openspec/specs here" and "I could not look" produce the same empty set,
    # and reporting the second as NOT APPLICABLE would retire the gate on the
    # strength of a typo in a path. Distinguish them before anything else.
    if not app_dir.is_dir():
        print(
            f"[gate-{GATE_NUM}] e2e-coverage: ERROR — {app_dir} is not a "
            f"readable directory, so nothing was inspected. This is not an "
            f"absence of specs; it is a failure to look."
        )
        return EXIT_ERROR

    spec_root = app_dir / "openspec" / "specs"
    all_specs = (
        {str(p.relative_to(app_dir)) for p in spec_root.glob("*/spec.md")}
        if spec_root.is_dir()
        else set()
    )

    if not all_specs:
        print(
            f"[gate-{GATE_NUM}] e2e-coverage: NOT APPLICABLE — no "
            f"openspec/specs/*/spec.md in this repository, so there is no "
            f"declared scenario for an e2e test to trace back to."
        )
        return EXIT_NOT_APPLICABLE

    base_ref = os.environ.get("HYDRA_GATE_BASE_REF")
    if base_ref:
        touched = changed_spec_files(base_ref, app_dir)
        if not touched:
            print(
                f"[gate-{GATE_NUM}] e2e-coverage: EMPTY SCOPE — diff-scoped "
                f"against '{base_ref}' and NO spec file was touched. "
                f"{len(all_specs)} spec file(s) exist here and NONE were "
                f"inspected: @e2e traceability (ADR-020) is UNVERIFIED by this "
                f"run. This is not a pass. Audit the whole tree by running "
                f"without HYDRA_GATE_BASE_REF, or with "
                f"--scope-to-diff --base <root-commit>."
            )
            return EXIT_EMPTY_SCOPE
    else:
        touched = all_specs

    covered_refs, dead_refs = collect_ref_status(app_dir)

    findings: list[str] = []
    for rel in sorted(touched):
        spec_md = app_dir / rel
        if not spec_md.is_file():
            continue
        scenarios = parse_spec_scenarios(spec_md)
        for s in scenarios:
            if s["excluded"] and not s["bare_exclude"]:
                # Legitimately excluded — not required
                continue
            if s["bare_exclude"]:
                # Bare @e2e exclude without reason — non-compliant, flag it
                findings.append(
                    f"{s['ref']} — @e2e exclude without reason (reason required)"
                )
            elif s["ref"] in dead_refs:
                # Named, but by a test that never runs. Saying "missing @e2e"
                # here would send someone to add a tag that is already there.
                findings.append(
                    f"{s['ref']} — @e2e tag present but the test does not run: "
                    f"{dead_refs[s['ref']]}. A permanently-skipped or empty test is "
                    f"not coverage: unskip it, give it a body, or replace the tag "
                    f"with a reason-bearing `@e2e exclude`."
                )
            elif s["ref"] not in covered_refs:
                findings.append(f"{s['ref']} — missing @e2e")

    for line in sorted(set(findings)):
        print(line)

    count = len(set(findings))
    if count == 0:
        print(f"[gate-{GATE_NUM}] e2e-coverage: PASS — {len(covered_refs)} reference(s) in e2e suite")
        return EXIT_PASS
    print(
        f"[gate-{GATE_NUM}] e2e-coverage: FAIL — {count} scenario(s) without a running e2e test"
    )
    return EXIT_FAIL


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------


def main(argv: list[str]) -> int:
    mode = "gate"
    app = "."
    rest = argv[1:]
    i = 0
    while i < len(rest):
        if rest[i] == "--mode" and i + 1 < len(rest):
            mode = rest[i + 1]
            i += 2
            continue
        app = rest[i]
        i += 1
    app_dir = Path(app).resolve()
    try:
        if mode == "report":
            return run_report(app_dir)
        return run_gate(app_dir)
    except Exception as exc:  # noqa: BLE001 — a crash must not read as PASS
        # A gate that fell over has NOT inspected anything. Exiting 0 here
        # would be the falsely-green shape this package exists to prevent, and
        # exiting with a count would be a lie about what was measured.
        print(f"[gate-{GATE_NUM}] e2e-coverage: ERROR — {type(exc).__name__}: {exc}")
        return EXIT_ERROR


if __name__ == "__main__":
    sys.exit(main(sys.argv))
