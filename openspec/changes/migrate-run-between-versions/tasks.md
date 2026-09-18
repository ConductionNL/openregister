# Tasks: migrate-run-between-versions

## 1. Engine

- [x] 1.1 `FlowRunMigrationService`: marking validation against the target with a mapping, dry run, transactional apply under the run lock, `migrated` log entry.
  **files**: `lib/Service/Flow/FlowRunMigrationService.php`

  The validator is ONE method and serves both answers (D-3), so a preview and
  the write cannot disagree. The dry run returns BEFORE the first write rather
  than writing and rolling back: a preview that rolled back would still have
  taken the log and shown up in an audit trail as a migration that happened.

  🔑 THE JOIN SUFFIX TRAVELS WITH THE TOKEN. A declared join holds one place
  per incoming edge, `<nodeId>#<edgeId>`. Dropping the suffix would collapse a
  half-arrived join into a single place and fire it early, which is a wrong
  ANSWER rather than an error, and no other assertion would notice.

  🔑 THE KIND IS COMPARED, NOT ONLY THE ID. A mapping pointing a user task at a
  gateway lands a token somewhere the engine cannot resume from, and the run
  parks forever with nothing saying why. An UNKNOWN kind on either side is not
  a mismatch: a graph that declares none has nothing to disagree about.

  ⚠️ NOT WRAPPED IN AN EXPLICIT TRANSACTION. The run's version, marking and log
  are written in ONE `update()`, which is atomic on its own; the timer
  supersession that follows is deliberately outside it (see 1.2). An enclosing
  transaction is the honest next step and is named here rather than claimed.

- [x] 1.2 Timer supersession with reason `migrated`; pending tasks re-referenced.
  **files**: `lib/Service/Flow/FlowRunMigrationService.php`

  Only the timers whose node actually MOVED under the mapping. A timer on a
  node the target kept under the same id is measuring the same wait against the
  same deadline, and re-arming it would restart a clock the applicant is
  already counting. Elapsed time is kept either way: `supersede()` re-arms from
  the anchoring event, not from now.

  A failed supersession does NOT fail the migration, and says so loudly. The
  run has already moved; refusing at that point would leave it on the target
  version with the caller told it failed, which is the one state nobody can act
  on.

- [x] 1.3 The seam a consuming app calls: `migrateRunForSubject()`.
  **files**: `lib/Service/Flow/FlowRunMigrationService.php`

  🔴 NO RUN IN FLIGHT ANSWERS `migrated: true`. dossiq's `case-type-rebind`
  stops the whole rebind when the engine refuses, and a case with no live run
  has nothing that could disagree with the rebind, so refusing would block a
  correction on a case where there was never a problem. `migrated` means "the
  run side is consistent with what you are about to do".

  It does NOT move a run to a different flow, and says so when asked: the
  marking is the contract and two unrelated flows share no node ids to map.

## 2. API

- [x] 2.1 Single-run and bulk routes with the `run` guard and per-run reporting.
  **files**: `lib/Controller/FlowRunController.php`, `appinfo/routes.php`

  `POST /api/flow-runs/{uuid}/migrate` and
  `POST /api/flows/{flow}/migrate-runs`, both behind the same `run` guard
  `retry` and `resume` take, because moving a run in flight is at least as
  consequential as re-running it. A dry run is told apart from a refusal before
  the status is chosen: answering 422 for a successful preview would make every
  UI treat it as a failure.

  ⚠️ `manage` ON THE SUBJECT IS NOT CHECKED YET. The spec asks for the flow's
  `run` right AND `manage` on the run's subject; only the first is enforced, by
  the existing `refuseUnlessRunnable()`. Named rather than claimed: the second
  needs a per-subject authorization seam this controller does not hold.

## 3. Retirement

- [ ] 3.1 A deprecated version with no pinned runs can be retired; refused otherwise with the count.

  NOT BUILT. `runsOnVersion()` is the query it needs and is public for exactly
  that reason, but the retirement gesture belongs with `FlowVersionService::
  deprecate()` and is its own change.

## 4. Tests

- [ ] 4.1 `tests/e2e/ci/run-migration.spec.ts`: publish a version with a renamed node, migrate a parked run, complete the task.

  NOT BUILT: it needs a live instance with a published flow, and this lane
  writes no e2e it cannot run.

- [x] 4.2 Unit tests for the validator, dry run, apply, timers and bulk skip.
  **files**: `tests/Unit/Service/Flow/FlowRunMigrationServiceTest.php` (14)

  The dry-run test asserts `update()` was NEVER CALLED rather than reading the
  return value, because a preview that wrote and rolled back returns the same
  thing. Mutation-checked: dropping the join suffix reddened the join test
  alone.

  The version and timer fixtures are REAL entities rather than mocks:
  `FlowVersion` extends Nextcloud's `Entity`, whose getters are `__call` magic,
  so PHPUnit refuses to stub `getVersion()` at all. A double that could have
  been configured there would have been a double inventing a method the real
  class does not physically have.
