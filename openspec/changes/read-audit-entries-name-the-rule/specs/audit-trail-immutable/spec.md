## ADDED Requirements

### Requirement: A read entry names the access rule that let the reader see the object

Every `read` audit entry written by `ReadHistoryService::registerAuditRead()` SHALL carry a `ruleDigest` and a `ruleVersion`. `ruleDigest` SHALL be the 64-character hex SHA-256 of the canonical JSON of the schema authorization rule that granted the read: the first rule in the schema's `read` list the reader matched, with its `group` and `match` conditions as declared, not as resolved for this reader. `ruleVersion` SHALL be the schema's version at the time of the read. When no rule mediated the read (an administrator, the object's owner, or a schema without read rules), `ruleDigest` and `ruleVersion` SHALL be `null`. The digest SHALL be computed by one helper, so the same rule gives the same digest on every read path that writes a read entry, and a different rule gives a different digest.

#### Scenario: A read under a row-level rule records that rule
- **GIVEN** schema `melding` has the read rule `{ "group": "behandelaars", "match": { "afdeling": "$activeDepartment" } }`
- **AND** user `alice` is in group `behandelaars` and her department matches melding `m-123`
- **WHEN** `alice` opens `m-123` through `GET /api/objects/{register}/melding/m-123`
- **THEN** the new `read` entry carries `ruleDigest` equal to the SHA-256 of that rule's canonical JSON
- **AND** `ruleVersion` equal to the schema's current version
- @e2e exclude read logging is a backend side effect; covered by an API test that opens an object and reads its audit entry

#### Scenario: The digest does not depend on the reader
- **GIVEN** the rule above and users `alice` and `bob` in different departments
- **WHEN** each opens a melding of their own department
- **THEN** both `read` entries carry the same `ruleDigest`
- @e2e exclude backend computation; covered by RuleDigest unit tests and an API test

#### Scenario: Different rules give different digests
- **GIVEN** schemas `melding` and `dossier` with different read rules
- **WHEN** one user opens an object of each
- **THEN** the two `read` entries carry different `ruleDigest` values
- @e2e exclude backend computation; covered by RuleDigest unit tests

#### Scenario: A read no rule mediated records no digest
- **GIVEN** an administrator opens a melding
- **WHEN** the `read` entry is written
- **THEN** its `ruleDigest` and `ruleVersion` are `null`
- @e2e exclude read logging is a backend side effect; covered by ReadHistoryService unit tests

#### Scenario: An auditor proves which rule was in force
- **GIVEN** a `read` entry with `ruleDigest` D and `ruleVersion` V
- **AND** the schema's read rule has changed since
- **WHEN** an auditor takes the schema at version V and computes the digest of the rule that matched
- **THEN** the result equals D
- @e2e exclude manual audit procedure; covered by a unit test that digests a stored schema version

### Requirement: The rule digest is part of the hash chain

`ruleDigest` and `ruleVersion` SHALL enter the canonical JSON that the audit hash chain hashes, in the chain's key order, when they are present. When an entry has neither field, they SHALL be left out of the canonical JSON entirely, so an entry written before this requirement hashes exactly as it did and the chain still verifies end to end.

#### Scenario: A chain across old and new entries verifies
- **GIVEN** a chain whose early entries were written before the two columns existed and whose later entries carry a `ruleDigest`
- **WHEN** `GET /api/audit-trails/verify` is called
- **THEN** the chain verifies as valid end to end
- @e2e exclude verification endpoint with no page of its own; covered by a chain verification test

#### Scenario: Tampering with a digest breaks the chain
- **GIVEN** a sealed `read` entry with a `ruleDigest`
- **WHEN** its `ruleDigest` is changed directly in the database
- **THEN** chain verification fails at that entry
- @e2e exclude tamper test against the database; covered by a chain verification test
