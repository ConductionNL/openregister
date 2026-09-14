# linked-entity-types

## ADDED Requirements

### Requirement: Any object reads the records that reference it (REQ-OHC-001)

The system SHALL answer, for any object, which objects reference it,
grouped by schema, each carrying its title, its status and when it last
changed. The answer SHALL respect the caller's access, SHALL be paged per
group, and SHALL require no change to either schema.

#### Scenario: an address has a history

- **GIVEN** an address object referenced by a melding, an inspection and a permit case
- **WHEN** the address is read with its referencing records
- **THEN** the three are returned, grouped by schema, each with its status

#### Scenario: the reverse view obeys access

- **GIVEN** a caller who may read only one of the three
- **WHEN** they make the same read
- **THEN** only that one is returned

### Requirement: A lens property reads a referenced object's field live (REQ-OHC-002)

A property MAY declare a lens: a reference property on this schema and a
property to read through it. The value SHALL be resolved at read time,
SHALL NOT be stored, and SHALL be read-only. A write to a lens property
SHALL be refused. When the referenced object's value changes, the lens
SHALL reflect it with no write to this object.

#### Scenario: the besluit's date on the bezwaar, without a copy

- **GIVEN** a bezwaar referencing a besluit, with a lens onto the besluit's date
- **WHEN** the besluit's date changes
- **THEN** reading the bezwaar returns the new date, and the bezwaar was not written

#### Scenario: a lens cannot be written

- **GIVEN** the same lens property
- **WHEN** a client sends a value for it
- **THEN** the write is refused, naming the property

### Requirement: A lens over an unreadable object renders as withheld (REQ-OHC-003)

When the caller may not read the referenced object, the lens SHALL render
as withheld, distinguishable from absent. The referenced object's other
values SHALL NOT be disclosed.

#### Scenario: withheld is not the same as empty

- **GIVEN** a lens onto an object the caller may not read
- **WHEN** the object carrying the lens is read
- **THEN** the property is marked withheld rather than returned empty

### Requirement: Every schema declares its list columns and search fields (REQ-OHC-004)

A schema MAY declare the columns a list surface shows and the fields it
searches on. The generic list surface SHALL render them for any schema, so
no object type needs a list page of its own. A schema declaring none SHALL
keep the current default columns.

#### Scenario: an object type is as usable as a case list

- **GIVEN** a schema declaring four columns and two search fields
- **WHEN** its list surface is opened
- **THEN** the four columns render and the two fields are searchable

### Requirement: An intake source is an administered object (REQ-OHC-005)

A watched folder, a mailbox or an inbound endpoint SHALL be an object in a
register rather than a value in a settings screen. There SHALL be able to
be more than one, each SHALL be switchable off, and each SHALL carry the
access rules and audit any object carries.

#### Scenario: a second mailbox is configuration, not code

- **GIVEN** one configured mailbox source
- **WHEN** an administrator adds a second
- **THEN** both are listed and both are polled

#### Scenario: a source is switched off without being deleted

- **GIVEN** two sources
- **WHEN** one is disabled
- **THEN** it stops being polled and stays listed
