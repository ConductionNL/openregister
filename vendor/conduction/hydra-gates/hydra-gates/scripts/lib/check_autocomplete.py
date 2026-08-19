#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
r"""Gate-44 autocomplete-attr — an `<input>` whose name/id/model says it
collects a well-known personal detail must declare `autocomplete=`, so browser
autofill and password managers can fill it. WCAG 2.2 AA SC 1.3.5 (Identify
Input Purpose).

WHY THIS IS A FILE AND NOT A HEREDOC
------------------------------------
gate-44 ran an inline `python3 - "$vue" <<'PYAC' >> log 2>/dev/null` once per
file and never read the exit status. Measured 2026-08-08 on opencatalogi with
a `python3` that exits 1 on every invocation, gates 40, 42 and 44 all reported
PASS — gate-40 over the 13 real findings it had reported one run earlier —
while every a11y gate whose checker lived in a file reported SKIPPED (wiring).
An empty log is not a clean sheet (ConductionNL/.github#147 / #249). Moving
the logic here buys the return-code guard the runner already applies
everywhere else in the family.

A SINGLE-QUOTED ATTRIBUTE IS THE SAME ATTRIBUTE
-----------------------------------------------
The previous regex read values out of DOUBLE QUOTES ONLY:

    re.search(r'(^|\s)(?:name|id|:name|:id|v-model)\s*=\s*"([^"]+)"', attrs)

so `<input id='b44-tel' type='text' name='telephone'>` — identical rendered
DOM, identical defect — was invisible. Proven by control fixture: the
double-quoted form fired in both a .vue app and a PHP-template app, the
single-quoted form reported PASS in both. Zero occurrences in the fleet
today, which is exactly why it could sit there indefinitely.

`[^>]*` went for the same reason it went from gate-39: a `>` inside an
attribute value is not the end of the tag (#259, #198, #236).

WHAT IS NOT FLAGGED
-------------------
  * `type=` in hidden/submit/button/reset/image/file/checkbox/radio/color/range
    — nothing to autofill
  * anything already carrying `autocomplete=`, literal or bound
  * markup inside comments or `<script>`/`<style>` blocks — an `<input>` in a
    JS string is not a control. The heredoc this replaces scanned the raw
    file, so a commented-out example counted; that is the gate-64 defect
    (#184) and the one gate-38 (#247) and gate-41 (#266) each shipped a fix
    for.

Usage:
    check_autocomplete.py <file>...     # findings on stdout, exit 0
"""
from __future__ import annotations

import re
import sys

COMMENT = re.compile(r'<!--.*?-->', re.DOTALL)
BLOCK = re.compile(r'<(script|style)\b[^>]*>.*?</\1\s*>', re.DOTALL | re.IGNORECASE)

# Quote-aware attribute run: whole quoted values are consumed, so a `>` inside
# one cannot terminate the tag early.
INPUT = re.compile(r'<input\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)/?>',
                   re.IGNORECASE | re.DOTALL)

SKIP_TYPE = re.compile(
    r'(?:^|\s)type\s*=\s*["\']'
    r'(?:hidden|submit|button|reset|image|file|checkbox|radio|color|range)["\']',
    re.IGNORECASE)
HAS_AUTOCOMPLETE = re.compile(r'(?:^|\s)(?::|v-bind:)?autocomplete\s*=')
# Both quote styles. The name is captured so the finding can name it.
NAME_LIKE = re.compile(
    r'(?:^|\s)(?::|v-bind:)?(?:name|id|v-model)\s*=\s*'
    r'(?:"([^"]+)"|\'([^\']+)\')')
# WHICH TOKEN IS THE NOUN (.github#319)
# ------------------------------------
# This was a bare substring alternation with no word boundaries:
#
#     re.compile(r'(email|tel(?:ephone)?|phone|...|city|postal|zip|...)')
#
# so `city` matched inside `capa*city*`, and equally would match `opacity`,
# `velocity`, `simplicity`, `electricity`; `tel` matched inside `ho*tel*`; `zip`
# inside `g*zip*`/`un*zip*`. Measured on pipelinq: two `type="number"`
# `min="1"` queue-capacity settings (`queue-edit-max-capacity`,
# `queue-new-max-capacity`) reported as collecting a personal detail.
#
# TOKEN BOUNDARIES ALONE ARE NOT ENOUGH, which is why this does more than
# #319's suggested `\b` anchoring. Measured on nldesign, all three findings:
#
#     nldesign-email-footer-org-name
#     nldesign-email-footer-accessibility-url
#     nldesign-email-footer-privacy-url
#
# `email` IS a whole hyphen-delimited token in each, so a `\b`-anchored regex
# still fires — yet these are the EMAIL FOOTER settings, and the three fields
# collect an organisation name and two URLs. None collects an email address.
#
# What separates them is WHERE the noun sits. An identifier names its subject
# at the END and qualifies it at the front: `portal-change-email` collects an
# email; `nldesign-email-footer-org-name` collects a NAME, and `email` there is
# context ("the email footer"), not the datum. So the semantic term must fall
# in the LAST TWO tokens.
#
# The window is two rather than one because the noun legitimately carries a
# qualifier on either side:
#
#   postal-code   zip-code   phone-number   street-address   post-code
#   emailRecipient                       <- noun first, qualifier after
#
# Measured: a strict last-token-only rule dropped nextcloud-vue's
# `fieldIdFor('emailRecipient')` — an `<input type="email">` that genuinely
# wants `autocomplete="email"` — so it was widened to two and that true
# positive is pinned by a test.
#
# The vocabulary is deliberately NOT extended while fixing a false-positive
# defect. `organisation` (British spelling) was tried and reverted: it made
# decidesk's admin-settings "Organization name" field a finding, and that is
# site configuration, not information about the user, so WCAG 1.3.5 does not
# apply to it. Widening a vocabulary is a different change with a different
# burden of proof.
#
# Every relaxation here ships with the true positive it must not swallow —
# see `SemanticTokenBoundaries` in test_check_autocomplete.py.
_SEMANTIC_TOKENS = {
    'email', 'tel', 'telephone', 'phone', 'firstname', 'lastname', 'fullname',
    'address', 'street', 'city', 'postal', 'postcode', 'zip', 'country',
    'password', 'username', 'organization', 'birthday', 'dob',
}

_TOKEN_SPLIT = re.compile(r'[^A-Za-z0-9]+|(?<=[a-z0-9])(?=[A-Z])')


def _tokens(value: str) -> list[str]:
    """`portal-change-email` / `form.granteeEmail` -> lowercase word tokens."""
    return [t.lower() for t in _TOKEN_SPLIT.split(value) if t]


def _is_semantic(value: str) -> bool:
    """True when the identifier NAMES a well-known personal detail."""
    toks = _tokens(value)
    if not toks:
        return False
    window = toks[-2:]
    if any(t in _SEMANTIC_TOKENS for t in window):
        return True
    # `first-name` / `firstName` / `post-code` join into one known term.
    if len(window) == 2 and (window[0] + window[1]) in _SEMANTIC_TOKENS:
        return True
    return False


def scan_source(fname: str, src: str) -> list[str]:
    txt = BLOCK.sub(' ', COMMENT.sub(' ', src)).replace('\n', ' ')
    findings: list[str] = []
    for m in INPUT.finditer(txt):
        attrs = m.group(1) or ''
        if SKIP_TYPE.search(attrs):
            continue
        if HAS_AUTOCOMPLETE.search(attrs):
            continue
        # EVERY name-like attribute, not just the first one found. The regex
        # this replaces used `re.search`, so the FIRST of name/id/v-model
        # decided the verdict alone:
        #
        #     <input id="e" type="text" name="email">
        #
        # matched `id="e"`, found no semantic noun in `e`, and stopped — while
        # `name="email"` sat one attribute to the right. Caught by
        # test_a_semantic_input_without_autocomplete, which is the plainest
        # textbook case this gate has.
        for name_m in NAME_LIKE.finditer(attrs):
            val = name_m.group(1) if name_m.group(1) is not None else name_m.group(2)
            if _is_semantic(val):
                findings.append(
                    f'{fname}: <input name/id="{val}" ...> '
                    f'rule=semantic-input-without-autocomplete')
                break
    return findings


def scan_files(files: list[str]) -> list[str]:
    out: list[str] = []
    for fname in files:
        try:
            with open(fname, encoding='utf-8', errors='replace') as f:
                src = f.read()
        except OSError:
            continue
        out.extend(scan_source(fname, src))
    return out


def main(argv: list[str]) -> int:
    for line in scan_files(argv[1:]):
        print(line)
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
