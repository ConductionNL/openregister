## ADDED Requirements

### Requirement: Audit rows are hash-chained at insert time

Every audit-trail row SHALL be sealed into the SHA-256 hash chain at the moment
it is inserted. The insert paths `AuditTrailMapper::createAuditTrail()` and
`createAuditTrailEntry()` SHALL set `previousHash` from the current chain head
(`AuditHashService::getLastHash()`) and set `hash` (`computeHash()`) before
persisting the row. The hashed payload SHALL exclude the `hash` and
`previousHash` fields themselves.

#### Scenario: A newly created audit row carries a hash

- **WHEN** an object write produces an audit-trail row
- **THEN** the persisted row has a non-null `hash`
- **AND** its `previousHash` equals the `hash` of the immediately preceding row
  (or the genesis seed for the first hashed row)

#### Scenario: Tampering is detected

- **WHEN** a persisted audit row's payload is altered directly in the database
- **AND** `AuditHashService::verifyChain()` is run
- **THEN** it returns `valid: false`
- **AND** it identifies the index at which the chain breaks

#### Scenario: Post-cutover null hash is a break, not a skip

- **WHEN** a row created after the hash-chain cutover marker has a null `hash`
- **AND** `verifyChain()` is run
- **THEN** it returns `valid: false`
- **AND** it does NOT silently count the row as `skippedNullHashes`

#### Scenario: Legacy pre-cutover rows are sealed by backfill

- **WHEN** the backfill repair step runs against pre-existing null-hash rows
- **THEN** each row is sealed with `previousHash`/`hash` in insertion order
- **AND** re-running the step is a no-op (already-hashed rows are skipped)

### Requirement: Audit entries cannot be deleted through the service layer without an audited admin override

Service-layer helpers that delete audit rows SHALL NOT bypass the immutability
guarantee. `LogService::deleteLog()`/`deleteLogs()` SHALL either not exist or
SHALL require an admin caller and record an "immutability override" audit entry
before deletion, matching `LogService::clearAll()`.

#### Scenario: Non-admin cannot delete audit entries via the service

- **WHEN** a non-admin context invokes an audit-deletion service helper
- **THEN** the call is rejected
- **AND** no audit row is deleted

### Requirement: Authorization-configuration changes are durably audited

Changes to register/schema authorization and role definitions SHALL be persisted
as audit-trail rows via `AuditTrailMapper::createAuditTrailEntry()`, not recorded
only in the application log.

#### Scenario: Changing a schema's authorization writes an audit row

- **WHEN** a schema's `authorization` block is modified
- **THEN** a hash-chained audit-trail row describing the change (actor, target,
  before/after) is persisted
- **AND** the row participates in `verifyChain()`
