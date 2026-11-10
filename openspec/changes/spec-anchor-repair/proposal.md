---
kind: config
---

## Why

A fleet audit of `@spec openspec/...` traceability anchors (ADR-020 / hydra
gate-46 `spec-anchor-existence`) found **5,051 broken anchors in OpenRegister**
alone (24,502 fleet-wide across 23 apps). A broken anchor is a docblock/JSDoc
`@spec` tag whose target file or `#requirement` heading no longer resolves — so
the code *claims* to implement a requirement the tag cannot point at. The
traceability layer is largely fiction.

The dominant cause is mechanical, not conceptual: the `/opsx-annotate` retrofit
runs tagged methods with `@spec openspec/changes/<slug>/tasks.md#task-N`
pointing at the **change directory**, not the canonical `openspec/specs/`.
When each change was archived (`openspec/changes/ → changes/archive/<date>-<slug>/`)
the target evaporated. This is the exact failure MEMORY warns about: *"@spec
must target canonical `openspec/specs/`, NEVER a change dir — they evaporate on
archive."* The intended requirement is still recoverable: the archived
`tasks.md` line encodes the capability and the requirement heading text verbatim
(`- [x] task-7: widget-registry#REQ-001 — The system MUST …`).

## What Changes

A **deterministic, comment-only** repointer (`tool/repoint.py` + `tool/resolver.py`)
rewrites every unambiguously-resolvable broken `@spec` anchor to its canonical
`openspec/specs/<cap>/spec.md[#requirement-<slug>]` target. It is conservative
by construction — it only repoints when the canonical target *verifiably
resolves under gate-46 logic*, and flags everything else for human triage
(`residual-dangling.md`) rather than guessing.

Applied to OpenRegister (base `origin/development`):

- **3,643 anchors auto-repointed** across 695 files
  (896 to an exact requirement heading, 2,747 to the capability spec file).
- **1,408 anchors left dangling** and filed for human review (see
  `residual-dangling.md` + the umbrella issue).
- **Comment-only proof**: every one of the 7,000+ changed lines contains
  `@spec`; every touched file has `insertions == deletions` (1:1 line rewrite);
  no PHP/Vue/JS logic byte changes.
- **Gate-46 re-verify**: repointed anchors resolve — OR broken count
  5,051 → 1,408.

This change ships only docblock `@spec` retargets + the tool, its unit test, and
the dangling triage list. No runtime behaviour changes.

## Impact

- Affected: docblock `@spec` tags in `lib/` and `src/` only.
- Risk: negligible — comment-only, mechanically proven, gate-46-verified.
- Follow-up: the 1,408 residual-dangling anchors (umbrella issue); the
  canonical home for the tool is `hydra/scripts/` for reuse across the fleet.
