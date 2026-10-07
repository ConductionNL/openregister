# Tasks: tasks-delegation-and-substitution

## 1. Mandate on the task

- [ ] 1.1 Migration adding `required_mandate` and `mandate_evidence` to `openregister_tasks` and `mandate_evidence` to `openregister_task_audit`; entity fields and serialisation. Verify: `tests/Unit/Db/TaskEntitiesTest.php` round-trips both fields.
- [ ] 1.2 `TaskBuilder` reads `requiredMandate` and refuses a verb the catalogue does not know; `UserTaskNode` gains the config key. Verify: a new `tests/Unit/Service/Task/TaskBuilderTest.php` for a known verb, an unknown verb (400 message names it) and none.

## 2. Delegation check

- [ ] 2.1 `TaskMandateGuard::assertHolds()` with the three cases of D-2 and evidence from `ProvenanceResolver`. Verify: `tests/Unit/Service/Task/TaskMandateGuardTest.php` with a holder, a non-holder, a custom verb decided by a stubbed vote, a missing subject and a throwing check (all fail closed).
- [ ] 2.2 Call the guard in `TaskService::delegate()`, store the evidence, audit it; map `TaskMandateRefusedException` to 422 in `TaskController`. Verify: `TaskServiceTest` delegate cases; `POST /api/flow-tasks/{uuid}/delegate` to a non-holder answers 422 naming the verb.

## 3. Substitution

- [ ] 3.1 `TaskSubstitution::routeIfAbsent()` reading the Nextcloud absence and applying D-4 and D-6. Verify: `tests/Unit/Service/Task/TaskSubstitutionTest.php` for absent with replacement, absent without, absence not in effect, feature disabled, stand-in without mandate.
- [ ] 3.2 Call it on create, import, assign, reassign, offer routing and claim fallback; drop absent members in `TaskPerformerResolver`. Verify: `TaskServiceTest` and `TaskPerformerResolverTest` cases.
- [ ] 3.3 Listener for `OutOfOfficeStartedEvent` and `OutOfOfficeEndedEvent` queuing `TaskSubstitutionJob`, bounded to 200 per run with a watermark; the return rule of D-5. Verify: `TaskSubstitutionJobTest` for start, end with an untouched task, end with a touched task, and 450 tasks over three runs.
- [ ] 3.4 `assigneeAbsent` and `absentUntil` on the inbox row. Verify: `TaskInboxServiceTest`.

## 4. Tests and docs

- [ ] 4.1 Add `tests/e2e/ci/task-delegation-mandate.spec.ts`: delegate to a holder and a non-holder, set an absence with a replacement through the Nextcloud absence API, create a task for the absent user, read the task and its audit.
- [ ] 4.2 Document delegation with a mandate and substitution in `docs/features/`, with a screenshot of the audit tab showing a `substitute` entry.

Acceptance:

- A delegation to someone without the task's `requiredMandate` changes nothing and answers 422.
- Every substituted task shows `onBehalfOf` and a `substitute` audit entry with the absence period.
