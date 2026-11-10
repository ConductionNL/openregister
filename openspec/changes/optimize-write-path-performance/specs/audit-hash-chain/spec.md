## ADDED Requirements

### Requirement: Batched audit inserts are sealed into the hash chain in one pass

Hash-chain sealing MUST be batched whenever audit-trail rows are persisted in
batches (bulk saves): one range SELECT, one previous-hash SELECT,
and one UPDATE per batch instead of three queries per row. Batched sealing
MUST produce a chain that `verifyChain()` verifies identically to per-row
sealing: rows are hashed in id order; the first unsealed row chains onto the
hash of the row immediately before the range (or the genesis hash);
already-sealed rows inside the range are left untouched but contribute their
stored hash as the chain link; unsealed rows interleaved by concurrent
writers are sealed with the same deterministic value their own per-row
sealing would compute. Batched sealing MUST remain fail-soft: a sealing
failure logs and leaves rows unhashed rather than losing the audit records.

#### Scenario: Batch chains from genesis

- **GIVEN** an empty audit table and a batch of two freshly inserted rows
- **WHEN** the batch is sealed
- **THEN** row 1's hash is `sha256(genesisHash . canonicalJson(row1))` with `previous_hash = genesisHash`
- **AND** row 2's hash chains onto row 1's computed hash
- **AND** both are persisted in a single UPDATE statement

#### Scenario: Sealed rows in the range are adopted, not rewritten

- **GIVEN** a row already sealed by per-row sealing sits inside the batch's id range
- **WHEN** the batch is sealed
- **THEN** that row's stored hash and previous_hash are not modified
- **AND** the next unsealed row chains onto that stored hash

#### Scenario: Batched and per-row sealing verify identically

- **WHEN** `verifyChain()` runs over a section of the chain sealed by the batched path
- **THEN** every recomputed hash matches the stored hash exactly as it would for per-row-sealed entries
