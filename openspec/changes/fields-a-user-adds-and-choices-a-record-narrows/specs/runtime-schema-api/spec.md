# runtime-schema-api

## ADDED Requirements

### Requirement: A property may be declared at a scope narrower than the schema (REQ-FUC-001)

A property MAY declare a `scope` naming an organisational unit or a team.
Such a property SHALL be a full property of the schema: validated, indexed,
searchable, facetable, groupable and exportable exactly as an unscoped one.
It SHALL be returned and writable only for principals inside its scope, and
SHALL be absent from the read of a principal outside it. Adding, changing
and removing a scoped property SHALL be a declared action gated by a group,
so it can be granted without granting the schema.

#### Scenario: a team adds the field it needs

- **GIVEN** a user holding the scoped-property action for their team
- **WHEN** they add a property scoped to that team
- **THEN** the property is saved and is visible on the team's records

#### Scenario: the field behaves like every other field

- **GIVEN** objects carrying values for a scoped property
- **WHEN** the list is filtered, facetted and grouped on it
- **THEN** it behaves exactly as an unscoped property of the same type

#### Scenario: another team does not see it

- **GIVEN** the same property
- **WHEN** a user of another team reads one of those objects
- **THEN** the property is absent from the read and a write to it is refused

#### Scenario: an ordinary user without the action cannot add one

- **GIVEN** a user outside the declared group
- **WHEN** they try to add a scoped property
- **THEN** the request is refused
- @e2e exclude {authorization, covered by unit tests}

### Requirement: Scoped properties are bounded, reviewable and promotable (REQ-FUC-002)

The number of scoped properties per scope SHALL be bounded by an
administered ceiling, and a property above it SHALL be refused naming the
ceiling. The system SHALL report scoped properties with no value written for
a declared period. A scoped property SHALL be promotable to a schema
property as a recorded act, and promotion SHALL keep the values already
stored.

#### Scenario: the register does not fill up unnoticed

- **GIVEN** a scope at its administered ceiling
- **WHEN** another scoped property is added
- **THEN** it is refused naming the ceiling

#### Scenario: an abandoned field is visible as abandoned

- **GIVEN** a scoped property with no values written for longer than the declared period
- **WHEN** the report is read
- **THEN** it is listed as unused
- @e2e exclude {report, covered by unit tests}

#### Scenario: promotion keeps what people typed

- **GIVEN** a scoped property with values on forty objects
- **WHEN** it is promoted to a schema property
- **THEN** the forty values are still readable
- **AND** the promotion is on the audit trail with its actor

### Requirement: A reference property may narrow its choices with a query over the record (REQ-FUC-003)

A reference property MAY declare a filter over the referenced schema whose
operands are properties of the record being edited. The options read SHALL
return only the matching objects, paged and evaluated under the caller's
access. A write of a reference outside the filter SHALL be refused on the
server with HTTP 422 naming the filter, whatever client made it. Schema save
SHALL refuse a filter naming a property that neither the record's schema nor
the referenced schema declares.

#### Scenario: only the contacts of this organisation

- **GIVEN** a case holding an organisation, and a `contactPerson` reference filtered on the contact's organisation matching it
- **WHEN** the options are read for that case
- **THEN** only contacts of that organisation are returned

#### Scenario: a client that ignores the filter is refused

- **GIVEN** the same property
- **WHEN** a client writes a contact of another organisation directly to the API
- **THEN** the write is refused with HTTP 422 naming the filter

#### Scenario: an unknown operand is refused at schema save

- **GIVEN** a filter naming a property neither schema declares
- **WHEN** the schema is saved
- **THEN** the save fails with HTTP 422 naming the property
- @e2e exclude {annotation validator, covered by unit tests}

### Requirement: An unresolved filter offers nothing and names what it needs (REQ-FUC-004)

When the property a filter depends on holds no value, the options read SHALL
return no options and SHALL name the property whose value it needs. It SHALL
NOT return the unfiltered set.

#### Scenario: choose the organisation first

- **GIVEN** a case with no organisation chosen and a `contactPerson` reference filtered on it
- **WHEN** the options are read
- **THEN** no options are returned
- **AND** the response names the organisation property as the one it needs

#### Scenario: no options is not every option

- **GIVEN** the same case
- **WHEN** the options are read
- **THEN** the full contact list is not returned
- @e2e exclude {options read, covered by unit tests}
