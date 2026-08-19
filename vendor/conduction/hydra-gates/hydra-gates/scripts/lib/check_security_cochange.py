#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Gate-47 security-change-has-tests — a PR that changes security-sensitive
code must also touch a test.

WHY THIS WAS REWRITTEN
----------------------
The shell implementation this replaces classified a changed file by grepping
**the whole file**:

    grep -qE "(#\\[NoAdminRequired\\]|…|IUserSession|parse_url|…)" "$f"

So a file was "a security change" if the token appeared ANYWHERE in it — in a
method the PR never went near, in an import, in a comment. Two agents hit this
independently on the same day:

  * a PR whose hunks were CSS custom properties and one added chevron column
    was told to add a CSRF test, because the component file also happens to
    render something behind `IUserSession`;
  * a PR that was **provably comment-only** — all 30 changed `lib/` lines
    inside docblocks — was told to co-change tests.

Neither used the opt-out, which is the tell that matters: developers do not
reach for an opt-out when they believe the finding is wrong, they argue with
it or ignore the gate. A gate whose finding cannot be acted on truthfully is
an unclosable gate, and unclosable gates are how a suite loses its readers.

WHAT CHANGED
------------
1. **Classify on the HUNKS, not the file.** Only lines the diff actually adds
   or removes are examined. `git diff -U0` gives exactly those.

2. **A comment line counts only for an ANNOTATION.** In Nextcloud the docblock
   forms `@NoAdminRequired` / `@NoCSRFRequired` / `@PublicPage` ARE the auth
   declaration, so a changed docblock carrying one is a real security change.
   Prose that merely mentions `IUserSession` is not — it is a sentence. The
   discriminator is which token, not whether the line is a comment, because
   collapsing the two is what produced the comment-only false positive.

Path-based classification (`lib/**/Auth/**`, `lib/*Csrf*`, …) is unchanged:
a file under those paths is security code by location, and any change to it
qualifies.

WHAT STILL FIRES
----------------
A hunk that adds, removes or edits an auth annotation, a CSRF exemption, a
session lookup, a signature comparison or a URL parse — with no test file in
the same diff — is still reported, and ``test_check_security_cochange.py``
proves it for every token in the vocabulary.

Usage:
    check_security_cochange.py <base-ref> [app-dir]
    # prints one line per security-touching file with no test co-change
"""
from __future__ import annotations

import os
import re
import subprocess
import sys

# Security code identified by LOCATION. Any change to such a file qualifies —
# there is no "incidental" edit to lib/Service/Auth/TokenVerifier.php.
_PATH_PATTERNS = (
    re.compile(r"^lib/.*Auth[^/]*\.php$"),
    re.compile(r"^lib/.*Csrf[^/]*\.php$"),
    re.compile(r"^lib/.*Session[^/]*\.php$"),
    re.compile(r"^lib/.*/(Auth|Session|Csrf|Rbac|Permission|Authorization)/.*$"),
)

# Tokens that are a security DECLARATION — but ONLY IN A CODE POSITION.
#
# WHY THIS IS POSITION-ANCHORED, AND WHY IT WAS BOTH WRONG DIRECTIONS AT ONCE
# ---------------------------------------------------------------------------
# This used to be an unanchored alternation: the literal `#[NoAdminRequired]`
# or `@NoAdminRequired` matched anywhere on a changed line. Measured on
# larpingapp 2026-08-08, it was wrong in both directions from the same regex —
# the pairing #269 found in gate-48 and did not carry across to its sibling.
#
#   FALSE POSITIVE. `CharactersController.php` carries a docblock paragraph
#   explaining why a method is deliberately admin-only:
#
#       * becomes `@NoAdminRequired` again, paired with a real ownership check.
#
#   Rewording that ONE SENTENCE — a change with no code in it at all — made
#   gate-47 demand a test co-change. That is #191's shape one level up: the
#   cheapest way to clear the finding is to reword the prose again, so the
#   gate is satisfiable by prose and manufactures the appearance of a security
#   review. The gate's own module docstring already committed to not doing
#   this ("Prose that merely mentions IUserSession is not — it is a sentence");
#   the annotation arm simply never implemented it.
#
#   FALSE NEGATIVE. `#\[NoAdminRequired\]` is a LITERAL, so the equally valid
#   fully-qualified form is invisible:
#
#       #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
#
#   A commit adding exactly that line to a controller — opening an
#   admin-only endpoint to every authenticated user — with no test in the diff
#   was measured to report `[gate-47] security-change-has-tests: PASS`. Same
#   class as #184: a checker that greps a string literal misses every
#   qualified form and matches every comment, and so fails both ways at once.
#
# THE RULE (identical to check_csrf_removal.py, deliberately)
#   attribute form  the line's content STARTS with `#[`, and the attribute
#                   group contains the token. `[^]]*` is bounded by the closing
#                   bracket, so `#[NoAdminRequired, NoCSRFRequired]` and the
#                   fully-qualified form both count, while a sentence with
#                   `#[NoAdminRequired]` in the middle of it does not.
#   docblock form   an optional comment lead-in (`*`, `//`, `#`), then the tag
#                   AT THE START of the content. That is the only position
#                   PHP's docblock parsers accept a tag in, and it is not a
#                   position prose reaches. The lead-in is permissive on
#                   purpose — `// @PublicPage` is still a declaration-shaped
#                   line, and narrowing to `*` only would trade this false
#                   positive for a false negative, which is the trap #269
#                   named. What is excluded is the tag appearing PART-WAY
#                   THROUGH a sentence, which is the only shape prose takes.
_ANNOTATION_RE = re.compile(
    r"^\s*#\[[^]]*\b(?:NoAdminRequired|AuthorizedAdminSetting|PublicPage|NoCSRFRequired)\b"
    r"|^\s*(?:\*+|//+|\#(?!\[)|/\*+)?\s*@(?:NoAdminRequired|NoCSRFRequired|PublicPage)\b"
)

# Tokens that are security-relevant only as CODE. In prose they are the name
# of a thing being described, not a change to it.
_CODE_TOKEN_RE = re.compile(
    r"\bparse_url\s*\("
    r"|\bhash_equals\s*\("
    r"|\bpassword_verify\s*\("
    r"|\bIUserSession\b"
    r"|\bgetSecureRandom\s*\("
    r"|\brequesttoken\b"
)

_TEST_PATH_RE = re.compile(
    r"^tests?/|/tests?/|\.spec\.(js|ts|vue|php)$|\.test\.(js|ts|vue)$|Test\.php$"
)

_CANDIDATE_RE = re.compile(r"^(lib/.*\.php|src/.*\.(vue|js|ts))$")

# Comment shapes, per line, after leading whitespace.
_COMMENT_LINE_RE = re.compile(r"^\s*(?:\*|//|/\*|\#(?!\[))")


def is_test_path(path: str) -> bool:
    return bool(_TEST_PATH_RE.search(path))


def is_security_path(path: str) -> bool:
    return any(p.match(path) for p in _PATH_PATTERNS)


def line_is_security_relevant(line: str) -> bool:
    """Is this ONE changed line a security change?

    A comment line qualifies only via ``_ANNOTATION_RE``, and only when the
    annotation is at DOCBLOCK-TAG POSITION — a docblock whose tag changed is a
    changed auth declaration; a sentence that names the tag is not. `#[` is
    excluded from the `#` comment shape so a PHP 8 attribute is never read as
    a shell comment.

    ``match`` rather than ``search``: ``_ANNOTATION_RE`` is anchored with
    ``^`` on both branches, so the two are equivalent here, but ``match``
    states the intent — position is the whole point of this regex.
    """
    if _ANNOTATION_RE.match(line):
        return True
    if _COMMENT_LINE_RE.match(line):
        return False
    return bool(_CODE_TOKEN_RE.search(line))


def changed_lines(base_ref: str, path: str, cwd: str,
                  old_path: str | None = None) -> list[str]:
    """The added/removed lines for *path*, without diff framing.

    `-U0` so no context line is mistaken for a change: context is precisely
    the code the PR did NOT touch, and treating it as touched is the defect
    this function exists to remove.

    *old_path* is the pre-rename spelling when the file was renamed, and it
    MUST be in the pathspec alongside *path*. A pathspec is applied BEFORE
    rename detection runs, so asking for the destination alone deletes the
    source side of the pair from the diff and git has nothing left to match
    it against — it then reports the destination as `new file mode` with
    every line added. A pure move of a file that merely CONTAINS a security
    token (`requesttoken`, a session lookup) therefore classified as a
    security change of the whole file, which is the same
    classify-the-file-not-the-hunks defect this module was written to
    remove, arriving through the pathspec instead of through grep.

    Measured 2026-08-10 on pipelinq#763: `git mv src/components/X.vue
    src/dialogs/X.vue` (`similarity index 100%`, `0 insertions(+), 0
    deletions(-)`) was reported by this gate as 230 added lines and one
    security-touching change.

    With both spellings present git pairs them again, so a pure rename
    yields no lines and a rename-with-edits yields exactly its real hunks.
    """
    pathspec = [path] if old_path is None else [old_path, path]
    proc = subprocess.run(
        ["git", "-c", "safe.directory=*", "diff", "-U0", "--no-color",
         "-M", f"{base_ref}...HEAD", "--", *pathspec],
        cwd=cwd, capture_output=True, text=True, check=False,
    )
    out = proc.stdout
    if not out.strip():
        proc = subprocess.run(
            ["git", "-c", "safe.directory=*", "diff", "-U0", "--no-color",
             "-M", base_ref, "--", *pathspec],
            cwd=cwd, capture_output=True, text=True, check=False,
        )
        out = proc.stdout
    lines: list[str] = []
    for raw in out.splitlines():
        if raw.startswith(("+++", "---", "@@", "diff ", "index ",
                           "new file", "deleted file", "similarity ",
                           "rename ")):
            continue
        if raw.startswith(("+", "-")):
            lines.append(raw[1:])
    return lines


def changed_files(base_ref: str, cwd: str) -> list[str]:
    proc = subprocess.run(
        ["git", "-c", "safe.directory=*", "diff", "--name-only",
         f"{base_ref}...HEAD"],
        cwd=cwd, capture_output=True, text=True, check=False,
    )
    out = proc.stdout
    if not out.strip():
        proc = subprocess.run(
            ["git", "-c", "safe.directory=*", "diff", "--name-only", base_ref],
            cwd=cwd, capture_output=True, text=True, check=False,
        )
        out = proc.stdout
    return [ln.strip() for ln in out.splitlines() if ln.strip()]


def rename_map(base_ref: str, cwd: str) -> dict[str, str]:
    """``{destination: source}`` for every file the diff reports as renamed.

    Read once, unscoped, so rename detection actually has both sides to pair.
    `changed_lines` needs the source spelling to ask git a question whose
    answer is not an artefact of the pathspec — see its docstring.
    """
    out = ""
    for ref in (f"{base_ref}...HEAD", base_ref):
        proc = subprocess.run(
            ["git", "-c", "safe.directory=*", "diff", "--name-status",
             "-M", "--no-color", ref],
            cwd=cwd, capture_output=True, text=True, check=False,
        )
        out = proc.stdout
        if out.strip():
            break
    renames: dict[str, str] = {}
    for raw in out.splitlines():
        parts = raw.split("\t")
        if len(parts) == 3 and parts[0].startswith("R"):
            renames[parts[2].strip()] = parts[1].strip()
    return renames


def scan(base_ref: str, cwd: str = ".") -> tuple[list[str], bool]:
    """(security-touching files, whether the diff also touches a test)."""
    files = changed_files(base_ref, cwd)
    has_test = any(is_test_path(f) for f in files)
    renames = rename_map(base_ref, cwd)
    security: list[str] = []
    for f in files:
        if is_security_path(f):
            security.append(f)
            continue
        if not _CANDIDATE_RE.match(f):
            continue
        for line in changed_lines(base_ref, f, cwd, renames.get(f)):
            if line_is_security_relevant(line):
                security.append(f)
                break
    return security, has_test


def main(argv: list[str]) -> int:
    if len(argv) < 2:
        print("usage: check_security_cochange.py <base-ref> [app-dir]",
              file=sys.stderr)
        return 2
    base_ref = argv[1]
    cwd = argv[2] if len(argv) > 2 else os.getcwd()
    security, has_test = scan(base_ref, cwd)
    if security and not has_test:
        for f in security:
            print(f)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
