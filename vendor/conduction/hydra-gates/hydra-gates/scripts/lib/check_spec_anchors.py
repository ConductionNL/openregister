#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
"""Gate-46 spec-anchor-existence — does the `@spec` tag point at something real?

Gate-16 checks that a `@spec` tag is PRESENT. This gate checks that its
target RESOLVES: the file exists, and — when the tag carries a `#fragment`
— that the fragment names a heading or task item that actually exists in
that file. Observed 2026-04-27 on opencatalogi#85 where
`@spec openspec/specs/federation/spec.md#requirement-directory-self-detection`
pointed at a requirement nobody had written.

WHY THIS LIVES IN A FILE
------------------------
It used to be a ~140-line heredoc inside ``run-hydra-gates.sh``. A gate with
no test suite of its own is a gate whose false-positive rate nobody can
measure, and this one's rate was 56%. Moving it here puts it under
``tests/run-helper-suites.sh``, which discovers every ``scripts/lib/test_*``
automatically — see ``test_check_spec_anchors.py``.

WHAT A FRAGMENT IS ALLOWED TO NAME
----------------------------------
A repository's `@spec` tags are written by humans and by three generations
of retrofit tooling, so the same requirement is addressed several ways. All
of these name a requirement that EXISTS, and none of them is a dangling
reference:

  heading, kebab-cased      `## Requirement: Foo Bar`      ← `#requirement-foo-bar`
  heading, GitHub's own     `## A subscription's retry`    ← `#a-subscriptions-retry`
  heading prefix            `### Task 8 — long title`      ← `#task-8`
  the `:` tail              `### Requirement: Foo Bar`     ← `#foo-bar`
  the `:` tail's own head   `### Requirement: REQ-001: …`  ← `#REQ-001`   (A)
  a delimited id token      `### Requirement: Foo (REQ-5)` ← `#REQ-5`     (B)
  a task item id            `- [x] 2.3 Add the thing`      ← `#task-2.3`
  the Nth checkbox item     (positional retrofit tags)     ← `#task-4`

(A) and (B) are the two shapes that produced 1,295 of the fleet's 1,995
gate-46 findings. Both were rejected for the same structural reason: the
full-heading rule accepted a PREFIX match (`heading.startswith(frag + '-')`)
but the `:`-tail rule accepted only EQUALITY, and nothing looked inside
parentheses at all. Neither rejection was evidence about the requirement —
in every sampled case the requirement was right there in the heading.

WHAT STILL FAILS, AND MUST
--------------------------
A fragment naming an id that appears NOWHERE in the file still fails. The
relaxations above are all anchored to text the heading actually contains:
(B) requires the id to be a *delimited token* (inside `(…)`/`[…]`), not a
substring, and (A) requires it to be the tail's own leading `:`-segment. A
tag that invents `#REQ-999` against a file whose headings stop at REQ-007
resolves to nothing and is reported — that is the case this gate exists for,
and ``test_check_spec_anchors.py`` proves it still fires for every rule that
was loosened.

Usage:
    check_spec_anchors.py <log-path> <file>...
"""
from __future__ import annotations

import glob
import os
import re
import sys

# Match `@spec openspec/...` but NOT `@spec exclude ...`
TAG = re.compile(r'@spec\s+(openspec/[^\s`\'"]+)')
DATE = re.compile(r'\b\d{4}-\d{2}-\d{2}\b')
HEADING = re.compile(r'^\s*#{1,6}\s+(.+?)\s*$')

# Any single-character checkbox marker, not just ` `/`x`/`X`.
#
# OpenSpec task lists use `- [~]` for "partially done" and `- [-]` for
# "dropped". The old `[ xX]` class saw neither. That cost twice: the item's
# own id became unaddressable, AND every positional `#task-N` after it in
# the file silently resolved to the wrong item, because the counter never
# incremented for the skipped line. A wrong positional resolution is worse
# than a missing one — it can report PASS against a different task.
CHECKBOX = re.compile(r'^\s*-\s*\[.\]')
CHECKBOX_ID = re.compile(r'^\s*-\s*\[.\]\s*(?:\*\*)?([A-Za-z0-9][A-Za-z0-9.\-]*)')

# `(REQ-PAY-001)`, `[REQ-005]`, `(REQ-PAY-001, REQ-PAY-003)`
DELIMITED = re.compile(r'[(\[]([^)\]]{1,120})[)\]]')

# `#T1`, `#T02`, `#t9` — the shorthand spelling of "Task N".
#
# Two repos' annotation tooling emits it and nothing has ever accepted it:
# portaliq writes `#T3` against `### Task 3: Fail-closed trust re-checks…`
# and procest writes `#T02` against `## 2. Controller + routes`. The task is
# right there in the file; only the spelling of the reference is unusual.
# It is NOT a GitHub anchor — clicking it lands nowhere — but this gate asks
# whether the tag NAMES SOMETHING REAL, and `Task 3` is real.
#
# Deliberately NOT wired into the positional-checkbox rule. `#task-N` means
# "the Nth checkbox" as well as "the heading numbered N"; giving `#T99` that
# second reading too would let it resolve against any file with 99 checkboxes,
# which is evidence about nothing. `T<n>` resolves against HEADINGS only, so
# `#T99` against a nine-task file still fails — see ``test_check_spec_anchors``.
TASK_SHORTHAND = re.compile(r'^[Tt](\d+)$')

# An "id-like" token: something that could be a requirement/task identifier
# rather than a word of prose. It must contain a digit — `REQ-BIE-004-01`,
# `2.3`, `T01`, `5.2` do; `retry`, `policy`, `Requirement` do not. Requiring a
# digit is what stops "lift the tokens out of the heading" from degenerating
# into "match any word in any heading", which would be a blind gate.
IDLIKE = re.compile(r'^[A-Za-z0-9][A-Za-z0-9._\-]*$')


def slugify(text: str) -> str:
    """Kebab-case the visible heading text.

    Every non-alphanumeric run becomes a single `-`, so an apostrophe or a
    slash becomes a separator rather than vanishing. This is the shape the
    fleet's own retrofit tooling emits.
    """
    t = text.strip().lower()
    t = re.sub(r'[^a-z0-9]+', '-', t).strip('-')
    return t


def gh_slugify(text: str) -> str:
    """GitHub's own heading-anchor rule.

    Drop every character that is not alphanumeric, dash, underscore or
    space, then collapse spaces to dashes. It parts company with
    ``slugify`` on punctuation *inside* a word: GitHub publishes
    "A subscription's retry/backoff policy" as
    ``#a-subscriptions-retrybackoff-policy`` where ``slugify`` produces
    ``#a-subscription-s-retry-backoff-policy``.

    Both shapes are accepted rather than one replacing the other: a tag
    written to satisfy the kebab rule still resolves, a tag written against
    the anchor a reader's browser actually lands on still resolves, and
    either way the heading has to EXIST — which is what this gate is for.
    Observed 2026-08-05 on openconnector: 137 findings, none of them real.
    """
    t = text.strip().lower()
    t = re.sub(r'[^a-z0-9\-_ ]+', '', t)
    return re.sub(r'\s+', '-', t).strip('-')


def dedate(name: str) -> str:
    """`retrofit-2026-04-28-b2b-crossrefs` and `retrofit-b2b-crossrefs-2026-04-28`
    both normalise to `retrofit-b2b-crossrefs`: archiving moves the date token."""
    return re.sub(r'-+', '-', DATE.sub('', name)).strip('-')


def _both(text: str) -> set[str]:
    """Both slug dialects for one piece of text, empties dropped."""
    return {s for s in (slugify(text), gh_slugify(text)) if s}


def _idlike_tokens(text: str) -> set[str]:
    """Slugs of the id-shaped whitespace tokens inside *text*.

    `Scenario REQ-BIE-004-01` yields `req-bie-004-01` and drops `scenario`;
    `Task 2.3 of giant` yields `2-3` and drops `task`, `of`, `giant`. The
    digit requirement is the whole safety property here.

    Only the kebab dialect is emitted, deliberately. ``gh_slugify`` drops the
    dot, so `Task 2.3` would also yield `23` — which a positional `#task-23`
    tag would then resolve against. Whole-heading GitHub anchors are already
    covered by the long aliases; a lifted token does not need a second
    dialect, and giving it one manufactures collisions.
    """
    out: set[str] = set()
    for tok in text.split():
        tok = tok.strip('.,;:*_`"\'')
        if not tok or not any(c.isdigit() for c in tok):
            continue
        if IDLIKE.match(tok):
            s = slugify(tok)
            if s:
                out.add(s)
    return out


def heading_aliases(head: str) -> tuple[set[str], set[str]]:
    """Split a heading into (long_aliases, token_aliases).

    ``long_aliases`` are full titles — a fragment may match them by equality
    OR by being a dash-delimited PREFIX of them (`#task-8` ← `### Task 8 —
    long title`). ``token_aliases`` are short identifiers lifted out of the
    heading (`REQ-PAY-001`); those require EQUALITY, because prefix-matching a
    short id would let `#req` resolve against `REQ-PAY-001` and that really
    would be a blind gate.
    """
    long_aliases: set[str] = set(_both(head))
    token_aliases: set[str] = set()

    # The heading with its parenthesised/bracketed suffix removed.
    # `## Webhooks (Task 2.9 of giant)` is addressed as `#webhooks` — the
    # bracket carries provenance, not title. Without this the only way
    # `#webhooks` could resolve is by prefix, and single-segment prefix
    # matching is exactly what makes `#scenario` resolve everywhere.
    bare = DELIMITED.sub(' ', head).strip()
    if bare and bare != head:
        long_aliases |= _both(bare)

    if ':' in head:
        lead, tail = head.split(':', 1)
        long_aliases |= _both(tail)
        bare_tail = DELIMITED.sub(' ', tail).strip()
        if bare_tail and bare_tail != tail:
            long_aliases |= _both(bare_tail)
        # `### Requirement: REQ-001: List and search zaken` — the tail's own
        # leading `:`-segment is the requirement id.
        if ':' in tail:
            token_aliases |= _both(tail.split(':', 1)[0])
        # `#### Scenario REQ-BIE-004-01: Cron triggers export run creation` —
        # the id sits in the segment BEFORE the colon, as its own token.
        token_aliases |= _idlike_tokens(lead)

    # `#### 5.2 Foo` ← `#5.2`.
    #
    # Restricted to an id-shaped lead. Taking the first word unconditionally
    # made `#scenario` resolve against every `#### Scenario …` heading and
    # `#requirement` against every `### Requirement: …` — the gate would
    # accept a tag that names a heading LEVEL rather than a requirement.
    token_aliases |= _idlike_tokens(head.split()[0] if head.split() else '')

    # `### Requirement: Foo (REQ-PAY-001)` / `[REQ-005]` / `(REQ-A-1, REQ-A-2)`
    # / `## BlastService (Task 2.3 of giant)`
    for m in DELIMITED.finditer(head):
        for piece in re.split(r'[,;/]', m.group(1)):
            piece = piece.strip()
            if piece:
                token_aliases |= _both(piece)
                token_aliases |= _idlike_tokens(piece)

    return long_aliases, token_aliases


def _matches(long_aliases: set[str], token_aliases: set[str], frags: tuple[str, ...]) -> bool:
    live = tuple(f for f in frags if f)
    if not live:
        return False
    if any(a in live for a in long_aliases):
        return True
    if any(a in live for a in token_aliases):
        return True
    # `-` boundary on the prefix rule keeps `#task-8` from matching `Task 89`.
    #
    # A SINGLE-SEGMENT fragment is excluded from prefix matching. `#task-8`
    # against `### Task 8 — long title` is the case the prefix rule exists
    # for and it has two segments. A one-word fragment prefix-matches on the
    # first word of a sentence-shaped title — `#scenario` against every
    # `#### Scenario …` in the repo, `#cron` against
    # `#### … Cron triggers export run creation` — which is evidence about
    # nothing.
    #
    # `#webhooks` against `## Webhooks (Task 2.9 of giant)` is a real
    # anchor and is NOT lost by this: the parenthesised suffix is stripped
    # in `heading_aliases`, so it resolves by EQUALITY instead.
    return any(
        a.startswith(f + '-')
        for a in long_aliases
        for f in live
        if '-' in f
    )


def has_anchor(md_path: str, fragment: str) -> bool:
    """True when *fragment* names a heading or task item in *md_path*."""
    frag_slug = slugify(fragment)
    frag_alt = slugify(re.sub(r'^task-', '', fragment))
    # The fragment VERBATIM, alongside its slugified forms.
    #
    # `heading_aliases` already produces both spellings of a heading — the
    # kebab one and the one GitHub actually publishes — but the fragment was
    # being pushed through `slugify()` first, and that rewrites `_` to `-`.
    # A tag copied from a real GitHub link therefore stopped matching the
    # gh alias it was copied FROM:
    #
    #   heading   ### Requirement: Trace-scoped call correlation via
    #                 call_log.sessionId (REQ-011)
    #   gh alias  …-via-call_logsessionid-req-011      ← what GitHub links to
    #   fragment  …-via-call_logsessionid-req-011      ← what the author wrote
    #   slugified …-via-call-logsessionid-req-011      ← what was compared
    #
    # which matches neither alias: not the gh one (underscore rewritten) and
    # not the kebab one (`call-log-sessionid`, split at the underscore).
    # Reported as "anchor not found" on a tag whose link works.
    frags = [frag_slug, frag_alt, fragment.lower()]
    # `#T3` / `#T02` — see TASK_SHORTHAND. Leading zeros are stripped because
    # the heading is `## 2. Controller + routes`, whose lifted id token is `2`.
    sh = TASK_SHORTHAND.match(fragment)
    if sh:
        n = sh.group(1).lstrip('0') or '0'
        frags.extend((f'task-{n}', n))
    frags = tuple(frags)
    pos_m = re.fullmatch(r'(?:task-)?(\d+)', fragment)
    positional = int(pos_m.group(1)) if pos_m else None
    item_count = 0
    try:
        with open(md_path, encoding='utf-8', errors='replace') as f:
            for line in f:
                m = HEADING.match(line)
                if m:
                    long_aliases, token_aliases = heading_aliases(m.group(1))
                    if _matches(long_aliases, token_aliases, frags):
                        return True
                    continue
                if CHECKBOX.match(line):
                    item_count += 1
                    # Positional convention used by reverse-spec retrofits:
                    # `#task-N` / `#N` addresses the Nth top-level checkbox.
                    if positional is not None and item_count == positional:
                        return True
                t = CHECKBOX_ID.match(line)
                if t and slugify(t.group(1).rstrip('.:')) in frags:
                    return True
        return False
    except OSError:
        return False


def find_repo_root(start: str | None = None) -> str | None:
    p = start or os.getcwd()
    while p and p != '/':
        if os.path.isdir(os.path.join(p, 'openspec')):
            return p
        p = os.path.dirname(p)
    return None


def build_archive_index(root: str) -> dict[str, list[str]]:
    """Archiving moves `openspec/changes/<name>/` to
    `openspec/changes/archive/<date>-<name>/` (or legacy
    `openspec/archive/<name>`, sometimes with the date token reshuffled).
    Tags keep pointing at the original path; resolve through this index
    rather than forcing a repo-wide retag."""
    index: dict[str, list[str]] = {}
    for pattern in ('openspec/changes/archive/*', 'openspec/archive/*'):
        for d in glob.glob(os.path.join(root, pattern)):
            if os.path.isdir(d):
                index.setdefault(dedate(os.path.basename(d)), []).append(d)
    return index


def build_capability_index(root: str) -> dict[str, list[str]]:
    """Every home a capability's `spec.md` can occupy, keyed by capability.

    THE ARCHIVING PROBLEM THIS SOLVES
    ---------------------------------
    A capability's spec moves house at least twice in its life:

      1. in flight   `openspec/changes/<change>/specs/<cap>/spec.md`
      2. archived    `openspec/changes/archive/<date>-<change>/specs/<cap>/spec.md`
      3. canonical   `openspec/specs/<cap>/spec.md`

    ``build_archive_index`` already carries a tag from (1) to (2), keyed on the
    CHANGE name. That is not enough, and the gap is what made this gate a
    treadmill:

      * The project rule — and, after this change, the phpcs SpecTagSniff that
        instructs authors — says point `@spec` at (3). But archiving in these
        repos does NOT reliably promote the delta spec into `openspec/specs/`.
        procest carries 18 tags at `openspec/specs/process-mining-bottlenecks/
        spec.md`, written exactly as instructed, whose spec only ever existed
        at (2). Following the correct advice produced a dangling reference.
      * The change name need not equal the capability name. procest's
        `realtime-updates-ui` capability lives under the change
        `adopt-live-updates-ui`, so no CHANGE-keyed index can find it — 10
        more findings.

    Keying on the CAPABILITY instead spans all three homes and is stable across
    both the archive move and the promotion that follows it, so a tag written
    once keeps resolving no matter which stage the change has reached. That is
    what "a rule that survives archiving" has to mean.

    THIS IS NOT A WIDENING OF WHAT COUNTS AS RESOLVED
    -------------------------------------------------
    It only supplies additional FILES to look in, and it is consulted only when
    every literal spelling has already failed (see ``resolve``). The fragment
    still has to name a heading that EXISTS in one of them. A tag naming a
    requirement nobody wrote fails exactly as before — doriath's 77 findings
    are all of that shape and this index rescues none of them, which is the
    point. ``test_check_spec_anchors.py`` pins that.
    """
    index: dict[str, list[str]] = {}
    patterns = (
        'openspec/specs/*/spec.md',
        'openspec/changes/*/specs/*/spec.md',
        'openspec/changes/archive/*/specs/*/spec.md',
        'openspec/archive/*/specs/*/spec.md',
    )
    for pattern in patterns:
        for f in glob.glob(os.path.join(root, pattern)):
            if os.path.isfile(f):
                index.setdefault(os.path.basename(os.path.dirname(f)), []).append(f)
    # The flat spelling, `openspec/specs/<cap>.md`, predates the directory form.
    for f in glob.glob(os.path.join(root, 'openspec/specs/*.md')):
        if os.path.isfile(f):
            index.setdefault(os.path.basename(f)[: -len('.md')], []).append(f)
    return index


def capability_of(path: str) -> str | None:
    """The capability a spec-path addresses, whichever home it names.

    Returns None for anything that is not a capability spec — `tasks.md`,
    `design.md`, `proposal.md` — because those are per-CHANGE documents with
    no canonical home to migrate to, and the change-keyed archive index is
    already the right resolver for them.
    """
    m = re.match(r'openspec/(?:specs|changes/[^/]+/specs|changes/archive/[^/]+/specs|'
                 r'archive/[^/]+/specs)/([^/]+)/spec\.md$', path)
    if m:
        return m.group(1)
    m = re.match(r'openspec/specs/([^/]+)\.md$', path)
    return m.group(1) if m else None


def _path_shapes(path: str) -> list[str]:
    """The equivalent spellings of one spec path.

    `openspec/specs/task-collaboration.md` and
    `openspec/specs/task-collaboration/spec.md` are the same spec — OpenSpec
    moved from the flat file to the directory form, and tags written before
    the move keep the old spelling. Reporting the old spelling as "target
    file not found" says the requirement is missing when the requirement is
    right there under the other name.
    """
    shapes = [path]
    if path.endswith('/spec.md'):
        shapes.append(path[: -len('/spec.md')] + '.md')
    elif path.endswith('.md'):
        shapes.append(path[: -len('.md')] + '/spec.md')
    return shapes


def resolve(
    path: str,
    root: str,
    archive_index: dict[str, list[str]],
    capability_index: dict[str, list[str]] | None = None,
) -> list[str]:
    """Existing candidate files for a tag path: the literal path, its
    flat/directory counterpart, and archived counterparts of both.

    Several archived dirs can share a de-dated key (e.g. two
    annotate-openregister retrofits from different dates), so candidates
    carrying the tag's own date token are tried first and ALL existing
    candidates are returned — the anchor check accepts a fragment found in
    any of them."""
    cands: list[str] = []
    for shape in _path_shapes(path):
        abs_path = os.path.join(root, shape)
        if os.path.exists(abs_path) and abs_path not in cands:
            cands.append(abs_path)
        m = re.match(r'openspec/(?:changes|archive)/([^/]+)/(.*)$', shape)
        if not m or m.group(1) == 'archive':
            continue
        name = m.group(1)
        dates = DATE.findall(name)
        dirs = archive_index.get(dedate(name), [])
        dirs = sorted(dirs, key=lambda d: 0 if any(t in os.path.basename(d) for t in dates) else 1)
        for d in dirs:
            cand = os.path.join(d, m.group(2))
            if os.path.exists(cand) and cand not in cands:
                cands.append(cand)
    # LAST RESORT ONLY — see build_capability_index.
    #
    # Consulted solely when every literal spelling has already failed, so a
    # tag whose own path resolves is judged against THAT file and nothing
    # else. Reaching here means the spec is not where the tag says it is;
    # the capability index says where it went. The anchor check that follows
    # is unchanged, so this converts "file not found" into a real anchor
    # verdict rather than into a pass.
    if not cands and capability_index:
        cap = capability_of(path)
        for cand in capability_index.get(cap or '', []):
            if cand not in cands:
                cands.append(cand)
    return cands


def scan_files(files: list[str], root: str | None = None) -> list[str]:
    """Return one finding line per unresolved `@spec` target."""
    root = root or find_repo_root() or os.getcwd()
    archive_index = build_archive_index(root)
    capability_index = build_capability_index(root)
    findings: list[str] = []
    for fp in files:
        try:
            with open(fp, encoding='utf-8', errors='replace') as f:
                src = f.read()
        except OSError:
            continue
        for m in TAG.finditer(src):
            target = m.group(1)
            if '#' in target:
                path, frag = target.split('#', 1)
            else:
                path, frag = target, None
            candidates = resolve(path, root, archive_index, capability_index)
            if not candidates:
                findings.append(f"{fp}: @spec target file not found → {target}")
                continue
            if frag and path.endswith('.md'):
                if not any(has_anchor(c, frag) for c in candidates):
                    findings.append(f"{fp}: @spec anchor not found in {path} → #{frag}")
    return findings


def main(argv: list[str]) -> int:
    if len(argv) < 2:
        print("usage: check_spec_anchors.py <log-path> <file>...", file=sys.stderr)
        return 2
    log_path, files = argv[1], argv[2:]
    findings = scan_files(files)
    with open(log_path, 'a', encoding='utf-8') as g:
        for line in findings:
            g.write(line + "\n")
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
