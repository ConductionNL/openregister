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
  - Run 2026-11-25 on main against a sandbox Nextcloud 34.0.6 on MariaDB, as a non-admin assignee through `/api/flow-tasks`: B created with `blockedBy` A is absent from the inbox and from its total (the `NOT IN` predicate runs on MariaDB), present with `includeBlocked=true`; completing A puts B back in the default inbox and B's audit reads `create`, `released`. With `includeBlocked=true`, B read `blocked: false` while A was open, which is 4.2
- [x] 4.2 `FlowWiring::buildTaskInboxService()` passes the blocker lookup (`TaskBlockerMapperInterface`) to `TaskInboxService`, so a row waiting on an open task carries `blocked: true` (found live in 4.1: the factory left it out, the service's `blockers` stayed null and every row read as not blocked)
  - Test: FlowWiringTaskServicesTest::testTheTaskInboxIsBuiltWithTheBlockerLookup (red without the fix); live: B2 `blocked: true` while A2 is open, `false` after A2 completes
