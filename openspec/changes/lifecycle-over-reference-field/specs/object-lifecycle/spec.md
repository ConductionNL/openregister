# object-lifecycle

## ADDED Requirements

### Requirement: A transition carries a form whose values save with the move

A static transition, a graph block, a side move or a reopen MAY declare a
`form` naming schema properties and which are required. The transition
endpoint SHALL accept those values beside the action id, SHALL validate
them against the schema, SHALL write them in the same save as the lifecycle
field, and the transition's audit entry SHALL list them.
`availableActions()` SHALL return each action's form definition.

#### Scenario: closing with a result is one save and one audit entry

- **GIVEN** a `case` whose graph declares a form with required `result` on the move to the terminal status
- **WHEN** the handler applies that move with `result: 'granted'`
- **THEN** the case's status and result change in one save and one audit entry lists both
- @e2e exclude {proposal only; task 3.2 adds tests/e2e/api-direct/lifecycle-graph-side-moves.spec.ts when the engine ships}

#### Scenario: a required form value missing is refused

- **GIVEN** the same case
- **WHEN** the handler applies the move without `result`
- **THEN** the transition is refused with 422 naming `result` and the status is unchanged
- @e2e exclude {form validation is covered by unit tests on TransitionEngine}

### Requirement: Side moves set a second field and freeze the graph while set

A graph block MAY declare `sideMoves`, each naming a boolean field, the
value it sets and a label. The engine SHALL offer a side move whose field
is not yet at its value, SHALL refuse every graph move while any side
move's field is set, and SHALL offer the reversing side move instead.

#### Scenario: a suspended case offers resume only

- **GIVEN** a case at status `In behandeling` with side moves `suspend` (sets `suspended` true) and `resume` (sets it false)
- **WHEN** the handler applies `suspend` and lists available actions
- **THEN** the list holds `resume` and no `move-to` action
- @e2e exclude {proposal only; task 3.2 adds tests/e2e/api-direct/lifecycle-graph-side-moves.spec.ts when the engine ships}

### Requirement: A declared reopen is the only move out of a terminal state

A graph block MAY declare `reopen` with a target sibling and a required
action, `manage` by default. The engine SHALL offer `reopen` on an object
in a terminal state to a user who holds that action, SHALL apply it as a
named transition through the guard registry, and SHALL audit it as a
reopen. Without a declared reopen, terminal states SHALL stay locked out as
before.

#### Scenario: a manager reopens a closed case

- **GIVEN** a closed case whose graph declares `reopen` to the first status and a user with `manage`
- **WHEN** the user applies `reopen`
- **THEN** the status becomes the first status and the audit entry is of kind reopen
- @e2e exclude {proposal only; task 3.2 adds tests/e2e/api-direct/lifecycle-graph-side-moves.spec.ts when the engine ships}

#### Scenario: a handler without manage is not offered reopen

- **GIVEN** the same closed case and a user with `update` only
- **WHEN** they list available actions
- **THEN** the list is empty
- @e2e exclude {the guard is covered by unit tests on TransitionEngine}
