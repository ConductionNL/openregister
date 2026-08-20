## MODIFIED Requirements

### Requirement: Hash chain writes MUST be serialized to prevent race conditions

Concurrent audit trail inserts MUST NOT produce broken hash chains. All seal
passes — per-row (`sealRow()`) and batched (`sealRows()`) — MUST serialize
their critical section (predecessor-hash read through hash UPDATE) under one
exclusive advisory lock with the well-known key `openregister/audit-seal`
(`OCP\Lock\ILockingProvider`), so no two passes can interleave a predecessor
read with another pass's write. Lock acquisition MUST be bounded (short
retry window, no indefinite wait) and fail-soft: when the lock cannot be
acquired the pass MUST log a warning and leave the rows unsealed — a later
seal pass chains them, and unsealed rows are skipped by both verification
and predecessor lookup — rather than blocking the audit write path. The
lock MUST always be released, including when the seal pass throws.

#### Scenario: Two simultaneous audit writes

- **WHEN** two audit trail entries are created at the same moment
- **THEN** both entries MUST be correctly chained (each referencing the correct previous hash)
- **AND** no two entries MUST share the same `previousHash` value (except the genesis hash for the first entry)

#### Scenario: Seal passes run under the shared exclusive advisory lock

- **WHEN** `sealRow()` or `sealRows()` seals audit rows
- **THEN** the exclusive lock `openregister/audit-seal` is acquired before the predecessor hash is read
- **AND** the lock is released after the hash UPDATE completes

#### Scenario: Lock contention is fail-soft

- **WHEN** the seal lock cannot be acquired within the bounded retry window
- **THEN** a warning is logged and the rows are left unsealed
- **AND** no database read or write is performed by the abandoned pass
- **AND** the audit rows themselves remain persisted (sealing is fail-soft, the insert is not)

#### Scenario: Lock released when the seal pass throws

- **WHEN** the seal pass fails mid-flight (e.g. a database error)
- **THEN** the advisory lock is released regardless
- **AND** the failure propagates to the caller's existing fail-soft handling

## ADDED Requirements

### Requirement: Seal passes MUST chain from the nearest prior SEALED row

The predecessor lookup for a seal pass MUST skip unsealed rows (`hash` NULL
or empty — fail-soft leftovers or pre-migration entries) and MUST return the
nearest PRIOR row with a non-empty `hash` as the `previousHash` source,
falling back to the genesis hash when no sealed predecessor exists.
This mirrors exactly how `verifyChain()` walks the chain (null-hash rows are
skipped and counted as `skippedNullHashes` while the last SEALED hash is
carried forward). Chaining from the immediately-prior row regardless of its
seal state would fall back to genesis whenever that row is unsealed,
producing a PERMANENT false chain break at that link.

#### Scenario: Unsealed predecessor does not break the chain

- **GIVEN** row N is an unsealed fail-soft leftover and row N-1 is sealed with hash `h`
- **WHEN** row N+1 is sealed
- **THEN** row N+1's `previousHash` is `h` (the nearest sealed predecessor), not the genesis hash
- **AND** `verifyChain()` over rows N-1..N+1 returns `valid: true` with `skippedNullHashes: 1`

#### Scenario: Predecessor lookup filters unsealed rows in the query

- **WHEN** the predecessor hash for a seal pass is read
- **THEN** the lookup query excludes rows with `hash` NULL or empty
- **AND** returns the highest-id remaining row below the sealed row's id
