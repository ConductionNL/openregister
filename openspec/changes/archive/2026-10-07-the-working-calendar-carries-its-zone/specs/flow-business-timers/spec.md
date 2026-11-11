## ADDED Requirements

### Requirement: A working calendar says which zone its days are counted in

A working calendar SHALL carry an OPTIONAL `timezone`, an IANA zone name,
defaulting to `UTC`. A value that is not an IANA zone name SHALL be refused
with a message naming the field, and SHALL NOT be coerced.

The default SHALL NOT be the process's own default zone: the same calendar
must answer the same days on every instance.

The zone SHALL be the organisation's, and no rule SHALL read a viewer's
display zone in its place.

#### Scenario: The seeded Dutch calendar counts Dutch days

- **GIVEN** the `nl-national` calendar
- **WHEN** it is built
- **THEN** its zone SHALL be `Europe/Amsterdam`

#### Scenario: A calendar with no zone counts UTC days

- **GIVEN** a calendar declaring no zone, on a server set to another zone
- **WHEN** it is built
- **THEN** its zone SHALL be `UTC`

#### Scenario: A zone that does not resolve is refused

- **GIVEN** a calendar declaring `CET+1`
- **WHEN** it is built
- **THEN** the build SHALL be refused with a message naming `timezone`
