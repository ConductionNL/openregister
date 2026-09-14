# enhanced-audit-trail

## ADDED Requirements

### Requirement: Every audit entry names the cause of the write (REQ-RCN-001)

Every audit entry SHALL carry a cause drawn from a closed vocabulary: a
person acting directly, a scheduled job, an import, a migration, a rule, or
a cascade from another write. The cause SHALL be derived on the server from
the acting context and SHALL NOT be settable by a client; a cause supplied
in a request SHALL be ignored and the attempt SHALL be recorded. Where the
cause is a run or another write, the entry SHALL name it, so the entry
points at the import, the job, the rule or the originating write that
produced it. The audit read and the audit export SHALL filter on cause and
on the named run.

#### Scenario: eight hundred records changed by one load

- **GIVEN** an import that updated eight hundred objects
- **WHEN** the audit trail is filtered on cause `import` for that run
- **THEN** the eight hundred entries are returned and no others

#### Scenario: a cascade names the write that caused it

- **GIVEN** a delete that cascades to five referenced objects
- **WHEN** the audit entry of one of the five is read
- **THEN** its cause is `cascade` and it names the originating write

#### Scenario: a client cannot claim to be a migration

- **GIVEN** a request carrying a cause field
- **WHEN** the object is written
- **THEN** the entry's cause is the one derived from the acting context
- **AND** the attempt to supply a cause is recorded
- @e2e exclude {request handling, covered by unit tests}

### Requirement: The cause survives deferred work (REQ-RCN-002)

A write made by a background job on behalf of an earlier act SHALL carry the
cause and the actor of that act, not the system. The cause SHALL travel with
the acting context that deferred listeners already forward to their jobs.

#### Scenario: a projection written overnight still names its origin

- **GIVEN** a save that defers a projection to a background job
- **WHEN** the job writes and the audit entry is read
- **THEN** it names the original actor and the cause of the save that deferred it

#### Scenario: a scheduled job with no earlier act reads as scheduled

- **GIVEN** a nightly retention pass with no originating user act
- **WHEN** its writes are audited
- **THEN** the cause is `scheduled job` and names the job
- @e2e exclude {background job, covered by unit tests}
