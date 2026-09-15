# object-lifecycle

## ADDED Requirements

### Requirement: Every write path evaluates the declared rules (REQ-REO-004)

Rule evaluation SHALL happen in the save pipeline, so that object create,
object update, object patch, import, a flow node write and a bulk job
write all reach it. The system SHALL carry a test enumerating the write
paths and asserting that each one reaches the evaluator, so that a write
path added later without an entry fails.

#### Scenario: an imported object is subject to the same rules

- **GIVEN** a schema whose state `closed` requires `outcome`
- **WHEN** an import creates an object in state `closed` without `outcome`
- **THEN** the import records that row as refused, naming `outcome`
- **AND** the object is not created

#### Scenario: a new write path without an entry fails the test

- **GIVEN** the enumerated write paths and their assertions
- **WHEN** a write path is added that bypasses the save pipeline
- **THEN** the enumeration test fails and names the path
- @e2e exclude {test-suite invariant, covered by the enumeration unit test}

### Requirement: A property's allowed values may follow another property (REQ-REO-005)

A property MAY declare that its allowed values follow from the value of
another property of the same object, administered as a table of pairs
rather than as one rule per pair. Schema save SHALL refuse a table naming
a property the schema does not declare or a value the controlling property
cannot take. On object save a value outside the pairs allowed by the
controlling value SHALL be refused with 422, naming both properties.

#### Scenario: a dependent value outside the table is refused

- **GIVEN** `resultaat` whose allowed values follow `zaaktype`, and a table pairing `bezwaar` with `gegrond` and `ongegrond`
- **WHEN** an object with `zaaktype: bezwaar` is saved with `resultaat: verleend`
- **THEN** the save fails with 422 naming `resultaat` and `zaaktype`

#### Scenario: a table naming an unknown property is refused at schema save

- **GIVEN** a table whose controlling property is not declared by the schema
- **WHEN** the schema is saved
- **THEN** the save fails with 422 naming the missing property
- @e2e exclude {validator, covered by unit tests}

### Requirement: A property default may be an expression (REQ-REO-006)

A property's default MAY be a JSON-AST expression instead of a literal,
evaluated on create in the same evaluator as a declared calculation. An
expression that cannot be evaluated SHALL refuse the create and name the
property, never fall back to an empty value.

#### Scenario: a default is derived on create

- **GIVEN** a property `uiterlijkeDatum` whose default adds six weeks to `ontvangstdatum`
- **WHEN** an object is created with `ontvangstdatum` set and no `uiterlijkeDatum`
- **THEN** `uiterlijkeDatum` is six weeks later

#### Scenario: an unevaluable default refuses the create

- **GIVEN** the same property and a create without `ontvangstdatum`
- **WHEN** the object is created
- **THEN** the create fails and the message names `uiterlijkeDatum`
- @e2e exclude {evaluator behaviour, covered by unit tests}
