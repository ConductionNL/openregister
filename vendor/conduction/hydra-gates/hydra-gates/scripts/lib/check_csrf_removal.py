#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Gate-48 csrf-cochange — which removed lines actually DROPPED CSRF protection?

WHY THIS EXISTS (#191)
----------------------
The gate found removed attributes like this::

    git diff -U0 "${BASE_REF}...HEAD" -- 'lib/Controller/*.php' \\
        | grep -E '^-.*(@NoCSRFRequired|#\\[NoCSRFRequired\\])'

`^-.*` puts no constraint on where in the line the token sits, so a comment
that NAMES the attribute is indistinguishable from an attribute that was
deleted. Measured on nldesign (`development` vs `origin/beta`): the gate was
red, nothing about CSRF had changed, and the matched line was one removed
sentence from a class docblock —

    - * (#[PublicPage] + #[NoCSRFRequired]) and the response contract are owned by

— replaced by another sentence saying the same thing.

WHY THIS ONE IS ESPECIALLY BAD TO LEAVE
---------------------------------------
The cheapest way to clear the finding is to REWORD A COMMENT. That changes
nothing about CSRF and teaches exactly the habit the gate exists to prevent,
so a gate satisfiable by prose is worse than no gate: it manufactures the
appearance of a security review. Same family as #184, where gate-64 grepped a
quoted string literal and so matched every comment and missed every constant.

THE RULE
--------
A removed line counts only when the token is in a CODE POSITION:

  attribute form  after the `-`, optional whitespace, the content STARTS with
                  `#[`, and that attribute list contains `NoCSRFRequired`.
                  `#[NoAdminRequired, NoCSRFRequired]` counts; a sentence with
                  `#[NoCSRFRequired]` in the middle of it does not.

  docblock form   after the `-`, optional whitespace, an optional leading `*`
                  and optional whitespace, the content STARTS with
                  `@NoCSRFRequired`. That is the only position PHP's own
                  docblock parsers accept a tag in, and it is not a position
                  prose reaches.

The nldesign line fails both — its `#[NoCSRFRequired]` sits mid-sentence after
`(` — while a genuine deletion of either form still matches.

Usage::

    check_csrf_removal.py < unified.diff

Prints the removed lines that are real CSRF removals, one per line. Exits 0
always; the OUTPUT is the answer (#209).
"""
from __future__ import annotations

import re
import sys

# `-` then optional whitespace then `#[`, with NoCSRFRequired inside the
# attribute group. `[^]]*` is bounded by the closing bracket so a `#[Foo]`
# followed later on the line by the word NoCSRFRequired in prose cannot match.
ATTRIBUTE_REMOVED = re.compile(r'^-\s*#\[[^]]*\bNoCSRFRequired\b')
# `-` then optional whitespace, an optional docblock star, optional
# whitespace, then the tag AT THE START of the content.
DOCBLOCK_TAG_REMOVED = re.compile(r'^-\s*(?:\*\s*)?@NoCSRFRequired\b')

# A diff header line is `---` / `---` shaped; it is not a removed line of code.
DIFF_HEADER = re.compile(r'^---(\s|$)')
# `+++ b/lib/Controller/X.php` — starts a new file's hunks. `+++` must be
# tested before the `+` addition branch, exactly as `---` is before `-`.
DIFF_FILE_HEADER = re.compile(r'^\+\+\+\s+(?:b/)?(?P<path>\S+)')


def removals(diff: str) -> list[str]:
    """Removed lines that genuinely DROPPED CSRF protection.

    A REMOVAL PAIRED WITH AN IDENTICAL ADDITION IS A MOVE, NOT A REMOVAL.

    Relocating a docblock line inside a file emits a `-` and a `+` carrying the
    same bytes. Scanning only `^-` reads that as deleting the annotation, and
    the gate reports a security regression for a diff in which nothing about
    CSRF changed. Measured on larpingapp: #297 moved one comment line while
    reordering `SettingsController`'s docblocks —

        -     * @NoCSRFRequired removed to close the CSRF-forgery surface (closes #206).
        +     * instance-wide configuration write needs. `@NoCSRFRequired` was removed
        +     * @NoCSRFRequired removed to close the CSRF-forgery surface (closes #206).

    — and gate-48 kept `Hydra Gates` red on that repo's `development` branch
    from then on, over a commit that changed no auth posture at all.

    Cancellation is per FILE and by MULTISET. Per file because a line deleted
    from one controller and added to another is a real change of posture for
    the first one; by multiset because a diff that removes a tag twice and
    restores it once has removed it once.
    """
    # {path: [raw content of each added line]} and the removals in file order.
    added: dict[str | None, list[str]] = {}
    found: list[tuple[str | None, str]] = []
    path: str | None = None

    for line in diff.splitlines():
        header = DIFF_FILE_HEADER.match(line)
        if header:
            path = header.group('path')
            continue
        if line.startswith('+'):
            added.setdefault(path, []).append(line[1:])
            continue
        if not line.startswith('-') or DIFF_HEADER.match(line):
            continue
        if ATTRIBUTE_REMOVED.match(line) or DOCBLOCK_TAG_REMOVED.match(line):
            found.append((path, line))

    out: list[str] = []
    for file_path, line in found:
        pool = added.get(file_path)
        if pool is not None and line[1:] in pool:
            # Consume the pairing so a second identical removal still reports.
            pool.remove(line[1:])
            continue
        out.append(line)
    return out


def main(argv: list[str]) -> int:
    if len(argv) > 1:
        print("usage: check_csrf_removal.py < unified.diff", file=sys.stderr)
        return 2
    for line in removals(sys.stdin.read()):
        print(line)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
