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
