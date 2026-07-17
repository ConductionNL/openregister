# Design — spec-anchor-repair (OpenRegister)

## Category breakdown (OpenRegister, base `origin/development`)

5,051 broken `@spec` anchors, classified by recoverability:

| Category | Count | Disposition |
|---|---|---|
| **(c) archived change → canonical spec recoverable** | 3,643 | **auto-repointed** |
| &nbsp;&nbsp;↳ exact requirement-heading match | 896 | `specs/<cap>/spec.md#requirement-<slug>` |
| &nbsp;&nbsp;↳ capability proven, heading not matched | 2,747 | `specs/<cap>/spec.md` (file-level) |
| **(d) non-annotate tasks.md (no `task-N: cap#REQ` line)** | 874 | flag — needs spec-delta read (often multi-cap → ambiguous) |
| **(d) non-tasks.md ref (decimal task / design.md / proposal.md / re-headed specs anchor)** | 445 | flag |
| **(c) capability archived/deleted — requirement genuinely gone** | 74 | flag — the tag is a lie |
| **(d) no-fragment tasks.md ref, slug not a canonical capability** | 14 | flag |
| **(d) archived change dir not located** | 1 | flag |
| **Total dangling flagged for human triage** | **1,408** | `residual-dangling.md` |

## The repointer's conservatism rules

`tool/resolver.py` repoints **only** on unambiguous, verifiable signals:

1. **`changes/<slug>/tasks.md#task-N`** — locate the archived `tasks.md`
   (deterministic paths first, then the exact date-prefixed
   `changes/archive/YYYY-MM-DD-<slug>/` convention — a unique match only).
   Parse the `task-N` line for a `<cap>#<REQ>` token, or fall back to the
   enclosing `## <cap>` section heading. The capability name is taken
   **verbatim** — never inferred.
2. **Anchor granularity** is used only on an **exact** requirement-heading text
   match (`requirement-` + `slugify(title)` equals a real heading slug in the
   canonical spec). Otherwise the fragment is dropped and the anchor becomes
   capability-level — an honest downgrade, never a positional/fuzzy guess.
3. **`changes/<slug>/specs/<cap>/spec.md#anchor`** → canonical
   `specs/<cap>/spec.md`, keeping the anchor only if it still resolves.
4. **Post-condition gate**: every proposed new target is re-checked with
   gate-46 logic *before* it is written. A candidate that would not resolve is
   rejected and the anchor stays dangling (observed: 0 rejects on OR).
5. Anything not covered by 1–3 → **DANGLING**, never guessed.

## Comment-only proof (why this is safe to script)

The "no scripting for code changes" rule guards against a script mangling
*logic*. This edit touches only docblock `@spec` comment tags, and the proof is
mechanical:

- The rewrite runs through the gate-46 `@spec` tag regex — only a tag's target
  substring can change.
- **Assertion 1**: `git diff --unified=0` — every `+`/`-` line contains `@spec`
  (OR: 0 non-`@spec` lines out of 7,030).
- **Assertion 2**: `git diff --numstat` — every file has `insertions ==
  deletions` (1:1 line replacement; OR: 0 asymmetric files). No line added or
  removed ⇒ no statement added or removed.
- **Assertion 3** (unit test): logic lines byte-identical before/after on a
  synthetic fixture; a genuinely-dangling anchor is left untouched.

## Test

`tool/test_repoint.py` builds a synthetic app root and proves: (1) a moved
anchor whose task line names a canonical capability + requirement heading is
repointed to the exact anchor; (2) an anchor whose capability has no canonical
spec is left dangling; (3) the rewrite is comment-only (logic byte-identical).

## Non-goals

- Repointing the 1,408 residual-dangling anchors — deferred to human triage.
- Re-heading canonical specs to ADR-037 form — out of scope.
- Adding new requirements — this change asserts existing traceability, it does
  not author it.

## Round-2 addendum (2026-07-16) — three tool defects found by wider use

Running this tool across the remaining fleet (procest, opencatalogi,
softwarecatalog, decidesk, docudesk, openconnector) exposed three defects in the
version that shipped here. All three are fixed in `tool/`, each covered by a
regression test verified to FAIL against the old tool:

1. **CRLF normalisation** (found on procest). Universal-newline read + default
   write silently rewrote CRLF files to LF — 2,462 non-`@spec` diff lines, a
   whitespace reformat wearing an anchor-repair hat. The comment-only assertion
   caught it. Fixed with `newline=''` on read and write; files that cannot be
   decoded losslessly are now skipped rather than rewritten via
   `errors='replace'` (which would burn U+FFFD into source).
2. **Raw-fragment leak** (found on decidesk). The `@spec` regex swallows a
   sentence-ending `.` into the fragment. The resolver matched on
   `slugify(frag)` but emitted the RAW frag, so it "repaired" an anchor into
   another gate-46-broken anchor — and its own post-condition check was blind
   because `is_broken()` used the same lenient compare. Fixed by emitting
   `slugify(frag)` and making `is_broken()` byte-identical to gate-46 (verbatim
   fragment compare).
3. **No self-heal path** for defect 2's output: the leaked anchors are already
   `openspec/specs/...`, a shape the resolver did not recognise, so they were
   stuck as DANGLING. Added shape 3 — normalise a fragment on an
   already-canonical target when only its punctuation/case differs from a real
   heading. Unambiguous: it does not choose a target, it spells the existing one
   the way gate-46 reads it.

Defect 2 had already shipped, leaving residue: **openregister 3, pipelinq 49,
shillinq 0**. Both are repaired by this round. The reconciliation identity
`before − repointed == after` (tool count vs gate-46 count) is what exposed
defect 2 and is now checked per app.

**OpenRegister gate-46: 1,411 → 1,408.** (The original round reported 1,408 but
actually landed 1,411 — the 3-anchor gap *was* the raw-fragment leak.)
