# runtime-schema-api

## ADDED Requirements

### Requirement: A repeating group is a declared property kind (REQ-RGC-001)

A property MAY be declared a repeating group, naming its member
properties, a minimum and a maximum count, whether the order is
meaningful, and which member acts as the item label. Each item SHALL be
validated the way an object is validated, and a violation SHALL name the
item's position and the member property. Counts outside the declared
bounds SHALL be refused.

#### Scenario: two gemachtigden on one record

- **GIVEN** a repeating group `gemachtigden` with a maximum of three
- **WHEN** two items are saved
- **THEN** both are stored, in order, each validated

#### Scenario: a violation says which item

- **GIVEN** the same group and an item missing a required member
- **WHEN** it is saved
- **THEN** the refusal names the item's position and the member property

#### Scenario: the maximum is enforced

- **GIVEN** the same group with a maximum of three
- **WHEN** four items are saved
- **THEN** the write is refused, naming the maximum

### Requirement: A property records that a value was not supplied, with a reason (REQ-RGC-002)

A property MAY be marked as not supplied, carrying a reason from an
administered list. That state SHALL be distinguishable from empty, SHALL
satisfy a required-value rule, and SHALL be readable with the object.

#### Scenario: honest incompleteness beats a typed "onbekend"

- **GIVEN** a required property and an administered reason
- **WHEN** the property is marked not supplied with that reason
- **THEN** the object saves and the property reads as not supplied with the reason

#### Scenario: not supplied is not empty

- **GIVEN** one object with an empty property and one marked not supplied
- **WHEN** both are read
- **THEN** the two states are distinguishable
