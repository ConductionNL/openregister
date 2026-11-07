# row-field-level-security

## ADDED Requirements

### Requirement: A relation type declares which properties of the far record it exposes (REQ-RTE-004)

A relation type MAY declare `exposes`, a list of properties of the far
schema. A link SHALL only ever show what the reader may read on the far
record. A reader without the read right on the far record SHALL NOT reach it
through the link, in their own organisation or another: the reference SHALL
stay an identifier, as for any record they cannot read. A reader who may read
the far record SHALL receive through the link exactly the declared properties
their own rules allow. Every other property SHALL read as withheld rather than
as absent or empty, and no metadata of the far record SHALL carry a withheld
value. `exposes` SHALL be validated at schema save against the far schema's
declared properties, and an unknown property SHALL be refused with HTTP 422
naming it.

#### Scenario: a Wmo case sees two fields of a Jeugdwet case

- **GIVEN** a relation type from a Wmo case to a Jeugdwet case declaring `exposes: ["status", "startDate"]`
- **WHEN** a handler who may read the Jeugdwet case reads it through the relation
- **THEN** `status` and `startDate` are returned
- **AND** every other property reads as withheld

#### Scenario: a link does not carry a reader to a record they cannot read

- **GIVEN** the same relation type
- **WHEN** a handler without the read right on the Jeugdwet case, in any organisation, extends the relation
- **THEN** the reference stays the identifier of the Jeugdwet case
- **AND** no property of the Jeugdwet case is returned
- @e2e exclude {needs a second organisation in the seed, covered by unit tests in RenderObjectExtendReadCheckTest}

#### Scenario: withheld is not empty

- **GIVEN** a handler who may read the far record
- **WHEN** the response is inspected for a property outside the exposed set
- **THEN** it is marked withheld and no value is present

#### Scenario: the metadata of the far record carries no withheld value

- **GIVEN** a far record whose `@self.name` is filled from a property outside the exposed set
- **WHEN** a handler reads it through the relation
- **THEN** `@self` says which record it is
- **AND** `@self.name` is not present
- @e2e exclude {the e2e schema fills no name from a property, covered by unit tests in RenderObjectExtendReadCheckTest}

#### Scenario: an unknown property is refused at schema save

- **GIVEN** a relation type exposing a property the far schema does not declare
- **WHEN** the schema is saved
- **THEN** the save fails with HTTP 422 naming the property

### Requirement: An exposure narrows a read and never widens one (REQ-RTE-005)

`exposes` is a display choice, not an access rule. The properties a reader
receives in the extended property SHALL be the intersection of the relation's
declared `exposes` and what that reader could see on the far record through
any other path. A relation SHALL NOT grant access to a property the reader is
otherwise refused. The render SHALL always narrow to the declared properties,
also for a reader who holds the read right on the far record. That reader
SHALL find the full far record, as a direct read shows it to them, in
`@self.objects`.

#### Scenario: a relation is not a back door

- **GIVEN** a relation exposing a property that field-level security refuses to the reader's groups
- **WHEN** the reader reads the far record through the relation
- **THEN** that property reads as withheld

#### Scenario: a reader with the read right finds the full record in @self.objects

- **GIVEN** a reader who may read the far record directly
- **WHEN** the same record is read through the relation
- **THEN** the extended property shows the declared properties and marks the rest withheld
- **AND** `@self.objects` holds every property they could see directly
- @e2e exclude {intersection rule, covered by unit tests}
