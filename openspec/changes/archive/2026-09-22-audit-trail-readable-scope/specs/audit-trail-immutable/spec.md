# audit-trail-immutable

## ADDED Requirements

### Requirement: The audit trail is readable within a caller's own scope

The system SHALL offer a scoped audit list, separate from the admin-only
instance-wide index, that returns audit entries only for objects the calling
user may read. Readability SHALL be decided by the same RBAC funnel the object
read path uses, so that a grant, a schema rule and a register rule all mean
here what they mean everywhere else. An anonymous caller SHALL receive
nothing. An entry whose object cannot be resolved, or whose schema cannot be
resolved, SHALL be absent rather than present, so that every failure to decide
hides a row instead of showing it. The scoped list SHALL be cursor paginated,
SHALL NOT count the table, and SHALL bound the number of rows it inspects per
request.

#### Scenario: a handler sees only the entries of objects they may read

- **GIVEN** a trail with entries on an object the caller may read and entries on an object they may not
- **WHEN** the caller lists the scoped audit trail
- **THEN** only the entries of the readable object are returned
- @e2e exclude {the scope decision is a unit-level contract on ReadableAuditTrailLister, mutation-checked in tests/Unit/Service/Audit/ReadableAuditTrailListerTest.php}

#### Scenario: an anonymous caller is told nothing

- **GIVEN** a trail with entries
- **WHEN** an anonymous caller lists the scoped audit trail
- **THEN** no entries are returned and no query for candidates is made
- @e2e exclude {asserted in tests/Unit/Service/Audit/ReadableAuditTrailListerTest.php::testAnonymousCallerGetsNothingAndAsksTheMapperNothing}

#### Scenario: an entry whose object is gone is not shown

- **GIVEN** an audit entry whose object no longer resolves
- **WHEN** a non-admin lists the scoped audit trail
- **THEN** that entry is absent
- @e2e exclude {asserted in tests/Unit/Service/Audit/ReadableAuditTrailListerTest.php}

### Requirement: The scoped audit list withholds the instance-recon fields

The scoped audit list SHALL NOT return the `session`, `request` and
`ipAddress` of an entry. Those fields describe the instance rather than the
object, and the admin-only index remains the only surface that carries them.

#### Scenario: a scoped row carries the change but not the session

- **GIVEN** an audit entry with a session, a request id and an IP address on a readable object
- **WHEN** a non-admin lists the scoped audit trail
- **THEN** the row carries its action, actor and changes, and carries no `session`, `request` or `ipAddress`
- @e2e exclude {asserted in tests/Unit/Service/Audit/ReadableAuditTrailListerTest.php}
