# object-lifecycle

## ADDED Requirements

### Requirement: A preservation regime sits between active and transferred (REQ-APS-006)

A nominated object SHALL be able to enter a preservation state distinct
from the archive state that takes a finished object out of the working
views. In the preservation state an object SHALL leave the working views,
SHALL refuse writes to its content, SHALL keep its references resolvable
and SHALL remain readable. A schema SHALL be able to offer the archive
state, the preservation state, both or neither, and the two SHALL be
reported separately wherever a state is shown.

#### Scenario: a closed dossier and a static dossier are not the same thing

- **GIVEN** one object in the archive state and one in the preservation state
- **WHEN** both are read
- **THEN** each reports its own state and they are distinguishable

#### Scenario: a preserved object refuses a content write and still resolves

- **GIVEN** an object in the preservation state that another object references
- **WHEN** a write to its content is attempted and the reference is resolved
- **THEN** the write is refused naming the preservation state
- **AND** the reference resolves normally

### Requirement: A lifecycle end may be a referenced row, not only a listed value (REQ-APS-007)

`x-openregister-lifecycle.final` SHALL accept the reference form
`{ "from": "<schema>", "field": "<property>" }` beside the static list of
state values it already accepts. `from` SHALL name the schema the
lifecycle field references, and `field` SHALL name the property on that
row that says whether the lifecycle ends there. A schema save SHALL
refuse any other shape, naming the shape rather than the field's enum. A
reference form SHALL NOT be checked against the field's enum, because a
lifecycle field that is a `$ref` declares none.

The reference form SHALL be resolved by reading the referenced row and
taking the named property, resolved once per state per request. A state
that resolves to no row, to a row outside the declared schema, or to a
row without the named property SHALL NOT be terminal and SHALL be
reported in the log.

A lifecycle field that is a `$ref` carries the identifier of a row each
tenant creates for itself. A static list can only name values written
into the schema, so it names nothing, nothing is terminal, and an object
closes with no archival future and no error. That silence is what this
requirement ends.

#### Scenario: a case closes because its status row says the case is over

- **GIVEN** a schema whose lifecycle field references a status schema, declaring `final` as `{ from: statusType, field: isFinal }`
- **WHEN** an object reaches a status whose row carries `isFinal: true`
- **THEN** the state is terminal and the object is nominated
- @e2e exclude {resolution against a referenced row, covered by unit tests}

#### Scenario: a status row that is not an end leaves the case open

- **GIVEN** the same schema
- **WHEN** an object reaches a status whose row carries `isFinal: false`
- **THEN** the state is not terminal and nothing is nominated
- @e2e exclude {resolution against a referenced row, covered by unit tests}

#### Scenario: a state nobody can resolve is reported, not treated as an end

- **GIVEN** the same schema
- **WHEN** an object reaches a value that resolves to no row, or to a row in another schema
- **THEN** the state is not terminal
- **AND** the reason is written to the log naming the value and the declared schema
- @e2e exclude {log assertion, covered by unit tests}

#### Scenario: half a reference is refused at schema save

- **GIVEN** a schema declaring `final` as `{ from: statusType }`
- **WHEN** the schema is saved
- **THEN** the save is refused with `lifecycle-final-malformed`
- **AND** the message names the two keys the reference form needs
- @e2e exclude {schema-save validation, covered by unit tests}

#### Scenario: a static list of states still decides

- **GIVEN** a schema declaring `final` as a list of state values
- **WHEN** an object reaches one of them
- **THEN** the state is terminal, and no row is read
- @e2e exclude {resolution against a referenced row, covered by unit tests}
