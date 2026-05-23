# opsx-annotate — Learnings

Dated, atomic observations from executions of this skill. One insight per bullet.

The **Capture Learnings** step in `SKILL.md` appends here directly for high-confidence observations and to [learning-candidates.md](learning-candidates.md) for unverified ones.

**Consolidation trigger:** review and consolidate when this file exceeds ~80–100 entries. Merge duplicates, remove outdated items, promote validated principles into SKILL.md guardrails/rules under `Consolidated Principles`.

## Patterns That Work

- 2026-04-23 — One file at a time, one Edit call per file is safer than batching. The skill's guardrail "Sequential Edits only — Edit tool's file-state tracking doesn't safely batch across files" came out of the same pattern the sibling `opsx-coverage-scan` skill uses (dispatching to a single subagent rather than fanning out). Sequential wins when each step depends on the previous file's state.
- 2026-04-23 — The ghost-change pattern (named `retrofit-annotate-{app}-{YYYY-MM-DD}`, archived at end of run) makes `@spec` tag paths textually stable even after the change moves to `openspec/changes/archive/`. No live lookup means no broken tags later.

## Mistakes to Avoid

- 2026-04-23 — Do NOT reorder existing `@spec` / `@license` / `@copyright` tags to satisfy PHPCS. The ADR-003 order is fixed — if PHPCS disagrees, fix the PHPCS config, not the tags. Risk: a well-meaning autofix that breaks every other file's format in a later `composer cs:fix` run.
- 2026-04-23 — Do NOT use `sed`/`awk`/`python` to batch-edit docblocks. The project rule is Edit tool or full-file Write (if the linter reverts). Scripted edits have produced silent breakage (wrong indentation, mangled docblock nesting) in prior retrofit attempts across the apps-extra workspaces.
- 2026-04-28 (openregister) — Do NOT place `@spec` immediately after `@return` without a blank comment line between them. PHPCS enforces a blank line before the `@spec` tag when it follows any other docblock tag. Correct form:
  ```php
  * @return Configuration[] Array of configuration entities
  *
  * @spec openspec/changes/retrofit-.../tasks.md#task-N
  ```
  The missing blank line produces a PHPCS error like "Expected 1 blank line before summary; 0 found." Run `phpcbf` to auto-fix indentation errors; blank-line errors must be fixed manually.
- 2026-04-28 (openregister) — Do NOT use background agents for annotation batches of 70+ files. Background completion cannot be monitored and partial annotation leaves the branch in ambiguous state. Use foreground agents; split into ≤ 40-file sub-batches if needed.
- 2026-04-28 — Do NOT attempt `rm -rf` on ghost change directories after archiving. This command is blocked in the project environment. Delete files individually with `rm` per file, then `rmdir` on empty directories. Allow a few extra minutes for cleanup of nested dirs.

## Domain Knowledge

- 2026-04-23 — `@spec` points at a **task in a change**, never directly at a REQ. This is what lets retrofit work: a ghost change creates a synthetic task list that maps Bucket 1 entries to something `@spec` can reference. Without the task indirection, annotations would have to change whenever REQs renumber.
- 2026-04-23 — The `retrofit` + `annotation-only` PR labels matter for reviewer routing. Annotation-only PRs can be approved after a quick tag spot-check; mixing logic changes into these PRs defeats the separation that makes them easy to review. Labels are enforced by the PR template, not just convention.

## Open Questions

- 2026-04-23/24 (openregister) — **Resuming a skill run after interleaved human work on the same branch is a load-bearing unhappy path that the skill's numbered steps don't cover.** On openregister: a parallel session had scaffolded + annotated 66 files + pushed the branch + stacked an unrelated feat commit on top. Discovery came from `git log` much later than it should have. Fix candidate: Step 1.5 should check (a) does the target retrofit branch already exist locally or on origin? (b) does the ghost change dir already exist on disk? (c) does HEAD have any `@spec openspec/changes/retrofit-annotate-*` tags not yet ours? Any "yes" → offer resume / reset / new-dated-run.
- 2026-04-23/24 — **Merging `development` mid-flow (to drop an unrelated feat commit) worked cleanly with `ort` strategy** on openregister — all 66 pre-existing annotations survived auto-merges on lib/Cron and lib/Service/Edepot files. Annotation content lives in docblock regions that rarely overlap with typical upstream changes (DI type swaps, new methods, refactors). This is evidence that mid-flow rebases/merges onto the annotation branch are low-risk if the branch isn't under review yet.
- 2026-04-23 — Should the skill support a `--capability <name>` flag to chunk Bucket 1 by capability when the scan produces > 150 methods? `opsx-coverage-scan` learnings note this as an Open Question too (openregister produced 678 Bucket 1 methods) — the need is real but the ergonomic answer is not obvious (per-capability PRs → coordination tax; single mega-PR → unreviewable).
- 2026-04-23 — Is the `.git-blame-ignore-revs` instruction ("each developer must enable it once") low-friction enough that developers will actually do it? If not, retrofit commits pollute `git blame` forever. Worth a follow-up survey.
- 2026-04-23 — **Eval-surfaced weakness (baseline 0.68, see `evals/grading.json`): the Idempotent guardrail is stated as text (line 262 "detect this and stop, asking") but is NOT wired into any of the 13 numbered steps.** `idempotent-rerun` eval scored 0.00 — running the skill twice against the same app silently creates a second dated ghost change. Cross-cutting theme with the sibling `opsx-coverage-scan` large-app-asks-confirmation eval (also 0.00, same root cause): Hydra skill Guardrails sections describe invariants that never made it into the numbered sequence. → ✅ Promoted to SKILL.md Step 1.5 (2026-04-24).
- 2026-04-23 — Eval-surfaced gap in Step 1: SKILL.md says "< 24h old" but doesn't name the `generated_at` JSON field or specify parsing method. A well-meaning implementation could use filesystem mtime instead. → ✅ Promoted to SKILL.md Step 1 (2026-04-24).

## Consolidated Principles

Validated after 3+ confirmations or after resolving a measured eval failure.

- **Sequential Edit-tool writes, never scripted.** The skill's guardrail and the hydra-gate-spdx skill agree — Edit or full-file Write is the only safe path. Already in SKILL.md Guardrails; keep visible.
- **Ghost change per run, archived at end.** The dated name + end-of-run archival is load-bearing: it lets the same app be retrofitted multiple times without tag conflicts.
