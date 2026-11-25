# computed-fields

## ADDED Requirements

### Requirement: A sequence may count separately per value of a property

`x-openregister-generated` MAY carry `partitionBy`, the name of one property
of the object. Schema save SHALL refuse a `partitionBy` that is not a plain
property name. On create, the system SHALL draw the number from a counter
keyed by the sequence name, the value of that property and the period, so
each value numbers from one in each period. An object whose `partitionBy`
property is empty, or holds a list or an object, SHALL draw from the
sequence's unpartitioned counter. A supplied value SHALL advance the counter
of its own partition.

#### Scenario: two units each start at one

- **GIVEN** schema `registration` whose `registrationNumber` declares sequence `post`, format `{year}-{seq:5}`, `resetOn: year`, `partitionBy: unit`
- **WHEN** a registration of unit `Finance`, one of unit `Housing` and another of unit `Finance` are created in 2026
- **THEN** they are numbered `2026-00001`, `2026-00001` and `2026-00002`
- @e2e exclude {pinned by the unit test GeneratedIdentifierPartitionTest; the live check is written with filinq's post register}

#### Scenario: an object without a unit keeps the shared counter

- **GIVEN** the same schema
- **WHEN** a registration without `unit` is created
- **THEN** it is numbered from the counter `post` had before `partitionBy` was declared
- @e2e exclude {pinned by the unit test GeneratedIdentifierPartitionTest}

#### Scenario: a path is refused

- **GIVEN** a schema declaring `partitionBy: unit.name`
- **WHEN** it is saved
- **THEN** the save is refused naming `partitionBy`
- @e2e exclude {schema-save validation, pinned by the unit test GeneratedIdentifierPartitionTest}
