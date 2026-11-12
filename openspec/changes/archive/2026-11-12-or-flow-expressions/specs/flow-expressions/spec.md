## ADDED Requirements

### Requirement: Expressions evaluate against the current item (REQ-FX-001)

A flow expression SHALL be JSONLogic, evaluated against a document exposing
the current item's `json` and `binary`, its `itemIndex`, the step's
`itemCount`, the run `context`, and the `subject`.

One authored expression SHALL therefore apply per item, without the author
drawing a loop.

#### Scenario: An expression reads the current item

- **GIVEN** an item whose record has `status: open`
- **WHEN** `{"var": "json.status"}` is evaluated
- **THEN** it returns `open`

#### Scenario: Position is in scope

- **GIVEN** item 2 of 5
- **WHEN** `itemIndex` and `itemCount` are read
- **THEN** they are 2 and 5

### Requirement: An expression that cannot be evaluated is false (REQ-FX-002)

Evaluation SHALL return null rather than throwing, and a condition SHALL treat
null as FALSE.

A branch whose condition could not be evaluated must not be taken. Throwing
would abort a run mid-graph with side effects half applied, which is worse than
a branch not firing.

#### Scenario: An unknown operator does not take the branch

- **GIVEN** a condition using an operator no one registered
- **WHEN** it is evaluated as a condition
- **THEN** the result is false

### Requirement: Expressions are validated when a flow is saved (REQ-FX-003)

A node SHALL reject a malformed expression in `validateConfig()`, so an author
learns in the editor rather than from a run that silently does nothing.

#### Scenario: A malformed condition cannot be saved

- **GIVEN** a filter whose condition uses an unknown operator
- **WHEN** the flow is saved
- **THEN** validation fails

### Requirement: Flows get the operators JSONLogic lacks (REQ-FX-004)

The engine SHALL register operators for string casing, trimming, splitting,
joining, replacement and regex matching; date formatting and arithmetic; array
uniqueness, sorting and length; and `coalesce`, `toJson`, `fromJson`.

The set SHALL stay small. Each operator exists because its absence would push
an author toward arbitrary code, which is the gap this decision exists to keep
closed.

A date operator given something unparseable SHALL return null, never "now" —
silently substituting the current time would corrupt data.

#### Scenario: A bad date returns null

- **GIVEN** `dateFormat` applied to text that is not a date
- **WHEN** it is evaluated
- **THEN** it returns null

### Requirement: Filter keeps only matching items (REQ-FX-005)

`openregister.filter` SHALL evaluate its condition per item and return only
those that match. Matching nothing SHALL be a legitimate outcome that ends the
branch's data.

A surviving item SHALL be paired to its ORIGINAL input index, not its new
position, so provenance survives the drop.

#### Scenario: Survivors keep their original provenance

- **GIVEN** two items where only the second matches
- **WHEN** the filter runs
- **THEN** one item is returned, paired to input item 1

### Requirement: Wait suspends the run and resumes cleanly (REQ-FX-006)

`openregister.wait` SHALL suspend the run for a duration (`for`) or until a
moment (`until`), and SHALL pass items through unchanged when re-entered with
`context.resuming`.

The node is re-entered because the marking does not advance past a suspended
step. A bare number in `for` SHALL be read as seconds.

A time that cannot be read at RUN time SHALL pass through rather than suspend,
because suspending on a moment that will never arrive strands the run forever.

#### Scenario: The first pass suspends and the second continues

- **GIVEN** a wait of one hour
- **WHEN** the step first runs
- **THEN** it suspends with a resume time an hour ahead
- **AND** when re-entered while resuming, it returns its items unchanged

@e2e exclude expression evaluation is backend-only — covered by PHPUnit; the expression editor is covered by its own change
