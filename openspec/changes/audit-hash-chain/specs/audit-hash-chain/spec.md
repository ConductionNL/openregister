---
status: proposed
capability: audit-hash-chain
---

# Audit Trail Hash Chain

## Purpose

Define the cryptographic hash chaining mechanism for audit trail entries, the canonical
JSON format for deterministic hashing, the verification endpoint contract, and the
serialization requirements for concurrent writes. This capability enables auditors to
detect tampering in the audit log by verifying that each entry's hash chains correctly
from the previous entry's hash.

## ADDED Requirements

### Requirement: Every audit trail entry MUST include a SHA-256 hash chained to the previous entry

Each audit trail entry MUST contain a `hash` field computed as `SHA-256(previous_hash + canonical_json(entry_data))`. The `previousHash` field links to the preceding entry's hash, forming a tamper-evident chain. Both `hash` and `previousHash` MUST be stored as VARCHAR(64) columns in the database.

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
- **AND** the JSON MUST use sorted keys (lexicographic order) and no whitespace (compact form)
- **AND** two independent computations of the same entry MUST produce identical hashes

### Requirement: The system MUST provide a hash chain verification endpoint

An API endpoint MUST allow auditors to verify the integrity of the hash chain.

#### Scenario: Verify full chain integrity

- **WHEN** a GET request is made to `/api/audit-trails/verify`
- **THEN** the system MUST iterate all audit trail entries in order (by ID ascending)
- **AND** recompute each entry's hash from its data and the previous entry's hash
- **AND** compare each recomputed hash to the stored hash
- **AND** return a JSON response with `{"valid": true, "entriesVerified": <count>}`

#### Scenario: Verify chain with range parameters

- **WHEN** a GET request is made to `/api/audit-trails/verify?from=100&to=200`
- **THEN** the system MUST verify only entries with IDs between 100 and 200 (inclusive)
- **AND** the verification MUST start from the entry immediately before `from` (ID 99) to obtain the correct `previousHash` for entry 100
- **AND** return `{"valid": true, "entriesVerified": 101, "range": {"from": 100, "to": 200}}`

#### Scenario: Detect tampered entry in chain

- **WHEN** an entry in the chain has been modified after creation (stored hash does not match recomputed hash)
- **THEN** the verification endpoint MUST return `{"valid": false, "brokenAt": <entry_id>, "entriesVerified": <count>}`
- **AND** the `brokenAt` field MUST identify the first entry where the chain breaks
- **AND** all entries up to and including `brokenAt` MUST be listed in the response as `"broken": [ID, ID, ...]` (all entries from the break point onward that have invalid hashes)

#### Scenario: Handle entries without hashes (pre-migration)

- **WHEN** the verification encounters entries with null `hash` values (created before hash chaining was enabled)
- **THEN** those entries MUST be skipped in the verification
- **AND** the chain MUST resume at the first non-null hash entry
- **AND** the response MUST include `"skippedNullHashes": <count>` indicating how many entries were skipped

#### Scenario: Verify returns appropriate HTTP status

- **WHEN** the verification endpoint is called
- **THEN** a valid chain MUST return HTTP 200 with `valid: true`
- **AND** a broken chain MUST return HTTP 200 with `valid: false, brokenAt: <ID>` (not 4xx/5xx — verification success is the endpoint completing; the result field indicates the audit outcome)

### Requirement: Hash chain writes MUST be serialized to prevent race conditions

Concurrent audit trail inserts MUST NOT produce broken hash chains.

#### Scenario: Two simultaneous audit writes

- **WHEN** two audit trail entries are created at the same moment by concurrent processes
- **THEN** both entries MUST be correctly chained (each referencing the correct previous hash)
- **AND** no two entries MUST share the same `previousHash` value (except the genesis hash for the first entry, where multiple entries with the genesis `previousHash` is acceptable only if they are the very first entries ever written)

#### Scenario: Serialized database write

- **WHEN** the audit trail service writes a new entry
- **THEN** the write MUST acquire an exclusive lock or use SERIALIZABLE transaction isolation
- **AND** the query for the "last entry" MUST occur within the same transaction as the INSERT
- **AND** a second concurrent writer MUST block or be sequenced after the first write completes

### Requirement: A database migration MUST add hash columns

The migration MUST add `hash` and `previous_hash` columns to the audit trails table.

#### Scenario: Migration adds nullable hash columns

- **WHEN** the migration runs on an existing database with audit trail entries
- **THEN** columns `hash` (VARCHAR 64, NULLABLE) and `previous_hash` (VARCHAR 64, NULLABLE) MUST be added to the audit_trails table
- **AND** existing entries MUST retain null values for both columns (no backfill)
- **AND** an index MUST be created on the `hash` column for verification queries: `CREATE INDEX idx_audit_trails_hash ON audit_trails(hash)`

#### Scenario: First entry after migration computes genesis-based hash

- **WHEN** the first audit entry is created after the migration completes
- **THEN** its `previousHash` MUST be set to `SHA-256("openregister-genesis-v1")`
- **AND** its `hash` MUST be computed from the genesis hash + the entry's canonical JSON

### Requirement: Hash computation MUST be deterministic across PHP versions and libraries

Two independent computations of the same audit entry MUST always produce the same hash.

#### Scenario: Hash stability across restarts

- **WHEN** an audit entry is created, its hash is computed and stored
- **AND** the system is restarted
- **AND** the entry's hash is recomputed from the stored data
- **THEN** the recomputed hash MUST exactly match the originally stored hash

#### Scenario: UTF-8 and non-ASCII handling

- **WHEN** an audit entry's data contains non-ASCII characters (e.g., Dutch names "François", "José", "Müller")
- **THEN** the canonical JSON MUST encode these as UTF-8 (not escaped Unicode)
- **AND** the SHA-256 hash MUST be computed on the UTF-8 bytes
- **AND** the hash MUST match across different systems (deterministic)

## MODIFIED Requirements

(none)

## DEPRECATED Requirements

(none)
