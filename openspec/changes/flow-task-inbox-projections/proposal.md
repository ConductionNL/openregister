---
kind: code
depends_on: [flow-task-entity]
---

# Proposal: flow-task-inbox-projections

## Summary

Deliver the engine task to where people already work: a Nextcloud
notification when work arrives (**actionable** — approve and reject are
buttons, not a link to a page), a VTODO in the assignee's own calendar
carrying a `URL` back to the task form, a write-back listener so ticking
that VTODO off completes the engine task, and the inbox surfaces that make
"what is waiting for me?" a widget and a tab rather than an API call.

Every one of these is a **PROJECTION**. `openregister_tasks` is the single
truth; the calendar, the notification and the widget are read models that
are rebuilt from it and never read back into it — except through one
authorizing gate that treats what arrives as untrusted input. ADR-098
Decision 2 is delivery on Nextcloud's own entities, and the reason it is a
projection and not a second store is measured, not aesthetic.

## Why

**`flow-task-entity` makes tasks exist. It explicitly notifies nobody and
appears in no calendar** — its own acceptance criteria say so ("Nothing in
this change sends a notification, writes a VTODO..."). A queryable inbox
that nothing pushes to is an inbox users have to remember to open.

**The one actionable notification the fleet has cannot act.** decidesk's
`actionItemAssignedToYou`
(`decidesk/lib/Settings/decidesk_register.json:4202-4241`) is the only rule
in the fleet that declares `actions`, and its single action is
`target: {kind: 'object-detail'}` — an "open the item" link. It could not be
anything else: `AnnotationNotifier::addDeclaredActions()` renders every
declared action as `->setLink($url, 'GET')`
(`lib/Notification/AnnotationNotifier.php:235`), hardcoded. So the notifier
can offer to NAVIGATE and can never offer to DECIDE. A binary approval
delivered as a link costs a page load and a second decision point; delivered
as two buttons it costs one tap. The validator already caps `MAX_ACTIONS`
at 2 (`lib/Service/Notification/NotificationAnnotationValidator.php:68`) —
exactly approve and reject.

**The overdue notification the fleet ships fires on a value somebody had to
remember to write.** decidesk's `actionOverdue`
(`decidesk_register.json:4173-4180`) is
`trigger: {type: scheduled, intervalSec: 86400, filter: {taskStatus: "overdue"}}`.
`overdue` is a clock-derived fact stored as a status value; between writes it
is wrong, and `flow-task-entity` forbids storing it at all. The filter has to
be re-expressed against a derived predicate or the notification silently
covers only the tasks something stamped.

**In the calendar, "who owes this" is a substring of a free-text field.**
`TaskService::extractAssigneeFromDescription()`
(`lib/Service/TaskService.php:258-264`) returns
`substr($description, strlen('Assigned to: '))` when the description starts
with that literal — the prefix `CnTasksTab.vue:304` writes
(`nextcloud-vue/src/components/CnObjectSidebar/CnTasksTab.vue:304`:
`taskData.description = 'Assigned to: ' + this.newTaskAssignee.displayName`).
It stores a **display name**, so it does not even round-trip to a uid. The
docblock one method up claims the filter "matches ATTENDEE or description"
(`lib/Service/TaskService.php:112`); no code path in the file reads
`ATTENDEE`. `createTask()` emits `SUMMARY`, `DESCRIPTION`, `STATUS`,
`PRIORITY`, `DUE`, three `X-OPENREGISTER-*` properties, a base64
`X-OPENREGISTER-DATA` blob and an RFC 9253 `LINK`
(`lib/Service/TaskService.php:400-440`) — and no `URL`, so the VTODO deep-
links back to the object **API endpoint**, not to anything a human can open.

**And the aggregate that stands in for an inbox does not scale and cannot
express the lifecycle.** `getAllUserTasks()`
(`lib/Service/TaskService.php:120-197`) iterates every calendar, calls
`getCalendarObjects()` per calendar, `getCalendarObject()` per row, `strpos`
pre-filters, parses with `Sabre\VObject\Reader`, then filters status and
assignee **in PHP** and sorts **in PHP**. `openspec/specs/object-interactions/spec.md`
concedes the outcome in a scenario named "Performance degradation warning"
(10,000+ objects MAY exceed 2 seconds). Its vocabulary is VTODO's four
values — `needs-action | in-process | completed | cancelled`
(`nextcloud-vue/src/types/task.d.ts:5`) — which cannot express the six CMMN
states, cannot express a pooled task with no assignee, and cannot express a
candidate group, because a VTODO lives in exactly one person's calendar.

**Why a projection and not a mirror.** The fleet has already paid for the
alternative and wrote the post-mortem into a manifest:
`hermiq/src/manifest.json:1240` records that a page "used to declare
hermiq/agentflow — a duplicate mirror of the native flow rows — so the list
showed objects while the engine ran the native rows, **free to drift**". A
calendar the user can edit is a far more writable second store than that
one was. So the contract has to be stated as a requirement and not left as
an intention: truth flows one way, and the one path back is a gate.

## What Changes

- **Task notifications are DECLARATIVE, through the dispatcher that already
  exists.** A `TaskNotificationRules` registry modelled exactly on
  `lib/Service/Notification/SystemSchemaRules.php` publishes
  `x-openregister-notifications` rules for the task entity; a listener on
  task lifecycle events adapts the task into a transient `ObjectEntity` and
  calls `AnnotationNotificationDispatcher::dispatchWithSchema()`
  (`lib/Service/Notification/AnnotationNotificationDispatcher.php:211`).
  This is not a new pipeline: it is the seam
  `SystemEntityNotificationListener` (`lib/Listener/SystemEntityNotificationListener.php:94-127`)
  already uses with `SystemEntityObjectAdapter`
  (`lib/Service/Notification/SystemEntityObjectAdapter.php:46`) to give
  registers, schemas, sources, agents and webhooks declarative notifications
  without any of them being an OR object. Tasks are the sixth such entity,
  not a special case.
- **Rules address the named transition ACTION, not the resulting state.**
  `matches()` already supports `trigger: {type: 'transition', action: ...}`
  including an array of actions
  (`AnnotationNotificationDispatcher.php:1523-1539`). `flow-task-entity`
  stores `last_action` precisely so this works. Delivered events: offered to
  your pool, assigned to you, reassigned away from you, due soon, escalated,
  cancelled by propagation.
- **Actionable notifications gain a decision target — the only new dialect
  surface.** `VALID_ACTION_TARGET_KINDS` is `object-detail | route | url`
  (`NotificationAnnotationValidator.php:60`), all rendered `GET`. A fourth
  kind, `task-verb`, names a lifecycle verb and its outcome; the notifier
  renders it `->setLink($url, 'POST')`. Two of them are approve and reject,
  which is what `MAX_ACTIONS = 2` already allows. The POST lands on the same
  `TaskController` verb a form submission would, and is authorized by
  `TaskAuthorizationService` identically — the notification is a transport,
  never a bypass.
- **Overdue is notified from the derived predicate.** The `scheduled` rule
  uses the operator-object filter grammar verified in production at
  `shillinq/lib/Settings/register.d/contract-lifecycle-management.json:383-401`
  (`{"all":[{"field":"status","operator":"notIn","values":[...]},
  {"field":"dueDate","operator":"before","value":"now"}]}`) evaluated over the
  task projection's derived fields. No rule anywhere filters on a stored
  `overdue`.
- **`lib/Service/TaskService.php` becomes the projection WRITER and stops
  being the store.** Its 753 lines of VTODO CRUD keep working, but the
  authority inverts: `openregister_tasks` is written first, and the VTODO is
  rendered from it into the **assignee's** calendar. The projected VTODO
  carries `SUMMARY` from the task, `DUE` from `due_at`, `PRIORITY` from the
  normalised scale, `STATUS` from the CMMN state through a published
  mapping, `X-OPENREGISTER-TASK` (the task uuid — the identity that makes
  write-back addressable), a real `X-OPENREGISTER-TASK-ASSIGNEE` uid, and a
  `URL` property deep-linking to the task form. `'Assigned to: '` prose is
  removed at both ends, `CnTasksTab.vue:304` included.
- **Write-back is a gate, not a sync.** A Sabre `ServerPlugin` registered
  through `<sabre><plugins><plugin>` in `appinfo/info.xml` — the mechanism
  `apps/dav/lib/AppInfo/PluginManager.php:155-160` loads — inspects VTODO
  writes carrying `X-OPENREGISTER-TASK`, maps `STATUS:COMPLETED` onto the
  `complete` verb, and calls `TaskService` (the entity one), which
  authorizes. Authorized: the engine advances and the projection is
  re-rendered. Unauthorized or invalid: the write is refused in-band where
  the request came through DAV, and where it committed anyway the projection
  is **reverted** to the engine's truth and the user is notified why.
  Everything arriving this way is untrusted input from a user-editable
  store.
- **A tasks widget beside the runs widget.** `CnTasksWidget`, modelled on
  `nextcloud-vue/src/components/CnFlowRunsWidget/CnFlowRunsWidget.vue`
  (559L), reads the entity change's inbox API with its server-side filtering,
  pagination and total, and renders a badge count off the total rather than
  off a row count.
- **The nc-vue task leaf is repointed off VTODO onto the task API.**
  `nextcloud-vue/src/integrations/builtin/tasks.js` (64L), `CnTasksTab.vue`
  (500L), `CnTasksCard.vue` (387L) and `src/types/task.d.ts` (31L) move from
  the `/api/objects/{r}/{s}/{id}/tasks` VTODO endpoints
  (`appinfo/routes.php:906-909`) onto tasks-by-object, and `TTaskStatus`
  widens from the four VTODO values to the six CMMN states plus the derived
  overdue flag. **Blast radius is one app**: `decidesk/src/manifest.json:594`
  is the only manifest in the fleet that mounts `integrationId: "tasks"`.
- **`GET /api/tasks` (`appinfo/routes.php:903`) keeps its URL and changes its
  meaning**: it answers from the engine inbox. The calendar-walking
  aggregate retires with it.

## What does NOT change

Each of these is a different change and is explicitly OUT of scope:

- **`flow-task-entity`** — the table, `TaskService` (the entity one),
  `TaskAuthorizationService`, `TaskInboxService`, the verbs, the audit and
  the inbox QUERY API. This change adds no lifecycle rule and no
  authorization rule; it consumes both. Where a projection needs to know
  whether something is allowed, it asks.
- **`flow-user-task-node`** — the `openregister.user-task` node and the
  suspend/resume wiring.
- **`flow-task-forms`** — the task form itself. This change deep-links TO it
  and delivers a two-button decision for the binary case; the structured
  completion payload is specified there.
- **`flow-business-timers`** — what "due soon" and "escalated" MEAN: SLA
  arithmetic, business days, the escalation matrix, the breach sweep. This
  change delivers notifications for those events; it does not compute them.
- **`flow-approval-consolidation`** — migrating the 23 fleet task shapes,
  including decidesk's `action-item`, whose schema is today a read-only
  `caldav-vtodo` projection (`decidesk_register.json`,
  `x-openregister-object-source: {provider: caldav-vtodo, readOnly: true}`).
- **`flow-messaging-nodes`** — the messaging NODES a flow author drops on a
  canvas (`openregister.send-notification` and its siblings) are an
  author-placed send with an author-chosen recipient. This change is
  **automatic task-lifecycle delivery**: nobody places it, and it fires
  because a task changed hands. The two share the dispatcher and share
  nothing else. A flow that needs to tell somebody something that is not a
  task uses that change; a task that needs an owner to know it exists uses
  this one.

The generic object-interactions VTODO leaf — tasks a user creates by hand on
any object, unrelated to any engine task — keeps working. Only its authority
over engine tasks is removed.

## Capabilities

### New Capabilities
- `flow-task-projections`: the one-directional-truth contract, declarative
  task notifications including actionable decisions, the CalDAV VTODO
  projection with its deep link and real assignee, the authorizing write-back
  gate, and the inbox surfaces.

### Modified Capabilities
- `object-interactions`: "Tasks on Objects via CalDAV VTODO", "Task Status
  Mapping", "Task Compatibility with Nextcloud Tasks App" and "User-Wide Task
  Aggregate Endpoint" change at the requirement level. A VTODO carrying
  `X-OPENREGISTER-TASK` stops being an independently editable record and
  becomes a projection; the user-wide aggregate stops walking calendars.

## Impact

- **Affected specs**: new `flow-task-projections`; delta on
  `object-interactions`.
- **Affected code (OpenRegister)**: `lib/Service/TaskService.php` (753L —
  inverted from store to projection writer);
  new `lib/Service/Task/TaskProjectionService.php`,
  `lib/Service/Task/TaskCalendarProjector.php`,
  `lib/Service/Notification/TaskNotificationRules.php`,
  `lib/Listener/TaskNotificationListener.php`,
  `lib/Dav/TaskVtodoWriteBackPlugin.php`,
  `lib/Listener/TaskVtodoWriteBackListener.php`;
  `lib/Service/Notification/NotificationAnnotationValidator.php` (+1 action
  target kind), `lib/Notification/AnnotationNotifier.php:235` (POST render),
  `lib/Controller/TasksController.php` + `appinfo/routes.php:903`;
  `appinfo/info.xml` gains a `<sabre>` section.
  `lib/Service/ObjectSource/CalDavVtodoObjectSourceProvider.php:253` and the
  `nc-task` virtual schema (`lib/Repair/SeedAppVirtualSchemas.php:100`) keep
  projecting the user's own calendar and are unchanged.
- **Affected code (nextcloud-vue)**: new `CnTasksWidget`;
  `src/integrations/builtin/tasks.js`,
  `src/components/CnObjectSidebar/CnTasksTab.vue`,
  `src/components/CnTasksCard/CnTasksCard.vue`, `src/types/task.d.ts`.
- **Affected apps**: **decidesk only** — the sole mounter of the tasks leaf
  (`decidesk/src/manifest.json:594`) and the owner of the two notification
  rules this change generalises. No other app mounts the leaf, so the leaf's
  API change is a one-app coordination, not a fleet migration.
- **Depends on**: `flow-task-entity` — a projection with no truth to project
  is a second store, which is the thing this change exists to prevent.
- **ADRs**: ADR-098 D2 (delivery on Nextcloud's own entities; projection, not
  storage); ADR-031 (declarative-vs-imperative — notifications are the
  canonical declarative surface, and every imperative choice here is argued
  in design.md); ADR-002 (CalDAV as the calendar substrate); ADR-005
  (fail-closed authorization — the write-back gate re-validates everything);
  ADR-001 (seed data).
