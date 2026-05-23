# Learnings — opsx-apply

## Patterns That Work
<!-- Implementation approaches that consistently navigate OpenSpec tasks well -->

- 2026-04-24 — Loading ALL applicable ADRs before touching any code prevents late-stage rework: violating ADR-001 (OpenRegister data layer) or ADR-005 (security) mid-implementation forces revisiting already-completed tasks. A two-minute upfront read of every `adr-*.md` file consistently eliminates review findings that would otherwise surface at the code-review stage.
- 2026-04-24 — Running `composer check:strict` after each task rather than only at the end keeps the feedback loop tight. Catching a PHPCS error on the file just written is a one-line fix; catching 20 errors across 7 files after full implementation often triggers a second review cycle.
- 2026-04-24 — The progress comment upsert pattern (search for `## Pipeline Progress` before posting) is essential in CI: without it, each builder run on a retry cycle adds a new comment instead of updating the existing one, and the issue accumulates noise that obscures the actual state.

## Mistakes to Avoid
<!-- Errors, wrong assumptions, or approaches that caused implementation problems -->

- 2026-04-24 — Skipping the GitHub issue checkbox update after each task and batching them at the end is fragile: if the session is interrupted mid-implementation the issue body lags behind `tasks.md`, and the next orchestrator cycle compares them incorrectly, re-queuing already-completed tasks.
- 2026-04-24 — Implementing tasks without seed data when a schema is introduced (violating ADR-001 `@self` envelope requirement) causes the recheck gate to fail even when all code quality checks pass. The `design.md` Seed Data section must be consulted before marking any schema-related task complete.

## Domain Knowledge
<!-- Facts about OpenSpec workflows, task structures, or project-specific implementation patterns -->

- 2026-04-24 — The Headless Mode Contract is the critical boundary between interactive and CI use of this skill. When `HYDRA_HEADLESS=1` is set, the model gate (block on Haiku), the Step 6 confirm prompt, Step 7 pause points, and the Step 10 "what's next" menu are all suppressed — but every write to `tasks.md`, the GitHub issue body, `plan.json`, and the progress comment remains mandatory. Drift between this table and `images/builder/entrypoint.sh` breaks the pipeline silently, so any new interactive pause added to SKILL.md must be mirrored in the entrypoint's override list.
- 2026-04-24 — `plan.json`'s `tracking_issue` field is the single join key between the local artifact tree and GitHub. Every step that touches the GitHub issue (checkbox update, progress comment) reads this field at runtime; if it is null (issue not yet created) those steps must be skipped gracefully rather than erroring, because the skill can be run before `/opsx-plan-to-issues`.
- 2026-04-24 — The `contract.md` artifact, when present, overrides design.md for endpoint/schema definitions. Deviating from a declared error code or response shape in `contract.md` is treated as a spec violation by the code reviewer, even if the deviation appears to be an improvement. Read it as a frozen interface, not a suggestion.

## Open Questions
<!-- Unresolved challenges or edge cases for future investigation -->

- 2026-04-24 — The 3-cycle quality fix limit (maximum 3 rounds before reporting remaining issues and continuing) has not been validated against real multi-issue PHPCS outputs. It is unclear whether 3 rounds is always sufficient or whether some PHPCS rule combinations produce cascading new violations after each auto-fix pass.

## Consolidated Principles
<!-- Promoted from patterns after 3+ confirmations — these become standing rules -->
