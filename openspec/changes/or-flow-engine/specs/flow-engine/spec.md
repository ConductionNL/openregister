## ADDED Requirements

### Requirement: OpenRegister owns the fleet's flow engine (REQ-FE-001)

OpenRegister SHALL provide the only flow-execution engine in the fleet. Leaf
apps (openconnector, procest, openbuild, and any future consumer) SHALL consume
it rather than implement their own (ADR-022, ADR-065). A leaf app that adds a
flow/workflow execution engine is an ADR-022 violation.

The engine SHALL be usable without a Nextcloud container: side effects are
performed by a caller-supplied `FlowStepDispatcher`, so the engine can be
unit-tested in isolation and a consuming app can contribute step types without
an engine change.

#### Scenario: A consuming app supplies its own step behaviour

- **GIVEN** a consumer implementing `FlowStepDispatcher`
- **WHEN** `FlowEngine::run()` reaches a step
- **THEN** the engine calls `dispatch()` with that step's own edge
  configuration (including `type` and `configRef`) plus the step's input items,
  and threads the returned items on to the next step
- **AND** the engine itself makes no assumption about what the step does

### Requirement: The execution core is a Petri net (REQ-FE-002)

The engine SHALL execute flows via `symfony/workflow`. `FlowDefinitionBuilder`
SHALL translate a stored flow document into a `Definition`, mapping each flow
node to a place and each flow edge to a transition.

A Petri net is required — not merely convenient — because it is a superset of
both models the fleet already has: a single-token marking is a state machine
(procest's `case.status`), and a multi-token marking expresses parallel splits
and synchronising joins, which no existing fleet engine can represent.

#### Scenario: An edge to several nodes is a parallel split

- **GIVEN** a flow with an edge `{ from: 'start', to: ['a', 'b'] }`
- **WHEN** that transition fires
- **THEN** both `a` and `b` hold a token, and both branches run

#### Scenario: An edge from several nodes is a synchronising join

- **GIVEN** a flow with an edge `{ from: ['a_done', 'b_done'], to: 'done' }`
- **WHEN** only `a_done` holds a token
- **THEN** the join is NOT enabled and does not fire
- **AND** once `b_done` also holds a token, the join fires exactly once

#### Scenario: A join that can never be satisfied does not fire

- **GIVEN** a join whose second input place can never receive a token
- **WHEN** the run reaches the join
- **THEN** the run ends without firing it, rather than firing a
  half-satisfied join

### Requirement: A flow document is validated before it runs (REQ-FE-003)

A stored flow document is user data and SHALL be treated as untrusted. The
builder SHALL reject a document that does not describe a runnable graph, naming
the offending element, rather than allowing `symfony/workflow` to fail later
and less legibly.

The builder SHALL reject: a document with no nodes; a node with no id; a
duplicate node id (which would silently merge two nodes into one place, running
a graph the author did not draw); an edge missing `from` or `to`; an edge whose
endpoint does not resolve to a declared node; and an `initial` naming an
unknown node.

Edges sharing a `name` SHALL NOT be merged — two edges named `approve` from
different nodes are two transitions, as drawn. Merging them would invent a join
the author never asked for.

#### Scenario: A dangling edge is rejected by name

- **GIVEN** a flow with an edge `{ id: 'ghost', from: 'a', to: 'nowhere' }`
  where no node `nowhere` is declared
- **WHEN** the definition is built
- **THEN** it fails with `Flow edge "ghost" references unknown node "nowhere".`

#### Scenario: A malformed flow fails loudly

- **GIVEN** a malformed flow document
- **WHEN** `FlowEngine::run()` is called
- **THEN** it returns `status: 'failed'` with the validation message in
  `error`
- **AND** it does NOT swallow the failure — unlike `x-openregister-flows`,
  whose `run()` deliberately catches to protect the save path, this engine's
  failures are the caller's to see

### Requirement: Both edge dialects are accepted (REQ-FE-004)

An edge SHALL accept `{from, to}` (the stored document dialect) and
`{source, target}` (the dialect `CnGraphCanvas` emits). A canvas payload is
therefore directly runnable without a translation layer in every consumer.

#### Scenario: A canvas-shaped edge builds

- **GIVEN** an edge `{ id: 'go', source: 'a', target: 'b' }`
- **WHEN** the definition is built
- **THEN** the transition's froms are `['a']` and tos are `['b']`

### Requirement: Initial places are explicit or inferred (REQ-FE-005)

The run SHALL start on the places named in `initial` when present. Otherwise
the engine SHALL infer the start as the graph's sources — the nodes no edge
points at — so simple flows need no boilerplate.

A fully cyclic graph has no source. The engine SHALL start it on the first
declared node rather than refuse it: a loop is legitimate to draw, and
declaration order is the only available signal.

#### Scenario: Sources are inferred

- **GIVEN** a flow `a -> b -> c` with no `initial`
- **WHEN** the definition is built
- **THEN** the initial places are `['a']`

### Requirement: The run lifecycle governs failures (REQ-FE-006)

The engine SHALL provide a run lifecycle, an append-only trace, and a per-step
error policy — none of which `symfony/workflow` supplies — ported from
openconnector's `FlowRunnerService`:

- A run SHALL end in exactly one of `completed`, `stopped`, `dead_letter`, or
  `failed`.
- Each step SHALL be recorded in an append-only run log with its transition
  name, status, and any error.
- Each step SHALL honour an `onError` policy of `stop`, `continue`, or
  `dead_letter`.
- `stop` SHALL be the default, and an **unrecognised** policy SHALL stop —
  a typo must fail safe rather than silently mean `continue`.
- Where `onError: continue`, the marking SHALL still advance past the failed
  step, otherwise the engine would retry that transition forever.

#### Scenario: An unknown error policy stops the run

- **GIVEN** a step with `onError: 'carry-on-regardless'`
- **WHEN** that step throws
- **THEN** the run ends `stopped`

#### Scenario: continue advances past a failed step

- **GIVEN** a two-step flow whose first step throws with `onError: continue`
- **WHEN** the flow runs
- **THEN** both steps are dispatched and the run ends `completed`
- **AND** the run log records the first step as `failed` with its error

### Requirement: The data channel between steps is an item list (REQ-FE-008)

The engine SHALL thread a LIST of items between steps, not a single object.
A step SHALL receive its input items and SHALL return its output items, so a
step that acts per record acts once per item, a step that filters returns
fewer items than it received, and a step that fans out returns more.

- An item SHALL carry `json` (the record), `binary` (attachments keyed by
  name), and `pairedItem` (which input item produced it).
- A run SHALL seed exactly one item from the subject when no seed is supplied,
  so a flow that never fans out behaves exactly like the single-object model.
- The engine SHALL normalise a dispatcher's return value, accepting a full item
  list, a single item, or a bare record. Rejecting the looser shapes would push
  identical boilerplate into every consuming app's dispatcher.
- An empty returned list SHALL be meaningful — it ends that branch's data — and
  SHALL NOT be treated as "no change".
- Run-level metadata SHALL travel in `context`, which is NOT the data channel.
  Records placed in `context` stop being per-record the moment a step fans out.
- Each run-log entry SHALL record the item count in and out for that step.

`pairedItem` is what makes a run explainable: given an item at the end of a
flow it is the chain back to the input that caused it. A fan-out with no
provenance leaves no way to answer "where did this come from", which is the
first question asked of any failed run.

#### Scenario: A step's output items become the next step's input

- **GIVEN** a two-step flow
- **WHEN** the first step returns items it has tagged
- **THEN** the second step receives exactly those items, not the run's seed

#### Scenario: A fan-out hands every item to the next step

- **GIVEN** a two-step flow whose first step returns three items
- **WHEN** the flow runs
- **THEN** the second step receives all three
- **AND** each output item's `pairedItem` still names the input item it came from

#### Scenario: A step returning no items ends that branch's data

- **GIVEN** a two-step flow whose first step returns an empty list
- **WHEN** the flow runs
- **THEN** the second step receives an empty item list
- **AND** the run's final items are empty

#### Scenario: A run with no seed starts from one item built from the subject

- **GIVEN** a run started without explicit items
- **WHEN** the first step is dispatched
- **THEN** it receives exactly one well-formed item

### Requirement: A run cannot loop forever (REQ-FE-007)

The engine SHALL abort a run that fires more than 1000 transitions and SHALL
report it as `failed` with an explanatory error. Because a Petri net can
express cycles, a user-drawn loop can otherwise run indefinitely; a silent
truncation would report success for a flow that never finished.

#### Scenario: An unbounded loop is aborted and reported

- **GIVEN** a flow `a -> b -> a`
- **WHEN** it runs
- **THEN** the run ends `failed` with an error mentioning an unbounded loop

@e2e exclude engine execution is backend-only — covered by PHPUnit, not browser UI; the authoring surface is covered by CnGraphCanvas and its consumers
