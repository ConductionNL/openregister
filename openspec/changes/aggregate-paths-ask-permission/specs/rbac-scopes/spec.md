# rbac-scopes

## ADDED Requirements

### Requirement: An aggregate over a property obeys that property's read rule (REQ-RBAC-140)

A facet, aggregation, grouping or other summary computed over a schema property
SHALL be shown only to a caller who may read that property. A summary that may
not be shown SHALL be ABSENT from the response rather than reported as zero or
empty, and the response SHALL name the fields withheld. Where the read rule
cannot be resolved, the summary SHALL be withheld.

#### Scenario: the value set is not readable to someone the values are not

- **GIVEN** a property carrying an authorization block or a scope
- **AND** a caller outside it who may list the register
- **WHEN** they request a facet over that property
- **THEN** no bucket for it is returned
- **AND** the field is named as withheld

#### Scenario: a sum is a value

- **GIVEN** a caller who may list a schema but may not read one of its properties
- **WHEN** they run an aggregation summing that property
- **THEN** it is refused

#### Scenario: a column heading is a value

- **GIVEN** a kanban view grouped on a property the caller may not read
- **WHEN** the board is opened
- **THEN** the distinct values are not returned as columns

#### Scenario: withheld is not none

- **GIVEN** an aggregate withheld from a caller
- **WHEN** the response is read
- **THEN** the aggregate is absent rather than zero
- @e2e exclude {response shape, covered by unit tests}

#### Scenario: an ungoverned property is unaffected

- **GIVEN** a schema with no property-level authorization
- **WHEN** any aggregate is requested
- **THEN** it is computed as before
