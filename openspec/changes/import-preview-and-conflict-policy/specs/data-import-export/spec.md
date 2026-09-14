# data-import-export

## ADDED Requirements

### Requirement: A column mapping is authored, saved and reusable (REQ-IPC-001)

An uploaded file's columns SHALL be mappable onto a schema's properties in
an authored mapping, with the first rows shown as they would be mapped. A
mapping SHALL be saveable under a name and reusable against another file
with the same columns. A mapping naming a property the schema does not
have SHALL be refused, naming the property.

#### Scenario: a monthly correction reuses last month's mapping

- **GIVEN** a saved mapping and a new file with the same columns
- **WHEN** the mapping is applied
- **THEN** the columns are mapped without being reassigned by hand

#### Scenario: an unknown property is refused

- **GIVEN** a mapping naming a property that no longer exists on the schema
- **WHEN** it is applied
- **THEN** it is refused, naming the property
- @e2e exclude {validator behaviour, covered by unit tests}

### Requirement: An import is previewed, and the write applies the preview (REQ-IPC-002)

An import SHALL be previewable. The preview SHALL report how many rows
would be created, updated, skipped and refused, with a reason for each
refused row, and SHALL write nothing. The write SHALL apply the decisions
the preview made. A file that changed since the preview SHALL be refused.
The preview SHALL run as a background job with progress.

#### Scenario: a migration is inspected before it lands

- **GIVEN** a file of two hundred rows against a register holding fifty of them
- **WHEN** it is previewed
- **THEN** the counts of created, updated, skipped and refused are reported and nothing is written

#### Scenario: a changed file is refused

- **GIVEN** a preview taken of one file
- **WHEN** the write is requested with a different file
- **THEN** it is refused and nothing is written

### Requirement: A conflict policy is declared and a double match is refused (REQ-IPC-003)

An import SHALL declare a conflict policy of `create-only`,
`update-only`, `upsert` or `refuse-on-conflict`, and a match key. A row
that conflicts SHALL be handled by the declared policy. A row matching
more than one existing object SHALL be refused, naming the candidates,
whatever the policy. An import declaring no policy SHALL keep the current
upsert behaviour.

#### Scenario: a first migration refuses an unexpected match

- **GIVEN** policy `create-only` and a row matching an existing object
- **WHEN** the import runs
- **THEN** the row is refused with its reason, and the existing object is unchanged

#### Scenario: an ambiguous row is never resolved by guessing

- **GIVEN** a row whose key matches two objects
- **WHEN** the import runs under `upsert`
- **THEN** the row is refused, naming both objects

### Requirement: A destruction writes a restorable copy first (REQ-IPC-004)

Before an object or a file is destroyed, the system SHALL write a
restorable copy to a configured location outside the application, and the
recorded destruction SHALL name that copy. A destruction whose copy cannot
be written SHALL NOT run, and the failure SHALL be reported.

#### Scenario: the archivaris has something to sign

- **GIVEN** an object due for destruction and a configured copy location
- **WHEN** it is destroyed
- **THEN** a copy exists at that location and the destruction record names it

#### Scenario: no copy, no destruction

- **GIVEN** a copy location that cannot be written to
- **WHEN** a destruction is attempted
- **THEN** it does not run and the failure names the location
- @e2e exclude {filesystem condition, covered by unit tests}

### Requirement: The instance serialises and loads, without secrets (REQ-IPC-005)

The system SHALL serialise registers, schemas, objects, files and
configuration into a portable set, and SHALL load such a set into another
instance. Both SHALL run as background jobs with progress and a per-row
outcome. Secrets SHALL be excluded, and the serialisation SHALL record
that they were excluded so the absence is not read as their absence.

#### Scenario: an instance moves to another host

- **GIVEN** a serialised instance
- **WHEN** it is loaded into an empty instance
- **THEN** the registers, schemas, objects and files are present, with a per-row outcome reported
- @e2e exclude {long-running job, covered by unit tests with a small fixture}

#### Scenario: the file says the secrets are missing

- **GIVEN** an instance holding configured credentials
- **WHEN** it is serialised
- **THEN** no credential value is present and the set records that secrets were excluded
