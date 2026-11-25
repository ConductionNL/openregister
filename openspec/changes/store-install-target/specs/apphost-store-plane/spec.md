# apphost-store-plane

## ADDED Requirements

### Requirement: An install can go into a target the app declares

A store MAY declare an install target: the object property that records it and
the schemas whose installed objects get it. An install request carrying a
`target` SHALL write that id into the declared property on every installed
object of a declared schema, after the bundle's signature is checked. An install
without a `target` SHALL behave as before. A `target` the store did not declare,
or one that is not an id, SHALL be refused with 400 and nothing installed; the
install posture SHALL be checked first.

#### Scenario: a skill bundle is installed into one world

- **GIVEN** a store declaring `installTarget` with property `setting` and schema `larping_skill`
- **AND** a signed configuration set with two skills
- **WHEN** an administrator installs it with target `world-1`
- **THEN** the signature is checked on the set as published
- **AND** both skills are imported with `setting` = `world-1`

#### Scenario: a target the store does not declare is refused

- **GIVEN** a store declaring no `installTarget`
- **WHEN** an administrator installs an item with a target
- **THEN** the answer is 400 and nothing is resolved or installed

#### Scenario: a target that is not an id is refused

- **GIVEN** a store declaring an `installTarget`
- **WHEN** an administrator installs with target `../other`
- **THEN** the answer is 400 and nothing is installed

#### Scenario: a caller who may not install learns nothing about targets

- **GIVEN** a store whose install posture is admin
- **WHEN** a non-administrator installs with any target
- **THEN** the answer is 403
