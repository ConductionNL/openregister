# computed-fields

## ADDED Requirements

### Requirement: The calculation operator catalogue is published (REQ-CVJ-001)

The system SHALL publish every operator the JSON-AST calculation evaluator
accepts, with its arity, the types of its operands, the type of its result
and a description a human can read. The list SHALL be generated from the
evaluator's own dispatch, so an operator added to the evaluator appears
without a second edit.

#### Scenario: an expression builder offers what the engine has

- **GIVEN** an evaluator accepting arithmetic, comparison, logical, string and date operators
- **WHEN** the operator catalogue is read
- **THEN** each operator is returned with its arity and its operand types

#### Scenario: a newly added operator appears on its own

- **GIVEN** an operator added to the evaluator's dispatch
- **WHEN** the catalogue is read
- **THEN** that operator is present
- @e2e exclude {generated from one source, covered by a unit test comparing the two}

### Requirement: A calculation is authored through a property form and tried before it is saved (REQ-CVJ-002)

A calculation declaration SHALL be forwardable by an application's
property form under the published property vocabulary contract, and SHALL
be validated at schema save against the operator catalogue exactly as a
directly written annotation is. The system SHALL evaluate a declaration
against a named object or a sample payload without saving the schema,
returning either the value or the error. The properties an expression
reads SHALL be derived from the expression itself, and circular dependency
detection SHALL run on the derived list.

#### Scenario: an administrator tries an expression before committing it

- **GIVEN** an unsaved calculation that adds six weeks to `ontvangstdatum`
- **WHEN** it is evaluated against a sample payload holding that date
- **THEN** the response carries the resulting date
- **AND** no schema was written

#### Scenario: an invalid operator is refused at schema save

- **GIVEN** a forwarded calculation naming an operator the catalogue does not hold
- **WHEN** the schema is saved
- **THEN** the save fails with 422 naming the operator

#### Scenario: a cycle is caught on the derived list

- **GIVEN** two calculations that read each other's properties, with no declared dependency lists
- **WHEN** the schema is saved
- **THEN** the save fails naming both properties as a cycle
- @e2e exclude {validator, covered by unit tests}
