# Tasks: flow-task-inbox-projections

## 1. Dialect: a notification that can decide

- [x] 1.1 Add the `task-verb` action target kind to
      `lib/Service/Notification/NotificationAnnotationValidator.php` beside
      the three at `:60` (`object-detail|route|url`): it names a lifecycle
      verb and an optional outcome, never an author-composed URL. Keep
      `MAX_ACTIONS = 2` (`:68`) — approve and reject is exactly two. Reject
      an unknown verb naming the value. Existing rules keep validating
      unchanged.
- [x] 1.2 Render it as a state-changing action:
      `lib/Notification/AnnotationNotifier.php:235` hardcodes
      `->setLink($url, 'GET')` for every declared action; a `task-verb`
      target renders POST against the `TaskController` verb route, and the
      other three kinds keep rendering GET. Resolve the target URL in the
      dispatcher's target resolver (`AnnotationNotificationDispatcher.php:2598-2640`
      handles `url`/`route`/`object-detail` today). A verb whose outcome
      requires a mandatory comment resolves to the task form instead — a
      GET — because `flow-tasks` refuses a rejecting outcome without one.

## 2. Task notifications, declaratively

- [x] 2.1 `lib/Service/Notification/TaskObjectAdapter.php` extending
      `ObjectEntity`, modelled on `SystemEntityObjectAdapter.php:46`. Entity
      uuid = TASK uuid (so `NotificationDedupeState`, which is keyed per
      object, dedupes per task). Flattens assignee, candidate users/groups,
      requester, watchers, priority, `due_at`, `is_terminal`, the derived
      overdue fields and the subject object's context into payload fields
      recipients and filters can read. NO notification logic in it — design
      D-1's fence.
- [x] 2.2 `lib/Service/Notification/TaskNotificationRules.php` modelled on
      `SystemSchemaRules.php:58`: the rule set from design.md — Seed Data,
      addressed at `trigger.action` (matched at
      `AnnotationNotificationDispatcher.php:1523-1539`), covering offered,
      assigned, reassigned-away, due-soon, escalated and cancelled. The
      overdue rule uses the operator-object filter grammar verified at
      `shillinq/lib/Settings/register.d/contract-lifecycle-management.json:383-401`
      over DERIVED fields — no rule anywhere filters a stored `overdue`.
- [x] 2.3 `lib/Listener/TaskNotificationListener.php` on the task lifecycle
      events, calling
      `AnnotationNotificationDispatcher::dispatchWithSchema()` (`:211`) with
      `context['action']` set to the recorded transition action — the same
      seam `SystemEntityNotificationListener.php:94-127` uses. No second
      notification pipeline.
- [x] 2.4 Withdrawal via `IManager::markProcessed()` (the call already used
      at `lib/Service/NotificationService.php:228`) on every terminal
      transition and on assignee change: a claimed pool task clears the
      other members' notifications; a task terminated by propagation leaves
      no approve button standing.

## 3. The calendar projection

- [x] 3.1 Projection state per task (rendered-content hash + timestamp,
      written by the projector only, read by no lifecycle or authorization
      rule) so idempotency, echo suppression and drift detection are
      comparisons rather than guesses — design D-2 rules 6 and 8.
- [x] 3.2 `lib/Service/Task/TaskCalendarProjector.php` rendering the VTODO
      from the task per the property table in design.md — D-5, including the
      new `URL` (a form deep link; `lib/Service/TaskService.php:400-440`
      emits no `URL` at all today), `X-OPENREGISTER-TASK` and
      `X-OPENREGISTER-TASK-ASSIGNEE`. Into the ASSIGNEE's calendar:
      `findUserCalendar()` (`:561-582`) resolves the SESSION user today and
      must be parameterised by uid. A pooled task with no assignee is NOT
      projected. Reassignment removes and recreates; a terminal task is
      rendered terminal.
- [x] 3.3 Invert `lib/Service/TaskService.php` (753L) from store to
      projection writer per the method table in design.md — D-7: two classes
      keyed on `X-OPENREGISTER-TASK`; `createTask()` refuses an
      engine-identity payload from the sub-resource endpoint;
      `deleteTask()` on a projected VTODO does not cancel the task. Delete
      `extractAssigneeFromDescription()` (`:258-264`) and the
      `'Assigned to: '` writer at
      `nextcloud-vue/src/components/CnObjectSidebar/CnTasksTab.vue:304`, and
      fix the docblock at `:112` that claims ATTENDEE matching no code
      performs.
      > OpenRegister side done. The `'Assigned to: '` WRITER at
      > `CnTasksTab.vue:304` lives in nextcloud-vue and is tracked with 5.2;
      > until it lands, that prose arrives as an ordinary DESCRIPTION on a
      > STANDALONE VTODO and nothing on the server reads it any more.
- [x] 3.4 Run projection and notification AFTER the lifecycle transaction
      commits, never inside it (design D-8): a calendar outage or an
      assignee with no VTODO-capable calendar logs and skips naming the
      task, and the transition still succeeds. `findUserCalendar()`'s
      `NoVtodoCalendarException` (`:576`) stays fatal for a STANDALONE task
      and becomes a skip for a projection. Reconciliation re-renders what
      was skipped.

## 4. The write-back gate

- [x] 4.1 One gate implementation taking `(task_uuid, requested_verb,
      actor)` and nothing else — never a state, never a field value (design
      D-2 rule 3). It calls the entity `TaskService`, which authorizes.
      Fail-closed on unresolvable task, illegal transition, unknown property
      shape or unavailable authorization. Only `STATUS` and deletion name
      verbs; `SUMMARY`/`DESCRIPTION`/`DUE`/`PRIORITY` edits reach the engine
      never and are overwritten by the next render.
- [x] 4.2 `lib/Dav/TaskVtodoWriteBackPlugin.php` — a Sabre `ServerPlugin`
      declared in `appinfo/info.xml` under `<sabre><plugins><plugin>` (the
      mechanism `apps/dav/lib/AppInfo/PluginManager.php:155-160` loads) on
      `beforeWriteContent`/`beforeUnbind`, acting only on VTODOs carrying
      `X-OPENREGISTER-TASK`. A refusal throws a DAV forbidden exception so
      the client never records the change.
- [x] 4.3 `lib/Listener/TaskVtodoWriteBackListener.php` on the `apps/dav`
      `CalendarObjectUpdatedEvent`/`CalendarObjectDeletedEvent` for writes
      that bypass the plugin: here the write has committed, so REVERT the
      projection to the engine's truth and notify the actor naming the task
      and the reason. No silent revert anywhere. Every refusal writes a task
      audit denial with actor and reason.

## 5. Inbox surfaces

- [x] 5.1 `CnTasksWidget` in nextcloud-vue modelled on
      `src/components/CnFlowRunsWidget/CnFlowRunsWidget.vue` (559L), reading
      the `flow-tasks` inbox API. Filter, sort, page and total come from the
      query; the badge reads the TOTAL, never the row count; no client-side
      filter is applied over a returned page.
      > **Unblocked and wired (2026-09-01).** nextcloud-vue#910 merged the
      > `tasks` entity source and `CnTasksWidget` (change
      > cn-tasks-entity-source). OpenRegister side: the manifest declares
      > `flow-task-inbox` (`/flow-tasks`, `type: "index"`,
      > `entitySource: "tasks"`) plus a Tasks menu entry, the dashboard
      > carries the `tasks` widget, and the per-uuid deep link
      > `/flow-tasks/{uuid}` renders `FlowTaskDetail`
      > (`TaskController::open()` now serves the SPA shell instead of a
      > hash redirect the history-mode router never resolved).
      > ⚠️ Release gate: #910 is on nextcloud-vue `development` and NOT yet
      > in a published npm version (latest is 2.29.0, cut before the
      > merge). The pin is `^2.29.0`; when the next promotion publishes,
      > refresh the lockfile so the built bundle actually carries the
      > source and the widget — until then the inbox index is empty with a
      > console warning and the widget cell reads unavailable.
- [ ] 5.2 Repoint the leaf: `src/integrations/builtin/tasks.js` (64L),
      `CnObjectSidebar/CnTasksTab.vue` (500L),
      `CnTasksCard/CnTasksCard.vue` (387L) and `src/types/task.d.ts` (31L)
      off the VTODO sub-resource endpoints (`appinfo/routes.php:906-909`)
      onto tasks-by-object. `TTaskStatus` widens from the four VTODO values
      (`task.d.ts:5`) to the six CMMN states; `isOverdue` becomes
      server-derived; the assignee becomes an identity; a pooled task shows
      no assignee and offers claim; and only verbs the caller is authorized
      to invoke are offered. Coordinate with decidesk, the only mounter
      (`decidesk/src/manifest.json:594`).
      > **Blocked (nextcloud-vue, 2026-09-01).** Same repo, same
      > coordination as 5.1: the leaf files named here are nextcloud-vue's,
      > and decidesk is the one mounter to coordinate with. The server
      > contract they repoint onto is in place (`GET /api/flow-tasks`
      > with `objectUuid=`, the six CMMN states, server-derived `overdue`,
      > the assignee as a uid, and verbs refused with 403 so a surface can
      > learn what to offer). The two `@e2e` scenarios this leaves
      > uncovered, the watcher who sees no action buttons and the widget's
      > page-of-rows with the full count, are UI scenarios and travel with
      > this task.
- [x] 5.3 `GET /api/tasks` (`appinfo/routes.php:903`) answers from the inbox
      instead of `TaskService::getAllUserTasks()` (`:120-197`, which walks
      every calendar and filters and paginates in PHP). Visibility, filter,
      sort, page and total are the query's; `assignee` is no longer accepted
      as a free-text filter; the 200 limit cap stays.

## 6. Seed data and tests

- [x] 6.1 Install the rule set and projection fixtures from design.md —
      Seed Data (assigned-to-you with two `task-verb` actions; offered-to-
      pool via `kind: groups` from the task's own candidate groups; overdue
      in the verified filter grammar; cancelled-by-propagation; one
      projected task, one pooled task with no projection, one assignee with
      no VTODO-capable calendar) through the existing seeding path,
      idempotent.
- [x] 6.2 Gate tests: a stranger's completion refused and audited; a shared-
      calendar tick reverted and the actor notified; an illegal transition
      from a terminal task refused; a VTODO with no `X-OPENREGISTER-TASK`
      untouched through create/edit/complete; a `SUMMARY` edit not reaching
      the engine; both hooks proven to reach the one gate.
- [x] 6.3 Contract tests: rendering twice produces one VTODO and no second
      notification; a gate-driven completion records exactly one audit
      entry (no echo); a deleted VTODO is rebuilt with identical content and
      the task untouched; a clock-controlled overdue rule fires with the row
      byte-identical before and after; a failing calendar backend leaves the
      assignment committed and inboxed.
- [x] 6.4 Playwright coverage for the six `@e2e`-marked scenarios across
      `specs/flow-task-projections/spec.md` and
      `specs/object-interactions/spec.md`: approve from the notification;
      the assigned task in the calendar with a link that resolves to the
      form; completing the projected VTODO; the unauthorized calendar
      completion reverted and reported; the widget's page-of-rows with the
      full count; the watcher who sees a task and no action buttons; and the
      aggregate listing engine tasks.

## Acceptance criteria

- No code path in this change calls `INotificationManager` to send a task
  notification. Every task notification originates in an
  `x-openregister-notifications` rule evaluated by
  `AnnotationNotificationDispatcher`.
- No rule, filter, column or payload field anywhere records whether a task
  is overdue. The scheduled rule filters the same derivation the inbox
  filter and the API projection use.
- The only path from a projection into the engine carries
  `(task_uuid, requested_verb, actor)`. Grep the write-back gate: no code
  assigns a lifecycle state, an assignee or an outcome from VTODO content.
- Every refused write-back leaves the engine unchanged, the projection
  showing the engine's state, an audit denial recorded, and the actor
  notified. There is no silent-revert branch.
- A pooled task with no assignee exists in no calendar, and is still listed
  and notified.
- No lifecycle transition can be failed or rolled back by a projection or
  notification failure. A test with a calendar backend that fails every
  write proves it.
- The word `'Assigned to: '` appears nowhere in OpenRegister or
  nextcloud-vue, and no code reads an assignee out of a description.
- A VTODO carrying no `X-OPENREGISTER-TASK` behaves exactly as it did before
  this change, through create, list, edit, complete and delete.
- Nothing in this change defines a lifecycle rule, an authorization rule, a
  flow node, a task form, an SLA computation, or migrates a fleet task
  shape.

## Quality checklist

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan); nextcloud-vue
  lint and vitest pass.
- Every new PHP file carries `@license EUPL-1.2` and
  `@copyright 2026 Conduction B.V.`; every public/protected method carries a
  `@spec openspec/specs/flow-task-projections/spec.md` anchor, and the
  changed `object-interactions` methods point at that spec.
- The `hydra-gate-notification-dialect` gate passes: no legacy dialect, and
  no imperative object-notification dispatch introduced in a leaf.
- Regression check with decidesk installed — the only app mounting the tasks
  leaf (`decidesk/src/manifest.json:594`) and the owner of the two rules
  being generalised. Its suite is green and its action-item surfaces still
  render.
- Depends on `flow-task-entity`: the table, the verbs, the authorization and
  the inbox query exist before anything here projects them.
- References ADR-098 D2 (delivery on Nextcloud's own entities as a
  projection), ADR-031 (declarative-vs-imperative — design.md D-1),
  ADR-002 (CalDAV substrate), ADR-005 (the gate is fail-closed), ADR-001
  (seed data).
