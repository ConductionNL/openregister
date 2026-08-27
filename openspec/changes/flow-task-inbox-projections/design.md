# Design: flow-task-inbox-projections

## Context

See proposal.md — Why. The design-relevant starting points, measured:

**The declarative notification engine already exists and already serves
non-object entities.** `AnnotationNotificationDispatcher::dispatch()`
(`lib/Service/Notification/AnnotationNotificationDispatcher.php:177`)
resolves a schema from an `ObjectEntity` and delegates to
`dispatchWithSchema()` (`:211`), which is PUBLIC. That second entry point is
how OpenRegister's own system entities — register, schema, configuration,
source, agent, webhook — get declarative notifications without being
objects: `SystemSchemaRules` (`lib/Service/Notification/SystemSchemaRules.php:43-48`,
`:58`) holds in-code `x-openregister-notifications` rules per synthetic
slug, `SystemEntityObjectAdapter extends ObjectEntity`
(`lib/Service/Notification/SystemEntityObjectAdapter.php:46`) wraps the
entity, and `SystemEntityNotificationListener::handle()`
(`lib/Listener/SystemEntityNotificationListener.php:94-127`) puts the two
together. The seam is proven; a task is the seventh entity through it.

**The dialect already addresses transition actions.**
`AnnotationNotificationDispatcher::matches()` (`:1523-1539`) supports
`trigger: {type: 'transition', action: <string|array>}` against
`$context['action']`. `flow-task-entity` records `last_action` for exactly
this reason.

**The dialect cannot yet express a decision.** `VALID_ACTION_TARGET_KINDS`
is `['object-detail', 'route', 'url']`
(`lib/Service/Notification/NotificationAnnotationValidator.php:60`), and
`AnnotationNotifier::addDeclaredActions()` renders every one of them
`->setLink($url, 'GET')` (`lib/Notification/AnnotationNotifier.php:235`).
`MAX_ACTIONS` is 2 (`NotificationAnnotationValidator.php:68`).
`VALID_CHANNELS` is
`['nc-notification','email','activity','webhook','talk','web-push']` (`:53`)
and `VALID_RECIPIENT_KINDS` is
`['users','field','groups','relation','object-acl','expression']` (`:51`).

**The CalDAV leaf is a full VTODO store.** `lib/Service/TaskService.php`
(753L) does CRUD over `OCA\DAV\CalDAV\CalDavBackend`: `createTask()` writes
the VTODO body at `:400-440` (`SUMMARY`, `DESCRIPTION`, `STATUS`,
`PRIORITY`, `DUE`, `X-OPENREGISTER-REGISTER/SCHEMA/OBJECT`, a base64
`X-OPENREGISTER-DATA` blob, an RFC 9253 `LINK` — no `URL`);
`vtodoToArray()` (`:595-624`) reads it back; `findUserCalendar()`
(`:561-582`) picks the SESSION user's first VTODO-supporting calendar;
`getAllUserTasks()` (`:120-197`) walks every calendar and filters in PHP;
`buildTaskDeepLink()` (`:352-364`) builds a Tasks-app hash link, i.e. a link
INTO the calendar rather than back to a task.
`CalDavVtodoObjectSourceProvider::toObjectEntity()`
(`lib/Service/ObjectSource/CalDavVtodoObjectSourceProvider.php:243-266`)
projects a VTODO into an `ObjectEntity` and merges the
`X-OPENREGISTER-DATA` blob's fields, and `TasksProvider::storageStrategy()`
returns `'link-table'` (`lib/Service/Integration/BuiltinProviders/TasksProvider.php:122`).
The `nc-task` virtual schema (`lib/Repair/SeedAppVirtualSchemas.php:100`)
projects the acting user's whole task list read-only.

**Nextcloud offers two write-back hooks.** A Sabre `ServerPlugin` declared
in `appinfo/info.xml` under `<sabre><plugins><plugin>` is loaded by
`apps/dav/lib/AppInfo/PluginManager.php:155-160`; and `apps/dav` emits
`CalendarObjectCreatedEvent` / `CalendarObjectUpdatedEvent` /
`CalendarObjectDeletedEvent` plus their `Cached*` variants
(`apps/dav/lib/Events/`). They differ in when they fire, which is the whole
design of D-6.

**Constraint from the chain:** `flow-task-entity` lands first and owns the
table, the verbs, the authorization and the inbox QUERY. Nothing in this
change may re-decide any of them.

## Goals / Non-Goals

**Goals:**
- One-directional truth stated as a mechanism, not a convention: a
  projection that drifts should be structurally unable to win.
- Reuse the declarative notification engine end to end; add exactly one
  concept to the dialect and justify it.
- A binary approval answerable in one tap from the notification, with the
  same authorization as any other surface.
- Write-back that fails CLOSED and is legible when it does — the user is
  told, not silently corrected.
- Delivery that cannot take the engine down with it.

**Non-Goals (design-level, on top of the proposal's scope):**
- No new notification transport. `email`, `talk`, `web-push`, `activity` and
  `webhook` are existing channels and are configured, not implemented, here.
- No calendar SHARING model. A projection goes into the assignee's own
  calendar; delegating visibility of that calendar is Nextcloud's feature,
  not this change's.
- No offline conflict resolution beyond last-writer-refused. A client that
  queues an unauthorized completion for a week gets a refusal a week later.
- No redesign of `CnFlowRunsWidget`. The tasks widget is modelled on it
  (`nextcloud-vue/src/components/CnFlowRunsWidget/CnFlowRunsWidget.vue`,
  559L) and does not refactor it.
- No re-derivation of "due soon" or "escalated". Those events are computed
  by `flow-business-timers`; this change delivers them.

## Decisions

### D-1 — Declarative-vs-imperative decision (ADR-031)

**Notifications here are DECLARATIVE with no exception. The calendar
projection and the write-back gate are imperative, and each is imperative
for a reason that is about protocol and trust, never about business rules.**

ADR-031's test is: if an `x-openregister-*` extension can express the
requirement, declare it rather than write a service. Applied surface by
surface:

**Notifications — declarative, fully.** Nothing in this change calls
`INotificationManager` for a task. Task delivery is a rule set in the
canonical `x-openregister-notifications` dialect, evaluated by
`AnnotationNotificationDispatcher`, rendered by `AnnotationNotifier`,
respecting `NotificationPreferenceService`, the queue
(`lib/BackgroundJob/NotificationQueueFlushJob.php`), the dedupe state and
the digest schedule — because it is the same rule set the rest of the
platform uses. Concretely:

- **who**: `recipients` with `kind: users` / `groups` / `field` /
  `expression` over the task's performer model. Nothing bespoke.
- **when**: `trigger: {type: 'transition', action: [...]}` naming the verbs
  `flow-task-entity` records, matched at
  `AnnotationNotificationDispatcher.php:1523-1539`.
- **due / overdue**: `trigger: {type: 'scheduled', intervalSec, filter}`
  using the operator-object filter grammar verified in production at
  `shillinq/lib/Settings/register.d/contract-lifecycle-management.json:383-401`.
- **where**: `channels`, from the six the validator already accepts
  (`NotificationAnnotationValidator.php:53`).

The declarative choice is not decoration. It is what makes a task
notification obey the user's per-rule preferences, dedupe, digest and
localisation without any of that being reimplemented — and it is what lets
`flow-approval-consolidation` retune fleet task delivery by editing rules
instead of shipping PHP.

**The one imperative in the notification path — and its fence.** A task is
not an OR object, so the dispatcher needs an `ObjectEntity` and a `Schema`
to work with. Both are produced in code: `TaskNotificationRules` (a
`SystemSchemaRules` clone, `SystemSchemaRules.php:58`) and a task adapter (a
`SystemEntityObjectAdapter` clone, `:46`). That is the same
imperative-adapter-plus-declarative-rules split ADR-031 already sanctioned
for system entities, and it is fenced by one rule: **the adapter may contain
no notification logic.** It maps task columns and derived fields onto a flat
`ObjectEntity` payload and stops. Every question of who, when, which channel
and what text is answered by a rule.

*Alternative rejected:* making the task an OR object so schema-declared
rules apply directly. `flow-task-entity` D-2 already rejected that for
storage reasons (pooled-query cost, atomic claim, ACL-vs-performer
authorization). Reversing it just to reach the dialect would trade a
correctness property for a plumbing convenience — and the adapter costs
about eighty lines.

**The dialect extension — one new action target kind.** The dialect can
express everything above EXCEPT a decision, because every declared action
renders `setLink($url, 'GET')` (`AnnotationNotifier.php:235`). One new
target kind, `task-verb`, names a lifecycle verb and outcome and renders
`setLink($url, 'POST')`. This is a genuinely new capability of the dialect,
not a workaround: it is declared in the rule, validated by
`NotificationAnnotationValidator`, and available to every future rule. It is
also deliberately narrow — the target names a VERB, never a URL the author
composes, so a rule cannot use a notification button to POST anywhere it
likes. *Alternative rejected:* an imperative task notifier that builds
actions in PHP. It would work, and it would make task notifications the one
family in the platform that ignores user preferences and dedupe, and would
leave the dialect still unable to express a decision for anyone else.

**The calendar projection — imperative, because iCalendar is a wire
format.** Building a VCALENDAR/VTODO body, negotiating `CalDavBackend`,
picking a calendar, and issuing a whole-object PUT are protocol mechanics.
ADR-031 keeps exactly this category in PHP. There is no schema extension
that emits an ICS blob, and inventing one would be a serialization format
described in JSON.

**The write-back gate — imperative, because it is a trust boundary.** The
gate's whole job is to distrust its input: verify the task identity, verify
the transition is legal, verify the caller, and refuse otherwise. A
declarative guard evaluates over data that is assumed well-formed; this code
runs before that assumption holds. It is also fail-closed by construction
(ADR-005): unknown property shape, unresolvable task, unavailable
authorization service — every one denies.

**Derived, never stored — restated because a projection is where it would
regress.** `overdue` has no column (`flow-task-entity`), and the scheduled
rule filters the derived predicate. The temptation this change creates is
specific: writing `overdue` into the projection payload so a scheduled rule
can filter it cheaply. The projection payload is BUILT per evaluation from
the same derivation the inbox uses, so there is no stored copy to go stale —
the mistake decidesk's `actionOverdue`
(`decidesk/lib/Settings/decidesk_register.json:4173-4180`) is still making.

### D-2 — The projection sync contract, in full

Stated here in full because "one-directional truth" is the entire point of
the change and is otherwise the kind of principle a later PR erodes one
convenience at a time.

**1. One writer.** `openregister_tasks` and its audit are the only store.
Every projection is a pure function of a task record plus the derivations
(`overdue`, `days_until_due`, display title) that `flow-task-entity` already
defines. No projection holds a field that cannot be reproduced from the
task.

**2. Rebuild, never merge.** A projection is RENDERED from the task, not
diffed against its previous self and merged. Reconciliation compares the
rendered target with what is there and makes what is there match. Nothing in
a projected surface is treated as an authority to preserve.

**3. Exactly one path back, and it carries requests.** The write-back gate
is the only path from a projection to the engine. What crosses it is a
`(task_uuid, requested_verb, actor)` triple — never a state, never a field
value. The engine decides whether that verb is legal and permitted. This is
the rule that stops `STATUS:COMPLETED` from being able to SET a state:
it can only REQUEST `complete`.

**4. Refusal is visible.** A refused request leaves the engine unchanged,
the projection restored to the engine's truth, an audit denial recorded, and
the actor notified. There is no code path that reverts silently. A calendar
entry that quietly comes back reads as a sync bug and trains users to
distrust the surface.

**5. Identity is carried, not inferred.** Every projected VTODO carries
`X-OPENREGISTER-TASK` (the task uuid). A VTODO without it is not this
capability's business at any point in its life. There is no heuristic
matching on summary, due date or calendar.

**6. Rendering is idempotent and non-reflexive.** Rendering twice with no
change is a no-op. A write the projector performs is tagged so the gate does
not read it back as a user edit, and a gate-driven transition's re-render
cannot re-enter the gate. The tag is a rendered-content hash held on the
task's projection state, so an echo is detectable even when the write
arrives through a path that did not preserve an in-process flag.

**7. Delivery is best-effort; the lifecycle is not.** A projection failure
is logged and retried and NEVER rolls back the transition that caused it
(D-8).

**8. Divergence is detectable.** The projection state per task records what
was rendered and when. Reconciliation is therefore a comparison, not a
guess, and "is anything drifted?" is answerable without reading every
calendar.

The measured reason all eight are written down rather than assumed:
`hermiq/src/manifest.json:1240` — a page that "used to declare
hermiq/agentflow — a duplicate mirror of the native flow rows — so the list
showed objects while the engine ran the native rows, free to drift". That
mirror was read-only to users. A calendar is not.

### D-3 — Notifications ride `dispatchWithSchema()`, not a new pipeline

`TaskNotificationRules` publishes the rule set under a synthetic slug;
`TaskNotificationListener` listens to the task lifecycle events
`flow-task-entity` emits, wraps the task in `TaskObjectAdapter extends
ObjectEntity`, and calls `dispatchWithSchema(object:, trigger:, context:,
schema:)` with `context['action']` set to the recorded transition action.
Copied wholesale from `SystemEntityNotificationListener.php:94-127`.

Two consequences worth being explicit about:

- **Dedupe keying.** `NotificationDedupeState` is keyed per object today.
  The adapter's uuid IS the task uuid, so a task is a stable dedupe subject
  without changing the mapper. This is why the adapter must expose the task
  uuid as the entity uuid and not, say, the subject object's.
- **Recipient resolution.** `kind: field` (the kind decidesk's
  `actionItemAssignedToYou` uses) reads a field off the payload, so the
  adapter must flatten `assignee`, `candidate_users`, `candidate_groups`,
  `requester` and `watchers` into readable payload fields. Pool recipients
  use `kind: groups` with the group list resolved from the payload rather
  than hardcoded — the pool is per task, unlike decidesk's static
  `decidesk-members`.

*Alternative rejected:* emitting synthetic object events so the existing
`AnnotationNotificationListener` picks tasks up. It would put non-objects on
the object event bus and every other object listener would have to learn to
ignore them.

### D-4 — Withdrawal, not just delivery

An actionable notification is a promise that the buttons still mean
something. `NotificationService.php:228` already uses
`IManager::markProcessed()`; the task listener uses the same call on
terminal transitions and on assignee change.

The case that makes this non-optional is the pooled task: four people are
notified, one claims, and the other three are holding approve buttons for
work that is no longer theirs. Without withdrawal the second click gets a
conflict — correct, and a bad experience that reads as a broken system.
Cancellation-by-propagation (`flow-task-entity` D-8) is the same problem
arriving from the engine side.

### D-5 — What is projected, into whose calendar, and what it carries

**Whose calendar.** The ASSIGNEE's first VTODO-supporting calendar, not the
session user's. `findUserCalendar()` (`lib/Service/TaskService.php:561-582`)
resolves from `IUserSession` today; the projection needs the same resolution
parameterised by uid. This is the single most consequential difference from
the current leaf: a task assigned by A to B must land in B's calendar, and
the code that exists resolves A's.

**Pooled tasks are not projected.** A VTODO lives in one calendar. There is
no calendar for "whoever claims it", and projecting into every member's
calendar would assert four assignments the engine has not made and then have
to retract three. Pooled work is delivered by notification and by the inbox;
the calendar entry appears on claim.

**What the VTODO carries** (rendered by the projector, read by the gate):

| VTODO property | Source | Note |
|---|---|---|
| `UID` | task uuid | stable across reassignment |
| `SUMMARY` | task display title | synthesized where `title` is null, never persisted back |
| `DESCRIPTION` | task description | assignee prose REMOVED |
| `DUE` | `due_at` | advisory; `expires_at` is not projected as `DUE` |
| `PRIORITY` | normalised priority → iCal 0-9 | the inverse of the import mapping `flow-task-entity` publishes |
| `STATUS` | lifecycle state → the four VTODO values | lossy by construction, see D-2 rule 3 |
| `URL` | task form deep link | **new**; today no `URL` is emitted at all (`:400-440`) |
| `X-OPENREGISTER-TASK` | task uuid | **new**; the identity the gate keys on |
| `X-OPENREGISTER-TASK-ASSIGNEE` | assignee uid | **new**; replaces `'Assigned to: '` prose |
| `X-OPENREGISTER-REGISTER/SCHEMA/OBJECT` | the task's anchor | unchanged shape, so the existing read path still resolves the subject |
| `LINK` | subject object API URI | unchanged (RFC 9253) |

`URL` and `LINK` do different jobs and both are kept: `LINK` points at the
subject object's API resource (machine), `URL` points at the task form
(human). The existing `buildTaskDeepLink()` (`:352-364`) builds a link into
the Tasks app — useful for the standalone leaf, and exactly backwards for a
projection, which is trying to get the user OUT of the calendar and into the
task.

**Assignee as a real identity.** `extractAssigneeFromDescription()`
(`:258-264`) returns `substr($description, strlen('Assigned to: '))`, and
`CnTasksTab.vue:304` writes that prefix plus a DISPLAY NAME — so the value
never round-trips to an account and two people sharing a display name are
indistinguishable. The docblock at `:112` claims the filter "matches
ATTENDEE or description"; no code in the file reads `ATTENDEE`. The
projection carries `X-OPENREGISTER-TASK-ASSIGNEE` as the uid. Whether to
ALSO emit a standards-track `ATTENDEE` (RFC 5545 §3.8.4.1, valid on VTODO)
is deferred — see Open Questions; it changes no requirement either way,
because the requirement is "machine-readable identity", not a property name.

### D-6 — Write-back: refuse in-band first, revert second

Two hooks, used for different reasons, both required.

**Primary — a Sabre `ServerPlugin`** declared in `appinfo/info.xml` under
`<sabre><plugins><plugin>`, loaded by
`apps/dav/lib/AppInfo/PluginManager.php:155-160`. It subscribes to
`beforeWriteContent` / `beforeUnbind`, parses the incoming ICS, and acts
only when `X-OPENREGISTER-TASK` is present. It maps the requested change to
a verb, calls the entity `TaskService`, and on refusal throws a DAV
forbidden exception. The client gets a 403 on the PUT and **never records
the change**. This is strictly better than reverting: there is no window in
which the user's client believes the task is done.

**Secondary — a listener on `CalendarObjectUpdatedEvent` /
`CalendarObjectDeletedEvent`** (`apps/dav/lib/Events/`), which catches
writes that reached the backend without traversing this plugin — another
app calling `CalDavBackend` directly, or a future DAV path change. Here the
write has committed, so the response is REVERT: re-render the projection
from the task and notify the actor.

*Alternative rejected — event listener only.* Simpler, one hook, and it
means every unauthorized tick is a revert. Clients that have already synced
show the task done and then undone; on a phone that reads as data loss.
*Alternative rejected — plugin only.* Leaves the direct-backend path
unguarded, which is precisely the path a future refactor takes.

**What the gate does NOT do.** It does not interpret `SUMMARY`,
`DESCRIPTION`, `DUE` or `PRIORITY` edits. Those are projection-owned; the
next render overwrites them (D-2 rule 2). Only `STATUS` (and deletion) name
verbs. This keeps the trust boundary one field wide.

**Echo suppression.** Rule 6 of D-2: the projector records a hash of what it
rendered on the task's projection state; the gate compares an incoming
body's relevant fields against it and treats a match as its own echo. A
flag on the request would not survive the async listener path.

### D-7 — `TaskService`'s authority inverts; the file survives

`lib/Service/TaskService.php` (753L) is not deleted and not rewritten. Its
authority changes:

| Method | Before | After |
|---|---|---|
| `createTask()` (`:384`) | creates the task | renders a projection; refuses an engine-identity payload from the sub-resource endpoint |
| `updateTask()` (`:465`) | changes the task | for a projected VTODO, routes through the gate; for a standalone VTODO, unchanged |
| `deleteTask()` (`:540`) | deletes the task | for a projected VTODO, does not cancel the engine task; projection is restored |
| `getTasksForObject()` (`:280`) | the answer | still the answer for standalone VTODOs; engine tasks come from the inbox |
| `getAllUserTasks()` (`:120`) | the user's task list | retired as the aggregate's implementation; `GET /api/tasks` (`appinfo/routes.php:903`) answers from the inbox |
| `findUserCalendar()` (`:561`) | session user's calendar | parameterised by uid; session user for standalone, assignee for projections |

The two-class split (standalone vs projected, keyed on
`X-OPENREGISTER-TASK`) is what lets both live in one file without the file
having two personalities: every branch asks one question.

Left alone deliberately:
`CalDavVtodoObjectSourceProvider` (`:243-266`) and the `nc-task` virtual
schema (`lib/Repair/SeedAppVirtualSchemas.php:100`) project the acting
user's own calendar read-only. A projected VTODO showing up there is
correct — it IS in the user's calendar — and it is read-only, so it cannot
become a second write path. `TasksProvider::storageStrategy()` stays
`'link-table'` (`:122`).

*Alternative rejected — a second service and leave the leaf frozen.* Two
writers to one calendar, no shared notion of which VTODOs are whose, and the
`'Assigned to: '` prose survives in the surface users actually touch.

### D-8 — Delivery failure is isolated from the transition

Projection and notification run AFTER the lifecycle transaction commits, not
inside it. A failure is logged with the task uuid and the failing surface,
and left to reconciliation (D-2 rule 8).

The alternative — projecting inside the transaction so the task and its
calendar entry are atomic — is what would let a calendar backend outage
block an approval chain. The asymmetry is deliberate: a task that exists
with no calendar entry is recoverable by reconciliation; a task that could
not be created because a calendar was down is an outage in the wrong system.
This is also why "assignee has no VTODO-capable calendar" is a skip and a
log, not the exception `findUserCalendar()` throws today (`:576`,
`NoVtodoCalendarException`).

### D-9 — The inbox surfaces, and a one-app leaf repoint

**`CnTasksWidget`** is modelled on `CnFlowRunsWidget` (559L) — same shell,
same loading/empty/error states, same row idiom — reading the inbox API with
its server-side filter, sort, page and total. The badge reads the TOTAL.
`flow-task-entity` D-9 makes filtering-in-the-datastore a spec requirement
for the query; the widget's obligation is to not undo it by filtering the
page it received.

**The leaf repoint** moves `nextcloud-vue/src/integrations/builtin/tasks.js`
(64L), `CnTasksTab.vue` (500L), `CnTasksCard.vue` (387L) and
`src/types/task.d.ts` (31L) from the VTODO sub-resource endpoints
(`appinfo/routes.php:906-909`) onto tasks-by-object. `TTaskStatus` widens
from `needs-action | in-process | completed | cancelled` (`task.d.ts:5`) to
the six CMMN states; `isOverdue` becomes a server-derived field rather than
a client comparison; the assignee becomes an identity; and the tab offers
only the verbs the caller may invoke.

**Blast radius is one app.** `decidesk/src/manifest.json:594` is the only
manifest in the fleet mounting `integrationId: "tasks"`. So the leaf's
contract can change in one coordinated step instead of behind a
compatibility shim — and decidesk is also the owner of both notification
rules being generalised, so it is one conversation, not two.

## Seed Data (ADR-001)

The declarative rule set IS the seed data for this change, plus fixtures
that exercise the projection. All uids are obviously fake and all uuids are
nil placeholders.

**1. Assigned to you — actionable, binary.** The generalisation of
decidesk's `actionItemAssignedToYou`
(`decidesk/lib/Settings/decidesk_register.json:4202-4241`): same channels,
recipient resolved from the task's own assignee field, and two `task-verb`
actions instead of one navigation link.

```json
{
  "taskAssignedToYou": {
    "trigger": { "type": "transition", "action": ["assign", "claim"] },
    "enabled": true,
    "channels": ["nc-notification", "web-push"],
    "recipients": [{ "kind": "field", "field": "assignee" }],
    "subject": {
      "nl": "Aan jou toegewezen: {{title}}",
      "en": "Assigned to you: {{title}}"
    },
    "actions": [
      {
        "label": { "nl": "Goedkeuren", "en": "Approve" },
        "primary": true,
        "target": { "kind": "task-verb", "verb": "complete", "outcome": "approved" }
      },
      {
        "label": { "nl": "Afwijzen", "en": "Reject" },
        "target": { "kind": "task-verb", "verb": "complete", "outcome": "rejected" }
      }
    ]
  }
}
```

The reject action routes to the form rather than completing directly,
because `flow-task-entity` makes a comment mandatory on a rejecting
outcome — the spec requires that routing, and the rule does not have to
know it.

**2. Offered to your pool — no assignee to address.**
`trigger: {type: 'transition', action: 'offer'}`, recipients
`kind: groups` resolved from the task's candidate groups, one navigation
action to the pooled inbox. Exercises the recipient path that
`kind: field` cannot serve.

**3. Overdue — the derived filter, in the verified grammar.** Modelled on
`shillinq/lib/Settings/register.d/contract-lifecycle-management.json:383-401`:

```json
{
  "taskOverdue": {
    "trigger": {
      "type": "scheduled",
      "intervalSec": 86400,
      "filter": {
        "all": [
          { "field": "isTerminal", "operator": "notIn", "values": [true] },
          { "field": "dueAt", "operator": "before", "value": "now" }
        ]
      },
      "dedupeFields": ["taskUuid"]
    },
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [{ "kind": "field", "field": "assignee" }],
    "subject": {
      "nl": "Taak over tijd: {{title}}",
      "en": "Task overdue: {{title}}"
    }
  }
}
```

No `overdue` field appears anywhere in it.

**4. Cancelled by propagation** — `trigger.action: 'cancel'`, recipient the
former assignee, no actions, and it is the fixture that proves withdrawal
(D-4) fires from the engine side.

**5. Projection fixtures**: one assigned task with a calendar projection
(assignee `EXAMPLE_APPROVER_USER`, task uuid
`00000000-0000-0000-0000-000000000002`); one pooled task with NO projection;
one task whose assignee has no VTODO-capable calendar, so the skip path has
a fixture rather than only a test double.

Seeds install through the existing seeding path and are idempotent.

## Migration Plan

1. **Dialect first, backwards compatible.** Add the `task-verb` action
   target kind to `NotificationAnnotationValidator` and the POST render to
   `AnnotationNotifier`. Existing rules are untouched: the three existing
   kinds keep rendering GET. Rollback is removing the kind; no stored rule
   uses it yet.
2. **Adapter, rules, listener.** Task notifications begin. Nothing about the
   calendar has changed and nothing writes back. Rollback is disabling the
   listener; tasks keep working, silently, as they did in
   `flow-task-entity`.
3. **Projection write path.** The projector renders VTODOs for assigned
   tasks; projection state is recorded. Still no write-back — the calendar
   is strictly read-only-in-effect, because any edit is simply overwritten
   on the next render. This is the safest possible intermediate state and it
   is worth pausing in.
4. **Write-back gate.** Sabre plugin plus the event listener. This is the
   step that opens the trust boundary, so it ships alone and with the audit
   assertions already green.
5. **Aggregate and leaf.** `GET /api/tasks` (`appinfo/routes.php:903`)
   switches to the inbox; the nc-vue leaf repoints. Coordinated with
   decidesk, the only mounter (`decidesk/src/manifest.json:594`). The
   sub-resource VTODO endpoints (`:906-909`) keep serving standalone tasks
   throughout.
6. **No data migration.** Existing VTODOs carry no `X-OPENREGISTER-TASK`, so
   every one of them is standalone by definition and keeps its current
   behaviour. Engine tasks are new. There is nothing to backfill and nothing
   to reclassify — which is the payoff for keying the two classes on a
   property that did not previously exist.

Rollback at any step above 3 leaves projected VTODOs in calendars. They are
inert: no `X-OPENREGISTER-TASK` handler means they behave as ordinary tasks.
A cleanup command removes them by property.

## Risks / Trade-offs

- **A POST from a notification is a state change from a surface with weak
  CSRF context** → It lands on the same authenticated controller route as
  any other call and is authorized by `TaskAuthorizationService`
  identically; the target names a VERB, not an author-supplied URL, so a
  rule cannot aim it. The notification is a transport with no privileges of
  its own, and the spec requires a denial to be audited like any other.
- **A user's calendar is shared, so someone else can tick the task off** →
  Exactly the case the gate exists for: the acting DAV identity is checked
  against the task's performer model, refused in-band by the Sabre plugin,
  and reverted-plus-notified where it committed. This is the single most
  likely real-world unauthorized path and it has an e2e scenario.
- **Losing the VTODO status vocabulary's expressiveness** (four values for
  six states) → Accepted and made safe by D-2 rule 3: the mapping is lossy
  only in the render direction, and an incoming status names a TRANSITION
  that the engine may refuse. The calendar can under-describe a task; it can
  never mis-set one.
- **Reassignment churns calendars** — a task reassigned three times deletes
  and creates three VTODOs, and a client that synced in between may show a
  stale entry → Bounded by carrying a stable `UID` and by reconciliation.
  The alternative (leave it in the old assignee's calendar) is worse: it
  shows someone work that is not theirs.
- **Notification volume on a busy pool.** Offering to a 40-person group
  produces 40 notifications, and claiming withdraws 39 → Mitigated by the
  existing dedupe/digest machinery this change deliberately rides rather
  than bypasses; a rule can move a pool offer to a digest without code.
- **A projection state column is one more thing that can drift from the
  task** → It is written by the projector only, holds only a rendered-content
  hash and a timestamp, and is never read by any lifecycle or authorization
  rule. If it is wrong the worst outcome is a redundant re-render.
- **Two hooks into DAV double the surface a Nextcloud upgrade can break** →
  Accepted (D-6): the plugin is the good path and the listener is the safety
  net, and they share one gate implementation, so an upgrade breaking one
  degrades behaviour rather than opening the boundary. A test asserts the
  gate is reached by both.
- **The leaf repoint is a breaking API change for decidesk** → One app, one
  manifest line (`decidesk/src/manifest.json:594`), sequenced last, and
  decidesk owns both rules being generalised. Coordinated, not shimmed.

## Open Questions

- Whether the projected VTODO should ALSO carry a standards-track `ATTENDEE`
  (RFC 5545 §3.8.4.1, valid on VTODO) alongside
  `X-OPENREGISTER-TASK-ASSIGNEE`. Deferrable: the spec requires a
  machine-readable identity, not a property name, so either satisfies it.
  The answer depends on measuring what the Tasks app and common third-party
  clients actually do with `ATTENDEE` on a VTODO, which is an observation to
  make against the running projection rather than a decision to guess now.
  It changes no requirement, no task, and no other decision.
- Reconciliation cadence — whether drift detection runs as a periodic sweep,
  on read, or both. It changes neither the contract (D-2) nor the task
  breakdown, and the right interval is a function of observed drift once
  there is a projection to observe.
