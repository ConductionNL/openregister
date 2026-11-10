## ADDED Requirements

### Requirement: Stored flows migrate to the action-node shape (REQ-FS-013)

Every stored flow SHALL be converted from behaviour-on-edges to
behaviour-on-nodes by the graph dual: each old edge becomes a node carrying its
`type` and `config`, and two nodes are connected wherever their old edges met at
a place. The old place's id and name SHALL be preserved as the connecting edge's
title.

The conversion SHALL preserve the step sequence, splits, merges and initial
positions of the original document.

#### Scenario: A split survives the migration

- **GIVEN** an old edge `{ id: 'work-gate', from: 'listed', to: ['work', 'idle'] }`
- **WHEN** the flow is migrated
- **THEN** node `work-gate` carries that edge's type and config
- **AND** it has one outgoing edge per step that left `work` and `idle`

#### Scenario: A merge survives the migration

- **GIVEN** three old edges all ending at place `done`
- **WHEN** the flow is migrated
- **THEN** the three corresponding nodes each have an edge to the same successor
- **AND** that successor runs when any one of them completes

#### Scenario: A place's label becomes the line's label

- **GIVEN** an old place `{ id: 'staged', name: 'Stage finished' }`
- **WHEN** the flow is migrated
- **THEN** the edge that replaces it carries `Stage finished` as its title

### Requirement: A non-terminal sink is marked as an explicit exit (REQ-FS-014)

After conversion, a node with no outgoing edge whose step type is not registered
terminal SHALL be marked `exit: true`.

This asserts only what the source document already meant — the place had no
outgoing edge, so the flow ended there — and prevents the migration from
producing flows that the connectivity rule would refuse.

#### Scenario: A non-terminal ending is made explicit

- **GIVEN** an old flow ending with an `openregister.set-fields` step into a
  place with no outgoing edges
- **WHEN** the flow is migrated
- **THEN** that node carries `exit: true`
- **AND** the migrated flow is not refused as a dead end

#### Scenario: A stop step needs no mark

- **GIVEN** an old flow ending with an `openregister.stop` step
- **WHEN** the flow is migrated
- **THEN** that node is not marked `exit: true`, being registered terminal already

### Requirement: The migration sees every flow and is idempotent (REQ-FS-015)

The migration SHALL read flows WITHOUT organisation or owner scoping, SHALL skip
flows already in the new shape, and SHALL report how many flows it inspected,
migrated and skipped.

A flow is pre-inversion iff any of its edges carries a non-empty `type` — the
same predicate the engine refuses on, so the two cannot disagree about what
needs migrating.

#### Scenario: A flow in another organisation is migrated

- **GIVEN** a flow whose organisation differs from every other flow's
- **WHEN** the migration runs
- **THEN** it is migrated
- **AND** the reported count includes it

#### Scenario: Running twice changes nothing

- **WHEN** the migration runs a second time
- **THEN** no flow is modified, and the report says every flow was already migrated

#### Scenario: In-flight runs stop the migration

- **GIVEN** a run in `running` or `suspended` state
- **WHEN** the migration runs
- **THEN** it refuses and names those runs
- **AND** no flow is modified

#### Scenario: Seeing nothing is distinguishable from having nothing to do

- **WHEN** the migration cannot read any flows
- **THEN** its report distinguishes that from having found only migrated flows
