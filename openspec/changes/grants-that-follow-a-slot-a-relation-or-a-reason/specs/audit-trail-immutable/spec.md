# audit-trail-immutable

## ADDED Requirements

### Requirement: An emergency grant records its reason and every read made under it (REQ-GSR-006)

Taking an emergency self-granted access SHALL write an audit entry naming
the principal, the record, the verbs granted, the duration and the reason
the principal supplied, on the hash-chained trail where it cannot be edited
afterwards. Every read and write performed under that grant SHALL be audited
as made under it, naming the grant. The entries SHALL survive the expiry of
the grant.

#### Scenario: the reason cannot be edited later

- **GIVEN** an emergency grant taken with a reason
- **WHEN** the audit trail is verified
- **THEN** the entry holding the reason is part of the chain

#### Scenario: what was looked at is on the record

- **GIVEN** a handler reading a record under an emergency grant
- **WHEN** the record's audit trail is read
- **THEN** the read is recorded as made under that grant

#### Scenario: the trail outlives the grant

- **GIVEN** an expired emergency grant
- **WHEN** the record's audit trail is read
- **THEN** the grant, its reason and the reads made under it are still there
- @e2e exclude {time-dependent, covered by unit tests with a clock fixture}
