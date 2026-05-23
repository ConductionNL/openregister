# Learning Candidates — opsx-annotate

Unverified observations awaiting promotion to [learnings.md](learnings.md).

## Promotion flow

```
Capture Learnings step -> learning-candidates.md -> (promotion criteria met?) -> learnings.md -> SKILL.md rules
                                                    ↓ no
                                                 discard after 30 days
```

## Promotion criteria (any one is sufficient)

1. **Confirmation** — the observation has been independently confirmed in 3+ executions of the skill.
2. **Eval resolution** — the observation explains a measured failure in an eval (see `evals/grading.json`) AND the fix has been verified.
3. **User endorsement** — the user has explicitly asked the skill to always apply the observation.

Promotion = move the entry from this file into the corresponding section of `learnings.md` and append `(promoted YYYY-MM-DD)` to the line.

## Discard rule

Entries older than 30 days without promotion should be removed during consolidation. If an observation keeps recurring without meeting promotion criteria, that itself is a signal — re-open it as an Open Question in `learnings.md` instead.

## Candidates

Format: `- YYYY-MM-DD — observation (section: patterns|mistakes|domain|open-question) [seen: N executions]`

<!-- Append candidates below. Keep the block in reverse chronological order. -->

- 2026-04-30 — Step 10 (archive ghost change) via `mv changes/X archive/X` leaves uncommitted deletions on the source path. The `git add -A` in step 8 only catches what existed at that moment; the move happens AFTER the commit. Workaround: include a final commit cycle after the archive step that re-runs `git add -A && git commit` for the cleanup. Better: use `git mv` instead of plain `mv` so git tracks the rename atomically. Observed: PR #1365 needed an extra "remove ghost change from changes/" commit AFTER the PR was created. (section: mistakes) [seen: 1 execution]

- 2026-04-30 — Idempotent guard works: a fresh run on a previously-annotated app (openregister had `retrofit-annotate-openregister-2026-04-23` from a prior pass with 141 files annotated) correctly fired the guard, asked the user, and accepted "Fresh run" cleanly. The new ghost change `retrofit-annotate-openregister-2026-04-30` coexisted with the prior one — both file-level and method-level docblocks accepted multiple `@spec` tags side-by-side, and PHPCS passed clean. Confirms the "Partial retrofit state" guardrail in SKILL.md works as designed. (section: patterns) [seen: 1 execution]

- 2026-04-30 — Subagent delegation works for 67-file annotation pass: same pattern as opsx-coverage-scan large runs — main agent sets up the ghost change scaffold + branch, delegates the per-file annotation work to one general-purpose subagent with a complete file→methods→task_num map in the prompt, then runs PHPCS to verify. Subagent returned in ~45 minutes with 0 PHPCS errors. Suggests promoting "delegate per-file annotation when file count > ~20" to SKILL.md once confirmed in 2+ runs. (section: patterns) [seen: 1 execution]
