# Tasks: a-task-may-wait-on-another-task

Decision 137. Owner openregister. Asked by dossiq
`task-dependencies-and-the-next-planned-action` 3.1 to 3.3.

## 1. The declaration

- [ ] 1.1 `blocked_by` column on `openregister_tasks` (migration, index), `Task::blockedBy`, serialised, carried by `TaskBuilder`; info.xml version moves.
  - `tests/Unit/Db/TaskBlockedByTest.php`
- [ ] 1.2 Create refuses `blocked`, the state `blocked`, a self-block, an unknown blocker and a loop (D-5, D-6).
  - `tests/Unit/Service/Task/TaskBlockedByCreateTest.php`

## 2. The derived flag and the inbox

- [ ] 2.1 `TaskMapper::openBlockerUuids()`; `TaskInboxService` adds `blocked` to every row, batched per page (D-3).
- [ ] 2.2 The inbox predicate leaves blocked tasks out of page and total, except on an object or run anchor or with `includeBlocked` (D-2); the controller reads `includeBlocked`.

## 3. The release

- [ ] 3.1 `TaskBlockerReleaseListener` on the committed `TaskTerminalEvent`: `released` audit and re-announcement per open dependant; registered in `Application` (D-4).
  - `tests/Unit/Listener/TaskBlockerReleaseListenerTest.php`

## 4. Live

- [ ] 4.1 Live probe: create A, create B blocked by A for the same assignee, read the inbox (B absent, total excludes it), complete A, read again (B present, audit has `released`).
