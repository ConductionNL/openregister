# Tasks: bulk-action-jobs

## 1. The job

- [ ] 1.1 A bulk job carries an action, a selection, an actor, an optional justification, a state and per-member outcomes.
- [ ] 1.2 The selection is an explicit id list or a query with its filters, and the job records which and the count at creation (D-2).
- [ ] 1.3 An instance-level ceiling on the largest selection one job may carry; a larger selection is refused at creation, naming the ceiling.

## 2. Preview and commit

- [ ] 2.1 Creation produces a `previewed` job with a per-object outcome and writes nothing (D-1).
- [ ] 2.2 Commit runs the same executor with `commit: true`; a query-backed selection is re-resolved and the delta is reported (D-2).
- [ ] 2.3 Outcomes distinguish applied, skipped with a reason and refused with the rule that refused it (D-3).
- [ ] 2.4 An action declares its guards; the homogeneity guard refuses a selection spanning more than one schema version, naming the versions and counts (D-6).
- [ ] 2.5 An action declares whether a justification is required; the commit is refused without one and the text lands in each member's audit entry (D-7).

## 3. Running

- [ ] 3.1 A running job reports position and counts; the list of a user's own jobs carries running and finished ones.
- [ ] 3.2 Cancel stops before the next object, keeps what is committed and reports the boundary (D-4).
- [ ] 3.3 A retry skips members already applied (D-5).
- [ ] 3.4 The per-object outcome of a finished job is downloadable, including the skipped members and their reasons.

## 4. Audit

- [ ] 4.1 The job is one audited act with its members; each member carries its own audit trail entry referencing the job.

## 5. Tests

- [ ] 5.1 `tests/e2e/ci/bulk-action-jobs.spec.ts`: select a set, preview, read the skips, commit, watch progress, download the outcome.
- [ ] 5.2 Unit tests: preview and commit agreeing on the same fixture, refused against skipped, the version guard, the ceiling, cancel at a boundary, retry idempotence and the growing query selection.
- [ ] 5.3 `openspec validate bulk-action-jobs --strict`.

## 6. Hand over

- [ ] 6.1 Hand the progress and outcome envelope to the dossiq lane and to nextcloud-vue, with the cluster 52 candidate ids.
