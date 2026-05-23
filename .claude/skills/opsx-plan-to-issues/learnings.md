# Learnings — opsx-plan-to-issues

## Patterns That Work

- 2026-04-24 — The multi-select with "All changes" option prevents a common workflow failure: when a developer has accumulated several pending changes, presenting them individually biases toward selecting only the one they were just discussing. The explicit "All changes" option makes the batch path equally easy to reach and ensures all changes get tracking issues before implementation starts.
- 2026-04-24 — The preview-and-confirm step (Step 3.5) before creating any issues has eliminated accidental duplicate issues in practice. Showing the full issue body preview also surfaces tasks.md parsing errors — misformatted checkbox lines, missing section headers — before they are committed to GitHub and become harder to clean up.
- 2026-04-24 — Label deduplication across all selected changes (collecting unique labels from every change before any `create_label` call) avoids rate-limiting from repeated label-existence checks when batch-creating issues for three or more changes in a single run.

## Mistakes to Avoid

- 2026-04-24 — Omitting the duplicate-issue guard (checking whether `plan.json` already has a non-null `tracking_issue`) when re-running the skill after a partial failure creates a second GitHub issue for the same change with no link between them. The plan.json then points to the newer issue number, and the older issue accumulates stale checkboxes that never get ticked off. Always read `plan.json` before creating an issue, not after.
- 2026-04-24 — Using `git remote get-url origin` without first checking `project.md` can resolve to a fork URL rather than the canonical `ConductionNL/<app>` repo, causing labels and issues to be created on the wrong repository. The priority order in Step 2 (project.md table first, git remote as fallback) exists precisely to avoid this.

## Domain Knowledge

- 2026-04-24 — `plan.json`'s `tracking_issue` field is the permanent join key used by every downstream skill. `/opsx-apply` reads it to know which GitHub issue to update checkboxes on; `/opsx-archive` uses it to close the issue when the PR merges. Writing the issue number into plan.json immediately after `create_issue` returns (Step 5) — not deferred to a later step — ensures the field is populated even if the session is interrupted before the "what's next" prompt.
- 2026-04-24 — Label colors follow the Hydra org-wide convention: `openspec` is always blue (`0075ca`), app-name labels are purple (`7057ff`), and per-spec labels are red (`d93f0b`). Using different colors breaks the board's fast visual read. These values are canonical and should not be adjusted per-project.
- 2026-04-24 — When invoked from an orchestrating skill (e.g., `/opsx-apply-loop`), the Step 6 AskUserQuestion is suppressed and the skill outputs the sentinel line `✅ plan-to-issues complete — NEXT STEP: immediately continue to Step 3 of apply-loop without pausing.` The orchestrator parses this sentinel to detect completion and continue. Any change to this exact output string breaks the orchestration contract.

## Open Questions

- 2026-04-24 — The spec label derivation (one label per subdirectory under `specs/`) works correctly when spec directories are named after domain areas, but the behaviour is undefined when `specs/` is absent or empty. It is unclear whether the skill should fall back to no spec labels or prompt the user to confirm.

## Consolidated Principles
<!-- Promoted from patterns after 3+ confirmations — these become standing rules -->
