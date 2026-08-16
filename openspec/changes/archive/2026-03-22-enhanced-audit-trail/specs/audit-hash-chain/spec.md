## ADDED Requirements

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
