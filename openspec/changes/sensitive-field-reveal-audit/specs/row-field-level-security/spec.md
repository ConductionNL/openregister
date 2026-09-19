# row-field-level-security

## ADDED Requirements

### Requirement: A protected property can audit every reveal

A property `authorization` block MAY declare `audit: true`. Schema save
SHALL refuse it on a property without a `read` rule. When the value of such
a property is returned to a user, the system SHALL write one hash-chained
audit entry `property.revealed` per (user, object, property, request), also
on list reads, batched per request; a stripped property SHALL write
nothing. Trusted internal reads SHALL write one entry per run naming the
process.

#### Scenario: a handler's look at a BSN is on record

- **GIVEN** property `bsn` with `read: [{group: "bsn-geautoriseerd"}]` and `audit: true`, and a user in that group
- **WHEN** the user reads one object
- **THEN** the object's audit trail holds one `property.revealed` entry naming the user and `bsn`
- @e2e exclude {proposal only; task 3.1 adds tests/e2e/ci/reveal-audit.spec.ts when the filter ships}

#### Scenario: a list reveals forty times

- **GIVEN** the same property and a list of forty objects
- **WHEN** the user lists them
- **THEN** forty entries are written in one batch
- @e2e exclude {batching, covered by unit tests}

#### Scenario: a user outside the group leaves no trace

- **GIVEN** a user not in `bsn-geautoriseerd`
- **WHEN** the user reads the object
- **THEN** `bsn` is absent and no reveal entry is written
- @e2e exclude {stripping, covered by PropertyRbacHandler unit tests}

#### Scenario: auditing a public field is refused

- **GIVEN** a property with `audit: true` and no `read` rule
- **WHEN** the schema is saved
- **THEN** the save fails with 422 naming the property
- @e2e exclude {validator, covered by unit tests}
