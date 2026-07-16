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
