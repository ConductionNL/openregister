## MODIFIED Requirements

### Requirement: Every audit trail entry MUST include a SHA-256 hash chained to the previous entry
Each audit trail entry MUST contain a `hash` field computed as `SHA-256(previous_hash + canonical_json(entry_data))`. The `previous_hash` field links to the preceding entry's hash, forming a tamper-evident chain.

The genesis seed is versioned. The current seed is `openregister-genesis-v2`, which supersedes `openregister-genesis-v1`; the version reflects the canonical form the chain commits to, and the two MUST move together.

The canonical form covers the flow attribution fields — the run, node and step that caused the row — so that re-pointing a row at a different run, or stripping its attribution, breaks verification exactly as altering any other field does.

#### Scenario: First audit entry uses genesis hash
- **WHEN** the first audit trail entry is created in the system (no previous entries exist)
- **THEN** the entry MUST have `previousHash` set to `SHA-256("openregister-genesis-v2")`
- **AND** the entry MUST have `hash` set to `SHA-256(genesis_hash + canonical_json(entry_data))`

#### Scenario: Subsequent entries chain to previous hash
- **WHEN** audit trail entry N is created after entry N-1 with hash `abc123...`
- **THEN** entry N MUST have `previousHash` set to `abc123...`
- **AND** entry N MUST have `hash` set to `SHA-256("abc123..." + canonical_json(entry_data_N))`

#### Scenario: Canonical JSON excludes hash fields
- **WHEN** computing the hash for an audit trail entry
- **THEN** the canonical JSON MUST include all entry fields except `hash` and `previousHash`
- **AND** the JSON MUST use sorted keys and no whitespace (compact canonical form)

#### Scenario: Flow attribution is covered by the hash
- **WHEN** a row's recorded run, node or step is altered directly in the database
- **AND** the chain is verified
- **THEN** verification MUST report the chain as broken at that row

## ADDED Requirements

### Requirement: Changing the canonical form is a verify-then-rechain migration @e2e exclude migration-time integrity operation with no user-facing surface; asserted by migration tests over seeded chains

A change to the canonical form or the genesis seed SHALL be performed as a single migration that, in order:

1. verifies the existing chain **against the canonical form the existing rows were sealed under**, not the incoming one;
2. durably records that verdict — whether the chain was intact, and if not, where it first broke — before any row is altered;
3. re-seals every row under the new seed and canonical form.

The verification in step 1 SHALL use a frozen copy of the outgoing canonicaliser retained in the codebase for that purpose. Verifying with the incoming canonicaliser would report every pre-existing row as broken regardless of whether it had been tampered with, making the verdict indistinguishable between a healthy chain and a compromised one — and a re-chain then permanently conceals the difference.

The recorded verdict SHALL be readable after the migration completes. A re-chain over a chain that did not verify is permitted, but SHALL NOT be silent.

#### Scenario: An intact chain is re-sealed and recorded as intact
- **WHEN** the migration runs against a chain that verifies under the outgoing canonical form
- **THEN** the verdict recorded before re-sealing states the chain was intact and names the number of rows verified
- **AND** every row is afterwards sealed under the new seed
- **AND** verification under the new canonical form succeeds

#### Scenario: A broken chain is recorded before it is re-sealed over
- **WHEN** the migration runs against a chain that does NOT verify under the outgoing canonical form
- **THEN** the recorded verdict states the chain was broken and identifies where
- **AND** that verdict remains readable after the re-seal has made the break undetectable

#### Scenario: The pre-check uses the outgoing canonical form
- **WHEN** the pre-migration verification runs
- **THEN** it computes canonical JSON without the fields the migration is introducing
- **AND** a chain sealed before the migration verifies as intact

#### Scenario: Re-sealing is resumable and idempotent
- **WHEN** the migration is interrupted part-way and re-run
- **THEN** it completes the re-seal without double-sealing rows already migrated
- **AND** the final chain verifies end to end

#### Scenario: Tombstoned rows survive the re-seal as tombstones
- **WHEN** the chain being re-sealed contains rows purged under retention
- **THEN** those rows are carried forward as declared tombstones
- **AND** the re-sealed chain does not report them as breaks
