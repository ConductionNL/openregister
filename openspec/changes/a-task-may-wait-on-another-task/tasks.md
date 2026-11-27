# Tasks: a-task-may-wait-on-another-task

Decision 137. Owner openregister. Asked by dossiq
`task-dependencies-and-the-next-planned-action` 3.1 to 3.3.

## 1. The declaration

- [x] 1.1 `blocked_by` column on `openregister_tasks` (migration, index), `Task::blockedBy`, serialised, carried by `TaskBuilder`; info.xml version moves.
  - `tests/Unit/Db/TaskBlockedByTest.php`
- [x] 1.2 Create refuses `blocked`, the state `blocked`, a self-block, an unknown blocker and a loop (D-5, D-6).
  - `tests/Unit/Service/Task/TaskBlockedByCreateTest.php` (`TaskBlockerGuard`, called from `TaskService::create()` and `import()`)

## 2. The derived flag and the inbox

- [x] 2.1 `TaskBlockerMapper::openBlockerUuids()` (its own mapper: TaskMapper sat at the phpmd class-length limit); `TaskInboxService` adds `blocked` to every row, batched per page (D-3).
- [x] 2.2 The inbox predicate leaves blocked tasks out of page and total, except on an object or run anchor or with `includeBlocked` (D-2); the controller reads `includeBlocked`.
  - `tests/Unit/Db/TaskBlockedByTest.php`, `tests/Unit/Controller/TaskControllerTest.php::testIndexPassesIncludeBlockedIntoTheCriteria`

## 3. The release

- [x] 3.1 `TaskBlockerReleaseListener` on the committed `TaskTerminalEvent`: `released` audit and re-announcement per open dependant; registered in `Application` (D-4).
  - `tests/Unit/Listener/TaskBlockerReleaseListenerTest.php`

## 4. Live

- [x] 4.1 Live probe: create A, create B blocked by A for the same assignee, read the inbox (B absent, total excludes it), complete A, read again (B present, audit has `released`). (not run: needs the live instance after the migration; the NOT IN subquery's SQL is only proven on a real database)
  > 2026-11-25, live on a sandbox Nextcloud 34 on PostgreSQL 16 (main 55f2aa3e): A, then B blocked by A, both for
  > `e2e-owner`. The NOT IN predicate holds on PostgreSQL: B is out of `e2e-owner`'s inbox page and out of its total;
  > with `includeBlocked=true` it is listed. Completing A brings B into the inbox and B's audit reads
  > `create, released`. The probe found one defect, fixed in this same PR: `FlowWiring` built `TaskInboxService`
  > without the blocker mapper, so the derived `blocked` flag read `false` on every row, also for B while A was open.
  > With the mapper wired, B reads `blocked: true` under `includeBlocked` and on its own read, and `false` once A is
  > done.
