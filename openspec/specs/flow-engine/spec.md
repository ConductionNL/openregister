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
