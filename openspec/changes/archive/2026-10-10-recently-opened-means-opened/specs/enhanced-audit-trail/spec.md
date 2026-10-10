# enhanced-audit-trail

## ADDED Requirements

### Requirement: A read made on a person's behalf names its cause as a lookup

The audit cause vocabulary SHALL include `lookup`: a read the code made while serving a person, which is not the person opening the object. A `read` row written inside a lookup SHALL carry cause `lookup` and SHALL otherwise be the same row it was before: same action, same actor, same hash chain, same retention. A lookup SHALL only replace the cause `person`; inside an import, a rule, a migration, a scheduled job or a cascade, the row SHALL keep that cause and its run. Like every cause, `lookup` SHALL be derived on the server and SHALL NOT be settable by a client.

#### Scenario: a guard reads an object for a person

- **GIVEN** a user acting directly
- **WHEN** a permission guard reads object A inside a lookup
- **THEN** the audit trail holds a `read` row for A with cause `lookup` and the user as actor
- @e2e exclude {stamped in the shared audit builder; covered by WriteCauseTest and RecentLensLookupCallSitesTest}

#### Scenario: a lookup inside a scheduled job

- **GIVEN** a scheduled job running with cause `scheduled` and run `job-7`
- **WHEN** it reads object A inside a lookup
- **THEN** the `read` row for A carries cause `scheduled` and run `job-7`
- @e2e exclude {ambient frame behaviour; covered by WriteCauseTest}

#### Scenario: filtering out lookups

- **GIVEN** an audit trail with `read` rows of cause `person` and `lookup`
- **WHEN** an auditor filters the audit trail on cause `person`
- **THEN** only the reads people made directly are returned
- @e2e exclude {the cause filter is the existing REQ-RCN-001 filter; `lookup` is one more value in it}
