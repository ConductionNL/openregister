---
status: in-progress
---

# flow-engine Specification

**OpenSpec changes**
- `or-delegated-identity` (active) — a run records the identity it EXECUTES AS (`runAs`) separately from what CAUSED it (`triggeredBy`); a run's identity comes from its trigger node rather than from the flow definition, so a schedule trigger must declare a resolvable user or fail to save; and identity is re-resolved at every fire and resume rather than snapshotted (ADR-099).

- `or-delegation-grants` (active) — turns a DECLARED acting identity into an AUTHORIZED one: a delegation grant record with a consent lifecycle, refusal at save and at every fire when the author holds no grant for the user they named, and an `awaiting_consent` run state deduped on (principal, actingAs, scope) (ADR-099).

## Purpose
Defines how OpenRegister reads a flow document and turns it into a run: what carries behaviour (the node), what carries sequence (the edge), when converging paths merge versus synchronise, how a path is allowed to end, and what a flow records about its own runnability and its last run. This is the fleet's single flow engine (ADR-065) — openconnector and hermiq contribute node types to it and do not implement their own.

## Requirements

### Requirement: A NODE carries the behaviour and an EDGE carries only sequence @e2e exclude engine-internal document lowering — covered by FlowDefinitionBuilder unit tests

A flow document consists of `nodes[]` and `edges[]`. Each node MUST carry the `type` and `config` of the step it performs; each edge MUST carry only `from`, `to` and optional display text. `FlowDefinitionBuilder::build()` MUST lower each node to one transition carrying that node's `type`/`config`, and `FlowTokenRouter::stepFor()` MUST resolve a transition name to its NODE rather than to an entry in `edges[]`. `RegistryStepDispatcher` MUST read `type` and `config` off the node it is given.

#### Scenario: A step is read from the node, not the edge
- **GIVEN** a node `find-work` with `type: openregister.object-read` and an edge leaving it that carries no `type`
- **WHEN** the run reaches the transition named `find-work`
- **THEN** the dispatcher MUST execute `openregister.object-read` with that node's `config`
- **AND** the edge MUST contribute only the target place

### Requirement: A pre-inversion document MUST be refused, never reinterpreted @e2e exclude engine-internal refusal — covered by FlowDefinitionBuilder unit tests

A document is pre-inversion if and only if any EDGE carries a non-empty `type`. `FlowDefinitionBuilder` MUST refuse such a document with a message naming the offending edge and the migration to run. It MUST NOT attempt to read the step off the edge, and MUST NOT repair the document in place.

#### Scenario: A half-migrated flow is refused rather than partly run
- **GIVEN** a document in which one edge still carries `type: openregister.set-fields`
- **WHEN** the engine is asked to build it
- **THEN** it MUST throw, naming that edge
- **AND** it MUST NOT run the remaining nodes, because a flow that runs while skipping the step nobody claimed would report success having not done the work

### Requirement: Converging edges MERGE by default; synchronising requires `join: true` @e2e exclude engine-internal lowering semantics — covered by FlowMergeTest

Where several edges converge on a node, the lowering MUST give that node ONE shared input place, so the node fires when ANY predecessor delivers a token (an OR-merge). A node that MUST wait for every predecessor MUST declare `join: true`, which lowers to one input place per incoming edge, all required (an AND-join).

#### Scenario: Three mutually exclusive paths converge and fire once
- **GIVEN** a routing node whose three branches all lead to `done`, and `done` does not declare `join`
- **WHEN** exactly one branch is taken and its token reaches `done`
- **THEN** `done` MUST fire on that single token
- **AND** the run MUST NOT wait for the two branches that were never taken

#### Scenario: A declared join waits for all of its predecessors
- **GIVEN** a node declaring `join: true` with two incoming edges
- **WHEN** a token arrives on one edge only
- **THEN** the node MUST NOT fire
- **AND** it MUST fire once a token has arrived on both

### Requirement: SUSPENDING is a run-level act, so an EMPTY firing MUST NOT suspend @e2e exclude engine-internal suspend rule — covered by WaitNodeTest

`FlowSuspension` stops the WHOLE run and stores its marking; it is not scoped to the branch that threw it. A transition MAY fire with no items — a routing node sent every item down another branch, or that branch had no work this pass — and a node that waits MUST return those items unchanged rather than suspend.

This matters wherever branches are PRIORITIES rather than alternatives. Such a flow evaluates a preferred branch first and falls through when it is empty, so an empty branch reaching a wait is the normal case, not an error. Suspending on it stops the branch that DID carry an item, and when the run resumes the marking has moved on: every remaining transition fires empty and the item is gone, while the log records only `completed` steps with zero items in and zero out.

Nothing is deferred by returning early. With no items there is nothing to delay, and a later pass that DOES carry items reaches the node and suspends then.

#### Scenario: An empty branch does not pause the run
- **GIVEN** a flow whose routing node sends its only item to a collect branch, leaving a dispatch branch that also contains a wait
- **WHEN** the wait on the empty dispatch branch fires with no items
- **THEN** the run MUST NOT suspend
- **AND** the collect branch MUST advance in the same pass

#### Scenario: A wait carrying work still suspends
- **GIVEN** the same wait node and configuration
- **WHEN** it fires with one or more items on its first pass
- **THEN** it MUST suspend the run with the resolved `resumeAt`

#### Scenario: A resumed wait passes its items through
- **GIVEN** a run woken by the worker because `resumeAt` has passed
- **WHEN** the wait node runs a second time with `resuming` set
- **THEN** it MUST return its items unchanged rather than suspend again

### Requirement: A path MUST end deliberately, and a dead end MUST be reported @e2e exclude engine-internal connectivity check — covered by FlowDeadEndTest

A node with no outgoing edge is a dead end unless it ends the path deliberately. It ends deliberately if EITHER its type is registered as an end (the type implements `IFlowEndNode`, resolved through `FlowNodeRegistry::isEnd()`) OR the node itself declares `exit: true`. The two MUST be OR-ed, never AND-ed, so a migrated flow whose sink carries an ordinary action type is not refused, and a contributed end type needs no OpenRegister change.

`FlowNodePreflight` MUST report every dead end as a WARNING carrying `reason: node-dead-end` and naming the node. It MUST NOT report a node whose `type` is empty — that node is already refused by name in `FlowDefinitionBuilder`, and two findings on one node for one defect is how a warning list becomes noise.

#### Scenario: A forgotten sink is named
- **GIVEN** a document whose node `forgotten` carries an ordinary step type, has no outgoing edge and no `exit` flag
- **WHEN** the document is inspected
- **THEN** the report MUST contain a `node-dead-end` warning naming `forgotten`
- **AND** `blocking` MUST remain empty, because a dead end never blocks a save

#### Scenario: A wired graph reports nothing
- **GIVEN** the same document with `forgotten` connected onward to an end node
- **WHEN** the document is inspected
- **THEN** it MUST produce no `node-dead-end` warning

#### Scenario: `exit: true` ends a path whose type is not terminal
- **GIVEN** a single node carrying `type: openregister.set-fields` and `exit: true`, with no edges
- **WHEN** the document is inspected
- **THEN** it MUST produce no `node-dead-end` warning

### Requirement: Creating, editing and running a flow are named rights

`flow.create`, `flow.update`, `flow.delete` and `flow.run` MUST exist in the
action matrix and MUST be enforced by `FlowController`. Before them the flow
endpoints were `@NoAdminRequired` and scoped only by organisation, so any member
could do all four and no admin could narrow it.

They MUST be seeded `@authenticated` — the explicit "any signed-in user" grant —
because that is exactly the access that already exists. A seed defaulting to
admin-only would lock out every non-admin flow author on upgrade: a breaking
change wearing a feature's clothes.

`@authenticated` MUST NOT become a default. An action with no entry MUST still
deny, or the matrix turns from fail-closed to fail-open on the strength of a
convenience.

Refusal MUST be a 403 response, never an escaping OCS exception: that surfaces
as a 500 from a plain `Controller`, and a right that reads as a server fault is
one nobody can act on.

#### Scenario: The shipped seed does not lock out existing authors
- **GIVEN** an instance upgrading to the release that adds these rights
- **WHEN** a non-admin member of an organisation creates a flow
- **THEN** it MUST succeed exactly as before
- @e2e exclude covered by `ActionAuthEveryoneTest`, which asserts against the
  shipped seed file itself

#### Scenario: An unlisted action still denies
- **GIVEN** an action with no entry in the matrix
- **WHEN** a non-admin invokes it
- **THEN** it MUST be refused
- @e2e exclude covered by `ActionAuthEveryoneTest`, with a positive control that
  makes absence mean open and turns the assertion red

#### Scenario: A narrowed right refuses the write itself
- **GIVEN** `flow.create` narrowed to a group the caller is not in
- **WHEN** they call the create endpoint
- **THEN** it MUST answer 403 and the flow service MUST NOT be reached
- @e2e exclude covered by `FlowControllerTest`

### Requirement: A flow MUST have a trigger and an end

Every flow MUST carry at least one TRIGGER node and at least one END node.
Without a trigger nothing can ever start it, and the flow sits fully authored
and never runs with no run record to say why. Without an end node no path
finishes deliberately, so every path stops somewhere the author did not mark
while the run is still reported completed.

Both MUST be decided by the node's TYPE, never by its position in the graph.

A flow may end in SUCCESS or in ERROR — `openregister.end` carries an `error`
flag — and both are deliberate ends. Failing is an outcome, not the absence of
one.

`exit: true` MUST NOT satisfy this. The flag is a per-instance escape that ends
one PATH for a migrated document; it is not a node saying the flow finishes
here.

Both MUST be WARNINGS rather than blocking, for the same reason a dead end is: a
flow mid-authoring is legitimately missing both, and refusing the save would
force the author to build in an order where the document is never incomplete.
The editor MUST surface them as an error banner.

#### Scenario: A flow with no trigger is reported
- **GIVEN** a document with a step and an end node but no trigger node
- **WHEN** it is inspected
- **THEN** a `flow-has-no-trigger` warning MUST be reported
- **AND** `blocking` MUST remain empty
- @e2e exclude engine-internal check — covered by `FlowEntryAndExitTest`

#### Scenario: Graph position is not a role
- **GIVEN** two ordinary steps wired `a → b`, so `a` has nothing pointing at it
  and `b` has no outgoing edge
- **WHEN** the document is inspected
- **THEN** BOTH `flow-has-no-trigger` and `flow-has-no-end` MUST be reported,
  because neither node's TYPE says it can start or end a run
- @e2e exclude engine-internal check — covered by `FlowEntryAndExitTest`, with a
  positive control that reads the graph shape instead and turns it red

#### Scenario: An end that fails still counts
- **GIVEN** a flow whose only end node carries `config.error: true`
- **WHEN** it is inspected
- **THEN** no `flow-has-no-end` warning MUST be reported
- @e2e exclude engine-internal check — covered by `FlowEntryAndExitTest`

### Requirement: Saving a half-wired flow MUST succeed and warn; running one MUST be refused @e2e exclude API contract — covered by Newman and by FlowDeadEndTest

`POST` and `PUT /api/flows` MUST store the flow and return the stored document with a `warnings` array alongside its own fields. Saving MUST NOT be refused for a dead end: a disconnected graph is the normal state of one being authored, and refusing it would require the author to build the graph in an order that is never disconnected.

Running MUST be refused. The guard MUST sit in `FlowRunService::queue()`, which every dispatch path passes through — manual, trigger, schedule, MCP, the workflow-engine operation and a sub-flow call — because a guard on the manual path alone would leave cron-fired flows unguarded. On refusal NO `FlowRun` MUST be created, the flow's `status` MUST become `error` and `status_message` MUST name the offending nodes. When a run IS accepted, a previous `error` status MUST be cleared back to `ok`.

The schedule sweep MUST catch the refusal PER FLOW, so one unrunnable flow does not stop later due flows from firing.

#### Scenario: A dead-ended flow is stored, then refused at run time
- **GIVEN** a flow whose node `forgotten` is a dead end
- **WHEN** it is saved
- **THEN** the save MUST succeed and the response MUST carry a `node-dead-end` warning naming `forgotten`
- **WHEN** it is then run, by any dispatch path
- **THEN** no `FlowRun` MUST be created
- **AND** the flow's `status` MUST be `error` with `status_message` naming `forgotten`

#### Scenario: One unrunnable flow does not disable the schedule
- **GIVEN** two due scheduled flows, the first of which is dead-ended
- **WHEN** the sweep runs
- **THEN** the first MUST be skipped with its refusal recorded on the flow
- **AND** the second MUST still fire

### Requirement: A flow MUST record its last run @e2e exclude persistence contract — covered by unit tests and Newman

`Flow` MUST carry `status`, `statusMessage`, `lastRunUuid`, `lastRunStatus`, `lastRunMessage` and `lastRunAt`, all nullable, exposed in camelCase by `jsonSerialize()`. `FlowRunService` MUST write the last-run fields when a run reaches a TERMINAL state (`completed`, `stopped`, `failed`, `dead_letter`) — not per step, and not on queue.

A null `lastRunAt` MUST mean "has never run". The migration adding these columns MUST NOT backfill them, because a value derived from run history would assert a history the column did not record.

#### Scenario: A queued run does not overwrite the last run
- **GIVEN** a flow whose last run completed yesterday
- **WHEN** a new run is queued and is still `queued`
- **THEN** `lastRunAt` MUST still be yesterday's timestamp

#### Scenario: A refused flow is distinguishable from one that never ran
- **GIVEN** a flow refused for a dead end, which therefore has no `FlowRun` at all
- **WHEN** the flow is read
- **THEN** `status` MUST be `error` with a message naming the nodes
- **AND** `lastRunAt` MUST be null, and the two MUST be distinguishable without reading run history

### Requirement: A node declares whether it triggers or ends a path

One concept, one word: a node's role is `trigger`, `step` or `end`. That
vocabulary MUST be the same in the node ids, in the engine's code, in the
palette API and in the editor's badges.

A type declares its role by implementing `IFlowTriggerNode` or `IFlowEndNode` —
marker interfaces, because the role is a property of the TYPE and there is
nothing to pass and nothing to answer. Neither is a `step`. `FlowNodeRegistry`
resolves both and MUST ship the resulting `role` on every palette entry.

A node's role MUST come from its TYPE and never from its position in the graph.
"Nothing points at this node" and "this node has no outgoing edge" are facts
about one drawing, and reading them as roles calls an unconnected step a
trigger.

No consumer may INFER the role from the node's id. A convention like
`id.includes('.trigger-')` mis-labels every node another app contributes under
a name that does not fit the pattern — which is the hardcoded list
`FlowNodeRegistry::isEnd()` exists to avoid, reintroduced one layer up. The
engine knows; it says so.

The word "terminal" is deliberately NOT used for a node. It remains the word for
a RUN that has reached a final status (`FlowRun::isTerminal()`,
`SyncRecordStatus::isTerminal()`), and one word cannot carry both questions.

#### Scenario: A contributed node's role comes from what it declares
- **GIVEN** a node from another app whose id matches no naming convention, which
  implements `IFlowStartNode`
- **WHEN** the palette is built
- **THEN** its entry MUST report `role: "trigger"`
- @e2e exclude engine-internal registry behaviour — covered by
  `FlowNodeRegistryTest`, with a positive control that reverts to inferring the
  role from the id and turns the assertion red

#### Scenario: Every palette entry carries a role
- **GIVEN** any registered node type
- **WHEN** the palette is built
- **THEN** `role` MUST be present and MUST be one of `trigger`, `step`, `end`
- **AND** a type that declares neither marker MUST be `step`
- @e2e exclude engine-internal registry behaviour — covered by `FlowNodeRegistryTest`

#### Scenario: An unregistered type is a step, not a guess
- **GIVEN** a type no app has registered
- **WHEN** its role is asked for
- **THEN** it MUST be `step`, because its own preflight finding already reports
  that it is unknown
- @e2e exclude engine-internal registry behaviour — covered by `FlowNodeRegistryTest`

### Requirement: A TRIGGER is a node, and a flow may carry several

What starts a flow is a node on the graph, not a property of the flow row. The
engine MUST offer three trigger node types — `openregister.trigger-object`,
`openregister.trigger-schedule`, `openregister.trigger-manual` — registered
through the same `RegisterFlowNodesEvent` as every other node, so the palette
offers them and the preflight checks their config.

A trigger is an ENTRY POINT, not work. `execute()` MUST pass its items through
unchanged: by the time a run exists the trigger has already fired. It MUST NOT
re-check its own subject — the resolver decided this flow wanted this event
before the run was queued, and a second copy of that rule is one that can
drift, dropping legitimate runs or admitting rejected ones.

A flow MAY carry several trigger nodes, and each is an independent entry point.
This is the capability the flow row could not express: `trigger`,
`triggerRegister`, `triggerSchema` and `cron` are four columns holding exactly
one trigger between them, so "on a schedule AND when an object changes" had no
representation and was worked around by duplicating the flow.

#### Scenario: An object trigger names exactly one event, register and schema
- **GIVEN** a node of type `openregister.trigger-object`
- **WHEN** its config is validated
- **THEN** `event`, `register` and `schema` MUST each be present and non-empty
- **AND** `event` MUST be one of `object.created`, `object.updated`, `object.deleted`
- @e2e exclude engine-internal config validation — covered by the node's unit tests

#### Scenario: A trigger with no subject is refused rather than defaulted
- **GIVEN** an object trigger whose `schema` is missing
- **WHEN** its config is validated
- **THEN** it MUST throw, naming the missing key
- **AND** it MUST NOT default: a trigger with no subject either matches nothing
  and never fires, or matches everything and fires on every object — and both
  are silent
- @e2e exclude engine-internal config validation — covered by the node's unit tests

#### Scenario: Two schemas require two triggers, not one wider trigger
- **GIVEN** a flow that must react to objects of two different schemas
- **WHEN** it is authored
- **THEN** it MUST carry one trigger node per schema
- **AND** differing JSON shapes MUST be normalised by a mapping node downstream,
  because a single trigger admitting both would hand the next node two shapes
  under one name and it would read the fields the two happen to share
- @e2e exclude an authoring constraint with no engine-side assertion — covered by
  the canvas tests

#### Scenario: A schedule trigger carries a five-field cron expression
- **GIVEN** a node of type `openregister.trigger-schedule`
- **WHEN** its config is validated
- **THEN** `cron` MUST be present and MUST have five space-separated fields
- **AND** the semantics are NOT checked — whether an expression ever matches a
  real minute is the scheduler's question, not the node's
- @e2e exclude engine-internal config validation — covered by the node's unit tests

#### Scenario: A manual trigger says out loud that a person starts the flow
- **GIVEN** a node of type `openregister.trigger-manual`
- **WHEN** its config is validated
- **THEN** it MUST accept no configuration keys at all
- **AND** it MUST remain distinguishable from a flow with NO trigger node, which
  is an unfinished flow rather than a deliberate on-demand one
- @e2e exclude engine-internal config validation — covered by the node's unit tests

### Requirement: The cutover from trigger COLUMNS to trigger NODES MUST be proven per flow

The four columns remain AUTHORITATIVE for matching until every flow's node set
is shown to fire on the same events. `FlowTriggerDerivation` is the single place
that maps between the two, and `compare()` answers the question per flow —
never in aggregate, because "most flows agree" is not a basis for changing which
flows run.

A divergence MUST be reported in the direction it breaks: a column trigger with
no node means the flow WOULD STOP firing; a node trigger with no column means
the flow does NOT fire today and the cutover would START it. Both are behaviour
changes an operator has to be told about, and a single "not equivalent" hides
which one happened.

**Measured on the development instance, 2026-08-10.** All 16 flows carry ZERO
trigger nodes — every flow is authored purely on the columns. Switching the
resolver to nodes ALONE would therefore have stopped all 16, and 4 of the 5
object-triggered flows cannot be represented as nodes at all: they are scoped to
any register and any schema, which `openregister.trigger-object` deliberately
refuses to express.

**The cutover ships as nodes-first WITH a column fallback.** A flow that has any
row in the derived index is matched by its NODES alone; a flow with no rows keeps
matching through its columns. This is what makes the change safe to ship without
re-authoring anything: the backfill converted 0 flows on the development instance
and the resolver's output was byte-identical before and after.

The fallback is the DEPRECATION SURFACE, not a permanent second path. Of the 16,
12 convert by simply being re-authored with a trigger node (8 `manual`,
3 `schedule`, 1 scoped `object.created`); only the 4 unscoped object triggers are
genuinely blocked, and each needs one trigger node per register/schema pair.
Widening the node to accept a wildcard would contradict the scenario above.

#### Scenario: An unconverted flow keeps firing through its columns

- **GIVEN** a flow with no rows in the derived trigger index
- **WHEN** an object event fires that its trigger columns match
- **THEN** the flow MUST still be started
- **AND** this MUST hold when the index is UNREADABLE — during the upgrade that
  creates it, every flow falls back, because answering "no flow was interested"
  there would silence the whole engine and look exactly like a quiet afternoon
- @e2e exclude engine-internal resolution — covered by `FlowLocatorTriggerCutoverTest`

#### Scenario: A converted flow does NOT fire from its stale columns

- **GIVEN** a flow that HAS rows in the index, whose trigger column still names
  an event its nodes no longer declare
- **WHEN** that event fires
- **THEN** the flow MUST NOT be started
- **AND** the reason is that deleting a trigger node has to actually unsubscribe
  the flow — consulting the columns for a converted flow would let a removed node
  keep firing through a column nobody edits
- @e2e exclude engine-internal resolution — covered by `FlowLocatorTriggerCutoverTest`

#### Scenario: A flow matched by both sources is started once

- **GIVEN** a flow matched by both its index rows and its trigger columns
- **WHEN** the event fires
- **THEN** it MUST be started exactly once
- @e2e exclude engine-internal resolution — covered by `FlowLocatorTriggerCutoverTest`

#### Scenario: The backfill distinguishes "not yet re-authored" from "cannot be expressed"

- **GIVEN** flows whose columns name `manual`, `schedule` and an unscoped
  `object.updated`
- **WHEN** the backfill reports what it could not convert
- **THEN** the manual and schedule flows MUST be reported as merely awaiting
  re-authoring, and only the unscoped OBJECT trigger as blocked
- **AND** the reason is that `register` and `schema` are meaningless for a manual
  or schedule trigger, so reporting them as "unscoped" would name 15 flows as
  blocked when 11 of them convert by adding one node
- @e2e exclude engine-internal reporting — covered by the backfill's own output

#### Scenario: An unscoped column trigger is never reported as reproducible by a node

- **GIVEN** a flow whose `triggerRegister` and `triggerSchema` are empty (any/any)
- **WHEN** it is compared against a node naming the same event
- **THEN** the verdict MUST be NOT equivalent, naming the unscoped trigger
- **AND** the reason is that the two do not match the same set of events: the
  column form fires on registers the node form does not
- @e2e exclude an engine-internal comparison — covered by `FlowTriggerDerivationTest`

#### Scenario: A half-authored trigger node subscribes to nothing, never to everything

- **GIVEN** an object trigger node missing its `register`
- **WHEN** the flow's trigger set is derived
- **THEN** that node MUST contribute NO trigger
- **AND** it MUST NOT be widened to "any register", which would subscribe the
  flow to every object event in the instance
- @e2e exclude an engine-internal derivation — covered by `FlowTriggerDerivationTest`

#### Scenario: The same trigger authored twice is one subscription

- **GIVEN** a flow carrying two identical object trigger nodes
- **WHEN** its trigger set is derived
- **THEN** exactly one trigger MUST result, so one event starts one run
- @e2e exclude an engine-internal derivation — covered by `FlowTriggerDerivationTest`

### Requirement: Trigger matching MUST NOT scale with the number of flows

An object event fires inside the dispatch of a user action — a save, an
upload, a delete. Whatever the engine does to decide which flows want that
event is therefore paid by the person who performed the action, on every
action, forever.

Resolving flows for an event MUST NOT open flow documents. It MUST match on an
indexed projection of the trigger nodes — the exact `(event, register, schema)`
triple each object trigger declares — so the cost is one indexed lookup
regardless of how many flows exist or how many nodes each contains.

The single-subject rule on `openregister.trigger-object` is what makes this an
exact-match lookup rather than a set intersection. That is a consequence of the
rule and not its justification: the reason for one subject per trigger is that
objects of different schemas carry different JSON.

A flow whose triggers cannot be projected MUST be reported, not silently
skipped: a trigger that never matches is indistinguishable from a flow with
nothing to do.

**Measured 2026-08-10, on the column-based path that is still authoritative.**
`FlowMapper::findByTrigger()` is an INDEX SCAN on `or_flow_trigger_idx
(enabled, trigger)` — it does not table-scan, and it does not parse any flow's
nodes to decide a match. That half of the requirement already holds.

What it does do is `SELECT *`, so every candidate row arrives carrying its
`nodes` and `edges` JSON: row width 2418 against 51 for `id, uuid, owner`, and
the documents themselves average 3,068 bytes with a worst case of 14,232 on
this instance. The resolver needs the id, the uuid and the owner — it reads the
document for nothing.

At sixteen flows that is 0.08 ms and invisible. It is recorded because the cost
is per MATCHING flow and is paid inside the dispatch of every user's save, so
it grows with exactly the thing this requirement exists to bound. The fix is a
narrow projection in the mapper, not a cache.

#### Scenario: Firing an event does not read flow documents
- **GIVEN** an instance with many flows, each carrying several trigger nodes
- **WHEN** an object event fires
- **THEN** the resolver MUST answer from the indexed projection
- **AND** it MUST NOT load flow documents to inspect their nodes
- @e2e exclude a performance invariant measured by a query-count assertion in the
  trigger tests, not through a browser

### Requirement: The engine preserves graph annotations and never executes them

A flow document MAY carry `annotations[]` — free-placed notes an author pins to
the canvas, each with its own position and text, belonging to no node and no
edge. They are the third element of the document, alongside `nodes[]` and
`edges[]`.

The engine MUST ignore them when building a definition and MUST preserve them
across a save. `FlowDefinitionBuilder` reads `nodes` and `edges` by key and
does not enumerate the document, so ignoring is already the behaviour;
preserving is the part that needs a store that keeps them.

They MUST NOT be called `notes` at the document root. `Flow` already has a
`notes` column of type STRING — the flow's own prose — and an array under the
same name would either overwrite it or be silently coerced. A note about the
whole flow and a note pinned at a point on the canvas are different things and
must not share a name.

An annotation MUST NOT be expressible as a node. A node is lowered to a
transition and becomes something the run moves through; an annotation that
arrived as a node would be built, marked, and waited on, which is how a comment
would come to deadlock a flow.

#### Scenario: Annotations survive a round-trip through the editor
- **GIVEN** a flow carrying two annotations
- **WHEN** it is loaded, edited and saved
- **THEN** both annotations MUST come back with their text and positions intact
- @e2e exclude a persistence invariant — covered by the flow store's tests

#### Scenario: An annotation contributes nothing to the definition
- **GIVEN** a flow with one node and one annotation
- **WHEN** the definition is built
- **THEN** it MUST contain exactly the places and transitions the node implies
- **AND** the annotation MUST contribute no place, no transition and no marking
- @e2e exclude engine-internal lowering — covered by FlowDefinitionBuilder tests

### Requirement: A node type declares its own form, and its own run-log actions

A node's behaviour is contributed by an app; so is everything an operator needs
in order to configure it and to understand what it did. The engine MUST let a
node type declare both, through optional interfaces alongside `IFlowNode`:

- **A config form.** A declarative field list — key, label, type, help, and
  where a value comes from (a literal, or a lookup the providing app resolves).
  A type that declares none keeps the raw-JSON pane, which is the honest
  fallback rather than a typed pane over guessed keys.
- **Run-log actions.** Given one log entry, the links that entry earns: for an
  openconnector call node, the contract or the source it used; for an agent
  node, the session it created. Each is a label, an href and an icon.

The engine MUST NOT hard-code any app's fields or links. The reason is the one
the catalogue already proves: `RegisterFlowNodesEvent` exists so an app can add
a node without OpenRegister knowing about it, and a form registry that only
OpenRegister could extend would put the form of every contributed node back
into the engine.

A form declaration MUST be data, not markup. A node that shipped a component
would tie the engine's rendering to that app's build, and a canvas rendered in
one app would be asked to mount a component from another.

Actions MUST be derived from a log entry rather than stored on it. An href
frozen into a log at write time is a link that rots when the target moves, and
a run log is kept for months.

#### Scenario: A node type with no form declaration still configures
- **GIVEN** a node type that declares no config form
- **WHEN** an operator edits a node of that type
- **THEN** the raw-JSON configuration pane MUST be offered
- @e2e exclude covered by the node editor's component tests

#### Scenario: A contributed node's form comes from its own app
- **GIVEN** an app contributing a node type that declares a form
- **WHEN** the editor renders that node's configuration
- **THEN** the fields MUST come from the declaration, and the engine MUST NOT
  carry any knowledge of that app's keys
- @e2e exclude covered by the registry tests

#### Scenario: A log entry offers the links its node earns
- **GIVEN** a run log entry written by an openconnector call node
- **WHEN** the entry is displayed
- **THEN** the actions that node type declares MUST be offered against it
- **AND** each action's href MUST be resolved at display time, not read from
  the stored entry
- @e2e exclude covered by the run-log component tests

### Requirement: A run records what each node received, returned and logged

Every node execution MUST record its input items, its output items, and its own
log lines, against the run and the node that produced them.

This is what makes a run inspectable rather than merely scored. A run that
records only a status per step answers "did it work" and nothing else; the
question actually asked of a failed run is "what did it get, and what did it do
with it".

Agent nodes MUST log to the same shape as every other node. An agent's reasoning
is longer and less structured, which is the argument for a defined shape rather
than against one: a free-form dump is the format that cannot be read next to
anything else.

An agent node MUST record the identifier of the session it created, so the run
log can offer a link to it. It MUST NOT copy the session's content into the log
— the session is the record, and a copy diverges from it.

Recording MUST be bounded. A node that returns ten thousand items MUST NOT put
ten thousand items in a log; the record MUST carry a bounded sample and the true
count, and MUST say that it is a sample. An unbounded log is one that fills a
disk and is then deleted wholesale, taking the runs that mattered with it.

#### Scenario: A failed step says what it received
- **GIVEN** a run in which one node failed
- **WHEN** its log entry is inspected
- **THEN** the input it received, the output it produced (if any) and its log
  lines MUST all be available
- @e2e exclude covered by the engine's run-log tests

#### Scenario: A large item list is sampled, and says so
- **GIVEN** a node that returns more items than the record's bound
- **WHEN** its entry is written
- **THEN** the entry MUST carry a bounded sample, the true count, and a marker
  that it is a sample
- @e2e exclude covered by the engine's run-log tests

#### Scenario: An agent node points at its session rather than copying it
- **GIVEN** an agent node that created a session
- **WHEN** its log entry is written
- **THEN** the entry MUST carry the session identifier
- **AND** it MUST NOT contain a copy of the session's messages
- @e2e exclude covered by the agent node's tests

### Requirement: A node that calls out per item MUST be able to do so concurrently

The engine dispatches a node once with the whole item list, and a node that
performs one outbound call per item currently loops. For N items that is N
sequential round-trips, and the wall-clock is N times one call's latency
regardless of how idle the machine is.

**Measured 2026-08-10.** `SourceCallNode::execute()` is a `foreach` over items
calling `performCall()` in turn. openconnector's own synchronisation reader —
the code a flow-based openconnector replaces — does the opposite:
`Each::ofLimit()` with an admission gate enforcing a concurrency cap
(`FETCH_CONCURRENCY_DEFAULT` 5, `FETCH_CONCURRENCY_MAX` 20) AND an in-flight
byte budget, per-item failure isolation, and saves pipelined behind the fetch
window. The difference is structural, not marginal: N serial round-trips
against ceil(N/5) waves. A flow-based reader that keeps the loop is slower than
the thing it replaces, by a factor that grows with the list.

The engine MUST therefore let a node declare that its per-item work is
concurrency-safe, and run it within a bound. The bound is not optional: an
unbounded fan-out turns one flow over a large list into a burst against an
upstream that did nothing to deserve it, which is why the reader being replaced
caps by COUNT and by BYTES — ten 5 MB attachments are trivial where ten 2 GB
exports are not.

Concurrency MUST NOT change what the run records. `pairedItem` already ties an
output back to the input that produced it, and the run log's `input`/`output`
sample MUST stay in input order — a log whose order depends on which call
returned first is not comparable between two runs of the same flow.

One item's failure MUST NOT abort the others, and MUST still reach the step's
`onError` policy. Losing per-item isolation to gain speed would trade a
correctness property the serial loop has for a performance one.

#### Scenario: A per-item caller runs within a bound, not one at a time
- **GIVEN** a node declaring its per-item work concurrency-safe, and 20 items
- **WHEN** the step runs with a cap of 5
- **THEN** at most 5 calls MUST be in flight at once
- **AND** the wall-clock MUST be closer to 4 waves than to 20 round-trips
- @e2e exclude a timing property — covered by the dispatcher's tests with a
  stubbed transport

#### Scenario: Concurrency does not reorder the record
- **GIVEN** a concurrent step whose calls return out of order
- **WHEN** the run log is written
- **THEN** the recorded items MUST be in INPUT order
- **AND** each output's `pairedItem` MUST name the input that produced it
- @e2e exclude covered by the dispatcher's tests

#### Scenario: One item's failure does not take the others
- **GIVEN** a concurrent step where one of five calls fails
- **WHEN** the step completes
- **THEN** the other four results MUST be present
- **AND** the failure MUST reach the step's `onError` policy exactly as it does
  from the serial loop
- @e2e exclude covered by the dispatcher's tests

### Requirement: A node MUST be able to resume from where it stopped

`FlowSuspension` lets a node pause a run, and the engine already preserves what
the RUN was carrying: the marking is not advanced, items and context are stored,
and `FlowToken` is rehydrated on the way back in. What a resumed node learned
about ITSELF was one boolean, `context.resuming`.

That is sufficient for `WaitNode`, whose wait is over by construction, and
insufficient for every other reason to pause. A synchronisation parked on a rate
limit must come back to its page and its shard; a step awaiting an answer must
come back knowing which question it asked. Without somewhere to record that, such
a node restarts — and a resume that restarts is a retry wearing the wrong name.

**Measured 2026-08-13.** A twelve-shard crawl whose early shards spent the whole
`code_search` budget cancelled the rest. Three consecutive runs, 65 s apart, each
returned the same 641 repositories: the starved shards were never reached because
every run began again at the first one.

The engine MUST therefore carry per-node resume state across a suspension, and it
MUST be scoped per node: a flow may hold two nodes of the same type, and one flat
bag would have them overwrite each other's position — a defect that appears only
on the second node.

A node MUST NOT be able to read or write another node's slot. `context.resuming`
answers a question about the RUN and is true for every node downstream of a
suspension; whether THIS node has somewhere to continue from is the thing worth
branching on, and MUST be answerable separately.

Resume state MUST live from a suspension to the resume that answers it, and no
longer. A node that returns normally has finished, so its slot MUST be dropped —
otherwise the next pass through that node, inside a loop or on a later scheduled
tick, is handed a finished node's cursor.

#### Scenario: A node continues from where it stopped
- **GIVEN** a node that records its page and then suspends
- **WHEN** the run resumes and the node runs again
- **THEN** the node MUST read back the page it recorded
- @e2e exclude engine-internal — covered by RegistryStepDispatcherResumeTest

#### Scenario: Two nodes do not share a position
- **GIVEN** two nodes in one flow, each recording a page under the same key
- **WHEN** both have suspended
- **THEN** each MUST read back its OWN page
- @e2e exclude engine-internal — covered by FlowResumeStateTest

#### Scenario: A finished node keeps nothing
- **GIVEN** a node that records a position and then returns normally
- **WHEN** the step completes
- **THEN** its resume slot MUST be empty
- @e2e exclude engine-internal — covered by RegistryStepDispatcherResumeTest

### Requirement: A run suspended on an external signal MUST be reachable

`FlowSuspension` has always documented a null `resumeAt` as "waits for an
external signal". Nothing could deliver one. `FlowRunMapper::findDue()`
deliberately excludes runs with no `resume_at` — correctly, since waking them on
a clock would run them before their answer arrived — `findStale()` reads only
`running`, and no endpoint existed to say the signal had come.

The consequence was worse than an unusable feature. `hasActiveRun()` counts
`suspended`, so a run waiting on a signal it could never receive also stopped its
flow from ever being scheduled again. The documented case silently retired the
flow that used it.

The engine MUST provide a way to deliver that signal, guarded by the same
ownership check as retry — resuming another user's run is the same IDOR as
re-running it. The payload MUST reach the node that suspended, and MUST be
consumed by the walk it wakes: a signal answers ONE question, and a flow with two
awaiting steps MUST NOT have the second read the answer given to the first.

A node that suspends waiting on a signal MUST ALSO carry a heartbeat `resumeAt`.
A delivery can fail, and can arrive while the run is still mid-walk and has not
suspended yet; either loses the only wake-up the run would ever get. The
heartbeat costs a no-op wake per interval and bounds the damage of a lost signal
to that interval instead of to the flow.

A signal that never arrives MUST eventually be reaped rather than left forever,
and MUST be FAILED rather than resumed: resuming would run the awaiting node as
though it had been answered, when what happened is that nobody answered.

#### Scenario: An answer wakes the run
- **GIVEN** a run suspended awaiting an answer
- **WHEN** the resume endpoint is posted a decision
- **THEN** the run MUST become due, and the awaiting node MUST receive the decision

#### Scenario: A nudge is not an answer
- **GIVEN** a run suspended awaiting an answer
- **WHEN** the resume endpoint is posted with no decision
- **THEN** the run MUST remain suspended
- @e2e exclude node-internal — covered by AwaitSignalNodeTest

#### Scenario: An answer is consumed once
- **GIVEN** a flow with two awaiting steps, the first already answered
- **WHEN** the second suspends
- **THEN** it MUST NOT read the first step's answer
- @e2e exclude engine-internal — covered by FlowRunService's persist path

#### Scenario: A signal that never comes does not retire the flow
- **GIVEN** a run suspended on a signal past the configured wait
- **WHEN** the worker passes
- **THEN** the run MUST be failed with a reason naming the missing signal
- **AND** its flow MUST become schedulable again

### Requirement: A flow may declare the virtual-app it belongs to, and be listed by it

A flow MAY carry an `applicationSlug`, identifying the OpenBuild virtual-app
it belongs to. It is optional and defaults to absent: a flow with no
`applicationSlug` MUST remain a fully valid, ordinary flow, and existing
flows MUST NOT be backfilled with one as part of adding the field.

`applicationSlug` is independent of `app`. `app` is the owning Nextcloud app
(e.g. `hermiq`); `applicationSlug` is narrower — one Nextcloud app may host
several virtual apps, each with its own flows, and `app=hermiq` alone
cannot distinguish between them.

`GET /apps/openregister/api/flows` MUST accept an optional
`applicationSlug` query parameter. When supplied, the result MUST be
restricted to flows whose stored `applicationSlug` equals it exactly; when
omitted or empty, the endpoint MUST behave exactly as it does today —
every flow visible to the caller, regardless of `applicationSlug`. The two
filters compose: passing both `app` and `applicationSlug` MUST narrow by
both.

`applicationSlug` MUST be a client-editable field on create and update,
alongside the other descriptive string fields (e.g. `name`, `description`).
It MUST NOT be treated as a stamped/server-owned field the way `owner` and
`organisation` are: any caller permitted to create or update a flow may set
or clear it.

#### Scenario: A flow with no applicationSlug is listed and served unchanged

- **GIVEN** a flow that has never had `applicationSlug` set
- **WHEN** it is read via `GET /apps/openregister/api/flows/{id}` or listed
  via `GET /apps/openregister/api/flows` with no `applicationSlug` filter
- **THEN** it is returned exactly as before, with `applicationSlug: null`

#### Scenario: Filtering narrows to exactly the matching virtual-app's flows

- **GIVEN** flows with `applicationSlug` values `"hydra"`, `"other-app"`,
  and one flow with no `applicationSlug` at all
- **WHEN** `GET /apps/openregister/api/flows?applicationSlug=hydra` is called
- **THEN** only the flow(s) with `applicationSlug: "hydra"` are returned

#### Scenario: An absent or empty filter returns every flow, matching current behaviour

- **GIVEN** a mix of flows, some with `applicationSlug` set and some without
- **WHEN** `GET /apps/openregister/api/flows` is called with no
  `applicationSlug` parameter
- **THEN** every flow visible to the caller is returned, unaffected by
  whether it carries an `applicationSlug`

#### Scenario: The app and applicationSlug filters compose

- **GIVEN** flows across several Nextcloud apps, some sharing the same
  `applicationSlug`
- **WHEN** `GET /apps/openregister/api/flows?app=hermiq&applicationSlug=hydra`
  is called
- **THEN** only flows with `app: "hermiq"` AND `applicationSlug: "hydra"`
  are returned

#### Scenario: A partial update that omits applicationSlug leaves it unchanged

- **GIVEN** a stored flow with `applicationSlug: "hydra"`
- **WHEN** it is updated with a payload that omits the `applicationSlug` key
- **THEN** the stored `applicationSlug` remains `"hydra"`

#### Scenario: An explicit null clears applicationSlug

- **GIVEN** a stored flow with `applicationSlug: "hydra"`
- **WHEN** it is updated with `applicationSlug: null`
- **THEN** the stored `applicationSlug` becomes null
