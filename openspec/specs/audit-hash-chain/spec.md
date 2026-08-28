---
status: done
---

# audit-hash-chain Specification

---
status: implemented
---

## Purpose

@e2e exclude cryptographic backend service — covered by PHPUnit
Cryptographic SHA-256 hash chaining on audit trail entries with genesis hash, verification endpoint, and tamper detection reporting. Each entry's hash chains to the previous entry, making any tampering detectable by auditors.

## OpenSpec changes

- `audit-seal-backlog-repair` (in-progress) — builds the bulk seal pass that the
  fail-soft write path has assumed since `harden-audit-seal-concurrency`: a
  bounded, resumable, idempotent driver (`occ` command + capped background job)
  that drains unsealed rows in ascending chain order, one lock per window rather
  than one per row, never re-hashing an already-sealed row. Adds a partial index
  for the backlog cursor and a cutover marker so `verifyChain()` stops reporting
  `valid: true` over rows it silently skipped.

## Requirements
### Requirement: Every audit trail entry MUST include a SHA-256 hash chained to the previous entry
Each audit trail entry MUST contain a `hash` field computed as `SHA-256(previous_hash + canonical_json(entry_data))`. The `previous_hash` field links to the preceding entry's hash, forming a tamper-evident chain.

#### Scenario: First audit entry uses genesis hash
- **WHEN** the first audit trail entry is created in the system (no previous entries exist)
- **THEN** the entry MUST have `previousHash` set to `SHA-256("openregister-genesis-v1")`
- **AND** the entry MUST have `hash` set to `SHA-256(genesis_hash + canonical_json(entry_data))`

#### Scenario: Subsequent entries chain to previous hash
- **WHEN** audit trail entry N is created after entry N-1 with hash `abc123...`
- **THEN** entry N MUST have `previousHash` set to `abc123...`
- **AND** entry N MUST have `hash` set to `SHA-256("abc123..." + canonical_json(entry_data_N))`

#### Scenario: Canonical JSON excludes hash fields
- **WHEN** computing the hash for an audit trail entry
- **THEN** the canonical JSON MUST include all entry fields except `hash` and `previousHash`
- **AND** the JSON MUST use sorted keys and no whitespace (compact canonical form)

### Requirement: The system MUST provide a hash chain verification endpoint
An API endpoint MUST allow auditors to verify the integrity of the hash chain.

#### Scenario: Verify full chain integrity
- **WHEN** a GET request is made to `/api/audit-trails/verify`
- **THEN** the system MUST iterate all audit trail entries in order
- **AND** recompute each hash and compare to the stored hash
- **AND** return a JSON response with `{"valid": true, "entriesVerified": <count>}`

#### Scenario: Verify chain with range parameters
- **WHEN** a GET request is made to `/api/audit-trails/verify?from=100&to=200`
- **THEN** the system MUST verify only entries with IDs between 100 and 200 (inclusive)
- **AND** return `{"valid": true, "entriesVerified": 101, "range": {"from": 100, "to": 200}}`

#### Scenario: Detect tampered entry in chain
- **WHEN** an entry in the chain has been modified after creation (stored hash does not match recomputed hash)
- **THEN** the verification endpoint MUST return `{"valid": false, "brokenAt": <entry_id>, "entriesVerified": <count>}`
- **AND** the `brokenAt` field MUST identify the first entry where the chain breaks

#### Scenario: Handle entries without hashes (pre-migration)
- **WHEN** the verification encounters entries with null `hash` values (created before hash chaining was enabled)
- **THEN** those entries MUST be skipped in the verification
- **AND** the response MUST include `"skippedNullHashes": <count>`

### Requirement: Hash chain writes MUST be serialized to prevent race conditions
Concurrent audit trail inserts MUST NOT produce broken hash chains.

#### Scenario: Two simultaneous audit writes
- **WHEN** two audit trail entries are created at the same moment
- **THEN** both entries MUST be correctly chained (each referencing the correct previous hash)
- **AND** no two entries MUST share the same `previousHash` value (except the genesis hash for the first entry)

### Requirement: A database migration MUST add hash columns
The migration MUST add `hash` and `previous_hash` columns to the audit trails table.

#### Scenario: Migration adds nullable hash columns
- **WHEN** the migration runs on an existing database with audit trail entries
- **THEN** columns `hash` (VARCHAR 64) and `previous_hash` (VARCHAR 64) MUST be added
- **AND** existing entries MUST retain null values for both columns
- **AND** an index MUST be created on the `hash` column for verification queries

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

