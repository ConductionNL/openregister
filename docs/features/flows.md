# Flows

## Overview

A flow is a process you draw: boxes for the things that happen, arrows for what
happens next. OpenRegister runs it. A flow can start when an object changes, on
a schedule, or when a person presses Run. It can read and write objects, branch,
wait, ask a person, send a message, and call other flows.

Flows are the one flow engine for every app built on OpenRegister (ADR-065). An
app that needs a process ships a flow; it does not build its own engine.

This page is for flow authors and administrators. Everything on it describes
what the engine does today. Where the engine does less than you might expect,
the page says so.

## What flows are, and what they are not

A flow is stored as a document of **nodes** and **edges**. A node is an action.
An edge says which node runs next. Under the hood the engine turns that
document into a Petri net, which is what lets it run branches side by side and
wait for several branches to finish. You never write the Petri net yourself.

Two boundaries matter before you build anything.

**Notifications notify; flows orchestrate.** "Whenever X happens, tell Y" is a
notification rule. Declare it on the schema with `x-openregister-notifications`
(ADR-031, "Schema-declarative business logic over service classes"). See
[Webhooks & Notifications](webhooks-and-notifications.md). A flow's messaging
steps are for a send at a *point in a process*: "after the manager approved,
tell the applicant". Both go through the same notification machinery, so
templates, rate limits and opt-outs behave the same.

**API calls go through OpenConnector.** A flow calls an external API with the
`openconnector.source-call` step, against a source configured in OpenConnector
(ADR-094). OpenRegister ships no HTTP step, and no webhook-sending step, on
purpose: a source is where the base URL, the credentials and the rate limits are
configured once. A flow that needs an API and has no OpenConnector installed
cannot call it.

What flows are not:

- Not a code runner. Conditions and computed values are JSONLogic expressions.
  There is no JavaScript or PHP step.
- Not the n8n/Windmill integration. That is a separate way to hand work to an
  external tool; see [Workflow Automation](workflow-automation.md).

## The canvas

Open **Flows** in the OpenRegister navigation. A row opens the flow's overview
page (what it does, its versions and its recent runs). **Open in editor** opens
the canvas.

### Nodes carry behaviour, edges carry sequence

Each node has an `id`, a `type` (one of the step ids below) and a `config`
object. Each edge has an `id`, a `from` and a `to`:

```json
{
  "nodes": [
    { "id": "start", "type": "openregister.trigger-manual", "config": {} },
    { "id": "tag", "type": "openregister.set-fields", "config": { "set": { "reviewed": true } } },
    { "id": "done", "type": "openregister.end", "config": {} }
  ],
  "edges": [
    { "id": "e1", "from": "start", "to": "tag" },
    { "id": "e2", "from": "tag", "to": "done" }
  ]
}
```

Data moves through a flow as a list of **items**. Each item has a record
(`json`) and attachments (`binary`). Most steps act once per item, so you do not
draw a loop to process a list.

### Branches

- **Several outgoing edges run in parallel.** An edge from `a` to `b` and an
  edge from `a` to `c` run both `b` and `c`.
- **Choosing one branch** uses exits. A node declares `exits`, each with an
  `id` and optionally a JSONLogic `condition`. Edges name the exit they leave
  from with `fromExit`. Exits are tried in order; the first whose condition
  holds wins. An exit without a condition is the "else". A node that conditions
  any exit must also have an else, or the flow will not save. The reason: a token
  with nowhere to go does not error, the run just stops and looks finished.

```json
{
  "id": "check",
  "type": "openregister.switch",
  "config": {},
  "exits": [
    { "id": "large", "condition": { ">": [{ "var": "json.amount" }, 1000] } },
    { "id": "small" }
  ]
}
```

with edges `{"id": "e3", "from": "check", "fromExit": "large", "to": "approve"}`
and `{"id": "e4", "from": "check", "fromExit": "small", "to": "book"}`.

An expression sees `json` (the current item), `binary`, `itemIndex`,
`itemCount`, `context` (run metadata) and `subject` (the object the run is
about). `{"var": "json.status"}` reads the item's `status`.

### Converging edges: merge by default, join on request

Two edges arriving at one node mean "run when **either** arrives" by default.
To wait for **all** incoming edges, set `"join": true` on the node. Merge is the
default because many flows reach one exit from several mutually exclusive
paths; a join there would wait forever.

### Errors per node

A node may carry `onError`: `stop` (the default) ends the run as `failed`,
`dead_letter` ends it as `dead_letter`, and `continue` logs the failure and
goes on.

### Annotations

Free notes pinned to the canvas are stored as `annotations`, each
`{id, x, y, text}`. The engine ignores them. They are not nodes, so a note can
never become something the run waits on. Any config key starting with `$`
(for example `$why`) is also a note: the engine never reads it.

### The form pane and the JSON pane

A step type can describe its own configuration fields (label, control, help
text). The editor then shows a form. A step type that describes no fields is
edited as raw JSON. The [Steps](#steps) section lists every key each built-in
step reads, so you can fill in the JSON by hand.

### Saving, versions and publishing

Saving checks every step against the steps this instance has, and checks each
step's configuration. A step whose app is enabled but does not provide that
type is refused. A step from an app that is not installed is saved with a
warning.

A flow has versions. A **published** version is what triggers and schedules
run. Its graph is fixed: to change the graph, open a new draft
(`POST /api/flows/{id}/draft`), edit, and publish it
(`POST /api/flows/{id}/publish`). Renaming a flow, editing its description or
switching it off is allowed on a published flow. A test run from the editor may
use the draft.

## Triggers

A trigger is the node where a run begins. A flow may carry several. Three
trigger types ship:

| Step id | Name in the palette | Config keys |
|---|---|---|
| `openregister.trigger-object` | When an object changes | `event`, `register`, `schema`, `scope`, `transition`, `changedFields` |
| `openregister.trigger-schedule` | On a schedule | `cron`, `runAs` |
| `openregister.trigger-manual` | When someone runs it | `next` |

**`openregister.trigger-object`** names exactly one `event`, one `register` and
one `schema`. `event` must be `object.created`, `object.updated`,
`object.deleted` or `object.transitioned`; the node refuses the other catalogue
events. Both `register` and `schema` are required: there is no "any".

```json
{ "id": "onCreate", "type": "openregister.trigger-object",
  "config": { "event": "object.created", "register": "zaken", "schema": "melding" } }
```

Why one schema per trigger: objects of two schemas do not have the same fields.
A trigger that took both would hand the next step two shapes under one name.
To react to two schemas, use two trigger nodes and normalise their shapes with a
mapping step where the paths meet.

The optional `scope` names one `property` and one scalar `value`, for example
`{"property": "caseType", "value": "<uuid>"}`. It is validated and shown in the
rule overview. It does **not** filter which events start the flow: a run starts
for every matching object. Put an `openregister.filter` or
`openregister.switch` after the trigger to narrow it.

Two keys **do** decide whether a run starts:

- **`transition`**, with `event: object.transitioned` only. An object of lists:
  `actions` (the lifecycle action names), `from` and `to` (states). A run starts
  only when every list you give contains the transition's own value. An empty
  object, or no `transition` at all, starts on every transition of the schema.
  The run context carries `action`, `from`, `to` and `automatic`.

  ```json
  { "id": "onClosed", "type": "openregister.trigger-object",
    "config": { "event": "object.transitioned", "register": "zaken", "schema": "aanvraag",
                "transition": { "to": ["closed"] } } }
  ```

- **`changedFields`**, with `event: object.updated` only. A list of top-level
  property names. A run starts only when at least one of them has a different
  value after the save than before it. A nested change counts as a change of its
  top-level property. The run context carries the names that changed as
  `changedFields`, so a later step can branch on them too. An update that
  arrives without the previous version starts nothing: nothing proves a watched
  field changed.

  This is also how a flow that writes back onto its own record avoids starting
  itself again: watch the fields it reads, not the fields it writes.

Both keys are refused on any other event, and as an empty list or a list holding
anything but names. Publishing a flow refuses a `changedFields` name that is not
a property of the trigger's schema, because such a trigger would never start.
Renaming a property later is not refused, but when the schema update declares
the rename, the response lists every published trigger that still watches the
old name under `flowWarnings` (flow, step, field and its new name), so the maker
can update the trigger.

**`openregister.trigger-schedule`** carries a five-field `cron` expression and
`runAs`, the user the run acts as. `runAs` is required and must be an existing
account. Nobody is present at 03:00, and the flow's author is not used as a
stand-in. A scheduled run starts with no object; the first step has to fetch
what it works on. See [the cutover](#the-trigger-cutover-honestly) for how the
schedule itself is picked up today.

**`openregister.trigger-manual`** starts the flow on demand. Its only key,
`next`, is a hint shown after the run (an `openregister.end` node can override
it). A flow with a manual trigger says "a person starts this"; a flow with no
trigger at all just looks unfinished.

### The event catalogue

These are the events the engine fires into flows, from
`EventCatalogService::CATALOG`. The builder reads the same list from
`GET /api/flow/event-catalog`.

<!-- flow-event-catalogue:start -->
| Event id | Fires when | Group |
|---|---|---|
| `object.created` | An object is created (old alias `created`) | Object |
| `object.updated` | An object is updated (old alias `updated`) | Object |
| `object.deleted` | An object is deleted (old alias `deleted`) | Object |
| `object.locked` | An object is locked | Object |
| `object.unlocked` | An object is unlocked | Object |
| `object.reverted` | An object is reverted | Object |
| `object.transitioned` | An object changes lifecycle state | Object |
| `file.created` | A file is created | File |
| `file.updated` | A file is written | File |
| `file.deleted` | A file is deleted | File |
| `user.created` | A user is created | User |
| `user.deleted` | A user is deleted | User |
| `share.created` | A share is created | Share |
| `share.deleted` | A share is deleted | Share |
| `tag.assigned` | A tag is assigned | Tag |
| `tag.unassigned` | A tag is removed | Tag |
| `case.item.completed` | A case plan item is completed | Case |
| `case.item.terminated` | A case plan item is terminated | Case |
| `case.item.disabled` | A case plan item is disabled | Case |
<!-- flow-event-catalogue:end -->

`object.created`, `object.updated`, `object.deleted` and `object.transitioned`
can be expressed as an `openregister.trigger-object` node.
The others are reached through the flow's trigger fields (`trigger`,
`triggerRegister`, `triggerSchema`), as described in
[the cutover](#the-trigger-cutover-honestly).

File, user, share and tag events have no OpenRegister object. They match on the
event id alone, so a flow on `file.created` runs for every new file. The
event's details (a file path, a user id) become the run's first item.

## Steps

All built-in steps, by the id you put in `type`. "Keys" lists every top-level
config key the step reads. A key that is not listed is one the step would
ignore, so saving refuses it. Keys starting with `$` are notes and are exempt.

Values in a config can read the item with placeholders: `{{ title }}` reads the
item's `title`. A value that is exactly one placeholder keeps its type, so a
number stays a number.

### Shaping items

**`openregister.set-fields`** (Edit fields). Sets, renames and removes fields on
every item. Keys: `set`, `compute`, `rename`, `remove`, `keepOnlySet`. `set`
takes values (placeholders allowed); `compute` takes JSONLogic expressions.
Order per item: remove, rename, set, compute. `keepOnlySet: true` drops every
field you did not set.

```json
{ "set": { "status": "in-behandeling", "handler": "{{ assignee }}" },
  "remove": ["draftNotes"] }
```

**`openregister.action-inputs`** (Action inputs). No keys. Copies the inputs an
object action was run with onto every item under `inputs`, so later steps read
`{{ inputs.reason }}`. Put it right after the manual trigger of a flow bound to
an action.

**`openregister.explode`** (Explode). Turns a list on the item into one item per
entry. Keys: `path` (required), `as` (default `item`), `keepRecord` (default
true, keeps the rest of the record on each new item).

```json
{ "path": "findings", "as": "finding" }
```

**`openregister.filter`** (Filter). Keeps the items whose `condition` holds.
Keys: `condition` (required, a JSONLogic expression, not a constant). Keeping
none is a valid result, not an error.

```json
{ "condition": { "==": [{ "var": "json.status" }, "open"] } }
```

**`openregister.map`** (Map). Runs every item through a stored mapping. Keys:
`mapping` (required, the mapping reference).

**`openregister.batch`** (Batch items). Splits the items into batches of
`batchSize` (required, 1 or more). Each output item carries its slice under
`items`. Use it before a step that takes a bounded page at a time.

**`openregister.merge`** (Merge). Combines items arriving from several branches.
Keys: `mode` and `key`. `append` (default) keeps all, in order. `mergeByKey`
merges items that share the `key` value; the later branch wins a collision.
`unique` drops later items whose `key` value was already seen. Put
`"join": true` on the merge node when it must wait for every branch.

**`openregister.decision-table`** (Evaluate a decision table). Applies a table of
rules to each item and writes the outcome onto it. Keys: `table` (inline) or
`tableRef` (from the decision-tables register, not both), `inputMapping`,
`outputMapping`, `defaultOutputs` (written when no rule matches; empty fails the
step), `resultKey` (where to record which rules matched).

**`openregister.flow-state`** (Flow state). Remembers values between runs of the
same flow. Keys: `operation`, `key`, `as`, `default`, `value`, `from`, `record`,
`slots`, `capacity`, `leaseSeconds`, `holder`, `slot`. Operations: `get` reads
`key` into the item under `as` (falling back to `default`); `set` writes `value`,
or the item field named by `from`; `forget` removes `key`; `claim` takes a free
slot from the pool named `slots` (needs `capacity`) and puts it on the item as
`slot`; `release` gives it back. `GET /api/flow/{flowId}/state` shows what a
flow holds.

```json
{ "operation": "set", "key": "lastSyncedAt", "from": "updated" }
```

### Branching and ending

**`openregister.switch`** (Switch). No keys. Passes its items through unchanged;
the branch is chosen by the node's `exits` (see [Branches](#branches)). Any node
can have exits; the switch is the visible place to draw them from.

**`openregister.route`** (Route items). Sends each item down its own branch.
Keys: `rules` (required, each with a `condition` and an `output`) and `default`.
Each item goes to the first rule it matches, or to `default`. An item matching
nothing with no `default` is dropped.

```json
{ "rules": [ { "condition": { ">": [{ "var": "json.score" }, 7] }, "output": "high" } ],
  "default": "low" }
```

**`openregister.end`** (End). Ends the run on purpose. Keys: `error`, `message`,
`next`. Without `error` the run ends as `stopped`. With `"error": true` it ends
as `failed` with `message`. `next` overrides the manual trigger's hint for runs
that end here.

### Waiting

**`openregister.wait`** (Wait). Pauses the run, then carries on. Keys: `for` (a
duration such as `2 hours`; a bare number is seconds) or `until` (a moment).
The run is stored as `suspended` and a background job wakes it.

**`openregister.await-signal`** (Wait for an answer). Pauses until someone
or something answers. See [Approvals](#approvals). Keys: `question` (required),
`assignee`, `signalKey`, `heartbeatMinutes`, `failOnReject`, `correlationKey`.

### Repeating and calling other flows

**`openregister.iterate`** (Repeat until done). Runs a chain of steps once per
batch until the source produces nothing. Keys: `source` (a step, with a `type`),
`body` (a list of steps, each with a `type`), `maxIterations` (default 100),
`onLimit` (`fail`, the default, or `stop`). The iteration number is in
`context.iteration`, which is how a source asks for the next page.

**`openregister.sub-flow`** (Run a flow). Runs another flow as one step. Keys:
`flow` or `flowId`, `wait`, `fanOut`. With `wait` (the default) the child runs
now with this step's items and its output comes back. With `"wait": false` the
child is queued and this flow carries on. With `"fanOut": true` the child runs
once per item; one failing child fails the step. A flow that would call itself,
directly or round a cycle, is refused.

### Objects

Object steps act **as the run's acting user**, with that user's permissions. A
run with no resolvable user reads and writes nothing, and says why.

**`openregister.object-read`** (Read objects). Finds objects and puts them on
the item. Keys: `register`, `schema`, `filters`, `fanOut`, `limit` (default 100),
`output` (default `objects`). By default the matches land as one list under
`output`. With `"fanOut": true` the step emits one item per object, so the
next steps act on each one. A filter whose placeholder renders empty fails the
step instead of asking a wider question.

```json
{ "register": "zaken", "schema": "lock", "filters": { "status": "stale" }, "fanOut": true }
```

**`openregister.object-write`** (Write an object). Creates, updates, upserts or
deletes one object per item. Keys: `register`, `schema`, `operation`, `fields`,
`payloadFrom`, `match`, `replace`, `bulk`, `skipWhen`, `output`,
`confirmDelete`, `permanent`, `maxWrites`, `onConflict`, `onMissing`,
`onNoMatch`, `subjectRole`. `operation` is `create`, `update`, `upsert` or
`delete`; the last three need a `match`. Updates patch: a field you do not map
is left alone, unless you set `"replace": true`. A delete needs a `match` that
finds exactly one object and `"confirmDelete": true`, and is a soft delete
unless `"permanent": true`. `bulk: true` writes a whole page in one call
(create and upsert only).

```json
{ "register": "zaken", "schema": "melding", "operation": "update",
  "match": { "uuid": "{{ uuid }}" }, "fields": { "status": "afgehandeld" } }
```

**`openregister.transition`** (Lifecycle transition). Moves the object through a
declared lifecycle transition, with the same guards and audit as a direct move.
Keys: `action` (the transition), `data`, `object` (another object than the
item's). A refused move fails the step.

**`openregister.link-object`** (Create or link an object). Keys: `register`,
`schema`, `data`, `relation`, `handoff`. With `register`, `schema` and `data` it
creates a related object and, when `relation` names a property, writes the new
uuid into it. With `handoff` it runs the schema's declared hand-off to another
app. If that app is not installed the step fails.

**`openregister.lock-object`** (Lock an object). Holds the object for this run.
Keys: `uuid` (default: the item's object), `duration` (default one hour),
`waitSeconds` (how long to keep retrying while another run holds it, default one
hour), `process`, `subjectRole`. A held lock parks the run and retries; when the
wait runs out the step fails. It never breaks someone else's lock.

**`openregister.unlock-object`** (Unlock an object). Keys: `uuid`. Releases a
lock this run holds. Optional: a run releases all its locks when it ends,
however it ends. This step only releases sooner.

**`openregister.harvest-source`** (Harvest a source). Keys: `sourceId`. Runs the
source's harvest pipeline once under a per-source lock and emits the run summary
as its one item. A disabled source, a type with no registered fetcher and a
source without a mapping fail the run (`source-disabled`, `fetcher-missing`,
`mapping-missing`); a run that finds the lock held is skipped (`already-running`).
A source with a schedule and an account gets a flow with this step written for
it; see [Add a harvest protocol from your app](../development/harvest-protocols.md).

**`openregister.case-open`** (Open a case). Keys: `definition` (the case plan),
`uuid`. Starts a case plan on the object. If it already has one, the item says
so (`case.opened: false`) and the flow carries on.

**`openregister.case-advance`** (Advance a case). Keys: `item`, `to`, `reason`,
`uuid`. Moves one plan item (a stage, task or milestone) to a new state. A move
the plan does not allow fails the step.

### People

**`openregister.user-task`** (Ask a person or group). Creates a task for a
person, a group or an agent, and waits for the answer. The task sits in the
task inbox (**Tasks**, `/api/flow-tasks`), where it can be claimed, delegated,
completed or cancelled. Main keys: `title`, `description`, `assignee`,
`candidates`, `outcomes`, `outcomeKey` (default `task`), `dueAt`, `expiresAt`,
`failOnReject`. The full list also covers routing (`candidateUsers`,
`candidateGroups`, `candidateRole`, `routingStrategy`, `routingFallback`,
`performerType`, `prompt`, `priority`), timers (`sla`, `calendar`, `ladder`,
`escalationRules`, `onTimeout`, `onExpiry`), `onReject`, `heartbeatMinutes`,
`advance`, `attachTo`, `purpose`, `legalEffect` and the form keys `formKind`,
`formSchema`, `formAction`, `formFields`, `formId`, `formRequireChecklist`.

**`openregister.portal-task`** (Ask a party outside the organisation). Asks the
applicant or another party on the case, through the portal, and waits for an
answer or upload. Keys: `title`, `description`, `partyRole`, `uploadRequired`,
`uploadMaxFiles`, `uploadAcceptedTypes`, `uploadMaxSizeMb`, `outcomeKey`,
`reasonField`, `dueAt`, `expiresAt`, `onTimeout`, `onReject`,
`heartbeatMinutes`, `advance`.

### Messaging

Notifications notify; flows orchestrate. Use these steps for a send at a point
in the process. For "whenever X happens, tell Y", use a schema notification
rule instead ([Webhooks & Notifications](webhooks-and-notifications.md)). For an
external API, use `openconnector.source-call`: there is deliberately no
send-webhook step.

All three go through the same notification machinery as a schema rule
(ADR-031): the same recipient resolution, `{{ field }}` placeholders, rate
limits and switches. They pass their items through unchanged. A send that fails
fails the step, so a completed run never hides an undelivered message. A
recipient list that resolves to more people than the configured bound is
refused before anything is sent.

**`openregister.send-notification`** (Send a notification). An in-app Nextcloud
notification; web push rides along under the normal rules. Keys: `recipients`
(required: user ids, group ids, or an item field such as `{{ assignee }}`;
groups are expanded), `title`, `message` (required).

```json
{ "recipients": ["{{ assignee }}"], "title": "New report",
  "message": "Report {{ title }} is waiting for you." }
```

**`openregister.send-email`** (Send an email). Keys: `recipients` (required),
`subject`, `body` (required), `externalRecipients`, `messageCategory`.
Recipients take the same forms, plus email addresses when
`externalRecipients` allows them: `none` refuses addresses, `object` mails only
addresses on the item, `any` mails every valid address. `messageCategory`
decides whether an opt-out applies; empty means service mail.

**`openregister.send-talk-message`** (Send a Talk message). Keys:
`conversation` (required, a token or an item field), `message` (required). The
message is posted as the run's acting user, who must already be a participant.
The step never adds anyone to a conversation; "not a participant" fails it.

### Steps from other apps

Other apps add steps to the palette through the same registration event
OpenRegister uses. `openconnector.source-call` comes from OpenConnector and
`hermiq.agent-step` from Hermiq; their documentation lives with those apps. A
flow naming a step from an app that is not installed still saves (with a
warning) and becomes runnable when the app is enabled.

## Approvals

`openregister.await-signal` pauses a run until an answer arrives. Use it for a
system that calls back, or for a simple yes/no. For a person who has to be
found, told and able to delegate, use `openregister.user-task`; for a party
outside the organisation, `openregister.portal-task`.

**1. Ask.** The step needs a `question`. `assignee` records who should answer
(a user or group id).

```json
{ "id": "approval", "type": "openregister.await-signal",
  "config": { "question": "Approve the grant for {{ applicant }}?", "assignee": "managers" } }
```

**2. Tell the person.** The await step itself sends nothing. Put a
`openregister.send-notification` or `openregister.send-email` step before it to
tell the approver, with a link to the case.

**3. Answer.** The answer is a POST to the run:

```bash
curl -u manager:pass -X POST -H 'Content-Type: application/json' \
  https://your-instance/index.php/apps/openregister/api/flow-runs/<run uuid>/resume \
  -d '{"decision": "approve", "reason": "Budget available"}'
```

When the step declares a `correlationKey` such as `grant:{{ uuid }}`, a caller
can answer by key instead of run id:
`POST /api/flow-run-signals/{key}`. No match gives 404; more than one match gives
409 and wakes nothing.

Who may answer: when the step recorded an `assignee`, only that user (or a
member of that group) may answer. A step with no `assignee` can be answered by
anyone who may resume the run. Resuming also requires the run's flow to be yours,
or the `flow.update` right, or administrator rights.

A POST without a `decision` is a nudge, not an answer: the step goes back to
waiting. That makes a double click harmless.

**4. Route on the answer.** The whole payload is written onto every item under
`signalKey` (default `signal`). Branch on it with exits:

```json
{ "id": "decide", "type": "openregister.switch", "config": {},
  "exits": [
    { "id": "yes", "condition": { "==": [{ "var": "json.signal.decision" }, "approve"] } },
    { "id": "no" }
  ] }
```

A rejection (`reject` or `rejected`) is not a failure by default; route on it.
Set `"failOnReject": true` to end the run as `failed` instead.

**The heartbeat.** While waiting, the step wakes every `heartbeatMinutes`
(default 15, minimum 5) and checks for an answer. An answer wakes the run on the
next worker pass either way; the heartbeat is a safety net for a lost wake-up.

**When nobody answers.** The run stays `suspended` and keeps checking. It is not
failed automatically: the engine's 14-day reaper only fails runs suspended
*without* a wake-up time, and every built-in waiting step, this one included,
always sets one. While the run waits, a scheduled flow does not start a new run
(the scheduler skips a flow whose previous run is still active). There is no
per-step timeout on `openregister.await-signal` and no endpoint to cancel one
run. To end it, answer it (for example with `"decision": "reject"`). For
deadlines and escalation, use `openregister.user-task`, which has `dueAt`,
`expiresAt` and the business timers.

## Runs

Every run is stored. **Flows → a flow → its overview** lists recent runs; a run
opens its detail page. The API is `GET /api/flow-runs` (filter by `flowId`,
`status`, `since`), `GET /api/flow-runs/{uuid}`, `GET /api/flow-runs/active`
and `GET /api/flow-runs/summary`.

You see the runs you started, plus every run of the flows you own.

### Statuses

| Status | Meaning |
|---|---|
| `queued` | Waiting for the background worker |
| `running` | A worker is executing it now |
| `suspended` | Paused mid-graph: a wait, an answer, a task or a lock |
| `awaiting_consent` | Waiting for a person to allow the delegation the run needs |
| `completed` | Reached its end |
| `stopped` | Ended on purpose: an end step, or the kill switch |
| `failed` | A step failed (with `onError: stop`), or the run was reaped or expired |
| `dead_letter` | A step failed and its `onError` was `dead_letter` |

`completed`, `stopped`, `failed` and `dead_letter` are final. The others are
"active".

### What a run records

Per step, the run log records the number of items in and out, a sample of the
first 5 input and output items, and up to 50 log lines the step wrote (each up
to 500 characters). The sample is small on purpose: it shows the shape, not the
data. Run history is kept for the period an administrator sets (**Keep run
history for (days)** in the flow settings); a flow can set its own
`retentionDays`.

The flow itself carries the last finished run: `lastRunUuid`,
`lastRunStatus`, `lastRunMessage` and `lastRunAt` (empty means it never ran).
`status` and `statusMessage` hold the engine's verdict on the flow, for example
why it cannot run.

### Retry and resume

- **Retry** (`POST /api/flow-runs/{uuid}/retry`) works on a finished run only. It
  queues a **new** run for the same flow, subject and trigger, from the start.
  The old run is left as it was. Steps that already wrote or sent will do so
  again.
- **Resume** (`POST /api/flow-runs/{uuid}/resume`) works on a `suspended` run
  only. It delivers an answer (see [Approvals](#approvals)) and the worker
  continues from where the run paused.

Both are allowed to the flow's owner, users with `flow.update`, and
administrators.

### Limits

A run may take `limits.maxRuntimeMinutes` (or the instance setting, default 60)
of worker time before it is stopped at the next step; 0 means no limit. A run
fires at most 1000 steps. A run left in `running` by a worker that died is
failed by the reaper, not restarted, because restarting would repeat its side
effects. A run still `queued` after 24 hours is expired as `failed`.

### Why a half-wired flow saves but will not run

A node with no outgoing edge, that is not an end node and is not marked
`"exit": true`, is a dead end: the run would stop there and report
`completed`. Saving such a flow is allowed, because a flow is half-wired while
you build it; saving warns. **Running** it is refused, naming the nodes, on
every path: the Run button, triggers, schedules and sub-flow calls.

## Stopping things

What exists today:

- **Switch one flow off.** Set `enabled` to false. Triggers and the scheduler
  no longer start it. Runs already queued or suspended are not ended by this.
- **The kill switch (whole instance).** An administrator turns on
  **Kill switch — stop all flow execution now** in the flow settings (app
  config `flow_kill_switch`). Every run, on every flow, ends as `stopped` at its next
  step, and the run log names the check that stopped it. It is checked before every
  step, so a run that resumes after a wait is caught too. Nothing can exempt a
  flow from it. Turn it off to let new runs through; stopped runs stay stopped
  (retry them if needed).
- **A halt for one organisation.** An administrator can halt one app's steps
  for one organisation (`POST /api/organisations/{uuid}/halts`, with an app and
  an optional step-id prefix). Steps it covers are refused for that
  organisation's runs. Remove it with
  `DELETE /api/organisation-halts/{id}`.
- **An end step.** `openregister.end` stops a run on purpose.
- **Stale runs.** The background worker fails runs stuck in `running` (after 15
  minutes by default, never sooner than the runtime a run is allowed), expires
  runs `queued` for over 24 hours, and fails runs suspended with no wake-up time
  after 14 days.

What does not exist:

- There is no endpoint or button to cancel one run. Use the kill switch, wait
  for the reaper, or answer the step the run waits on.
- A flow cannot be deleted while one of its runs is unfinished.

## Sharing

What governs access to a flow today:

- Every flow belongs to one **organisation**. You only see and open flows of
  your active organisation.
- **Rights.** Five named rights, each seeded for all signed-in users and
  narrowable by an administrator: `flow.read` (list and open flows),
  `flow.create`, `flow.update` (edit, adopt, dry-run, and run or retry a flow
  you do not own), `flow.delete`, and `flow.run` (may start flows at all).
- **Running someone else's flow** needs more than `flow.run`: you must own the
  flow, hold `flow.update`, or be an administrator. A flow without an owner
  runs for nobody until somebody adopts it.
- **Whose rights a run uses.** A run you start acts as you. A triggered run acts
  as the user whose action caused the event. A scheduled run acts as the
  trigger's `runAs`. When none of these names anyone, the run is refused: the
  flow's owner is never used as a stand-in.
- **Run history** is visible to whoever started the run and to the flow's owner.

There is **no per-flow share list yet**. Read-and-run sharing with named users
or groups, where a recipient can never edit, is designed but not built: the
share fields exist on the flow's storage schema but nothing reads them when a
flow is opened or run. Until it ships, letting a colleague run your flow means
giving them `flow.update`, which also lets them edit it.

Copying flows to another organisation or instance does exist, as part of a
configuration set. An installed flow arrives disabled and owned by the person
who installed it.

## Shipping flows

An app ships a flow inside its register configuration, on a schema, under
`x-openregister-flows`:

```json
{
  "slug": "melding",
  "configuration": {
    "x-openregister-flows": [
      {
        "name": "Notify the team about a new report",
        "description": "Tells the intake group when a report arrives.",
        "trigger": "object.created",
        "triggerRegister": "zaken",
        "nodes": [ ... ],
        "edges": [ ... ]
      }
    ]
  }
}
```

The keys read are `name` (required), `app`, `description`, `trigger`,
`triggerRegister`, `cron`, `executionMode`, `nodes`, `edges` and `limits`. The
declaring schema becomes the flow's trigger schema.

What happens (`SchemaFlowImportListener`, on every schema save and configuration
import):

- A new declaration becomes a flow that is **disabled and has no owner**, with
  version 1 published. Nothing runs yet. Someone adopts it
  (`POST /api/flows/{id}/adopt`, which makes the caller the owner) and switches
  it on. Adopting is the moment a person takes responsibility for it.
- A later import **updates** that same flow (matched on app, name and schema)
  instead of adding a copy. It never re-enables a flow an administrator switched
  off and never changes its owner. A changed graph is published as the next
  version when the rules allow; otherwise the previous version keeps running and
  a warning is logged.
- A broken declaration is logged and skipped. It does not stop the schema from
  saving or its other flows from importing.

When to ship and when to author in place: ship a flow when the process belongs
to the app and every installation should have it, so it is versioned with the
app. Author in the editor when the process is local to one organisation.

## The trigger cutover, honestly

Triggers used to be four fields on the flow: `trigger`, `triggerRegister`,
`triggerSchema` and `cron`. They hold exactly one trigger. Trigger **nodes**
replace them, but the fields have not gone away. Both are live, with one rule
deciding which wins.

**Which regime a flow is in.** For object events:

- A flow whose **published** version has at least one complete
  `openregister.trigger-object` node is *converted*. It fires from its nodes
  alone. Its trigger fields are ignored for every event.
- A flow with no such node is *unconverted*. It fires from its trigger fields,
  as before, provided it has a published version, is enabled and has an owner.

So look at the published graph: an `openregister.trigger-object` node with
`event`, `register` and `schema` filled in means nodes; anything else means the
fields.

**What re-authoring changes.** Adding an object trigger node and publishing
switches the flow to nodes. From then on:

- deleting a trigger node really unsubscribes the flow from that event;
- several trigger nodes give several entry points;
- the old trigger fields no longer fire anything, so a flow that also relied on
  a field-based event (for example `file.created`, which has no node form) stops
  firing on it. Keep such a flow unconverted, or split it.

Deleting the **last** object trigger node turns the flow back into an
unconverted one, and its trigger fields apply again if they are still set.

**What is still field-only:**

- Every catalogue event except `object.created`, `object.updated` and
  `object.deleted`.
- The schedule. The scheduler picks flows whose `trigger` is `schedule` and
  whose `cron` is set. An `openregister.trigger-schedule` node supplies `runAs`
  (whose rights the run uses); it does not put the flow on the schedule by
  itself. A scheduled flow needs both today.
- An unscoped object trigger. Empty `triggerRegister` and `triggerSchema` mean
  "any register, any schema". A node cannot say that, on purpose. Such a flow
  keeps its fields until it is scoped. The workaround is one
  `openregister.trigger-object` node per register and schema pair it really
  needs, with a mapping step if the shapes differ.

The fallback to the fields is the deprecation path, not a second permanent
model. Flows still on it are the ones to re-author.

## Related Features

- [Put a task on a record](record-tasks.md): the record page's Tasks tab over
  the same task inbox
- [Workflow Automation](workflow-automation.md): handing work to external tools
  (n8n, Windmill), schema hooks and declarative lifecycles
- [Webhooks & Notifications](webhooks-and-notifications.md): notification rules,
  the "whenever X, tell Y" side of the boundary
- [Event-Driven Architecture](event-driven-architecture.md): the events the
  trigger catalogue is built on
- [Object Storage & Lifecycle](object-storage.md): locks and lifecycle
  transitions used by the object steps
