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

- [ ] 4.1 Optional `attachTo` on `openregister.user-task`, filling the task's existing `objectUuid`/`registerId`/`schemaId` — D-4, not a new column.
- [ ] 4.2 An unheld role fails the step and creates no task.
- [ ] 4.3 Assert the attached task appears in the subject-anchored inbox read and in the case sidebar, since those are the readers the choice of fields was made for.

## 5. Reading it back

- [x] 5.1 `GET /api/flow-runs/{uuid}` serves the declared subjects.
- [x] 5.2 `/objects` is untouched. A test asserting an object read but never written appears in one and not the other.

## 6. Proof

- [ ] 6.1 Playwright, over the live API, the whole scene from the brief: a case is created and recorded as `case`, locked, a person is asked with `attachTo: case`, they answer with a form value, and the answer routes a Switch. Assert the task is on the case, the run and the person.
- [ ] 6.2 Playwright: `attachTo` naming an unheld role fails the step and leaves no task.
- [ ] 6.3 `composer check:strict`, both l10n gates, full unit suite. Exit code, not summary line.
