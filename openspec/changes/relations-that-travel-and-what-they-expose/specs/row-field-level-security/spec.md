# row-field-level-security

## ADDED Requirements

### Requirement: A relation type declares which properties of the far record it exposes (REQ-RTE-004)

A relation type MAY declare `exposes`, a list of properties of the far
schema. When a principal reaches a record only through that relation, the
read SHALL return exactly the declared properties. Every other property
SHALL read as withheld rather than as absent or empty. `exposes` SHALL be
validated at schema save against the far schema's declared properties, and
an unknown property SHALL be refused with HTTP 422 naming it.

#### Scenario: a Wmo case sees two fields of a Jeugdwet case

- **GIVEN** a relation type from a Wmo case to a Jeugdwet case declaring `exposes: ["status", "startDate"]`
- **WHEN** a handler with no access to the Jeugdwet register reads the linked record through the relation
- **THEN** `status` and `startDate` are returned
- **AND** every other property reads as withheld

#### Scenario: withheld is not empty

- **GIVEN** the same read
- **WHEN** the response is inspected for a property outside the exposed set
- **THEN** it is marked withheld and no value is present
- @e2e exclude {read shape, covered by unit tests}

#### Scenario: an unknown property is refused at schema save

- **GIVEN** a relation type exposing a property the far schema does not declare
- **WHEN** the schema is saved
- **THEN** the save fails with HTTP 422 naming the property
- @e2e exclude {annotation validator, covered by unit tests}

### Requirement: An exposure narrows a read and never widens one (REQ-RTE-005)

The properties a reader receives through a relation SHALL be the
intersection of the relation's declared `exposes` and what that reader could
see on the far record through any other path. A relation SHALL NOT grant
access to a property the reader is otherwise refused, and a reader who
already holds full access SHALL be unaffected by the declaration.

#### Scenario: a relation is not a back door

- **GIVEN** a relation exposing a property that field-level security refuses to the reader's groups
- **WHEN** the reader reads the far record through the relation
- **THEN** that property reads as withheld

#### Scenario: a reader with access sees no change

- **GIVEN** a reader who may read the far record directly
- **WHEN** the same record is read through the relation
- **THEN** every property they could see directly is returned
- @e2e exclude {intersection rule, covered by unit tests}
