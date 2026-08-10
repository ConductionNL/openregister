---
status: done
---

# flow-engine Specification

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

### Requirement: A path MUST end deliberately, and a dead end MUST be reported @e2e exclude engine-internal connectivity check — covered by FlowDeadEndTest

A node with no outgoing edge is a dead end unless it ends the path deliberately. It ends deliberately if EITHER its type is registered terminal (the type implements `IFlowTerminalNode`, resolved through `FlowNodeRegistry::isTerminal()`) OR the node itself declares `exit: true`. The two MUST be OR-ed, never AND-ed, so a migrated flow whose sink carries an ordinary action type is not refused, and a contributed terminal type needs no OpenRegister change.

`FlowNodePreflight` MUST report every dead end as a WARNING carrying `reason: node-dead-end` and naming the node. It MUST NOT report a node whose `type` is empty — that node is already refused by name in `FlowDefinitionBuilder`, and two findings on one node for one defect is how a warning list becomes noise.

#### Scenario: A forgotten sink is named
- **GIVEN** a document whose node `forgotten` carries an ordinary step type, has no outgoing edge and no `exit` flag
- **WHEN** the document is inspected
- **THEN** the report MUST contain a `node-dead-end` warning naming `forgotten`
- **AND** `blocking` MUST remain empty, because a dead end never blocks a save

#### Scenario: A wired graph reports nothing
- **GIVEN** the same document with `forgotten` connected onward to a terminal node
- **WHEN** the document is inspected
- **THEN** it MUST produce no `node-dead-end` warning

#### Scenario: `exit: true` ends a path whose type is not terminal
- **GIVEN** a single node carrying `type: openregister.set-fields` and `exit: true`, with no edges
- **WHEN** the document is inspected
- **THEN** it MUST produce no `node-dead-end` warning

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
