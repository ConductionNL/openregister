# flow-token

The run-level value a flow carries across its steps, into its sub-flows, and
over a suspension.

## ADDED Requirements

### Requirement: A run carries a mutable flow token

Every flow run SHALL expose a flow token at `context['token']` that any step can
both read and write, without changing the signature a flow node implements.

The token holds run-level values — a correlation id, a resolved reference, an
amended request/response envelope. It is not the data channel: records belong in
the item list, because a value placed in the token stops being per-record the
moment a step fans out or filters.

#### Scenario: A step reads a value another step wrote

- **WHEN** a step writes a value to the token and a later step reads that key
- **THEN** the later step receives the written value

#### Scenario: A run without a stored token starts with an empty one

- **WHEN** a run is executed and its stored context holds no token
- **THEN** the run is given an empty token rather than failing

#### Scenario: A malformed stored token does not break the run

- **WHEN** a run's stored context holds a token that is not a well-formed token
- **THEN** the run is given a usable token rather than throwing

### Requirement: The token survives suspension and resumption

A flow token SHALL be persisted when a run stops and restored when it resumes,
so that a run suspended by a wait continues later with the values it already
held.

#### Scenario: A token written before a wait is readable after it

- **WHEN** a step writes to the token, a later step suspends the run, and the run
  is resumed
- **THEN** a step running after the resumption reads the value written before the
  suspension

#### Scenario: The token is persisted on every outcome

- **WHEN** a run reaches any terminal or suspended status
- **THEN** the token's values are stored on the run

### Requirement: A sub-flow is seeded with its parent's token

A sub-flow SHALL be started with a token seeded from the values held by the flow
that invoked it, so a child can read what its parent resolved.

The child receives its own token rather than the parent's instance, so a
fire-and-forget child can never mutate a parent that has already moved on.

#### Scenario: A child reads a value its parent wrote

- **WHEN** a parent writes a value to its token and then invokes a sub-flow
- **THEN** a step in the sub-flow reads that value from its own token

#### Scenario: A fire-and-forget child cannot mutate its parent

- **WHEN** a parent invokes a sub-flow without waiting and that sub-flow writes to
  its token
- **THEN** the parent's token is unchanged

### Requirement: A waited-on sub-flow returns its token to the parent

A sub-flow invoked with `wait` SHALL merge the values it holds back into the
invoking flow's token when it completes, so that a sub-flow can resolve a value
on its parent's behalf.

Where both hold the same key the child's value wins, because the child ran later
and is the more specific writer.

#### Scenario: A parent reads a value its waited-on child wrote

- **WHEN** a parent invokes a sub-flow with `wait` and the sub-flow writes a value
  to its token
- **THEN** a step in the parent running after the sub-flow reads that value

#### Scenario: A child's value overrides the parent's on conflict

- **WHEN** a waited-on sub-flow writes a key its parent had already written
- **THEN** the parent's token holds the child's value after the sub-flow completes

#### Scenario: A parent's other values are preserved

- **WHEN** a waited-on sub-flow returns values for some keys but not others
- **THEN** the parent's values for the untouched keys are unchanged
