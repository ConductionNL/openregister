# enhanced-audit-trail Specification

## Purpose
TBD - created by archiving change add-hotpath-db-indexes. Update Purpose after archive.

## Requirements

### Requirement: Audit-trail queries are index-backed

Queries against the audit trail filtered by `register`/`schema` SHALL be
supported by a database index (covering statistics and history listings). The
append-only audit table SHALL NOT require a full table scan for
register/schema-scoped reads.

#### Scenario: Register-scoped audit stats use an index

- **WHEN** a register-detail view or dashboard requests audit statistics filtered
  by register (and schema)
- **THEN** the query uses a composite index on `(register, schema)` and does not
  full-scan the audit table

### Requirement: The universal soft-delete list predicate is index-backed on all supported databases

The common list predicate `_deleted IS NULL AND _owner = ?` SHALL be supported by
an index on every supported database platform, not only PostgreSQL.

#### Scenario: MySQL/MariaDB list query is indexed

- **WHEN** a list/search query runs on a MySQL/MariaDB install
- **THEN** the `(_deleted, _owner)` predicate is served by a composite index

### Requirement: Schema-scoped table resolution does not scan the whole catalog

Resolving the magic tables for a schema SHALL filter candidate tables in the
catalog query, not list every table in the database and discard non-matches in
PHP.

#### Scenario: findBySchema filters in the query

- **WHEN** objects are fetched by schema
- **THEN** the `information_schema` lookup is filtered to candidate tables for that
  schema

### Requirement: The audit trail is written to a configured file sink (REQ-ATS-001)

In addition to the database, audit entries SHALL be written to a
structured file in a configured location and a documented format. The file
SHALL be append-only from the application's side. A failure to write to
the sink SHALL itself be recorded as an audit entry and SHALL be reported
on the operations console.

#### Scenario: the security operations centre can read the trail

- **GIVEN** a configured file sink
- **WHEN** an auditable act happens
- **THEN** an entry appears in the database and a line in the file

#### Scenario: a broken sink is loud

- **GIVEN** a sink location that cannot be written
- **WHEN** an auditable act happens
- **THEN** the database entry is written, a failure entry is recorded, and the console reports it
- @e2e exclude {filesystem condition, covered by unit tests}

### Requirement: A write made with a token names the token, its owner and its consumer (REQ-ATS-002)

An audit entry for a write made with an API token SHALL name the token,
the principal who owns it and the consumer it belongs to, beside the
before and after values the entry already carries. Request and response
payloads SHALL NOT be stored.

#### Scenario: which integration changed this field

- **GIVEN** a field changed by a call made with a supplier's token
- **WHEN** the entry is read
- **THEN** it names the token, its owner, the consumer, and the value before and after

#### Scenario: no payload copy

- **GIVEN** the same call carrying a body
- **WHEN** the entry is read
- **THEN** no request or response body is present

### Requirement: Reported content keeps a copy that removal does not destroy (REQ-ATS-003)

When content is reported for review, the system SHALL copy it at the
moment the report is filed. The copy SHALL be readable only by the
reviewers, SHALL carry its own retention, and a later removal of the
content SHALL name the copy.

#### Scenario: deleting the content does not delete the evidence

- **GIVEN** reported content that is then removed
- **WHEN** a reviewer opens the report
- **THEN** the copy is readable and the removal record names it

#### Scenario: the copy is not generally readable

- **GIVEN** the same copy
- **WHEN** a user who is not a reviewer asks for it
- **THEN** it is refused
- @e2e exclude {access resolution, covered by unit tests}

### Requirement: A security-relevant setting change is announced (REQ-ATS-004)

A setting MAY be marked security relevant. When such a setting changes,
the administrators SHALL be notified, naming the setting, the actor and
the old and new values where neither is a secret. A secret value SHALL be
announced as changed without being quoted.

#### Scenario: the beheerteam hears about it

- **GIVEN** a setting marked security relevant
- **WHEN** it changes
- **THEN** the administrators are notified with the setting, the actor and both values

#### Scenario: a secret is announced without being shown

- **GIVEN** a security-relevant setting holding a credential
- **WHEN** it changes
- **THEN** the notification says it changed and quotes neither value

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

### Requirement: A correction is its own act, with a required reason (REQ-RGC-003)

Correcting a core value after the fact SHALL require a correction right
and a reason, and SHALL be recorded as a correction rather than as an
ordinary update. The audit entry SHALL carry the reason and both values. A
principal without the correction right SHALL be refused, naming the right.

#### Scenario: a mis-registered case is corrected, not edited

- **GIVEN** a principal holding the correction right
- **WHEN** they correct a core value with a reason
- **THEN** the value changes and the audit entry is a correction carrying the reason and both values

#### Scenario: no reason, no correction

- **GIVEN** the same principal
- **WHEN** they correct a value with no reason
- **THEN** it is refused, naming the requirement

#### Scenario: an auditor can separate corrections from updates

- **GIVEN** a record with four updates and one correction
- **WHEN** the trail is filtered to corrections
- **THEN** one entry is returned

### Requirement: Consecutive edits merge only under an administered window (REQ-RGC-004)

The system SHALL default to recording every edit separately. An
administrator MAY set an aggregation window within which consecutive edits
by one actor on one object are recorded as a single entry, and that entry
SHALL name how many edits it covers.

#### Scenario: order is not merged away by default

- **GIVEN** no aggregation window
- **WHEN** one actor makes two edits a minute apart
- **THEN** two entries are recorded, in order

#### Scenario: a set window says what it merged

- **GIVEN** a window of five minutes
- **WHEN** one actor makes three edits inside it
- **THEN** one entry is recorded, naming three edits

### Requirement: A record's file metadata is corrected in one form (REQ-RGC-005)

Every file on an object, with its name and its description, SHALL be
editable and savable together in one act. One audit entry SHALL be written
per file actually changed, and a file left unchanged SHALL write none.

#### Scenario: tidying a dossier before it goes out

- **GIVEN** an object with six files, three of them badly named
- **WHEN** the three are renamed and saved together
- **THEN** all three are renamed and three audit entries are written

#### Scenario: nothing changed, nothing recorded

- **GIVEN** the same form saved with no edits
- **THEN** no audit entry is written
