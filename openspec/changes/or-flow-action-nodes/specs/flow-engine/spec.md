## MODIFIED Requirements

### Requirement: The execution core is a Petri net (REQ-FE-002)

The engine SHALL execute flows via `symfony/workflow`. `FlowDefinitionBuilder`
SHALL **lower** a stored flow document into a `Definition`.

A flow document is authored as **actions and sequence**: a node is an action and
carries the step's `type` and `config`; an edge is sequence and carries only
which action runs next, plus an optional display `title`/`note`. The Petri net
is the engine's internal representation, not the authoring format.

The lowering SHALL be:

- each node `N` yields a transition `T_N` carrying `N`'s `type` and `config`;
- each node `N` yields an input place `in(N)`;
- each edge `A → B` adds `in(B)` to `T_A`'s target places;
- a node with no outgoing edges yields a terminal place `end(N)` as its target;
- a node with no incoming edges contributes `in(N)` to the initial places.

A Petri net is still required — not merely convenient — because it remains a
superset of both models the fleet has: a single-token marking is a state machine
(procest's `case.status`), and a multi-token marking expresses parallel splits
and synchronising joins.

#### Scenario: A node carries the step, and the engine dispatches it

- **GIVEN** a flow with node `{ id: 'fetch', type: 'openconnector.source-call', config: { … } }`
- **WHEN** the run reaches `fetch`
- **THEN** the dispatcher is called with that NODE's `type` and `config`
- **AND** the transition is named for the node

#### Scenario: A node with several outgoing edges is a parallel split

- **GIVEN** a node `start` with edges to `a` and to `b`
- **WHEN** `start` fires
- **THEN** both `a` and `b` hold a token, and both branches run

#### Scenario: Converging edges are a merge, not a join

- **GIVEN** node `done` with incoming edges from `idle`, `at-capacity` and `failed`
- **WHEN** exactly one of those three fires
- **THEN** `done` runs
- **AND** it does NOT wait for the other two

#### Scenario: A declared join synchronises

- **GIVEN** node `{ id: 'done', join: true }` with incoming edges from `a` and `b`
- **WHEN** only `a` has fired
- **THEN** `done` is not enabled
- **AND** when `b` has also fired, `done` is enabled

### Requirement: A flow document is validated before it runs (REQ-FE-003)

The builder SHALL reject: a document with no nodes; a node with no id; a
duplicate node id (which would silently merge two nodes into one place, running
a flow the author did not draw); a node with no `type`; a node whose `type` is
unknown to the live node registry; an edge missing `from` or `to`; an edge whose
endpoint does not resolve to a declared node; and an `initial` naming an unknown
node.

Every rejection SHALL name the offending node or edge. The engine SHALL NOT
infer, repair or skip a malformed element: a skipped step produces a run that
reports success without doing the work.

#### Scenario: A dangling edge is rejected by name

- **GIVEN** a flow with an edge `{ id: 'ghost', from: 'a', to: 'nowhere' }`
  where no node `nowhere` is declared
- **WHEN** the definition is built
- **THEN** it fails with a message naming edge `ghost` and node `nowhere`

#### Scenario: A node with no step type is refused

- **GIVEN** a flow with node `{ id: 'gap' }` and no `type`
- **WHEN** the definition is built
- **THEN** it fails naming `gap`
- **AND** the run does not start

### Requirement: Initial places are explicit or inferred (REQ-FE-005)

The run SHALL start on the nodes named in `initial` when present. Otherwise the
engine SHALL infer the start as the flow's sources — the nodes no edge points
at. A flow whose edges form a complete cycle has no source and SHALL start on
the first declared node.

`initial` SHALL name NODES. It named places under the previous model; the two
coincided then and do not now.

#### Scenario: A cyclic flow still starts

- **GIVEN** a flow whose every node has an incoming edge
- **WHEN** the definition is built
- **THEN** the run starts on the first declared node

## ADDED Requirements

### Requirement: There is exactly one authoring model (REQ-FE-009)

The engine SHALL accept exactly one flow authoring shape: behaviour on nodes,
sequence on edges. A document carrying behaviour on an edge — any edge with a
non-empty `type` — SHALL be refused, naming the edge and directing the author to
the migration.

The engine SHALL NOT accept both shapes, and SHALL NOT infer which was intended.
A document with behaviour on both nodes and edges matches the refusal predicate
and is refused.

This is deliberate and its cost is accepted. A half-migrated document under dual
support would run, skip the step nobody claimed, and report COMPLETED — the
exact silent failure that the previous model's hard refusal existed to prevent,
reintroduced as a data-dependent bug instead of a loud one.

#### Scenario: A pre-inversion document is refused, not reinterpreted

- **GIVEN** a stored flow whose edges carry `type` and `config`
- **WHEN** it is built or run
- **THEN** it fails naming the first such edge
- **AND** the message identifies the document as pre-inversion and names the migration
- **AND** no run is created

#### Scenario: A migrated document builds

- **GIVEN** the same flow after migration — types on nodes, edges carrying only `from`/`to`
- **WHEN** it is built
- **THEN** the definition builds
- **AND** the transitions carry the same step types, in the same order, as before migration

## REMOVED Requirements

### Requirement: Both edge dialects are accepted (REQ-FE-004)

**Reason**: This required an edge to accept `{from, to}` and `{source, target}`
interchangeably, because an edge WAS the transition and both spellings were in
use across the fleet. Accepting two spellings for one concept is the same class
of ambiguity REQ-FE-009 now forbids for behaviour, and it is no longer earning
anything: an edge is sequence only, so there is nothing about it worth two
dialects.

`{from, to}` remains the single stored form — it is what all stored flows
already carry and what the builder already reads, so keeping it adds no
migration risk. Consumers that author in canvas terms (`{source, target}`,
which is `CnGraphCanvas`'s prop shape) translate at their own edge, as hermiq
does; the engine accepts one spelling.

**Migration**: `or-flow-migrate-definitions` normalises any stored edge using
`{source, target}` to `{from, to}` in the same pass that moves behaviour onto
nodes. A document still carrying the other spelling afterwards is refused by
name rather than silently accepted.
