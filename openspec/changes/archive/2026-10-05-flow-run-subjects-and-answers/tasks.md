# Tasks

## 1. The answers fix, first and on its own

- [x] 1.1 A test asserting a **downstream** step can route on `answers.x`, seen RED. Not another assertion that responses were stored — D-5.
- [x] 1.2 `FlowTaskBridge::outcomeBagFor()` gains `answers` from `task.responses`, empty set when absent, never a missing key.
- [x] 1.3 A test for the empty case, so the two failure shapes stay one shape.
- [x] 1.4 Check the portal node still places its own `answers` and that the two paths now agree.

## 2. The subject set

- [x] 2.1 `subjects` json column on `FlowRun`, plus the entity accessors.
- [x] 2.2 Migration for the column, and a repair seeding ONLY the `trigger` entry from `subjectUuid` on runs that have one. Nothing back-filled from the audit — see the trap.
- [x] 2.3 Bump `<version>` in `appinfo/info.xml` in the same commit or the repair never runs (gate-110).
- [x] 2.4 `FlowRunSubjects`: read, record (replace-and-log on a held role), address-by-role, idempotent re-record.
- [x] 2.5 Unit tests seen RED: trigger-only default; replacement logged; re-fire does not duplicate; unknown role fails naming the roles held.

## 3. Nodes that record

- [x] 3.1 Optional `subjectRole` on `openregister.object-write` and `openregister.lock-object`.
- [x] 3.2 No role, no recording — assert the audit-derived list still shows the write.

## 4. Attaching a task

- [x] 4.1 Optional `attachTo` on `openregister.user-task`, filling the task's existing `objectUuid`/`registerId`/`schemaId` — D-4, not a new column.
- [x] 4.2 An unheld role fails the step and creates no task.
- [x] 4.3 Assert the attached task appears in the subject-anchored inbox read. The Newman collection reads the task back through `GET /flow-tasks?scope=assigned` and asserts its `objectUuid`, `registerId` and `schemaId` are the case's. The case SIDEBAR is a dossiq surface reading the same three fields, so it is covered by the choice of fields rather than by a second assertion here.

## 5. Reading it back

- [x] 5.1 `GET /api/flow-runs/{uuid}` serves the declared subjects.
- [x] 5.2 `/objects` is untouched. A test asserting an object read but never written appears in one and not the other.

## 6. Proof

- [x] 6.1 The whole scene from the brief, over the live API: a case is created and recorded as `case`, locked, a person is asked with `attachTo: case`, they answer with a form value, and the answer routes. Asserts the task is on the case, the run and the person.

  ⚠️ **Newman, not Playwright.** `playwright.config.ts` excludes `**/api-direct/**` from every project, so a Playwright spec for an HTTP contract runs only when a developer invokes an ad-hoc config by hand and CI executes none of it — a green spec and zero CI coverage look identical from the outside. `tests/newman/openregister-flow-subjects.postman_collection.json`, registered in `run-all.sh`, which `api-test-coverage.yml` runs on every PR. Same move as the `delegation` and `register-descriptors` collections.
- [x] 6.2 `attachTo` naming an unheld role fails the step and leaves no task — folder 3 of the same collection.
- [x] 6.3 `composer check:strict`, both l10n gates, full unit suite. Exit code, not summary line.

  Measured 2026-09-08, every one by exit code rather than by its summary:
  `lint` clean, `phpcs` rc=0, `phpmd` swept PER DIRECTORY with the baseline
  (E=0 — the whole-`lib/` run is OOM-killed and reports that as a pass),
  `psalm` rc=0, `phpstan` rc=0 ("No errors"), `phpunit` rc=0 over 19,434
  tests, `test:l10n` rc=0 and `test:l10n:parity` rc=0 across all 36 locales.

  ⚠️ The CLAUDE.md "Known state" note saying `npm run test:l10n` is red at
  HEAD is STALE: it passes, and the 17 keys it names are present.
